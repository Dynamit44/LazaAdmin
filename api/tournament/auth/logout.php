<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
  session_destroy();
}
respond_json(['ok'=>true]);
