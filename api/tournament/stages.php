<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

$pdo = db();

$tournamentId = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;

// Определяем, есть ли колонка transition_minutes (чтобы не ловить 500 на разных схемах)
$hasTransition = false;
try {
  $chk = $pdo->query("SHOW COLUMNS FROM stages LIKE 'transition_minutes'");
  $hasTransition = (bool)($chk && $chk->fetch());
} catch (Throwable $e) {
  // если SHOW COLUMNS запрещён/не работает — просто не тащим колонку
  $hasTransition = false;
}

$cols = [
  's.id',
  's.tournament_id',
  's.name',
  's.is_current',
  's.start_date',
  's.end_date',
  's.day_start',
  's.day_end',
  's.fields',
  's.match_minutes',
  's.break_minutes',
  's.min_rest_slots',
  's.timezone',
];
if ($hasTransition) {
  $cols[] = 's.transition_minutes';
} else {
  $cols[] = '15 AS transition_minutes';
}

$sql = "SELECT\n  " . implode(",\n  ", $cols) . "\nFROM stages s\nWHERE 1=1";
$params = [];

if ($tournamentId > 0) {
  $sql .= " AND s.tournament_id = :tid";
  $params[':tid'] = $tournamentId;
}

$sql .= " ORDER BY s.is_current DESC, s.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

respond_json(['ok' => true, 'stages' => $rows]);
