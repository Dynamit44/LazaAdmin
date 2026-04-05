<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$u = require_role('operator','organizer');

$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();
$matchId = (int)($b['match_id'] ?? 0);
$hg = (int)($b['home_goals'] ?? 0);
$ag = (int)($b['away_goals'] ?? 0);

if ($matchId <= 0) respond_json(['ok'=>false,'error'=>'match_id required'], 400);
if ($hg < 0 || $ag < 0) respond_json(['ok'=>false,'error'=>'goals must be >= 0'], 400);

$stMatch = $pdo->prepare('SELECT stage_id FROM matches WHERE id = :id LIMIT 1');
$stMatch->execute([':id' => $matchId]);
$rsStageId = (int)($stMatch->fetchColumn() ?: 0);
if ($rsStageId <= 0) {
  respond_json(['ok'=>false,'error'=>'match not found'], 404);
}
lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $rsStageId));

$stmt = $pdo->prepare("
  INSERT INTO results(match_id, home_goals, away_goals, updated_by, updated_at)
  VALUES(:mid,:hg,:ag,:uid,NOW())
  ON DUPLICATE KEY UPDATE
    home_goals=VALUES(home_goals),
    away_goals=VALUES(away_goals),
    updated_by=VALUES(updated_by),
    updated_at=NOW()
");
$stmt->execute([':mid'=>$matchId, ':hg'=>$hg, ':ag'=>$ag, ':uid'=>(int)$u['id']]);

respond_json(['ok'=>true]);
