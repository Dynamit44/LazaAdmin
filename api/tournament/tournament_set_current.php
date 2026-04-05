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

$st = $pdo->prepare("SELECT id FROM tournaments WHERE id=:id LIMIT 1");
$st->execute([':id'=>$id]);
if (!$st->fetchColumn()) {
  respond_json(['ok'=>false,'error'=>'tournament not found'], 404);
}

lc_require_tournament_not_archived($pdo, $id);

$pdo->beginTransaction();

try {
  $pdo->exec("UPDATE tournaments SET is_current=0");

  $st = $pdo->prepare("UPDATE tournaments SET is_current=1 WHERE id=:id");
  $st->execute([':id'=>$id]);

  $pdo->commit();
  respond_json(['ok'=>true,'id'=>$id]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>'Set current failed','details'=>$e->getMessage()], 500);
}
