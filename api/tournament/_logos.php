<?php
declare(strict_types=1);

function lc_logo_public_url(?string $path): string {
  $path = trim((string)$path);
  if ($path === '') return '';
  if (preg_match('~^https?://~i', $path)) return $path;
  if ($path[0] !== '/') $path = '/' . ltrim($path, '/');
  return $path;
}

function lc_logo_upload_dir(): string {
  $dir = dirname(__DIR__, 2) . '/uploads/tournament/squads';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  return $dir;
}

function lc_logo_abs_path(?string $path): string {
  $path = trim((string)$path);
  if ($path === '' || preg_match('~^https?://~i', $path)) return '';
  if ($path[0] !== '/') $path = '/' . ltrim($path, '/');

  $base = dirname(__DIR__, 2);
  $uploadsBase = realpath($base . '/uploads/tournament/squads') ?: ($base . '/uploads/tournament/squads');
  $candidate = $base . $path;
  $real = realpath($candidate);

  if ($real === false) {
    return (is_file($candidate) ? $candidate : '');
  }
  if (strpos($real, $uploadsBase) !== 0) return '';
  return $real;
}

function lc_logo_safe_unlink(?string $path): void {
  $abs = lc_logo_abs_path($path);
  if ($abs !== '' && is_file($abs)) {
    @unlink($abs);
  }
}

function lc_logo_ext_by_mime(string $mime): string {
  $mime = strtolower(trim($mime));
  return match ($mime) {
    'image/png' => 'png',
    'image/jpeg', 'image/jpg' => 'jpg',
    'image/webp' => 'webp',
    default => '',
  };
}
