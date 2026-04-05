<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

$pdo = db();

$st = $pdo->query(
  'SELECT id, name, is_current, is_archived, archived_at, created_at FROM tournaments ORDER BY is_archived ASC, is_current DESC, id DESC'
);
$items = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

respond_json(['ok'=>true, 'tournaments'=>$items]);
