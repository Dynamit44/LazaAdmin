<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require __DIR__ . '/_logos.php';

require_role('operator','organizer');

try {

$pdo = db();

// гарантируем наличие таблиц дней/расписания (нужно для выборки матчей по дню)
ensure_stage_days_table($pdo);
ensure_schedule_items_table($pdo);
ensure_clubs_logo_path($pdo);

// ---- helpers ----
function body_json(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

function int0($v): int {
  return (int)preg_replace('~[^0-9]~', '', (string)$v);
}

function str0($v): string {
  return trim((string)$v);
}

function safe_slug(string $s, string $fallback='g'): string {
  $s = trim($s);
  if ($s === '') return $fallback;
  // латиница/цифры/._- ; остальное выкидываем
  $s = preg_replace('~[^a-zA-Z0-9._-]+~', '_', $s);
  $s = trim($s, '_');
  if ($s === '') return $fallback;
  return substr($s, 0, 32);
}

function find_font_path(): ?string {
  // 1) из config.php можно подсунуть свою
  $cfg = $GLOBALS['CFG'] ?? [];
  if (is_array($cfg) && !empty($cfg['img_font_path']) && is_string($cfg['img_font_path']) && is_file($cfg['img_font_path'])) {
    return $cfg['img_font_path'];
  }
  // 2) самые частые пути (Linux)
  $candidates = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
    '/usr/local/share/fonts/DejaVuSans.ttf',
  ];
  foreach ($candidates as $p) {
    if (is_file($p)) return $p;
  }
  return null;
}

function design_asset_abs(string $publicPath): ?string {
  $publicPath = '/' . ltrim(trim($publicPath), '/');
  if ($publicPath === '/' || strpos($publicPath, '/uploads/') !== 0) return null;
  $base = dirname(__DIR__, 2);
  $abs = $base . $publicPath;
  return is_file($abs) ? $abs : null;
}

function fit_inside(Imagick $im, int $maxW, int $maxH): void {
  $w = max(1, (int)$im->getImageWidth());
  $h = max(1, (int)$im->getImageHeight());
  $scale = min($maxW / $w, $maxH / $h);
  if ($scale >= 1.0) return;
  $nw = max(1, (int)round($w * $scale));
  $nh = max(1, (int)round($h * $scale));
  $im->resizeImage($nw, $nh, Imagick::FILTER_LANCZOS, 1);
}

function cover_resize(Imagick $im, int $targetW, int $targetH): void {
  $w = max(1, (int)$im->getImageWidth());
  $h = max(1, (int)$im->getImageHeight());
  $scale = max($targetW / $w, $targetH / $h);
  $nw = max(1, (int)ceil($w * $scale));
  $nh = max(1, (int)ceil($h * $scale));
  $im->resizeImage($nw, $nh, Imagick::FILTER_LANCZOS, 1);
  $x = max(0, (int)floor(($nw - $targetW) / 2));
  $y = max(0, (int)floor(($nh - $targetH) / 2));
  $im->cropImage($targetW, $targetH, $x, $y);
  $im->setImagePage(0, 0, 0, 0);
}

function draw_centered_fit_text(Imagick $img, string $text, int $centerX, int $baselineY, int $maxWidth, int $startSize, string $color, ?string $font=null, int $minSize=16): void {
  $size = $startSize;
  $d = new ImagickDraw();
  $d->setTextAntialias(true);
  $d->setFillColor($color);
  if ($font) $d->setFont($font);
  while ($size >= $minSize) {
    $d->setFontSize($size);
    $probe = $img->queryFontMetrics($d, $text);
    if ((int)($probe['textWidth'] ?? 0) <= $maxWidth) break;
    $size--;
  }
  $d->setFontSize(max($size, $minSize));
  $probe = $img->queryFontMetrics($d, $text);
  $x = (int)round($centerX - (($probe['textWidth'] ?? 0) / 2));
  $img->annotateImage($d, $x, $baselineY, 0, $text);
}

function draw_right_text(Imagick $img, string $text, int $rightX, int $baselineY, int $size, string $color, ?string $font=null): void {
  $d = new ImagickDraw();
  $d->setTextAntialias(true);
  $d->setFillColor($color);
  $d->setFontSize($size);
  if ($font) $d->setFont($font);
  $probe = $img->queryFontMetrics($d, $text);
  $x = (int)round($rightX - (($probe['textWidth'] ?? 0)));
  $img->annotateImage($d, $x, $baselineY, 0, $text);
}

function lc_fit_text(Imagick $img, ImagickDraw $draw, string $text, int $maxWidth): string {
  $text = trim($text);
  if ($text === '' || $maxWidth <= 0) return $text;
  $probe = $img->queryFontMetrics($draw, $text);
  if ((int)($probe['textWidth'] ?? 0) <= $maxWidth) return $text;

  $base = $text;
  while (mb_strlen($base, 'UTF-8') > 1) {
    $base = mb_substr($base, 0, mb_strlen($base, 'UTF-8') - 1, 'UTF-8');
    $cand = rtrim($base) . '…';
    $m = $img->queryFontMetrics($draw, $cand);
    if ((int)($m['textWidth'] ?? 0) <= $maxWidth) return $cand;
  }
  return '…';
}

function lc_draw_team_logo(Imagick $img, string $logoPath, int $x, int $y, int $size): void {
  if ($size <= 0) return;
  $abs = lc_logo_abs_path($logoPath);
  if ($abs === '' || !is_file($abs)) return;
  try {
    $logo = new Imagick($abs);
    $logo->setImageFormat('png');
    $logo->thumbnailImage($size, $size, true, true);
    $lw = (int)$logo->getImageWidth();
    $lh = (int)$logo->getImageHeight();
    $dx = $x + max(0, intdiv($size - $lw, 2));
    $dy = $y + max(0, intdiv($size - $lh, 2));
    $img->compositeImage($logo, Imagick::COMPOSITE_OVER, $dx, $dy);
    $logo->clear();
    $logo->destroy();
  } catch (Throwable $e) {
    // ignore bad logo silently
  }
}

function format_city(string $city): string {
  $s0 = trim($city);
  if ($s0 === '') return '';

  // Если mbstring нет — просто возвращаем как есть (лучше капс, чем 500).
  if (!function_exists('mb_strtolower') || !function_exists('mb_substr') || !function_exists('mb_strtoupper')) {
    return $s0;
  }

  $s = mb_strtolower(preg_replace('~\s+~u', ' ', $s0), 'UTF-8');
  $small = ['и'=>1,'в'=>1,'во'=>1,'на'=>1,'к'=>1,'ко'=>1,'о'=>1,'об'=>1,'от'=>1,'до'=>1,'из'=>1,'за'=>1,'по'=>1,'под'=>1,'над'=>1,'при'=>1,'у'=>1,'с'=>1,'со'=>1,'без'=>1,'для'=>1,'про'=>1];

  $words = explode(' ', $s);
  $out = [];
  foreach ($words as $wi => $w) {
    $parts = array_values(array_filter(explode('-', $w), fn($x)=>$x!==''));
    $pout = [];
    foreach ($parts as $pi => $p) {
      if ($p === '') { $pout[] = $p; continue; }
      if (($wi !== 0 || $pi !== 0) && isset($small[$p])) { $pout[] = $p; continue; }
      $first = mb_substr($p, 0, 1, 'UTF-8');
      $rest  = mb_substr($p, 1, null, 'UTF-8');
      $pout[] = mb_strtoupper($first, 'UTF-8') . $rest;
    }
    $out[] = implode('-', $pout);
  }
  return implode(' ', $out);
}

function is_played(array $m): bool {
  if (!array_key_exists('home_goals', $m) || !array_key_exists('away_goals', $m)) return false;
  return !($m['home_goals'] === null || $m['away_goals'] === null || $m['home_goals'] === '' || $m['away_goals'] === '');
}

function pair_key(int $a, int $b): string {
  return ($a < $b) ? ($a . ':' . $b) : ($b . ':' . $a);
}

function _pp_norm_time(string $t): string {
  $t = trim($t);
  if ($t === '') return '';
  if (preg_match('~^\d{2}:\d{2}$~', $t)) return $t . ':00';
  if (preg_match('~^\d{2}:\d{2}:\d{2}$~', $t)) return $t;
  return '';
}

function _pp_add_minutes(string $hhmmss, int $minutes): string {
  $hhmmss = _pp_norm_time($hhmmss);
  if ($hhmmss === '') return '';
  [$h,$m,$s] = array_map('intval', explode(':', $hhmmss));
  $sec = ($h*3600 + $m*60 + $s) + ($minutes*60);
  $sec %= (24*3600);
  if ($sec < 0) $sec += (24*3600);
  $hh = intdiv($sec, 3600);
  $mm = intdiv($sec % 3600, 60);
  return sprintf('%02d:%02d', $hh, $mm);
}

/**
 * day_no (1..N) -> day_date
 * Источник истины:
 * 1) schedule_items.day_date (но только в диапазоне дат этапа)
 * 2) stage_days (active -> any), тоже в диапазоне
 */
function pick_day_date(PDO $pdo, int $stageId, int $dayNo, string $stageFrom='', string $stageTo=''): string {
  if ($dayNo <= 0) $dayNo = 1;
  $idx = $dayNo - 1;

  $hasRange = ($stageFrom !== '' && $stageTo !== '');

  // 1) schedule_items: реальные даты из расписания
  try {
    $sql = "SELECT DISTINCT day_date
            FROM schedule_items
            WHERE stage_id=:sid";
    $params = [':sid' => $stageId];

    if ($hasRange) {
      $sql .= " AND day_date BETWEEN :df AND :dt";
      $params[':df'] = $stageFrom;
      $params[':dt'] = $stageTo;
    }

    $sql .= " ORDER BY day_date ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $days = $st->fetchAll(PDO::FETCH_COLUMN);

    if ($days && isset($days[$idx])) return (string)$days[$idx];
    if ($days) return (string)$days[count($days)-1];
  } catch (Throwable $e) {
    // идём дальше
  }

  // 2) stage_days active
  $sql = "SELECT day_date FROM stage_days WHERE stage_id=:sid AND is_active=1";
  $params = [':sid' => $stageId];
  if ($hasRange) {
    $sql .= " AND day_date BETWEEN :df AND :dt";
    $params[':df'] = $stageFrom;
    $params[':dt'] = $stageTo;
  }
  $sql .= " ORDER BY day_date ASC";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $days = $st->fetchAll(PDO::FETCH_COLUMN);
  if ($days && isset($days[$idx])) return (string)$days[$idx];
  if ($days) return (string)$days[count($days)-1];

  // 3) stage_days any (если всё inactive)
  $sql = "SELECT day_date FROM stage_days WHERE stage_id=:sid";
  $params = [':sid' => $stageId];
  if ($hasRange) {
    $sql .= " AND day_date BETWEEN :df AND :dt";
    $params[':df'] = $stageFrom;
    $params[':dt'] = $stageTo;
  }
  $sql .= " ORDER BY day_date ASC";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $days = $st->fetchAll(PDO::FETCH_COLUMN);
  if ($days && isset($days[$idx])) return (string)$days[$idx];
  return $days ? (string)$days[count($days)-1] : '';
}

/**
 * Возвращает список day_date по РЕАЛЬНОМУ расписанию (schedule_items),
 * строго для stage_id + category + variant (и по возможности phase=group).
 * Если расписания нет — fallback на stage_days.
 */
function list_day_dates_for_category(PDO $pdo, int $stageId, string $category, int $variant, string $stageFrom='', string $stageTo=''): array {
  $category = trim($category);
  if ($category === '' || $variant <= 0) return [];

  $hasRange = ($stageFrom !== '' && $stageTo !== '');

  // есть ли колонка phase
  $hasPhase = false;
  try { $pdo->query("SELECT phase FROM matches LIMIT 1"); $hasPhase = true; } catch (Throwable $e) { $hasPhase = false; }

  // 1) Реальные дни из schedule_items + matches (фильтр cat/variant)
  try {
    $sql = "
      SELECT DISTINCT si.day_date
      FROM schedule_items si
      INNER JOIN matches m ON m.id = si.match_id
      WHERE si.stage_id = :sid
        AND m.stage_id  = :sid
        AND m.category  = :cat
        AND m.variant   = :v
    ";
    $params = [':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant];


    if ($hasRange) {
      $sql .= " AND si.day_date BETWEEN :df AND :dt ";
      $params[':df'] = $stageFrom;
      $params[':dt'] = $stageTo;
    }

    $sql .= " ORDER BY si.day_date ASC ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $days = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $days = array_values(array_filter(array_map('strval', $days)));

    if ($days) return $days;
  } catch (Throwable $e) {
    // fallback ниже
  }

  // 2) Fallback: stage_days active/any
  $sql = "SELECT day_date FROM stage_days WHERE stage_id=:sid AND is_active=1";
  $params = [':sid'=>$stageId];
  if ($hasRange) {
    $sql .= " AND day_date BETWEEN :df AND :dt";
    $params[':df'] = $stageFrom;
    $params[':dt'] = $stageTo;
  }
  $sql .= " ORDER BY day_date ASC";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $days = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $days = array_values(array_filter(array_map('strval', $days)));
  if ($days) return $days;

  $sql = "SELECT day_date FROM stage_days WHERE stage_id=:sid";
  $params = [':sid'=>$stageId];
  if ($hasRange) {
    $sql .= " AND day_date BETWEEN :df AND :dt";
    $params[':df'] = $stageFrom;
    $params[':dt'] = $stageTo;
  }
  $sql .= " ORDER BY day_date ASC";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $days = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  return array_values(array_filter(array_map('strval', $days)));
}

/**
 * Возвращает матчи дня, сгруппированные по group_code.
 * Берём матч-ид из schedule_items на конкретный day_date.
 */
function load_group_day_matches(PDO $pdo, int $stageId, string $category, int $variant, string $dayDate): array {
  if ($dayDate === '') return [];

  // stage params (как в schedule_view.php)
  $st = $pdo->prepare("SELECT day_start, match_minutes, break_minutes, transition_minutes FROM stages WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$stageId]);
  $stage = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $matchMinutes = (int)($stage['match_minutes'] ?? 20);
  $breakMinutes = (int)($stage['break_minutes'] ?? 5);
  $trMinutes    = (int)($stage['transition_minutes'] ?? 15);
  if ($matchMinutes <= 0) $matchMinutes = 20;
  if ($breakMinutes < 0)  $breakMinutes = 0;
  if ($trMinutes < 0)     $trMinutes = 0;

  $slotMinutes = $matchMinutes * 2 + $breakMinutes + $trMinutes;
  if ($slotMinutes <= 0) $slotMinutes = 45;

  $defaultDayStart = _pp_norm_time((string)($stage['day_start'] ?? ''));
  if ($defaultDayStart === '') $defaultDayStart = '09:00:00';

  // day_start override from stage_days for this date
  $q = $pdo->prepare("SELECT day_start FROM stage_days WHERE stage_id=:sid AND day_date=:dd LIMIT 1");
  $q->execute([':sid'=>$stageId, ':dd'=>$dayDate]);
  $ov = _pp_norm_time((string)($q->fetchColumn() ?: ''));
  $dayStart = $ov !== '' ? $ov : $defaultDayStart;

  // matches from schedule_items
  $sql = "
    SELECT
      m.id AS match_id,
      m.group_code,
      sh.name AS home_name,
      sa.name AS away_name,
      ch.logo_path AS home_logo_path,
      ca.logo_path AS away_logo_path,
      r.home_goals AS home_goals,
      r.away_goals AS away_goals,
      r.home_pen_goals AS home_pen_goals,
      r.away_pen_goals AS away_pen_goals,
      si.slot_index,
      si.resource_code
    FROM schedule_items si
    JOIN matches m ON m.id = si.match_id
    LEFT JOIN squads sh ON sh.id = m.home_squad_id
    LEFT JOIN clubs ch ON ch.id = sh.club_id
    LEFT JOIN squads sa ON sa.id = m.away_squad_id
    LEFT JOIN clubs ca ON ca.id = sa.club_id
    LEFT JOIN results r ON r.match_id = m.id
    WHERE si.stage_id = :sid1
      AND si.day_date = :dd
      AND m.stage_id  = :sid2
      AND m.category  = :cat
      AND m.variant   = :v
      AND m.phase     = 'group'
    ORDER BY si.slot_index ASC, si.resource_code ASC, m.id ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([
    ':sid1'=>$stageId,
    ':sid2'=>$stageId,
    ':dd'=>$dayDate,
    ':cat'=>$category,
    ':v'=>$variant,
  ]);

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $byGroup = [];

  foreach ($rows as $r) {
    $g = (string)($r['group_code'] ?? '');
    if ($g === '') continue;

    $slot = (int)($r['slot_index'] ?? 0);
    $time = ($slot > 0) ? _pp_add_minutes($dayStart, ($slot - 1) * $slotMinutes) : '';

    $byGroup[$g][] = [
      'time' => $time, // ✅ вот оно, “реальное”
      'home' => (string)($r['home_name'] ?? ''),
      'away' => (string)($r['away_name'] ?? ''),
      'home_logo_path' => (string)($r['home_logo_path'] ?? ''),
      'away_logo_path' => (string)($r['away_logo_path'] ?? ''),
      'hg'   => ($r['home_goals'] === null ? null : (int)$r['home_goals']),
      'ag'   => ($r['away_goals'] === null ? null : (int)$r['away_goals']),
      'hpg'  => ($r['home_pen_goals'] === null ? null : (int)$r['home_pen_goals']),
      'apg'  => ($r['away_pen_goals'] === null ? null : (int)$r['away_pen_goals']),
      'slot' => $slot,
      'res'  => (string)($r['resource_code'] ?? ''),
    ];
  }

  return $byGroup;
}



function fmt_match_line(array $m): string {
  $home = trim((string)($m['home'] ?? ''));
  $away = trim((string)($m['away'] ?? ''));
  $hg = $m['hg'] ?? null;
  $ag = $m['ag'] ?? null;
  $hpg = $m['hpg'] ?? null;
  $apg = $m['apg'] ?? null;
  $score = playoff_score_text(is_int($hg) ? $hg : null, is_int($ag) ? $ag : null, is_int($hpg) ? $hpg : null, is_int($apg) ? $apg : null);
  //return "{$home} — {$away}  {$score}";
  $time = trim((string)($m['time'] ?? ''));
  $prefix = $time ? ($time . '  ') : '';
  return "{$prefix}{$home} — {$away}  {$score}";

}

function playoff_caption_by_code(string $code): string {
  $code = strtoupper(trim($code));
  if ($code === '') return 'Плей-офф';
  if ($code === 'F') return 'Финал';
  if ($code === '3') return 'Матч за 3 место';

  if (preg_match('~^P(\d+)-(\d+)-SF(\d+)$~', $code, $m)) {
    return '1/2 финала (' . $m[1] . '–' . $m[2] . ' места)';
  }
  if (preg_match('~^P(\d+)-(\d+)-F$~', $code, $m)) {
    $from = (int)$m[1];
    return 'Матч за ' . $from . ' место';
  }
  if (preg_match('~^P(\d+)-(\d+)-3$~', $code, $m)) {
    $from = (int)$m[1];
    return 'Матч за ' . ($from + 2) . ' место';
  }
  return $code;
}

function playoff_score_text(?int $hg, ?int $ag, ?int $hpg=null, ?int $apg=null): string {
  return (is_int($hg) && is_int($ag)) ? ($hg . ':' . $ag) : '—:—';
}

function playoff_pen_text(?int $hg, ?int $ag, ?int $hpg=null, ?int $apg=null): string {
  if (is_int($hg) && is_int($ag) && is_int($hpg) && is_int($apg) && $hg === $ag) {
    return 'пен. ' . $hpg . ':' . $apg;
  }
  return '';
}

function resolve_playoff_participant_public(PDO $pdo, int $stageId, string $category, int $variant, string $kind, string $code, int $depth=0): array {
  if ($depth > 8) {
    return ['name' => ($kind === 'W' ? 'Поб. ' : 'Проигр. ') . playoff_caption_by_code($code), 'city' => '', 'logo_path' => ''];
  }

  $q = $pdo->prepare("
    SELECT
      m.id,
      m.code,
      m.home_squad_id,
      m.away_squad_id,
      m.home_label,
      m.away_label,
      m.home_ref,
      m.away_ref,
      sh.name AS home_name,
      sa.name AS away_name,
      ch.city AS home_city,
      ca.city AS away_city,
      ch.logo_path AS home_logo_path,
      ca.logo_path AS away_logo_path,
      r.home_goals,
      r.away_goals,
      r.home_pen_goals,
      r.away_pen_goals
    FROM matches m
    LEFT JOIN squads sh ON sh.id = m.home_squad_id
    LEFT JOIN clubs ch ON ch.id = sh.club_id
    LEFT JOIN squads sa ON sa.id = m.away_squad_id
    LEFT JOIN clubs ca ON ca.id = sa.club_id
    LEFT JOIN results r ON r.match_id = m.id
    WHERE m.stage_id = :sid AND m.category = :cat AND m.variant = :v AND m.code = :code
    LIMIT 1
  ");
  $q->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant, ':code'=>$code]);
  $m = $q->fetch(PDO::FETCH_ASSOC);
  if (!$m) {
    return ['name' => ($kind === 'W' ? 'Поб. ' : 'Проигр. ') . playoff_caption_by_code($code), 'city' => '', 'logo_path' => ''];
  }

  $hg = ($m['home_goals'] === null || $m['home_goals'] === '') ? null : (int)$m['home_goals'];
  $ag = ($m['away_goals'] === null || $m['away_goals'] === '') ? null : (int)$m['away_goals'];
  $hpg = ($m['home_pen_goals'] === null || $m['home_pen_goals'] === '') ? null : (int)$m['home_pen_goals'];
  $apg = ($m['away_pen_goals'] === null || $m['away_pen_goals'] === '') ? null : (int)$m['away_pen_goals'];

  $winnerSide = null;
  $loserSide = null;
  if (is_int($hg) && is_int($ag)) {
    if ($hg > $ag) { $winnerSide = 'home'; $loserSide = 'away'; }
    elseif ($ag > $hg) { $winnerSide = 'away'; $loserSide = 'home'; }
    elseif (is_int($hpg) && is_int($apg) && $hpg !== $apg) {
      if ($hpg > $apg) { $winnerSide = 'home'; $loserSide = 'away'; }
      else { $winnerSide = 'away'; $loserSide = 'home'; }
    }
  }

  $needSide = ($kind === 'W') ? $winnerSide : $loserSide;
  if ($needSide === 'home') {
    if (trim((string)($m['home_name'] ?? '')) !== '') {
      return [
        'name' => trim((string)$m['home_name']),
        'city' => format_city((string)($m['home_city'] ?? '')),
        'logo_path' => (string)($m['home_logo_path'] ?? ''),
      ];
    }
    return resolve_seed_ref_public($pdo, $stageId, $category, $variant, (string)($m['home_label'] ?? ''), (string)($m['home_ref'] ?? ''), $depth + 1);
  }
  if ($needSide === 'away') {
    if (trim((string)($m['away_name'] ?? '')) !== '') {
      return [
        'name' => trim((string)$m['away_name']),
        'city' => format_city((string)($m['away_city'] ?? '')),
        'logo_path' => (string)($m['away_logo_path'] ?? ''),
      ];
    }
    return resolve_seed_ref_public($pdo, $stageId, $category, $variant, (string)($m['away_label'] ?? ''), (string)($m['away_ref'] ?? ''), $depth + 1);
  }

  return ['name' => ($kind === 'W' ? 'Поб. ' : 'Проигр. ') . playoff_caption_by_code($code), 'city' => '', 'logo_path' => ''];
}

function resolve_seed_ref_public(PDO $pdo, int $stageId, string $category, int $variant, string $label, string $ref, int $depth=0): array {
  $label = trim($label);
  $ref = trim($ref);
  if ($label !== '') return ['name' => $label, 'city' => '', 'logo_path' => ''];
  if ($ref === '') return ['name' => '', 'city' => '', 'logo_path' => ''];

  if (preg_match('~^GE:([A-Z]):(\d+)$~', $ref, $m)) {
    $groupCode = $m[1];
    $place = (int)$m[2];
    $q = $pdo->prepare("
      SELECT s.name AS squad_name, c.city AS club_city, c.logo_path AS logo_path
      FROM group_entries ge
      JOIN squads s ON s.id = ge.squad_id
      LEFT JOIN clubs c ON c.id = s.club_id
      WHERE ge.stage_id = :sid
        AND ge.category = :cat
        AND ge.variant = :v
        AND ge.group_code = :g
        AND COALESCE(ge.manual_place, ge.pos) = :p
      ORDER BY ge.id ASC
      LIMIT 1
    ");
    $q->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant, ':g'=>$groupCode, ':p'=>$place]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r) {
      return [
        'name' => trim((string)($r['squad_name'] ?? '')),
        'city' => format_city((string)($r['club_city'] ?? '')),
        'logo_path' => (string)($r['logo_path'] ?? ''),
      ];
    }
    return ['name' => $groupCode . $place, 'city' => '', 'logo_path' => ''];
  }

  if (preg_match('~^([WL]):(.+)$~', $ref, $m)) {
    $srcKind = strtoupper(trim((string)$m[1]));
    $srcCode = strtoupper(trim((string)$m[2]));
    return resolve_playoff_participant_public($pdo, $stageId, $category, $variant, $srcKind, $srcCode, $depth + 1);
  }

  return ['name' => $ref, 'city' => '', 'logo_path' => ''];
}

function load_playoff_day_matches(PDO $pdo, int $stageId, string $category, int $variant, string $dayDate): array {
  if ($dayDate === '') return [];

  $st = $pdo->prepare("SELECT day_start, match_minutes, break_minutes, transition_minutes FROM stages WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$stageId]);
  $stage = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $matchMinutes = (int)($stage['match_minutes'] ?? 20);
  $breakMinutes = (int)($stage['break_minutes'] ?? 5);
  $trMinutes    = (int)($stage['transition_minutes'] ?? 15);
  if ($matchMinutes <= 0) $matchMinutes = 20;
  if ($breakMinutes < 0)  $breakMinutes = 0;
  if ($trMinutes < 0)     $trMinutes = 0;
  $slotMinutes = $matchMinutes * 2 + $breakMinutes + $trMinutes;
  if ($slotMinutes <= 0) $slotMinutes = 45;

  $defaultDayStart = _pp_norm_time((string)($stage['day_start'] ?? ''));
  if ($defaultDayStart === '') $defaultDayStart = '09:00:00';
  $q = $pdo->prepare("SELECT day_start FROM stage_days WHERE stage_id=:sid AND day_date=:dd LIMIT 1");
  $q->execute([':sid'=>$stageId, ':dd'=>$dayDate]);
  $ov = _pp_norm_time((string)($q->fetchColumn() ?: ''));
  $dayStart = $ov !== '' ? $ov : $defaultDayStart;

  $sql = "
    SELECT
      m.id AS match_id,
      m.code,
      m.home_label,
      m.away_label,
      m.home_ref,
      m.away_ref,
      sh.name AS home_name,
      sa.name AS away_name,
      ch.city AS home_city,
      ca.city AS away_city,
      ch.logo_path AS home_logo_path,
      ca.logo_path AS away_logo_path,
      r.home_goals AS home_goals,
      r.away_goals AS away_goals,
      r.home_pen_goals AS home_pen_goals,
      r.away_pen_goals AS away_pen_goals,
      si.slot_index,
      si.resource_code
    FROM schedule_items si
    JOIN matches m ON m.id = si.match_id
    LEFT JOIN squads sh ON sh.id = m.home_squad_id
    LEFT JOIN clubs ch ON ch.id = sh.club_id
    LEFT JOIN squads sa ON sa.id = m.away_squad_id
    LEFT JOIN clubs ca ON ca.id = sa.club_id
    LEFT JOIN results r ON r.match_id = m.id
    WHERE si.stage_id = :sid1
      AND si.day_date = :dd
      AND m.stage_id  = :sid2
      AND m.category  = :cat
      AND m.variant   = :v
      AND m.phase     = 'playoff'
    ORDER BY si.slot_index ASC, si.resource_code ASC, m.id ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([':sid1'=>$stageId, ':sid2'=>$stageId, ':dd'=>$dayDate, ':cat'=>$category, ':v'=>$variant]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $out = [];
  foreach ($rows as $r) {
    $slot = (int)($r['slot_index'] ?? 0);
    $time = ($slot > 0) ? _pp_add_minutes($dayStart, ($slot - 1) * $slotMinutes) : '';

    $home = trim((string)($r['home_name'] ?? ''));
    $away = trim((string)($r['away_name'] ?? ''));
    $homeCity = format_city((string)($r['home_city'] ?? ''));
    $awayCity = format_city((string)($r['away_city'] ?? ''));
    $homeLogo = (string)($r['home_logo_path'] ?? '');
    $awayLogo = (string)($r['away_logo_path'] ?? '');
    $homeLabel = trim((string)($r['home_label'] ?? ''));
    $awayLabel = trim((string)($r['away_label'] ?? ''));
    $homeRef   = trim((string)($r['home_ref'] ?? ''));
    $awayRef   = trim((string)($r['away_ref'] ?? ''));

    // В публикации плей-офф нужно показывать актуальные команды по GE:* даже если
    // home_squad_id/away_squad_id ещё пустые или устарели. Иначе в карточке остаются
    // A1/A2/A3, хотя в расписании уже видны реальные пары.
    if ($homeRef !== '') {
      $seed = resolve_seed_ref_public($pdo, $stageId, $category, $variant, $homeLabel, $homeRef);
      if (($seed['name'] ?? '') !== '' && !preg_match('~^[A-Z]\d+$~u', (string)$seed['name'])) {
        $home = (string)$seed['name'];
        $homeCity = (string)($seed['city'] ?? '');
        $homeLogo = (string)($seed['logo_path'] ?? '');
        if ($homeLabel !== '') $home .= ' [' . $homeLabel . ']';
      } elseif ($home === '') {
        $home = (string)($seed['name'] ?? '');
        $homeCity = (string)($seed['city'] ?? '');
        $homeLogo = (string)($seed['logo_path'] ?? '');
      }
    }
    if ($awayRef !== '') {
      $seed = resolve_seed_ref_public($pdo, $stageId, $category, $variant, $awayLabel, $awayRef);
      if (($seed['name'] ?? '') !== '' && !preg_match('~^[A-Z]\d+$~u', (string)$seed['name'])) {
        $away = (string)$seed['name'];
        $awayCity = (string)($seed['city'] ?? '');
        $awayLogo = (string)($seed['logo_path'] ?? '');
        if ($awayLabel !== '') $away .= ' [' . $awayLabel . ']';
      } elseif ($away === '') {
        $away = (string)($seed['name'] ?? '');
        $awayCity = (string)($seed['city'] ?? '');
        $awayLogo = (string)($seed['logo_path'] ?? '');
      }
    }

    if ($home === '') {
      $seed = resolve_seed_ref_public($pdo, $stageId, $category, $variant, $homeLabel, $homeRef);
      $home = (string)($seed['name'] ?? '');
      $homeCity = (string)($seed['city'] ?? '');
      $homeLogo = (string)($seed['logo_path'] ?? '');
    }
    if ($away === '') {
      $seed = resolve_seed_ref_public($pdo, $stageId, $category, $variant, $awayLabel, $awayRef);
      $away = (string)($seed['name'] ?? '');
      $awayCity = (string)($seed['city'] ?? '');
      $awayLogo = (string)($seed['logo_path'] ?? '');
    }

    if ($home !== '' && $homeCity !== '') $home .= ' • ' . $homeCity;
    if ($away !== '' && $awayCity !== '') $away .= ' • ' . $awayCity;

    $code = trim((string)($r['code'] ?? ''));
    $out[] = [
      'time' => $time,
      'home' => $home,
      'away' => $away,
      'home_logo_path' => $homeLogo,
      'away_logo_path' => $awayLogo,
      'hg'   => ($r['home_goals'] === null ? null : (int)$r['home_goals']),
      'ag'   => ($r['away_goals'] === null ? null : (int)$r['away_goals']),
      'hpg'  => ($r['home_pen_goals'] === null ? null : (int)$r['home_pen_goals']),
      'apg'  => ($r['away_pen_goals'] === null ? null : (int)$r['away_pen_goals']),
      'slot' => $slot,
      'res'  => (string)($r['resource_code'] ?? ''),
      'code' => $code,
      'title'=> playoff_caption_by_code($code),
    ];
  }
  return $out;
}

function render_playoff_image_imagick(array $meta, array $matches, string $outPath): void {
  if (!class_exists('Imagick')) throw new RuntimeException('Imagick не доступен');

  $W = 1080;
  $H = 1350;
  $font = find_font_path();
  $seasonText = trim((string)($meta['season_text'] ?? '26 Весна'));
  if ($seasonText === '') $seasonText = '26 Весна';

  $img = new Imagick();
  $img->newImage($W, $H, new ImagickPixel('#e9f5fb'));
  $img->setImageFormat('png');

  $bgPath = design_asset_abs('/uploads/tournament/design/bg_table.png');
  if ($bgPath) {
    $bg = new Imagick($bgPath);
    $bg->setImageFormat('png');
    cover_resize($bg, $W, $H);
    $img->compositeImage($bg, Imagick::COMPOSITE_OVER, 0, 0);
    $bg->clear();
    $bg->destroy();
  }

  $mist = new ImagickDraw();
  $mist->setFillColor('rgba(255,255,255,0.20)');
  $mist->ellipse((int)($W/2), (int)($H/2), 430, 520, 0, 360);
  $img->drawImage($mist);

  $mainLogoPath = design_asset_abs('/uploads/tournament/design/logo_main.png');
  if ($mainLogoPath) {
    $logo = new Imagick($mainLogoPath);
    $logo->setImageFormat('png');
    fit_inside($logo, 170, 150);
    $img->compositeImage($logo, Imagick::COMPOSITE_OVER, 28, 22);
    $logo->clear();
    $logo->destroy();
  }

  $titlePlatePath = design_asset_abs('/uploads/tournament/design/title_plate.png');
  $titleW = 620; $titleX = 218; $titleY = 28;
  if ($titlePlatePath) {
    $plate = new Imagick($titlePlatePath);
    $plate->setImageFormat('png');
    $plate->resizeImage($titleW, 112, Imagick::FILTER_LANCZOS, 1, false);
    $img->compositeImage($plate, Imagick::COMPOSITE_OVER, $titleX, $titleY);
    $plate->clear();
    $plate->destroy();
  }
  draw_centered_fit_text($img, "LAZACUP'" . $seasonText, $titleX + (int)($titleW/2), 92, $titleW - 120, 44, '#ffffff', $font, 24);

  $stageName = trim((string)($meta['stage_name'] ?? ''));
  $dayDate = trim((string)($meta['day_date'] ?? ''));
  $rightX = $W - 54;
  if ($stageName !== '') draw_right_text($img, $stageName, $rightX, 76, 34, '#173f69', $font);
  if ($dayDate !== '') draw_right_text($img, date('d-m-Y', strtotime($dayDate)), $rightX, 112, 24, '#173f69', $font);

  $catPlatePath = design_asset_abs('/uploads/tournament/design/category_plate.png');
  $catW = 420; $catX = (int)(($W - $catW) / 2); $catY = 156;
  if ($catPlatePath) {
    $cp = new Imagick($catPlatePath);
    $cp->setImageFormat('png');
    $cp->resizeImage($catW, 64, Imagick::FILTER_LANCZOS, 1, false);
    $img->compositeImage($cp, Imagick::COMPOSITE_OVER, $catX, $catY);
    $cp->clear();
    $cp->destroy();
  }
  draw_centered_fit_text($img, trim((string)($meta['category'] ?? '')) . ' • плей-офф', (int)($W/2), $catY + 38, $catW - 40, 24, '#ffffff', $font, 16);

  $cardX = 54; $cardY = 245; $cardW = $W - 108; $cardH = $H - 360;
  $card = new ImagickDraw();
  $card->setFillColor('rgba(255,255,255,0.50)');
  $card->setStrokeColor('rgba(255,255,255,0.60)');
  $card->setStrokeWidth(2);
  $card->roundRectangle($cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 24, 24);
  $img->drawImage($card);

  $tableX = $cardX + 26;
  $tableY = $cardY + 28;
  $tableW = $cardW - 52;
  $titleDraw = new ImagickDraw();
  $titleDraw->setTextAntialias(true);
  $titleDraw->setFillColor('#173f69');
  $titleDraw->setFontSize(30);
  if ($font) $titleDraw->setFont($font);
  $img->annotateImage($titleDraw, $tableX + 6, $tableY + 34, 0, 'Матчи дня:');

  $rows = max(1, count($matches));
  $listY = $tableY + 64;
  $availableH = ($cardY + $cardH) - $listY - 24;
  //$rowH = (int)floor(min(102, max(82, $availableH / max($rows, 6))));
  $rowH = (int)floor(min(112, max(92, $availableH / max($rows, 6))));

  $dMeta = new ImagickDraw();
  $dMeta->setTextAntialias(true);
  $dMeta->setFillColor('#173f69');
  $dMeta->setFontSize(24);
  if ($font) $dMeta->setFont($font);

  $dTeam = new ImagickDraw();
  $dTeam->setTextAntialias(true);
  $dTeam->setFillColor('#102b47');
  $dTeam->setFontSize(24);
  if ($font) $dTeam->setFont($font);

  $dScore = new ImagickDraw();
  $dScore->setTextAntialias(true);
  $dScore->setFillColor('#173f69');
  $dScore->setFontSize(28);
  if ($font) $dScore->setFont($font);

  $dPen = new ImagickDraw();
  $dPen->setTextAntialias(true);
  $dPen->setFillColor('#173f69');
  $dPen->setFontSize(20);
  if ($font) $dPen->setFont($font);

  if (!$matches) {
    $img->annotateImage($dTeam, $tableX + 6, $listY + 56, 0, 'Матчей на этот день нет');
  } else {
    foreach (array_values($matches) as $i => $mm) {
      $y1 = $listY + $i * $rowH;
      $y2 = $y1 + $rowH - 10;
      $rowBg = new ImagickDraw();
      $rowBg->setFillColor(($i % 2) ? 'rgba(255,255,255,0.18)' : 'rgba(255,255,255,0.28)');
      $rowBg->setStrokeColor('rgba(23,63,105,0.14)');
      $rowBg->setStrokeWidth(1);
      $rowBg->roundRectangle($tableX, $y1, $tableX + $tableW, $y2, 14, 14);
      $img->drawImage($rowBg);

      $time = trim((string)($mm['time'] ?? ''));
      $title = trim((string)($mm['title'] ?? 'Плей-офф'));
      $home = trim((string)($mm['home'] ?? ''));
      $away = trim((string)($mm['away'] ?? ''));

$score = playoff_score_text(
  is_int($mm['hg'] ?? null) ? (int)$mm['hg'] : null,
  is_int($mm['ag'] ?? null) ? (int)$mm['ag'] : null,
  is_int($mm['hpg'] ?? null) ? (int)$mm['hpg'] : null,
  is_int($mm['apg'] ?? null) ? (int)$mm['apg'] : null
);

$penScore = playoff_pen_text(
  is_int($mm['hg'] ?? null) ? (int)$mm['hg'] : null,
  is_int($mm['ag'] ?? null) ? (int)$mm['ag'] : null,
  is_int($mm['hpg'] ?? null) ? (int)$mm['hpg'] : null,
  is_int($mm['apg'] ?? null) ? (int)$mm['apg'] : null
);

      $logoSize = 24;
      $metaX = $tableX + 18;
      $img->annotateImage($dMeta, $metaX, $y1 + 30, 0, ($time !== '' ? ($time . '  ') : '') . $title);

      $scoreX = $tableX + 480;
      $homeAreaLeft = $tableX + 40;
      $homeAreaRight = $scoreX - 32;
      $awayAreaLeft = $scoreX + 78;
      $awayAreaRight = $tableX + $tableW - 24;
      $textY = $y1 + 68;

      $homeMaxTextW = max(90, $homeAreaRight - $homeAreaLeft - $logoSize - 8);
      $homeTxt = lc_fit_text($img, $dTeam, $home, $homeMaxTextW);
      $homeMetrics = $img->queryFontMetrics($dTeam, $homeTxt);
      $homeTextW = (int)ceil($homeMetrics['textWidth'] ?? 0);
      $homeTextX = $homeAreaRight - $homeTextW;
      $homeLogoX = $homeTextX - 8 - $logoSize;
      lc_draw_team_logo($img, (string)($mm['home_logo_path'] ?? ''), $homeLogoX, $textY - 24, $logoSize);
      $img->annotateImage($dTeam, $homeTextX, $textY, 0, $homeTxt);

      $img->annotateImage($dScore, $scoreX, $textY, 0, $score);

//if ($penScore !== '') {
//  $img->annotateImage($dPen, $scoreX, $textY + 18, 0, $penScore);
//}

if ($penScore !== '') {
  $penMetrics = $img->queryFontMetrics($dPen, $penScore);
  $scoreMetrics = $img->queryFontMetrics($dScore, $score);

  $scoreCenterX = $scoreX + ((float)($scoreMetrics['textWidth'] ?? 0) / 2);
  $penX = (int)round($scoreCenterX - ((float)($penMetrics['textWidth'] ?? 0) / 2));

  $img->annotateImage($dPen, $penX, $textY + 25, 0, $penScore);
}

      $awayLogoX = $awayAreaLeft;
      $awayTextX = $awayLogoX + $logoSize + 8;
      $awayMaxTextW = max(90, $awayAreaRight - $awayTextX);
      $awayTxt = lc_fit_text($img, $dTeam, $away, $awayMaxTextW);
      lc_draw_team_logo($img, (string)($mm['away_logo_path'] ?? ''), $awayLogoX, $textY - 24, $logoSize);
      $img->annotateImage($dTeam, $awayTextX, $textY, 0, $awayTxt);
    }
  }

  $pdo = db();
  $generatedAt = trim((string)($meta['generated_at'] ?? ''));
  lc_draw_footer($img, $W, $H, $font, $generatedAt, $pdo);
  $img->writeImage($outPath);
  $img->clear();
  $img->destroy();
}


function resolve_match_participant_public(PDO $pdo, int $stageId, string $category, int $variant, array $m, string $side): array {
  $side = ($side === 'away') ? 'away' : 'home';
  $name = trim((string)($m[$side . '_name'] ?? ''));
  $city = format_city((string)($m[$side . '_city'] ?? ''));
  $logo = (string)($m[$side . '_logo_path'] ?? '');
  $label = trim((string)($m[$side . '_label'] ?? ''));
  $ref = trim((string)($m[$side . '_ref'] ?? ''));

  if ($name !== '') {
    return ['name' => $name, 'city' => $city, 'logo_path' => $logo];
  }
  return resolve_seed_ref_public($pdo, $stageId, $category, $variant, $label, $ref);
}

function compute_playoff_places(PDO $pdo, int $stageId, string $category, int $variant): array {
  $sql = "
    SELECT
      m.id,
      m.code,
      m.home_label,
      m.away_label,
      m.home_ref,
      m.away_ref,
      sh.name AS home_name,
      sa.name AS away_name,
      ch.city AS home_city,
      ca.city AS away_city,
      ch.logo_path AS home_logo_path,
      ca.logo_path AS away_logo_path,
      r.home_goals,
      r.away_goals,
      r.home_pen_goals,
      r.away_pen_goals
    FROM matches m
    LEFT JOIN squads sh ON sh.id = m.home_squad_id
    LEFT JOIN clubs ch ON ch.id = sh.club_id
    LEFT JOIN squads sa ON sa.id = m.away_squad_id
    LEFT JOIN clubs ca ON ca.id = sa.club_id
    LEFT JOIN results r ON r.match_id = m.id
    WHERE m.stage_id = :sid
      AND m.category = :cat
      AND m.variant = :v
      AND m.phase = 'playoff'
    ORDER BY m.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $places = [];

  foreach ($rows as $m) {
    $code = strtoupper(trim((string)($m['code'] ?? '')));
    if ($code === '') continue;

    $hg  = ($m['home_goals'] === null || $m['home_goals'] === '') ? null : (int)$m['home_goals'];
    $ag  = ($m['away_goals'] === null || $m['away_goals'] === '') ? null : (int)$m['away_goals'];
    $hpg = ($m['home_pen_goals'] === null || $m['home_pen_goals'] === '') ? null : (int)$m['home_pen_goals'];
    $apg = ($m['away_pen_goals'] === null || $m['away_pen_goals'] === '') ? null : (int)$m['away_pen_goals'];

    $winnerSide = null;
    $loserSide = null;

    if (is_int($hg) && is_int($ag)) {
      if ($hg > $ag) { $winnerSide = 'home'; $loserSide = 'away'; }
      elseif ($ag > $hg) { $winnerSide = 'away'; $loserSide = 'home'; }
      elseif (is_int($hpg) && is_int($apg) && $hpg !== $apg) {
        if ($hpg > $apg) { $winnerSide = 'home'; $loserSide = 'away'; }
        else { $winnerSide = 'away'; $loserSide = 'home'; }
      }
    }

    if (!$winnerSide || !$loserSide) continue;

    $winner = resolve_match_participant_public($pdo, $stageId, $category, $variant, $m, $winnerSide);
    $loser  = resolve_match_participant_public($pdo, $stageId, $category, $variant, $m, $loserSide);

    if ($code === 'F') {
      $places[1] = $winner;
      $places[2] = $loser;
      continue;
    }
    if ($code === '3') {
      $places[3] = $winner;
      $places[4] = $loser;
      continue;
    }
    if (preg_match('~^P(\d+)-(\d+)-F$~', $code, $mm)) {
      $from = (int)$mm[1];
      $places[$from] = $winner;
      $places[$from + 1] = $loser;
      continue;
    }
    if (preg_match('~^P(\d+)-(\d+)-3$~', $code, $mm)) {
      $from = (int)$mm[1];
      $places[$from + 2] = $winner;
      $places[$from + 3] = $loser;
      continue;
    }
  }

  ksort($places, SORT_NUMERIC);
  return $places;
}

function compute_fallback_places_from_groups(array $groups, array $byPair): array {
  $places = [];
  $placeNo = 1;
  $groupCodes = array_keys($groups);
  sort($groupCodes, SORT_STRING);

  foreach ($groupCodes as $g) {
    $sorted = sort_group($groups[$g], $byPair);
    foreach ($sorted['teams'] as $team) {
      $places[$placeNo++] = [
        'name' => trim((string)($team['name'] ?? '')),
        'city' => trim((string)($team['city'] ?? '')),
        'logo_path' => (string)($team['logo_path'] ?? ''),
      ];
    }
  }
  return $places;
}

function merge_places_with_fallback(array $placesFromPlayoff, array $fallbackPlaces): array {
  $merged = [];

  foreach ($placesFromPlayoff as $place => $team) {
    $place = (int)$place;
    if ($place <= 0 || !is_array($team)) continue;
    $name = trim((string)($team['name'] ?? ''));
    if ($name === '') continue;
    $merged[$place] = [
      'name' => $name,
      'city' => trim((string)($team['city'] ?? '')),
      'logo_path' => (string)($team['logo_path'] ?? ''),
    ];
  }

  $used = [];
  foreach ($merged as $team) {
    $key = mb_strtolower(trim((string)($team['name'] ?? '')), 'UTF-8');
    if ($key !== '') $used[$key] = true;
  }

  foreach ($fallbackPlaces as $place => $team) {
    $place = (int)$place;
    if ($place <= 0 || isset($merged[$place]) || !is_array($team)) continue;
    $name = trim((string)($team['name'] ?? ''));
    if ($name === '') continue;
    $key = mb_strtolower($name, 'UTF-8');
    if (isset($used[$key])) continue;

    $merged[$place] = [
      'name' => $name,
      'city' => trim((string)($team['city'] ?? '')),
      'logo_path' => (string)($team['logo_path'] ?? ''),
    ];
    $used[$key] = true;
  }

  ksort($merged, SORT_NUMERIC);
  return $merged;
}

function render_final_places_image_imagick(array $meta, array $places, string $outPath): void {
  if (!class_exists('Imagick')) throw new RuntimeException('Imagick не доступен');

  $W = 1080;
  $H = 1350;
  $font = find_font_path();
  $seasonText = trim((string)($meta['season_text'] ?? '26 Весна'));
  if ($seasonText === '') $seasonText = '26 Весна';

  $img = new Imagick();
  $img->newImage($W, $H, new ImagickPixel('#e9f5fb'));
  $img->setImageFormat('png');

  $bgPath = design_asset_abs('/uploads/tournament/design/bg_table.png');
  if ($bgPath) {
    $bg = new Imagick($bgPath);
    $bg->setImageFormat('png');
    cover_resize($bg, $W, $H);
    $img->compositeImage($bg, Imagick::COMPOSITE_OVER, 0, 0);
    $bg->clear();
    $bg->destroy();
  }

  $mist = new ImagickDraw();
  $mist->setFillColor('rgba(255,255,255,0.20)');
  $mist->ellipse((int)($W/2), (int)($H/2), 430, 520, 0, 360);
  $img->drawImage($mist);

  $mainLogoPath = design_asset_abs('/uploads/tournament/design/logo_main.png');
  if ($mainLogoPath) {
    $logo = new Imagick($mainLogoPath);
    $logo->setImageFormat('png');
    fit_inside($logo, 170, 150);
    $img->compositeImage($logo, Imagick::COMPOSITE_OVER, 28, 22);
    $logo->clear();
    $logo->destroy();
  }

  $titlePlatePath = design_asset_abs('/uploads/tournament/design/title_plate.png');
  $titleW = 620; $titleX = 218; $titleY = 28;
  if ($titlePlatePath) {
    $plate = new Imagick($titlePlatePath);
    $plate->setImageFormat('png');
    $plate->resizeImage($titleW, 112, Imagick::FILTER_LANCZOS, 1, false);
    $img->compositeImage($plate, Imagick::COMPOSITE_OVER, $titleX, $titleY);
    $plate->clear();
    $plate->destroy();
  }
  draw_centered_fit_text($img, "LAZACUP'" . $seasonText, $titleX + (int)($titleW/2), 92, $titleW - 120, 44, '#ffffff', $font, 24);

  $stageName = trim((string)($meta['stage_name'] ?? ''));
  $rightX = $W - 54;
  if ($stageName !== '') draw_right_text($img, $stageName, $rightX, 76, 34, '#173f69', $font);

  $catPlatePath = design_asset_abs('/uploads/tournament/design/category_plate.png');
  $catW = 420; $catX = (int)(($W - $catW) / 2); $catY = 156;
  if ($catPlatePath) {
    $cp = new Imagick($catPlatePath);
    $cp->setImageFormat('png');
    $cp->resizeImage($catW, 64, Imagick::FILTER_LANCZOS, 1, false);
    $img->compositeImage($cp, Imagick::COMPOSITE_OVER, $catX, $catY);
    $cp->clear();
    $cp->destroy();
  }
  draw_centered_fit_text($img, trim((string)($meta['category'] ?? '')) . ' • итог', (int)($W/2), $catY + 38, $catW - 40, 24, '#ffffff', $font, 16);

  $cardX = 54; $cardY = 245; $cardW = $W - 108; $cardH = $H - 360;
  $card = new ImagickDraw();
  $card->setFillColor('rgba(255,255,255,0.50)');
  $card->setStrokeColor('rgba(255,255,255,0.60)');
  $card->setStrokeWidth(2);
  $card->roundRectangle($cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 24, 24);
  $img->drawImage($card);

  $tableX = $cardX + 26;
  $tableY = $cardY + 28;
  $tableW = $cardW - 52;

  $titleDraw = new ImagickDraw();
  $titleDraw->setTextAntialias(true);
  $titleDraw->setFillColor('#173f69');
  $titleDraw->setFontSize(30);
  if ($font) $titleDraw->setFont($font);
  $img->annotateImage($titleDraw, $tableX + 6, $tableY + 34, 0, 'Итоговые места:');

  $dPlace = new ImagickDraw();
  $dPlace->setTextAntialias(true);
  $dPlace->setFillColor('#173f69');
  $dPlace->setFontSize(34);
  if ($font) $dPlace->setFont($font);

  $dName = new ImagickDraw();
  $dName->setTextAntialias(true);
  $dName->setFillColor('#102b47');
  $dName->setFontSize(30);
  if ($font) $dName->setFont($font);

  $dCity = new ImagickDraw();
  $dCity->setTextAntialias(true);
  $dCity->setFillColor('#173f69');
  $dCity->setFontSize(20);
  if ($font) $dCity->setFont($font);

  $rows = max(1, count($places));
  $listY = $tableY + 64;
  $availableH = ($cardY + $cardH) - $listY - 24;
  $rowH = (int)floor(min(110, max(84, $availableH / max($rows, 6))));

  $i = 0;
  foreach ($places as $place => $team) {
    $y1 = $listY + $i * $rowH;
    $y2 = $y1 + $rowH - 10;

    $rowBg = new ImagickDraw();
    $rowBg->setFillColor(($i % 2) ? 'rgba(255,255,255,0.18)' : 'rgba(255,255,255,0.28)');
    $rowBg->setStrokeColor('rgba(23,63,105,0.14)');
    $rowBg->setStrokeWidth(1);
    $rowBg->roundRectangle($tableX, $y1, $tableX + $tableW, $y2, 14, 14);
    $img->drawImage($rowBg);

    $baseline = $y1 + 50;
    $placeText = $place . '.';
    $img->annotateImage($dPlace, $tableX + 20, $baseline, 0, $placeText);

    $logoX = $tableX + 98;
    $logoSize = 44;
    lc_draw_team_logo($img, (string)($team['logo_path'] ?? ''), $logoX, $baseline - 38, $logoSize);

    $nameX = $logoX + $logoSize + 16;
    $name = trim((string)($team['name'] ?? ''));
    $city = trim((string)($team['city'] ?? ''));
    $nameTxt = lc_fit_text($img, $dName, $name, $tableW - 210);
    $img->annotateImage($dName, $nameX, $baseline, 0, $nameTxt);

    if ($city !== '') {
      $cityTxt = lc_fit_text($img, $dCity, $city, $tableW - 210);
      $img->annotateImage($dCity, $nameX, $baseline + 28, 0, $cityTxt);
    }
    $i++;
  }

  $pdo = db();
  $generatedAt = trim((string)($meta['generated_at'] ?? ''));
  lc_draw_footer($img, $W, $H, $font, $generatedAt, $pdo);
  $img->writeImage($outPath);
  $img->clear();
  $img->destroy();
}

/** @return array{st:array, pos:array, name:array} */
function compute_overall(array $teams, array $byPair): array {
  $st = [];
  $pos = [];
  $name = [];

  foreach ($teams as $idx => $t) {
    $id = (int)($t['id'] ?? 0);
    if ($id <= 0) continue;
    $st[$id] = ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0,'played'=>0,'place'=>0];
    $name[$id] = (string)($t['name'] ?? '');
    $rawPos = $t['pos'] ?? ($idx + 1);
    $rp = is_numeric($rawPos) ? (int)$rawPos : ($idx + 1);
    $pos[$id] = ($rp > 0) ? $rp : ($idx + 1);
  }

  $n = count($teams);
  for ($i=0; $i<$n; $i++) {
    $aId = (int)($teams[$i]['id'] ?? 0);
    if ($aId <= 0) continue;
    for ($j=$i+1; $j<$n; $j++) {
      $bId = (int)($teams[$j]['id'] ?? 0);
      if ($bId <= 0) continue;

      $k = pair_key($aId, $bId);
      if (!isset($byPair[$k])) continue;
      $m = $byPair[$k];
      if (!is_played($m)) continue;

      $hg = (int)$m['home_goals'];
      $ag = (int)$m['away_goals'];
      $hId = (int)$m['home_squad_id'];
      $awId = (int)$m['away_squad_id'];
      if (!isset($st[$hId]) || !isset($st[$awId])) continue;

      $st[$hId]['played']++; $st[$awId]['played']++;
      $st[$hId]['gf'] += $hg; $st[$hId]['ga'] += $ag;
      $st[$awId]['gf'] += $ag; $st[$awId]['ga'] += $hg;

      if ($hg > $ag) {
        $st[$hId]['pts'] += 3; $st[$hId]['wins'] += 1;
      } elseif ($hg < $ag) {
        $st[$awId]['pts'] += 3; $st[$awId]['wins'] += 1;
      } else {
        $st[$hId]['pts'] += 1; $st[$awId]['pts'] += 1;
      }
    }
  }

  return ['st'=>$st,'pos'=>$pos,'name'=>$name];
}

/** @return array<int,array{pts:int,wins:int,gf:int,ga:int}> */
function compute_h2h(array $ids, array $byPair): array {
  $out = [];
  foreach ($ids as $id) {
    $out[(int)$id] = ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0];
  }

  $n = count($ids);
  for ($i=0; $i<$n; $i++) {
    for ($j=$i+1; $j<$n; $j++) {
      $aId = (int)$ids[$i];
      $bId = (int)$ids[$j];
      $k = pair_key($aId, $bId);
      if (!isset($byPair[$k])) continue;

      $m = $byPair[$k];
      if (!is_played($m)) continue;

      $hg = (int)$m['home_goals'];
      $ag = (int)$m['away_goals'];

      $hId  = (int)$m['home_squad_id'];
      $awId = (int)$m['away_squad_id'];
      if (!isset($out[$hId]) || !isset($out[$awId])) continue;

      $out[$hId]['gf'] += $hg; $out[$hId]['ga'] += $ag;
      $out[$awId]['gf'] += $ag; $out[$awId]['ga'] += $hg;

      if ($hg > $ag) {
        $out[$hId]['pts'] += 3; $out[$hId]['wins'] += 1;
      } elseif ($hg < $ag) {
        $out[$awId]['pts'] += 3; $out[$awId]['wins'] += 1;
      } else {
        $out[$hId]['pts'] += 1; $out[$awId]['pts'] += 1;
      }
    }
  }

  return $out;
}

function make_key(array $over, array $h2h): string {
  $gd  = ($over['gf'] ?? 0) - ($over['ga'] ?? 0);
  $hgd = ($h2h['gf'] ?? 0) - ($h2h['ga'] ?? 0);
  return implode('|', [
    (int)($over['pts'] ?? 0),
    (int)($over['wins'] ?? 0),
    (int)($h2h['pts'] ?? 0),
    (int)($h2h['wins'] ?? 0),
    (int)$hgd,
    (int)($h2h['gf'] ?? 0),
    (int)$gd,
    (int)($over['gf'] ?? 0),
  ]);
}

function detect_hard_tie(array $teams, array $overall, array $h2h): bool {
  $n = count($teams);
  for ($i=1; $i<$n; $i++) {
    $aId = (int)$teams[$i-1]['id'];
    $bId = (int)$teams[$i]['id'];
    $oa = $overall[$aId] ?? ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0];
    $ob = $overall[$bId] ?? ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0];
    if (($oa['pts'] ?? 0) !== ($ob['pts'] ?? 0) || ($oa['wins'] ?? 0) !== ($ob['wins'] ?? 0)) continue;
    $ha = $h2h[$aId] ?? ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0];
    $hb = $h2h[$bId] ?? ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0];
    if (make_key($oa, $ha) === make_key($ob, $hb)) return true;
  }
  return false;
}

