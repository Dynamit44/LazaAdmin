<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}
$b = read_json_body();

$squadId = (int)($b['squad_id'] ?? 0);
$coachIds = $b['coach_ids'] ?? [];

if ($squadId <= 0) respond_json(['ok'=>false,'error'=>'squad_id required'], 400);
if (!is_array($coachIds)) respond_json(['ok'=>false,'error'=>'coach_ids must be array'], 400);

$coachIds = array_values(array_unique(array_map('intval', $coachIds)));

$pdo = db();

$stSq = $pdo->prepare('SELECT stage_id FROM squads WHERE id = :id LIMIT 1');
$stSq->execute([':id' => $squadId]);
$sqStageId = (int)($stSq->fetchColumn() ?: 0);
if ($sqStageId <= 0) {
  respond_json(['ok'=>false,'error'=>'squad not found'], 404);
}
lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $sqStageId));

$pdo->beginTransaction();
try {
  $del = $pdo->prepare("DELETE FROM squad_coaches WHERE squad_id=:sid");
  $del->execute([':sid'=>$squadId]);

  if ($coachIds) {
    $ins = $pdo->prepare("INSERT INTO squad_coaches(squad_id, coach_id) VALUES(:sid,:cid)");
    foreach ($coachIds as $cid) {
      if ($cid <= 0) continue;
      $ins->execute([':sid'=>$squadId, ':cid'=>$cid]);
    }
  }

  $pdo->commit();
  respond_json(['ok'=>true]);
} catch (Throwable $e) {
  $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>$e->getMessage()], 400);
}
