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

$st = $pdo->prepare("SELECT id FROM tournaments WHERE id = :id LIMIT 1");
$st->execute([':id' => $id]);
if (!$st->fetchColumn()) {
  respond_json(['ok' => false, 'error' => 'tournament not found'], 404);
}

lc_require_tournament_not_archived($pdo, $id);

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT id FROM stages WHERE tournament_id = :tid");
  $st->execute([':tid' => $id]);
  $stageIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

  $deleted = [
    'publish_log'        => 0,
    'schedule_items'     => 0,
    'stage_days'         => 0,
    'stage_playoff_plan' => 0,
    'squad_constraints'  => 0,
    'tournament'         => 0,
  ];

  if ($stageIds) {
    $in = implode(',', array_fill(0, count($stageIds), '?'));

    $st = $pdo->prepare("DELETE FROM publish_log WHERE stage_id IN ($in)");
    $st->execute($stageIds);
    $deleted['publish_log'] = $st->rowCount();

    $st = $pdo->prepare("DELETE FROM schedule_items WHERE stage_id IN ($in)");
    $st->execute($stageIds);
    $deleted['schedule_items'] = $st->rowCount();

    $st = $pdo->prepare("DELETE FROM stage_days WHERE stage_id IN ($in)");
    $st->execute($stageIds);
    $deleted['stage_days'] = $st->rowCount();

    $st = $pdo->prepare("DELETE FROM stage_playoff_plan WHERE stage_id IN ($in)");
    $st->execute($stageIds);
    $deleted['stage_playoff_plan'] = $st->rowCount();

    $st = $pdo->prepare("DELETE FROM squad_constraints WHERE stage_id IN ($in)");
    $st->execute($stageIds);
    $deleted['squad_constraints'] = $st->rowCount();
  }

  $st = $pdo->prepare("DELETE FROM tournaments WHERE id = :id");
  $st->execute([':id' => $id]);
  $deleted['tournament'] = $st->rowCount();

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