/**
 * @return array{teams:array, standings:array, usedManual:bool, hardTie:bool}
 */
function sort_group(array $baseTeams, array $byPair): array {
  // ручная расстановка приоритетна
  $hasManual = false;
  foreach ($baseTeams as $t) {
    $mp = $t['manual_place'] ?? null;
    if ($mp !== null && $mp !== '' && is_numeric($mp)) { $hasManual = true; break; }
  }

  $overallPack = compute_overall($baseTeams, $byPair);
  $overall = $overallPack['st'];
  $posById = $overallPack['pos'];
  $nameById = $overallPack['name'];

  if ($hasManual) {
    $teams = $baseTeams;
    usort($teams, function($a, $b) use ($posById) {
      $ma = ($a['manual_place'] ?? null);
      $mb = ($b['manual_place'] ?? null);
      $ma = (is_numeric($ma) ? (int)$ma : 9999);
      $mb = (is_numeric($mb) ? (int)$mb : 9999);
      if ($ma !== $mb) return $ma <=> $mb;
      $aid = (int)($a['id'] ?? 0);
      $bid = (int)($b['id'] ?? 0);
      return ($posById[$aid] ?? 0) <=> ($posById[$bid] ?? 0);
    });
    foreach ($teams as $i => $t) {
      $id = (int)$t['id'];
      if (isset($overall[$id])) $overall[$id]['place'] = $i + 1;
    }
    return ['teams'=>$teams,'standings'=>$overall,'usedManual'=>true,'hardTie'=>false];
  }

  // bucket h2h по (pts,wins)
  $buckets = [];
  foreach ($baseTeams as $t) {
    $id = (int)($t['id'] ?? 0);
    $s = $overall[$id] ?? ['pts'=>0,'wins'=>0];
    $k = (int)$s['pts'] . '|' . (int)$s['wins'];
    if (!isset($buckets[$k])) $buckets[$k] = [];
    $buckets[$k][] = $id;
  }

  $h2h = [];
  foreach ($buckets as $ids) {
    if (count($ids) <= 1) continue;
    $sub = compute_h2h($ids, $byPair);
    foreach ($ids as $id) {
      $h2h[$id] = $sub[$id] ?? ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0];
    }
  }

  $teams = $baseTeams;
  usort($teams, function($a, $b) use ($overall, $h2h, $posById, $nameById) {
    $aid = (int)($a['id'] ?? 0);
    $bid = (int)($b['id'] ?? 0);
    $oa = $overall[$aid] ?? ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0];
    $ob = $overall[$bid] ?? ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0];
    $gdA = (int)($oa['gf'] ?? 0) - (int)($oa['ga'] ?? 0);
    $gdB = (int)($ob['gf'] ?? 0) - (int)($ob['ga'] ?? 0);

    if ((int)($ob['pts'] ?? 0) !== (int)($oa['pts'] ?? 0)) return (int)($ob['pts'] ?? 0) <=> (int)($oa['pts'] ?? 0);
    if ((int)($ob['wins'] ?? 0) !== (int)($oa['wins'] ?? 0)) return (int)($ob['wins'] ?? 0) <=> (int)($oa['wins'] ?? 0);

    $ha = $h2h[$aid] ?? ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0];
    $hb = $h2h[$bid] ?? ['pts'=>0,'wins'=>0,'gf'=>0,'ga'=>0];
    $hgdA = (int)($ha['gf'] ?? 0) - (int)($ha['ga'] ?? 0);
    $hgdB = (int)($hb['gf'] ?? 0) - (int)($hb['ga'] ?? 0);

    if ((int)($hb['pts'] ?? 0) !== (int)($ha['pts'] ?? 0)) return (int)($hb['pts'] ?? 0) <=> (int)($ha['pts'] ?? 0);
    if ((int)($hb['wins'] ?? 0) !== (int)($ha['wins'] ?? 0)) return (int)($hb['wins'] ?? 0) <=> (int)($ha['wins'] ?? 0);
    if ($hgdB !== $hgdA) return $hgdB <=> $hgdA;
    if ((int)($hb['gf'] ?? 0) !== (int)($ha['gf'] ?? 0)) return (int)($hb['gf'] ?? 0) <=> (int)($ha['gf'] ?? 0);

    if ($gdB !== $gdA) return $gdB <=> $gdA;
    if ((int)($ob['gf'] ?? 0) !== (int)($oa['gf'] ?? 0)) return (int)($ob['gf'] ?? 0) <=> (int)($oa['gf'] ?? 0);

    $pa = $posById[$aid] ?? 0;
    $pb = $posById[$bid] ?? 0;
    if ($pa !== $pb) return $pa <=> $pb;
    $na = (string)($nameById[$aid] ?? '');
    $nb = (string)($nameById[$bid] ?? '');
    return strcmp(mb_strtolower($na,'UTF-8'), mb_strtolower($nb,'UTF-8'));
  });

  $hard = detect_hard_tie($teams, $overall, $h2h);
  foreach ($teams as $i => $t) {
    $id = (int)$t['id'];
    if (isset($overall[$id])) $overall[$id]['place'] = $i + 1;
  }
  return ['teams'=>$teams,'standings'=>$overall,'usedManual'=>false,'hardTie'=>$hard];
}

