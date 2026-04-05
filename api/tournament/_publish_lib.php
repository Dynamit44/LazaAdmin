<?php
declare(strict_types=1);

/**
 * Общая библиотека публикации в VK для турнира.
 * Используется publish_schedule.php и publish_table.php
 */

require_once __DIR__ . '/_respond.php'; // respond_json, read_json_body, log_line

// --------- SID / SNAPSHOT ---------

function pub_validate_sid(string $sid): string {
  $sid = trim($sid);
  if ($sid === '' || !preg_match('~^[a-zA-Z0-9._-]{3,64}$~', $sid)) {
    throw new RuntimeException('bad sid');
  }
  return $sid;
}

function pub_paths_by_sid(string $sid): array {
  $base = dirname(__DIR__, 3) . '/_private/tournament/published';
  $dir  = $base . '/' . $sid;

  $realBase = realpath($base);
  $realDir  = realpath($dir);

  if (!$realBase || !$realDir || strncmp($realDir, $realBase, strlen($realBase)) !== 0) {
    throw new RuntimeException('snapshot not found');
  }

  return [$realBase, $realDir];
}

function pub_load_snapshot(string $dir): array {
  $p = $dir . '/snapshot.json';
  if (!is_file($p)) throw new RuntimeException('snapshot.json not found');

  $j = json_decode((string)file_get_contents($p), true);
  if (!is_array($j)) throw new RuntimeException('snapshot.json invalid');
  return $j;
}

function pub_collect_files(string $dir, array $snapshot, array $filesFromReq): array {
  $files = [];

  if ($filesFromReq) {
    $files = $filesFromReq;
  } else {
    if (!empty($snapshot['groups']) && is_array($snapshot['groups'])) {
      foreach ($snapshot['groups'] as $g) {
        if (is_array($g) && !empty($g['file'])) $files[] = (string)$g['file'];
      }
    }
  }

  $files = array_values(array_unique(array_filter(array_map('strval', $files), fn($x)=>trim($x) !== '')));
  if (!$files) throw new RuntimeException('нет картинок для публикации');

  $paths = [];
  foreach ($files as $f) {
    if (!preg_match('~^[a-zA-Z0-9._-]{3,128}$~', $f)) {
      throw new RuntimeException('bad file: ' . $f);
    }
    $p = $dir . '/' . $f;
    if (!is_file($p)) throw new RuntimeException('file not found: ' . $f);
    $paths[] = $p;
  }

  return [$files, $paths];
}

// --------- VK SECRETS / API ---------

function vk_load_secrets(): array {
  $p = dirname(__DIR__, 3) . '/_private/vk_secrets.php';
  if (!is_file($p)) return [];
  $s = require $p;
  return is_array($s) ? $s : [];
}

function vkid_tokens_read(array $vkCfg): array {
  $vkid = $vkCfg['vkid'] ?? [];
  $file = (string)($vkid['tokens_file'] ?? '');
  if ($file === '' || !is_file($file)) return [];
  $j = json_decode((string)file_get_contents($file), true);
  return is_array($j) ? $j : [];
}

function vkid_tokens_write(array $vkCfg, array $tokens): void {
  $vkid = $vkCfg['vkid'] ?? [];
  $file = (string)($vkid['tokens_file'] ?? '');
  if ($file === '') throw new RuntimeException('VKID tokens_file not configured');
  $dir = dirname($file);
  if (!is_dir($dir)) throw new RuntimeException('VKID tokens dir not found');

  $json = json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('VKID tokens json_encode failed');

  if (file_put_contents($file, $json, LOCK_EX) === false) {
    throw new RuntimeException('VKID tokens write failed');
  }
}

