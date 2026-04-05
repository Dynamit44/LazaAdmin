<?php
declare(strict_types=1);

// Generate / rebuild playoff matches in `matches` (phase = 'playoff')
// Works AFTER groups were formed. Creates placeholder participants (GE:*) and bracket refs (W:/L:).

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_schema.php';
require_once __DIR__ . '/_playoff_modes.php';

header('Content-Type: application/json; charset=UTF-8');

function respond(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_payload(): array {
  $raw = file_get_contents('php://input');
  if ($raw !== false && trim($raw) !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
  }
  // fallback
  return $_POST ?: $_GET;
}

function seed_ge(string $groupCode, int $pos): array {
  return [
    'id'    => null,
    'label' => $groupCode . (string)$pos,
    'ref'   => 'GE:' . $groupCode . ':' . (string)$pos,
  ];
}

function seed_ref(string $label, string $ref): array {
  return [
    'id'    => null,
    'label' => $label,
    'ref'   => $ref,
  ];
}

function seed_team(int $squadId, string $name = ''): array {
  return [
    'id'    => $squadId,
    'label' => $name !== '' ? $name : null,
    'ref'   => null,
  ];
}

/**
 * Anti-rematch для 4 команд: выбираем пары так, чтобы минимизировать матчи одной группы.
 * Структура входа: ['team'=>seed_team(...), 'group'=>'A'|'B'|'C'...]
 * Возврат: [[sX,sY],[sZ,sW]]
 */
function canon_best_pairs4(array $s1, array $s2, array $s3, array $s4): array {
  $cands = [
    [[$s1,$s4],[$s2,$s3]],
    [[$s1,$s3],[$s2,$s4]],
    [[$s1,$s2],[$s3,$s4]],
  ];
  $best = $cands[0];
  $bestScore = 1e9;

  foreach ($cands as $idx => $pairs) {
    $rem = 0;
    foreach ($pairs as $p) {
      $g1 = (string)($p[0]['group'] ?? '');
      $g2 = (string)($p[1]['group'] ?? '');
      if ($g1 !== '' && $g1 === $g2) $rem++;
    }
    $score = $rem * 100 + $idx;
    if ($score < $bestScore) { $bestScore = $score; $best = $pairs; }
  }
  return $best;
}

function groups_info(PDO $pdo, int $stageId, int $category, int $variant): array {
  $sql = "SELECT group_code, COUNT(*) AS cnt
          FROM group_entries
          WHERE stage_id=? AND category=? AND variant=?
          GROUP BY group_code
          ORDER BY group_code";
  $st = $pdo->prepare($sql);
  $st->execute([$stageId, $category, $variant]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $out = [];
  foreach ($rows as $r) {
    $gc = (string)$r['group_code'];
    $out[$gc] = (int)$r['cnt'];
  }
  return $out; // [A=>4, B=>4]
}

function fetch_plan(PDO $pdo, int $stageId, int $category, int $variant): ?array {
  $st = $pdo->prepare("SELECT * FROM stage_playoff_plan WHERE stage_id=? AND category=? AND variant=? LIMIT 1");
  $st->execute([$stageId, $category, $variant]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function save_plan(PDO $pdo, int $stageId, int $category, int $variant, bool $enabled, int $days, string $mode): void {
  $st = $pdo->prepare("INSERT INTO stage_playoff_plan (stage_id, category, variant, groups_count, playoff_enabled, playoff_days, playoff_mode, meta_json, updated_at)
    VALUES (?, ?, ?, 0, ?, ?, ?, '', NOW())
    ON DUPLICATE KEY UPDATE playoff_enabled=VALUES(playoff_enabled), playoff_days=VALUES(playoff_days), playoff_mode=VALUES(playoff_mode), updated_at=NOW()" );
  $st->execute([$stageId, $category, $variant, $enabled ? 1 : 0, $days, $mode]);
}

function delete_existing(PDO $pdo, int $stageId, int $category, int $variant): int {
  $st = $pdo->prepare("DELETE FROM matches WHERE stage_id=? AND category=? AND variant=? AND phase='playoff'");
  $st->execute([$stageId, $category, $variant]);
  return $st->rowCount();
}

function insert_matches(PDO $pdo, int $tournamentId, int $stageId, int $category, int $variant, array $matches): int {
  $sql = "INSERT INTO matches
    (tournament_id, stage_id, category, variant, group_code, round_no,
     home_squad_id, away_squad_id, phase, code, home_label, away_label, home_ref, away_ref)
    VALUES
    (:tid,:sid,:cat,:var,:gcode,:round,:hs,:as,'playoff',:code,:hl,:al,:href,:aref)";

  $st = $pdo->prepare($sql);
  $created = 0;

  foreach ($matches as $m) {
    $home = $m['home'];
    $away = $m['away'];

    $params = [
      ':tid'   => $tournamentId,
      ':sid'   => $stageId,
      ':cat'   => $category,
      ':var'   => $variant,
      // `group_code` in matches is NOT NULL in current schema. For playoff rows we use a dedicated marker.
      // Keep it short (safe even if column is CHAR(1)).
      ':gcode' => 'P',
      ':round' => (int)($m['round_no'] ?? 1),
      ':hs'    => $home['id'],
      ':as'    => $away['id'],
      ':code'  => (string)$m['code'],
      ':hl'    => $home['id'] ? null : (string)($home['label'] ?? null),
      ':al'    => $away['id'] ? null : (string)($away['label'] ?? null),
      ':href'  => (string)($home['ref'] ?? null),
      ':aref'  => (string)($away['ref'] ?? null),
    ];

    $st->execute($params);
    $created += 1;
  }

  return $created;
}

function gen_ties_all_places_2g(array $groups): array {
  $g = array_keys($groups);
  sort($g);
  $ga = $g[0];
  $gb = $g[1];

  $n = min($groups[$ga], $groups[$gb]);
  $matches = [];
  for ($p=1; $p <= $n; $p++) {
    $matches[] = [
      'code' => 'T' . $p,
      'round_no' => 1,
      'home' => seed_ge($ga, $p),
      'away' => seed_ge($gb, $p),
    ];
  }

  $warnings = [];
  if ($groups[$ga] !== $groups[$gb]) {
    $warnings[] = "Группы разного размера ({$ga}={$groups[$ga]}, {$gb}={$groups[$gb]}). Сформированы стыки только до места {$n}.";
  }

  return [$matches, $warnings];
}

function gen_blocks4_all_places_2g(array $groups): array {
  $g = array_keys($groups);
  sort($g);
  $ga = $g[0];
  $gb = $g[1];

  $nA = (int)$groups[$ga];
  $nB = (int)$groups[$gb];

  // ВАЖНО: этот режим по канону только для симметричных групп (4+4, 5+5, 3+3 и т.п.)
  // "Ломаные" (4+5, 4+3) должны идти через canon_festival или "стыки+хвост".
  if ($nA !== $nB) {
    throw new RuntimeException("Режим '2 группы: блоки по 4' требует равные группы. Сейчас {$ga}={$nA}, {$gb}={$nB}. Используйте Canon: фестивальный плей-офф.");
  }

  $n = $nA; // = $nB
  $matches = [];
  $warnings = [];

  $blockIdx = 0;
  $placeStart = 1;

  // Блоки по 4 команды формируем из пар мест: (1,2)->места 1-4; (3,4)->5-8; (5,6)->9-12...
  for ($r = 1; $r + 1 <= $n; $r += 2) {
    $r2 = $r + 1;
    $blockIdx++;

    // Красивые коды под "матчи за места": P1-4, P5-8, P9-12...
    $prefix = 'P' . $placeStart . '-' . ($placeStart + 3);

    $sf1 = $prefix . '-SF1';
    $sf2 = $prefix . '-SF2';

    // Semis (round 1): A(r)–B(r+1), B(r)–A(r+1)
    $matches[] = [
      'code' => $sf1,
      'round_no' => 1,
      'home' => seed_ge($ga, $r),
      'away' => seed_ge($gb, $r2),
    ];
    $matches[] = [
      'code' => $sf2,
      'round_no' => 1,
      'home' => seed_ge($gb, $r),
      'away' => seed_ge($ga, $r2),
    ];

    // Finals (round 2): матч за верхние места блока и матч за нижние места блока
    $matches[] = [
      'code' => $prefix . '-F',
      'round_no' => 2,
      'home' => seed_ref('W ' . $sf1, 'W:' . $sf1),
      'away' => seed_ref('W ' . $sf2, 'W:' . $sf2),
    ];
    $matches[] = [
      'code' => $prefix . '-3',
      'round_no' => 2,
      'home' => seed_ref('L ' . $sf1, 'L:' . $sf1),
      'away' => seed_ref('L ' . $sf2, 'L:' . $sf2),
    ];

    $placeStart += 4;
  }

  // Хвост для нечётного числа команд в группе (пример: 5+5) -> матч за последние 2 места: A5 vs B5
  if (($n % 2) === 1) {
    $prefix = 'P' . $placeStart . '-' . ($placeStart + 1);
    $matches[] = [
      'code' => $prefix,
      'round_no' => 1,
      'home' => seed_ge($ga, $n),
      'away' => seed_ge($gb, $n),
    ];
  }

  return [$matches, $warnings];
}

try {
  $pdo = db();

  $payload = read_payload();
  $stageId  = (int)($payload['stage_id'] ?? 0);
  $category = (int)($payload['category'] ?? 0);
  $variant  = (int)($payload['variant'] ?? 1);

  if ($stageId <= 0 || $category <= 0) {
    respond(['ok'=>false,'error'=>'stage_id/category required'], 400);
  }

  // Ensure schema for playoff columns in matches (if your _schema.php has these ensures)
  if (function_exists('ensure_matches_playoff_columns')) {
    ensure_matches_playoff_columns($pdo);
  }
  if (function_exists('ensure_stage_playoff_plan')) {
    ensure_stage_playoff_plan($pdo);
  }

  // Tournament id from stages
  $st = $pdo->prepare("SELECT tournament_id FROM stages WHERE id=? LIMIT 1");
  $st->execute([$stageId]);
  $tid = (int)($st->fetchColumn() ?: 0);
  if ($tid <= 0) {
    respond(['ok'=>false,'error'=>'stage not found'], 404);
  }

  lc_require_tournament_not_archived($pdo, $tid);

  $plan = fetch_plan($pdo, $stageId, $category, $variant);

  // Allow payload override (from UI selects)
  $mode = (string)($payload['mode'] ?? ($plan['playoff_mode'] ?? ''));
  $days = (int)($payload['playoff_days'] ?? ($plan['playoff_days'] ?? 1));
  $enabled = (int)($plan['playoff_enabled'] ?? 1) === 1;

  if ($mode === '' || $mode === 'none') {
    respond(['ok'=>false,'error'=>'playoff mode not set'], 400);
  }

  // Persist current mode/days (so UI stays consistent)
  save_plan($pdo, $stageId, $category, $variant, $enabled, max(0,$days), $mode);

  $groups = groups_info($pdo, $stageId, $category, $variant);
  $groupsCount = count($groups);

  // validate mode via single source of truth
  if (!playoff_mode_exists($mode)) {
    respond(['ok'=>false,'error'=>'bad playoff_mode'], 400);
  }
  if (!playoff_mode_allowed_for_groups($mode, (int)$groupsCount)) {
    respond(['ok'=>false,'error'=>'playoff_mode_not_allowed_for_groups'], 400);
  }

  if ($groupsCount === 0) {
    respond(['ok'=>false,'error'=>'no groups found for this category/variant'], 400);
  }

  $warnings = [];
  $matches = [];

  if ($groupsCount === 2) {
    if ($mode === 'ties_all_places_2g') {
      [$matches, $warnings] = gen_ties_all_places_2g($groups);
    } elseif ($mode === 'blocks4_all_places_2g') {
      [$matches, $warnings] = gen_blocks4_all_places_2g($groups);
    } else {
      respond(['ok'=>false,'error'=>'unsupported mode for 2 groups: '.$mode], 400);
    }
  } elseif ($groupsCount === 1) {
    $gc = array_key_first($groups);
    $n = (int)$groups[$gc];

    if ($mode === 'top4_semis_finals') {
      if ($n < 4) {
        respond(['ok'=>false,'error'=>'need at least 4 teams for top4_semis_finals'], 400);
      }

      $sf1 = 'SF1';
      $sf2 = 'SF2';

      $matches = [
        ['code'=>$sf1,'round_no'=>1,'home'=>seed_ge($gc,1),'away'=>seed_ge($gc,4)],
        ['code'=>$sf2,'round_no'=>1,'home'=>seed_ge($gc,2),'away'=>seed_ge($gc,3)],
        ['code'=>'F','round_no'=>2,'home'=>seed_ref('W '.$sf1,'W:'.$sf1),'away'=>seed_ref('W '.$sf2,'W:'.$sf2)],
        ['code'=>'3','round_no'=>2,'home'=>seed_ref('L '.$sf1,'L:'.$sf1),'away'=>seed_ref('L '.$sf2,'L:'.$sf2)],
      ];

      if ($n > 4) {
        $warnings[] = 'В режиме top4_semis_finals участвуют только места 1-4. Остальные команды без плей-офф матчей.';
      }
    } elseif ($mode === 'top2_final_plus_3rd_1g') {
      if ($n < 5) {
        respond(['ok'=>false,'error'=>'need at least 5 teams for top2_final_plus_3rd_1g'], 400);
      }

      $matches = [
        ['code'=>'F','round_no'=>1,'home'=>seed_ge($gc,1),'away'=>seed_ge($gc,2)],
        ['code'=>'3','round_no'=>1,'home'=>seed_ge($gc,3),'away'=>seed_ge($gc,4)],
      ];

      if ($n > 5) {
        $warnings[] = 'В режиме top2_final_plus_3rd_1g участвуют только места 1-4. Команды с 5 места и ниже остаются по таблице.';
      } else {
        $warnings[] = '5 место фиксируется по итоговой таблице группы без дополнительного матча.';
      }
    } else {
      respond(['ok'=>false,'error'=>'unsupported mode for 1 group: '.$mode], 400);
    }
  } elseif ($groupsCount === 3) {
// пока считаем blocks4_all_places_3g алиасом canon_festival (для UI)
if ($mode !== 'canon_festival' && $mode !== 'blocks4_all_places_3g') {
  respond(['ok'=>false,'error'=>'unsupported mode for 3 groups: '.$mode], 400);
}

    require_once __DIR__ . '/_canon_ranker.php';
    $rank = canon_rank_build($pdo, $stageId, $category, $variant, true);

    // Если ранкер видит абсолютную ничью -> требуем ручную расстановку (жребий)
    $needsGroups = [];
    if (!empty($rank['groups_meta']) && is_array($rank['groups_meta'])) {
      foreach ($rank['groups_meta'] as $gc => $meta) {
        $warns = $meta['warnings'] ?? [];
        if (is_array($warns)) {
          foreach ($warns as $w) {
            if (is_string($w) && stripos($w, 'нужен жребий') !== false) {
              $needsGroups[] = (string)$gc;
              break;
            }
          }
        }
      }
    }
    if ($needsGroups) {
      respond([
        'ok' => false,
        'needs_manual' => true,
        'needs_manual_groups' => array_values(array_unique($needsGroups)),
        'needs_manual_blocks' => [],
        'error' => 'Canon Ranker: нужен жребий/manual_place',
      ], 409);
    }

    $N = (int)($rank['N'] ?? 0);
    if ($N !== 9) {
      respond(['ok'=>false,'error'=>"canon_festival (3 groups): only N=9 implemented yet (got {$N})"], 501);
    }

    // Нормализуем seeds 1..9
$seeds = $rank['seeds'] ?? [];
$by = [];

// В ранкере сид = ключ массива + поле seed_place (а не seed)
foreach ($seeds as $key => $s) {
  $k = (int)($s['seed_place'] ?? $s['seed'] ?? $key);
  if ($k > 0) $by[$k] = $s;
}

for ($i=1; $i<=9; $i++) {
  if (!isset($by[$i])) respond(['ok'=>false,'error'=>'bad seeds: missing '.$i], 500);
}

    // Пакуем сиды с group для anti-rematch
    $S = [];
    for ($i=1; $i<=9; $i++) {
      $S[$i] = [
        'team'  => seed_team((int)$by[$i]['squad_id'], (string)($by[$i]['name'] ?? '')),
        'group' => (string)($by[$i]['group'] ?? $by[$i]['group_code'] ?? ''),
      ];
    }

    // ---- Места 1–4 ----
    $pairs = canon_best_pairs4($S[1], $S[2], $S[3], $S[4]);
    $sf1 = 'P1-4-SF1';
    $sf2 = 'P1-4-SF2';

    $matches[] = ['code'=>$sf1,'round_no'=>1,'home'=>$pairs[0][0]['team'],'away'=>$pairs[0][1]['team']];
    $matches[] = ['code'=>$sf2,'round_no'=>1,'home'=>$pairs[1][0]['team'],'away'=>$pairs[1][1]['team']];
    $matches[] = ['code'=>'P1-4-F','round_no'=>2,'home'=>seed_ref('W '.$sf1,'W:'.$sf1),'away'=>seed_ref('W '.$sf2,'W:'.$sf2)];
    $matches[] = ['code'=>'P1-4-3','round_no'=>2,'home'=>seed_ref('L '.$sf1,'L:'.$sf1),'away'=>seed_ref('L '.$sf2,'L:'.$sf2)];

    // ---- Места 5–6 ----
    $matches[] = ['code'=>'P5-6','round_no'=>1,'home'=>$S[5]['team'],'away'=>$S[6]['team']];

    // ---- Места 7–9 (цепочка): 8–9, победитель vs 7 ----
    $t1 = 'P7-9-T1';
    $t2 = 'P7-9-T2';
    $matches[] = ['code'=>$t1,'round_no'=>1,'home'=>$S[8]['team'],'away'=>$S[9]['team']];
    $matches[] = ['code'=>$t2,'round_no'=>2,'home'=>$S[7]['team'],'away'=>seed_ref('W '.$t1,'W:'.$t1)];

    // loser T1 = 9 место, winner/loser T2 = 7/8 место

  } else {
    respond(['ok'=>false,'error'=>'3+ groups not implemented yet'], 400);
  }

  if (!$matches) {
    respond(['ok'=>false,'error'=>'no matches generated (check inputs)'], 500);
  }

  $deleted = delete_existing($pdo, $stageId, $category, $variant);
  $created = insert_matches($pdo, $tid, $stageId, $category, $variant, $matches);

  respond([
    'ok' => true,
    'deleted' => $deleted,
    'created' => $created,
    'warnings' => $warnings,
  ]);

} catch (Throwable $e) {
  respond(['ok'=>false,'error'=>$e->getMessage()], 500);
}
