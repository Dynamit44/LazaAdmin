<?php
declare(strict_types=1);

function current_user(): ?array {
  return $_SESSION['user'] ?? null; // ВАЖНО: тот же ключ, что пишет login.php
}

function require_role(string ...$roles): void {
  $u = current_user();
  if (!$u) respond_json(['ok'=>false,'error'=>'Unauthorized'], 401);

  if ($roles) {
    $role = (string)($u['role'] ?? '');
    if (!in_array($role, $roles, true)) {
      respond_json(['ok'=>false,'error'=>'Forbidden'], 403);
    }
  }
}
