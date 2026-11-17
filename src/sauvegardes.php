<?php
declare(strict_types=1);

// ─── Suivi des sauvegardes Proxmox (page « Sauvegardes ») ───────────────
// Lecture seule : tâches de sauvegarde planifiées, sauvegarde en cours (avancement CT par CT),
// historique et copies présentes sur le stockage. Le jeton du portail a besoin du rôle
// PortailAudit (Sys.Audit + Datastore.Audit), voir README.

function bk_node(): string
{
    return '/nodes/' . rawurlencode(PVE_NODE);
}

/** Tâches de sauvegarde planifiées (Datacenter → Sauvegarde). @return array{jobs:array,error:?string} */
function bk_jobs(): array
{
    $r = pve_request('GET', '/cluster/backup');
    return ['jobs' => $r['ok'] && is_array($r['data']) ? $r['data'] : [], 'error' => $r['error']];
}

/** Dernières tâches vzdump, de la plus récente à la plus ancienne. @return array{tasks:array,error:?string} */
function bk_tasks(int $limit = 15): array
{
    $r = pve_request('GET', bk_node() . '/tasks?typefilter=vzdump&limit=' . $limit);
    if (!$r['ok']) {
        return ['tasks' => [], 'error' => $r['error']];
    }
    $t = is_array($r['data']) ? $r['data'] : [];
    usort($t, fn ($a, $b) => (int) ($b['starttime'] ?? 0) <=> (int) ($a['starttime'] ?? 0));
    return ['tasks' => $t, 'error' => null];
}

/** Lignes du journal d'une tâche. @return string[] */
function bk_log(string $upid): array
{
    $r = pve_request('GET', bk_node() . '/tasks/' . rawurlencode($upid) . '/log?limit=5000');
    if (!$r['ok'] || !is_array($r['data'])) {
        return [];
    }
    return array_map(fn ($l) => (string) ($l['t'] ?? ''), $r['data']);
}

/**
 * Analyse le journal vzdump : CT prévus, CT en cours, terminés (durée, taille) ou en échec.
 * @return array{vmids:int[], items:array<int,array>, current:?int, done:int, failed:int}
 */
function bk_parse(array $lines): array
{
    $vmids = [];
    $items = [];
    $cur   = null;
    foreach ($lines as $l) {
        if (preg_match('/starting new backup job: vzdump\s+(.*)$/', $l, $m)) {
            foreach (preg_split('/\s+/', $m[1]) ?: [] as $tok) {
                if (str_starts_with($tok, '--')) {
                    break;
                }
                if (ctype_digit($tok)) {
                    $vmids[] = (int) $tok;
                }
            }
        } elseif (preg_match('/Starting Backup of VM (\d+)/', $l, $m)) {
            $cur = (int) $m[1];
            $items[$cur] = ['state' => 'run', 'name' => '', 'start' => null, 'dur' => null, 'size' => '', 'error' => ''];
        } elseif ($cur !== null && preg_match('/Backup started at (\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)/', $l, $m)) {
            $items[$cur]['start'] = strtotime($m[1]) ?: null;
        } elseif ($cur !== null && preg_match('/(?:CT|VM) Name: (.+)$/', $l, $m)) {
            $items[$cur]['name'] = trim($m[1]);
        } elseif ($cur !== null && preg_match('/archive file size: (\S+)/', $l, $m)) {
            $items[$cur]['size'] = $m[1];
        } elseif (preg_match('/Finished Backup of VM (\d+) \((\d+):(\d\d):(\d\d)\)/', $l, $m)) {
            $id = (int) $m[1];
            $items[$id]['state'] = 'ok';
            $items[$id]['dur'] = (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) $m[4];
        } elseif (preg_match('/Backup of VM (\d+) failed - (.*)$/', $l, $m)) {
            $id = (int) $m[1];
            $items[$id] = ($items[$id] ?? ['name' => '', 'start' => null, 'dur' => null, 'size' => '']) + ['state' => 'err'];
            $items[$id]['state'] = 'err';
            $items[$id]['error'] = trim($m[2]);
        }
    }
    if (!$vmids) {
        $vmids = array_keys($items);
    }
    $current = null;
    foreach ($items as $id => $it) {
        if ($it['state'] === 'run') {
            $current = $id;
        }
    }
    return [
        'vmids'   => $vmids,
        'items'   => $items,
        'current' => $current,
        'done'    => count(array_filter($items, fn ($i) => $i['state'] === 'ok')),
        'failed'  => count(array_filter($items, fn ($i) => $i['state'] === 'err')),
    ];
}

