<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require __DIR__ . '/_logos.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$body = read_json_body();
if (!is_array($body)) respond_json(['ok'=>false,'error'=>'Bad JSON'], 400);

$squadId = (int)($body['squad_id'] ?? 0);
if ($squadId <= 0) respond_json(['ok'=>false,'error'=>'squad_id required'], 400);

$pdo = db();
ensure_clubs_logo_path($pdo);

$st = $pdo->prepare(
  "SELECT s.id, s.club_id, stg.tournament_id, c.logo_path
   FROM squads s
   JOIN clubs c ON c.id = s.club_id
   JOIN stages stg ON stg.id = s.stage_id
   WHERE s.id = :id LIMIT 1"
);
$st->execute([':id'=>$squadId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) respond_json(['ok'=>false,'error'=>'squad not found'], 404);

lc_require_tournament_not_archived($pdo, (int)($row['tournament_id'] ?? 0));

$clubId = (int)($row['club_id'] ?? 0);
$oldPath = (string)($row['logo_path'] ?? '');

$up = $pdo->prepare("UPDATE clubs SET logo_path=NULL WHERE id=:id LIMIT 1");
$up->execute([':id'=>$clubId]);
if ($oldPath !== '') lc_logo_safe_unlink($oldPath);

respond_json([
  'ok' => true,
  'squad_id' => $squadId,
  'club_id' => $clubId,
  'logo_path' => null,
  'logo_url' => '',
]);
