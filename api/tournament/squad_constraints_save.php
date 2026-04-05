<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('organizer');

$pdo = db();
ensure_squad_constraints_table($pdo);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];

$id      = (int)($in['id'] ?? 0);
$stageId = (int)($in['stage_id'] ?? 0);
$squadId = (int)($in['squad_id'] ?? 0);
$dayId   = (int)($in['day_id'] ?? 0);
$date    = trim((string)($in['date'] ?? '')); // YYYY-MM-DD
$nb      = isset($in['not_before_slot_no']) ? (int)$in['not_before_slot_no'] : null;
$na      = isset($in['not_after_slot_no'])  ? (int)$in['not_after_slot_no']  : null;
$comment = trim((string)($in['comment'] ?? ''));

if ($stageId <= 0 || $squadId <= 0) respond_json(['ok'=>false,'error'=>'stage_id and squad_id required'], 400);
if ($dayId <= 0 && $date === '') respond_json(['ok'=>false,'error'=>'day_id or date required'], 400);

// нормализация null
if ($nb !== null && $nb <= 0) $nb = null;
if ($na !== null && $na <= 0) $na = null;

if ($nb !== null && $na !== null && $nb > $na) {
  respond_json(['ok'=>false,'error'=>'not_before_slot_no > not_after_slot_no'], 400);
}

lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $stageId));

if ($id > 0) {
  $sql = "UPDATE squad_constraints
          SET stage_id=?, squad_id=?, day_id=?, day_date=?, not_before_slot_no=?, not_after_slot_no=?, comment=?
          WHERE id=? LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$stageId,$squadId, $dayId>0?$dayId:null, $date!==''?$date:null, $nb, $na, $comment!==''?$comment:null, $id]);
  respond_json(['ok'=>true,'id'=>$id]);
}



// UPSERT без уникального индекса: если уже есть запись на этот stage+squad+day_id/day_date — обновляем её
$existingId = 0;

if ($dayId > 0) {
  $st = $pdo->prepare("SELECT id FROM squad_constraints WHERE stage_id=? AND squad_id=? AND day_id=? LIMIT 1");
  $st->execute([$stageId, $squadId, $dayId]);
  $existingId = (int)($st->fetchColumn() ?: 0);
} elseif ($date !== '') {
  $st = $pdo->prepare("SELECT id FROM squad_constraints WHERE stage_id=? AND squad_id=? AND day_date=? LIMIT 1");
  $st->execute([$stageId, $squadId, $date]);
  $existingId = (int)($st->fetchColumn() ?: 0);
}

if ($existingId > 0) {
  $sql = "UPDATE squad_constraints
          SET stage_id=?, squad_id=?, day_id=?, day_date=?, not_before_slot_no=?, not_after_slot_no=?, comment=?
          WHERE id=? LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([
    $stageId,
    $squadId,
    $dayId>0 ? $dayId : null,
    $date!=='' ? $date : null,
    $nb,
    $na,
    $comment!=='' ? $comment : null,
    $existingId
  ]);
  respond_json(['ok'=>true,'id'=>$existingId]);
}

$sql = "INSERT INTO squad_constraints (stage_id, squad_id, day_id, day_date, not_before_slot_no, not_after_slot_no, comment)
        VALUES (?,?,?,?,?,?,?)";
$st = $pdo->prepare($sql);
$st->execute([$stageId,$squadId, $dayId>0?$dayId:null, $date!==''?$date:null, $nb, $na, $comment!==''?$comment:null]);

respond_json(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);