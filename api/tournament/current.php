<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('operator','organizer');


$pdo = db();
ensure_stages_transition_minutes($pdo);

$t = $pdo->query("SELECT id, name, created_at
                  FROM tournaments
                  WHERE is_current=1 AND is_archived=0
                  ORDER BY id DESC
                  LIMIT 1")->fetch();

$stage = null;
if ($t) {
  $st = $pdo->prepare("SELECT
                         id,
                         tournament_id,
                         name,
                         start_date,
                         end_date,
                         day_start,
                         day_end,
                         fields,
                         match_minutes,
                         break_minutes,
                         transition_minutes,
                         min_rest_slots,
                         timezone,
                         is_current
                       FROM stages
                       WHERE tournament_id=:tid AND is_current=1
                       ORDER BY id DESC
                       LIMIT 1");
  $st->execute([':tid' => (int)$t['id']]);
  $stage = $st->fetch() ?: null;
}

respond_json([
  'ok' => true,
  'tournament' => $t ?: null,
  'stage' => $stage
]);
