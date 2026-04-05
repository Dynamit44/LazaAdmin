<?php
declare(strict_types=1);

// /api/tournament/_snapshots_lib.php

function snapshots_root_www(): string {
  // __DIR__ = /www/lazacup.ru/api/tournament
  // 3 уровня вверх => /www
  return dirname(__DIR__, 3);
}

function snapshots_base_dir(): string {
  return snapshots_root_www() . '/_private/tournament/published';
}

function snapshots_meta_file(): string {
  return snapshots_base_dir() . '/_cleanup.json';
}

function snapshots_rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $items = @scandir($dir);
  if (!$items) { @rmdir($dir); return; }

  foreach ($items as $it) {
    if ($it === '.' || $it === '..') continue;
    $p = $dir . '/' . $it;
    if (is_dir($p)) snapshots_rrmdir($p);
    else @unlink($p);
  }
  @rmdir($dir);
}

function snapshots_list_dirs(string $baseDir): array {
  if (!is_dir($baseDir)) return [];
  $out = [];
  $items = @scandir($baseDir);
  if (!$items) return [];

  foreach ($items as $it) {
    if ($it === '.' || $it === '..') continue;
    if ($it === '_cleanup.json') continue;
    $p = $baseDir . '/' . $it;
    if (is_dir($p)) $out[] = ['name'=>$it, 'path'=>$p, 'mtime'=>@filemtime($p) ?: 0];
  }
  return $out;
}

function snapshots_read_meta(): array {
  $f = snapshots_meta_file();
  if (!is_file($f)) return [];
  $raw = @file_get_contents($f);
  $j = $raw ? json_decode($raw, true) : null;
  return is_array($j) ? $j : [];
}

function snapshots_write_meta(array $meta): void {
  $f = snapshots_meta_file();
  $dir = dirname($f);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents($f, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function snapshots_get_status(int $keepDays = 14): array {
  $baseDir = snapshots_base_dir();
  $dirs = snapshots_list_dirs($baseDir);

  $meta = snapshots_read_meta();

  return [
    'count' => count($dirs),
    'keep_days' => $keepDays,
    'last_cleanup_at' => (string)($meta['ts'] ?? ''),
    'last_cleanup_removed' => (int)($meta['removed'] ?? 0),
  ];
}

function snapshots_cleanup(int $keepDays = 14): array {
  $baseDir = snapshots_base_dir();
  if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);

  $now = time();
  $limit = $now - ($keepDays * 86400);

  $dirs = snapshots_list_dirs($baseDir);
  $removed = 0;

  foreach ($dirs as $d) {
    $mtime = (int)($d['mtime'] ?? 0);
    if ($mtime > 0 && $mtime < $limit) {
      snapshots_rrmdir($d['path']);
      $removed++;
    }
  }

  $meta = [
    'ts' => date('Y-m-d H:i:s'),
    'removed' => $removed,
    'keep_days' => $keepDays,
  ];
  snapshots_write_meta($meta);

  return $meta;
}


// Берём keep_days из settings (k='snapshots_keep_days'), если есть.
// ВАЖНО: без падений — всегда возвращаем int.
function snapshots_keep_days_from_db($pdo, int $def = 14): int {
  try {
    if (!$pdo) return $def;
    if (!function_exists('lc_setting_get')) return $def;
    $raw = lc_setting_get($pdo, 'snapshots_keep_days');
    $n = (int)trim((string)$raw);
    if ($n < 1 || $n > 365) return $def;
    return $n;
  } catch (Throwable $e) {
    return $def;
  }
}
