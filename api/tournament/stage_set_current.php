<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();
$id = (int)($b['id'] ?? 0);
if ($id <= 0) respond_json(['ok'=>false,'error'=>'id required'], 400);

$pdo = db();

$st = $pdo->prepare("SELECT id, tournament_id FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id'=>$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  respond_json(['ok'=>false,'error'=>'stage not found'], 404);
}

$tid = (int)$row['tournament_id'];
lc_require_tournament_not_archived($pdo, $tid);

$pdo->beginTransaction();
try {
  $st = $pdo->prepare("UPDATE stages SET is_current=0 WHERE tournament_id=:tid");
  $st->execute([':tid'=>$tid]);

  $st = $pdo->prepare("UPDATE stages SET is_current=1 WHERE id=:id");
  $st->execute([':id'=>$id]);

  $pdo->commit();
  respond_json(['ok'=>true,'id'=>$id,'tournament_id'=>$tid]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>'Set current failed','details'=>$e->getMessage()], 500);
}
