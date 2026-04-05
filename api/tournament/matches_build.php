<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'POST only'], 405);
}

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '', true);
if (!is_array($in)) {
  respond_json(['ok'=>false,'error'=>'bad json'], 400);
}

$stageId  = (int)($in['stage_id'] ?? 0);
$category = trim((string)($in['category'] ?? ''));
$variant  = (int)($in['variant'] ?? 1);

if ($stageId <= 0 || $category === '' || $variant <= 0) {
  respond_json(['ok'=>false,'error'=>'stage_id/category/variant required'], 400);
}

$pdo = db();

// stage -> tournament_id
$st = $pdo->prepare('SELECT id, tournament_id FROM stages WHERE id=:id');
$st->execute([':id'=>$stageId]);
$stage = $st->fetch(PDO::FETCH_ASSOC);
if (!$stage) {
  respond_json(['ok'=>false,'error'=>'stage not found'], 404);
}
$tournamentId = (int)$stage['tournament_id'];
lc_require_tournament_not_archived($pdo, $tournamentId);

// already has matches?
$st = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE stage_id=:sid AND category=:cat AND variant=:v');
$st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
$exists = (int)$st->fetchColumn();
if ($exists > 0) {
  respond_json(['ok'=>false,'error'=>'matches already exist (clear first)'], 409);
}

// load group entries
$st = $pdo->prepare('
  SELECT group_code, pos, squad_id
  FROM group_entries
  WHERE stage_id=:sid AND category=:cat AND variant=:v
  ORDER BY group_code, pos
');
$st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
  respond_json(['ok'=>false,'error'=>'no groups in DB (group_entries empty)'], 409);
}

$groups = [];
foreach ($rows as $r) {
  $g = (string)$r['group_code'];
  $sid = (int)$r['squad_id'];
  if (!isset($groups[$g])) $groups[$g] = [];
  $groups[$g][] = $sid;
}

// validate sizes
$bad = [];
foreach ($groups as $g => $teamIds) {
  if (count($teamIds) < 3) $bad[] = $g;
}
if ($bad) {
  respond_json(['ok'=>false,'error'=>'group size < 3', 'groups'=>$bad], 409);
}

function build_round_robin(array $teamIds): array {
  // circle method with BYE=0
  $teams = array_values($teamIds);
  if (count($teams) % 2 === 1) $teams[] = 0;
  $n = count($teams);
  $rounds = $n - 1;
  $half = (int)($n / 2);

  $out = [];
  for ($round = 1; $round <= $rounds; $round++) {
    for ($i = 0; $i < $half; $i++) {
      $a = $teams[$i];
      $b = $teams[$n - 1 - $i];
      if ($a === 0 || $b === 0) continue;

      // немного “перемешиваем” home/away для равномерности
      if ((($round + $i) % 2) === 0) {
        $home = $a; $away = $b;
      } else {
        $home = $b; $away = $a;
      }

      $out[] = ['round_no'=>$round, 'home'=>$home, 'away'=>$away];
    }

    // rotate (first fixed)
    $fixed = $teams[0];
    $rest = array_slice($teams, 1);
    $last = array_pop($rest);
    array_unshift($rest, $last);
    $teams = array_merge([$fixed], $rest);
  }

  return $out;
}

$pdo->beginTransaction();
try {
  $ins = $pdo->prepare('
    INSERT INTO matches (tournament_id, stage_id, category, variant, group_code, round_no, home_squad_id, away_squad_id)
    VALUES (:tid, :sid, :cat, :v, :g, :r, :h, :a)
  ');

  $totalMatches = 0;
  $perGroup = [];

  foreach ($groups as $g => $teamIds) {
    $pairs = build_round_robin($teamIds);
    $perGroup[$g] = 0;

    foreach ($pairs as $m) {
      $ins->execute([
        ':tid' => $tournamentId,
        ':sid' => $stageId,
        ':cat' => $category,
        ':v'   => $variant,
        ':g'   => $g,
        ':r'   => (int)$m['round_no'],
        ':h'   => (int)$m['home'],
        ':a'   => (int)$m['away'],
      ]);
      $totalMatches++;
      $perGroup[$g]++;
    }
  }

  $pdo->commit();
  respond_json([
    'ok' => true,
    'stage_id' => $stageId,
    'category' => $category,
    'variant' => $variant,
    'groups' => count($groups),
    'matches' => $totalMatches,
    'per_group' => $perGroup,
  ]);
} catch (Throwable $e) {
  $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>'db error', 'detail'=>$e->getMessage()], 500);
}
