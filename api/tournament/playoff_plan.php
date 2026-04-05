<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_playoff_modes.php';
require_role('organizer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$stageId = (int)($_GET['stage_id'] ?? 0);
if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);

$pdo = db();

$st = $pdo->prepare("SELECT id, tournament_id, name, start_date, end_date FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id'=>$stageId]);
$stage = $st->fetch(PDO::FETCH_ASSOC);
if (!$stage) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

$st = $pdo->prepare("
  SELECT *
  FROM stage_categories
  WHERE stage_id=:sid
  ORDER BY category DESC
");
$st->execute([':sid'=>$stageId]);
$cats = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("
  SELECT category, variant, COUNT(DISTINCT group_code) AS groups_count
  FROM group_entries
  WHERE stage_id=:sid
  GROUP BY category, variant
");
$st->execute([':sid'=>$stageId]);
$groupCounts = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $key = $r['category'].'|'.$r['variant'];
  $groupCounts[$key] = (int)$r['groups_count'];
}

$st = $pdo->prepare("
  SELECT category, variant, group_code, COUNT(*) AS n
  FROM group_entries
  WHERE stage_id=:sid
  GROUP BY category, variant, group_code
  ORDER BY category DESC, variant ASC, group_code ASC
");
$st->execute([':sid'=>$stageId]);
$groupSizes = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $key = $r['category'].'|'.$r['variant'];
  if (!isset($groupSizes[$key])) $groupSizes[$key] = [];
  $groupSizes[$key][] = ['group_code'=>$r['group_code'], 'n'=>(int)$r['n']];
}

$plans = [];
$tableMissing = false;
try {
  $st = $pdo->prepare("
    SELECT stage_id, category, variant, groups_count, playoff_mode, meta_json, updated_at
    FROM stage_playoff_plan
    WHERE stage_id=:sid
  ");
  $st->execute([':sid'=>$stageId]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = $r['category'].'|'.$r['variant'];
    $plans[$key] = [
      'stage_id' => (int)$r['stage_id'],
      'category' => (int)$r['category'],
      'variant' => (int)$r['variant'],
      'groups_count' => (int)$r['groups_count'],
      'playoff_mode' => (string)($r['playoff_mode'] ?? 'none'),
      'meta_json' => $r['meta_json'],
      'updated_at' => $r['updated_at'],
    ];
  }
} catch (PDOException $e) {
  if (($e->getCode() === '42S02') || (strpos($e->getMessage(), 'stage_playoff_plan') !== false)) {
    $tableMissing = true;
  } else {
    throw $e;
  }
}

function groups_sizes_sorted(array $sizes): array {
  usort($sizes, static function(array $a, array $b): int {
    return strcmp((string)($a['group_code'] ?? ''), (string)($b['group_code'] ?? ''));
  });
  return $sizes;
}

function recommend_mode_and_matrix(array $catRow, int $groupsCount, array $sizes = []): array {
  $enabled = (int)($catRow['playoff_enabled'] ?? 0) === 1;
  $type = playoff_type_normalize((string)($catRow['playoff_type'] ?? 'semis_finals'));
  $sizes = groups_sizes_sorted($sizes);

  if (!$enabled) {
    $items = playoff_mode_items_for_type_groups('none', 0);
    return [
      'allowed_ids' => ['none'],
      'allowed_items' => $items,
      'suggested_mode' => 'none',
      'note' => 'Плей-офф отключён в настройках категории.',
    ];
  }

  if ($groupsCount <= 0) {
    return [
      'allowed_ids' => ['none'],
      'allowed_items' => playoff_mode_items_for_type_groups('none', 0),
      'suggested_mode' => 'none',
      'note' => 'Группы ещё не сформированы. Сначала нужно сформировать группы.',
    ];
  }

  $allowedIds = [];
  $suggested = 'manual';
  $note = '';

  if ($type === 'semis_finals') {
    if ($groupsCount === 1) {
      $n = isset($sizes[0]['n']) ? (int)$sizes[0]['n'] : 0;
      if ($n === 4) {
        $allowedIds = ['top4_semis_finals'];
        $suggested = 'top4_semis_finals';
      } elseif ($n === 5) {
        $allowedIds = ['top2_final_plus_3rd_1g', 'top4_semis_finals'];
        $suggested = 'top2_final_plus_3rd_1g';
        $note = 'Для группы из 5 команд рекомендуем режим без лишнего полуфинала: 1–2 финал, 3–4 матч за 3 место.';
      } elseif ($n >= 6) {
        $allowedIds = ['blocks4_all_places_1g', 'top4_semis_finals'];
        $suggested = 'blocks4_all_places_1g';
        $note = 'Для одной большой группы рекомендуем режим розыгрыша всех мест.';
      } else {
        $allowedIds = ['manual'];
        $suggested = 'manual';
        $note = 'Для такого размера одной группы автоматический режим не задан. Нужна ручная схема.';
      }
    } elseif ($groupsCount === 2) {
      $allowedIds = ['blocks4_all_places_2g'];
      $suggested = 'blocks4_all_places_2g';
    } elseif ($groupsCount === 3) {
      $allowedIds = ['blocks4_all_places_3g'];
      $suggested = 'blocks4_all_places_3g';
    } else {
      $allowedIds = ['manual'];
      $suggested = 'manual';
      $note = 'Для такого числа групп автоматический режим не задан. Нужна ручная схема.';
    }
  } elseif ($type === 'place_matches') {
    if ($groupsCount === 1) {
      $allowedIds = ['blocks4_all_places_1g'];
      $suggested = 'blocks4_all_places_1g';
    } elseif ($groupsCount === 2) {
      $allowedIds = ['ties_all_places_2g', 'blocks4_all_places_2g'];
      $suggested = 'ties_all_places_2g';
    } elseif ($groupsCount === 3) {
      $allowedIds = ['blocks4_all_places_3g'];
      $suggested = 'blocks4_all_places_3g';
    } else {
      $allowedIds = ['manual'];
      $suggested = 'manual';
      $note = 'Для такого числа групп автоматический режим не задан. Нужна ручная схема.';
    }
  } elseif ($type === 'festival') {
    $byType = playoff_mode_items_for_type_groups('festival', max(1, $groupsCount));
    if ($byType) {
      $allowedIds = array_values(array_map(static fn(array $x): string => (string)$x['id'], $byType));
      $suggested = in_array('canon_festival', $allowedIds, true) ? 'canon_festival' : ($allowedIds[0] ?? 'manual');
    } else {
      $allowedIds = ['manual'];
      $suggested = 'manual';
      $note = 'Для festival-режима автоматическая схема не найдена. Нужна ручная схема.';
    }
  } elseif ($type === 'quarter_semis_finals') {
    $allowedIds = ['manual'];
    $suggested = 'manual';
    $note = 'Режим 1/4 + 1/2 + финалы ещё не внедрён в генератор. Пока только manual.';
  } else {
    $allowedIds = ['manual'];
    $suggested = 'manual';
    $note = 'Неизвестный тип плей-офф. Пока только manual.';
  }

  $allowedIds = array_values(array_unique(array_filter($allowedIds, static function(string $id) use ($groupsCount, $type): bool {
    return playoff_mode_exists($id)
      && playoff_mode_allowed_for_groups($id, $groupsCount)
      && playoff_mode_allowed_for_type($id, $type);
  })));

  if (!$allowedIds) {
    $allowedIds = ['manual'];
    $suggested = 'manual';
    if ($note === '') $note = 'Для текущей комбинации тип/группы автоматическая схема не найдена. Нужна ручная схема.';
  }

  if (!in_array($suggested, $allowedIds, true)) {
    $suggested = $allowedIds[0];
  }

  $all = playoff_modes_all();
  $allowedItems = [];
  foreach ($allowedIds as $id) {
    $m = $all[$id] ?? ['title' => $id, 'implemented' => false, 'types' => []];
    $allowedItems[] = [
      'id' => (string)$id,
      'title' => (string)($m['title'] ?? $id),
      'implemented' => (bool)($m['implemented'] ?? false),
      'types' => array_values(array_map('strval', (array)($m['types'] ?? []))),
      'recommended' => ($id === $suggested),
    ];
  }

  return [
    'allowed_ids' => $allowedIds,
    'allowed_items' => $allowedItems,
    'suggested_mode' => $suggested,
    'note' => $note,
  ];
}

$rows = [];
foreach ($cats as $c) {
  $cat = (string)($c['category'] ?? '');
  if ($cat === '') continue;

  $variants = [];
  foreach ($groupCounts as $k=>$gc) {
    [$kCat, $kVar] = explode('|', $k, 2);
    if ($kCat === $cat) $variants[] = (int)$kVar;
  }
  $variants = array_values(array_unique($variants));
  sort($variants);
  if (!$variants) $variants = [1];

  foreach ($variants as $v) {
    $key = $cat.'|'.$v;
    $gCount = $groupCounts[$key] ?? 0;
    $matrix = recommend_mode_and_matrix($c, (int)$gCount, $groupSizes[$key] ?? []);
    $applied = $plans[$key] ?? null;

    if ($applied) {
      $mode = (string)($applied['playoff_mode'] ?? 'none');
      if (!in_array($mode, $matrix['allowed_ids'], true)) {
        $applied['playoff_mode'] = (string)$matrix['suggested_mode'];
        if ($matrix['note'] === '') {
          $matrix['note'] = 'Сохранённый режим не подходит текущему типу/варианту. Подставлен рекомендуемый.';
        }
      }
    }

    $rows[] = [
      'category'=>(int)$cat,
      'variant'=>(int)$v,
      'groups_count'=>(int)$gCount,
      'groups'=>$groupSizes[$key] ?? [],
      'defaults'=>[
        'playoff_enabled'=>(int)($c['playoff_enabled'] ?? 0),
        'playoff_days'=>(int)($c['playoff_days'] ?? 0),
        'playoff_type'=>(string)($c['playoff_type'] ?? ''),
        'max_matches_per_day'=>(int)($c['max_matches_per_day'] ?? 1),
      ],
      'applied'=>$applied,
      'suggested'=>[
        'playoff_enabled'=>(int)($c['playoff_enabled'] ?? 0),
        'playoff_days'=>(int)($c['playoff_days'] ?? 0),
        'playoff_mode'=>(string)$matrix['suggested_mode'],
      ],
      'recommended_mode'=>(string)$matrix['suggested_mode'],
      'matrix_note'=>(string)$matrix['note'],
      'allowed_modes'=>$matrix['allowed_ids'],
      'allowed_mode_items'=>$matrix['allowed_items'],
      'has_groups'=>((int)$gCount > 0),
    ];
  }
}

respond_json([
  'ok'=>true,
  'stage'=>$stage,
  'rows'=>$rows,
  'warnings'=> $tableMissing ? ['stage_playoff_plan table missing: run migration SQL'] : [],
]);
