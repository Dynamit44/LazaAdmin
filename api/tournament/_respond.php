<?php
declare(strict_types=1);

/**
 * Единый JSON-ответ.
 */
function respond_json(array $data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Чтение JSON тела запроса.
 */
function read_json_body(): array {
  $raw = file_get_contents('php://input') ?: '';
  if ($raw === '') return [];
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    respond_json(['ok' => false, 'error' => 'Invalid JSON body'], 400);
  }
  return $data;
}

/**
 * Лог в файл (чтобы потом разбирать “почему не сгенерилось”).
 */
function log_line(string $message): void {
  $cfgPath = dirname(__DIR__, 3) . '/_private/tournament/config.php'; // /www/_private/...
  $cfg = require $cfgPath;

  $dir = $cfg['log_dir'] ?? sys_get_temp_dir();
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $file = rtrim($dir, '/\\') . '/tournament_' . date('Ymd') . '.log';
  @file_put_contents($file, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
}
