<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

require_role('organizer');

$pdo = db();
$st = $pdo->query("SELECT id, login, role, is_active FROM users ORDER BY role DESC, login ASC");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

respond_json(['ok'=>true,'users'=>$rows]);
