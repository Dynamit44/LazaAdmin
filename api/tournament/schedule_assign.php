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
$dayDate = (string)($body['day_date'] ?? ($_POST['day_date'] ?? ''));
$slotIndex = (int)($body['slot_index'] ?? ($_POST['slot_index'] ?? 0));
$resCode = (string)($body['resource_code'] ?? ($_POST['resource_code'] ?? ''));
$matchId = (int)($body['match_id'] ?? ($_POST['match_id'] ?? 0));

if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);
if ($dayDate === '') respond_json(['ok'=>false,'error'=>'day_date required'], 400);
if ($slotIndex <= 0) respond_json(['ok'=>false,'error'=>'slot_index required'], 400);
if ($resCode === '') respond_json(['ok'=>false,'error'=>'resource_code required'], 400);

lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $stageId));

$driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$pdo->beginTransaction();
try {
  if ($matchId > 0) {
    // матч не должен сидеть в двух слотах
    $st = $pdo->prepare('DELETE FROM schedule_items WHERE stage_id=? AND match_id=?');
    $st->execute([$stageId, $matchId]);

    if ($driver === 'sqlite') {
      $sql = 'INSERT INTO schedule_items(stage_id, day_date, slot_index, resource_code, match_id)
              VALUES(?,?,?,?,?)
              ON CONFLICT(stage_id, day_date, slot_index, resource_code)
              DO UPDATE SET match_id=excluded.match_id, updated_at=CURRENT_TIMESTAMP';
      $st = $pdo->prepare($sql);
      $st->execute([$stageId, $dayDate, $slotIndex, $resCode, $matchId]);
    } else {
      $sql = 'INSERT INTO schedule_items(stage_id, day_date, slot_index, resource_code, match_id)
              VALUES(?,?,?,?,?)
              ON DUPLICATE KEY UPDATE match_id=VALUES(match_id), updated_at=CURRENT_TIMESTAMP';
      $st = $pdo->prepare($sql);
      $st->execute([$stageId, $dayDate, $slotIndex, $resCode, $matchId]);
    }

  } else {
    // очистка слота
    $st = $pdo->prepare('DELETE FROM schedule_items WHERE stage_id=? AND day_date=? AND slot_index=? AND resource_code=?');
    $st->execute([$stageId, $dayDate, $slotIndex, $resCode]);
  }

  $pdo->commit();
  respond_json(['ok'=>true]);
} catch (Throwable $e) {
  $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>$e->getMessage()], 500);
}
