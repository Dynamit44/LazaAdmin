<?php
declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');

function out_json(array $payload, int $http = 200): void {
  if (!headers_sent()) {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  }
  while (ob_get_level() > 0) { ob_end_clean(); }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function req_file(string $path): void {
  if (!is_file($path)) {
    throw new RuntimeException('Missing required file: ' . basename($path));
  }
  require_once $path;
}

function get_pdo(): PDO {
  if (function_exists('db')) {
    $pdo = db();
    if ($pdo instanceof PDO) return $pdo;
  }
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  if (isset($GLOBALS['DB']) && $GLOBALS['DB'] instanceof PDO) return $GLOBALS['DB'];
  throw new RuntimeException('DB handle not found');
}

function q_all(PDO $pdo, string $sql, array $args = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  return is_array($rows) ? $rows : [];
}

function q_one(PDO $pdo, string $sql, array $args = []): ?array {
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return ($row !== false) ? $row : null;
}

function in_json(): array {
  $raw = (string)file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function normalize_squad_ids($value): array {
  if (is_string($value)) {
    $parts = preg_split('~[\s,;]+~u', $value, -1, PREG_SPLIT_NO_EMPTY);
  } elseif (is_array($value)) {
    $parts = $value;
  } else {
    $parts = [];
  }

  $ids = [];
  foreach ($parts as $v) {
    $id = (int)$v;
    if ($id > 0) $ids[$id] = true;
  }
  return array_map('intval', array_keys($ids));
}

try {
  req_file(__DIR__ . '/_bootstrap.php');
  if (is_file(__DIR__ . '/_auth.php')) require_once __DIR__ . '/_auth.php';
  if (function_exists('require_role')) require_role('organizer');

  $pdo = get_pdo();
  $in = in_json();

  $id = (int)($in['id'] ?? 0);
  if ($id <= 0) out_json(['ok' => false, 'error' => 'id is required'], 200);

  $row = q_one($pdo, "SELECT id, stage_id FROM stage_shared_load_rules WHERE id = ? LIMIT 1", [$id]);
  if (!$row) out_json(['ok' => false, 'error' => 'Правило не найдено'], 200);

  lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, (int)($row['stage_id'] ?? 0)));

  $st = $pdo->prepare("DELETE FROM stage_shared_load_rules WHERE id = ?");
  $st->execute([$id]);

  out_json(['ok' => true, 'id' => $id], 200);
} catch (Throwable $e) {
  out_json(['ok' => false, 'error' => $e->getMessage()], 200);
}
