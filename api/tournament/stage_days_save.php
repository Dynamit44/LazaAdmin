<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('organizer');



// POST JSON: {stage_id, items:[{day_date, day_start, day_end, fields, is_active, use_default}]}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$pdo = db();
ensure_stage_days_table($pdo);

$b = read_json_body();
$stageId = (int)($b['stage_id'] ?? 0);
$items = $b['items'] ?? [];

if ($stageId <= 0 || !is_array($items)) {
  respond_json(['ok'=>false,'error'=>'stage_id и items обязательны'], 400);
}

$st = $pdo->prepare('SELECT * FROM stages WHERE id=:id LIMIT 1');
$st->execute([':id' => $stageId]);
$stage = $st->fetch();
if (!$stage) respond_json(['ok'=>false,'error'=>'Stage not found'], 404);

lc_require_tournament_not_archived($pdo, (int)($stage['tournament_id'] ?? 0));

$defStart = (string)($stage['day_start'] ?? '09:00:00');
$defEnd   = (string)($stage['day_end'] ?? '18:00:00');
$defFields= (int)($stage['fields'] ?? 1);

// helpers
function norm_time(string $t, string $fallback): string {
  $t = trim($t);
  if ($t === '') return $fallback;
  // accept HH:MM or HH:MM:SS
  if (preg_match('~^\d{2}:\d{2}$~', $t)) return $t . ':00';
  if (preg_match('~^\d{2}:\d{2}:\d{2}$~', $t)) return $t;
  return $fallback;
}

$pdo->beginTransaction();
try {
  $del = $pdo->prepare('DELETE FROM stage_days WHERE stage_id=:sid AND day_date=:d');

  // SQLite has no ON DUPLICATE KEY, so use delete+insert.
  //$insSqlite = $pdo->prepare('INSERT INTO stage_days(stage_id, day_date, day_start, day_end, fields, is_active, updated_at)
                              //VALUES(:sid,:d,:s,:e,:f,:a,datetime(\'now\'))');
  $insMysql  = $pdo->prepare('INSERT INTO stage_days(stage_id, day_date, day_start, day_end, fields, is_active)
                              VALUES(:sid,:d,:s,:e,:f,:a)
                              ON DUPLICATE KEY UPDATE day_start=VALUES(day_start), day_end=VALUES(day_end), fields=VALUES(fields), is_active=VALUES(is_active)');

  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $ins = ($driver === 'sqlite') ? $insSqlite : $insMysql;

  foreach ($items as $it) {
    if (!is_array($it)) continue;
    $day = trim((string)($it['day_date'] ?? ''));
    if ($day === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $day)) continue;

    $useDefault = (int)($it['use_default'] ?? 0);
    $isActive   = (int)($it['is_active'] ?? 1) ? 1 : 0;
    $start      = norm_time((string)($it['day_start'] ?? ''), $defStart);
    $end        = norm_time((string)($it['day_end'] ?? ''),   $defEnd);
    $fields     = (int)($it['fields'] ?? $defFields);
    if ($fields <= 0) $fields = $defFields;

    // Если строку сбрасывают — удаляем override.
    if ($useDefault === 1) {
      $del->execute([':sid'=>$stageId, ':d'=>$day]);
      continue;
    }

    // Если значения совпадают с дефолтами и активен — смысла хранить override нет.
    if ($isActive === 1 && $start === norm_time($defStart, $defStart) && $end === norm_time($defEnd, $defEnd) && $fields === $defFields) {
      $del->execute([':sid'=>$stageId, ':d'=>$day]);
      continue;
    }

    // upsert
    if ($driver === 'sqlite') {
      $del->execute([':sid'=>$stageId, ':d'=>$day]);
    }
    $ins->execute([
      ':sid'=>$stageId,
      ':d'=>$day,
      ':s'=>$start,
      ':e'=>$end,
      ':f'=>$fields,
      ':a'=>$isActive,
    ]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();

  respond_json(['ok'=>false,'error'=>'Server error: ' . $e->getMessage()], 500);
}

respond_json(['ok'=>true]);
