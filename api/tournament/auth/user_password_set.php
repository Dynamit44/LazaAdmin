<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();
$userId = (int)($b['user_id'] ?? 0);
$newPass = (string)($b['new_password'] ?? '');

if ($userId <= 0 || $newPass === '') {
  respond_json(['ok'=>false,'error'=>'user_id/new_password required'], 400);
}
if (mb_strlen($newPass) < 6) {
  respond_json(['ok'=>false,'error'=>'Password too short (min 6)'], 400);
}

$pdo = db();

// organizer тоже можно менять, но деактивацию — нет (это в другом endpoint)
$hash = password_hash($newPass, PASSWORD_DEFAULT);

$st = $pdo->prepare("UPDATE users SET pass_hash=:h WHERE id=:id LIMIT 1");
$st->execute([':h'=>$hash, ':id'=>$userId]);

respond_json(['ok'=>true]);
