<?php
declare(strict_types=1);

/**
 * Лёгкие миграции под новые таблицы (без отдельного мигратора).
 *
 * Важно: проект живёт и на MySQL, и на SQLite (в деве). Поэтому создаём таблицы
 * под текущий драйвер PDO.
 */

function ensure_stage_days_table(PDO $pdo): void {

  static $done = false;
  if ($done) return;
  $done = true;

  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

  if ($driver === 'sqlite') {
    $sql = "CREATE TABLE IF NOT EXISTS stage_days (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      stage_id INTEGER NOT NULL,
      day_date TEXT NOT NULL,
      day_start TEXT NULL,
      day_end TEXT NULL,
      fields INTEGER NULL,
      is_active INTEGER NOT NULL DEFAULT 1,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE(stage_id, day_date)
    )";
    $pdo->exec($sql);
    return;
  }

  // MySQL / MariaDB
  $sql = "CREATE TABLE IF NOT EXISTS stage_days (
    id INT NOT NULL AUTO_INCREMENT,
    stage_id INT NOT NULL,
    day_date DATE NOT NULL,
    day_start TIME NULL,
    day_end TIME NULL,
    fields INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stage_day (stage_id, day_date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

  $pdo->exec($sql);
}

/**
 * Мягкая миграция: добавляем stages.transition_minutes (если его ещё нет).
 */
function ensure_stages_transition_minutes(PDO $pdo): void {

  static $done = false;
  if ($done) return;
  $done = true;

  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

  try {
    if ($driver === 'sqlite') {
      $t = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='stages'")->fetchColumn();
      if (!$t) return;
    } else {
      $t = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='stages' LIMIT 1")->fetchColumn();
      if (!$t) return;
    }
  } catch (Throwable $e) {
    return;
  }

  try {
    if ($driver === 'sqlite') {
      $cols = $pdo->query("PRAGMA table_info(stages)")->fetchAll(PDO::FETCH_ASSOC);
      foreach ($cols as $c) {
        if ((string)($c['name'] ?? '') === 'transition_minutes') return;
      }
      $pdo->exec("ALTER TABLE stages ADD COLUMN transition_minutes INTEGER NOT NULL DEFAULT 15");
      return;
    }

    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stages' AND column_name='transition_minutes' LIMIT 1");
    $st->execute();
    if ($st->fetchColumn()) return;

    $pdo->exec("ALTER TABLE stages ADD COLUMN transition_minutes INT NOT NULL DEFAULT 15 AFTER break_minutes");
  } catch (Throwable $e) {
    if (function_exists('log_line')) {
      log_line('ensure_stages_transition_minutes ERROR: '.$e->getMessage());
    }
  }
}

function ensure_clubs_logo_path(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  $done = true;

  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

  try {
    if ($driver === 'sqlite') {
      $t = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='clubs'")->fetchColumn();
      if (!$t) return;
      $cols = $pdo->query("PRAGMA table_info(clubs)")->fetchAll(PDO::FETCH_ASSOC);
      foreach ($cols as $c) {
        if ((string)($c['name'] ?? '') === 'logo_path') return;
      }
      $pdo->exec("ALTER TABLE clubs ADD COLUMN logo_path TEXT NULL");
      return;
    }

    $t = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='clubs' LIMIT 1")->fetchColumn();
    if (!$t) return;

    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clubs' AND column_name='logo_path' LIMIT 1");
    $st->execute();
    if ($st->fetchColumn()) return;

    $pdo->exec("ALTER TABLE clubs ADD COLUMN logo_path VARCHAR(255) NULL AFTER city");
  } catch (Throwable $e) {
    if (function_exists('log_line')) {
      log_line('ensure_clubs_logo_path ERROR: ' . $e->getMessage());
    }
  }
}

function ensure_results_penalty_columns(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  $done = true;

  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

  try {
    if ($driver === 'sqlite') {
      $t = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='results'")->fetchColumn();
      if (!$t) return;

      $cols = $pdo->query("PRAGMA table_info(results)")->fetchAll(PDO::FETCH_ASSOC);
      $haveHomePen = false;
      $haveAwayPen = false;
      foreach ($cols as $c) {
        $name = (string)($c['name'] ?? '');
        if ($name === 'home_pen_goals') $haveHomePen = true;
        if ($name === 'away_pen_goals') $haveAwayPen = true;
      }
      if (!$haveHomePen) $pdo->exec("ALTER TABLE results ADD COLUMN home_pen_goals INTEGER NULL");
      if (!$haveAwayPen) $pdo->exec("ALTER TABLE results ADD COLUMN away_pen_goals INTEGER NULL");
      return;
    }

    $t = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='results' LIMIT 1")->fetchColumn();
    if (!$t) return;

    $st = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='results' AND column_name IN ('home_pen_goals','away_pen_goals')");
    $st->execute();
    $have = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $col) {
      $have[(string)$col] = true;
    }

    if (!isset($have['home_pen_goals'])) {
      try { $pdo->exec("ALTER TABLE results ADD COLUMN home_pen_goals INT NULL AFTER away_goals"); } catch (Throwable $e) {}
    }
    if (!isset($have['away_pen_goals'])) {
      try { $pdo->exec("ALTER TABLE results ADD COLUMN away_pen_goals INT NULL AFTER home_pen_goals"); } catch (Throwable $e) {}
    }
  } catch (Throwable $e) {
    if (function_exists('log_line')) {
      log_line('ensure_results_penalty_columns ERROR: ' . $e->getMessage());
    }
  }
}

