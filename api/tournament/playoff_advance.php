<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_schema.php';
require_once __DIR__ . '/_canon_ranker.php';

function pa_read_payload(): array {
  $raw = file_get_contents('php://input');
  if ($raw) {
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
  }
  return $_POST ?: [];
}

function pa_parse_ref(?string $ref): ?array {
  if (!$ref) return null;
  $ref = trim($ref);
  if ($ref === '') return null;
  if (preg_match('~^GE:([^:]+):(\d+)$~', $ref, $m)) {
    return ['type' => 'GE', 'group' => (string)$m[1], 'pos' => (int)$m[2]];
  }
  if (preg_match('~^(W|L):(.+)$~', $ref, $m)) {
    return ['type' => (string)$m[1], 'code' => trim((string)$m[2])];
  }
  return null;
}

function pa_group_matches_done(PDO $pdo, int $stageId, string $category, int $variant): bool {
  $st = $pdo->prepare(
    "SELECT COUNT(*) AS total_cnt,
            SUM(CASE WHEN r.home_goals IS NOT NULL AND r.away_goals IS NOT NULL THEN 1 ELSE 0 END) AS played_cnt
     FROM matches m
     LEFT JOIN results r ON r.match_id = m.id
     WHERE m.stage_id = ? AND m.category = ? AND m.variant = ? AND m.phase = 'group'"
  );
  $st->execute([$stageId, $category, $variant]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $total = (int)($r['total_cnt'] ?? 0);
  $played = (int)($r['played_cnt'] ?? 0);
  return $total > 0 && $played >= $total;
}

function pa_sync_group_positions(PDO $pdo, int $stageId, string $category, int $variant, array $canon): int {
  $orders = [];
  foreach (($canon['seeds'] ?? []) as $seed) {
    $g = (string)($seed['group_code'] ?? '');
    $pig = (int)($seed['place_in_group'] ?? 0);
    $sid = (int)($seed['squad_id'] ?? 0);
    if ($g === '' || $pig <= 0 || $sid <= 0) continue;
    $orders[$g][$pig] = $sid;
  }
  if (!$orders) return 0;

  $upd = $pdo->prepare(
    'UPDATE group_entries SET pos = :pos WHERE stage_id = :sid AND category = :cat AND variant = :var AND group_code = :grp AND squad_id = :squad'
  );
  $changed = 0;
  foreach ($orders as $g => $items) {
    ksort($items);
    foreach ($items as $pos => $sid) {
      $upd->execute([
        ':pos' => (int)$pos,
        ':sid' => $stageId,
        ':cat' => $category,
        ':var' => $variant,
        ':grp' => (string)$g,
        ':squad' => (int)$sid,
      ]);
      $changed += $upd->rowCount() > 0 ? 1 : 0;
    }
  }
  return $changed;
}

function pa_pick_winner_loser(array $src, array $res): ?array {
  $homeId = (int)($src['home_squad_id'] ?? 0);
  $awayId = (int)($src['away_squad_id'] ?? 0);
  if ($homeId <= 0 || $awayId <= 0) return null;

  $hg = (int)($res['hg'] ?? 0);
  $ag = (int)($res['ag'] ?? 0);
  $hpg = array_key_exists('hpg', $res) ? $res['hpg'] : null;
  $apg = array_key_exists('apg', $res) ? $res['apg'] : null;

  if ($hg > $ag) return ['winner' => $homeId, 'loser' => $awayId];
  if ($hg < $ag) return ['winner' => $awayId, 'loser' => $homeId];

  if ($hpg === null || $apg === null) return null;
  $hpg = (int)$hpg;
  $apg = (int)$apg;
  if ($hpg === $apg) return null;

  return ($hpg > $apg)
    ? ['winner' => $homeId, 'loser' => $awayId]
    : ['winner' => $awayId, 'loser' => $homeId];
}

function pa_apply(PDO $pdo, int $stageId, string $category, int $variant): array {
  ensure_results_penalty_columns($pdo);
  $hasPenCols = has_results_penalty_columns($pdo);

  $st = $pdo->prepare(
    "SELECT id, code, home_squad_id, away_squad_id, home_ref, away_ref
     FROM matches
     WHERE stage_id = ? AND category = ? AND variant = ? AND phase = 'playoff'
     ORDER BY id ASC"
  );
  $st->execute([$stageId, $category, $variant]);
  $matches = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$matches) {
    return ['ok' => true, 'updated' => 0, 'cleared' => 0, 'pos_synced' => 0, 'note' => 'no playoff matches'];
  }

  $groupsDone = pa_group_matches_done($pdo, $stageId, $category, $variant);
  $canon = null;
  $orders = [];
  $needsManual = false;
  $posSynced = 0;

  if ($groupsDone) {
    $canon = canon_rank_build($pdo, $stageId, $category, $variant, true);
    $needsManual = !empty($canon['needs_manual']);
    foreach (($canon['seeds'] ?? []) as $seed) {
      $g = (string)($seed['group_code'] ?? '');
      $pig = (int)($seed['place_in_group'] ?? 0);
      $sid = (int)($seed['squad_id'] ?? 0);
      if ($g === '' || $pig <= 0 || $sid <= 0) continue;
      $orders[$g][$pig] = $sid;
    }
    if (!$needsManual) {
      $posSynced = pa_sync_group_positions($pdo, $stageId, $category, $variant, $canon);
    }
  }

  $byId = [];
  $byCode = [];
  foreach ($matches as $m) {
    $id = (int)$m['id'];
    $byId[$id] = $m;
    $code = trim((string)($m['code'] ?? ''));
    if ($code !== '') $byCode[$code] = $id;
  }

  $sqlR = 'SELECT match_id, home_goals, away_goals';
  if ($hasPenCols) $sqlR .= ', home_pen_goals, away_pen_goals';
  $sqlR .= ' FROM results WHERE match_id IN (' . implode(',', array_fill(0, count($byId), '?')) . ')';

  $stR = $pdo->prepare($sqlR);
  $stR->execute(array_keys($byId));
  $resById = [];
  foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($r['home_goals'] === null || $r['away_goals'] === null) continue;
    $item = ['hg' => (int)$r['home_goals'], 'ag' => (int)$r['away_goals']];
    if ($hasPenCols) {
      $item['hpg'] = ($r['home_pen_goals'] === null) ? null : (int)$r['home_pen_goals'];
      $item['apg'] = ($r['away_pen_goals'] === null) ? null : (int)$r['away_pen_goals'];
    }
    $resById[(int)$r['match_id']] = $item;
  }

  $upd = $pdo->prepare('UPDATE matches SET home_squad_id = :home, away_squad_id = :away, home_label = NULL, away_label = NULL WHERE id = :id');
  $updated = 0;
  $cleared = 0;

  $resolve = function(array $row, string $side) use (&$byId, &$byCode, &$resById, $groupsDone, $needsManual, $orders): ?int {
    $ref = trim((string)($row[$side . '_ref'] ?? ''));
    if ($ref === '') return isset($row[$side . '_squad_id']) && (int)$row[$side . '_squad_id'] > 0 ? (int)$row[$side . '_squad_id'] : null;
    $p = pa_parse_ref($ref);
    if (!$p) return null;

    if ($p['type'] === 'GE') {
      if (!$groupsDone || $needsManual) return null;
      $g = (string)$p['group'];
      $pos = (int)$p['pos'];
      return (isset($orders[$g][$pos]) && (int)$orders[$g][$pos] > 0) ? (int)$orders[$g][$pos] : null;
    }

    $srcCode = (string)$p['code'];
    if ($srcCode === '' || !isset($byCode[$srcCode])) return null;
    $srcId = (int)$byCode[$srcCode];
    $src = $byId[$srcId] ?? null;
    if (!$src) return null;
    if (!isset($resById[$srcId])) return null;

    $wl = pa_pick_winner_loser($src, $resById[$srcId]);
    if (!$wl) return null;
    return ($p['type'] === 'W') ? (int)$wl['winner'] : (int)$wl['loser'];
  };

  for ($pass = 0; $pass < 6; $pass++) {
    $passChanged = false;
    foreach ($byId as $id => $row) {
      $newHome = $resolve($row, 'home');
      $newAway = $resolve($row, 'away');

      $oldHome = ((int)($row['home_squad_id'] ?? 0) > 0) ? (int)$row['home_squad_id'] : null;
      $oldAway = ((int)($row['away_squad_id'] ?? 0) > 0) ? (int)$row['away_squad_id'] : null;

      $homeChanged = ($newHome !== $oldHome);
      $awayChanged = ($newAway !== $oldAway);
      if (!$homeChanged && !$awayChanged) continue;

      $upd->bindValue(':home', $newHome, $newHome === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $upd->bindValue(':away', $newAway, $newAway === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $upd->bindValue(':id', (int)$id, PDO::PARAM_INT);
      $upd->execute();

      if (($oldHome !== null && $newHome === null) || ($oldAway !== null && $newAway === null)) $cleared++;
      if (($newHome !== null && $newHome !== $oldHome) || ($newAway !== null && $newAway !== $oldAway)) $updated++;

      $byId[$id]['home_squad_id'] = $newHome;
      $byId[$id]['away_squad_id'] = $newAway;
      $passChanged = true;
    }
    if (!$passChanged) break;
  }

  return [
    'ok' => true,
    'updated' => $updated,
    'cleared' => $cleared,
    'pos_synced' => $posSynced,
    'groups_done' => $groupsDone,
    'needs_manual' => $needsManual,
    'groups' => array_keys($orders),
  ];
}

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'playoff_advance.php') {
  header('Content-Type: application/json; charset=UTF-8');
  try {
    $pdo = db();
    $payload = pa_read_payload();
    $stageId = (int)($payload['stage_id'] ?? 0);
    $category = trim((string)($payload['category'] ?? ''));
    $variant = (int)($payload['variant'] ?? 1);
    if ($stageId <= 0 || $category === '') {
      respond_json(['ok' => false, 'error' => 'stage_id/category required'], 400);
    }
    $paTid = lc_tournament_id_for_stage($pdo, $stageId);
    lc_require_tournament_not_archived($pdo, $paTid);
    respond_json(pa_apply($pdo, $stageId, $category, $variant));
  } catch (Throwable $e) {
    respond_json(['ok' => false, 'error' => $e->getMessage()], 500);
  }
}