function render_group_image_imagick(array $meta, array $teams, array $standings, string $outPath): void {
  if (!class_exists('Imagick')) throw new RuntimeException('Imagick не доступен');

  $W = 1080;
  $H = 1350;
  $font = find_font_path();
  $showResults = !empty($meta['show_results']);
  $seasonText = trim((string)($meta['season_text'] ?? '26 Весна'));
  if ($seasonText === '') $seasonText = '26 Весна';

  $img = new Imagick();
  $img->newImage($W, $H, new ImagickPixel('#e9f5fb'));
  $img->setImageFormat('png');

  $bgPath = design_asset_abs('/uploads/tournament/design/bg_table.png');
  if ($bgPath) {
    $bg = new Imagick($bgPath);
    $bg->setImageFormat('png');
    cover_resize($bg, $W, $H);
    $img->compositeImage($bg, Imagick::COMPOSITE_OVER, 0, 0);
    $bg->clear();
    $bg->destroy();
  }

  $mist = new ImagickDraw();
  $mist->setFillColor('rgba(255,255,255,0.20)');
  $mist->ellipse((int)($W/2), (int)($H/2), 430, 520, 0, 360);
  $img->drawImage($mist);

  $mainLogoPath = design_asset_abs($showResults ? '/uploads/tournament/design/logo_main.png' : '/uploads/tournament/design/logo_cup.png');
  if ($mainLogoPath) {
    $logo = new Imagick($mainLogoPath);
    $logo->setImageFormat('png');
    fit_inside($logo, $showResults ? 170 : 180, $showResults ? 150 : 180);
    $img->compositeImage($logo, Imagick::COMPOSITE_OVER, $showResults ? 28 : 28, $showResults ? 22 : 20);
    $logo->clear();
    $logo->destroy();
  }

  $titlePlatePath = design_asset_abs('/uploads/tournament/design/title_plate.png');
  $titleW = 620; $titleX = 218; $titleY = 28;
  if ($titlePlatePath) {
    $plate = new Imagick($titlePlatePath);
    $plate->setImageFormat('png');
    //fit_inside($plate, $titleW, 112);
    $plate->resizeImage($titleW, 112, Imagick::FILTER_LANCZOS, 1, false);
    $img->compositeImage($plate, Imagick::COMPOSITE_OVER, $titleX, $titleY);
    $plate->clear();
    $plate->destroy();
  }
  draw_centered_fit_text($img, "LAZACUP'" . $seasonText, $titleX + (int)($titleW/2), 92, $titleW - 120, 44, '#ffffff', $font, 24);

  $stageName = trim((string)($meta['stage_name'] ?? ''));
  $dayDate = trim((string)($meta['day_date'] ?? ''));
  $rightX = $W - 54;
  //if ($stageName !== '') draw_right_text($img, $stageName, $rightX, 72, 26, '#ffffff', $font);
  //if ($dayDate !== '') draw_right_text($img, $dayDate, $rightX, 104, 18, 'rgba(255,255,255,0.92)', $font);
  if ($stageName !== '') draw_right_text($img, $stageName, $rightX, 76, 34, '#173f69', $font);
  if ($dayDate !== '') draw_right_text($img, date('d-m-Y', strtotime($dayDate)), $rightX, 112, 24, '#173f69', $font);

  $catPlatePath = design_asset_abs('/uploads/tournament/design/category_plate.png');
  $catW = 340;
  $catX = (int)(($W - $catW) / 2);
  $catY = 156;
  if ($catPlatePath) {
    $cp = new Imagick($catPlatePath);
    $cp->setImageFormat('png');
    //fit_inside($cp, $catW, 64);
    $cp->resizeImage($catW, 64, Imagick::FILTER_LANCZOS, 1, false);
    $img->compositeImage($cp, Imagick::COMPOSITE_OVER, $catX, $catY);
    $cp->clear();
    $cp->destroy();
  }
  $groupCode = trim((string)($meta['group_code'] ?? ''));
  $catCaption = trim((string)($meta['category'] ?? '')) . ($groupCode !== '' ? ' • группа ' . $groupCode : '');
  draw_centered_fit_text($img, $catCaption, (int)($W/2), $catY + 38, $catW - 40, 24, '#ffffff', $font, 16);

  //$cardX = 54; $cardY = 225; $cardW = $W - 108; $cardH = $H - 280;
  $cardX = 54;
  $cardY = 245;
  $cardW = $W - 108;
  $cardH = $H - 360;
  $card = new ImagickDraw();
  $card->setFillColor('rgba(255,255,255,0.50)');
  $card->setStrokeColor('rgba(255,255,255,0.60)');
  $card->setStrokeWidth(2);
  $card->roundRectangle($cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 24, 24);
  $img->drawImage($card);

  $tableX = $cardX + 26;
  $tableY = $cardY + 28;
  $tableW = $cardW - 52;
  $rows = max(1, count($teams));
  $headerH = 52;
  $resultsReserve = $showResults ? 280 : 30;
  $availableRowsH = $cardH - 78 - $resultsReserve - $headerH;
  $rowH = (int)floor(min(60, max(44, $availableRowsH / max($rows, 7))));
  $tableH = $headerH + $rows * $rowH;

  $colNoW = 58;
  $colPtsW = 88;
  $colGFGAW = 120;
  $colNameW = $tableW - ($colNoW + $colPtsW + $colGFGAW);

  $hdr = new ImagickDraw();
  $hdr->setFillColor('rgba(23,63,105,0.08)');
  $hdr->setStrokeColor('rgba(23,63,105,0.22)');
  $hdr->setStrokeWidth(1);
  $hdr->rectangle($tableX, $tableY, $tableX + $tableW, $tableY + $headerH);
  $img->drawImage($hdr);

  for ($i = 0; $i < $rows; $i++) {
    $y1 = $tableY + $headerH + $i * $rowH;
    if (($i % 2) === 1) {
      $rowBg = new ImagickDraw();
      $rowBg->setFillColor('rgba(255,255,255,0.20)');
      $rowBg->setStrokeColor('rgba(0,0,0,0)');
      $rowBg->rectangle($tableX, $y1, $tableX + $tableW, $y1 + $rowH);
      $img->drawImage($rowBg);
    }
  }

  $grid = new ImagickDraw();
  $grid->setFillColor('rgba(0,0,0,0)');
  $grid->setStrokeColor('rgba(23,63,105,0.22)');
  $grid->setStrokeWidth(1);
  $grid->rectangle($tableX, $tableY, $tableX + $tableW, $tableY + $tableH);
  $grid->line($tableX + $colNoW, $tableY, $tableX + $colNoW, $tableY + $tableH);
  $grid->line($tableX + $colNoW + $colNameW, $tableY, $tableX + $colNoW + $colNameW, $tableY + $tableH);
  $grid->line($tableX + $colNoW + $colNameW + $colPtsW, $tableY, $tableX + $colNoW + $colNameW + $colPtsW, $tableY + $tableH);
  $grid->line($tableX, $tableY + $headerH, $tableX + $tableW, $tableY + $headerH);
  for ($i = 1; $i < $rows; $i++) {
    $y = $tableY + $headerH + $i * $rowH;
    $grid->line($tableX, $y, $tableX + $tableW, $y);
  }
  $img->drawImage($grid);

  $dHead = new ImagickDraw();
  $dHead->setTextAntialias(true);
  $dHead->setFillColor('#173f69');
  $dHead->setFontSize(24);
  if ($font) $dHead->setFont($font);
  $img->annotateImage($dHead, $tableX + 14, $tableY + 34, 0, '№');
  $img->annotateImage($dHead, $tableX + $colNoW + 18, $tableY + 34, 0, 'КОМАНДЫ');
  $img->annotateImage($dHead, $tableX + $colNoW + $colNameW + 16, $tableY + 34, 0, 'О');
  $img->annotateImage($dHead, $tableX + $colNoW + $colNameW + $colPtsW + 16, $tableY + 34, 0, 'З-П');

  $dRow = new ImagickDraw();
  $dRow->setTextAntialias(true);
  $dRow->setFillColor('#102b47');
  $dRow->setFontSize(23);
  if ($font) $dRow->setFont($font);
  $dRowSmall = new ImagickDraw();
  $dRowSmall->setTextAntialias(true);
  $dRowSmall->setFillColor('#102b47');
  $dRowSmall->setFontSize(22);
  if ($font) $dRowSmall->setFont($font);

  for ($i = 0; $i < $rows; $i++) {
    $t = $teams[$i];
    $id = (int)($t['id'] ?? 0);
    $st = $standings[$id] ?? ['pts'=>0,'gf'=>0,'ga'=>0,'place'=>$i+1];
    $baseline = $tableY + $headerH + $i * $rowH + (int)round($rowH * 0.66);

$name = trim((string)($t['name'] ?? ''));
$city = trim((string)($t['city'] ?? ''));
if ($city !== '') {
  $name .= ' • ' . $city;
}

$logoPath = (string)($t['logo_path'] ?? '');
$nameX = $tableX + $colNoW + 14;
lc_draw_team_logo($img, $logoPath, $nameX, $baseline - 30, 36);
$nameTxt = lc_fit_text($img, $dRow, $name, $colNameW - 66);

$img->annotateImage($dRowSmall, $tableX + 14, $baseline, 0, (string)($i + 1));
$img->annotateImage($dRow, $nameX + 46, $baseline, 0, $nameTxt);
    $img->annotateImage($dRowSmall, $tableX + $colNoW + $colNameW + 16, $baseline, 0, (string)(int)($st['pts'] ?? 0));
    $img->annotateImage($dRowSmall, $tableX + $colNoW + $colNameW + $colPtsW + 16, $baseline, 0, ((int)($st['gf'] ?? 0)) . '-' . ((int)($st['ga'] ?? 0)));
  }

  if ($showResults) {
    $matches = (isset($meta['matches']) && is_array($meta['matches'])) ? $meta['matches'] : [];
    $resY = $tableY + $tableH + 40;
    $resH = ($cardY + $cardH) - $resY - 24;

    $resCard = new ImagickDraw();
    $resCard->setFillColor('rgba(255,255,255,0.52)');
    $resCard->setStrokeColor('rgba(23,63,105,0.14)');
    $resCard->setStrokeWidth(1);
    $resCard->roundRectangle($tableX, $resY, $tableX + $tableW, $resY + $resH, 18, 18);
    $img->drawImage($resCard);

    $dResTitle = new ImagickDraw();
    $dResTitle->setTextAntialias(true);
    $dResTitle->setFillColor('#173f69');
    $dResTitle->setFontSize(30);
    if ($font) $dResTitle->setFont($font);
    $img->annotateImage($dResTitle, $tableX + 18, $resY + 42, 0, 'Матчи дня:');

    $dRes = new ImagickDraw();
    $dRes->setTextAntialias(true);
    $dRes->setFillColor('#102b47');
    $dRes->setFontSize(24);
    if ($font) $dRes->setFont($font);

    $dScore = new ImagickDraw();
    $dScore->setTextAntialias(true);
    $dScore->setFillColor('#173f69');
    $dScore->setFontSize(24);
    if ($font) $dScore->setFont($font);

$dPen = new ImagickDraw();
$dPen->setTextAntialias(true);
$dPen->setFillColor('#173f69');
$dPen->setFontSize(20);
if ($font) $dPen->setFont($font);

    $lineY = $resY + 90;
    $step = 38;
    $maxLines = max(3, (int)floor(($resH - 96) / $step));
    if (!$matches) {
      $img->annotateImage($dRes, $tableX + 18, $lineY, 0, 'Матчей на этот день нет');
    } else {
      $n = 0;
      foreach ($matches as $mm) {
        if ($n >= $maxLines) break;
        $time = trim((string)($mm['time'] ?? ''));
        $home = trim((string)($mm['home'] ?? ''));
        $away = trim((string)($mm['away'] ?? ''));
        $homeLogo = (string)($mm['home_logo_path'] ?? '');
        $awayLogo = (string)($mm['away_logo_path'] ?? '');
        $hg = $mm['hg'] ?? null;
        $ag = $mm['ag'] ?? null;
        $hpg = $mm['hpg'] ?? null;
  $apg = $mm['apg'] ?? null;
  //$score = playoff_score_text(is_int($hg) ? $hg : null, is_int($ag) ? $ag : null, is_int($hpg) ? $hpg : null, is_int($apg) ? $apg : null);

$score = playoff_score_text(
  is_int($mm['hg'] ?? null) ? (int)$mm['hg'] : null,
  is_int($mm['ag'] ?? null) ? (int)$mm['ag'] : null,
  is_int($mm['hpg'] ?? null) ? (int)$mm['hpg'] : null,
  is_int($mm['apg'] ?? null) ? (int)$mm['apg'] : null
);

$penScore = playoff_pen_text(
  is_int($mm['hg'] ?? null) ? (int)$mm['hg'] : null,
  is_int($mm['ag'] ?? null) ? (int)$mm['ag'] : null,
  is_int($mm['hpg'] ?? null) ? (int)$mm['hpg'] : null,
  is_int($mm['apg'] ?? null) ? (int)$mm['apg'] : null
);

$timeX = $tableX + 18;
$scoreX = $tableX + 468;

// Левая зона: домашняя команда
$homeAreaLeft  = $tableX + 120;
$homeAreaRight = $scoreX - 28;

// Правая зона: гостевая команда
$awayAreaLeft  = $scoreX + 78;
$awayAreaRight = $tableX + $tableW - 24;

$logoSize = 24;
$logoGap  = 8;

if ($time !== '') {
    $img->annotateImage($dRes, $timeX, $lineY, 0, $time);
}

// HOME — прижимаем к правому краю своей зоны
$homeMaxTextW = max(80, $homeAreaRight - $homeAreaLeft - $logoSize - $logoGap);
$homeTxt = lc_fit_text($img, $dRes, $home, $homeMaxTextW);
$homeMetrics = $img->queryFontMetrics($dRes, $homeTxt);
$homeTextW = (int)ceil($homeMetrics['textWidth'] ?? 0);

$homeTextX = $homeAreaRight - $homeTextW;
$homeLogoX = $homeTextX - $logoGap - $logoSize;

lc_draw_team_logo($img, $homeLogo, $homeLogoX, $lineY - 24, $logoSize);
$img->annotateImage($dRes, $homeTextX, $lineY, 0, $homeTxt);

// SCORE — строго по центру
$img->annotateImage($dScore, $scoreX, $lineY, 0, $score);

// AWAY — обычное выравнивание слева направо
$awayLogoX = $awayAreaLeft;
$awayTextX = $awayLogoX + $logoSize + $logoGap;

$awayMaxTextW = max(80, $awayAreaRight - $awayTextX);
$awayTxt = lc_fit_text($img, $dRes, $away, $awayMaxTextW);

lc_draw_team_logo($img, $awayLogo, $awayLogoX, $lineY - 24, $logoSize);
$img->annotateImage($dRes, $awayTextX, $lineY, 0, $awayTxt);

$lineY += $step;
$n++;
      }
    }
  }

  $pdo = db();
  $generatedAt = trim((string)($meta['generated_at'] ?? ''));
  lc_draw_footer($img, $W, $H, $font, $generatedAt, $pdo);

  $img->writeImage($outPath);
  $img->clear();
  $img->destroy();
}