function has_results_penalty_columns(PDO $pdo): bool {
  static $cache = null;
  if ($cache !== null) return $cache;

  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

  try {
    if ($driver === 'sqlite') {
      $cols = $pdo->query("PRAGMA table_info(results)")->fetchAll(PDO::FETCH_ASSOC);
      $haveHomePen = false;
      $haveAwayPen = false;
      foreach ($cols as $c) {
        $name = (string)($c['name'] ?? '');
        if ($name === 'home_pen_goals') $haveHomePen = true;
        if ($name === 'away_pen_goals') $haveAwayPen = true;
      }
      $cache = ($haveHomePen && $haveAwayPen);
      return $cache;
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='results' AND column_name IN ('home_pen_goals','away_pen_goals')");
    $st->execute();
    $cache = ((int)$st->fetchColumn() >= 2);
    return $cache;
  } catch (Throwable $e) {
    $cache = false;
    return false;
  }
}

function ensure_squad_constraints_table(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  $done = true;

  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

  if ($driver === 'sqlite') {
    $sql = "CREATE TABLE IF NOT EXISTS squad_constraints (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      stage_id INTEGER NOT NULL,
      squad_id INTEGER NOT NULL,
      day_id INTEGER NULL,
      day_date TEXT NULL,
      not_before_slot_no INTEGER NULL,
      not_after_slot_no INTEGER NULL,
      comment TEXT NULL,
      created_at TEXT NOT NULL DEFAULT (datetime('now')),
      updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );";
    $pdo->exec($sql);
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_stage_squad_day ON squad_constraints(stage_id, squad_id, day_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stage_day ON squad_constraints(stage_id, day_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stage_date ON squad_constraints(stage_id, day_date);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_squad ON squad_constraints(squad_id);");
    return;
  }

  $sql = "CREATE TABLE IF NOT EXISTS squad_constraints (
    id INT NOT NULL AUTO_INCREMENT,
    stage_id INT NOT NULL,
    squad_id INT NOT NULL,
    day_id BIGINT UNSIGNED NULL,
    day_date DATE NULL,
    not_before_slot_no INT NULL,
    not_after_slot_no INT NULL,
    comment VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stage_squad_day (stage_id, squad_id, day_id),
    KEY idx_stage_day (stage_id, day_id),
    KEY idx_stage_date (stage_id, day_date),
    KEY idx_squad (squad_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;";
  $pdo->exec($sql);

  try {
    $pdo->exec("ALTER TABLE squad_constraints MODIFY day_id BIGINT UNSIGNED NULL");
  } catch (Throwable $e) {}

  try {
    $hasUq = $pdo->query("SHOW INDEX FROM squad_constraints WHERE Key_name='uq_stage_squad_day'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasUq) {
      $pdo->exec("DELETE t1 FROM squad_constraints t1
        INNER JOIN squad_constraints t2
          ON t1.stage_id=t2.stage_id
         AND t1.squad_id=t2.squad_id
         AND t1.day_id=t2.day_id AND t1.day_id IS NOT NULL
         AND t1.id>t2.id");
      $pdo->exec("ALTER TABLE squad_constraints ADD UNIQUE KEY uq_stage_squad_day (stage_id, squad_id, day_id)");
    }
  } catch (Throwable $e) {}

  try { $pdo->exec("ALTER TABLE squad_constraints ADD KEY idx_stage_day (stage_id, day_id)"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE squad_constraints ADD KEY idx_stage_date (stage_id, day_date)"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE squad_constraints ADD KEY idx_squad (squad_id)"); } catch (Throwable $e) {}
}

function ensure_schedule_items_table(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  $done = true;

  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

  if ($driver === 'sqlite') {
    $sql = "CREATE TABLE IF NOT EXISTS schedule_items (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      stage_id INTEGER NOT NULL,
      day_date TEXT NOT NULL,
      slot_index INTEGER NOT NULL,
      resource_code TEXT NOT NULL,
      match_id INTEGER NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE(stage_id, day_date, slot_index, resource_code)
    )";
    $pdo->exec($sql);

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_si_stage_day ON schedule_items(stage_id, day_date)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_si_match ON schedule_items(match_id)");
    return;
  }

  $sql = "CREATE TABLE IF NOT EXISTS schedule_items (
    id INT NOT NULL AUTO_INCREMENT,
    stage_id INT NOT NULL,
    day_date DATE NOT NULL,
    slot_index INT NOT NULL,
    resource_code VARCHAR(32) NOT NULL,
    match_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stage_day_slot_res (stage_id, day_date, slot_index, resource_code),
    UNIQUE KEY uq_match (match_id),
    KEY idx_stage_day (stage_id, day_date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

  $pdo->exec($sql);
}