function vkid_refresh_if_needed(array $vkCfg): string {
  if (!function_exists('curl_init')) throw new RuntimeException('curl extension is required');

  $vkid = $vkCfg['vkid'] ?? null;
  if (!is_array($vkid)) throw new RuntimeException('VKID not configured in vk_secrets.php');

  $clientId = (string)($vkid['client_id'] ?? '');
  $deviceId = (string)($vkid['device_id'] ?? '');
  if ($clientId === '') throw new RuntimeException('VKID client_id missing');

  // lock, чтобы не словить гонку rotation (иначе invalid_grant)
  $lockPath = dirname(__DIR__, 3) . '/_private/vkid_refresh.lock';
  $lockFp = fopen($lockPath, 'c+');
  if (!$lockFp) throw new RuntimeException('cannot open vkid lock');
  if (!flock($lockFp, LOCK_EX)) { fclose($lockFp); throw new RuntimeException('cannot lock vkid'); }

  // перечитываем токены уже под локом
  $t = vkid_tokens_read($vkCfg);

  $access = (string)($t['access_token'] ?? '');
  $refresh = (string)($t['refresh_token'] ?? '');
  $expiresAt = (int)($t['expires_at'] ?? 0);

  // если access ещё живой > 2 минут — возвращаем
  if ($access !== '' && $expiresAt > time() + 120) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    return $access;
  }

  if ($refresh === '') {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    throw new RuntimeException('VKID refresh_token missing (vkid_tokens.json)');
  }

  $post = [
    'grant_type' => 'refresh_token',
    'client_id' => $clientId,
    'refresh_token' => $refresh,
  ];
  if ($deviceId !== '') $post['device_id'] = $deviceId;

  $ch = curl_init('https://id.vk.ru/oauth2/auth');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($post),
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);

  $raw = curl_exec($ch);
  if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    flock($lockFp, LOCK_UN); fclose($lockFp);
    throw new RuntimeException('VKID refresh curl error: ' . $err);
  }
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $j = json_decode($raw, true);
  if (!is_array($j)) {
    flock($lockFp, LOCK_UN); fclose($lockFp);
    throw new RuntimeException('VKID refresh bad JSON (HTTP ' . $http . ')');
  }
  if (!empty($j['error'])) {
    $msg = is_string($j['error']) ? $j['error'] : 'VKID refresh error';
    flock($lockFp, LOCK_UN); fclose($lockFp);
    throw new RuntimeException($msg);
  }

  $newAccess  = (string)($j['access_token'] ?? '');
  $newRefresh = (string)($j['refresh_token'] ?? '');
  $expiresIn  = (int)($j['expires_in'] ?? 0);

  if ($newAccess === '' || $newRefresh === '' || $expiresIn <= 0) {
    flock($lockFp, LOCK_UN); fclose($lockFp);
    throw new RuntimeException('VKID refresh response missing fields');
  }

  $newTokens = [
    'access_token' => $newAccess,
    'refresh_token' => $newRefresh,
    'expires_at' => time() + $expiresIn,
  ];

  vkid_tokens_write($vkCfg, $newTokens);

  flock($lockFp, LOCK_UN);
  fclose($lockFp);

  return $newAccess;
}

function vk_get_token_for_publish(array $vkCfg): string {
  // приоритет: VKID (auto refresh), fallback: старый token/group_token
  if (!empty($vkCfg['vkid'])) return vkid_refresh_if_needed($vkCfg);

  $token = (string)($vkCfg['token'] ?? ($vkCfg['group_token'] ?? ''));
  if ($token === '') throw new RuntimeException('VK token not configured (_private/vk_secrets.php)');
  return $token;
}

function vk_api(string $method, array $params, string $token, string $v): array {
  if (!function_exists('curl_init')) throw new RuntimeException('curl extension is required');

  $url = 'https://api.vk.com/method/' . $method;
  $params['access_token'] = $token;
  $params['v'] = $v;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($params),
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);

  $raw = curl_exec($ch);
  if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('VK curl error: ' . $err);
  }

  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $j = json_decode($raw, true);
  if (!is_array($j)) throw new RuntimeException('VK bad JSON (HTTP ' . $http . ')');

  if (isset($j['error'])) {
    $e = $j['error'];
    $code = (string)($e['error_code'] ?? '0');
    $msg  = (string)($e['error_msg'] ?? 'VK error');
    // важно: это сообщение поднимаем наверх единым образом
    throw new RuntimeException('VK ' . $code . ': ' . $msg);
  }

  return $j['response'] ?? $j;
}

