<?php
declare(strict_types=1);

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $cfg = $GLOBALS['CFG'] ?? null;
  if (!is_array($cfg) || empty($cfg['db']['dsn'])) {
    throw new RuntimeException('DB config missing (cfg[db][dsn])');
  }

  $dsn  = (string)$cfg['db']['dsn'];
  $user = (string)($cfg['db']['user'] ?? '');
  $pass = (string)($cfg['db']['pass'] ?? '');

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  return $pdo;
}
