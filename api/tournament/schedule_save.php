<?php
declare(strict_types=1);

require_once __DIR__.'/_bootstrap.php';
require_once __DIR__.'/_schema.php';
require_once __DIR__.'/_auth.php';

require_role('organizer');

$pdo = db();
ensure_schedule_items_table($pdo);

$body = read_json_body();
$stageId = (int)($body['stage_id'] ?? ($_POST['stage_id'] ?? 0));

if ($stageId <= 0) {
  respond_json(['ok'=>false,'error'=>'stage_id required'], 400);
}

lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $stageId));

$items = $body['items'] ?? null;
$assignments = $body['assignments'] ?? null;

if (!is_array($items) && is_array($assignments)) {
  // поддержка формата schedule_lab.php
  $items = [];
  foreach ($assignments as $a) {
    if (!is_array($a)) continue;
$items[] = [
  'day_date' => (string)($a['date'] ?? ''),
  'slot_index' => (int)($a['slot_index'] ?? $a['slot_no'] ?? 0),
  'resource_code' => (string)($a['field_code'] ?? ''),
  'match_id' => (int)($a['match_id'] ?? 0),
];
  }
}

if (!is_array($items)) {
  respond_json(['ok'=>false,'error'=>'items required'], 400);
}

// нормализация + валидация
$norm = [];
foreach ($items as $it) {
  if (!is_array($it)) continue;
  $dayDate = (string)($it['day_date'] ?? '');
  $slotIndex = (int)($it['slot_index'] ?? 0);
  $resCode = (string)($it['resource_code'] ?? '');
  $matchId = (int)($it['match_id'] ?? 0);

  if ($dayDate === '' || $slotIndex <= 0 || $resCode === '') continue;
  if ($matchId <= 0) continue; // пустые слоты не пишем

  $norm[] = [$dayDate, $slotIndex, $resCode, $matchId];
}

$driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$pdo->beginTransaction();
try {
  // проще и надёжнее: сохраняем как "слепок" по этапу
  $st = $pdo->prepare('DELETE FROM schedule_items WHERE stage_id=?');
  $st->execute([$stageId]);

  if (!empty($norm)) {
    if ($driver === 'sqlite') {
      $sql = 'INSERT INTO schedule_items(stage_id, day_date, slot_index, resource_code, match_id)
              VALUES(?,?,?,?,?)
              ON CONFLICT(stage_id, day_date, slot_index, resource_code)
              DO UPDATE SET match_id=excluded.match_id, updated_at=CURRENT_TIMESTAMP';
    } else {
      $sql = 'INSERT INTO schedule_items(stage_id, day_date, slot_index, resource_code, match_id)
              VALUES(?,?,?,?,?)
              ON DUPLICATE KEY UPDATE match_id=VALUES(match_id), updated_at=CURRENT_TIMESTAMP';
    }

    $ins = $pdo->prepare($sql);
    foreach ($norm as [$dayDate, $slotIndex, $resCode, $matchId]) {
      $ins->execute([$stageId, $dayDate, $slotIndex, $resCode, $matchId]);
    }
  }

  $pdo->commit();
  respond_json(['ok'=>true,'saved'=>count($norm)]);
} catch (Throwable $e) {
  $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>$e->getMessage()], 500);
}
