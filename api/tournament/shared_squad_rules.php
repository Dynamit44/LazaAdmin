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

  $stageId = isset($_GET['stage_id']) ? (int)$_GET['stage_id'] : 0;
  if ($stageId <= 0) out_json(['ok' => false, 'error' => 'stage_id is required'], 200);

  $sql = "
    SELECT
      r.id,
      r.stage_id,
      r.max_matches_per_day,
      r.min_rest_slots,
      r.note,
      GROUP_CONCAT(rs.squad_id ORDER BY rs.squad_id SEPARATOR ',') AS squad_ids_csv,
      GROUP_CONCAT(
        CONCAT(
          rs.squad_id,
          ':',
          COALESCE(s.name, CONCAT('#', rs.squad_id))
        )
        ORDER BY rs.squad_id SEPARATOR ' | '
      ) AS squad_names_text
    FROM stage_shared_load_rules r
    LEFT JOIN stage_shared_load_rule_squads rs ON rs.rule_id = r.id
    LEFT JOIN squads s ON s.id = rs.squad_id
    WHERE r.stage_id = ?
    GROUP BY r.id
    ORDER BY r.id DESC
  ";

  $rows = q_all($pdo, $sql, [$stageId]);
  $items = [];

  foreach ($rows as $r) {
    $ids = normalize_squad_ids((string)($r['squad_ids_csv'] ?? ''));
    $items[] = [
      'id' => (int)$r['id'],
      'stage_id' => (int)$r['stage_id'],
      'max_matches_per_day' => (int)$r['max_matches_per_day'],
      'min_rest_slots' => (int)$r['min_rest_slots'],
      'note' => (string)($r['note'] ?? ''),
      'squad_ids' => $ids,
      'squad_ids_text' => implode(', ', $ids),
      'squad_names_text' => (string)($r['squad_names_text'] ?? ''),
    ];
  }

  out_json(['ok' => true, 'items' => $items], 200);
} catch (Throwable $e) {
  out_json(['ok' => false, 'error' => $e->getMessage()], 200);
}
