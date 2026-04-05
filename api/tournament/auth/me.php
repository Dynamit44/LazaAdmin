<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

$u = current_user();
if (!$u) respond_json(['ok'=>false,'error'=>'Unauthorized'], 401);

respond_json(['ok'=>true,'user'=>$u]);
