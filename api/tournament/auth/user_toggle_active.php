<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();
$userId = (int)($b['user_id'] ?? 0);
$isActive = (int)($b['is_active'] ?? 0);

if ($userId <= 0) {
  respond_json(['ok'=>false,'error'=>'user_id required'], 400);
}
$isActive = $isActive ? 1 : 0;

$pdo = db();

// Нельзя деактивировать organizer
$st = $pdo->prepare("SELECT role FROM users WHERE id=:id LIMIT 1");
$st->execute([':id'=>$userId]);
$role = (string)($st->fetchColumn() ?: '');
if ($role === 'organizer') {
  respond_json(['ok'=>false,'error'=>'Organizer cannot be deactivated'], 403);
}

$st = $pdo->prepare("UPDATE users SET is_active=:a WHERE id=:id LIMIT 1");
$st->execute([':a'=>$isActive, ':id'=>$userId]);

respond_json(['ok'=>true]);
