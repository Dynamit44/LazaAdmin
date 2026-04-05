<?php
declare(strict_types=1);

// Generate VK-ready preview image(s) + post text for schedule (from saved schedule_items)
// POST JSON: { stage_id, category?, variant?, day_date?, phase? }
// Returns: { ok:true, sid, images:[{file,url}], post_text }

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('operator','organizer');

$pdo = db();
ensure_schedule_items_table($pdo);
ensure_stage_days_table($pdo);
ensure_stages_transition_minutes($pdo);
ensure_schedule_stage_events_table($pdo);

function norm_time(string $t): string {
  $t = trim($t);
  if ($t === '') return '';
  if (preg_match('~^\d{2}:\d{2}$~', $t)) return $t . ':00';
  if (preg_match('~^\d{2}:\d{2}:\d{2}$~', $t)) return $t;
  return '';
}

function add_minutes(string $hhmmss, int $minutes): string {
  $hhmmss = norm_time($hhmmss);
  if ($hhmmss === '') return '';
  [$h,$m,$s] = array_map('intval', explode(':', $hhmmss));
  $sec = $h * 3600 + $m * 60 + $s + $minutes * 60;
  $sec %= (24 * 3600);
  if ($sec < 0) $sec += (24 * 3600);
  $hh = intdiv($sec, 3600);
  $mm = intdiv($sec % 3600, 60);
  return sprintf('%02d:%02d', $hh, $mm);
}

function pick_current_stage_id(PDO $pdo): int {
  $t = (int)($pdo->query("SELECT id FROM tournaments WHERE is_current=1 ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
  if ($t <= 0) return 0;
  $st = $pdo->prepare("SELECT id FROM stages WHERE tournament_id=:tid AND is_current=1 ORDER BY id DESC LIMIT 1");
  $st->execute([':tid' => $t]);
  return (int)($st->fetchColumn() ?: 0);
}

function matches_has_column(PDO $pdo, string $col): bool {
  static $cols = null;
  if ($cols !== null) return isset($cols[$col]);
  $cols = [];
  try {
    foreach ($pdo->query("SHOW COLUMNS FROM matches") as $r) {
      $cols[(string)$r['Field']] = true;
    }
  } catch (Throwable $e) {
    $cols[$col] = true;
  }
  return isset($cols[$col]);
}

function fmt_date_short(string $ymd): string {
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $ymd)) return $ymd;
  $dt = DateTime::createFromFormat('Y-m-d', $ymd);
  return $dt ? $dt->format('d.m.Y') : $ymd;
}

function find_font_path(): string {
  $candidates = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
  ];
  foreach ($candidates as $p) {
    if (is_file($p)) return $p;
  }
  return '';
}

function make_sid(): string {
  return bin2hex(random_bytes(8));
}

function published_dir(string $sid): string {
  $base = dirname(__DIR__, 3) . '/_private/tournament/published';
  $dir = rtrim($base, '/\\') . '/' . $sid;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}

function image_url(string $sid, string $file): string {
  return '/api/tournament/publish_image.php?sid=' . rawurlencode($sid) . '&file=' . rawurlencode($file);
}