// ---- input ----
$in = body_json();

$stageId = int0($in['stage_id'] ?? ($_GET['stage_id'] ?? 0));
$category = str0($in['category'] ?? ($_GET['category'] ?? ''));
$variant = int0($in['variant'] ?? ($_GET['variant'] ?? 1));
$rawDay = (string)($in['day'] ?? ($_GET['day'] ?? 1));
$day = int0($rawDay);
$isFinalMode = in_array(mb_strtolower(trim($rawDay), 'UTF-8'), ['999','итог','final'], true);
$dry = int0($in['dry'] ?? ($_GET['dry'] ?? 0));
$showResults = int0($in['show_results'] ?? ($_GET['show_results'] ?? 1)) !== 0;
$seasonText = str0($in['season_text'] ?? ($_GET['season_text'] ?? '26 Весна'));
if ($seasonText === '') $seasonText = '26 Весна';

// Фоллбек для превью: если endpoint дернули без параметров (часто бывает из-за кеша/форм-сабмита),
// берём "current stage" и первую категорию этого этапа.
if ($stageId <= 0) {
  $t = $pdo->query("SELECT id FROM tournaments WHERE is_current=1 ORDER BY id DESC LIMIT 1")->fetch();
  if ($t) {
    $st0 = $pdo->prepare("SELECT id FROM stages WHERE tournament_id=:tid AND is_current=1 ORDER BY id DESC LIMIT 1");
    $st0->execute([':tid' => (int)$t['id']]);
    $sr0 = $st0->fetch();
    if ($sr0 && !empty($sr0['id'])) $stageId = (int)$sr0['id'];
  }
}
if ($category === '' && $stageId > 0) {
  $sc0 = $pdo->prepare("SELECT category FROM stage_categories WHERE stage_id=:sid ORDER BY category ASC LIMIT 1");
  $sc0->execute([':sid' => $stageId]);
  $cr0 = $sc0->fetch();
  if ($cr0 && isset($cr0['category'])) $category = trim((string)$cr0['category']);
}

