<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_schema.php';
require_role('organizer');

$pdo = db();
ensure_squad_constraints_table($pdo);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];

$id = (int)($in['id'] ?? 0);
if ($id <= 0) respond_json(['ok'=>false,'error'=>'id required'], 400);

$st = $pdo->prepare('SELECT stage_id FROM squad_constraints WHERE id = ? LIMIT 1');
$st->execute([$id]);
$scStageId = (int)($st->fetchColumn() ?: 0);
if ($scStageId <= 0) {
  respond_json(['ok'=>false,'error'=>'constraint not found'], 404);
}
lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $scStageId));

$st = $pdo->prepare("DELETE FROM squad_constraints WHERE id=? LIMIT 1");
$st->execute([$id]);

respond_json(['ok'=>true]);