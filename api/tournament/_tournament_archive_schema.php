<?php
declare(strict_types=1);

/**
 * Идемпотентное добавление колонок архивации в tournaments (только MySQL).
 * Подключается из _bootstrap.php через require_once — без повторной загрузки _schema.php.
 */
function ensure_tournaments_archive_columns(PDO $pdo): void {
  static $done = false;
  if ($done) {
    return;
  }
  $done = true;

  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  if ($driver !== 'mysql') {
    return;
  }

  try {
    $t = $pdo->query(
      "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='tournaments' LIMIT 1"
    )->fetchColumn();
    if (!$t) {
      return;
    }

    $st = $pdo->prepare(
      "SELECT COLUMN_NAME FROM information_schema.columns
       WHERE table_schema=DATABASE() AND table_name='tournaments' AND COLUMN_NAME IN ('is_archived','archived_at')"
    );
    $st->execute();
    $have = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $col) {
      $have[(string)$col] = true;
    }

    if (!isset($have['is_archived'])) {
      $pdo->exec(
        "ALTER TABLE tournaments ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0
         COMMENT '1 = турнир в архиве' AFTER is_current"
      );
    }
    if (!isset($have['archived_at'])) {
      $pdo->exec(
        "ALTER TABLE tournaments ADD COLUMN archived_at DATETIME NULL DEFAULT NULL
         COMMENT 'Момент отправки в архив; NULL если не архив' AFTER is_archived"
      );
    }
  } catch (Throwable $e) {
    if (function_exists('log_line')) {
      log_line('ensure_tournaments_archive_columns ERROR: ' . $e->getMessage());
    }
  }
}
