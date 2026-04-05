<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

function read_input(): array {
    $raw = file_get_contents('php://input');
    $json = $raw ? json_decode($raw, true) : null;
    if (is_array($json)) return $json;
    return $_POST ?: [];
}

$in = read_input();
$id = isset($in['id']) ? (int)$in['id'] : 0;

if ($id <= 0) {
    respond_json(['ok' => false, 'error' => 'id обязателен']);
}

$pdo = db();

$st = $pdo->prepare("SELECT id FROM coaches WHERE id = ? LIMIT 1");
$st->execute([$id]);
if (!$st->fetchColumn()) {
    respond_json(['ok' => false, 'error' => 'Тренер не найден']);
}

$st = $pdo->prepare("DELETE FROM coaches WHERE id = ?");
$st->execute([$id]);

respond_json(['ok' => true, 'deleted' => true, 'id' => $id]);
