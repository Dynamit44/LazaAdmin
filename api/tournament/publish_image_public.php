<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// Public image for VK link preview (signed).
// Reads file from /_private/tournament/published/{sid}/{file}
// Validates exp + sig using secrets.

$sid  = (string)($_GET['sid'] ?? '');
$file = (string)($_GET['file'] ?? '');
$exp  = (string)($_GET['exp'] ?? '');
$sig  = (string)($_GET['sig'] ?? '');

if (!preg_match('~^[a-zA-Z0-9_-]{3,64}$~', $sid)) {
    http_response_code(400);
    echo 'Bad sid';
    exit;
}

// простая валидация, чтобы не было ../
if ($file === '' || !preg_match('~^[a-zA-Z0-9._-]{1,128}$~', $file) || str_contains($file, '..') || str_contains($file, '/')) {
    http_response_code(400);
    echo 'Bad file';
    exit;
}

$expInt = (int)$exp;
if ($expInt <= time()) {
    http_response_code(403);
    echo 'Expired';
    exit;
}

// --- вычисляем PRIVATE_DIR без констант ---
$WWW_DIR     = dirname(__DIR__, 3);          // .../data/www
$PRIVATE_DIR = $WWW_DIR . '/_private';       // .../data/www/_private

$dir  = $PRIVATE_DIR . '/tournament/published/' . $sid;
$path = $dir . '/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$secretsFile = $PRIVATE_DIR . '/vk_secrets.php';
if (!is_file($secretsFile)) {
    http_response_code(500);
    echo 'No secrets';
    exit;
}

$secrets = require $secretsFile;
$secret  = (string)($secrets['public_secret'] ?? '');
if ($secret === '') {
    http_response_code(500);
    echo 'No public_secret';
    exit;
}

// подпись: sha256("{sid}|{file}|{exp}|{secret}")
$expected = hash('sha256', $sid . '|' . $file . '|' . $expInt . '|' . $secret);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo 'Bad signature';
    exit;
}

$ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'png'  => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=300');
header('X-Robots-Tag: noindex, nofollow');

readfile($path);
