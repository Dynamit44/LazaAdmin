<?php
declare(strict_types=1);

// Read-only view for operator/organizer: saved schedule_items -> human-friendly list
// GET /api/tournament/schedule_view.php?stage_id=123&category=2018&variant=1&day_date=2026-03-28

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('operator','organizer');

$pdo = db();
ensure_schedule_items_table($pdo);
ensure_stage_days_table($pdo);
ensure_stages_transition_minutes($pdo);

function _sv_norm_time(string $t): string {
  $t = trim($t);
  if ($t === '') return '';
  if (preg_match('~^\d{2}:\d{2}$~', $t)) return $t . ':00';
  if (preg_match('~^\d{2}:\d{2}:\d{2}$~', $t)) return $t;
  return '';
}

function _sv_add_minutes(string $hhmmss, int $minutes): string {
  $hhmmss = _sv_norm_time($hhmmss);
  if ($hhmmss === '') return '';
  [$h,$m,$s] = array_map('intval', explode(':', $hhmmss));
  $sec = $h * 3600 + $m * 60 + $s + $minutes * 60;
  $sec %= (24 * 3600);
  if ($sec < 0) $sec += (24 * 3600);
  $hh = intdiv($sec, 3600);
  $mm = intdiv($sec % 3600, 60);
  return sprintf('%02d:%02d', $hh, $mm);
}

