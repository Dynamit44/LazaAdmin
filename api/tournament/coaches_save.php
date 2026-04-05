<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

//function read_input(): array {
//    $raw = file_get_contents('php://input');
//    $json = $raw ? json_decode($raw, true) : null;
//    if (is_array($json)) return $json;
//    return $_POST ?: [];
//}

function read_input(): array {
    $raw = file_get_contents('php://input');
    $json = $raw ? json_decode($raw, true) : null;
    if (is_array($json)) return $json;

    if (!empty($_POST)) return $_POST;
    if (!empty($_GET))  return $_GET;

    return [];
}


$in = read_input();

$id   = isset($in['id']) ? (int)$in['id'] : 0;
$name = trim((string)($in['name'] ?? ''));

if ($name === '') {
    respond_json(['ok' => false, 'error' => 'name обязателен']);
}

if (mb_strlen($name) > 255) {
    respond_json(['ok' => false, 'error' => 'Слишком длинное имя (макс 255)']);
}

$pdo = db();

// Создание: переиспользуем по имени (твой вариант "барс", "косм1" и т.п.)
if ($id <= 0) {
    $st = $pdo->prepare("SELECT id FROM coaches WHERE name = ? LIMIT 1");
    $st->execute([$name]);
    $existingId = (int)($st->fetchColumn() ?: 0);

    if ($existingId > 0) {
        respond_json(['ok' => true, 'id' => $existingId, 'reused' => true]);
    }

    $st = $pdo->prepare("INSERT INTO coaches (name) VALUES (?)");
    $st->execute([$name]);

    respond_json(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'created' => true]);
}

// Обновление
$st = $pdo->prepare("SELECT id FROM coaches WHERE id = ? LIMIT 1");
$st->execute([$id]);
if (!$st->fetchColumn()) {
    respond_json(['ok' => false, 'error' => 'Тренер не найден']);
}

$st = $pdo->prepare("UPDATE coaches SET name = ? WHERE id = ?");
$st->execute([$name, $id]);

respond_json(['ok' => true, 'id' => $id, 'updated' => true]);
