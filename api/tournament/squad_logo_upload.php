<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require __DIR__ . '/_logos.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$squadId = (int)($_POST['squad_id'] ?? 0);
if ($squadId <= 0) respond_json(['ok'=>false,'error'=>'squad_id required'], 400);
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  respond_json(['ok'=>false,'error'=>'file required'], 400);
}

$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  respond_json(['ok'=>false,'error'=>'upload error'], 400);
}
$tmp = (string)($f['tmp_name'] ?? '');
if ($tmp === '' || !is_file($tmp)) respond_json(['ok'=>false,'error'=>'bad upload'], 400);

$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > 5 * 1024 * 1024) {
  respond_json(['ok'=>false,'error'=>'file too large (max 5MB)'], 400);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmp);
$ext = lc_logo_ext_by_mime($mime);
if ($ext === '') {
  respond_json(['ok'=>false,'error'=>'allowed: png, jpg, webp'], 400);
}

$pdo = db();
ensure_clubs_logo_path($pdo);

$st = $pdo->prepare(
  "SELECT s.id, s.club_id, stg.tournament_id, c.logo_path
   FROM squads s
   JOIN clubs c ON c.id = s.club_id
   JOIN stages stg ON stg.id = s.stage_id
   WHERE s.id = :id LIMIT 1"
);
$st->execute([':id'=>$squadId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) respond_json(['ok'=>false,'error'=>'squad not found'], 404);

lc_require_tournament_not_archived($pdo, (int)($row['tournament_id'] ?? 0));

$clubId = (int)($row['club_id'] ?? 0);
if ($clubId <= 0) respond_json(['ok'=>false,'error'=>'club not found'], 404);
$oldPath = (string)($row['logo_path'] ?? '');

$dir = lc_logo_upload_dir();
if (!is_dir($dir)) respond_json(['ok'=>false,'error'=>'upload dir unavailable'], 500);

$basename = 'club_' . $clubId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
$abs = $dir . '/' . $basename;
$rel = '/uploads/tournament/squads/' . $basename;

if (!move_uploaded_file($tmp, $abs)) {
  respond_json(['ok'=>false,'error'=>'cannot save file'], 500);
}

try {
  $up = $pdo->prepare("UPDATE clubs SET logo_path=:p WHERE id=:id LIMIT 1");
  $up->execute([':p'=>$rel, ':id'=>$clubId]);
  if ($oldPath !== '' && $oldPath !== $rel) {
    lc_logo_safe_unlink($oldPath);
  }
} catch (Throwable $e) {
  @unlink($abs);
  respond_json(['ok'=>false,'error'=>'db_error','details'=>$e->getMessage()], 500);
}

respond_json([
  'ok' => true,
  'squad_id' => $squadId,
  'club_id' => $clubId,
  'logo_path' => $rel,
  'logo_url' => lc_logo_public_url($rel),
]);
