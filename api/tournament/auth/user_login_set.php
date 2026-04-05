<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();
$userId = (int)($b['user_id'] ?? 0);
$newLogin = trim((string)($b['new_login'] ?? ''));

if ($userId <= 0 || $newLogin === '') {
  respond_json(['ok'=>false,'error'=>'user_id/new_login required'], 400);
}

// простая валидация логина (лат/цифра/._-), 3..32
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,31}$/', $newLogin)) {
  respond_json(['ok'=>false,'error'=>'Bad login format'], 400);
}

$pdo = db();

// уникальность
$st = $pdo->prepare("SELECT id FROM users WHERE login=:l AND id<>:id LIMIT 1");
$st->execute([':l'=>$newLogin, ':id'=>$userId]);
if ($st->fetchColumn()) {
  respond_json(['ok'=>false,'error'=>'Login already exists'], 409);
}

$st = $pdo->prepare("UPDATE users SET login=:l WHERE id=:id LIMIT 1");
$st->execute([':l'=>$newLogin, ':id'=>$userId]);

respond_json(['ok'=>true]);
