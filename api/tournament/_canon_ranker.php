<?php
declare(strict_types=1);

/**
 * Canon Ranker v2 (мозг)
 *
 * Истина:
 * - "места в группе" не хранятся, а ВЫЧИСЛЯЮТСЯ по results (matches+results)
 * - manual_place — ДОВОДЧИК (жребий/ручная корректировка), применяется только:
 *     a) как финальный порядок, если заполнен полностью 1..K без дыр/дублей
 *     b) иначе — только как последний tie-break для неразрешимых ничейных блоков
 *
 * Canon seeding:
 * - tier-based: сначала все 1-е места групп, потом все 2-е, потом 3-и...
 * - внутри каждого tier сортируем по "за матч": PPG, GDpg, GFpg, затем детерминированный tie-break.
 */

function canon_rank_build(PDO $pdo, int $stageId, $category, int $variant, bool $useManual = true): array {
  // 1) Состав групп
  [$groups, $squadInfo, $squadToGroup] = canon_rank_fetch_groups($pdo, $stageId, $category, $variant);

  // 2) Матчи/результаты для расчёта статов
  $matches = canon_rank_fetch_group_matches($pdo, $stageId, $category, $variant);

  // 3) manual_place map (если используем)
  $manualMap = [];
  if ($useManual) {
    $manualMap = canon_rank_fetch_manual_place_map($pdo, $stageId, $category, $variant);
  }

  // 4) Статы по командам (по всем матчам групп)
  $statsBySquad = canon_rank_calc_stats($matches);

  // 5) По каждой группе — вычисляем порядок мест (auto + h2h + manual доводчик)
  $groupMeta = [];
  $groupOrders = []; // groupCode => [squadId1,squadId2,...] по местам 1..n

  foreach ($groups as $g => $rows) {
    [$order, $warnings, $needBlocks] = canon_rank_auto_order_group((string)$g, $rows, $statsBySquad, $matches, $manualMap);
    $groupOrders[$g] = $order;
    $groupMeta[$g] = ['mode' => 'auto', 'warnings' => $warnings, 'needs_manual_blocks' => $needBlocks];
  }

  // 6) Строим tiers: все 1-е места, потом все 2-е и т.д.
  $tiers = [];
  $maxSize = 0;
  foreach ($groupOrders as $g => $order) $maxSize = max($maxSize, count($order));

  for ($place = 1; $place <= $maxSize; $place++) {
    $tier = [];
    foreach ($groupOrders as $g => $order) {
      if (isset($order[$place-1])) {
        $sid = (int)$order[$place-1];
        $tier[] = [
          'group_code' => (string)$g,
          'place_in_group' => (int)$place,
          'squad_id' => $sid,
          'squad_name' => (string)($squadInfo[$sid]['name'] ?? ('#'.$sid)),
          'stats' => $statsBySquad[$sid] ?? canon_rank_empty_stats(),
        ];
      }
    }

    // сортируем внутри tier по "за матч": PPG, GDpg, GFpg, затем детерминированно
    usort($tier, function($a, $b) {
      $sa = $a['stats']; $sb = $b['stats'];
      if ($sa['ppg'] !== $sb['ppg']) return ($sa['ppg'] < $sb['ppg']) ? 1 : -1;
      if ($sa['gdpg'] !== $sb['gdpg']) return ($sa['gdpg'] < $sb['gdpg']) ? 1 : -1;
      if ($sa['gfpg'] !== $sb['gfpg']) return ($sa['gfpg'] < $sb['gfpg']) ? 1 : -1;

      // детерминированный "жребий": squad_id
      return ((int)$a['squad_id'] < (int)$b['squad_id']) ? -1 : 1;
    });

    $tiers[$place] = $tier;
  }

  // 7) Склеиваем tiers в общий посев 1..N
  $seeds = [];
  $seedPlace = 0;

  foreach ($tiers as $tierNo => $tierList) {
    foreach ($tierList as $e) {
      $seedPlace++;
      $e['seed_place'] = $seedPlace;
      $e['tier'] = (int)$tierNo;
      $seeds[$seedPlace] = $e;
    }
  }

  return [
    'ok' => true,
    'N' => count($seeds),
    'seeds' => $seeds,
    'tiers' => $tiers,
    'groups_meta' => $groupMeta,

    // needs_manual is TRUE if at least one group has an unresolved absolute tie block (no manual_place set)
    'needs_manual' => canon_rank_groups_need_manual($groupMeta),
    'needs_manual_groups' => canon_rank_need_manual_groups($groupMeta),
    'needs_manual_blocks' => canon_rank_need_manual_blocks($groupMeta),
  ];
}

