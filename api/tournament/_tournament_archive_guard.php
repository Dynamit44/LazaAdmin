<?php
declare(strict_types=1);

/**
 * Защита от изменений данных архивного турнира.
 * Требует заранее подключённый _respond.php (через _bootstrap.php).
 */

function lc_tournament_id_for_stage(PDO $pdo, int $stageId): int {
  if ($stageId <= 0) {
    return 0;
  }
  $st = $pdo->prepare('SELECT tournament_id FROM stages WHERE id = :id LIMIT 1');
  $st->execute([':id' => $stageId]);
  return (int)($st->fetchColumn() ?: 0);
}

/**
 * Завершает запрос с 403, если турнир в архиве. Если строки турнира нет — ничего не делает.
 */
function lc_require_tournament_not_archived(PDO $pdo, int $tournamentId): void {
  if ($tournamentId <= 0) {
    return;
  }
  $st = $pdo->prepare('SELECT is_archived FROM tournaments WHERE id = :id LIMIT 1');
  $st->execute([':id' => $tournamentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return;
  }
  if ((int)($row['is_archived'] ?? 0) === 1) {
    respond_json(['ok' => false, 'error' => 'Турнир в архиве'], 403);
  }
}
