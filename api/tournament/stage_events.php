<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('organizer','operator');

try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond_json(['ok' => false, 'error' => 'Method not allowed'], 405);
  }

  $stageId = (int)($_GET['stage_id'] ?? 0);
  if ($stageId <= 0) {
    respond_json(['ok'=>false,'error'=>'stage_id обязателен'], 400);
  }

  $pdo = db();

  if (function_exists('ensure_schedule_stage_events_table')) {
    ensure_schedule_stage_events_table($pdo);
  }

  $stage = null;

  try {
    $st = $pdo->prepare('SELECT id, name, date_from AS start_date, date_to AS end_date FROM stages WHERE id=:id LIMIT 1');
    $st->execute([':id' => $stageId]);
    $stage = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {
    $stage = null;
  }

  if (!$stage) {
    try {
      $st = $pdo->prepare('SELECT id, name, start_date, end_date FROM stages WHERE id=:id LIMIT 1');
      $st->execute([':id' => $stageId]);
      $stage = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
      $stage = null;
    }
  }

  if (!$stage) {
    respond_json(['ok'=>false,'error'=>'Stage not found'], 404);
  }

  $startDate = (string)($stage['start_date'] ?? '');
  $endDate   = (string)($stage['end_date'] ?? '');
  $startDate = $startDate !== '' ? substr($startDate, 0, 10) : '';
  $endDate   = $endDate !== '' ? substr($endDate, 0, 10) : '';

  if ($startDate === '' || $endDate === '') {
    respond_json(['ok'=>false,'error'=>'У этапа не заданы даты'], 400);
  }

  $allowedDates = [];
  try {
    $d1 = new DateTimeImmutable($startDate);
    $d2 = new DateTimeImmutable($endDate);
    if ($d2 < $d1) {
      respond_json(['ok'=>false,'error'=>'Дата окончания раньше даты начала'], 400);
    }
    for ($cur = $d1; $cur <= $d2; $cur = $cur->modify('+1 day')) {
      $allowedDates[] = $cur->format('Y-m-d');
    }
  } catch (Throwable $e) {
    respond_json(['ok'=>false,'error'=>'Некорректные даты этапа'], 400);
  }

  $items = [];
  try {
    $q = $pdo->prepare('SELECT id, stage_id, event_date, time_from, time_to, event_type, title, is_active FROM schedule_stage_events WHERE stage_id=:sid ORDER BY event_date ASC, time_from ASC, id ASC');
    $q->execute([':sid' => $stageId]);
    foreach (($q->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
      $items[] = [
        'id' => (int)($r['id'] ?? 0),
        'stage_id' => (int)($r['stage_id'] ?? 0),
        'event_date' => (string)($r['event_date'] ?? ''),
        'time_from' => substr((string)($r['time_from'] ?? ''), 0, 5),
        'time_to' => substr((string)($r['time_to'] ?? ''), 0, 5),
        'event_type' => (string)($r['event_type'] ?? 'custom'),
        'title' => (string)($r['title'] ?? ''),
        'is_active' => (int)($r['is_active'] ?? 1),
      ];
    }
  } catch (Throwable $e) {
    // если таблица/колонки ещё не готовы — просто отдаём пустой список, а не 500
    $items = [];
  }

  respond_json([
    'ok' => true,
    'stage' => [
      'id' => (int)($stage['id'] ?? 0),
      'name' => (string)($stage['name'] ?? ''),
      'start_date' => $startDate,
      'end_date' => $endDate,
    ],
    'allowed_dates' => $allowedDates,
    'items' => $items,
  ]);

} catch (Throwable $e) {
  respond_json([
    'ok' => false,
    'error' => 'stage_events fatal: ' . $e->getMessage(),
  ], 500);
}