/** État d'un stockage (actif, place). */
function bk_storage(string $storage): array
{
    $r = pve_request('GET', bk_node() . '/storage/' . rawurlencode($storage) . '/status');
    return $r['ok'] && is_array($r['data']) ? $r['data'] : [];
}

/** Copies présentes sur un stockage, regroupées par CT/VM. @return array{byVm:array,error:?string} */
function bk_copies(string $storage): array
{
    $r = pve_request('GET', bk_node() . '/storage/' . rawurlencode($storage) . '/content?content=backup');
    if (!$r['ok']) {
        return ['byVm' => [], 'error' => $r['error']];
    }
    $by = [];
    foreach (is_array($r['data']) ? $r['data'] : [] as $f) {
        $id = (int) ($f['vmid'] ?? 0);
        $by[$id][] = ['ctime' => (int) ($f['ctime'] ?? 0), 'size' => (int) ($f['size'] ?? 0), 'notes' => (string) ($f['notes'] ?? '')];
    }
    foreach ($by as &$list) {
        usort($list, fn ($a, $b) => $b['ctime'] <=> $a['ctime']);
    }
    unset($list);
    ksort($by);
    return ['byVm' => $by, 'error' => null];
}

/** Statut lisible d'une tâche terminée. */
function bk_status_label(array $task): array
{
    if (empty($task['endtime'])) {
        return ['En cours', 'run'];
    }
    $s = (string) ($task['status'] ?? '');
    if ($s === 'OK') {
        return ['Réussie', 'ok'];
    }
    if (stripos($s, 'job errors') !== false) {
        return ['Erreurs', 'err'];
    }
    return [$s !== '' ? mb_substr($s, 0, 60) : 'Échec', 'err'];
}

function fmt_duree(?int $s): string
{
    if ($s === null || $s < 0) {
        return '—';
    }
    if ($s < 60) {
        return $s . ' s';
    }
    if ($s < 3600) {
        return intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s';
    }
    return intdiv($s, 3600) . ' h ' . str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT) . ' min';
}

function fmt_taille(int $o): string
{
    $u = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    $v = (float) $o;
    while ($v >= 1024 && $i < 4) {
        $v /= 1024;
        $i++;
    }
    return ($i >= 3 ? number_format($v, 1, ',', ' ') : (string) round($v)) . ' ' . $u[$i];
}

/** « dans 3 h 20 min » / « il y a 5 min ». */
function fmt_relatif(int $ts): string
{
    $d = $ts - time();
    $txt = fmt_duree(abs($d));
    $txt = preg_replace('/ \d+ s$/', '', $txt) ?? $txt; // pas de secondes au-delà d'une minute
    return $d >= 0 ? "dans $txt" : "il y a $txt";
}

/** Barre d'avancement en SVG (la CSP du portail interdit les styles en ligne). */
function bk_bar(float $ratio, string $class = ''): string
{
    $w = round(max(0.0, min(1.0, $ratio)) * 240, 2);
    return '<svg class="pb ' . e($class) . '" viewBox="0 0 240 10" preserveAspectRatio="none" role="img" aria-label="Avancement '
        . (int) round($ratio * 100) . ' %"><rect class="pb-bg" x="0" y="0" width="240" height="10" rx="5"/>'
        . '<rect class="pb-fill" x="0" y="0" width="' . $w . '" height="10" rx="5"/></svg>';
}
