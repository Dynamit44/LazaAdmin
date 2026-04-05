<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = ($method === 'POST') ? read_json_body() : $_GET;

if (!is_array($input)) {
  respond_json(['ok' => false, 'error' => 'Bad request'], 400);
}

$stageId = (int)($input['stage_id'] ?? 0);
if ($stageId <= 0) {
  respond_json(['ok' => false, 'error' => 'stage_id required'], 400);
}

$pdo = db();

try {
  $report = [
    'slot_duplicates'        => [],
    'match_duplicates'       => [],
    'schedule_items_orphans' => [],
    'schedule_orphans'       => [],
    'results_orphans'        => [],
    'team_conflicts'         => [],
    'coach_conflicts'        => [],
  ];

  // 1. Дубли слотов в schedule_items
  $st = $pdo->prepare("
    SELECT
      si.stage_id,
      si.day_date,
      si.slot_index,
      si.resource_code,
      COUNT(*) AS cnt,
      GROUP_CONCAT(si.match_id ORDER BY si.match_id SEPARATOR ',') AS match_ids
    FROM schedule_items si
    WHERE si.stage_id = ?
    GROUP BY si.stage_id, si.day_date, si.slot_index, si.resource_code
    HAVING COUNT(*) > 1
    ORDER BY si.day_date, si.slot_index, si.resource_code
  ");
  $st->execute([$stageId]);
  $report['slot_duplicates'] = $st->fetchAll(PDO::FETCH_ASSOC);

  // 2. Один match_id в нескольких слотах
  $st = $pdo->prepare("
    SELECT
      si.match_id,
      COUNT(*) AS cnt,
      GROUP_CONCAT(
        CONCAT(si.day_date, ' / слот ', si.slot_index, ' / ресурс ', si.resource_code)
        ORDER BY si.day_date, si.slot_index, si.resource_code
        SEPARATOR ' || '
      ) AS places
    FROM schedule_items si
    WHERE si.stage_id = ?
      AND si.match_id IS NOT NULL
    GROUP BY si.match_id
    HAVING COUNT(*) > 1
    ORDER BY si.match_id
  ");
  $st->execute([$stageId]);
  $report['match_duplicates'] = $st->fetchAll(PDO::FETCH_ASSOC);

  // 3. Висячие match_id в schedule_items
  $st = $pdo->prepare("
    SELECT
      si.id,
      si.day_date,
      si.slot_index,
      si.resource_code,
      si.match_id
    FROM schedule_items si
    LEFT JOIN matches m ON m.id = si.match_id
    WHERE si.stage_id = ?
      AND si.match_id IS NOT NULL
      AND m.id IS NULL
    ORDER BY si.day_date, si.slot_index, si.resource_code, si.id
  ");
  $st->execute([$stageId]);
  $report['schedule_items_orphans'] = $st->fetchAll(PDO::FETCH_ASSOC);

  // 4. Висячие match_id в schedule
  // У schedule нет stage_id, поэтому фильтруем через stage матчей и отдельно ловим сироты
  $st = $pdo->query("
    SELECT
      s.match_id,
      s.start_time,
      s.field_code
    FROM schedule s
    LEFT JOIN matches m ON m.id = s.match_id
    WHERE s.match_id IS NOT NULL
      AND m.id IS NULL
    ORDER BY s.match_id
  ");
  $report['schedule_orphans'] = $st->fetchAll(PDO::FETCH_ASSOC);

  // 5. Висячие match_id в results
  $st = $pdo->query("
    SELECT
      r.match_id,
      r.home_goals,
      r.away_goals,
      r.updated_by,
      r.updated_at
    FROM results r
    LEFT JOIN matches m ON m.id = r.match_id
    WHERE r.match_id IS NOT NULL
      AND m.id IS NULL
    ORDER BY r.match_id
  ");
  $report['results_orphans'] = $st->fetchAll(PDO::FETCH_ASSOC);

  // 6. Конфликты команды в одном слоте
  // ВАЖНО: если у тебя в matches поля называются не home_squad_id/away_squad_id,
  // а иначе, замени их здесь.
  $st = $pdo->prepare("
    SELECT
      x.day_date,
      x.slot_index,
      x.team_id,
      COUNT(*) AS cnt,
      GROUP_CONCAT(x.match_id ORDER BY x.match_id SEPARATOR ',') AS match_ids
    FROM (
      SELECT
        si.day_date,
        si.slot_index,
        m.id AS match_id,
        m.home_squad_id AS team_id
      FROM schedule_items si
      JOIN matches m ON m.id = si.match_id
      WHERE si.stage_id = ?
        AND m.home_squad_id IS NOT NULL

      UNION ALL

      SELECT
        si.day_date,
        si.slot_index,
        m.id AS match_id,
        m.away_squad_id AS team_id
      FROM schedule_items si
      JOIN matches m ON m.id = si.match_id
      WHERE si.stage_id = ?
        AND m.away_squad_id IS NOT NULL
    ) x
    GROUP BY x.day_date, x.slot_index, x.team_id
    HAVING COUNT(*) > 1
    ORDER BY x.day_date, x.slot_index, x.team_id
  ");
  $st->execute([$stageId, $stageId]);
  $report['team_conflicts'] = $st->fetchAll(PDO::FETCH_ASSOC);

  // 7. Конфликты тренера в одном слоте
  // ВАЖНО: если у тебя в matches поля называются иначе, замени их и тут.
  $st = $pdo->prepare("
    SELECT
      z.day_date,
      z.slot_index,
      z.coach_id,
      COUNT(DISTINCT z.match_id) AS cnt,
      GROUP_CONCAT(DISTINCT z.match_id ORDER BY z.match_id SEPARATOR ',') AS match_ids,
      GROUP_CONCAT(DISTINCT z.squad_id ORDER BY z.squad_id SEPARATOR ',') AS squad_ids
    FROM (
      SELECT
        si.day_date,
        si.slot_index,
        m.id AS match_id,
        sc.coach_id,
        sc.squad_id
      FROM schedule_items si
      JOIN matches m ON m.id = si.match_id
      JOIN squad_coaches sc ON sc.squad_id = m.home_squad_id
      WHERE si.stage_id = ?

      UNION ALL

      SELECT
        si.day_date,
        si.slot_index,
        m.id AS match_id,
        sc.coach_id,
        sc.squad_id
      FROM schedule_items si
      JOIN matches m ON m.id = si.match_id
      JOIN squad_coaches sc ON sc.squad_id = m.away_squad_id
      WHERE si.stage_id = ?
    ) z
    GROUP BY z.day_date, z.slot_index, z.coach_id
    HAVING COUNT(DISTINCT z.match_id) > 1
    ORDER BY z.day_date, z.slot_index, z.coach_id
  ");
  $st->execute([$stageId, $stageId]);
  $report['coach_conflicts'] = $st->fetchAll(PDO::FETCH_ASSOC);

  $summary = [];
  foreach ($report as $key => $rows) {
    $summary[$key] = is_array($rows) ? count($rows) : 0;
  }

  $totalProblems = array_sum($summary);

  respond_json([
    'ok'             => true,
    'stage_id'       => $stageId,
    'has_problems'   => $totalProblems > 0,
    'total_problems' => $totalProblems,
    'summary'        => $summary,
    'report'         => $report,
  ]);

} catch (Throwable $e) {
  respond_json([
    'ok'    => false,
    'error' => $e->getMessage(),
  ], 500);
}