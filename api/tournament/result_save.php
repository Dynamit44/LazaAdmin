<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require __DIR__ . '/playoff_advance.php';
require_role('operator','organizer');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'POST only'], 405);
}

$in = read_json_body();
$matchId = (int)($in['match_id'] ?? 0);
$hgRaw = $in['home_goals'] ?? null;
$agRaw = $in['away_goals'] ?? null;
$hpgRaw = $in['home_pen_goals'] ?? null;
$apgRaw = $in['away_pen_goals'] ?? null;
$clear = ($hgRaw === null && $agRaw === null);

if ($matchId <= 0) {
  respond_json(['ok'=>false,'error'=>'match_id required'], 400);
}

$hg = null;
$ag = null;
$hpg = null;
$apg = null;

if (!$clear) {
  if (!is_numeric($hgRaw) || !is_numeric($agRaw)) {
    respond_json(['ok'=>false,'error'=>'home_goals/away_goals must be numbers or null-null to clear'], 400);
  }
  $hg = (int)$hgRaw;
  $ag = (int)$agRaw;
  if ($hg < 0 || $ag < 0 || $hg > 99 || $ag > 99) {
    respond_json(['ok'=>false,'error'=>'goals out of range'], 400);
  }

  if ($hpgRaw !== null && $hpgRaw !== '') {
    if (!is_numeric($hpgRaw)) respond_json(['ok'=>false,'error'=>'home_pen_goals must be a number or null'], 400);
    $hpg = (int)$hpgRaw;
    if ($hpg < 0 || $hpg > 99) respond_json(['ok'=>false,'error'=>'home_pen_goals out of range'], 400);
  }
  if ($apgRaw !== null && $apgRaw !== '') {
    if (!is_numeric($apgRaw)) respond_json(['ok'=>false,'error'=>'away_pen_goals must be a number or null'], 400);
    $apg = (int)$apgRaw;
    if ($apg < 0 || $apg > 99) respond_json(['ok'=>false,'error'=>'away_pen_goals out of range'], 400);
  }
}

$pdo = db();

try {
  ensure_results_penalty_columns($pdo);
  $hasPenCols = has_results_penalty_columns($pdo);

  $st = $pdo->prepare('SELECT id, stage_id, category, variant, phase FROM matches WHERE id = :id LIMIT 1');
  $st->execute([':id' => $matchId]);
  $match = $st->fetch(PDO::FETCH_ASSOC);
  if (!$match) {
    respond_json(['ok'=>false,'error'=>'match not found'], 404);
  }

  lc_require_tournament_not_archived(
    $pdo,
    lc_tournament_id_for_stage($pdo, (int)($match['stage_id'] ?? 0))
  );

  $phase = trim((string)($match['phase'] ?? ''));

  if (!$clear) {
    if ($phase === 'playoff') {
      if ($hg !== $ag) {
        $hpg = null;
        $apg = null;
      } else {
        if ($hpg === null || $apg === null) {
          respond_json(['ok'=>false,'error'=>'Для плей-офф при ничьей обязательны пенальти'], 400);
        }
        if ($hpg === $apg) {
          respond_json(['ok'=>false,'error'=>'В плей-офф пенальти не могут быть равны'], 400);
        }
      }
    } else {
      $hpg = null;
      $apg = null;
    }
  }

  $updatedBy = 0;
  $u = current_user();
  $login = (is_array($u) && isset($u['login'])) ? (string)$u['login'] : '';
  if ($login !== '') {
    try {
      $st = $pdo->prepare('SELECT id FROM users WHERE login = :login LIMIT 1');
      $st->execute([':login' => $login]);
      $id = (int)$st->fetchColumn();
      if ($id > 0) $updatedBy = $id;
    } catch (Throwable $e) {
    }
  }

  if ($clear) {
    $st = $pdo->prepare('DELETE FROM results WHERE match_id = :mid');
    $st->execute([':mid'=>$matchId]);
  } else {
    if ($hasPenCols) {
      $sql = "INSERT INTO results (match_id, home_goals, away_goals, home_pen_goals, away_pen_goals, updated_by, updated_at)
              VALUES (:mid, :hg, :ag, :hpg, :apg, :uby, NOW())
              ON DUPLICATE KEY UPDATE
                home_goals = VALUES(home_goals),
                away_goals = VALUES(away_goals),
                home_pen_goals = VALUES(home_pen_goals),
                away_pen_goals = VALUES(away_pen_goals),
                updated_by = VALUES(updated_by),
                updated_at = NOW()";
      $st = $pdo->prepare($sql);
      $st->bindValue(':mid', $matchId, PDO::PARAM_INT);
      $st->bindValue(':hg', $hg, PDO::PARAM_INT);
      $st->bindValue(':ag', $ag, PDO::PARAM_INT);
      $st->bindValue(':hpg', $hpg, $hpg === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $st->bindValue(':apg', $apg, $apg === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
      $st->bindValue(':uby', $updatedBy, PDO::PARAM_INT);
      $st->execute();
    } else {
      if ($phase === 'playoff' && $hg === $ag) {
        respond_json(['ok'=>false,'error'=>'В БД ещё нет полей для пенальти. Сначала обнови схему.'], 500);
      }
      $sql = "INSERT INTO results (match_id, home_goals, away_goals, updated_by, updated_at)
              VALUES (:mid, :hg, :ag, :uby, NOW())
              ON DUPLICATE KEY UPDATE
                home_goals = VALUES(home_goals),
                away_goals = VALUES(away_goals),
                updated_by = VALUES(updated_by),
                updated_at = NOW()";
      $st = $pdo->prepare($sql);
      $st->execute([':mid'=>$matchId, ':hg'=>$hg, ':ag'=>$ag, ':uby'=>$updatedBy]);
    }
  }

  $playoffAuto = null;
  if ($phase === 'group' || $phase === 'playoff') {
    $playoffAuto = pa_apply(
      $pdo,
      (int)$match['stage_id'],
      trim((string)$match['category']),
      (int)($match['variant'] ?? 1)
    );
  }

  $out = [
    'ok' => true,
    'match_id' => $matchId,
    'cleared' => $clear,
  ];
  if (!$clear) {
    $out['home_goals'] = $hg;
    $out['away_goals'] = $ag;
    $out['home_pen_goals'] = $hpg;
    $out['away_pen_goals'] = $apg;
  }
  if (is_array($playoffAuto)) {
    $out['playoff_auto'] = $playoffAuto;
  }

  respond_json($out);
} catch (Throwable $e) {
  respond_json(['ok'=>false,'error'=>'db: '.$e->getMessage()], 500);
}
