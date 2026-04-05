<?php
// /api/tournament/matches.php
// Матчи по этапу/категории/варианту + опционально по фазе.
// Для плей-офф мастер-экрана (схема) нам достаточно списка матчей и названий команд.

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_role('organizer');

$stage_id = (int)($_GET['stage_id'] ?? 0);
$category = (int)($_GET['category'] ?? 0);
$variant  = (int)($_GET['variant'] ?? 0);
$phase    = isset($_GET['phase']) ? trim((string)$_GET['phase']) : '';

if ($stage_id <= 0 || $category <= 0) {
  respond_json(['ok'=>false,'error'=>'stage_id/category required'], 200);
}
if ($variant <= 0) $variant = 1;

// phase: только безопасные значения. Пусто = не фильтруем (оставляем все фазы)
if ($phase !== '' && !preg_match('~^[a-z0-9_]{1,24}$~i', $phase)) {
  respond_json(['ok'=>false,'error'=>'bad phase'], 200);
}

$pdo = db();

$params = [
  ':stage_id' => $stage_id,
  ':category' => $category,
  ':variant'  => $variant,
];

$wherePhase = '';
if ($phase !== '') {
  $wherePhase = ' AND m.phase = :phase ';
  $params[':phase'] = $phase;
}

$sql = "
SELECT
  m.id,
  m.tournament_id,
  m.stage_id,
  m.category,
  m.variant,
  m.group_code,
  m.round_no,
  m.home_squad_id,
  m.away_squad_id,
  m.phase,
  m.code,
  m.home_label,
  m.away_label,
  m.home_ref,
  m.away_ref,
  sh.name AS home_name,
  sa.name AS away_name
FROM matches m
LEFT JOIN squads sh ON sh.id = m.home_squad_id
LEFT JOIN squads sa ON sa.id = m.away_squad_id
WHERE m.stage_id = :stage_id
  AND m.category = :category
  AND m.variant = :variant
  {$wherePhase}
ORDER BY
  CASE WHEN m.phase='group' THEN 0 WHEN m.phase='playoff' THEN 1 ELSE 2 END,
  m.group_code,
  m.round_no,
  m.id
";

try {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  respond_json([
    'ok' => true,
    'matches' => $rows,
    'count' => count($rows),
  ], 200);

} catch (Throwable $e) {
  respond_json([
    'ok' => false,
    'error' => $e->getMessage(),
  ], 200);
}