if ($stageId <= 0 || $category === '') {
  respond_json(['ok'=>false,'error'=>'stage_id и category обязательны', 'debug'=>[
    'stage_id'=>$stageId,
    'category'=>$category,
    'variant'=>$variant,
    'method'=>($_SERVER['REQUEST_METHOD'] ?? ''),
    'content_type'=>($_SERVER['CONTENT_TYPE'] ?? ''),
  ]], 400);
}
if ($variant <= 0) $variant = 1;
if ($day <= 0) $day = 1;

// имена турнира/этапа
$st = $pdo->prepare("
  SELECT s.*, t.name AS tournament_name
  FROM stages s
  JOIN tournaments t ON t.id = s.tournament_id
  WHERE s.id = :sid
  LIMIT 1
");
$st->execute([':sid' => $stageId]);
$stageRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$stageRow) {
  throw new Exception('Stage not found');
}

lc_require_tournament_not_archived($pdo, (int)($stageRow['tournament_id'] ?? 0));

$st->execute([':sid'=>$stageId]);
$stageRow = $st->fetch();
if (!$stageRow) {
  respond_json(['ok'=>false,'error'=>'stage not found'], 404);
}

$tournamentName = (string)($stageRow['tournament_name'] ?? '');
$stageName = (string)($stageRow['name'] ?? '');

