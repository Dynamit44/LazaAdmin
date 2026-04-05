<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('operator','organizer');

@set_time_limit(90);

require_once __DIR__ . '/_publish_lib.php';

try {
  $body = read_json_body();
  $res = pub_handle('table', $body);

  if (!empty($res['ok'])) {
    respond_json(['ok'=>true] + $res, 200);
  }
  respond_json(['ok'=>false] + $res, 500);

} catch (Throwable $e) {
  if (function_exists('log_line')) log_line('publish_table fatal: ' . $e->getMessage());
  respond_json(['ok'=>false,'error'=>'Server error: '.$e->getMessage()], 500);
}
