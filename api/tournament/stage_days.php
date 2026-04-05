<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('organizer');

// GET /api/tournament/stage_days.php?stage_id=11
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  respond_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$stageId = (int)($_GET['stage_id'] ?? 0);
if ($stageId <= 0) {
  respond_json(['ok'=>false,'error'=>'stage_id обязателен'], 400);
}

$pdo = db();
ensure_stage_days_table($pdo);

$st = $pdo->prepare('SELECT * FROM stages WHERE id=:id LIMIT 1');
$st->execute([':id' => $stageId]);
$stage = $st->fetch();
if (!$stage) {
  respond_json(['ok'=>false,'error'=>'Stage not found'], 404);
}

$startDate = (string)($stage['start_date'] ?? '');
$endDate   = (string)($stage['end_date'] ?? '');
if ($startDate === '' || $endDate === '') {
  respond_json(['ok'=>false,'error'=>'У этапа не заданы даты start_date/end_date'], 400);
}

$defaults = [
  'day_start' => (string)($stage['day_start'] ?? '09:00:00'),
  'day_end'   => (string)($stage['day_end'] ?? '18:00:00'),
  'fields'    => (int)($stage['fields'] ?? 1),
];

// overrides
$q = $pdo->prepare('SELECT * FROM stage_days WHERE stage_id=:sid');
$q->execute([':sid' => $stageId]);
$ov = [];
foreach ($q->fetchAll() as $r) {
  $d = (string)$r['day_date'];
  $ov[$d] = $r;
}

// dates inclusive
try {
  $d1 = new DateTimeImmutable($startDate);
  $d2 = new DateTimeImmutable($endDate);
} catch (Throwable $e) {
  respond_json(['ok'=>false,'error'=>'Некорректные даты этапа'], 400);
}

if ($d2 < $d1) {
  respond_json(['ok'=>false,'error'=>'end_date раньше start_date'], 400);
}

$items = [];
$cur = $d1;
while ($cur <= $d2) {
  $key = $cur->format('Y-m-d');
  $r = $ov[$key] ?? null;

  $items[] = [
    'day_date'  => $key,
    'day_start' => $r ? (string)($r['day_start'] ?? '') : $defaults['day_start'],
    'day_end'   => $r ? (string)($r['day_end'] ?? '')   : $defaults['day_end'],
    'fields'    => $r ? (int)($r['fields'] ?? 0)         : $defaults['fields'],
    'is_active' => $r ? (int)($r['is_active'] ?? 1)      : 1,
    'is_custom' => $r ? 1 : 0,
  ];
  $cur = $cur->modify('+1 day');
}

respond_json([
  'ok' => true,
  'defaults' => $defaults,
  'items' => $items,
]);
