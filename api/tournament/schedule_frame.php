<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('organizer');

// GET /api/tournament/schedule_frame.php?stage_id=11
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  respond_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$pdo = db();
ensure_stage_days_table($pdo);
ensure_stages_transition_minutes($pdo);

function table_exists(PDO $pdo, string $name): bool {
  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  try {
    if ($driver === 'sqlite') {
      $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n LIMIT 1");
      $st->execute([':n' => $name]);
      return (bool)$st->fetchColumn();
    }
    $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:n LIMIT 1");
    $st->execute([':n' => $name]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function build_days(PDO $pdo, array $stage): array {
  $startDate = (string)($stage['start_date'] ?? '');
  $endDate   = (string)($stage['end_date'] ?? '');
  if ($startDate === '' || $endDate === '') return [];

  $defaults = [
    'day_start' => (string)($stage['day_start'] ?? '09:00:00'),
    'day_end'   => (string)($stage['day_end'] ?? '18:00:00'),
    'fields'    => (int)($stage['fields'] ?? 1),
  ];

  // overrides
  $q = $pdo->prepare('SELECT * FROM stage_days WHERE stage_id=:sid');
  $q->execute([':sid' => (int)$stage['id']]);
  $ov = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $d = (string)($r['day_date'] ?? '');
    if ($d !== '') $ov[$d] = $r;
  }

  try {
    $d1 = new DateTimeImmutable($startDate);
    $d2 = new DateTimeImmutable($endDate);
  } catch (Throwable $e) {
    return [];
  }
  if ($d2 < $d1) return [];

  $items = [];
  $cur = $d1;
  while ($cur <= $d2) {
    $key = $cur->format('Y-m-d');
    $r = $ov[$key] ?? null;

    $items[] = [
      'day_date'  => $key,
      'day_start' => $r ? (string)($r['day_start'] ?? '') : $defaults['day_start'],
      'day_end'   => $r ? (string)($r['day_end'] ?? '')   : $defaults['day_end'],
      'fields'    => $r ? (int)($r['fields'] ?? 0)         : $defaults['fields'],
      'is_active' => $r ? (int)($r['is_active'] ?? 1)      : 1,
      'is_custom' => $r ? 1 : 0,
    ];
    $cur = $cur->modify('+1 day');
  }

  return ['defaults' => $defaults, 'items' => $items];
}

$stageId = (int)($_GET['stage_id'] ?? 0);

// если stage_id не передали — берём текущий
if ($stageId <= 0) {
  $t = $pdo->query("SELECT id FROM tournaments WHERE is_current=1 ORDER BY id DESC LIMIT 1")->fetchColumn();
  if ($t) {
    $st = $pdo->prepare("SELECT id FROM stages WHERE tournament_id=:tid AND is_current=1 ORDER BY id DESC LIMIT 1");
    $st->execute([':tid' => (int)$t]);
    $stageId = (int)($st->fetchColumn() ?: 0);
  }
}

if ($stageId <= 0) {
  respond_json(['ok'=>false,'error'=>'stage_id обязателен (или не задан текущий этап)'], 400);
}

$st = $pdo->prepare("SELECT * FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id' => $stageId]);
$stage = $st->fetch(PDO::FETCH_ASSOC);
if (!$stage) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

// категории этапа (времена)
$cats = [];
if (table_exists($pdo, 'stage_categories')) {
  $q = $pdo->prepare("SELECT category, match_minutes, break_minutes, min_rest_slots FROM stage_categories WHERE stage_id=:sid ORDER BY category DESC");
  $q->execute([':sid' => $stageId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  $tr = (int)($stage['transition_minutes'] ?? 15);
  foreach ($rows as $r) {
    $half = (int)($r['match_minutes'] ?? 0);
    $brk  = (int)($r['break_minutes'] ?? 0);
    $rest = (int)($r['min_rest_slots'] ?? 0);

    if ($half <= 0) $half = (int)($stage['match_minutes'] ?? 20);
    if ($brk  <= 0) $brk  = (int)($stage['break_minutes'] ?? 5);
    if ($rest <  0) $rest = 0;
    if ($tr   <  0) $tr   = 0;

    $cats[] = [
      'category' => (string)($r['category'] ?? ''),
      'half_minutes' => $half,
      'break_minutes' => $brk,
      'transition_minutes' => $tr,
      'slot_minutes' => ($half * 2 + $brk + $tr),
      'min_rest_slots' => ($rest > 0 ? $rest : (int)($stage['min_rest_slots'] ?? 0)),
    ];
  }
}

// ресурсы (field_code -> units_mask)
$resources = [];
if (table_exists($pdo, 'stage_fields')) {
  $q = $pdo->prepare("SELECT field_code, units_mask FROM stage_fields WHERE stage_id=:sid ORDER BY units_mask ASC");
  $q->execute([':sid'=>$stageId]);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $resources[] = [
      'code' => (string)($r['field_code'] ?? ''),
      'mask' => (int)($r['units_mask'] ?? 0),
    ];
  }
}

// fallback (если ещё не сохраняли правила полей)
if (!$resources) {
  $fields = (int)($stage['fields'] ?? 4);
  $n = max(1, min(4, $fields));
  $full = (1 << $n) - 1;
  $resources = [
    ['code'=>'1','mask'=>1],
    ['code'=>'2','mask'=>2],
    ['code'=>'3','mask'=>4],
    ['code'=>'4','mask'=>8],
    ['code'=>'12','mask'=>3],
    ['code'=>'34','mask'=>12],
    ['code'=>'full','mask'=>($n === 4 ? 15 : $full)],
  ];
  // фильтруем то, что не влезает в n полей
  $resources = array_values(array_filter($resources, function($r) use ($full){
    $m = (int)$r['mask'];
    return ($m & ~$full) === 0;
  }));
}

// конфликты по маскам
$conflicts = [];
for ($i=0; $i<count($resources); $i++) {
  $a = $resources[$i];
  $conflicts[$a['code']] = [];
}
for ($i=0; $i<count($resources); $i++) {
  for ($j=$i+1; $j<count($resources); $j++) {
    $a = $resources[$i];
    $b = $resources[$j];
    if (((int)$a['mask'] & (int)$b['mask']) !== 0) {
      $conflicts[$a['code']][] = $b['code'];
      $conflicts[$b['code']][] = $a['code'];
    }
  }
}

// правила полей по category+variant
$rules = [];
if (table_exists($pdo, 'stage_category_fields')) {
  $q = $pdo->prepare("SELECT category, variant, field_code FROM stage_category_fields WHERE stage_id=:sid ORDER BY category DESC, variant ASC, field_code ASC");
  $q->execute([':sid'=>$stageId]);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cat = (string)($r['category'] ?? '');
    $var = (int)($r['variant'] ?? 1);
    $key = $cat.'#'.$var;
    if (!isset($rules[$key])) $rules[$key] = [];
    $rules[$key][] = (string)($r['field_code'] ?? '');
  }
}

$days = build_days($pdo, $stage);

respond_json([
  'ok' => true,
  'stage' => $stage,
  'days' => $days,
  'categories' => $cats,
  'resources' => $resources,
  'conflicts' => $conflicts,
  'rules' => $rules,
]);
