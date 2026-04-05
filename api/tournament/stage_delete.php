<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$b = read_json_body();
$id = (int)($b['id'] ?? 0);
if ($id <= 0) {
  respond_json(['ok' => false, 'error' => 'id required'], 400);
}

$pdo = db();

$st = $pdo->prepare("SELECT id, tournament_id FROM stages WHERE id = :id LIMIT 1");
$st->execute([':id' => $id]);
$stageRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$stageRow) {
  respond_json(['ok' => false, 'error' => 'stage not found'], 404);
}

lc_require_tournament_not_archived($pdo, (int)$stageRow['tournament_id']);

try {
  $pdo->beginTransaction();

  $deleted = [
    'publish_log'        => 0,
    'schedule_items'     => 0,
    'stage_days'         => 0,
    'stage_playoff_plan' => 0,
    'squad_constraints'  => 0,
    'stage'              => 0,
  ];

  $st = $pdo->prepare("DELETE FROM publish_log WHERE stage_id = :id");
  $st->execute([':id' => $id]);
  $deleted['publish_log'] = $st->rowCount();

  $st = $pdo->prepare("DELETE FROM schedule_items WHERE stage_id = :id");
  $st->execute([':id' => $id]);
  $deleted['schedule_items'] = $st->rowCount();

  $st = $pdo->prepare("DELETE FROM stage_days WHERE stage_id = :id");
  $st->execute([':id' => $id]);
  $deleted['stage_days'] = $st->rowCount();

  $st = $pdo->prepare("DELETE FROM stage_playoff_plan WHERE stage_id = :id");
  $st->execute([':id' => $id]);
  $deleted['stage_playoff_plan'] = $st->rowCount();

  $st = $pdo->prepare("DELETE FROM squad_constraints WHERE stage_id = :id");
  $st->execute([':id' => $id]);
  $deleted['squad_constraints'] = $st->rowCount();

  $st = $pdo->prepare("DELETE FROM stages WHERE id = :id");
  $st->execute([':id' => $id]);
  $deleted['stage'] = $st->rowCount();

  $pdo->commit();

  respond_json([
    'ok'      => true,
    'id'      => $id,
    'deleted' => $deleted,
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  respond_json([
    'ok'    => false,
    'error' => $e->getMessage(),
  ], 500);
}