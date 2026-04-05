<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_role('operator','organizer');

require_once __DIR__ . '/_canon_ranker.php';

$pdo = db();

$in = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
  ? (read_json_body() ?: ($_POST ?: []))
  : ($_GET ?: []);

$stageId  = (int)($in['stage_id'] ?? 0);
$category = $in['category'] ?? 0;
$variant  = (int)($in['variant'] ?? 1);
$useManual = !isset($in['use_manual']) || (int)$in['use_manual'] !== 0;

if ($stageId <= 0 || $category === '' || $variant <= 0) {
  respond_json(['ok'=>false,'error'=>'stage_id/category/variant required'], 400);
}

try {
  $res = canon_rank_build($pdo, $stageId, $category, $variant, $useManual);

  $table = [];
  foreach ($res['seeds'] as $place => $e) {
    $s = $e['stats'];
    $table[] = [
      'seed' => (int)$place,
      'tier' => (int)$e['tier'],
      'group' => (string)$e['group_code'],
      'group_place' => (int)$e['place_in_group'],
      'squad_id' => (int)$e['squad_id'],
      'name' => (string)$e['squad_name'],
      'played' => (int)$s['played'],
      'pts' => (int)$s['pts'],
      'wins' => (int)$s['wins'],
      'gf' => (int)$s['gf'],
      'ga' => (int)$s['ga'],
      'gd' => (int)$s['gd'],
      'ppg' => round((float)$s['ppg'], 6),
      'gdpg' => round((float)$s['gdpg'], 6),
      'gfpg' => round((float)$s['gfpg'], 6),
    ];
  }

  respond_json([
    'ok' => true,
    'N' => $res['N'],
    'groups_meta' => $res['groups_meta'], // warnings по абсолютным ничьим
    'needs_manual' => $res['needs_manual'] ?? false,
    'needs_manual_groups' => $res['needs_manual_groups'] ?? [],
    'needs_manual_blocks' => $res['needs_manual_blocks'] ?? [],
    'seeds' => $table,
  ]);

} catch (Throwable $e) {
  respond_json(['ok'=>false,'error'=>$e->getMessage()], 500);
}