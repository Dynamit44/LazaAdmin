<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_snapshots_lib.php';

$keepDays = snapshots_keep_days_from_db(db(), 14);
$meta = snapshots_cleanup($keepDays);

echo "Cleanup OK. keep_days={$keepDays}, removed={$meta['removed']}, ts={$meta['ts']}\n";