function _sv_pick_current_stage_id(PDO $pdo): int {
  $t = (int)($pdo->query("SELECT id FROM tournaments WHERE is_current=1 ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
  if ($t <= 0) return 0;
  $st = $pdo->prepare("SELECT id FROM stages WHERE tournament_id=:tid AND is_current=1 ORDER BY id DESC LIMIT 1");
  $st->execute([':tid' => $t]);
  return (int)($st->fetchColumn() ?: 0);
}

function _sv_matches_has_column(PDO $pdo, string $col): bool {
  static $cols = null;
  if ($cols !== null) return isset($cols[$col]);

  $cols = [];
  try {
    foreach ($pdo->query("SHOW COLUMNS FROM matches") as $r) {
      $cols[(string)$r['Field']] = true;
    }
  } catch (Throwable $e) {
    // if SHOW COLUMNS not supported, assume column exists to avoid breaking existing MySQL setup
    $cols[$col] = true;
  }
  return isset($cols[$col]);
}


function _sv_current_role(): string {
  $u = current_user();
  return is_array($u) ? trim((string)($u['role'] ?? '')) : '';
}

function _sv_human_playoff_ref(string $label, string $ref): string {
  $label = trim($label);
  if ($label !== '') return $label;

  $ref = trim($ref);
  if ($ref === '') return '';

  if (preg_match('~^GE:([A-Z]):(\d+)$~', $ref, $m)) {
    return $m[1] . $m[2];
  }

  if (preg_match('~^([WL]):(.+)$~', $ref, $m)) {
    $kind = ($m[1] === 'W') ? 'Поб.' : 'Проигр.';
    $src  = strtoupper(trim($m[2]));
    $tag = $src;
    if (preg_match('~(QF\d+|SF\d+|F|3|T\d+)$~', $src, $mm)) {
      $tag = $mm[1];
    }
    if (preg_match('~^QF(\d+)$~', $tag, $mm)) $tag = 'ЧФ' . $mm[1];
    elseif (preg_match('~^SF(\d+)$~', $tag, $mm)) $tag = 'ПФ' . $mm[1];
    elseif ($tag === 'F') $tag = 'Финал';
    elseif ($tag === '3') $tag = 'М3';
    elseif (preg_match('~^T(\d+)$~', $tag, $mm)) $tag = 'Т' . $mm[1];
    return $kind . ' ' . $tag;
  }

  return $ref;
}

$stageId = (int)($_GET['stage_id'] ?? 0);
if ($stageId <= 0) $stageId = _sv_pick_current_stage_id($pdo);
if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required (or no current stage)'], 400);

$category = trim((string)($_GET['category'] ?? ''));
$variant  = (int)($_GET['variant'] ?? 0);
$dayDate  = trim((string)($_GET['day_date'] ?? ''));
$phase    = trim((string)($_GET['phase'] ?? '')); // group|playoff|all

$st = $pdo->prepare("SELECT id, tournament_id, name, start_date, end_date, day_start, day_end, fields, match_minutes, break_minutes, transition_minutes, timezone
                     FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id' => $stageId]);
$stage = $st->fetch(PDO::FETCH_ASSOC);
if (!$stage) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

$matchMinutes = (int)($stage['match_minutes'] ?? 20);
$breakMinutes = (int)($stage['break_minutes'] ?? 5);
$trMinutes    = (int)($stage['transition_minutes'] ?? 15);
if ($matchMinutes <= 0) $matchMinutes = 20;
if ($breakMinutes < 0)  $breakMinutes = 0;
if ($trMinutes < 0)     $trMinutes = 0;

$slotMinutes = $matchMinutes * 2 + $breakMinutes + $trMinutes;
if ($slotMinutes <= 0) $slotMinutes = 45;

// day_start overrides
$dayStartByDate = [];
$q = $pdo->prepare("SELECT day_date, day_start FROM stage_days WHERE stage_id=:sid");
$q->execute([':sid' => $stageId]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $d = (string)($r['day_date'] ?? '');
  $t = _sv_norm_time((string)($r['day_start'] ?? ''));
  if ($d !== '' && $t !== '') $dayStartByDate[$d] = $t;
}

$defaultDayStart = _sv_norm_time((string)($stage['day_start'] ?? ''));
if ($defaultDayStart === '') $defaultDayStart = '09:00:00';


// Map group_entries positions -> squads (to resolve playoff GE:* refs into real team names for operator view)
$geMap = []; // key: "{$cat}|{$var}|{$group}|{$pos}" => ['name'=>..,'city'=>..,'label'=>..]
try {
  $qge = $pdo->prepare("
    SELECT ge.category, ge.variant, ge.group_code, ge.pos,
           s.name AS squad_name,
           c.city AS club_city
    FROM group_entries ge
    INNER JOIN squads s ON s.id = ge.squad_id
    LEFT JOIN clubs c ON c.id = s.club_id
    WHERE ge.stage_id = :sid
  ");
  $qge->execute([':sid' => $stageId]);
  foreach ($qge->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cat = trim((string)($r['category'] ?? ''));
    $var = (int)($r['variant'] ?? 0);
    $g   = trim((string)($r['group_code'] ?? ''));
    $pos = (int)($r['pos'] ?? 0);
    if ($cat === '' || $var <= 0 || $g === '' || $pos <= 0) continue;
    $k = $cat.'|'.$var.'|'.$g.'|'.$pos;
    $geMap[$k] = [
      'name' => trim((string)($r['squad_name'] ?? '')),
      'city' => trim((string)($r['club_city'] ?? '')),
      'label'=> $g.$pos,
    ];
  }
} catch (Throwable $e) {
  // ignore if table not present in some env
}


$where = "si.stage_id = :sid";
$params = [':sid' => $stageId];

if ($category !== '') {
  $where .= " AND m.category = :cat";
  $params[':cat'] = $category;
}

if ($dayDate !== '') {
  // basic YYYY-MM-DD validation to avoid trash
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $dayDate)) {
    respond_json(['ok'=>false,'error'=>'bad day_date'], 400);
  }
  $where .= " AND si.day_date = :dd";
  $params[':dd'] = $dayDate;
}

$hasVariant = _sv_matches_has_column($pdo, 'variant');
if ($variant > 0 && $hasVariant) {
  $where .= " AND m.variant = :v";
  $params[':v'] = $variant;
}

if ($phase !== '' && $phase !== 'all') {
  if ($phase !== 'group' && $phase !== 'playoff') {
    respond_json(['ok'=>false,'error'=>'bad phase'], 400);
  }
  $where .= " AND m.phase = :ph";
  $params[':ph'] = $phase;
}


$role = _sv_current_role();

$viewMode = trim((string)($_GET['view_mode'] ?? ''));
$isOperatorView = ($role === 'operator' || $viewMode === 'operator');

// For operator view: hide real playoff participants until ALL group matches
// of the same category+variant are finished.
$groupDoneMap = []; // key: cat|var => bool
try {
  if ($hasVariant) {
    $sqlDone = "
      SELECT m.category AS category, m.variant AS variant,
             COUNT(*) AS total_cnt,
             SUM(CASE WHEN r.match_id IS NOT NULL THEN 1 ELSE 0 END) AS played_cnt
      FROM matches m
      LEFT JOIN results r ON r.match_id = m.id
      WHERE m.stage_id = :sid AND m.phase = 'group'
      GROUP BY m.category, m.variant
    ";
  } else {
    $sqlDone = "
      SELECT m.category AS category, 0 AS variant,
             COUNT(*) AS total_cnt,
             SUM(CASE WHEN r.match_id IS NOT NULL THEN 1 ELSE 0 END) AS played_cnt
      FROM matches m
      LEFT JOIN results r ON r.match_id = m.id
      WHERE m.stage_id = :sid AND m.phase = 'group'
      GROUP BY m.category
    ";
  }
  $qd = $pdo->prepare($sqlDone);
  $qd->execute([':sid' => $stageId]);
  foreach ($qd->fetchAll(PDO::FETCH_ASSOC) as $rr) {
    $cc = trim((string)($rr['category'] ?? ''));
    $vv = (int)($rr['variant'] ?? 0);
    if ($cc === '') continue;
    $totalCnt = (int)($rr['total_cnt'] ?? 0);
    $playedCnt = (int)($rr['played_cnt'] ?? 0);
    $groupDoneMap[$cc.'|'.$vv] = ($totalCnt <= 0) ? true : ($playedCnt >= $totalCnt);
  }
} catch (Throwable $e) {
  // fail-open: if we cannot check completion, do not hide participants globally
  $groupDoneMap = [];
}

$selVariant = $hasVariant ? ", m.variant AS variant" : ", 0 AS variant";

$sql = "
  SELECT
    si.day_date,
    si.slot_index,
    si.resource_code,
    si.match_id,
    m.phase AS phase,
    m.code  AS code,
    m.round_no AS round_no,
    m.category AS category
    {$selVariant},
    sh.name AS home_name,
    ch.city AS home_city,
    sa.name AS away_name,
    ca.city AS away_city,
    m.home_label AS home_label,
    m.away_label AS away_label,
    m.home_ref AS home_ref,
    m.away_ref AS away_ref
  FROM schedule_items si
  INNER JOIN matches m ON m.id = si.match_id
  LEFT JOIN squads sh ON sh.id = m.home_squad_id
  LEFT JOIN squads sa ON sa.id = m.away_squad_id
  LEFT JOIN clubs ch ON ch.id = sh.club_id
  LEFT JOIN clubs ca ON ca.id = sa.club_id
  WHERE {$where}
  ORDER BY si.day_date ASC, si.slot_index ASC, si.resource_code ASC
";

$q = $pdo->prepare($sql);
$q->execute($params);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

$days = [];
foreach ($rows as $r) {
  $date  = (string)($r['day_date'] ?? '');
  $slot  = (int)($r['slot_index'] ?? 0);
  $field = (string)($r['resource_code'] ?? '');
  $mid   = (int)($r['match_id'] ?? 0);
  if ($date === '' || $slot <= 0 || $field === '' || $mid <= 0) continue;

  $ds = $dayStartByDate[$date] ?? $defaultDayStart;
  $time = _sv_add_minutes($ds, ($slot - 1) * $slotMinutes);

  $home = trim((string)($r['home_name'] ?? ''));
  $away = trim((string)($r['away_name'] ?? ''));
  $hc   = trim((string)($r['home_city'] ?? ''));
  $ac   = trim((string)($r['away_city'] ?? ''));

  $homeLabel = trim((string)($r['home_label'] ?? ''));
  $awayLabel = trim((string)($r['away_label'] ?? ''));
  $homeRef   = trim((string)($r['home_ref'] ?? ''));
  $awayRef   = trim((string)($r['away_ref'] ?? ''));

  $rowCat = trim((string)($r['category'] ?? ''));
  $rowVar = (int)($r['variant'] ?? 0);
  $ph     = trim((string)($r['phase'] ?? ''));
  $code   = trim((string)($r['code'] ?? ''));

  $doneKey = $rowCat . '|' . $rowVar;
  $groupsFinished = array_key_exists($doneKey, $groupDoneMap) ? (bool)$groupDoneMap[$doneKey] : true;
  //$hidePlayoffTeams = ($role === 'operator' && $ph === 'playoff' && !$groupsFinished);
  $hidePlayoffTeams = ($isOperatorView && $ph === 'playoff' && !$groupsFinished);

  if ($hidePlayoffTeams) {
    $home = _sv_human_playoff_ref($homeLabel, $homeRef);
    $away = _sv_human_playoff_ref($awayLabel, $awayRef);
    $hc = '';
    $ac = '';
  } else {
    // If squads not set (typical for playoff placeholders), try resolve GE:* -> real squad name via group_entries
    if ($home === '' && $homeRef !== '') {
      if (preg_match('~^GE:([A-Z]):(\d+)$~', $homeRef, $mm)) {
        $g = $mm[1]; $pos = (int)$mm[2];
        $k = $rowCat.'|'.$rowVar.'|'.$g.'|'.$pos;
        if (isset($geMap[$k]) && ($geMap[$k]['name'] ?? '') !== '') {
          $home = (string)$geMap[$k]['name'];
          $hc = (string)($geMap[$k]['city'] ?? '');
          if ($homeLabel !== '') $home .= ' ['.$homeLabel.']';
        }
      }
    }
    if ($away === '' && $awayRef !== '') {
      if (preg_match('~^GE:([A-Z]):(\d+)$~', $awayRef, $mm)) {
        $g = $mm[1]; $pos = (int)$mm[2];
        $k = $rowCat.'|'.$rowVar.'|'.$g.'|'.$pos;
        if (isset($geMap[$k]) && ($geMap[$k]['name'] ?? '') !== '') {
          $away = (string)$geMap[$k]['name'];
          $ac = (string)($geMap[$k]['city'] ?? '');
          if ($awayLabel !== '') $away .= ' ['.$awayLabel.']';
        }
      }
    }

    // Fallback: use label / ref
    if ($home === '') {
      $home = _sv_human_playoff_ref($homeLabel, $homeRef);
    }
    if ($away === '') {
      $away = _sv_human_playoff_ref($awayLabel, $awayRef);
    }

    if ($home !== '' && $hc !== '') $home .= ' (' . $hc . ')';
    if ($away !== '' && $ac !== '') $away .= ' (' . $ac . ')';
  }

  if (!isset($days[$date])) $days[$date] = ['day_date' => $date, 'items' => []];

  $days[$date]['items'][] = [
    'time' => $time,
    'category' => $rowCat,
    'variant' => $rowVar,
    'field' => $field,
    'home' => $home,
    'away' => $away,
    'phase' => $ph,
    'code' => $code,
    'round_no' => (int)($r['round_no'] ?? 0),
    'match_id' => $mid,
    'slot_index' => $slot,
    'resource_code' => $field,
  ];
}

respond_json([
  'ok' => true,
  'stage' => $stage,
  'slot_minutes' => $slotMinutes,
  'filters' => [
    'category' => $category,
    'variant' => ($variant > 0 && $hasVariant) ? $variant : 0,
    'day_date' => $dayDate,
    'phase' => ($phase === '' ? 'all' : $phase),
  ],
  'days' => array_values($days),
]);
