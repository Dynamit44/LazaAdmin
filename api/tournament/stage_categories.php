<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$stageId = (int)($_GET['stage_id'] ?? 0);
if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);

$pdo = db();

// stage exists (чтобы не ловить непонятные пустые ответы)
$st = $pdo->prepare("SELECT id, match_minutes, break_minutes, transition_minutes, min_rest_slots FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id'=>$stageId]);
$stage = $st->fetch(PDO::FETCH_ASSOC);
if (!$stage) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

$st = $pdo->prepare("
  SELECT id, stage_id, category, match_minutes, break_minutes, min_rest_slots, max_matches_per_day, playoff_enabled, playoff_days, playoff_type
  FROM stage_categories
  WHERE stage_id=:sid
  ORDER BY category DESC
");
$st->execute([':sid'=>$stageId]);

respond_json([
  'ok'=>true,
  'stage'=>$stage,          // на будущее (плейсхолдеры “из этапа”)
  'rows'=>$st->fetchAll(PDO::FETCH_ASSOC),
]);
