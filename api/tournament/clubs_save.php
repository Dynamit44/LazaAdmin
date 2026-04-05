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
$city = trim((string)($in['city'] ?? ''));

if ($name === '' || $city === '') {
    respond_json(['ok' => false, 'error' => 'name и city обязательны']);
}

if (mb_strlen($name) > 255 || mb_strlen($city) > 255) {
    respond_json(['ok' => false, 'error' => 'Слишком длинные значения (макс 255)']);
}

$pdo = db();

// Если создаём — попробуем переиспользовать существующий клуб (name+city)
if ($id <= 0) {
    $st = $pdo->prepare("SELECT id FROM clubs WHERE name = ? AND city = ? LIMIT 1");
    $st->execute([$name, $city]);
    $existingId = (int)($st->fetchColumn() ?: 0);

    if ($existingId > 0) {
        respond_json(['ok' => true, 'id' => $existingId, 'reused' => true]);
    }

    $st = $pdo->prepare("INSERT INTO clubs (name, city) VALUES (?, ?)");
    $st->execute([$name, $city]);

    respond_json(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'created' => true]);
}

// Обновление
$st = $pdo->prepare("SELECT id FROM clubs WHERE id = ? LIMIT 1");
$st->execute([$id]);
if (!$st->fetchColumn()) {
    respond_json(['ok' => false, 'error' => 'Клуб не найден']);
}

$st = $pdo->prepare("UPDATE clubs SET name = ?, city = ? WHERE id = ?");
$st->execute([$name, $city, $id]);

respond_json(['ok' => true, 'id' => $id, 'updated' => true]);