function canon_rank_fetch_groups(PDO $pdo, int $stageId, $category, int $variant): array {
  $sql = "
    SELECT ge.group_code, ge.squad_id, ge.pos, ge.manual_place, s.name
    FROM group_entries ge
    JOIN squads s ON s.id = ge.squad_id
    WHERE ge.stage_id = :stage_id AND ge.category = :category AND ge.variant = :variant
    ORDER BY ge.group_code, ge.pos
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':stage_id' => $stageId,
    ':category' => $category,
    ':variant' => $variant,
  ]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $groups = [];
  $squadInfo = [];
  $squadToGroup = [];

  foreach ($rows as $r) {
    $g = (string)$r['group_code'];
    $sid = (int)$r['squad_id'];

    if (!isset($groups[$g])) $groups[$g] = [];
    $groups[$g][] = $r;

    $squadInfo[$sid] = ['name' => (string)$r['name']];
    $squadToGroup[$sid] = $g;
  }

  if (!$groups) {
    throw new RuntimeException('Canon Ranker: no group_entries found');
  }

  return [$groups, $squadInfo, $squadToGroup];
}

function canon_rank_fetch_manual_place_map(PDO $pdo, int $stageId, $category, int $variant): array {
  $sql = "
    SELECT squad_id, manual_place
    FROM group_entries
    WHERE stage_id = :stage_id AND category = :category AND variant = :variant
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':stage_id' => $stageId,
    ':category' => $category,
    ':variant' => $variant,
  ]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $map = [];
  foreach ($rows as $r) {
    $sid = (int)$r['squad_id'];
    $mp = $r['manual_place'];
    $map[$sid] = ($mp === null) ? null : (int)$mp;
  }
  return $map;
}

function canon_rank_fetch_group_matches(PDO $pdo, int $stageId, $category, int $variant): array {
  $sql = "
    SELECT m.id, m.home_squad_id, m.away_squad_id, m.phase, m.group_code, m.round_no,
           r.home_goals, r.away_goals
    FROM matches m
    LEFT JOIN results r ON r.match_id = m.id
    WHERE m.stage_id = :stage_id AND m.category = :category AND m.variant = :variant
      AND m.phase = 'group'
    ORDER BY m.group_code, m.round_no, m.id
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':stage_id' => $stageId,
    ':category' => $category,
    ':variant' => $variant,
  ]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function canon_rank_calc_stats(array $matches): array {
  $stats = [];
  foreach ($matches as $m) {
    $h = (int)$m['home_squad_id'];
    $a = (int)$m['away_squad_id'];

    if (!isset($stats[$h])) $stats[$h] = canon_rank_empty_stats();
    if (!isset($stats[$a])) $stats[$a] = canon_rank_empty_stats();

    $hg = $m['home_goals']; $ag = $m['away_goals'];
    if ($hg === null || $ag === null) continue; // матч без результата

    $hg = (int)$hg; $ag = (int)$ag;

    // played
    $stats[$h]['played']++;
    $stats[$a]['played']++;

    // gf/ga/gd
    $stats[$h]['gf'] += $hg;
    $stats[$h]['ga'] += $ag;
    $stats[$h]['gd'] += ($hg - $ag);

    $stats[$a]['gf'] += $ag;
    $stats[$a]['ga'] += $hg;
    $stats[$a]['gd'] += ($ag - $hg);

    // points
    if ($hg > $ag) {
      $stats[$h]['pts'] += 3; $stats[$h]['wins']++;
      $stats[$a]['losses']++;
    } elseif ($hg < $ag) {
      $stats[$a]['pts'] += 3; $stats[$a]['wins']++;
      $stats[$h]['losses']++;
    } else {
      $stats[$h]['pts'] += 1; $stats[$a]['pts'] += 1;
      $stats[$h]['draws']++; $stats[$a]['draws']++;
    }
  }

  // per-game metrics
  foreach ($stats as $sid => $s) {
    $p = (int)$s['played'];
    $stats[$sid]['ppg'] = ($p > 0) ? ($s['pts'] / $p) : 0.0;
    $stats[$sid]['gdpg'] = ($p > 0) ? ($s['gd'] / $p) : 0.0;
    $stats[$sid]['gfpg'] = ($p > 0) ? ($s['gf'] / $p) : 0.0;
  }

  return $stats;
}

