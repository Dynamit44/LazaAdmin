<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();
$login = trim((string)($b['login'] ?? ''));
$pass  = (string)($b['password'] ?? '');

if ($login === '' || $pass === '') {
  respond_json(['ok'=>false,'error'=>'login/password required'], 400);
}

$pdo = db();

// users: id, login, pass_hash, role, is_active
$st = $pdo->prepare("SELECT id, login, pass_hash, role, is_active FROM users WHERE login = :login LIMIT 1");
$st->execute([':login'=>$login]);
$u = $st->fetch(PDO::FETCH_ASSOC);

if ($u && (int)($u['is_active'] ?? 1) === 1) {
  $hash = (string)($u['pass_hash'] ?? '');
  if ($hash !== '' && password_verify($pass, $hash)) {
    session_regenerate_id(true);
    $_SESSION['user'] = [
      'id'    => (int)($u['id'] ?? 0),
      'login' => (string)($u['login'] ?? ''),
      'role'  => (string)($u['role'] ?? ''),
    ];
    respond_json(['ok'=>true,'user'=>$_SESSION['user']]);
  }
}

respond_json(['ok'=>false,'error'=>'Invalid credentials'], 401);
