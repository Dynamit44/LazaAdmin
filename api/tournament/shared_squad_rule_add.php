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

  $stageId = (int)($in['stage_id'] ?? 0);
  $squadIds = normalize_squad_ids($in['squad_ids'] ?? []);
  $maxMatchesPerDay = (int)($in['max_matches_per_day'] ?? 2);
  $minRestSlots = (int)($in['min_rest_slots'] ?? 0);
  $note = trim((string)($in['note'] ?? ''));

  if ($stageId <= 0) out_json(['ok' => false, 'error' => 'stage_id is required'], 200);
  if (count($squadIds) < 2) out_json(['ok' => false, 'error' => 'Нужно выбрать минимум 2 команды'], 200);

  if ($maxMatchesPerDay < 1) $maxMatchesPerDay = 1;
  if ($maxMatchesPerDay > 9) $maxMatchesPerDay = 9;
  if ($minRestSlots < 0) $minRestSlots = 0;
  if ($minRestSlots > 20) $minRestSlots = 20;

  lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $stageId));

  // Проверяем, что все squad_id реально принадлежат этапу
  $place = implode(',', array_fill(0, count($squadIds), '?'));
  $args = array_merge([$stageId], $squadIds);
  $rows = q_all(
    $pdo,
    "SELECT id FROM squads WHERE stage_id = ? AND id IN ($place)",
    $args
  );

  $found = [];
  foreach ($rows as $r) $found[(int)$r['id']] = true;

  $missing = [];
  foreach ($squadIds as $sid) {
    if (empty($found[$sid])) $missing[] = $sid;
  }
  if ($missing) {
    out_json([
      'ok' => false,
      'error' => 'Некоторые команды не принадлежат выбранному этапу',
      'missing_ids' => $missing
    ], 200);
  }

  // Защита от точного дубля набора команд внутри этапа
  $ruleRows = q_all($pdo, "
    SELECT r.id, GROUP_CONCAT(rs.squad_id ORDER BY rs.squad_id SEPARATOR ',') AS squad_ids_csv
    FROM stage_shared_load_rules r
    JOIN stage_shared_load_rule_squads rs ON rs.rule_id = r.id
    WHERE r.stage_id = ?
    GROUP BY r.id
  ", [$stageId]);

  $incomingKey = implode(',', $squadIds);
  foreach ($ruleRows as $rr) {
    if ((string)($rr['squad_ids_csv'] ?? '') === $incomingKey) {
      out_json([
        'ok' => false,
        'error' => 'Такое правило уже существует',
        'existing_id' => (int)$rr['id']
      ], 200);
    }
  }

  $pdo->beginTransaction();

  $st = $pdo->prepare("
    INSERT INTO stage_shared_load_rules (stage_id, max_matches_per_day, min_rest_slots, note)
    VALUES (?, ?, ?, ?)
  ");
  $st->execute([$stageId, $maxMatchesPerDay, $minRestSlots, $note]);
  $ruleId = (int)$pdo->lastInsertId();

  $stLink = $pdo->prepare("
    INSERT INTO stage_shared_load_rule_squads (rule_id, squad_id)
    VALUES (?, ?)
  ");

  foreach ($squadIds as $sid) {
    $stLink->execute([$ruleId, $sid]);
  }

  $pdo->commit();

  out_json([
    'ok' => true,
    'id' => $ruleId,
    'stage_id' => $stageId,
    'squad_ids' => $squadIds,
    'max_matches_per_day' => $maxMatchesPerDay,
    'min_rest_slots' => $minRestSlots,
    'note' => $note
  ], 200);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  out_json(['ok' => false, 'error' => $e->getMessage()], 200);
}
