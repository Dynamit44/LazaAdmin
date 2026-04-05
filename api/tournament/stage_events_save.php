<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$pdo = db();
ensure_schedule_stage_events_table($pdo);

$b = read_json_body();
$stageId = (int)($b['stage_id'] ?? 0);
$items = $b['items'] ?? [];
if ($stageId <= 0 || !is_array($items)) {
  respond_json(['ok'=>false,'error'=>'stage_id и items обязательны'], 400);
}

$st = $pdo->prepare('SELECT id, start_date, end_date, tournament_id FROM stages WHERE id=:id LIMIT 1');
$st->execute([':id' => $stageId]);
$stage = $st->fetch();
if (!$stage) {
  respond_json(['ok'=>false,'error'=>'Stage not found'], 404);
}

lc_require_tournament_not_archived($pdo, (int)($stage['tournament_id'] ?? 0));

$startDate = (string)($stage['start_date'] ?? '');
$endDate   = (string)($stage['end_date'] ?? '');
if ($startDate === '' || $endDate === '') {
  respond_json(['ok'=>false,'error'=>'У этапа не заданы даты start_date/end_date'], 400);
}

$allowedDates = [];
try {
  $d1 = new DateTimeImmutable($startDate);
  $d2 = new DateTimeImmutable($endDate);
  if ($d2 < $d1) {
    respond_json(['ok'=>false,'error'=>'end_date раньше start_date'], 400);
  }
  for ($cur = $d1; $cur <= $d2; $cur = $cur->modify('+1 day')) {
    $allowedDates[$cur->format('Y-m-d')] = true;
  }
} catch (Throwable $e) {
  respond_json(['ok'=>false,'error'=>'Некорректные даты этапа'], 400);
}

function _evt_norm_time(string $t): string {
  $t = trim($t);
  if (preg_match('~^\d{2}:\d{2}$~', $t)) return $t . ':00';
  if (preg_match('~^\d{2}:\d{2}:\d{2}$~', $t)) return $t;
  return '';
}

function _evt_hm(string $t): string {
  return substr($t, 0, 5);
}

$allowedTypes = ['opening'=>true, 'closing'=>true, 'break'=>true, 'awarding'=>true, 'custom'=>true];
$clean = [];
foreach ($items as $idx => $it) {
  if (!is_array($it)) continue;

  $eventDate = trim((string)($it['event_date'] ?? ''));
  $timeFrom  = _evt_norm_time((string)($it['time_from'] ?? ''));
  $timeTo    = _evt_norm_time((string)($it['time_to'] ?? ''));
  $eventType = trim((string)($it['event_type'] ?? 'custom'));
  $title     = trim((string)($it['title'] ?? ''));
  $isActive  = (int)($it['is_active'] ?? 1) ? 1 : 0;

  if ($eventDate === '' || !isset($allowedDates[$eventDate])) {
    respond_json(['ok'=>false,'error'=>'Событие вне диапазона этапа: #' . ($idx + 1)], 400);
  }
  if ($timeFrom === '' || $timeTo === '') {
    respond_json(['ok'=>false,'error'=>'Некорректное время события: #' . ($idx + 1)], 400);
  }
  if ($timeFrom >= $timeTo) {
    respond_json(['ok'=>false,'error'=>'time_from должен быть раньше time_to: #' . ($idx + 1)], 400);
  }
  if (!isset($allowedTypes[$eventType])) {
    $eventType = 'custom';
  }
  if ($title === '') {
    respond_json(['ok'=>false,'error'=>'У события должно быть название: #' . ($idx + 1)], 400);
  }

  $clean[] = [
    'event_date' => $eventDate,
    'time_from' => $timeFrom,
    'time_to' => $timeTo,
    'event_type' => $eventType,
    'title' => $title,
    'is_active' => $isActive,
  ];
}

$pdo->beginTransaction();
try {
  $del = $pdo->prepare('DELETE FROM schedule_stage_events WHERE stage_id=:sid');
  $del->execute([':sid' => $stageId]);

  $ins = $pdo->prepare('INSERT INTO schedule_stage_events(stage_id, event_date, time_from, time_to, event_type, title, is_active) VALUES(:sid,:d,:f,:t,:type,:title,:active)');

  foreach ($clean as $it) {
    $ins->execute([
      ':sid' => $stageId,
      ':d' => $it['event_date'],
      ':f' => $it['time_from'],
      ':t' => $it['time_to'],
      ':type' => $it['event_type'],
      ':title' => $it['title'],
      ':active' => $it['is_active'],
    ]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>'Server error: ' . $e->getMessage()], 500);
}

respond_json([
  'ok' => true,
  'saved' => count($clean),
  'items' => array_map(static function(array $it): array {
    return [
      'event_date' => $it['event_date'],
      'time_from' => _evt_hm($it['time_from']),
      'time_to' => _evt_hm($it['time_to']),
      'event_type' => $it['event_type'],
      'title' => $it['title'],
      'is_active' => $it['is_active'],
    ];
  }, $clean),
]);