function vk_upload_wall_photo(string $uploadUrl, string $filePath): array {
  if (!function_exists('curl_init')) throw new RuntimeException('curl extension is required');
  if (!is_file($filePath)) throw new RuntimeException('File not found: ' . basename($filePath));

  $ch = curl_init($uploadUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
      'photo' => new CURLFile($filePath, 'image/png', basename($filePath)),
    ],
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);

  $raw = curl_exec($ch);
  if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('VK upload curl error: ' . $err);
  }

  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $j = json_decode($raw, true);
  if (!is_array($j)) throw new RuntimeException('VK upload bad JSON (HTTP ' . $http . ')');

  if (!isset($j['server'], $j['photo'], $j['hash'])) {
    throw new RuntimeException('VK upload response missing fields');
  }

  return $j;
}

/**
 * Главная функция публикации в VK.
 * Возвращает мету: post_id, owner_id, post_url, attachments_count
 */
function pub_vk_post(array $paths, string $message, array $vkCfg): array {
  //$token = (string)($vkCfg['token'] ?? ($vkCfg['group_token'] ?? ''));
   $token = vk_get_token_for_publish($vkCfg);
  $groupId = (int)($vkCfg['group_id'] ?? 0);
  $ownerId = (int)($vkCfg['owner_id'] ?? 0);
  $apiV = (string)($vkCfg['api_version'] ?? '5.199');
  $domain = (string)($vkCfg['domain'] ?? '');

  if ($token === '') throw new RuntimeException('VK token not configured (_private/vk_secrets.php)');

  if (!$groupId && $ownerId) $groupId = abs($ownerId);
  if (!$groupId) throw new RuntimeException('VK group_id not configured (set group_id or owner_id in vk_secrets.php)');

  $ownerId = -abs($groupId); // на стену сообщества

  // 1) upload server
  $srv = vk_api('photos.getWallUploadServer', ['group_id' => $groupId], $token, $apiV);
  $uploadUrl = $srv['upload_url'] ?? '';
  if (!is_string($uploadUrl) || $uploadUrl === '') throw new RuntimeException('VK upload_url not found');

  // 2) upload + save each image
  $attachments = [];
  foreach ($paths as $p) {
    $up = vk_upload_wall_photo($uploadUrl, $p);

    $saved = vk_api('photos.saveWallPhoto', [
      'group_id' => $groupId,
      'photo' => $up['photo'],
      'server' => $up['server'],
      'hash' => $up['hash'],
    ], $token, $apiV);

    if (!is_array($saved) || !isset($saved[0]) || !is_array($saved[0])) {
      throw new RuntimeException('VK saveWallPhoto unexpected response');
    }

    $ph = $saved[0];
    $oid = (int)($ph['owner_id'] ?? 0);
    $pid = (int)($ph['id'] ?? 0);
    if (!$oid || !$pid) throw new RuntimeException('VK saveWallPhoto missing owner_id/id');

    $att = 'photo' . $oid . '_' . $pid;
    if (!empty($ph['access_key'])) $att .= '_' . $ph['access_key'];
    $attachments[] = $att;
  }

  // 3) wall.post
  $post = vk_api('wall.post', [
    'owner_id' => $ownerId,
    'from_group' => 1,
    'message' => $message,
    'attachments' => implode(',', $attachments),
  ], $token, $apiV);

  $postId = (int)($post['post_id'] ?? 0);
  if (!$postId) throw new RuntimeException('VK wall.post did not return post_id');

  $postUrl = $domain
    ? ('https://vk.com/' . rawurlencode($domain) . '?w=wall' . $ownerId . '_' . $postId)
    : ('https://vk.com/wall' . $ownerId . '_' . $postId);

  return [
    'post_id' => $postId,
    'owner_id' => $ownerId,
    'post_url' => $postUrl,
    'attachments_count' => count($attachments),
  ];
}

// --------- PUBLISH LOG (MySQL) ---------

