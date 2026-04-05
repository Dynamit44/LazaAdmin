<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require __DIR__ . '/_logos.php';
require_role('operator','organizer');

$pdo = db();
ensure_clubs_logo_path($pdo);
ensure_results_penalty_columns($pdo);

function matches_has_column(PDO $pdo, string $col): bool {
  static $cols = null;
  if ($cols === null) {
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM matches") as $r) {
      $cols[(string)$r['Field']] = true;
    }
  }
  return isset($cols[$col]);
}

function results_has_penalty_columns(PDO $pdo): bool {
  static $cols = null;
  if ($cols === null) {
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM results") as $r) {
      $cols[(string)$r['Field']] = true;
    }
  }
  return isset($cols['home_pen_goals']) && isset($cols['away_pen_goals']);
}

$extraWhere = matches_has_column($pdo, 'phase') ? " AND m.phase=\'group\' " : "";

$stageId  = (int)($_GET['stage_id'] ?? 0);
$category = trim((string)($_GET['category'] ?? ''));
$variant  = (int)($_GET['variant'] ?? 1);
$type     = trim((string)($_GET['type'] ?? 'schedule'));

if ($stageId <= 0) {
  respond_json(['ok'=>false,'error'=>'stage_id required'], 400);
}

if ($type === 'stage_categories') {
  $st = $pdo->prepare('
    SELECT category, match_minutes
    FROM stage_categories
    WHERE stage_id=:sid
    ORDER BY category DESC
  ');
  $st->execute([':sid'=>$stageId]);
  respond_json(['ok'=>true, 'stage_id'=>$stageId, 'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($type === 'variants') {
  if ($category === '') {
    respond_json(['ok'=>false,'error'=>'category required'], 400);
  }
  $st = $pdo->prepare('
    SELECT DISTINCT category_variant AS variant
    FROM squads
    WHERE stage_id=:sid AND category=:cat
    ORDER BY variant ASC
  ');
  $st->execute([':sid'=>$stageId, ':cat'=>$category]);
  $items = array_map(fn($r)=> (int)$r['variant'], $st->fetchAll(PDO::FETCH_ASSOC));
  respond_json(['ok'=>true, 'stage_id'=>$stageId, 'category'=>$category, 'items'=>$items]);
}

if ($category === '' || $variant <= 0) {
  respond_json(['ok'=>false,'error'=>'category and variant required'], 400);
}

if ($type === 'schedule') {
  $st = $pdo->prepare('
    SELECT s.start_time, s.field_code,
           m.id AS match_id, m.group_code, m.round_no,
           sh.name AS home, sa.name AS away,
           r.home_goals, r.away_goals
    FROM schedule s
    INNER JOIN matches m ON m.id = s.match_id
    INNER JOIN squads sh ON sh.id = m.home_squad_id
    INNER JOIN squads sa ON sa.id = m.away_squad_id
    LEFT JOIN results r ON r.match_id = m.id
    WHERE m.stage_id=:sid AND m.category=:cat AND m.variant=:v
    ORDER BY s.start_time, s.field_code
  ');
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  respond_json(['ok'=>true, 'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($type === 'groups') {
  $st = $pdo->prepare('
    SELECT ge.group_code, ge.pos, ge.manual_place,
           s.id, s.name,
           c.name AS club, c.city AS city, c.logo_path AS logo_path,
           s.category_variant, s.rating
    FROM group_entries ge
    INNER JOIN squads s ON s.id = ge.squad_id
    LEFT JOIN clubs c ON c.id = s.club_id
    WHERE ge.stage_id=:sid AND ge.category=:cat AND ge.variant=:v
    ORDER BY ge.group_code, ge.pos
  ');
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $groups = [];
  foreach ($rows as $r) {
    $g = $r['group_code'];
    if (!isset($groups[$g])) $groups[$g] = [];
    $groups[$g][] = [
      'id'=>(int)$r['id'],
      'pos'=>(int)$r['pos'],
      'manual_place'=>($r['manual_place']===null ? null : (int)$r['manual_place']),
      'name'=>$r['name'],
      'club'=>$r['club'],
      'city'=>$r['city'],
      'logo_url'=>lc_logo_public_url((string)($r['logo_path'] ?? '')),
      'variant'=>(int)$r['category_variant'],
      'rating'=>(int)$r['rating'],
    ];
  }

  respond_json(['ok'=>true, 'groups'=>$groups]);
}

if ($type === 'standings') {
  $st = $pdo->prepare('
    SELECT s.id, s.name,
           SUM(CASE WHEN m.home_squad_id=s.id THEN r.home_goals ELSE r.away_goals END) AS gf,
           SUM(CASE WHEN m.home_squad_id=s.id THEN r.away_goals ELSE r.home_goals END) AS ga,
           SUM(CASE
             WHEN (m.home_squad_id=s.id AND r.home_goals>r.away_goals) OR (m.away_squad_id=s.id AND r.away_goals>r.home_goals) THEN 3
             WHEN r.home_goals=r.away_goals THEN 1
             ELSE 0
           END) AS pts
    FROM squads s
    LEFT JOIN matches m ON (m.home_squad_id=s.id OR m.away_squad_id=s.id)
       AND m.stage_id=:sid AND m.category=:cat AND m.variant=:v
    LEFT JOIN results r ON r.match_id = m.id
    WHERE s.stage_id=:sid AND s.category=:cat AND s.category_variant=:v
    GROUP BY s.id
    ORDER BY pts DESC, (gf-ga) DESC, gf DESC
  ');
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  respond_json(['ok'=>true, 'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($type === 'board') {
  $st = $pdo->prepare('
    SELECT ge.group_code, ge.pos, ge.manual_place,
           s.id, s.name,
           c.name AS club, c.city AS city, c.logo_path AS logo_path
    FROM group_entries ge
    INNER JOIN squads s ON s.id = ge.squad_id
    LEFT JOIN clubs c ON c.id = s.club_id
    WHERE ge.stage_id=:sid AND ge.category=:cat AND ge.variant=:v
    ORDER BY ge.group_code, ge.pos
  ');
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $groups = [];
  foreach ($rows as $r) {
    $g = (string)$r['group_code'];
    if (!isset($groups[$g])) $groups[$g] = [];
    $groups[$g][] = [
      'id'          => (int)$r['id'],
      'pos'         => (int)$r['pos'],
      'manual_place'=> ($r['manual_place']===null ? null : (int)$r['manual_place']),
      'name'        => $r['name'],
      'club'        => $r['club'],
      'city'        => $r['city'] ?? '',
      'logo_url'    => lc_logo_public_url((string)($r['logo_path'] ?? '')),
    ];
  }

  $hasPens = results_has_penalty_columns($pdo);
  $selectPens = $hasPens ? ', r.home_pen_goals, r.away_pen_goals' : '';

  $sql = '
    SELECT m.id, m.group_code, m.round_no,
           m.home_squad_id, m.away_squad_id,
           r.home_goals, r.away_goals' . $selectPens . '
    FROM matches m
    LEFT JOIN results r ON r.match_id = m.id
    WHERE m.stage_id=:sid AND m.category=:cat AND m.variant=:v AND m.phase=\'group\'
    ORDER BY m.group_code, m.round_no, m.id
  ';
  $st = $pdo->prepare($sql);
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  $groupMatches = $st->fetchAll(PDO::FETCH_ASSOC);

  $sql = '
    SELECT m.id, m.group_code, m.round_no, m.phase, m.code,
           m.home_label, m.away_label, m.home_ref, m.away_ref,
           m.home_squad_id, m.away_squad_id,
           sh.name AS home_name, sa.name AS away_name,
           r.home_goals, r.away_goals' . $selectPens . '
    FROM matches m
    LEFT JOIN squads sh ON sh.id = m.home_squad_id
    LEFT JOIN squads sa ON sa.id = m.away_squad_id
    LEFT JOIN results r ON r.match_id = m.id
    WHERE m.stage_id=:sid AND m.category=:cat AND m.variant=:v AND m.phase=\'playoff\'
    ORDER BY m.round_no, m.id
  ';
  $st = $pdo->prepare($sql);
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  $playoffMatches = $st->fetchAll(PDO::FETCH_ASSOC);

  respond_json([
    'ok'=>true,
    'stage_id'=>$stageId,
    'category'=>$category,
    'variant'=>$variant,
    'groups'=>$groups,
    'matches'=>$groupMatches,
    'playoff_matches'=>$playoffMatches,
  ]);
}

respond_json(['ok'=>false,'error'=>'unknown type'], 400);
