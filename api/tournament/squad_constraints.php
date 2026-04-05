<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('organizer');

$pdo = db();
ensure_squad_constraints_table($pdo);

$stageId = (int)($_GET['stage_id'] ?? 0);
if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);

$dayId = (int)($_GET['day_id'] ?? 0);
$date  = trim((string)($_GET['date'] ?? ''));
$allDays = (int)($_GET['all_days'] ?? 0) === 1;

$where = "WHERE sc.stage_id = :stage_id";
$args  = [':stage_id'=>$stageId];

if (!$allDays) {
  if ($date === '' && $dayId > 0) {
    $date = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', (string)$dayId);
  }

  if ($dayId > 0 && $date !== '') {
    $where .= " AND (sc.day_id = :day_id OR sc.day_date = :day_date OR (sc.day_date = :day_date AND (sc.day_id IS NULL OR sc.day_id=0)))";
    $args[':day_id'] = $dayId;
    $args[':day_date'] = $date;
  } elseif ($dayId > 0) {
    $where .= " AND sc.day_id = :day_id";
    $args[':day_id'] = $dayId;
  } elseif ($date !== '') {
    $where .= " AND (sc.day_date = :day_date OR sc.day_id = :day_id_from_date OR (sc.day_date = :day_date AND (sc.day_id IS NULL OR sc.day_id=0)))";
    $args[':day_date'] = $date;
    $args[':day_id_from_date'] = (int)preg_replace('/[^0-9]/', '', $date);
  }
}

$sql = "
SELECT
  sc.*,
  s.name AS squad_name,
  s.category AS squad_category,
  c.name AS club_name
FROM squad_constraints sc
LEFT JOIN squads s ON s.id = sc.squad_id
LEFT JOIN clubs c ON c.id = s.club_id
{$where}
ORDER BY sc.id DESC
";

$st = $pdo->prepare($sql);
$st->execute($args);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

respond_json(['ok'=>true,'count'=>count($items),'items'=>$items]);