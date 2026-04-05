<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_respond.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_snapshots_lib.php';

require_role('organizer'); // ВАЖНО: вариадик строками, не массив

$keepDays = snapshots_keep_days_from_db(db(), 14);
$st = snapshots_get_status($keepDays);

respond_json([
  'ok' => true,
  'data' => $st,
]);