function wrap_text(Imagick $img, ImagickDraw $draw, string $text, int $maxW): array {
  $text = trim($text);
  if ($text === '') return [''];

  $words = preg_split('~\s+~u', $text) ?: [];
  $lines = [];
  $cur = '';

  foreach ($words as $w) {
    $try = ($cur === '') ? $w : ($cur . ' ' . $w);
    $m = $img->queryFontMetrics($draw, $try);
    $wpx = (int)round($m['textWidth'] ?? 0);
    if ($wpx <= $maxW) {
      $cur = $try;
      continue;
    }
    if ($cur === '') {
      $lines[] = $try;
      $cur = '';
      continue;
    }
    $lines[] = $cur;
    $cur = $w;
  }

  if ($cur !== '') $lines[] = $cur;
  return $lines ?: [''];
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

function render_schedule_pages(array $meta, array $days, string $sid, string $outDir): array {
  if (!class_exists('Imagick')) throw new RuntimeException('Imagick не доступен');

  $W = 1080;
  $H = 1350;
  $font = find_font_path();

  $new_page = function(string $headText) use ($W,$H,$font,$meta): array {
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
    $mist->setFillColor('rgba(255,255,255,0.18)');
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

    $seasonText = trim((string)($meta['season_text'] ?? '26 Весна'));
    if ($seasonText === '') $seasonText = '26 Весна';
    draw_centered_fit_text($img, "LAZACUP'" . $seasonText, $titleX + (int)($titleW/2), 92, $titleW - 120, 44, '#ffffff', $font, 24);

    $stageName = trim((string)($meta['stage_name'] ?? ''));
    $dayDate = trim((string)($meta['day_date'] ?? ''));
    $rightX = $W - 54;
    if ($stageName !== '') draw_right_text($img, $stageName, $rightX, 76, 34, '#173f69', $font);
    if ($dayDate !== '') {
      $dayTxt = preg_match('~^\d{4}-\d{2}-\d{2}$~', $dayDate) ? date('d-m-Y', strtotime($dayDate)) : $dayDate;
      draw_right_text($img, $dayTxt, $rightX, 112, 24, '#173f69', $font);
    }

    $catPlatePath = design_asset_abs('/uploads/tournament/design/category_plate.png');
    $catW = 380;
    $catX = (int)(($W - $catW) / 2);
    $catY = 156;
    if ($catPlatePath) {
      $cp = new Imagick($catPlatePath);
      $cp->setImageFormat('png');
      $cp->resizeImage($catW, 64, Imagick::FILTER_LANCZOS, 1, false);
      $img->compositeImage($cp, Imagick::COMPOSITE_OVER, $catX, $catY);
      $cp->clear();
      $cp->destroy();
    }

    $catCaption = trim((string)($meta['category_label'] ?? ''));
    if ($catCaption === '') $catCaption = 'Расписание';
    draw_centered_fit_text($img, $catCaption, (int)($W/2), $catY + 38, $catW - 40, 24, '#ffffff', $font, 16);

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
    $y = $cardY + 46;

$tableW = $cardW - 52;
$colTimeW = 120;
$colFieldW = 70;

return [$img, $cardX, $cardY, $cardW, $cardH, $tableX, $tableW, $colTimeW, $colFieldW, $y];
  };

  $save_page = function(Imagick $img, int $pageNum) use ($outDir, $sid): array {
    $file = 'schedule_' . $pageNum . '.png';
    $path = rtrim($outDir, '/\\') . '/' . $file;
    $img->writeImage($path);
    return ['file' => $file, 'url' => image_url($sid, $file)];
  };

  $page = 1;
  [$img, $cardX, $cardY, $cardW, $cardH, $tableX, $tableW, $colTimeW, $colFieldW, $y] = $new_page('Матчи');
  $maxY = $cardY + $cardH - 40;
  $colMatchW = $tableW - ($colTimeW + $colFieldW);
  if ($colMatchW < 320) $colMatchW = 320;

  //$sep_line = function(int $yy) use (&$img, $tableX, $tableW): void {
  //  $d = new ImagickDraw();
  //  $d->setStrokeColor('rgba(23,63,105,0.10)');
  //  $d->setStrokeWidth(1);
  //  $d->line($tableX, $yy, $tableX + $tableW, $yy);
  //  $img->drawImage($d);
  //};

  $draw_day_header = function(string $dayText) use (&$img, $font, $tableX, $tableW, &$y, $sep_line): void {
    $y += 10;
    $dT = new ImagickDraw();
    $dT->setTextAntialias(true);
    $dT->setFillColor('#173f69');
    $dT->setFontSize(28);
    if ($font) $dT->setFont($font);
    $img->annotateImage($dT, $tableX + 4, $y, 0, $dayText);
    $y += 16;
    //$sep_line($y);
    $y += 24;
  };

  $draw_headers = function() use (&$img, $font, &$y, $tableX, $tableW, $colTimeW, $colFieldW): void {
    $dHead = new ImagickDraw();
    $dHead->setTextAntialias(true);
    $dHead->setFillColor('#173f69');
    $dHead->setFontSize(24);
    if ($font) $dHead->setFont($font);

    $img->annotateImage($dHead, $tableX + 8, $y, 0, 'ВРЕМЯ');
    $img->annotateImage($dHead, $tableX + $colTimeW + 8, $y, 0, 'ПОЛЕ');
    $img->annotateImage($dHead, $tableX + $colTimeW + $colFieldW + 32, $y, 0, 'КОМАНДЫ');
    $y += 12;
    $gridHead = new ImagickDraw();
    $gridHead->setStrokeColor('rgba(23,63,105,0.16)');
    $gridHead->setStrokeWidth(1);
    $gridHead->line($tableX, $y, $tableX + $tableW, $y);
    $img->drawImage($gridHead);
    $y += 36;
  };

$singleDay = !empty($meta['day_date']);
if ($singleDay) {
  $draw_headers();
}

  $images = [];
  //$singleDay = !empty($meta['day_date']);

  foreach ($days as $d) {
    $dayDate = (string)($d['day_date'] ?? '');
    $items = (array)($d['items'] ?? []);
    if (!$items) continue;

    if (!$singleDay) {
      if ($y + 90 > $maxY) {
        $generatedAt = trim((string)($meta['generated_at'] ?? ''));
        lc_draw_footer($img, $W, $H, $font, $generatedAt, db());
        $images[] = $save_page($img, $page);
        $page++;
        [$img, $cardX, $cardY, $cardW, $cardH, $tableX, $tableW, $colTimeW, $colFieldW, $y] = $new_page('Матчи (продолжение)');
        $maxY = $cardY + $cardH - 40;
      }
      $draw_day_header(fmt_date_short($dayDate));
      $draw_headers();
    }

    foreach ($items as $it) {
      $time = (string)($it['time'] ?? '');
      $cat  = trim((string)($it['category'] ?? ''));
      $field = (string)($it['field'] ?? '');
      $home = (string)($it['home'] ?? '');
      $away = (string)($it['away'] ?? '');
      $kind = (string)($it['kind'] ?? 'match');

      if ($kind === 'event') {
        $match = $home;
      } else {
        $match = trim($home . ' — ' . $away);
        if (empty($meta['category']) && $cat !== '') {
          $match = trim($cat . ' • ' . $match);
        }
      }

      $dTime = new ImagickDraw();
      $dTime->setTextAntialias(true);
      $dTime->setFillColor('#173f69');
      $dTime->setFontSize(28);
      if ($font) $dTime->setFont($font);

      $dMeta = new ImagickDraw();
      $dMeta->setTextAntialias(true);
      $dMeta->setFillColor('rgba(23,63,105,0.78)');
      $dMeta->setFontSize(24);
      if ($font) $dMeta->setFont($font);

      $dMatch = new ImagickDraw();
      $dMatch->setTextAntialias(true);
      $dMatch->setFillColor('#173f69');
      $dMatch->setFontSize(24);
      if ($font) $dMatch->setFont($font);

      $lines = wrap_text($img, $dMatch, $match, $colMatchW - 8);
      if (count($lines) > 2) {
        $lines = array_slice($lines, 0, 2);
        $lines[count($lines)-1] = rtrim($lines[count($lines)-1]) . '…';
      }

      $needH = 54 + (count($lines) - 1) * 28;

      if ($y + $needH > $maxY) {
        $generatedAt = trim((string)($meta['generated_at'] ?? ''));
        lc_draw_footer($img, $W, $H, $font, $generatedAt, db());
        $images[] = $save_page($img, $page);
        $page++;
        [$img, $cardX, $cardY, $cardW, $cardH, $tableX, $tableW, $colTimeW, $colFieldW, $y] = $new_page('Матчи (продолжение)');
        $maxY = $cardY + $cardH - 40;
        if (!$singleDay) {
          $draw_day_header(fmt_date_short($dayDate));
        }
        $draw_headers();
      }

      $xTime = $tableX;
      $xField = $xTime + $colTimeW;
      $xMatch = $xField + $colFieldW + 32;

      $img->annotateImage($dTime, $xTime, $y, 0, $time);
      //$img->annotateImage($dMeta, $xField, $y, 0, $field);
$fieldTxt = (string)$field;
$fieldMetrics = $img->queryFontMetrics($dMeta, $fieldTxt);
$fieldTextW = (int)ceil($fieldMetrics['textWidth'] ?? 0);
$fieldXCentered = $xField + (int)(($colFieldW - $fieldTextW) / 2);

$img->annotateImage($dMeta, $fieldXCentered, $y, 0, $fieldTxt);

      $yy = $y;
      foreach ($lines as $ln) {
        $img->annotateImage($dMatch, $xMatch, $yy, 0, $ln);
        $yy += 28;
      }

      $y += $needH + 12;
      //$sep_line($y - 4);
      $y += 6;
    }
  }

  $generatedAt = trim((string)($meta['generated_at'] ?? ''));
  lc_draw_footer($img, $W, $H, $font, $generatedAt, db());

  $images[] = $save_page($img, $page);
  return $images;
}

try {
  $in = read_json_body();

  $stageId = (int)($in['stage_id'] ?? 0);
  if ($stageId <= 0) $stageId = pick_current_stage_id($pdo);
  if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required (or no current stage)'], 400);

  $category = trim((string)($in['category'] ?? ''));
  $variant  = (int)($in['variant'] ?? 0);
  $dayDate  = trim((string)($in['day_date'] ?? ''));
  $phase    = trim((string)($in['phase'] ?? '')); // group|playoff|all

  if ($dayDate !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $dayDate)) {
    respond_json(['ok'=>false,'error'=>'bad day_date'], 400);
  }

  if ($phase !== '' && $phase !== 'all' && $phase !== 'group' && $phase !== 'playoff') {
    respond_json(['ok'=>false,'error'=>'bad phase'], 400);
  }

  $st = $pdo->prepare("SELECT id, tournament_id, name, day_start, match_minutes, break_minutes, transition_minutes
                       FROM stages WHERE id=:id LIMIT 1");
  $st->execute([':id' => $stageId]);
  $stage = $st->fetch(PDO::FETCH_ASSOC);
  if (!$stage) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

  lc_require_tournament_not_archived($pdo, (int)($stage['tournament_id'] ?? 0));

  $qt = $pdo->prepare("SELECT name FROM tournaments WHERE id=:id LIMIT 1");
  $qt->execute([':id'=>(int)$stage['tournament_id']]);
  $tname = (string)($qt->fetchColumn() ?: '');

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
    $t = norm_time((string)($r['day_start'] ?? ''));
    if ($d !== '' && $t !== '') $dayStartByDate[$d] = $t;
  }

  $defaultDayStart = norm_time((string)($stage['day_start'] ?? ''));
  if ($defaultDayStart === '') $defaultDayStart = '09:00:00';


$role = is_array(current_user()) ? trim((string)(current_user()['role'] ?? '')) : '';
$viewMode = trim((string)($in['view_mode'] ?? ''));
$isOperatorView = ($role === 'operator' || $viewMode === 'operator');

// completion map for group stage by category+variant
$groupDoneMap = [];
try {
  if (matches_has_column($pdo, 'variant')) {
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
    $groupDoneMap[$cc . '|' . $vv] = ($totalCnt <= 0) ? true : ($playedCnt >= $totalCnt);
  }
} catch (Throwable $e) {
  $groupDoneMap = [];
}

function sp_event_type_title(string $type): string {
  switch (trim($type)) {
    case 'opening': return 'Открытие';
    case 'closing': return 'Закрытие';
    case 'break':   return 'Перерыв';
    case 'awarding': return 'Награждение';
    default: return 'Событие';
  }
}

function sp_event_display_title(string $type, string $title): string {
  $typeTitle = sp_event_type_title($type);
  $title = trim($title);
  if ($title === '') return $typeTitle;

  $lower = static function(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
  };

  if ($lower($title) === $lower($typeTitle)) return $title;
  return $typeTitle . ' • ' . $title;
}

function sp_human_playoff_ref(string $label, string $ref): string {
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
    $where .= " AND si.day_date = :dd";
    $params[':dd'] = $dayDate;
  }

  $hasVariant = matches_has_column($pdo, 'variant');
  if ($variant > 0 && $hasVariant) {
    $where .= " AND m.variant = :v";
    $params[':v'] = $variant;
  }

  if ($phase !== '' && $phase !== 'all') {
    $where .= " AND m.phase = :ph";
    $params[':ph'] = $phase;
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

  $qq = $pdo->prepare($sql);
  $qq->execute($params);
  $rows = $qq->fetchAll(PDO::FETCH_ASSOC);

  $daysMap = [];
  $dayFilterSql = '';
  $eventParams = [':sid' => $stageId];
  if ($dayDate !== '') {
    $dayFilterSql = ' AND event_date = :edd';
    $eventParams[':edd'] = $dayDate;
  }

  $qe = $pdo->prepare("
    SELECT event_date, time_from, time_to, event_type, title
    FROM schedule_stage_events
    WHERE stage_id = :sid AND is_active = 1{$dayFilterSql}
    ORDER BY event_date ASC, time_from ASC, id ASC
  " );
  $qe->execute($eventParams);
  $eventRows = $qe->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
  $date  = (string)($r['day_date'] ?? '');
  $slot  = (int)($r['slot_index'] ?? 0);
  $field = (string)($r['resource_code'] ?? '');
  $mid   = (int)($r['match_id'] ?? 0);
  if ($date === '' || $slot <= 0 || $field === '' || $mid <= 0) continue;

  $ds = $dayStartByDate[$date] ?? $defaultDayStart;
  $time = add_minutes($ds, ($slot - 1) * $slotMinutes);

  $home = trim((string)($r['home_name'] ?? ''));
  $away = trim((string)($r['away_name'] ?? ''));
  $hc   = trim((string)($r['home_city'] ?? ''));
  $ac   = trim((string)($r['away_city'] ?? ''));

  $homeLabel = trim((string)($r['home_label'] ?? ''));
  $awayLabel = trim((string)($r['away_label'] ?? ''));
  $homeRef   = trim((string)($r['home_ref'] ?? ''));
  $awayRef   = trim((string)($r['away_ref'] ?? ''));

  $cat  = trim((string)($r['category'] ?? ''));
  $var  = (int)($r['variant'] ?? 0);
  $ph   = trim((string)($r['phase'] ?? ''));
  $code = trim((string)($r['code'] ?? ''));

  $doneKey = $cat . '|' . $var;
  $groupsFinished = array_key_exists($doneKey, $groupDoneMap) ? (bool)$groupDoneMap[$doneKey] : true;
  $hidePlayoffTeams = ($isOperatorView && $ph === 'playoff' && !$groupsFinished);

  if ($hidePlayoffTeams) {
    $home = sp_human_playoff_ref($homeLabel, $homeRef);
    $away = sp_human_playoff_ref($awayLabel, $awayRef);
    $hc = '';
    $ac = '';
  } else {
    // If squads not set (typical for playoff placeholders), try resolve GE:* -> real squad name via group_entries
    if ($home === '' && $homeRef !== '') {
      if (preg_match('~^GE:([A-Z]):(\d+)$~', $homeRef, $mm)) {
        $g = $mm[1];
        $pos = (int)$mm[2];
        $k = $cat . '|' . $var . '|' . $g . '|' . $pos;
        if (isset($geMap[$k]) && ($geMap[$k]['name'] ?? '') !== '') {
          $home = (string)$geMap[$k]['name'];
          $hc = (string)($geMap[$k]['city'] ?? '');
          if ($homeLabel !== '') $home .= ' [' . $homeLabel . ']';
        }
      }
    }

    if ($away === '' && $awayRef !== '') {
      if (preg_match('~^GE:([A-Z]):(\d+)$~', $awayRef, $mm)) {
        $g = $mm[1];
        $pos = (int)$mm[2];
        $k = $cat . '|' . $var . '|' . $g . '|' . $pos;
        if (isset($geMap[$k]) && ($geMap[$k]['name'] ?? '') !== '') {
          $away = (string)$geMap[$k]['name'];
          $ac = (string)($geMap[$k]['city'] ?? '');
          if ($awayLabel !== '') $away .= ' [' . $awayLabel . ']';
        }
      }
    }

    // Fallback: use human-readable label / ref
    if ($home === '') {
      $home = sp_human_playoff_ref($homeLabel, $homeRef);
    }
    if ($away === '') {
      $away = sp_human_playoff_ref($awayLabel, $awayRef);
    }

    if ($home !== '' && $hc !== '') $home .= ' (' . $hc . ')';
    if ($away !== '' && $ac !== '') $away .= ' (' . $ac . ')';
  }

  if (!isset($daysMap[$date])) {
    $daysMap[$date] = ['day_date' => $date, 'items' => []];
  }

  $daysMap[$date]['items'][] = [
    'kind' => 'match',
    'sort_time' => $time,
    'sort_rank' => 1,
    'time' => $time,
    'category' => $cat,
    'variant' => $var,
    'field' => $field,
    'home' => $home,
    'away' => $away,
    'phase' => $ph,
    'code' => $code,
    'round_no' => (int)($r['round_no'] ?? 0),
  ];
}

foreach ($eventRows as $ev) {
  $date = trim((string)($ev['event_date'] ?? ''));
  if ($date === '') continue;

  $from = substr(trim((string)($ev['time_from'] ?? '')), 0, 5);
  $to   = substr(trim((string)($ev['time_to'] ?? '')), 0, 5);

  $timeStart = $from;
  if ($timeStart === '' && $to !== '') {
    $timeStart = $to;
  }

  $timeRange = '';
  if ($from !== '' && $to !== '') {
    $timeRange = $from . '–' . $to;
  } elseif ($from !== '') {
    $timeRange = $from;
  } elseif ($to !== '') {
    $timeRange = $to;
  }

  $eventLabel = sp_event_display_title((string)($ev['event_type'] ?? 'custom'), (string)($ev['title'] ?? ''));
  $eventText = $eventLabel;
  if ($timeRange !== '') {
    $eventText .= ' (' . $timeRange . ')';
  }

  if (!isset($daysMap[$date])) {
    $daysMap[$date] = ['day_date' => $date, 'items' => []];
  }

  $daysMap[$date]['items'][] = [
    'kind' => 'event',
    'sort_time' => ($timeStart !== '' ? $timeStart : '99:99'),
    'sort_rank' => 0,
    'time' => $timeStart,
    'time_range' => $timeRange,
    'category' => '',
    'variant' => 0,
    'field' => 'Все поля',
    'home' => $eventText,
    'away' => '',
    'phase' => 'event',
    'code' => '',
    'round_no' => 0,
    'event_type' => (string)($ev['event_type'] ?? 'custom'),
    'title' => trim((string)($ev['title'] ?? '')),
  ];
}

ksort($daysMap);
foreach ($daysMap as &$dayRow) {
  if (empty($dayRow['items']) || !is_array($dayRow['items'])) continue;
  usort($dayRow['items'], static function(array $a, array $b): int {
    $ta = (string)($a['sort_time'] ?? '99:99');
    $tb = (string)($b['sort_time'] ?? '99:99');
    if ($ta !== $tb) return strcmp($ta, $tb);

    $ra = (int)($a['sort_rank'] ?? 99);
    $rb = (int)($b['sort_rank'] ?? 99);
    if ($ra !== $rb) return $ra <=> $rb;

    $fa = (string)($a['field'] ?? '');
    $fb = (string)($b['field'] ?? '');
    return strcmp($fa, $fb);
  });
}
unset($dayRow);

  $days = array_values($daysMap);
  if (!$days) respond_json(['ok'=>false,'error'=>'schedule is empty (nothing to publish)'], 400);

  // post text
  $lines = [];
  $lines[] = 'Расписание — ' . (($category !== '') ? $category : 'все категории');
  if ($phase === 'group') $lines[] = 'Фаза: группы';
  if ($phase === 'playoff') $lines[] = 'Фаза: плей-офф';
  $lines[] = '';

  foreach ($days as $d) {
    $dd = (string)($d['day_date'] ?? '');
    $items = (array)($d['items'] ?? []);
    if (!$items) continue;

    $lines[] = fmt_date_short($dd);

    foreach ($items as $it) {
      $t = (string)($it['time'] ?? '');
      $f = (string)($it['field'] ?? '');
      $home = (string)($it['home'] ?? '');
      $away = (string)($it['away'] ?? '');
      $cat = (string)($it['category'] ?? '');
      $kind = (string)($it['kind'] ?? 'match');

      if ($kind === 'event') {
        $lines[] = trim($t . ' ' . $f . ' ' . $home);
      } else {
        $lines[] = ($category === '')
          ? trim($t . ' ' . $cat . ' ' . $f . ' ' . $home . ' — ' . $away)
          : trim($t . ' ' . $f . ' ' . $home . ' — ' . $away);
      }
    }

    $lines[] = '';
  }

  $lines[] = '#lazacup #расписание';
  $postText = rtrim(implode("\n", $lines));

  $catLabel = ($category !== '') ? $category : 'все категории';

  $sid = make_sid();
  $dir = published_dir($sid);

  $meta = [
    'tournament_name' => $tname,
    'stage_name' => (string)($stage['name'] ?? ''),
    'stage_id' => $stageId,
    'category' => $category,
    'variant' => ($variant > 0 && $hasVariant) ? $variant : 0,
    'day_date' => $dayDate,
    'category_label' => $catLabel,
    'day_label' => ($dayDate !== '' ? ('День — ' . fmt_date_short($dayDate)) : 'Дни — все'),
    'slot_minutes' => $slotMinutes,
    'created_at' => date('c'),
    'generated_at' => date('d-m-Y H:i'),
    'season_text' => trim((string)($in['season_text'] ?? '26 Весна')),
  ];

  $images = render_schedule_pages($meta, $days, $sid, $dir);

  @file_put_contents($dir . '/snapshot.json', json_encode([
    'meta' => $meta,
    'days' => $days,
    'images' => $images,
    'post_text' => $postText,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

  respond_json([
    'ok' => true,
    'sid' => $sid,
    'images' => $images,
    'post_text' => $postText,
    'meta' => $meta,
  ]);

} catch (Throwable $e) {
  log_line('schedule_preview: ' . $e->getMessage());
  respond_json(['ok'=>false,'error'=>$e->getMessage()], 500);
}
