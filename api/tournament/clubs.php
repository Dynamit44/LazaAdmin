<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_role('organizer');

$pdo = db();
$st = $pdo->query("SELECT id, name, city FROM clubs ORDER BY city, name");
respond_json(['ok'=>true,'clubs'=>$st->fetchAll()]);