$stageFrom = (string)($stageRow['date_from'] ?? '');
$stageTo   = (string)($stageRow['date_to']   ?? '');

$stageFrom = $stageFrom ? substr($stageFrom, 0, 10) : '';
$stageTo   = $stageTo   ? substr($stageTo,   0, 10) : '';


// day_no -> day_date: ИСТОЧНИК ИСТИНЫ = реальные игровые дни из schedule_items для этой category+variant
// Берём и group, и playoff — иначе playoff-дни вообще не попадут в выпадающий список.
$days = [];

try {
  $sql = "
    SELECT DISTINCT si.day_date
    FROM schedule_items si
    JOIN matches m ON m.id = si.match_id
    WHERE si.stage_id = :sid1
      AND m.stage_id  = :sid2
      AND m.category  = :cat
      AND m.variant   = :v
      AND m.phase IN ('group','playoff')
  ";

  $params = [
    ':sid1' => $stageId,
    ':sid2' => $stageId,
    ':cat'  => $category,
    ':v'    => $variant,
  ];

  // если у этапа есть диапазон — не вылезаем за него
  if ($stageFrom !== '' && $stageTo !== '') {
    $sql .= " AND si.day_date BETWEEN :df AND :dt ";
    $params[':df'] = $stageFrom;
    $params[':dt'] = $stageTo;
  }

  $sql .= " ORDER BY si.day_date ASC ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $days = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $days = array_values(array_filter(array_map('strval', $days)));
} catch (Throwable $e) {
  $days = [];
}

