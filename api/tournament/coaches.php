<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

$pdo = db();

$st = $pdo->query("SELECT id, name FROM coaches ORDER BY name");
$items = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

respond_json(['ok'=>true, 'coaches'=>$items]);
