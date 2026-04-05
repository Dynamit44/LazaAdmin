<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();

$id            = (int)($b['id'] ?? 0);
$tournament_id = (int)($b['tournament_id'] ?? 0);
$name          = trim((string)($b['name'] ?? ''));

$start_date = (string)($b['start_date'] ?? '');
$end_date   = (string)($b['end_date'] ?? '');

$day_start = trim((string)($b['day_start'] ?? ''));
$day_end   = trim((string)($b['day_end'] ?? ''));

$fields            = (int)($b['fields'] ?? 4);
$match_minutes     = (int)($b['match_minutes'] ?? 20);
$break_minutes     = (int)($b['break_minutes'] ?? 5);
$transitionMinutes = (int)($b['transition_minutes'] ?? 15);
$min_rest_slots    = (int)($b['min_rest_slots'] ?? 0);

$timezone = trim((string)($b['timezone'] ?? 'Europe/Moscow'));
if ($timezone === '') $timezone = 'Europe/Moscow';

// validate
if ($tournament_id <= 0) respond_json(['ok'=>false,'error'=>'tournament_id required'], 400);
if ($name === '') respond_json(['ok'=>false,'error'=>'name required'], 400);

if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $start_date)) respond_json(['ok'=>false,'error'=>'start_date invalid'], 400);
if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $end_date)) respond_json(['ok'=>false,'error'=>'end_date invalid'], 400);
if ($end_date < $start_date) respond_json(['ok'=>false,'error'=>'end_date < start_date'], 400);

// day_start/day_end: поддержим HH:MM и HH:MM:SS
if (!preg_match('~^\d{2}:\d{2}(:\d{2})?$~', $day_start)) respond_json(['ok'=>false,'error'=>'day_start invalid'], 400);
if (!preg_match('~^\d{2}:\d{2}(:\d{2})?$~', $day_end)) respond_json(['ok'=>false,'error'=>'day_end invalid'], 400);
if (strlen($day_start) === 5) $day_start .= ':00';
if (strlen($day_end) === 5) $day_end .= ':00';
if ($day_end <= $day_start) respond_json(['ok'=>false,'error'=>'day_end must be > day_start'], 400);

if ($fields < 1 || $fields > 4) respond_json(['ok'=>false,'error'=>'fields must be 1..4'], 400);
if ($match_minutes <= 0) respond_json(['ok'=>false,'error'=>'match_minutes must be > 0'], 400);
if ($break_minutes < 0) respond_json(['ok'=>false,'error'=>'break_minutes must be >= 0'], 400);
if ($transitionMinutes < 0) respond_json(['ok'=>false,'error'=>'transition_minutes must be >= 0'], 400);
if ($min_rest_slots < 0) respond_json(['ok'=>false,'error'=>'min_rest_slots must be >= 0'], 400);

$pdo = db();

// tournament exists
$st = $pdo->prepare("SELECT id FROM tournaments WHERE id=:id");
$st->execute([':id'=>$tournament_id]);
if (!$st->fetchColumn()) respond_json(['ok'=>false,'error'=>'tournament not found'], 404);

lc_require_tournament_not_archived($pdo, $tournament_id);

// На всякий случай: если transition_minutes вдруг нет — добавим (MySQL)
try {
  $chk = $pdo->query("SHOW COLUMNS FROM stages LIKE 'transition_minutes'");
  if (!$chk || !$chk->fetch()) {
    $pdo->exec("ALTER TABLE stages ADD COLUMN transition_minutes INT NOT NULL DEFAULT 15");
  }
} catch (Throwable $e) {
  // если нет прав — просто поедем дальше (будет ошибка SQL ниже, но тогда хотя бы увидим по обработчику)
}

if ($id > 0) {
  $st = $pdo->prepare("SELECT id, tournament_id FROM stages WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$id]);
  $stageRow = $st->fetch(PDO::FETCH_ASSOC);
  if (!$stageRow) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

  lc_require_tournament_not_archived($pdo, (int)$stageRow['tournament_id']);

  $st = $pdo->prepare("
    UPDATE stages SET
      tournament_id=:tournament_id,
      name=:name,
      start_date=:start_date,
      end_date=:end_date,
      day_start=:day_start,
      day_end=:day_end,
      fields=:fields,
      match_minutes=:match_minutes,
      break_minutes=:break_minutes,
      transition_minutes=:transition_minutes,
      min_rest_slots=:min_rest_slots,
      timezone=:timezone
    WHERE id=:id
  ");
$st->execute([
    ':tournament_id'=>$tournament_id,
    ':name'=>$name,
    ':start_date'=>$start_date,
    ':end_date'=>$end_date,
    ':day_start'=>$day_start,
    ':day_end'=>$day_end,
    ':fields'=>$fields,
    ':match_minutes'=>$match_minutes,
    ':break_minutes'=>$break_minutes,
    ':transition_minutes'=>$transitionMinutes,
    ':min_rest_slots'=>$min_rest_slots,
    ':timezone'=>$timezone,
    ':id'=>$id,
  ]);

  respond_json(['ok'=>true,'id'=>$id]);
}

$st = $pdo->prepare("
  INSERT INTO stages(
    tournament_id, name, start_date, end_date, day_start, day_end,
    fields, match_minutes, break_minutes, transition_minutes, min_rest_slots, timezone, is_current
  ) VALUES(
    :tournament_id, :name, :start_date, :end_date, :day_start, :day_end,
    :fields, :match_minutes, :break_minutes, :transition_minutes, :min_rest_slots, :timezone, 0
  )
");
$st->execute([
  ':tournament_id'=>$tournament_id,
  ':name'=>$name,
  ':start_date'=>$start_date,
  ':end_date'=>$end_date,
  ':day_start'=>$day_start,
  ':day_end'=>$day_end,
  ':fields'=>$fields,
  ':match_minutes'=>$match_minutes,
  ':break_minutes'=>$break_minutes,
  ':transition_minutes'=>$transitionMinutes,
  ':min_rest_slots'=>$min_rest_slots,
  ':timezone'=>$timezone,
]);

respond_json(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
