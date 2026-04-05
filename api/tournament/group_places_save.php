<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('operator','organizer');

$pdo = db();

// POST JSON:
//  {stage_id, category, variant, group_code, order:[squad_id...]}  — сохранить
//  {stage_id, category, variant, group_code, clear:1}             — сбросить
$in = read_json_body();

$stageId  = isset($in['stage_id']) ? (int)$in['stage_id'] : 0;
$category = isset($in['category']) ? trim((string)$in['category']) : '';
$variant  = isset($in['variant']) ? (int)$in['variant'] : 1;
$group    = isset($in['group_code']) ? trim((string)$in['group_code']) : '';
$clear    = !empty($in['clear']);
$order    = isset($in['order']) && is_array($in['order']) ? $in['order'] : [];

if ($stageId <= 0 || $category === '' || $variant <= 0 || $group === '') {
  respond_json(['ok'=>false,'error'=>'Bad params'], 400);
}

$st = $pdo->prepare('SELECT tournament_id FROM stages WHERE id=:id LIMIT 1');
$st->execute([':id'=>$stageId]);
$tournamentId = (int)$st->fetchColumn();
if ($tournamentId <= 0) {
  respond_json(['ok'=>false,'error'=>'stage not found'], 404);
}
lc_require_tournament_not_archived($pdo, $tournamentId);

// Нормализуем order
$ids = [];
foreach ($order as $v) {
  $id = (int)$v;
  if ($id > 0) $ids[] = $id;
}
$ids = array_values(array_unique($ids));

try {
  $pdo->beginTransaction();

  // Список squad_id, которые реально входят в эту группу
  $st = $pdo->prepare('
    SELECT squad_id
    FROM group_entries
    WHERE stage_id=:sid AND category=:cat AND variant=:v AND group_code=:g
    ORDER BY pos
  ');
  $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant, ':g'=>$group]);
  $existing = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

  if (!$existing) {
    $pdo->rollBack();
    respond_json(['ok'=>false,'error'=>'group not found or empty'], 404);
  }

  // Оставляем только существующие id и (если кто-то пропал из order) — дописываем в конец
  $ids = array_values(array_intersect($ids, $existing));
  $missing = array_values(array_diff($existing, $ids));
  $ids = array_merge($ids, $missing);

  // --- Основной сценарий: group_entries.manual_place (есть в дампе) ---
  $hasManualCol = true;
  try {
    $pdo->query('SELECT manual_place FROM group_entries LIMIT 1');
  } catch (Throwable $e) {
    $hasManualCol = false;
  }

  if ($hasManualCol) {
    // Сначала чистим, потом проставляем 1..N
    $st = $pdo->prepare('
      UPDATE group_entries
      SET manual_place = NULL
      WHERE stage_id=:sid AND category=:cat AND variant=:v AND group_code=:g
    ');
    $st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant, ':g'=>$group]);

    if (!$clear) {
      $stU = $pdo->prepare('
        UPDATE group_entries
        SET manual_place=:p
        WHERE stage_id=:sid AND category=:cat AND variant=:v AND group_code=:g AND squad_id=:qid
      ');
      $p = 1;
      foreach ($ids as $qid) {
        $stU->execute([':p'=>$p, ':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant, ':g'=>$group, ':qid'=>(int)$qid]);
        $p++;
      }
    }

    $pdo->commit();

    respond_json([
      'ok'=>true,
      'storage'=>'group_entries',
      'stage_id'=>$stageId,
      'category'=>$category,
      'variant'=>$variant,
      'group_code'=>$group,
      'cleared'=>$clear
    ]);
  }

  // --- Фоллбек: отдельная таблица group_places (если ты решишь вынести места туда) ---
  // Рекомендуемая схема (вариант В):
  // group_places: tournament_id, stage_id, category, variant, group_code, squad_id, place, updated_at
  //
  // Мы поддержим и поле place, и поле manual_place (если так назовёшь).

  // выясняем, как называется колонка "место"
  $placeCol = 'place';
  try {
    $pdo->query('SELECT place FROM group_places LIMIT 1');
  } catch (Throwable $e) {
    $placeCol = 'manual_place';
    // если и manual_place нет — пусть уже SQL упадёт с понятной ошибкой
  }

  // Clear existing for group
  $stD = $pdo->prepare('
    DELETE FROM group_places
    WHERE tournament_id=:tid AND stage_id=:sid AND category=:cat AND variant=:v AND group_code=:g
  ');
  $stD->execute([':tid'=>$tournamentId, ':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant, ':g'=>$group]);

  if (!$clear) {
    $sql = 'INSERT INTO group_places (tournament_id, stage_id, category, variant, group_code, squad_id, ' . $placeCol . ', updated_at)
            VALUES (:tid,:sid,:cat,:v,:g,:qid,:p,NOW())';
    $stI = $pdo->prepare($sql);
    $p=1;
    foreach ($ids as $qid) {
      $stI->execute([':tid'=>$tournamentId, ':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant, ':g'=>$group, ':qid'=>(int)$qid, ':p'=>$p]);
      $p++;
    }
  }

  $pdo->commit();

  respond_json([
    'ok'=>true,
    'storage'=>'group_places',
    'stage_id'=>$stageId,
    'category'=>$category,
    'variant'=>$variant,
    'group_code'=>$group,
    'cleared'=>$clear
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>$e->getMessage()], 500);
}
