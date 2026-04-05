<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer','operator');

$stageId = (int)($_GET['stage_id'] ?? 0);
if ($stageId <= 0) {
  respond_json(['ok'=>false,'error'=>'stage_id required'], 400);
}

$pdo = db();

// stage
$st = $pdo->prepare("
  SELECT id, tournament_id, name, start_date, end_date, fields, day_start, day_end
  FROM stages
  WHERE id = :id
  LIMIT 1
");
$st->execute([':id'=>$stageId]);
$stage = $st->fetch(PDO::FETCH_ASSOC);
if (!$stage) {
  respond_json(['ok'=>false,'error'=>'stage not found'], 404);
}

// categories from squads
$st = $pdo->prepare("
  SELECT
    s.category,
    COUNT(*) AS teams
  FROM squads s
  WHERE s.stage_id = :sid
  GROUP BY s.category
  ORDER BY s.category DESC
");
$st->execute([':sid'=>$stageId]);
$cats = $st->fetchAll(PDO::FETCH_ASSOC);

function rrMatches(int $teams): int {
  return (int)(($teams * ($teams - 1)) / 2);
}

function calcFormat(int $teams): array {
  // Под боевой турнир и текущие договорённости:
  // 4 -> круг + 1-4/2-3 + финалы
  // 5 -> круг + 1-2, 3-4
  // 6 -> круг
  // 8 -> 2x4 + перекрест + финалы на 1-8
  switch ($teams) {
    case 4:
      return [
        'format' => 'Круг + 1-4 / 2-3 + финалы',
        'group_matches' => rrMatches(4),   // 6
        'playoff_matches' => 4,            // 1-4, 2-3, финал, 3 место
        'team_min_matches' => 5,
        'note' => ''
      ];

    case 5:
      return [
        'format' => 'Круг + 1-2 / 3-4',
        'group_matches' => rrMatches(5),   // 10
        'playoff_matches' => 2,
        'team_min_matches' => 4,
        'note' => 'Исключение: 5 место остаётся с 4 матчами'
      ];

    case 6:
      return [
        'format' => 'Одна группа в круг',
        'group_matches' => rrMatches(6),   // 15
        'playoff_matches' => 0,
        'team_min_matches' => 5,
        'note' => ''
      ];

    case 8:
      return [
        'format' => '2 группы по 4 + стыки + финалы 1-8',
        'group_matches' => rrMatches(4) * 2, // 12
        'playoff_matches' => 8,              // 4 перекреста + 4 финала по местам
        'team_min_matches' => 5,
        'note' => ''
      ];

    default:
      return [
        'format' => 'Не задано',
        'group_matches' => 0,
        'playoff_matches' => 0,
        'team_min_matches' => 0,
        'note' => 'Для этого количества команд формат пока не описан'
      ];
  }
}

$rows = [];
$totalTeams = 0;
$totalGroup = 0;
$totalPlayoff = 0;
$totalMatches = 0;

foreach ($cats as $row) {
  $category = trim((string)$row['category']);
  $teams = (int)$row['teams'];

  $calc = calcFormat($teams);
  $groupMatches = (int)$calc['group_matches'];
  $playoffMatches = (int)$calc['playoff_matches'];
  $allMatches = $groupMatches + $playoffMatches;

  $rows[] = [
    'category' => $category,
    'teams' => $teams,
    'format' => $calc['format'],
    'group_matches' => $groupMatches,
    'playoff_matches' => $playoffMatches,
    'total_matches' => $allMatches,
    'team_min_matches' => (int)$calc['team_min_matches'],
    'note' => $calc['note'],
  ];

  $totalTeams += $teams;
  $totalGroup += $groupMatches;
  $totalPlayoff += $playoffMatches;
  $totalMatches += $allMatches;
}

// days count
$daysCount = 0;
if (!empty($stage['start_date']) && !empty($stage['end_date'])) {
  try {
    $d1 = new DateTimeImmutable($stage['start_date']);
    $d2 = new DateTimeImmutable($stage['end_date']);
    $daysCount = (int)$d1->diff($d2)->days + 1;
  } catch (Throwable $e) {
    $daysCount = 0;
  }
}

$avgPerDay = ($daysCount > 0) ? round($totalMatches / $daysCount, 2) : null;

respond_json([
  'ok' => true,
  'stage' => $stage,
  'summary' => [
    'categories' => count($rows),
    'teams' => $totalTeams,
    'group_matches' => $totalGroup,
    'playoff_matches' => $totalPlayoff,
    'total_matches' => $totalMatches,
    'days_count' => $daysCount,
    'avg_per_day' => $avgPerDay,
  ],
  'rows' => $rows,
]);