// fallback: если расписание ещё не сохранено/пустое — берём stage_days
if (!$days) {
  $sql = "SELECT day_date FROM stage_days WHERE stage_id=:sid";
  $params = [':sid'=>$stageId];

  if ($stageFrom !== '' && $stageTo !== '') {
    $sql .= " AND day_date BETWEEN :df AND :dt";
    $params[':df'] = $stageFrom;
    $params[':dt'] = $stageTo;
  }

  $sql .= " ORDER BY day_date ASC";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $days = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $days = array_values(array_filter(array_map('strval', $days)));
}

$dayCount = count($days);
if ($dayCount <= 0) $dayCount = 1;

if (!$isFinalMode) {
  if ($day < 1) $day = 1;
  if ($day > $dayCount) $day = $dayCount;
}

$dayDate = $isFinalMode
  ? ''
  : (($days && isset($days[$day-1]))
      ? (string)$days[$day-1]
      : pick_day_date($pdo, $stageId, $day, $stageFrom, $stageTo));

// Лёгкий режим: только вычислить day_count/day_no/day_date, без построения групп/картинок.
if ($dry === 1) {
  respond_json([
    'ok' => true,
    'mode' => 'dry',
    'day_count' => $dayCount,
    'day_no' => $day,
    'day_date' => $dayDate,
    'days' => $days,
    'final_option' => ['value'=>999,'label'=>'ИТОГ'],
  ]);
}

$matchesByGroupDay = load_group_day_matches($pdo, $stageId, $category, $variant, $dayDate);
$playoffMatchesDay = load_playoff_day_matches($pdo, $stageId, $category, $variant, $dayDate);

if (!$isFinalMode && (!$matchesByGroupDay || !is_array($matchesByGroupDay) || count($matchesByGroupDay) === 0)
    && (!$playoffMatchesDay || !is_array($playoffMatchesDay) || count($playoffMatchesDay) === 0)) {
  respond_json([
    'ok' => true,
    'mode' => 'playoff_stub',
    'day_count' => $dayCount,
    'day_no' => $day,
    'day_date' => $dayDate,
    'images' => [],
    'post_text' => '',
    'stub' => [
      'title' => 'Плей-офф',
      'message' => 'Картинки скоро будут.',
    ],
  ]);
}

