<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// Превью/картинки публикаций — только для organizer (пока).
require_role('organizer','operator');

$sid  = isset($_GET['sid']) ? (string)$_GET['sid'] : '';
$file = isset($_GET['file']) ? (string)$_GET['file'] : '';

if ($sid === '' || $file === '') {
  http_response_code(400);
  echo 'Bad request';
  exit;
}

// простая валидация, чтобы не было ../
if (!preg_match('~^[a-zA-Z0-9._-]{3,64}$~', $sid)) {
  http_response_code(400);
  echo 'Bad sid';
  exit;
}
if (!preg_match('~^[a-zA-Z0-9._-]{3,128}$~', $file)) {
  http_response_code(400);
  echo 'Bad file';
  exit;
}

$base = dirname(__DIR__, 3) . '/_private/tournament/published';
$dir  = $base . '/' . $sid;
$path = $dir . '/' . $file;

$realBase = realpath($base);
$realPath = realpath($path);
if (!$realBase || !$realPath || strncmp($realPath, $realBase, strlen($realBase)) !== 0) {
  http_response_code(404);
  echo 'Not found';
  exit;
}

if (!is_file($realPath)) {
  http_response_code(404);
  echo 'Not found';
  exit;
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile($realPath);
