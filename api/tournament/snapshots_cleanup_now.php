<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_respond.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_snapshots_lib.php';

require_role('organizer');

$keepDays = snapshots_keep_days_from_db(db(), 14);
$meta = snapshots_cleanup($keepDays);

respond_json([
  'ok' => true,
  'data' => $meta,
]);
