<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$b = read_json_body();
$id = (int)($b['id'] ?? 0);
if ($id <= 0) {
  respond_json(['ok' => false, 'error' => 'id required'], 400);
}

$pdo = db();
$pdo->beginTransaction();

try {
  $st = $pdo->prepare('SELECT id, is_archived FROM tournaments WHERE id = :id LIMIT 1 FOR UPDATE');
  $st->execute([':id' => $id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $pdo->rollBack();
    respond_json(['ok' => false, 'error' => 'tournament not found'], 404);
  }

  if ((int)($row['is_archived'] ?? 0) === 0) {
    $pdo->commit();
    respond_json(['ok' => true, 'id' => $id, 'already_active' => true]);
  }

  $st = $pdo->prepare(
    'UPDATE tournaments SET is_archived = 0, archived_at = NULL WHERE id = :id'
  );
  $st->execute([':id' => $id]);

  $pdo->commit();
  respond_json(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  respond_json(['ok' => false, 'error' => 'Unarchive failed', 'details' => $e->getMessage()], 500);
}