function pub_log_ensure_table(PDO $pdo): void {
  // строго MySQL
  $pdo->exec("CREATE TABLE IF NOT EXISTS publish_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    kind VARCHAR(16) NOT NULL DEFAULT '',
    sid VARCHAR(64) NOT NULL DEFAULT '',
    files_count INT NOT NULL DEFAULT 0,

    stage_id INT NOT NULL DEFAULT 0,
    category VARCHAR(16) NOT NULL DEFAULT '',
    variant INT NOT NULL DEFAULT 0,
    day_no INT NOT NULL DEFAULT 0,
    day_date DATE NULL,

    vk_owner_id BIGINT NULL,
    vk_post_id BIGINT NULL,
    vk_post_url VARCHAR(255) NULL,

    message_hash CHAR(40) NOT NULL DEFAULT '',

    status ENUM('OK','ERR') NOT NULL DEFAULT 'OK',
    error_text TEXT NULL,

    PRIMARY KEY (id),
    KEY idx_created (created_at),
    KEY idx_stage_cat_var_day (stage_id, category, variant, day_no),
    KEY idx_sid (sid),
    KEY idx_vk (vk_owner_id, vk_post_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pub_log_write(array $row): void {
  try {
    if (!function_exists('db')) return; // db() живёт в _db.php через _bootstrap
    $pdo = db();
    if (!$pdo instanceof PDO) return;

    pub_log_ensure_table($pdo);

    $sql = "INSERT INTO publish_log
      (kind, sid, files_count, stage_id, category, variant, day_no, day_date,
       vk_owner_id, vk_post_id, vk_post_url, message_hash, status, error_text)
      VALUES
      (:kind, :sid, :files_count, :stage_id, :category, :variant, :day_no, :day_date,
       :vk_owner_id, :vk_post_id, :vk_post_url, :message_hash, :status, :error_text)";

    $st = $pdo->prepare($sql);
    $st->execute([
      ':kind'        => (string)($row['kind'] ?? ''),
      ':sid'         => (string)($row['sid'] ?? ''),
      ':files_count' => (int)($row['files_count'] ?? 0),

      ':stage_id'    => (int)($row['stage_id'] ?? 0),
      ':category'    => (string)($row['category'] ?? ''),
      ':variant'     => (int)($row['variant'] ?? 0),
      ':day_no'      => (int)($row['day_no'] ?? 0),
      ':day_date'    => !empty($row['day_date']) ? (string)$row['day_date'] : null,

      ':vk_owner_id' => isset($row['vk_owner_id']) ? (int)$row['vk_owner_id'] : null,
      ':vk_post_id'  => isset($row['vk_post_id']) ? (int)$row['vk_post_id'] : null,
      ':vk_post_url' => !empty($row['vk_post_url']) ? (string)$row['vk_post_url'] : null,

      ':message_hash'=> (string)($row['message_hash'] ?? ''),
      ':status'      => (string)($row['status'] ?? 'OK'),
      ':error_text'  => !empty($row['error_text']) ? (string)$row['error_text'] : null,
    ]);
  } catch (Throwable $e) {
    if (function_exists('log_line')) log_line('publish_log_write failed: ' . $e->getMessage());
  }
}

// --------- PUBLIC ENTRY (для endpoint’ов) ---------

/**
 * Унифицированный обработчик публикации.
 * $kind = 'schedule' | 'table' (чисто для меты/логов/дефолтного текста)
 */
function pub_handle(string $kind, array $body): array {
  $sid = pub_validate_sid((string)($body['sid'] ?? ''));
  [$realBase, $realDir] = pub_paths_by_sid($sid);
  $snap = pub_load_snapshot($realDir);

  $message = trim((string)($body['message'] ?? ''));
  if ($message === '') {
    $category = (string)($snap['category'] ?? '');
    $day = (string)($snap['day'] ?? '');
    $message = ($kind === 'schedule')
      ? ("Расписание — {$category}\nДень {$day}\n\n#lazacup #расписание #футболдети")
      : ("Групповой этап — {$category}\nДень {$day}\nрезультаты за этот игровой день\n\n#lazacup #результаты #футболдети");
    $message = trim($message);
  }

  $filesFromReq = (isset($body['files']) && is_array($body['files'])) ? $body['files'] : [];
  [$files, $paths] = pub_collect_files($realDir, $snap, $filesFromReq);

  $vkCfg = vk_load_secrets();

  // мета из snapshot + (если пришло с фронта — приоритет)
  $stageId = (int)($body['stage_id'] ?? ($snap['stage_id'] ?? 0));
  $category = (string)($body['category'] ?? ($snap['category'] ?? ''));
  $variant  = (int)($body['variant'] ?? ($snap['variant'] ?? 0));
  $dayNo    = (int)($body['day_no'] ?? ($snap['day'] ?? 0));
  $dayDate  = !empty($body['day_date']) ? (string)$body['day_date'] : null;

  if ($stageId > 0 && function_exists('db') && function_exists('lc_tournament_id_for_stage')) {
    $pdoPub = db();
    $tidPub = lc_tournament_id_for_stage($pdoPub, $stageId);
    if ($tidPub > 0) {
      lc_require_tournament_not_archived($pdoPub, $tidPub);
    }
  }

  $metaBase = [
    'kind' => $kind,
    'sid' => $sid,
    'files_count' => count($files),
    'snap_meta' => [
      'stage_id' => $snap['stage_id'] ?? null,
      'category' => $snap['category'] ?? null,
      'variant' => $snap['variant'] ?? null,
      'day' => $snap['day'] ?? null,
      'tournament_name' => $snap['tournament_name'] ?? null,
      'stage_name' => $snap['stage_name'] ?? null,
    ],
  ];

  $logRowBase = [
    'kind' => $kind,
    'sid'  => $sid,
    'files_count' => count($files),
    'stage_id' => $stageId,
    'category' => $category,
    'variant'  => $variant,
    'day_no'   => $dayNo,
    'day_date' => $dayDate,
    'message_hash' => sha1($message),
  ];

  try {
    $vkMeta = pub_vk_post($paths, $message, $vkCfg);

    // ✅ лог OK
    pub_log_write($logRowBase + [
      'status' => 'OK',
      'vk_owner_id' => $vkMeta['owner_id'] ?? null,
      'vk_post_id'  => $vkMeta['post_id'] ?? null,
      'vk_post_url' => $vkMeta['post_url'] ?? null,
    ]);

    return ['ok'=>true, 'meta'=>array_merge($metaBase, $vkMeta)];
  } catch (Throwable $e) {
    if (function_exists('log_line')) {
      log_line("publish {$kind} fatal: {$e->getMessage()} (sid={$sid})");
    }

    // ✅ лог ERR
    pub_log_write($logRowBase + [
      'status' => 'ERR',
      'error_text' => $e->getMessage(),
    ]);

    return ['ok'=>false, 'error'=>$e->getMessage(), 'meta'=>$metaBase];
  }
}


/* ===== LazaCup PUBLIC FOOTER + SETTINGS ===== */

// SETTINGS (DB) — simple key/value JSON storage for admin UI (no FTP).
// Table: settings(k VARCHAR(64) PK, v TEXT, updated_at DATETIME)

function lc_ensure_settings_table(PDO $pdo): void {
  // строго MySQL, без адаптеров
  $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(64) NOT NULL PRIMARY KEY,
    v TEXT NOT NULL,
    updated_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function lc_setting_get(PDO $pdo, string $k): ?string {
  lc_ensure_settings_table($pdo);
  $st = $pdo->prepare("SELECT v FROM settings WHERE k=:k LIMIT 1");
  $st->execute([':k'=>$k]);
  $r = $st->fetch();
  return $r && isset($r['v']) ? (string)$r['v'] : null;
}

function lc_setting_set(PDO $pdo, string $k, string $v): void {
  lc_ensure_settings_table($pdo);
  $now = date('Y-m-d H:i:s');
  $st = $pdo->prepare("INSERT INTO settings(k,v,updated_at) VALUES(:k,:v,:u)
    ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=VALUES(updated_at)");
  $st->execute([':k'=>$k, ':v'=>$v, ':u'=>$now]);
}

function lc_setting_get_json(PDO $pdo, string $k): array {
  $raw = lc_setting_get($pdo, $k);
  if ($raw === null || trim($raw)==='') return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

// FOOTER config builder (defaults + CFG + DB settings)
function lc_publish_footer_cfg(PDO $pdo = null): array {

  $defaults = [
    'enabled'   => true,
    'text'      => 'Generated by LazaCupSystem',
    'dt_format' => 'Y-m-d H:i',
    'size'      => 18,
    'pad_x'     => 48,
    'pad_y'     => 26,
    'opacity'   => 0.70,
  ];

  $res = $defaults;

  // 1) config.php (если есть)
  if (!empty($GLOBALS['CFG']['publish_footer']) && is_array($GLOBALS['CFG']['publish_footer'])) {
    $res = array_merge($res, $GLOBALS['CFG']['publish_footer']);
  }

  // 2) База данных
  if ($pdo) {
    lc_ensure_settings_table($pdo);

    $stmt = $pdo->prepare("SELECT v FROM settings WHERE k='publish_footer' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && isset($row['v'])) {
      $v = json_decode((string)$row['v'], true);
      if (is_array($v)) {
        $res = array_merge($res, $v);
      }
    }
  }

  // Санитайз
  $res['enabled'] = !empty($res['enabled']);
  $res['size']    = max(8, (int)$res['size']);
  $res['pad_x']   = max(0, (int)$res['pad_x']);
  $res['pad_y']   = max(0, (int)$res['pad_y']);

  $op = (float)$res['opacity'];
  if ($op < 0) $op = 0;
  if ($op > 1) $op = 1;
  $res['opacity'] = $op;

  return $res;
}


// Imagick renderer
function lc_draw_footer_imagick(Imagick $img, int $W, int $H, string $fontPath = '', string $generatedAt = '', PDO $pdo = null): void {
  $o = lc_publish_footer_cfg($pdo);
  if (empty($o['enabled'])) return;

  $dt = trim($generatedAt);
  if ($dt === '') $dt = date((string)$o['dt_format']);

  $text = $dt . '  •  ' . (string)$o['text'];

  $d = new ImagickDraw();
  $d->setTextAntialias(true);
  $d->setFillColor('rgba(23,63,105,' . $o['opacity'] . ')');
  $d->setFontSize((int)$o['size']);
  if ($fontPath && is_file($fontPath)) $d->setFont($fontPath);

  $m = $img->queryFontMetrics($d, $text);
  $textH = (int)ceil($m['textHeight'] ?? 0);

  $x = (int)$o['pad_x'];
  $y = $H - (int)$o['pad_y'];
  if ($y < $textH + 2) $y = $textH + 2;

  $img->annotateImage($d, $x, $y, 0, $text);
}

// GD renderer (kept for future/other generators)
function lc_draw_footer_gd($im, int $w, int $h, string $fontFile, string $generatedAt = '', PDO $pdo = null): void {
  $o = lc_publish_footer_cfg($pdo);
  if (empty($o['enabled'])) return;
  if (!$fontFile || !is_file($fontFile)) return;

  $dt = trim($generatedAt);
  if ($dt === '') $dt = date((string)$o['dt_format']);

  $text = $dt . '  •  ' . (string)$o['text'];

  $size = (int)$o['size'];
  $padX = (int)$o['pad_x'];
  $padY = (int)$o['pad_y'];

  $alpha = (int)round(127 - (127 * (float)$o['opacity']));
  $color = imagecolorallocatealpha($im, 23, 63, 105, $alpha);

  $bbox = imagettfbbox($size, 0, $fontFile, $text);
  $textH = abs($bbox[7] - $bbox[1]);

  $x = $padX;
  $y = $h - $padY;
  if ($y < $textH + 2) $y = $textH + 2;

  imagettftext($im, $size, 0, $x, $y, $color, $fontFile, $text);
}

// Unified footer entrypoint
function lc_draw_footer($canvas, int $W, int $H, string $fontPath = '', string $generatedAt = '', PDO $pdo = null): void {
  if ($canvas instanceof Imagick) {
    lc_draw_footer_imagick($canvas, $W, $H, $fontPath, $generatedAt, $pdo);
    return;
  }
  if ($canvas instanceof GdImage || (is_resource($canvas) && get_resource_type($canvas) === 'gd')) {
    lc_draw_footer_gd($canvas, $W, $H, $fontPath, $generatedAt, $pdo);
    return;
  }
}

