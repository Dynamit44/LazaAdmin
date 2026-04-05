<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require __DIR__ . '/_logos.php';
require_role('organizer');

$stageId  = (int)($_GET['stage_id'] ?? 0);
$category = trim((string)($_GET['category'] ?? ''));
$debug    = (int)($_GET['debug'] ?? 0);

if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);

$pdo = db();
ensure_clubs_logo_path($pdo);

// stage exists
$st = $pdo->prepare("SELECT id FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id'=>$stageId]);
if (!$st->fetchColumn()) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

$where = "WHERE s.stage_id = :stage_id";
$args  = [':stage_id'=>$stageId];

if ($category !== '') {
  // нормализуем категорию к "20xx"
  $catN = preg_replace('~[^0-9]~', '', $category);
  if (!preg_match('~^(20\d{2})$~', $catN)) respond_json(['ok'=>false,'error'=>'bad category'], 400);
  $where .= " AND s.category = :category";
  $args[':category'] = $catN;
}

$sql = "
SELECT
  s.id,
  s.tournament_id,
  s.stage_id,
  s.category,
  s.category_variant,
  s.club_id,
  s.name,
  s.rating,
  s.coach_id,
  ch.name AS coach_name,
  c.name  AS club_name,
  c.city  AS club_city,
  c.logo_path AS logo_path
FROM squads s
LEFT JOIN coaches ch ON ch.id = s.coach_id
LEFT JOIN clubs c ON c.id = s.club_id
{$where}
ORDER BY s.category DESC, s.rating DESC, s.name
";

$st = $pdo->prepare($sql);
$st->execute($args);
$items = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($items as &$it) {
  $it['logo_url'] = lc_logo_public_url((string)($it['logo_path'] ?? ''));
}
unset($it);

$payload = [
  'ok'    => true,
  'count' => count($items),
  'items' => $items,
];

if ($debug === 1) {
  $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $dbName = null;
  try { $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn(); } catch (Throwable $e) {}
  $payload['meta'] = [
    'driver' => $driver,
    'db'     => $dbName,
  ];
}

respond_json($payload);