function canon_rank_auto_order_group(string $groupCode, array $groupRows, array $statsBySquad, array $matches, array $manualMap): array {
  $warnings = [];
  $needsManualBlocks = [];

  $squadIds = [];
  foreach ($groupRows as $r) $squadIds[] = (int)$r['squad_id'];

  // 1) Сортируем по базовым ключам
  usort($squadIds, function($a, $b) use ($statsBySquad) {
    $sa = $statsBySquad[$a] ?? canon_rank_empty_stats();
    $sb = $statsBySquad[$b] ?? canon_rank_empty_stats();

    if ($sa['pts'] !== $sb['pts']) return ($sa['pts'] < $sb['pts']) ? 1 : -1;
    if ($sa['wins'] !== $sb['wins']) return ($sa['wins'] < $sb['wins']) ? 1 : -1;
    if ($sa['gd'] !== $sb['gd']) return ($sa['gd'] < $sb['gd']) ? 1 : -1;
    if ($sa['gf'] !== $sb['gf']) return ($sa['gf'] < $sb['gf']) ? 1 : -1;

    // пока tie — squad_id (потом tie-блоки разрулим h2h/manual)
    return ((int)$a < (int)$b) ? -1 : 1;
  });

  // 2) Находим tie-блоки по (pts,wins,gd,gf) — именно тут нужен h2h/manual
  $blocks = canon_rank_find_tie_blocks($squadIds, function($sid) use ($statsBySquad) {
    $s = $statsBySquad[$sid] ?? canon_rank_empty_stats();
    return implode('|', [(int)$s['pts'], (int)$s['wins'], (int)$s['gd'], (int)$s['gf']]);
  });

  foreach ($blocks as $block) {
    if (count($block) < 2) continue;

    // 2.1 h2h mini-league по матчам внутри блока
    $h2h = canon_rank_calc_h2h($block, $matches);
    usort($block, function($a, $b) use ($h2h) {
      $sa = $h2h[$a] ?? canon_rank_empty_stats();
      $sb = $h2h[$b] ?? canon_rank_empty_stats();

      if ($sa['pts'] !== $sb['pts']) return ($sa['pts'] < $sb['pts']) ? 1 : -1;
      if ($sa['gd'] !== $sb['gd']) return ($sa['gd'] < $sb['gd']) ? 1 : -1;
      if ($sa['gf'] !== $sb['gf']) return ($sa['gf'] < $sb['gf']) ? 1 : -1;

      return ((int)$a < (int)$b) ? -1 : 1;
    });

    // 2.2 Проверяем, осталась ли "абсолютная ничья" после h2h
    $blocks2 = canon_rank_find_tie_blocks($block, function($sid) use ($h2h) {
      $s = $h2h[$sid] ?? canon_rank_empty_stats();
      return implode('|', [(int)$s['pts'], (int)$s['gd'], (int)$s['gf']]);
    });

    foreach ($blocks2 as $blk2) {
      if (count($blk2) < 2) continue;

      // 2.3 manual_place как доводчик (только если задан для этих команд)
      $hasSomeManual = false;
      foreach ($blk2 as $sid) {
        if (isset($manualMap[$sid]) && $manualMap[$sid] !== null) { $hasSomeManual = true; break; }
      }

      if ($hasSomeManual) {
        usort($blk2, function($a, $b) use ($manualMap) {
          $ma = $manualMap[$a] ?? null;
          $mb = $manualMap[$b] ?? null;

          // Если у одного задано, у другого нет — заданное выигрывает (доводчик)
          if ($ma !== null && $mb === null) return -1;
          if ($ma === null && $mb !== null) return 1;

          // Оба заданы — сортируем по manual_place
          if ($ma !== null && $mb !== null && (int)$ma !== (int)$mb) return ((int)$ma < (int)$mb) ? -1 : 1;

          // иначе детерминированно
          return ((int)$a < (int)$b) ? -1 : 1;
        });
      } else {
        // manual нет — это реальная ситуация "нужен жребий"
        $warnings[] = "Group {$groupCode}: абсолютная ничья в блоке squad_ids=[".implode(',', $blk2)."] — нужен жребий/manual_place";
        $needsManualBlocks[] = ['group' => (string)$groupCode, 'squad_ids' => array_values(array_map('intval', $blk2))];
      }

      // применяем перестановку blk2 обратно в block
      $block = canon_rank_apply_block_order($block, $blk2);
    }

    // применяем перестановку block обратно в общий order
    $squadIds = canon_rank_apply_block_order($squadIds, $block);
  }

  return [$squadIds, $warnings, $needsManualBlocks];
}

