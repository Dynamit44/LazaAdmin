<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_playoff_modes.php';
require_role('organizer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();

$stageId = (int)($b['stage_id'] ?? 0);
$category = (int)($b['category'] ?? 0);
$variant = (int)($b['variant'] ?? 1);

$playoffMode = trim((string)($b['playoff_mode'] ?? 'none'));
$meta = $b['meta'] ?? null;

if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);
if ($category < 2000 || $category > 2100) respond_json(['ok'=>false,'error'=>'bad category'], 400);
if ($variant <= 0) respond_json(['ok'=>false,'error'=>'bad variant'], 400);

$pdo = db();

// stage exists
$st = $pdo->prepare("SELECT id FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id'=>$stageId]);
if (!$st->fetchColumn()) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $stageId));

// category exists in stage and remains the source of truth for base playoff settings
$st = $pdo->prepare("SELECT id, playoff_enabled, playoff_days FROM stage_categories WHERE stage_id=:sid AND category=:cat LIMIT 1");
$st->execute([':sid'=>$stageId, ':cat'=>$category]);
$catRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$catRow) respond_json(['ok'=>false,'error'=>'category not found in stage'], 404);

$playoffEnabled = (int)($catRow['playoff_enabled'] ?? 0) ? 1 : 0;
$playoffDays = (int)($catRow['playoff_days'] ?? 0);

// fact snapshot: groups_count
$st = $pdo->prepare("
  SELECT COUNT(DISTINCT group_code) AS c
  FROM group_entries
  WHERE stage_id=:sid AND category=:cat AND variant=:v
");
$st->execute([':sid'=>$stageId, ':cat'=>(string)$category, ':v'=>$variant]);
$groupsCount = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

$metaJson = null;
if ($meta !== null) {
  $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($metaJson === false) $metaJson = null;
}

try {
  $sql = "
    INSERT INTO stage_playoff_plan(stage_id, category, variant, groups_count, playoff_enabled, playoff_days, playoff_mode, meta_json)
    VALUES(:sid,:cat,:v,:gc,:pe,:pd,:pm,:mj)
    ON DUPLICATE KEY UPDATE
      groups_count=VALUES(groups_count),
      playoff_enabled=VALUES(playoff_enabled),
      playoff_days=VALUES(playoff_days),
      playoff_mode=VALUES(playoff_mode),
      meta_json=VALUES(meta_json),
      updated_at=CURRENT_TIMESTAMP
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':sid'=>$stageId,
    ':cat'=>$category,
    ':v'=>$variant,
    ':gc'=>$groupsCount,
    ':pe'=>$playoffEnabled,
    ':pd'=>$playoffDays,
    ':pm'=>$playoffMode,
    ':mj'=>$metaJson,
  ]);
} catch (PDOException $e) {
  if (($e->getCode() === '42S02') || (strpos($e->getMessage(), 'stage_playoff_plan') !== false)) {
    respond_json(['ok'=>false,'error'=>'stage_playoff_plan table missing: run migration SQL'], 400);
  }
  throw $e;
}

respond_json(['ok'=>true, 'saved'=>true, 'groups_count'=>$groupsCount]);
