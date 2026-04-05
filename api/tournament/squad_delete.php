<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  $b = $_GET;
} elseif ($method === 'POST') {
  $b = read_json_body();
} else {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

if (!is_array($b)) respond_json(['ok'=>false,'error'=>'Bad request'], 400);

$id = (int)($b['id'] ?? 0);
if ($id <= 0) respond_json(['ok'=>false,'error'=>'id required'], 400);

$pdo = db();

// load squad + stage for lock
$st = $pdo->prepare("SELECT id, stage_id FROM squads WHERE id=:id LIMIT 1");
$st->execute([':id'=>$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) respond_json(['ok'=>false,'error'=>'squad not found'], 404);

$stageId = (int)$row['stage_id'];

lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $stageId));

// lock if schedule exists
$cnt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM schedule sh
  JOIN matches m ON m.id = sh.match_id
  WHERE m.stage_id = :sid
");
$cnt->execute([':sid'=>$stageId]);
if ((int)$cnt->fetch(PDO::FETCH_ASSOC)['c'] > 0) {
  respond_json(['ok'=>false,'error'=>'Нельзя удалять команды после генерации расписания. Сначала очисти расписание этапа.'], 400);
}

// delete
$st = $pdo->prepare("DELETE FROM squads WHERE id=:id");
$st->execute([':id'=>$id]);

respond_json(['ok'=>true,'deleted'=>true,'id'=>$id]);
