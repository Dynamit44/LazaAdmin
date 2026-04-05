<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();
$stageId = (int)($b['stage_id'] ?? 0);
$rows = $b['rows'] ?? null;

if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);
if (!is_array($rows)) respond_json(['ok'=>false,'error'=>'rows must be array'], 400);

$pdo = db();

// stage exists
$st = $pdo->prepare("SELECT id, tournament_id FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id'=>$stageId]);
$stageCatRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$stageCatRow) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

lc_require_tournament_not_archived($pdo, (int)$stageCatRow['tournament_id']);

// запрет менять категории после генерации расписания
$cnt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM schedule sh
  JOIN matches m ON m.id=sh.match_id
  WHERE m.stage_id=:sid
");
$cnt->execute([':sid'=>$stageId]);
if ((int)($cnt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) > 0) {
  respond_json(['ok'=>false,'error'=>'Нельзя менять категории после генерации расписания. Сначала очисти расписание этапа.'], 400);
}

function asIntOrNull($v): ?int {
  if ($v === null) return null;
  if (is_string($v)) $v = trim($v);
  if ($v === '') return null;
  if (!is_numeric($v)) return null;
  return (int)$v;
}

function asBoolInt($v): int {
  if ($v === true) return 1;
  if ($v === false) return 0;
  if ($v === null) return 0;
  if (is_string($v)) {
    $vv = strtolower(trim($v));
    if ($vv === '1' || $vv === 'true' || $vv === 'yes' || $vv === 'on') return 1;
    return 0;
  }
  return (int)((int)$v !== 0);
}

function normPlayoffType($v): string {
  $v = is_string($v) ? trim($v) : '';
  if ($v === '') return 'semis_finals';

  $allowed = [
    'semis_finals' => true,
    'quarter_semis_finals' => true,
    'place_matches' => true,
  ];
  return isset($allowed[$v]) ? $v : 'semis_finals';
}

$seen = []; // category => true

$pdo->beginTransaction();
try {
  // полностью пересохраняем (проще и без рассинхрона)
  $pdo->prepare("DELETE FROM stage_categories WHERE stage_id=:sid")->execute([':sid'=>$stageId]);

  $ins = $pdo->prepare("
    INSERT INTO stage_categories(stage_id, category, match_minutes, break_minutes, min_rest_slots, max_matches_per_day, playoff_enabled, playoff_days, playoff_type)
    VALUES(:sid,:cat,:mm,:bm,:mrs,:mpd,:pe,:pd,:pt)
  ");

  foreach ($rows as $r) {
    if (!is_array($r)) continue;

    $cat = (int)($r['category'] ?? 0);
    $mm  = (int)($r['match_minutes'] ?? 0);

    $bm  = asIntOrNull($r['break_minutes'] ?? null);
    $mrs = asIntOrNull($r['min_rest_slots'] ?? null);

    

    $mpd = (int)($r['max_matches_per_day'] ?? 1);
    if ($mpd !== 2) $mpd = 1;

    $pe = asBoolInt($r['playoff_enabled'] ?? 0);
    $pd = (int)($r['playoff_days'] ?? 0);
    if ($pe === 0) {
      $pd = 0;
    } else {
      if ($pd !== 2) $pd = 1;
    }

    $pt = normPlayoffType($r['playoff_type'] ?? '');


    if ($cat < 2000 || $cat > 2100) throw new RuntimeException("Bad category: {$cat}");
    if ($mm < 5) throw new RuntimeException("Bad match_minutes for {$cat}: {$mm}");

    if ($bm !== null && $bm < 0) throw new RuntimeException("Bad break_minutes for {$cat}: {$bm}");
    if ($mrs !== null && $mrs < 0) throw new RuntimeException("Bad min_rest_slots for {$cat}: {$mrs}");

    if (isset($seen[$cat])) throw new RuntimeException("Duplicate category in payload: {$cat}");
    $seen[$cat] = true;

    $ins->execute([
      ':sid'=>$stageId,
      ':cat'=>$cat,
      ':mm'=>$mm,
      ':bm'=>$bm,
      ':mrs'=>$mrs,
      ':mpd'=>$mpd,
      ':pe'=>$pe,
      ':pd'=>$pd,
      ':pt'=>$pt,
    ]);
  }

  $pdo->commit();
  respond_json(['ok'=>true, 'saved'=>count($seen)]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>$e->getMessage()], 400);
}
