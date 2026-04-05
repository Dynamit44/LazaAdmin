<?php
declare(strict_types=1);

require_once __DIR__.'/_bootstrap.php';
require_once __DIR__.'/_schema.php';
require_once __DIR__.'/_auth.php';

// читать нужно и оператору, и организатору
require_role('operator','organizer');

$pdo = db();
ensure_schedule_items_table($pdo);

$stageId = (int)($_GET['stage_id'] ?? 0);
$dayDate = (string)($_GET['day_date'] ?? '');

if ($stageId <= 0) {
  respond_json(['ok'=>false,'error'=>'stage_id required'], 400);
}

$where = ' WHERE stage_id = ?';
$params = [$stageId];

if ($dayDate !== '') {
  $where .= ' AND day_date = ?';
  $params[] = $dayDate;
}

$sql = "SELECT id, stage_id, day_date, slot_index, resource_code, match_id, created_at, updated_at
        FROM schedule_items
        $where
        ORDER BY day_date ASC, slot_index ASC, resource_code ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

respond_json(['ok'=>true,'items'=>$items]);
