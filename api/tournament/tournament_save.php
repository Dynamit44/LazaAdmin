<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();

$id   = (int)($b['id'] ?? 0);
$name = trim((string)($b['name'] ?? ''));

if ($name === '') respond_json(['ok'=>false,'error'=>'name required'], 400);

$pdo = db();

if ($id > 0) {
  $st = $pdo->prepare("SELECT id FROM tournaments WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$id]);
  if (!$st->fetchColumn()) respond_json(['ok'=>false,'error'=>'tournament not found'], 404);

  lc_require_tournament_not_archived($pdo, $id);

  $st = $pdo->prepare("UPDATE tournaments SET name=:name WHERE id=:id");
  $st->execute([':name'=>$name, ':id'=>$id]);

  respond_json(['ok'=>true,'id'=>$id]);
}

// created_at обязателен (если в БД нет DEFAULT)
$st = $pdo->prepare("INSERT INTO tournaments(name, is_current, created_at) VALUES(:name, 0, NOW())");
$st->execute([':name'=>$name]);

respond_json(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
