<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/_respond.php';
require_once __DIR__ . '/_tournament_archive_guard.php';
require __DIR__ . '/_db.php';
require_once __DIR__ . '/_tournament_archive_schema.php';
require __DIR__ . '/_auth.php';
require __DIR__ . '/_publish_lib.php';

// Сессия (для логина админки)
$cfgPath = dirname(__DIR__, 3) . '/_private/tournament/config.php';
$cfg = require $cfgPath;
if (!is_array($cfg)) { throw new RuntimeException('Bad config'); }
$GLOBALS['CFG'] = $cfg;

ensure_tournaments_archive_columns(db());

session_name($cfg['session_name'] ?? 'TOURNAMENT_SESS');
session_start();