function canon_rank_find_tie_blocks(array $ids, callable $keyFn): array {
  $out = [];
  $cur = [];
  $prevKey = null;

  foreach ($ids as $sid) {
    $k = (string)$keyFn((int)$sid);

    if ($prevKey === null || $k === $prevKey) {
      $cur[] = (int)$sid;
    } else {
      if (count($cur) > 1) $out[] = $cur;
      $cur = [(int)$sid];
    }
    $prevKey = $k;
  }

  if (count($cur) > 1) $out[] = $cur;
  return $out;
}

function canon_rank_calc_h2h(array $block, array $matches): array {
  $set = array_fill_keys(array_map('intval', $block), true);
  $stats = [];

  foreach ($block as $sid) $stats[(int)$sid] = canon_rank_empty_stats();

  foreach ($matches as $m) {
    $h = (int)$m['home_squad_id'];
    $a = (int)$m['away_squad_id'];

    if (!isset($set[$h]) || !isset($set[$a])) continue;

    $hg = $m['home_goals']; $ag = $m['away_goals'];
    if ($hg === null || $ag === null) continue;

    $hg = (int)$hg; $ag = (int)$ag;

    $stats[$h]['played']++;
    $stats[$a]['played']++;

    $stats[$h]['gf'] += $hg;
    $stats[$h]['ga'] += $ag;
    $stats[$h]['gd'] += ($hg - $ag);

    $stats[$a]['gf'] += $ag;
    $stats[$a]['ga'] += $hg;
    $stats[$a]['gd'] += ($ag - $hg);

    if ($hg > $ag) {
      $stats[$h]['pts'] += 3;
    } elseif ($hg < $ag) {
      $stats[$a]['pts'] += 3;
    } else {
      $stats[$h]['pts'] += 1;
      $stats[$a]['pts'] += 1;
    }
  }

  // per-game metrics для h2h не нужны, но пусть будут нули
  return $stats;
}

function canon_rank_apply_block_order(array $source, array $blockOrdered): array {
  $pos = [];
  foreach ($blockOrdered as $i => $sid) $pos[(int)$sid] = $i;

  $set = array_fill_keys(array_map('intval', $blockOrdered), true);

  // собираем subset как встретился
  $subset = [];
  foreach ($source as $sid) {
    $sid = (int)$sid;
    if (isset($set[$sid])) $subset[] = $sid;
  }
  if (!$subset) return $source;

  // сортируем subset по заданному порядку
  usort($subset, fn($a,$b) => ($pos[$a] < $pos[$b]) ? -1 : 1);

  // вставляем обратно
  $out = [];
  $k = 0;
  foreach ($source as $sid) {
    $sid = (int)$sid;
    if (isset($set[$sid])) {
      $out[] = $subset[$k++];
    } else {
      $out[] = $sid;
    }
  }
  return $out;
}

function canon_rank_empty_stats(): array {
  return [
    'played' => 0,
    'pts' => 0,
    'wins' => 0,
    'draws' => 0,
    'losses' => 0,
    'gf' => 0,
    'ga' => 0,
    'gd' => 0,
    'ppg' => 0.0,
    'gdpg' => 0.0,
    'gfpg' => 0.0,
  ];
}

/** ---------- needs_manual helpers ---------- */

function canon_rank_groups_need_manual(array $groupsMeta): bool {
  foreach ($groupsMeta as $g => $meta) {
    if (!empty($meta['needs_manual_blocks'])) return true;
  }
  return false;
}

function canon_rank_need_manual_groups(array $groupsMeta): array {
  $out = [];
  foreach ($groupsMeta as $g => $meta) {
    if (!empty($meta['needs_manual_blocks'])) $out[] = (string)$g;
  }
  sort($out);
  return $out;
}

function canon_rank_need_manual_blocks(array $groupsMeta): array {
  $out = [];
  foreach ($groupsMeta as $g => $meta) {
    $blocks = $meta['needs_manual_blocks'] ?? [];
    if (is_array($blocks)) {
      foreach ($blocks as $b) {
        $out[] = [
          'group' => (string)($b['group'] ?? $g),
          'squad_ids' => array_values(array_map('intval', (array)($b['squad_ids'] ?? []))),
        ];
      }
    }
  }
  return $out;
}