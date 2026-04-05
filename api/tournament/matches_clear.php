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
lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $stageId));
$pdo->beginTransaction();
try {
  // schedule
  $st = $pdo->prepare('
    DELETE s
    FROM schedule s
    INNER JOIN matches m ON m.id = s.match_id
    WHERE m.stage_id=:sid AND m.category=:cat AND m.variant=:v
  ');
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  $deletedSchedule = $st->rowCount();

  // results
  $st = $pdo->prepare('
    DELETE r
    FROM results r
    INNER JOIN matches m ON m.id = r.match_id
    WHERE m.stage_id=:sid AND m.category=:cat AND m.variant=:v
  ');
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  $deletedResults = $st->rowCount();

  // matches
  $st = $pdo->prepare('DELETE FROM matches WHERE stage_id=:sid AND category=:cat AND variant=:v');
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  $deletedMatches = $st->rowCount();

  $pdo->commit();
  respond_json([
    'ok'=>true,
    'deleted' => [
      'schedule' => $deletedSchedule,
      'results'  => $deletedResults,
      'matches'  => $deletedMatches,
    ],
  ]);
} catch (Throwable $e) {
  $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>'db error', 'detail'=>$e->getMessage()], 500);
}