if (!$isFinalMode && (!$matchesByGroupDay || !is_array($matchesByGroupDay) || count($matchesByGroupDay) === 0)
    && $playoffMatchesDay && is_array($playoffMatchesDay) && count($playoffMatchesDay) > 0) {

  $baseDir = dirname(__DIR__, 3) . '/_private/tournament/published';
  if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    respond_json(['ok'=>false,'error'=>'cannot create published dir'], 500);
  }

  $sid = date('dmY_His') . '_' . bin2hex(random_bytes(3));
  $dir = $baseDir . '/' . $sid;
  if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
    respond_json(['ok'=>false,'error'=>'cannot create snapshot dir'], 500);
  }

  $generatedAt = date('d-m-Y H:i');
  $meta = [
    'tournament_name' => $tournamentName,
    'stage_name' => $stageName,
    'category' => $category,
    'variant' => $variant,
    'day' => $day,
    'day_date' => $dayDate,
    'generated_at' => $generatedAt,
    'season_text' => $seasonText,
  ];

  $fname = 'playoff.png';
  $out = $dir . '/' . $fname;
  try {
    render_playoff_image_imagick($meta, $playoffMatchesDay, $out);
  } catch (Throwable $e) {
    respond_json(['ok'=>false, 'error'=>'render failed: ' . $e->getMessage()], 500);
  }

  $url = '/api/tournament/publish_image.php?sid=' . rawurlencode($sid) . '&file=' . rawurlencode($fname);
  $snapshot = [
    'sid'=>$sid,
    'stage_id'=>$stageId,
    'category'=>$category,
    'variant'=>$variant,
    'day'=>$day,
    'day_date'=>$dayDate,
    'tournament_name'=>$tournamentName,
    'stage_name'=>$stageName,
    'generated_at'=>$generatedAt,
    'show_results'=>$showResults,
    'season_text'=>$seasonText,
    'groups'=>[[
      'group_code'=>'P',
      'file'=>$fname,
      'used_manual'=>false,
      'hard_tie'=>false,
      'matches'=>$playoffMatchesDay,
      'title'=>'Плей-офф',
      'kind'=>'playoff',
    ]],
  ];
  file_put_contents($dir . '/snapshot.json', json_encode($snapshot, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

  $lines = [];
  $lines[] = 'Плей-офф — ' . $category;
  $lines[] = 'День ' . $day . ($dayDate ? (' (' . $dayDate . ')') : '');
  $lines[] = '';
  foreach ($playoffMatchesDay as $mm) {
    $lines[] = fmt_match_line($mm);
  }
  $lines[] = '';
  $lines[] = '#lazacup #плейофф #результаты #футболдети';
  $postText = trim(implode("\n", $lines));

  respond_json([
    'ok'=>true,
    'mode'=>'playoff',
    'day_count'=>$dayCount,
    'day_no'=>$day,
    'day_date'=>$dayDate,
    'sid'=>$sid,
    'images'=>[[
      'group'=>'P',
      'file'=>$fname,
      'url'=>$url,
      'used_manual'=>false,
      'hard_tie'=>false,
      'title'=>'Плей-офф',
      'kind'=>'playoff',
    ]],
    'post_text'=>$postText,
    'show_results'=>$showResults,
    'season_text'=>$seasonText,
  ]);
}

// получаем группы
$qg = $pdo->prepare(
  "SELECT ge.group_code,
          ge.pos,
          ge.manual_place,
          s.id AS squad_id,
          s.name AS squad_name,
          c.city AS club_city,
          c.logo_path AS logo_path
   FROM group_entries ge
   JOIN squads s ON s.id=ge.squad_id
   JOIN clubs c ON c.id=s.club_id
   WHERE ge.stage_id=:sid AND ge.category=:cat AND ge.variant=:v
   ORDER BY ge.group_code ASC, ge.pos ASC"
);
$qg->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
$rows = $qg->fetchAll();

$groups = [];
foreach ($rows as $r) {
  $g = (string)($r['group_code'] ?? '');
  if ($g === '') continue;
  if (!isset($groups[$g])) $groups[$g] = [];
  $groups[$g][] = [
    'id' => (int)$r['squad_id'],
    'name' => (string)$r['squad_name'],
    'city' => format_city((string)($r['club_city'] ?? '')),
    'logo_path' => (string)($r['logo_path'] ?? ''),
    'pos' => (int)$r['pos'],
    'manual_place' => ($r['manual_place'] === null) ? null : (int)$r['manual_place'],
  ];
}

if (!$groups) {
  respond_json(['ok'=>false,'error'=>'groups not found (group_entries empty)'], 404);
}

// матчи (для подсчёта очков/мячей)
$qm = $pdo->prepare(
  "SELECT m.id,
          m.group_code,
          m.home_squad_id,
          m.away_squad_id,
          r.home_goals AS home_goals,
          r.away_goals AS away_goals
   FROM matches m
   LEFT JOIN results r ON r.match_id = m.id
   WHERE m.stage_id=:sid AND m.category=:cat AND m.variant=:v
   ORDER BY m.group_code ASC, m.id ASC"
);

$qm->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
$matches = $qm->fetchAll();

$byPair = [];
foreach ($matches as $m) {
  $a = (int)$m['home_squad_id'];
  $b = (int)$m['away_squad_id'];
  $byPair[pair_key($a, $b)] = [
    'id' => (int)$m['id'],
    'home_squad_id' => $a,
    'away_squad_id' => $b,
    'home_goals' => $m['home_goals'],
    'away_goals' => $m['away_goals'],
    'group_code' => (string)$m['group_code'],
  ];
}

if ($isFinalMode) {
  $places = compute_playoff_places($pdo, $stageId, $category, $variant);
  $fallbackPlaces = compute_fallback_places_from_groups($groups, $byPair);

  if ($places) {
    $places = merge_places_with_fallback($places, $fallbackPlaces);
  } else {
    $places = $fallbackPlaces;
  }

  if (!$places) {
    respond_json(['ok'=>false,'error'=>'нет данных для ИТОГА'], 404);
  }

  $baseDir = dirname(__DIR__, 3) . '/_private/tournament/published';
  if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    respond_json(['ok'=>false,'error'=>'cannot create published dir'], 500);
  }

  $sid = date('dmY_His') . '_' . bin2hex(random_bytes(3));
  $dir = $baseDir . '/' . $sid;
  if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
    respond_json(['ok'=>false,'error'=>'cannot create snapshot dir'], 500);
  }

  $generatedAt = date('d-m-Y H:i');
  $meta = [
    'tournament_name' => $tournamentName,
    'stage_name' => $stageName,
    'category' => $category,
    'variant' => $variant,
    'generated_at' => $generatedAt,
    'season_text' => $seasonText,
  ];

  $fname = 'final.png';
  $out = $dir . '/' . $fname;

  try {
    render_final_places_image_imagick($meta, $places, $out);
  } catch (Throwable $e) {
    respond_json(['ok'=>false, 'error'=>'render failed: ' . $e->getMessage()], 500);
  }

  $url = '/api/tournament/publish_image.php?sid=' . rawurlencode($sid) . '&file=' . rawurlencode($fname);

  $snapshot = [
    'sid'=>$sid,
    'stage_id'=>$stageId,
    'category'=>$category,
    'variant'=>$variant,
    'day'=>999,
    'day_date'=>'',
    'tournament_name'=>$tournamentName,
    'stage_name'=>$stageName,
    'generated_at'=>$generatedAt,
    'show_results'=>$showResults,
    'season_text'=>$seasonText,
    'groups'=>[[
      'group_code'=>'FINAL',
      'file'=>$fname,
      'used_manual'=>false,
      'hard_tie'=>false,
      'title'=>'Итог',
      'kind'=>'final',
      'places'=>$places,
    ]],
  ];
  file_put_contents($dir . '/snapshot.json', json_encode($snapshot, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

  $lines = [];
  $lines[] = 'Итоговые места — ' . $category;
  $lines[] = '';
  foreach ($places as $place => $team) {
    $name = trim((string)($team['name'] ?? ''));
    $city = trim((string)($team['city'] ?? ''));
    $lines[] = $place . '. ' . $name . ($city !== '' ? (' • ' . $city) : '');
  }
  $lines[] = '';
  $lines[] = '#lazacup #итоги #футболдети';
  $postText = trim(implode("\n", $lines));

  respond_json([
    'ok'=>true,
    'mode'=>'final',
    'day_count'=>$dayCount,
    'day_no'=>999,
    'day_date'=>'',
    'sid'=>$sid,
    'images'=>[[
      'group'=>'FINAL',
      'file'=>$fname,
      'url'=>$url,
      'used_manual'=>false,
      'hard_tie'=>false,
      'title'=>'Итог',
      'kind'=>'final',
    ]],
    'post_text'=>$postText,
    'show_results'=>$showResults,
    'season_text'=>$seasonText,
  ]);
}

// ---- render & store ----
$baseDir = dirname(__DIR__, 3) . '/_private/tournament/published';
if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
  respond_json(['ok'=>false,'error'=>'cannot create published dir'], 500);
}

$sid = date('dmY_His') . '_' . bin2hex(random_bytes(3));
$dir = $baseDir . '/' . $sid;
if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
  respond_json(['ok'=>false,'error'=>'cannot create snapshot dir'], 500);
}

$generatedAt = date('d-m-Y H:i');
$images = [];
$snapshotGroups = [];

$groupCodes = array_keys($groups);
sort($groupCodes, SORT_STRING);

foreach ($groupCodes as $g) {
  $baseTeams = $groups[$g];
  // сортировка + расчёт статистики
  $sorted = sort_group($baseTeams, $byPair);
  $teams = $sorted['teams'];
  $standings = $sorted['standings'];

  $groupMatches = $matchesByGroupDay[$g] ?? [];

  $meta = [
    'tournament_name' => $tournamentName,
    'stage_name' => $stageName,
    'category' => $category,
    'variant' => $variant,
    'day' => $day,
    'day_date' => $dayDate,
    'group_code' => $g,
    'used_manual' => (bool)$sorted['usedManual'],
    'hard_tie' => (bool)$sorted['hardTie'],
    'generated_at' => $generatedAt,
    'matches' => $groupMatches,
    'show_results' => $showResults,
    'season_text' => $seasonText,
  ];

  $fname = 'group_' . safe_slug($g, 'g') . '.png';
  $out = $dir . '/' . $fname;

  // рендерим (imagick)
  try {
    render_group_image_imagick($meta, $teams, $standings, $out);
  } catch (Throwable $e) {
    respond_json(['ok'=>false, 'error'=>'render failed: ' . $e->getMessage()], 500);
  }

  $url = '/api/tournament/publish_image.php?sid=' . rawurlencode($sid) . '&file=' . rawurlencode($fname);
  $images[] = ['group'=>$g, 'file'=>$fname, 'url'=>$url, 'used_manual'=>$meta['used_manual'], 'hard_tie'=>$meta['hard_tie']];

  $snapshotGroups[] = [
    'group_code'=>$g,
    'file'=>$fname,
    'used_manual'=>$meta['used_manual'],
    'hard_tie'=>$meta['hard_tie'],
    'teams'=>$teams,
    'standings'=>$standings,
    'matches'=>$groupMatches,
  ];
}

$snapshot = [
  'sid'=>$sid,
  'stage_id'=>$stageId,
  'category'=>$category,
  'variant'=>$variant,
  'day'=>$day,
  'day_date'=>$dayDate,
  'tournament_name'=>$tournamentName,
  'stage_name'=>$stageName,
  'generated_at'=>$generatedAt,
  'show_results'=>$showResults,
  'season_text'=>$seasonText,
  'groups'=>$snapshotGroups,
];
file_put_contents($dir . '/snapshot.json', json_encode($snapshot, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

// пост-текст (черновик)
$lines = [];
if ($showResults) {
  $lines[] = "Групповой этап — {$category}";
  $lines[] = "День {$day}" . ($dayDate ? " ({$dayDate})" : "");
  $lines[] = "результаты за этот игровой день";
  $lines[] = "";

  foreach ($groupCodes as $g) {
    $lines[] = "Группа {$g}";
    $gm = $matchesByGroupDay[$g] ?? [];
    if (!$gm) {
      $lines[] = "— матчей нет";
    } else {
      foreach ($gm as $mm) {
        $lines[] = fmt_match_line($mm);
      }
    }
    $lines[] = "";
  }

  $lines[] = "#lazacup #результаты #футболдети";
} else {
  $lines[] = "Турнирная таблица — {$category}";
  $lines[] = "Групповой этап" . ($dayDate ? " · {$dayDate}" : "");
  $lines[] = "";
  foreach ($groupCodes as $g) {
    $lines[] = "Группа {$g}";
  }
  $lines[] = "";
  $lines[] = "#lazacup #таблица #футболдети";
}
$postText = trim(implode("
", $lines));

respond_json([
  'ok'=>true,
  'mode'=>'group',
  'day_count'=>$dayCount,
  'day_no'=>$day,
  'day_date'=>$dayDate,
  'sid'=>$sid,
  'images'=>$images,
  'post_text'=>$postText,
  'show_results'=>$showResults,
  'season_text'=>$seasonText,
]);

} catch (Throwable $e) {
  if (function_exists('log_line')) {
    log_line('publish_preview fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  }
  respond_json([
    'ok' => false,
    'error' => 'Server error: ' . $e->getMessage(),
  ], 500);
}
