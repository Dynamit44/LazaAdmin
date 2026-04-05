<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$pdo = db();
$b = read_json_body();

$stageId   = (int)($b['stage_id'] ?? 0);
$category  = trim((string)($b['category'] ?? ''));    // '2013'
$variant   = (int)($b['variant'] ?? 1);               // 1/2/...
$groupSize = (int)($b['group_size'] ?? 5);

if ($stageId <= 0 || $category === '' || $variant <= 0 || $groupSize < 2) {
  respond_json(['ok'=>false,'error'=>'stage_id/category/variant/group_size required'], 400);
}

// stage
$st = $pdo->prepare("SELECT * FROM stages WHERE id = :id LIMIT 1");
$st->execute([':id' => $stageId]);
$stage = $st->fetch();
if (!$stage) respond_json(['ok'=>false,'error'=>'Stage not found'], 404);

$tournamentId  = (int)$stage['tournament_id'];
lc_require_tournament_not_archived($pdo, $tournamentId);
$matchMinutes  = max(1, (int)$stage['match_minutes']);
$breakMinutes  = max(0, (int)$stage['break_minutes']);
$minRestSlots  = max(0, (int)$stage['min_rest_slots']);

$tzName = (string)($stage['timezone'] ?? 'Europe/Moscow');
try { $tz = new DateTimeZone($tzName); } catch(Throwable $e) { $tz = new DateTimeZone('Europe/Moscow'); }

$startDate = (string)$stage['start_date'];
$endDate   = (string)$stage['end_date'];
$dayStart  = (string)$stage['day_start'];
$dayEnd    = (string)$stage['day_end'];

/**
 * 1) Берём разрешённые поля для category+variant
 */
$rf = $pdo->prepare("
  SELECT field_code
  FROM stage_category_fields
  WHERE stage_id=:sid AND category=:cat AND variant=:v
  ORDER BY field_code
");
$rf->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
$fieldCodes = array_map(fn($r)=>$r['field_code'], $rf->fetchAll());

if (!$fieldCodes) {
  respond_json([
    'ok'=>false,
    'error'=>"Нет правил полей для {$category} (variant={$variant}) на этом этапе. Сначала задай правила в field_rules.php.",
  ], 400);
}

/**
 * 2) Маски ресурсов полей (full/12/34/1..4)
 */
$sf = $pdo->prepare("SELECT field_code, units_mask FROM stage_fields WHERE stage_id=:sid");
$sf->execute([':sid'=>$stageId]);
$fieldMask = [];
foreach ($sf->fetchAll() as $r) $fieldMask[$r['field_code']] = (int)$r['units_mask'];

foreach ($fieldCodes as $fc) {
  if (!isset($fieldMask[$fc])) {
    respond_json(['ok'=>false,'error'=>"Field {$fc} не описан в stage_fields. Пересохрани правила."], 400);
  }
}

/**
 * 3) Берём команды только этого category+variant
 */
$qs = $pdo->prepare("
  SELECT s.id, s.name, s.rating
  FROM squads s
  WHERE s.stage_id = :sid AND s.category = :cat AND s.category_variant = :v
  ORDER BY s.rating DESC, s.id ASC
");
$qs->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
$squads = $qs->fetchAll();

if (count($squads) < 2) {
  respond_json(['ok'=>false,'error'=>'Нужно минимум 2 команды в этой категории/варианте'], 400);
}

/**
 * 4) Карта тренеров по команде
 */
$ids = array_map(fn($r)=>(int)$r['id'], $squads);
$coachMap = array_fill_keys($ids, []);
$in = implode(',', array_fill(0, count($ids), '?'));
$qc = $pdo->prepare("SELECT squad_id, coach_id FROM squad_coaches WHERE squad_id IN ($in)");
$qc->execute($ids);
foreach ($qc->fetchAll() as $r) {
  $sid = (int)$r['squad_id'];
  $cid = (int)$r['coach_id'];
  if ($sid > 0 && $cid > 0) $coachMap[$sid][] = $cid;
}
foreach ($coachMap as $sid => $arr) $coachMap[$sid] = array_values(array_unique($arr));

/**
 * 5) Предзагрузка занятости полей и тренеров по времени по ВСЕМ категориям этапа
 * - поле: units_mask (чтобы full блокировал всё)
 * - тренеры: чтобы не было одновременных матчей в разных категориях
 */
$busyMaskByTime = [];      // 'Y-m-d H:i:s' => int mask OR
$busyCoachesByTime = [];   // 'Y-m-d H:i:s' => [coach_id=>true]

$busy1 = $pdo->prepare("
  SELECT sh.start_time, sh.field_code
  FROM schedule sh
  JOIN matches m ON m.id = sh.match_id
  WHERE m.stage_id = :sid
");
$busy1->execute([':sid'=>$stageId]);
foreach ($busy1->fetchAll() as $r) {
  $t = (string)$r['start_time'];
  $fc = (string)$r['field_code'];
  $mask = $fieldMask[$fc] ?? 0;
  $busyMaskByTime[$t] = ($busyMaskByTime[$t] ?? 0) | $mask;
}

$busy2 = $pdo->prepare("
  (SELECT sh.start_time, sc.coach_id
   FROM schedule sh
   JOIN matches m ON m.id = sh.match_id
   JOIN squad_coaches sc ON sc.squad_id = m.home_squad_id
   WHERE m.stage_id = :sid)
  UNION ALL
  (SELECT sh.start_time, sc.coach_id
   FROM schedule sh
   JOIN matches m ON m.id = sh.match_id
   JOIN squad_coaches sc ON sc.squad_id = m.away_squad_id
   WHERE m.stage_id = :sid)
");
$busy2->execute([':sid'=>$stageId]);
foreach ($busy2->fetchAll() as $r) {
  $t = (string)$r['start_time'];
  $cid = (int)$r['coach_id'];
  if ($cid > 0) $busyCoachesByTime[$t][$cid] = true;
}

/**
 * 6) Чистим старую генерацию для stage/category/variant
 */
$pdo->beginTransaction();
try {
  $midStmt = $pdo->prepare("SELECT id FROM matches WHERE stage_id=:sid AND category=:cat AND variant=:v");
  $midStmt->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
  $matchIds = array_map(fn($r)=>(int)$r['id'], $midStmt->fetchAll());

  if ($matchIds) {
    $inM = implode(',', array_fill(0, count($matchIds), '?'));
    $pdo->prepare("DELETE FROM results WHERE match_id IN ($inM)")->execute($matchIds);
    $pdo->prepare("DELETE FROM schedule WHERE match_id IN ($inM)")->execute($matchIds);
    $pdo->prepare("DELETE FROM matches WHERE id IN ($inM)")->execute($matchIds);
  }

  $pdo->prepare("DELETE FROM group_entries WHERE stage_id=:sid AND category=:cat AND variant=:v")
      ->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);

  /**
   * 7) Делим на группы “змейкой”
   */
  $n = count($squads);
  $groupCount = max(1, (int)ceil($n / $groupSize));
  $groups = array_fill(0, $groupCount, []);

  for ($k = 0; $k < $n; $k++) {
    $row = intdiv($k, $groupCount);
    $col = $k % $groupCount;
    $gIndex = ($row % 2 === 0) ? $col : ($groupCount - 1 - $col);
    $groups[$gIndex][] = (int)$squads[$k]['id'];
  }

  $insGE = $pdo->prepare("
    INSERT INTO group_entries(tournament_id, stage_id, category, variant, group_code, squad_id, pos)
    VALUES(:tid,:sid,:cat,:v,:g,:sq,:pos)
  ");
  for ($gi=0; $gi<$groupCount; $gi++) {
    $code = chr(ord('A') + $gi);
    $pos = 1;
    foreach ($groups[$gi] as $sid) {
      $insGE->execute([
        ':tid'=>$tournamentId, ':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant,
        ':g'=>$code, ':sq'=>$sid, ':pos'=>$pos++
      ]);
    }
  }

  /**
   * 8) Круговик по группам
   */
  $insM = $pdo->prepare("
    INSERT INTO matches(tournament_id, stage_id, category, variant, group_code, round_no, home_squad_id, away_squad_id)
    VALUES(:tid,:sid,:cat,:v,:g,:r,:h,:a)
  ");

  $matches = [];
  foreach ($groups as $gi => $teamIds) {
    $gCode = chr(ord('A') + $gi);
    $teams = array_values($teamIds);
    if (count($teams) < 2) continue;
    if (count($teams) % 2 === 1) $teams[] = null;

    $m = count($teams);
    $rounds = $m - 1;

    for ($r=0; $r<$rounds; $r++) {
      for ($i=0; $i<$m/2; $i++) {
        $t1 = $teams[$i];
        $t2 = $teams[$m - 1 - $i];
        if ($t1 === null || $t2 === null) continue;

        if (($r + $i) % 2 === 0) { $home=$t1; $away=$t2; } else { $home=$t2; $away=$t1; }

        $insM->execute([
          ':tid'=>$tournamentId, ':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant,
          ':g'=>$gCode, ':r'=>($r+1), ':h'=>$home, ':a'=>$away
        ]);
        $matchId = (int)$pdo->lastInsertId();

        $allCoaches = array_values(array_unique(array_merge($coachMap[$home] ?? [], $coachMap[$away] ?? [])));
        $matches[] = [
          'match_id'=>$matchId,
          'group_code'=>$gCode,
          'round_no'=>$r+1,
          'home'=>$home,
          'away'=>$away,
          'coach_ids'=>$allCoaches,
          'hard'=>count($allCoaches)
        ];
      }

      // rotation
      $fixed = $teams[0];
      $rest = array_slice($teams, 1);
      $last = array_pop($rest);
      array_unshift($rest, $last);
      $teams = array_merge([$fixed], $rest);
    }
  }

  if (!$matches) {
    $pdo->commit();
    respond_json(['ok'=>false,'error'=>'Матчи не сгенерировались (проверь команды)'], 400);
  }

  /**
   * 9) Тайм-слоты
   */
  $slotLen = $matchMinutes + $breakMinutes;
  $slotTimes = [];

  $d1 = new DateTime($startDate, $tz);
  $d2 = new DateTime($endDate, $tz);

  while ($d1 <= $d2) {
    $dateStr = $d1->format('Y-m-d');
    $cur = new DateTime($dateStr . ' ' . $dayStart, $tz);
    $end = new DateTime($dateStr . ' ' . $dayEnd, $tz);

    while (true) {
      $finish = (clone $cur)->modify("+{$matchMinutes} minutes");
      if ($finish > $end) break;
      $slotTimes[] = clone $cur;
      $cur->modify("+{$slotLen} minutes");
    }
    $d1->modify('+1 day');
  }

  /**
   * 10) Раскладка по разрешённым полям + учёт занятости ресурсов и тренеров
   */
  usort($matches, function($a,$b){
    if ($a['hard'] !== $b['hard']) return $b['hard'] <=> $a['hard'];
    if ($a['round_no'] !== $b['round_no']) return $a['round_no'] <=> $b['round_no'];
    return strcmp($a['group_code'], $b['group_code']);
  });

  $lastSlotSquad = [];
  $lastSlotCoach = [];

  $scheduleRows = [];
  $remaining = $matches;

  for ($slotIndex=0; $slotIndex<count($slotTimes); $slotIndex++) {
    if (!$remaining) break;

    $timeStr = $slotTimes[$slotIndex]->format('Y-m-d H:i:s');

    // занятость этапа в этот момент
    $usedMask = $busyMaskByTime[$timeStr] ?? 0;
    $usedCoaches = $busyCoachesByTime[$timeStr] ?? [];

    // в этом слоте для текущей категории команды не должны пересекаться
    $usedSquads = [];

    // пытаемся заполнить все разрешённые поля (которые свободны по маске)
    foreach ($fieldCodes as $fc) {
      if (!$remaining) break;

      $mask = $fieldMask[$fc];

      // если ресурсы пересекаются с уже занятым (например full или 12 vs 1) — это поле недоступно
      if (($usedMask & $mask) !== 0) continue;

      // выберем лучший матч под этот слот/поле
      $bestIdx = -1;
      $bestScore = -999999;

      foreach ($remaining as $idx => $m) {
        $h = $m['home']; $a = $m['away'];

        if (isset($usedSquads[$h]) || isset($usedSquads[$a])) continue;

        if ($minRestSlots > 0) {
          if (isset($lastSlotSquad[$h]) && ($slotIndex - $lastSlotSquad[$h]) <= $minRestSlots) continue;
          if (isset($lastSlotSquad[$a]) && ($slotIndex - $lastSlotSquad[$a]) <= $minRestSlots) continue;
        }

        $conflict = false;
        foreach ($m['coach_ids'] as $cid) {
          if (isset($usedCoaches[$cid])) { $conflict = true; break; }
          if ($minRestSlots > 0 && isset($lastSlotCoach[$cid]) && ($slotIndex - $lastSlotCoach[$cid]) <= $minRestSlots) { $conflict = true; break; }
        }
        if ($conflict) continue;

        $score = ($m['hard'] * 100) - ($m['round_no'] * 3);
        if ($score > $bestScore) { $bestScore = $score; $bestIdx = $idx; }
      }

      if ($bestIdx === -1) {
        // на это поле в этот слот не нашлось матча без конфликтов
        continue;
      }

      $m = $remaining[$bestIdx];
      unset($remaining[$bestIdx]);
      $remaining = array_values($remaining);

      $scheduleRows[] = [
        'match_id'=>(int)$m['match_id'],
        'start_time'=>$timeStr,
        'field_code'=>$fc,
      ];

      // маркируем занятость
      $usedMask |= $mask;

      $usedSquads[$m['home']] = true;
      $usedSquads[$m['away']] = true;
      $lastSlotSquad[$m['home']] = $slotIndex;
      $lastSlotSquad[$m['away']] = $slotIndex;

      foreach ($m['coach_ids'] as $cid) {
        $usedCoaches[$cid] = true;
        $lastSlotCoach[$cid] = $slotIndex;
      }
    }
  }

  if ($remaining) {
    $pdo->rollBack();
    respond_json([
      'ok'=>false,
      'error'=>"Не удалось расписать все матчи. Осталось: ".count($remaining).". Увеличь время/дни или пересмотри правила полей.",
    ], 400);
  }

  // запись schedule
  $insS = $pdo->prepare("INSERT INTO schedule(match_id, start_time, field_code) VALUES(:mid,:st,:fc)");
  foreach ($scheduleRows as $r) {
    $insS->execute([':mid'=>$r['match_id'], ':st'=>$r['start_time'], ':fc'=>$r['field_code']]);
  }

  $pdo->commit();

  // кратко по группам
  $outGroups = [];
  foreach ($groups as $gi => $teamIds) {
    $code = chr(ord('A') + $gi);
    $outGroups[$code] = $teamIds;
  }

  respond_json([
    'ok'=>true,
    'stage_id'=>$stageId,
    'category'=>$category,
    'variant'=>$variant,
    'field_codes'=>$fieldCodes,
    'groups'=>$outGroups,
    'matches_count'=>count($matches),
    'scheduled_count'=>count($scheduleRows),
  ]);

} catch (Throwable $e) {
  $pdo->rollBack();
  log_line("generate.php ERROR: ".$e->getMessage());
  respond_json(['ok'=>false,'error'=>$e->getMessage()], 500);
}
