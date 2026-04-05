<?php
// v=TEST_2026_02_09
declare(strict_types=1);

// /api/tournament/schedule_lab.php
// Schedule Lab: строит сетку слотов по stage_days + пытается разложить реальные матчи из matches.
// Всегда возвращает JSON (даже при фаталах) — чтобы UI не видел "Bad JSON".

ob_start();
header('Content-Type: application/json; charset=utf-8');

$__startedAt = microtime(true);
$GLOBALS['__lab_startedAt'] = $__startedAt;

function _lab_ms(float $t0): int {
  return (int) round((microtime(true) - $t0) * 1000);
}

function _lab_out(array $payload, int $http = 200): void {
  if (!headers_sent()) {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  }
  while (ob_get_level() > 0) { ob_end_clean(); }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Фатал-перехватчик (parse/compile/require-fatal и т.п.)
register_shutdown_function(function() use ($__startedAt) {
  $e = error_get_last();
  if (!$e) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
  if (!in_array($e['type'], $fatalTypes, true)) return;

  _lab_out([
    'ok'   => false,
    'error'=> 'PHP Fatal: ' . ($e['message'] ?? 'unknown'),
    'meta' => [
      'file' => $e['file'] ?? null,
      'line' => $e['line'] ?? null,
      'ms'   => _lab_ms((float)($GLOBALS['__lab_startedAt'] ?? $__startedAt)),
    ],
  ], 200);
});

// Превращаем предупреждения/нотисы в исключения только внутри try/catch (для ясных сообщений)
set_error_handler(function(int $severity, string $message, string $file, int $line) {
  if (!(error_reporting() & $severity)) return false;
  // Не роняем лабу на нотиcах/деприкейтах — только реальные проблемы.
  $ignore = [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT];
  if (in_array($severity, $ignore, true)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
});

function _lab_require_if_exists(string $path): void {
  if (!is_file($path)) {
    throw new RuntimeException('Missing required file: ' . basename($path));
  }
  require_once $path;
}

function _lab_get_pdo(): PDO {
  // 1) функция db()
  if (function_exists('db')) {
    $pdo = db();
    if ($pdo instanceof PDO) return $pdo;
  }
  // 2) глобальный $pdo
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  // 3) $DB
  if (isset($GLOBALS['DB']) && $GLOBALS['DB'] instanceof PDO) return $GLOBALS['DB'];
  throw new RuntimeException('DB handle not found (expected db() or $pdo).');
}

function _lab_qAll(PDO $pdo, string $sql, array $args = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  return is_array($rows) ? $rows : [];
}

function _lab_qOne(PDO $pdo, string $sql, array $args = []): ?array {
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row !== false ? $row : null;
}

// --- squad_constraints helpers (not_before / not_after) ---
// Ключи карты: $map[YYYY-MM-DD][squad_id] = ['nb'=>int, 'na'=>int]
function _lab_load_squad_constraints(PDO $pdo, int $stageId, array $dates): array {
  $dates = array_values(array_filter(array_unique(array_map('strval', $dates))));
  if ($stageId <= 0 || !$dates) return [];

  $place = implode(',', array_fill(0, count($dates), '?'));
  $sql = "SELECT squad_id, day_date, not_before_slot_no, not_after_slot_no
          FROM squad_constraints
          WHERE stage_id = ? AND day_date IN ($place)";
  $rows = _lab_qAll($pdo, $sql, array_merge([$stageId], $dates));

  $map = [];
  foreach ($rows as $r) {
    $d = (string)($r['day_date'] ?? '');
    $sid = (int)($r['squad_id'] ?? 0);
    if ($d === '' || $sid <= 0) continue;

    $nb = isset($r['not_before_slot_no']) ? (int)$r['not_before_slot_no'] : 0;
    $na = isset($r['not_after_slot_no']) ? (int)$r['not_after_slot_no'] : 0;

    if ($nb <= 0) $nb = 0;
    if ($na <= 0) $na = 0;
    if ($nb === 0 && $na === 0) continue;

    $map[$d][$sid] = ['nb' => $nb, 'na' => $na];
  }
  return $map;
}

function _lab_set_squad_constraints(array $map): void {
  $GLOBALS['__lab_squad_constraints'] = $map;
}

function _lab_get_squad_constraints(): array {
  return $GLOBALS['__lab_squad_constraints'] ?? [];
}

function _lab_squad_constraint_violation(string $date, int $slotNo, int $squadId): ?array {
  if ($date === '' || $slotNo <= 0 || $squadId <= 0) return null;

  $map = _lab_get_squad_constraints();
  $c = $map[$date][$squadId] ?? null;
  if (!$c) return null;

  $nb = (int)($c['nb'] ?? 0);
  $na = (int)($c['na'] ?? 0);

  if ($nb > 0 && $slotNo < $nb) {
    return ['code' => 'SQUAD_NOT_BEFORE', 'msg' => "Ограничение команды: не раньше слота {$nb}", 'squad_id' => $squadId, 'slot_no' => $slotNo, 'limit' => $nb];
  }
  if ($na > 0 && $slotNo > $na) {
    return ['code' => 'SQUAD_NOT_AFTER', 'msg' => "Ограничение команды: не позже слота {$na}", 'squad_id' => $squadId, 'slot_no' => $slotNo, 'limit' => $na];
  }
  return null;
}


function _lab_playoff_dependency_violation(string $date, int $slotNo, array $match, array $assignments, array $matchMeta, array $dayIndexByDate): ?array {
  if ((string)($match['phase'] ?? '') !== 'playoff') return null;
  if ($date === '' || $slotNo <= 0) return null;

  $deps = $matchMeta[(int)($match['id'] ?? $match['match_id'] ?? 0)]['dependency_match_ids'] ?? ($match['dependency_match_ids'] ?? []);
  if (!is_array($deps) || !$deps) return null;

  $assignedByMid = [];
  foreach ($assignments as $a) {
    if (!is_array($a)) continue;
    $mid = (int)($a['match_id'] ?? 0);
    if ($mid <= 0) continue;
    $assignedByMid[$mid] = $a;
  }

  $curDayIdx = (int)($dayIndexByDate[$date] ?? -1);
  foreach ($deps as $depMid) {
    $depMid = (int)$depMid;
    if ($depMid <= 0) continue;

    $src = $assignedByMid[$depMid] ?? null;
    if (!$src) {
      return [
        'code' => 'PLAYOFF_SOURCE_UNPLACED',
        'msg' => 'Матч зависит от ещё не размещённого источника',
        'source_match_id' => $depMid,
      ];
    }

    $srcDate = (string)($src['date'] ?? '');
    $srcSlot = (int)($src['slot_no'] ?? 0);
    if ($srcDate === '' || $srcSlot <= 0) {
      return [
        'code' => 'PLAYOFF_SOURCE_UNPLACED',
        'msg' => 'Матч зависит от ещё не размещённого источника',
        'source_match_id' => $depMid,
      ];
    }

    $srcDayIdx = (int)($dayIndexByDate[$srcDate] ?? -1);
    if ($srcDayIdx < 0 || $curDayIdx < 0) continue;

    if ($srcDayIdx > $curDayIdx || ($srcDayIdx === $curDayIdx && $srcSlot >= $slotNo)) {
      return [
        'code' => 'PLAYOFF_ORDER',
        'msg' => 'Матч плей-офф нельзя ставить раньше источника W/L',
        'source_match_id' => $depMid,
        'source_date' => $srcDate,
        'source_slot_no' => $srcSlot,
      ];
    }
  }

  return null;
}




function _lab_load_shared_load_rules(PDO $pdo, int $stageId): array {
  if ($stageId <= 0) return ['rules' => [], 'squad_to_rules' => []];

  $rows = _lab_qAll($pdo, "
    SELECT
      r.id,
      r.max_matches_per_day,
      r.min_rest_slots,
      rs.squad_id
    FROM stage_shared_load_rules r
    JOIN stage_shared_load_rule_squads rs ON rs.rule_id = r.id
    WHERE r.stage_id = ?
    ORDER BY r.id ASC, rs.squad_id ASC
  ", [$stageId]);

  $rules = [];
  $squadToRules = [];

  foreach ($rows as $r) {
    $ruleId = (int)($r['id'] ?? 0);
    $squadId = (int)($r['squad_id'] ?? 0);
    if ($ruleId <= 0 || $squadId <= 0) continue;

    if (!isset($rules[$ruleId])) {
      $maxPerDay = (int)($r['max_matches_per_day'] ?? 0);
      $minRest = (int)($r['min_rest_slots'] ?? 0);
      if ($maxPerDay < 1) $maxPerDay = 1;
      if ($minRest < 0) $minRest = 0;

      $rules[$ruleId] = [
        'id' => $ruleId,
        'max_matches_per_day' => $maxPerDay,
        'min_rest_slots' => $minRest,
        'squad_ids' => [],
      ];
    }

    if (!in_array($squadId, $rules[$ruleId]['squad_ids'], true)) {
      $rules[$ruleId]['squad_ids'][] = $squadId;
    }
    if (!isset($squadToRules[$squadId])) $squadToRules[$squadId] = [];
    if (!in_array($ruleId, $squadToRules[$squadId], true)) $squadToRules[$squadId][] = $ruleId;
  }

  return ['rules' => $rules, 'squad_to_rules' => $squadToRules];
}

function _lab_set_shared_load_rules(array $data): void {
  $GLOBALS['__lab_shared_load_rules'] = $data;
}

function _lab_get_shared_load_rules(): array {
  return $GLOBALS['__lab_shared_load_rules'] ?? ['rules' => [], 'squad_to_rules' => []];
}

function _lab_shared_rule_ids_for_squads(array $squadIds): array {
  $cfg = _lab_get_shared_load_rules();
  $map = $cfg['squad_to_rules'] ?? [];

  $out = [];
  foreach ($squadIds as $sid) {
    $sid = (int)$sid;
    if ($sid <= 0) continue;
    foreach (($map[$sid] ?? []) as $ruleId) {
      $ruleId = (int)$ruleId;
      if ($ruleId > 0) $out[$ruleId] = true;
    }
  }

  return array_map('intval', array_keys($out));
}

function _lab_shared_match_rule_ids(array $match): array {
  if (!empty($match['shared_rule_ids']) && is_array($match['shared_rule_ids'])) {
    return array_values(array_unique(array_map('intval', $match['shared_rule_ids'])));
  }

  $squadIds = [
    (int)($match['home_squad_id'] ?? 0),
    (int)($match['away_squad_id'] ?? 0),
  ];
  return _lab_shared_rule_ids_for_squads($squadIds);
}

function _lab_shared_rule_match_count_for_date(string $date, int $ruleId, array $assignments, array $matchMeta, ?int $ignoreMatchId = null): int {
  if ($date === '' || $ruleId <= 0) return 0;

  $cnt = 0;
  foreach ($assignments as $a) {
    if (!is_array($a)) continue;
    if ((string)($a['date'] ?? '') !== $date) continue;

    $mid = (int)($a['match_id'] ?? 0);
    if ($mid <= 0) continue;
    if ($ignoreMatchId !== null && $mid === $ignoreMatchId) continue;

    $ruleIds = $matchMeta[$mid]['shared_rule_ids'] ?? [];
    if (!is_array($ruleIds) || !$ruleIds) continue;

    foreach ($ruleIds as $rid) {
      if ((int)$rid === $ruleId) { $cnt++; break; }
    }
  }

  return $cnt;
}

function _lab_shared_rule_slots_for_date(string $date, int $ruleId, array $assignments, array $matchMeta, ?int $ignoreMatchId = null): array {
  if ($date === '' || $ruleId <= 0) return [];

  $slots = [];
  foreach ($assignments as $a) {
    if (!is_array($a)) continue;
    if ((string)($a['date'] ?? '') !== $date) continue;

    $mid = (int)($a['match_id'] ?? 0);
    if ($mid <= 0) continue;
    if ($ignoreMatchId !== null && $mid === $ignoreMatchId) continue;

    $ruleIds = $matchMeta[$mid]['shared_rule_ids'] ?? [];
    if (!is_array($ruleIds) || !$ruleIds) continue;

    $hit = false;
    foreach ($ruleIds as $rid) {
      if ((int)$rid === $ruleId) { $hit = true; break; }
    }
    if (!$hit) continue;

    $slotNo = (int)($a['slot_no'] ?? 0);
    if ($slotNo > 0) $slots[] = $slotNo;
  }

  $slots = array_values(array_unique(array_map('intval', $slots)));
  sort($slots);
  return $slots;
}

function _lab_shared_rule_violation(string $date, int $slotNo, array $match, array $assignments, array $matchMeta): ?array {
  if ($date === '' || $slotNo <= 0) return null;

  $cfg = _lab_get_shared_load_rules();
  $rules = $cfg['rules'] ?? [];
  if (!$rules) return null;

  $mid = (int)($match['id'] ?? $match['match_id'] ?? 0);
  $ruleIds = _lab_shared_match_rule_ids($match);
  if (!$ruleIds) return null;

  foreach ($ruleIds as $ruleId) {
    $ruleId = (int)$ruleId;
    if ($ruleId <= 0 || empty($rules[$ruleId])) continue;

    $rule = $rules[$ruleId];
    $maxPerDay = (int)($rule['max_matches_per_day'] ?? 0);
    if ($maxPerDay > 0) {
      $cnt = _lab_shared_rule_match_count_for_date($date, $ruleId, $assignments, $matchMeta, $mid > 0 ? $mid : null);
      if (($cnt + 1) > $maxPerDay) {
        return [
          'code' => 'SHARED_MAX_PER_DAY',
          'msg' => "Связанные команды: превышен общий лимит {$maxPerDay} матч(а/ей) в день",
          'rule_id' => $ruleId,
          'limit' => $maxPerDay,
          'count' => $cnt,
        ];
      }
    }

    $minRest = (int)($rule['min_rest_slots'] ?? 0);
    if ($minRest > 0) {
      $slots = _lab_shared_rule_slots_for_date($date, $ruleId, $assignments, $matchMeta, $mid > 0 ? $mid : null);
      if ($slots) {
        $prev = null; $next = null;
        foreach ($slots as $sno) {
          if ($sno < $slotNo) $prev = $sno;
          if ($sno > $slotNo) { $next = $sno; break; }
        }

        if ($prev !== null && ($slotNo - $prev) <= $minRest) {
          return [
            'code' => 'SHARED_MIN_REST',
            'msg' => "Связанные команды: мало отдыха, нужно {$minRest} слотов",
            'rule_id' => $ruleId,
            'limit' => $minRest,
            'side' => 'before',
            'with_slot' => $prev,
          ];
        }
        if ($next !== null && ($next - $slotNo) <= $minRest) {
          return [
            'code' => 'SHARED_MIN_REST',
            'msg' => "Связанные команды: мало отдыха, нужно {$minRest} слотов",
            'rule_id' => $ruleId,
            'limit' => $minRest,
            'side' => 'after',
            'with_slot' => $next,
          ];
        }
      }
    }
  }

  return null;
}

function _lab_try_single_swap_for_unplaced_match(
  array $m,
  array &$assignments,
  array &$occupied,
  array $calendar,
  int $slotStrideMinutes,
  string $prefer,
  array $singleCols,
  array $pairCols,
  array $matchMeta,
  array &$countPerDay,
  array &$lastSlot,
  array &$catPlacedByDay,
  array &$groupPlacedByDay,
  array &$remainingCatMatches,
  array &$remainingGroupMatches,
  int &$placed
): bool {
  $dayMap = _lab_day_map($calendar);
  $mFirstDayIdx = (int)($m['first_day_idx'] ?? 0);
  $mLastDayIdx  = (int)($m['last_day_idx'] ?? (count($calendar) - 1));
  $minDayDate   = (string)($m['min_day_date'] ?? '');
  $phase        = (string)($m['phase'] ?? '');

  foreach ($dayMap as $date => $day) {
    $dayIdx = (int)($day['day_idx'] ?? 0);
    if ($dayIdx < $mFirstDayIdx || $dayIdx > $mLastDayIdx) continue;
    if ($minDayDate !== '' && $minDayDate !== '0000-00-00' && $date < $minDayDate) continue;

    $officialSlotCount = (int)($day['slotCount'] ?? 0);
    $maxSlotNo = $officialSlotCount;
    foreach ($assignments as $a) {
      if ((string)($a['date'] ?? '') !== $date) continue;
      $maxSlotNo = max($maxSlotNo, (int)($a['slot_no'] ?? 0));
    }

    for ($slotNo = 1; $slotNo <= $maxSlotNo; $slotNo++) {
      $slotIdx = $slotNo - 1;
      if ($phase === 'playoff' && $slotIdx >= $officialSlotCount) continue;

      $switchIdx = (int)($day['switchIdx'] ?? $officialSlotCount);
      $mode = _lab_mode_at_idx($slotIdx, $switchIdx, $prefer);
      $cols = ($mode === 'single') ? $singleCols : $pairCols;

      foreach ($cols as $fieldCode) {
        $fieldCode = (string)$fieldCode;
        if (empty(($m['_allowed_set'] ?? [])[$fieldCode])) continue;

        $idxOcc = $occupied[$date][$slotNo][$fieldCode] ?? null;
        if ($idxOcc === null) continue;
        $idxOcc = (int)$idxOcc;
        if (!isset($assignments[$idxOcc])) continue;

        $midOcc = (int)($assignments[$idxOcc]['match_id'] ?? 0);
        if ($midOcc <= 0 || !isset($matchMeta[$midOcc])) continue;

        $occDate  = (string)($assignments[$idxOcc]['date'] ?? '');
        $occSlot  = (int)($assignments[$idxOcc]['slot_no'] ?? 0);
        $occField = (string)($assignments[$idxOcc]['field_code'] ?? '');
        if ($occDate === '' || $occSlot <= 0 || $occField === '') continue;

        $tmpAssignments = $assignments;
        $tmpOccupied = $occupied;
        unset($tmpOccupied[$occDate][$occSlot][$occField]);

        $tmpAssignments[] = [
          'date' => $date,
          'time' => _lab_time_for_slot($date, $slotNo, $dayMap, $slotStrideMinutes),
          'slot_no' => $slotNo,
          'field_code' => $fieldCode,
          'match_id' => (int)$m['id'],
          'code' => (string)$m['code'],
          'home_squad_id' => (int)($m['home_squad_id'] ?? 0),
          'away_squad_id' => (int)($m['away_squad_id'] ?? 0),
          'label' => (string)($m['label'] ?? ''),
          'title' => (string)($m['title'] ?? ''),
          'phase' => (string)($m['phase'] ?? ''),
          'category' => (string)($m['category'] ?? ''),
          'group_code' => (string)($m['group_code'] ?? ''),
          'round_no' => (int)($m['round_no'] ?? 0),
          'code_view' => (($m['phase'] ?? '') === 'playoff') ? ((string)$m['code'] . '-' . (string)$m['category']) : (string)$m['code'],
          'coach_ids' => array_values($m['coach_ids'] ?? []),
          'coach_penalty' => 0,
          'coach_conflict' => 0,
          'coach_conflict_ids' => [],
          'coach_conflict_with' => [],
        ];
        $newIdx = count($tmpAssignments) - 1;
        if (!_lab_assignment_valid_idx($newIdx, $tmpAssignments, $matchMeta, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols)) continue;

        foreach ($dayMap as $date2 => $day2) {
          $official2 = (int)($day2['slotCount'] ?? 0);
          $maxSlotNo2 = $official2;
          foreach ($assignments as $a2) {
            if ((string)($a2['date'] ?? '') !== $date2) continue;
            $maxSlotNo2 = max($maxSlotNo2, (int)($a2['slot_no'] ?? 0));
          }

          for ($slotNo2 = 1; $slotNo2 <= $maxSlotNo2; $slotNo2++) {
            $slotIdx2 = $slotNo2 - 1;
            $switchIdx2 = (int)($day2['switchIdx'] ?? $official2);
            $mode2 = _lab_mode_at_idx($slotIdx2, $switchIdx2, $prefer);
            $cols2 = ($mode2 === 'single') ? $singleCols : $pairCols;

            foreach ($cols2 as $fieldCode2) {
              $fieldCode2 = (string)$fieldCode2;
              if ($date2 === $occDate && $slotNo2 === $occSlot && $fieldCode2 === $occField) continue;
              if (!empty($tmpOccupied[$date2][$slotNo2][$fieldCode2])) continue;
              if (!_lab_can_place_idx($idxOcc, $date2, $slotNo2, $fieldCode2, $tmpAssignments, $matchMeta, $tmpOccupied, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols)) continue;

              _lab_apply_move_idx($idxOcc, $date2, $slotNo2, $fieldCode2, $assignments, $occupied, $dayMap, $slotStrideMinutes);

              $assignments[] = [
                'date' => $date,
                'time' => _lab_time_for_slot($date, $slotNo, $dayMap, $slotStrideMinutes),
                'slot_no' => $slotNo,
                'field_code' => $fieldCode,
                'match_id' => (int)$m['id'],
                'code' => (string)$m['code'],
                'home_squad_id' => (int)($m['home_squad_id'] ?? 0),
                'away_squad_id' => (int)($m['away_squad_id'] ?? 0),
                'label' => (string)($m['label'] ?? ''),
                'title' => (string)($m['title'] ?? ''),
                'phase' => (string)($m['phase'] ?? ''),
                'category' => (string)($m['category'] ?? ''),
                'group_code' => (string)($m['group_code'] ?? ''),
                'round_no' => (int)($m['round_no'] ?? 0),
                'code_view' => (($m['phase'] ?? '') === 'playoff')
                  ? ((string)$m['code'] . '-' . (string)$m['category'])
                  : (string)$m['code'],
                'coach_ids' => array_values($m['coach_ids'] ?? []),
                'coach_penalty' => 0,
                'coach_conflict' => 0,
                'coach_conflict_ids' => [],
                'coach_conflict_with' => [],
              ];

              $addedIdx = count($assignments) - 1;
              $occupied[$date][$slotNo][$fieldCode] = $addedIdx;

              // ВАЖНО: rescue тоже считается размещением
              $placed++;

              // Обновляем ограничения по командам
              foreach ([(int)($m['home_squad_id'] ?? 0), (int)($m['away_squad_id'] ?? 0)] as $sid) {
                if ($sid <= 0) continue;
                $lastSlot[$date][$sid] = $slotNo;
                if (!isset($countPerDay[$date][$sid])) $countPerDay[$date][$sid] = 0;
                $countPerDay[$date][$sid]++;
              }

              // Обновляем spread-счётчики по группам
              if ((string)($m['phase'] ?? '') === 'group') {
                $catKey = (string)($m['category'] ?? '');
                $grpKey = $catKey . '|' . (string)($m['group_code'] ?? '');

                if (!isset($catPlacedByDay[$catKey])) $catPlacedByDay[$catKey] = [];
                if (!isset($groupPlacedByDay[$grpKey])) $groupPlacedByDay[$grpKey] = [];

                $rescuedDayIdx = (int)($dayMap[$date]['day_idx'] ?? 0);

                $catPlacedByDay[$catKey][$rescuedDayIdx] =
                  (int)($catPlacedByDay[$catKey][$rescuedDayIdx] ?? 0) + 1;

                $groupPlacedByDay[$grpKey][$rescuedDayIdx] =
                  (int)($groupPlacedByDay[$grpKey][$rescuedDayIdx] ?? 0) + 1;

                $remainingCatMatches[$catKey] =
                  max(0, (int)($remainingCatMatches[$catKey] ?? 0) - 1);

                $remainingGroupMatches[$grpKey] =
                  max(0, (int)($remainingGroupMatches[$grpKey] ?? 0) - 1);
              }

              return true;
            }
          }
        }
      }
    }
  }

  return false;
}

function _lab_try_shared_rescue(
  array &$work,
  array &$assignments,
  array &$calendar,
  int $slotStrideMinutes,
  string $prefer,
  array $singleCols,
  array $pairCols,
  array $matchMeta,
  array &$countPerDay,
  array &$lastSlot,
  array &$catPlacedByDay,
  array &$groupPlacedByDay,
  array &$remainingCatMatches,
  array &$remainingGroupMatches,
  int &$placed
): int {
  if (!$work) return 0;
  $rescued = 0;
  $occupied = _lab_rebuild_occupied($assignments);

  foreach ($work as $i => $m) {
    $sharedRuleIds = array_values($m['shared_rule_ids'] ?? []);
    if (!$sharedRuleIds) continue;
    if (_lab_try_single_swap_for_unplaced_match(
      $m,
      $assignments,
      $occupied,
      $calendar,
      $slotStrideMinutes,
      $prefer,
      $singleCols,
      $pairCols,
      $matchMeta,
      $countPerDay,
      $lastSlot,
      $catPlacedByDay,
      $groupPlacedByDay,
      $remainingCatMatches,
      $remainingGroupMatches,
      $placed
    )) {
      unset($work[$i]);
      $work = array_values($work);
      $rescued++;
      break;
    }
  }

  if ($rescued > 0) _lab_apply_conflict_flags($assignments, $matchMeta);
  return $rescued;
}


function _lab_try_generic_rescue(
  array &$work,
  array &$assignments,
  array &$calendar,
  int $slotStrideMinutes,
  string $prefer,
  array $singleCols,
  array $pairCols,
  array $matchMeta,
  array &$countPerDay,
  array &$lastSlot,
  array &$catPlacedByDay,
  array &$groupPlacedByDay,
  array &$remainingCatMatches,
  array &$remainingGroupMatches,
  int &$placed
): int {
  if (!$work) return 0;

  usort($work, function($a, $b){
    $wa = ((int)($a['last_day_idx'] ?? 0) - (int)($a['first_day_idx'] ?? 0));
    $wb = ((int)($b['last_day_idx'] ?? 0) - (int)($b['first_day_idx'] ?? 0));
    $d = ($wa <=> $wb);
    if ($d !== 0) return $d;

    $fa = is_array($a['allowed_fields'] ?? null) ? count($a['allowed_fields']) : 99;
    $fb = is_array($b['allowed_fields'] ?? null) ? count($b['allowed_fields']) : 99;
    $d = ($fa <=> $fb);
    if ($d !== 0) return $d;

    $d = ((int)($b['round_no'] ?? 0) <=> (int)($a['round_no'] ?? 0));
    if ($d !== 0) return $d;

    return ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
  });

  $rescued = 0;
  $maxPasses = min(24, max(1, count($work) * 2));

  while ($maxPasses-- > 0 && $work) {
    $occupied = _lab_rebuild_occupied($assignments);
    $changed = false;

    foreach ($work as $i => $m) {
      if (_lab_try_single_swap_for_unplaced_match(
        $m,
        $assignments,
        $occupied,
        $calendar,
        $slotStrideMinutes,
        $prefer,
        $singleCols,
        $pairCols,
        $matchMeta,
        $countPerDay,
        $lastSlot,
        $catPlacedByDay,
        $groupPlacedByDay,
        $remainingCatMatches,
        $remainingGroupMatches,
        $placed
      )) {
        unset($work[$i]);
        $work = array_values($work);
        $rescued++;
        $changed = true;
        break;
      }
    }

    if (!$changed) break;
  }

  if ($rescued > 0) _lab_apply_conflict_flags($assignments, $matchMeta);
  return $rescued;
}

function _lab_force_place_remaining_oob(
  array &$work,
  array &$assignments,
  array $calendar,
  int $slotStrideMinutes,
  string $prefer,
  array $singleCols,
  array $pairCols,
  array &$countPerDay,
  array &$lastSlot,
  int &$placed,
  string $lastStageDayDate = ''
): array {
  if (!$work) return ['forced' => 0, 'dates' => []];

  $forced = 0;
  $datesUsed = [];
  $numDays = count($calendar);

  foreach ($work as $i => $m) {
    if ($numDays <= 0) break;

    $firstDayIdx = max(0, (int)($m['first_day_idx'] ?? 0));
    $lastDayIdx = min($numDays - 1, max($firstDayIdx, (int)($m['last_day_idx'] ?? ($numDays - 1))));
    $targetIdx = (int)($m['preferred_day'] ?? $lastDayIdx);
    if ($targetIdx < $firstDayIdx) $targetIdx = $firstDayIdx;
    if ($targetIdx > $lastDayIdx) $targetIdx = $lastDayIdx;

    $minDayDate = (string)($m['min_day_date'] ?? '');
    if ($minDayDate !== '' && $minDayDate !== '0000-00-00') {
      for ($di = $firstDayIdx; $di <= $lastDayIdx; $di++) {
        $d = (string)($calendar[$di]['date'] ?? '');
        if ($d !== '' && $d >= $minDayDate) {
          if ($di > $targetIdx) $targetIdx = $di;
          break;
        }
      }
    }

    if (!empty($m['avoid_last_day']) && $lastStageDayDate !== '' && $targetIdx > $firstDayIdx) {
      $targetDate = (string)($calendar[$targetIdx]['date'] ?? '');
      if ($targetDate === $lastStageDayDate) $targetIdx--;
    }

    $date = (string)($calendar[$targetIdx]['date'] ?? '');
    if ($date === '') continue;

    $officialSlotCount = is_array($calendar[$targetIdx]['slots'] ?? null) ? count($calendar[$targetIdx]['slots']) : 0;
    $maxSlotNo = $officialSlotCount;
    foreach ($assignments as $a) {
      if ((string)($a['date'] ?? '') !== $date) continue;
      $maxSlotNo = max($maxSlotNo, (int)($a['slot_no'] ?? 0));
    }
    $slotNo = $maxSlotNo + 1;
    $timeStr = _lab_time_for_slot($date, $slotNo, $GLOBALS['__lab_day_index_by_date_map'] ?? _lab_day_map($calendar), $slotStrideMinutes);

    $preferredFields = [];
    if (!empty($m['_pair_only'])) {
      foreach (['12','34'] as $fc) if (!empty(($m['_allowed_set'] ?? [])[$fc])) $preferredFields[] = $fc;
    } elseif (!empty($m['_single_only'])) {
      foreach (['1','2','3','4'] as $fc) if (!empty(($m['_allowed_set'] ?? [])[$fc])) $preferredFields[] = $fc;
    }
    if (!$preferredFields) {
      foreach (($m['allowed_fields'] ?? []) as $fc) {
        $fc = (string)$fc;
        if ($fc !== '') $preferredFields[] = $fc;
      }
    }
    if (!$preferredFields) {
      $preferredFields = (!empty($m['_pair_only']) ? $pairCols : $singleCols);
    }
    $fieldCode = (string)($preferredFields[0] ?? '');
    if ($fieldCode === '') $fieldCode = '1';

    $assignments[] = [
      'date' => $date,
      'time' => $timeStr,
      'slot_no' => $slotNo,
      'field_code' => $fieldCode,
      'match_id' => (int)$m['id'],
      'code' => (string)$m['code'],
      'home_squad_id' => (int)($m['home_squad_id'] ?? 0),
      'away_squad_id' => (int)($m['away_squad_id'] ?? 0),
      'label' => (string)($m['label'] ?? ''),
      'title' => (string)($m['title'] ?? ''),
      'phase' => (string)($m['phase'] ?? ''),
      'category' => (string)($m['category'] ?? ''),
      'group_code' => (string)($m['group_code'] ?? ''),
      'round_no' => (int)($m['round_no'] ?? 0),
      'code_view' => (($m['phase'] ?? '') === 'playoff') ? ((string)$m['code'] . '-' . (string)$m['category']) : (string)$m['code'],
      'coach_ids' => array_values($m['coach_ids'] ?? []),
      'coach_penalty' => 0,
      'coach_conflict' => 0,
      'coach_conflict_ids' => [],
      'coach_conflict_with' => [],
      'forced_oob' => 1,
      'forced_reason' => 'forced_unplaced',
    ];

    foreach ([(int)($m['home_squad_id'] ?? 0), (int)($m['away_squad_id'] ?? 0)] as $sid) {
      if ($sid <= 0) continue;
      $lastSlot[$date][$sid] = $slotNo;
      if (!isset($countPerDay[$date][$sid])) $countPerDay[$date][$sid] = 0;
      $countPerDay[$date][$sid]++;
    }

    $placed++;
    $forced++;
    $datesUsed[$date] = true;
  }

  $work = [];
  return ['forced' => $forced, 'dates' => array_values(array_keys($datesUsed))];
}


$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'move_preview') {
  try {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];

    _lab_require_if_exists(__DIR__ . '/_bootstrap.php');
    _lab_require_if_exists(__DIR__ . '/_db.php');
    _lab_require_if_exists(__DIR__ . '/_schema.php');

    $stageId = (int)($in['stage_id'] ?? 0);
    $assignments = $in['assignments'] ?? [];
    $move = $in['move'] ?? null;

    if ($stageId <= 0 || !is_array($assignments) || !is_array($move) || empty($move['match_id']) || empty($move['to'])) {
      _lab_out(['ok'=>false,'reasons'=>[['code'=>'BAD_REQUEST','msg'=>'stage_id/assignments/move не заданы']]], 200);
    }

    $mid = (int)$move['match_id'];
    $toDate  = (string)($move['to']['date'] ?? '');
    $toSlot  = (int)($move['to']['slot_index'] ?? $move['to']['slot_no'] ?? 0);
    $toField = (string)($move['to']['field_code'] ?? '');

    if ($mid <= 0 || $toDate === '' || $toSlot <= 0 || $toField === '') {
      _lab_out(['ok'=>false,'reasons'=>[['code'=>'BAD_REQUEST','msg'=>'Неверные параметры переноса']]], 200);
    }

    $pdo = _lab_get_pdo();
    if (function_exists('ensure_schedule_stage_events_table')) ensure_schedule_stage_events_table($pdo);

    // squad_constraints: not_before / not_after
    _lab_set_squad_constraints(_lab_load_squad_constraints($pdo, $stageId, [$toDate]));
    _lab_set_shared_load_rules(_lab_load_shared_load_rules($pdo, $stageId));

    $reasons = [];

    // 1) occupied из assignments (исключая переносимый матч)
    $occ = [];        // $occ[date][slot_no][field] = match_id
    $slotMids = [];   // $slotMids[date][slot_no] = [match_id,...]
    $allMids = [];    // все match_id из assignments (кроме mid) для дальнейших выборок

    foreach ($assignments as $a) {
      if (!is_array($a)) continue;
      $am = (int)($a['match_id'] ?? 0);
      if ($am <= 0 || $am === $mid) continue;

      $d = (string)($a['date'] ?? '');
      $s = (int)($a['slot_index'] ?? $a['slot_no'] ?? 0);
      $f = (string)($a['field_code'] ?? '');
      if ($d === '' || $s <= 0 || $f === '') continue;

      $occ[$d][$s][$f] = $am;
      $slotMids[$d][$s][] = $am;
      $allMids[$am] = true;
    }

    // 2) ячейка занята?
    if (!empty($occ[$toDate][$toSlot][$toField])) {
      $reasons[] = ['code'=>'CELL_BUSY','msg'=>'Ячейка занята','with'=>[(int)$occ[$toDate][$toSlot][$toField]]];
    }

    // 3) мета переносимого матча
    $m = _lab_qOne($pdo, "SELECT id, stage_id, category, variant, home_squad_id, away_squad_id FROM matches WHERE id=? LIMIT 1", [$mid]);
    if (!$m) {
      _lab_out(['ok'=>false,'reasons'=>[['code'=>'MATCH_NOT_FOUND','msg'=>'Матч не найден']]], 200);
    }

    $cat  = (int)($m['category'] ?? 0);
    $var  = (int)($m['variant'] ?? 0);
    $home = (int)($m['home_squad_id'] ?? 0);
    $away = (int)($m['away_squad_id'] ?? 0);
    $moveSharedRuleIds = _lab_shared_rule_ids_for_squads([$home, $away]);

    // 4) allowed fields (stage_category_fields)
    $rows = _lab_qAll($pdo, "SELECT field_code FROM stage_category_fields WHERE stage_id=? AND category=? AND variant=?", [$stageId, $cat, $var]);
    $allowed = [];
    foreach ($rows as $r) {
      $fc = (string)($r['field_code'] ?? '');
      if ($fc !== '') $allowed[$fc] = true;
    }
    if (empty($allowed[$toField])) {
      $reasons[] = ['code'=>'FIELD_NOT_ALLOWED','msg'=>"Поле {$toField} запрещено для категории {$cat}/вариант {$var}"];
    }

    // 5) Собираем squads по матчам: целевой слот и весь день для отдыха
    $slotOthers = $slotMids[$toDate][$toSlot] ?? [];

    // Соберём все матчи этого дня (для отдыха) по assignments
    $dayMids = [];
    foreach ($assignments as $a) {
      if (!is_array($a)) continue;
      $am = (int)($a['match_id'] ?? 0);
      if ($am <= 0 || $am === $mid) continue;
      if ((string)($a['date'] ?? '') !== $toDate) continue;
      $dayMids[$am] = true;
    }

    // 5.1) матч_id -> squads (для slotOthers + dayMids)
    $needMids = array_values(array_unique(array_merge(array_keys($dayMids), $slotOthers)));
    $mmap = []; // match_id => ['home'=>..,'away'=>..]
    if (!empty($needMids)) {
      $place = implode(',', array_fill(0, count($needMids), '?'));
      $rows = _lab_qAll($pdo, "SELECT id, home_squad_id, away_squad_id FROM matches WHERE id IN ($place)", $needMids);
      foreach ($rows as $r) {
        $id = (int)$r['id'];
        $hh = (int)($r['home_squad_id'] ?? 0);
        $aa = (int)($r['away_squad_id'] ?? 0);
        $mmap[$id] = [
          'home' => $hh,
          'away' => $aa,
          'shared_rule_ids' => _lab_shared_rule_ids_for_squads([$hh, $aa]),
        ];
      }
    }

    // 6) TEAM_OVERLAP в целевом слоте
    if (!empty($slotOthers)) {
      $usedSquads = [];
      $withBySquad = [];

      foreach ($slotOthers as $omid) {
        $om = $mmap[(int)$omid] ?? null;
        if (!$om) continue;
        foreach (['home','away'] as $k) {
          $sid = (int)($om[$k] ?? 0);
          if ($sid) { $usedSquads[$sid] = true; $withBySquad[$sid] = (int)$omid; }
        }
      }

      $hit = [];
      if ($home && !empty($usedSquads[$home])) $hit[] = $withBySquad[$home] ?? null;
      if ($away && !empty($usedSquads[$away])) $hit[] = $withBySquad[$away] ?? null;
      $hit = array_values(array_unique(array_filter($hit, fn($x)=>$x!==null)));

      if (!empty($hit)) {
        $reasons[] = ['code'=>'TEAM_OVERLAP','msg'=>'Команда уже играет в этом слоте','with'=>$hit];
      }

      // 7) COACH_CONFLICT в слоте
      // Сначала соберём все squad_id (наш + чужие)
      $squadIds = [];
      if ($home) $squadIds[$home]=true;
      if ($away) $squadIds[$away]=true;
      foreach (array_keys($usedSquads) as $sid) $squadIds[(int)$sid]=true;

      $coachBySquad = [];
      if (!empty($squadIds)) {
        $ids = array_keys($squadIds);
        $place2 = implode(',', array_fill(0, count($ids), '?'));
        $rows = _lab_qAll($pdo, "SELECT id, coach_id FROM squads WHERE id IN ($place2)", $ids);
        foreach ($rows as $r) {
          $coachBySquad[(int)$r['id']] = (int)($r['coach_id'] ?? 0);
        }
      }

      $myCoaches = [];
      if ($home && !empty($coachBySquad[$home])) $myCoaches[$coachBySquad[$home]] = true;
      if ($away && !empty($coachBySquad[$away])) $myCoaches[$coachBySquad[$away]] = true;

      if (!empty($myCoaches)) {
        $hit2 = [];
        foreach ($usedSquads as $sid => $_) {
          $c = (int)($coachBySquad[(int)$sid] ?? 0);
          if ($c && !empty($myCoaches[$c])) {
            $hit2[] = $withBySquad[(int)$sid] ?? null;
          }
        }
        $hit2 = array_values(array_unique(array_filter($hit2, fn($x)=>$x!==null)));
        if (!empty($hit2)) {
          $reasons[] = ['code'=>'COACH_CONFLICT','msg'=>'Пересечение тренера в этом слоте','with'=>$hit2];
        }
      }
    }

// --- RESOURCE CONFLICTS (12/34 vs singles) ---
$confMap = [
  '1'  => ['1','12'],
  '2'  => ['2','12'],
  '3'  => ['3','34'],
  '4'  => ['4','34'],
  '12' => ['1','2','12'],
  '34' => ['3','4','34'],
];

// конфликты в целевом слоте по occ (кроме mid уже исключён выше)
if (!empty($confMap[$toField])) {
  $hit = [];
  foreach ($confMap[$toField] as $fc) {
    if (!empty($occ[$toDate][$toSlot][$fc])) $hit[] = (int)$occ[$toDate][$toSlot][$fc];
  }
  $hit = array_values(array_unique($hit));
  if (!empty($hit)) {
    $reasons[] = ['code'=>'RESOURCE_CONFLICT','msg'=>'Конфликт ресурсов (12/34 ↔ 1–4)','with'=>$hit];
  }
}


    // 8) REST (min_rest_slots) — внутри дня
    $minRest = 0;
    $row = _lab_qOne($pdo, "SELECT min_rest_slots FROM stage_categories WHERE stage_id=? AND category=? LIMIT 1", [$stageId, $cat]);
    if ($row && isset($row['min_rest_slots'])) $minRest = (int)$row['min_rest_slots'];

    if ($minRest > 0 && ($home || $away)) {
      // соберём slot_no игр каждой команды в этот день
      $slotsBySquad = []; // squad_id => [slot_no...]
      foreach ($assignments as $a) {
        if (!is_array($a)) continue;
        if ((string)($a['date'] ?? '') !== $toDate) continue;
        $am = (int)($a['match_id'] ?? 0);
        if ($am <= 0 || $am === $mid) continue;
        $sno = (int)($a['slot_no'] ?? 0);
        if ($sno <= 0) continue;

        $om = $mmap[$am] ?? null;
        if (!$om) continue;

        $hs = (int)($om['home'] ?? 0);
        $as = (int)($om['away'] ?? 0);
        if ($hs) $slotsBySquad[$hs][] = $sno;
        if ($as) $slotsBySquad[$as][] = $sno;
      }

      foreach ([$home, $away] as $sq) {
        if (!$sq) continue;
        $slots = $slotsBySquad[$sq] ?? [];
        if (empty($slots)) continue;
        sort($slots);

        $prev = null; $next = null;
        foreach ($slots as $sno) {
          if ($sno < $toSlot) $prev = $sno;
          if ($sno > $toSlot) { $next = $sno; break; }
        }

        // Требуем минимум minRest слотов "между", значит разница slot_no должна быть >= minRest+1
        if ($prev !== null && ($toSlot - $prev) <= $minRest) {
          $have = max(0, ($toSlot - $prev - 1));
          $reasons[] = ['code'=>'REST_VIOLATION','msg'=>"Мало отдыха: нужно {$minRest}, сейчас {$have} (до)"];
        }
        if ($next !== null && ($next - $toSlot) <= $minRest) {
          $have = max(0, ($next - $toSlot - 1));
          $reasons[] = ['code'=>'REST_VIOLATION','msg'=>"Мало отдыха: нужно {$minRest}, сейчас {$have} (после)"];
        }
      }
    }


    $moveMatchMeta = [];
    foreach ($mmap as $mmid => $mmr) {
      $moveMatchMeta[(int)$mmid] = [
        'home_squad_id' => (int)($mmr['home'] ?? 0),
        'away_squad_id' => (int)($mmr['away'] ?? 0),
        'shared_rule_ids' => array_values($mmr['shared_rule_ids'] ?? []),
      ];
    }
    $moveMatchMeta[$mid] = [
      'home_squad_id' => $home,
      'away_squad_id' => $away,
      'shared_rule_ids' => $moveSharedRuleIds,
    ];

    $moveDayMap = [];
    $dayRows = _lab_qAll($pdo, "SELECT day_date, day_start, day_end, fields, is_active FROM stage_days WHERE stage_id = ? AND day_date = ? LIMIT 1", [$stageId, $toDate]);
    $stage = _lab_qOne($pdo, 'SELECT day_start, day_end, fields, match_minutes, break_minutes, transition_minutes, timezone FROM stages WHERE id = ? LIMIT 1', [$stageId]);
    $timeZoneName = (string)($stage['timezone'] ?? 'Europe/Moscow');
    try { $moveTz = new DateTimeZone($timeZoneName); } catch (Throwable $e) { $moveTz = new DateTimeZone('Europe/Moscow'); }
    $dayRow = $dayRows[0] ?? [
      'day_start' => (string)($stage['day_start'] ?? '09:00:00'),
      'day_end' => (string)($stage['day_end'] ?? '18:00:00'),
      'fields' => (int)($stage['fields'] ?? 4),
      'is_active' => 1,
    ];
    if ($dayRow) {
      $dayStart = _lab_time_hm((string)($dayRow['day_start'] ?? '09:00'));
      $dayEnd = _lab_time_hm((string)($dayRow['day_end'] ?? '18:00'));
      try {
        $dayStartDt = _lab_dt($toDate, $dayStart, $moveTz);
        $dayEndDt = _lab_dt($toDate, $dayEnd, $moveTz);
        if ($dayEndDt > $dayStartDt) {
          $tmpSlots = [];
          for ($t = $dayStartDt; $t < $dayEndDt; $t = $t->modify('+' . max(1, (int)($stage['match_minutes'] ?? 25) * 2 + (int)($stage['break_minutes'] ?? 5) + max(0, (int)($stage['transition_minutes'] ?? 15))) . ' minutes')) {
            $tmpSlots[] = $t;
          }
          $moveDayMap[$toDate] = [
            'date' => $toDate,
            'day_idx' => 0,
            'dayStart' => $dayStartDt,
            'dayEnd' => $dayEndDt,
            'slots' => $tmpSlots,
            'slotCount' => count($tmpSlots),
            'switchIdx' => count($tmpSlots),
          ];
        }
      } catch (Throwable $e) {}
    }
    if ($moveDayMap) {
      _lab_set_stage_events(_lab_load_stage_events($pdo, $stageId, $moveTz, [$toDate]));
      $moveBaseMinutes = max(1, (int)($stage['match_minutes'] ?? 25) * 2 + (int)($stage['break_minutes'] ?? 5));
      $moveStrideMinutes = $moveBaseMinutes + max(0, (int)($stage['transition_minutes'] ?? 15));
      $eventViolation = _lab_stage_event_violation($toDate, $toSlot, $moveDayMap, $moveStrideMinutes, $moveBaseMinutes);
      if ($eventViolation) $reasons[] = $eventViolation;
    }

    $sharedViolation = _lab_shared_rule_violation($toDate, $toSlot, [
      'id' => $mid,
      'home_squad_id' => $home,
      'away_squad_id' => $away,
      'shared_rule_ids' => $moveSharedRuleIds,
    ], $assignments, $moveMatchMeta);
    if ($sharedViolation) $reasons[] = $sharedViolation;

    // squad_constraints check (hard)
    foreach ([$home, $away] as $sq) {
      $sq = (int)$sq;
      if ($sq <= 0) continue;
      $v = _lab_squad_constraint_violation($toDate, $toSlot, $sq);
      if ($v) $reasons[] = $v;
    }

    _lab_out([
      'ok' => empty($reasons),
      'reasons' => $reasons,
    ], 200);

  } catch (Throwable $e) {
    _lab_out([
      'ok' => false,
      'error' => $e->getMessage(),
      'meta' => ['ms' => _lab_ms((float)($GLOBALS['__lab_startedAt'] ?? microtime(true)))]
    ], 200);
  }
}


function _lab_time_hm(string $time): string {
  // В БД может быть HH:MM:SS, в UI хотим HH:MM
  return preg_match('~^\d{2}:\d{2}~', $time) ? substr($time, 0, 5) : $time;
}

function _lab_dt(string $date, string $time, DateTimeZone $tz): DateTimeImmutable {
  // date: YYYY-MM-DD, time: HH:MM[:SS]
  $time = strlen($time) === 5 ? ($time . ':00') : $time;
  return new DateTimeImmutable($date . ' ' . $time, $tz);
}


function _lab_set_stage_events(array $map): void {
  $GLOBALS['__lab_stage_events'] = $map;
}

function _lab_get_stage_events(): array {
  return $GLOBALS['__lab_stage_events'] ?? [];
}

function _lab_load_stage_events(PDO $pdo, int $stageId, DateTimeZone $tz, array $dates = []): array {
  $dates = array_values(array_filter(array_unique(array_map('strval', $dates))));
  if ($stageId <= 0) return [];

  $sql = "SELECT id, stage_id, event_date, time_from, time_to, event_type, title, is_active
          FROM schedule_stage_events
          WHERE stage_id = ? AND is_active = 1";
  $args = [$stageId];
  if ($dates) {
    $place = implode(',', array_fill(0, count($dates), '?'));
    $sql .= " AND event_date IN ($place)";
    $args = array_merge($args, $dates);
  }
  $sql .= " ORDER BY event_date ASC, time_from ASC, id ASC";

  $rows = _lab_qAll($pdo, $sql, $args);
  $map = [];
  foreach ($rows as $r) {
    $date = (string)($r['event_date'] ?? '');
    $from = _lab_time_hm((string)($r['time_from'] ?? ''));
    $to   = _lab_time_hm((string)($r['time_to'] ?? ''));
    if ($date === '' || $from === '' || $to === '' || $from >= $to) continue;

    try {
      $fromDt = _lab_dt($date, $from, $tz);
      $toDt   = _lab_dt($date, $to, $tz);
    } catch (Throwable $e) {
      continue;
    }
    if ($fromDt >= $toDt) continue;

    $map[$date][] = [
      'id' => (int)($r['id'] ?? 0),
      'stage_id' => (int)($r['stage_id'] ?? 0),
      'event_date' => $date,
      'time_from' => $from,
      'time_to' => $to,
      'event_type' => (string)($r['event_type'] ?? 'custom'),
      'title' => (string)($r['title'] ?? ''),
      'is_active' => (int)($r['is_active'] ?? 1),
      '_from' => $fromDt,
      '_to' => $toDt,
    ];
  }
  return $map;
}

function _lab_stage_event_violation(string $date, int $slotNo, array $dayMap, int $slotStrideMinutes, int $slotLastMinutes): ?array {
  if ($date === '' || $slotNo <= 0 || !isset($dayMap[$date])) return null;

  $events = _lab_get_stage_events();
  $list = $events[$date] ?? [];
  if (!$list) return null;

  $slotIdx = $slotNo - 1;
  $day = $dayMap[$date];
  $slotStart = null;
  if (isset($day['slots'][$slotIdx]) && $day['slots'][$slotIdx] instanceof DateTimeImmutable) {
    $slotStart = $day['slots'][$slotIdx];
  } elseif (isset($day['dayStart']) && $day['dayStart'] instanceof DateTimeImmutable) {
    $slotStart = $day['dayStart']->modify('+' . ($slotIdx * $slotStrideMinutes) . ' minutes');
  }
  if (!$slotStart) return null;

  $slotEnd = $slotStart->modify('+' . max(1, $slotLastMinutes) . ' minutes');
  foreach ($list as $ev) {
    $evFrom = $ev['_from'] ?? null;
    $evTo = $ev['_to'] ?? null;
    if (!($evFrom instanceof DateTimeImmutable) || !($evTo instanceof DateTimeImmutable)) continue;
    if ($slotStart < $evTo && $slotEnd > $evFrom) {
      $title = trim((string)($ev['title'] ?? ''));
      $from = (string)($ev['time_from'] ?? '');
      $to = (string)($ev['time_to'] ?? '');
      $msg = 'Слот зарезервирован';
      if ($title !== '') $msg .= ': ' . $title;
      if ($from !== '' && $to !== '') $msg .= ' (' . $from . '–' . $to . ')';
      return [
        'code' => 'EVENT_RESERVED',
        'msg' => $msg,
        'event_id' => (int)($ev['id'] ?? 0),
        'title' => $title,
        'event_type' => (string)($ev['event_type'] ?? 'custom'),
        'time_from' => $from,
        'time_to' => $to,
      ];
    }
  }
  return null;
}

function _lab_build_event_slots_out(array $dayMap, int $slotStrideMinutes, int $slotLastMinutes): array {
  $events = _lab_get_stage_events();
  if (!$events) return [];

  $out = [];
  foreach ($dayMap as $date => $day) {
    $slots = $day['slots'] ?? [];
    $slotCount = is_array($slots) ? count($slots) : 0;
    if ($slotCount <= 0 || empty($events[$date])) continue;

    for ($slotNo = 1; $slotNo <= $slotCount; $slotNo++) {
      $violation = _lab_stage_event_violation($date, $slotNo, $dayMap, $slotStrideMinutes, $slotLastMinutes);
      if (!$violation) continue;
      $out[] = [
        'date' => $date,
        'slot_index' => $slotNo,
        'slot_no' => $slotNo,
        'title' => (string)($violation['title'] ?? ''),
        'event_type' => (string)($violation['event_type'] ?? 'custom'),
        'time_from' => (string)($violation['time_from'] ?? ''),
        'time_to' => (string)($violation['time_to'] ?? ''),
        'msg' => (string)($violation['msg'] ?? 'Слот зарезервирован'),
      ];
    }
  }
  return $out;
}

  // === Coach conflict "repair pass" helpers (soft constraint) ===
  function _lab_day_map(array $calendar): array {
    $map = [];
    foreach ($calendar as $i => $d) {
      $date = (string)($d['date'] ?? '');
      if ($date === '') continue;
      $slots = $d['slots'] ?? [];
      $map[$date] = [
        'day_idx' => (int)$i,
        'date' => $date,
        'dayStart' => $d['dayStart'],
        'dayEnd'   => $d['dayEnd'],
        'slots'    => $slots,
        'slotCount'=> is_array($slots) ? count($slots) : 0,
        'switchIdx'=> (int)($d['switchIdx'] ?? (is_array($slots) ? count($slots) : 0)),
      ];
    }
    return $map;
  }

  function _lab_mode_at_idx(int $slotIdx, int $switchIdx, string $prefer): string {
    $primaryIsSingle = ($prefer === 'single');
    $isPrimaryZone = ($slotIdx < $switchIdx);
    return ($isPrimaryZone ? ($primaryIsSingle ? 'single' : 'pair') : ($primaryIsSingle ? 'pair' : 'single'));
  }

  function _lab_cols_for_slot(string $date, int $slotNo, array $dayMap, int $slotStrideMinutes, string $prefer, array $singleCols, array $pairCols): array {
    if (!isset($dayMap[$date])) return $singleCols;
    $d = $dayMap[$date];
    $official = (int)($d['slotCount'] ?? 0);
    $switchIdx = (int)($d['switchIdx'] ?? $official);

    $slotIdx = max(0, $slotNo - 1);
    if ($official <= 0) {
      $mode = ($prefer === 'pair') ? 'pair' : 'single';
    } elseif ($slotIdx < $official) {
      $mode = _lab_mode_at_idx($slotIdx, $switchIdx, $prefer);
    } else {
      $mode = _lab_mode_at_idx($official - 1, $switchIdx, $prefer);
    }

    return ($mode === 'pair') ? $pairCols : $singleCols;
  }

  function _lab_time_for_slot(string $date, int $slotNo, array $dayMap, int $slotStrideMinutes): string {
    if (!isset($dayMap[$date])) return '00:00';
    $d = $dayMap[$date];
    $slotIdx = max(0, $slotNo - 1);
    $slots = $d['slots'] ?? [];
    $official = (int)($d['slotCount'] ?? 0);

    if ($official > 0 && $slotIdx < $official) {
      return $slots[$slotIdx]->format('H:i');
    }
    $dt = $d['dayStart']->modify('+' . ($slotIdx * $slotStrideMinutes) . ' minutes');
    return $dt->format('H:i');
  }

  function _lab_build_conflicts(array $assignments, array $matchMeta): array {
    $map = [];
    foreach ($assignments as $idx => $a) {
      $date = (string)($a['date'] ?? '');
      $slotNo = (int)($a['slot_no'] ?? 0);
      $mid = (int)($a['match_id'] ?? 0);
      if ($date === '' || $slotNo <= 0 || $mid <= 0) continue;

      $cids = $matchMeta[$mid]['coach_ids'] ?? ($a['coach_ids'] ?? []);
      if (!$cids) continue;

      foreach ($cids as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        $k = $date . '|' . $slotNo . '|' . $cid;
        if (!isset($map[$k])) $map[$k] = ['date'=>$date,'slot_no'=>$slotNo,'coach_id'=>$cid,'idxs'=>[]];
        $map[$k]['idxs'][] = (int)$idx;
      }
    }

    $out = [];
    foreach ($map as $v) {
      if (count($v['idxs']) > 1) $out[] = $v;
    }

    usort($out, function($a, $b){
      $d = strcmp((string)$a['date'], (string)$b['date']);
      if ($d !== 0) return $d;
      $d = ((int)$a['slot_no'] <=> (int)$b['slot_no']);
      if ($d !== 0) return $d;
      return ((int)$a['coach_id'] <=> (int)$b['coach_id']);
    });

    return $out;
  }

  function _lab_check_rest_for_squad(string $date, int $squadId, array $assignments, array $matchMeta): bool {
    $games = [];
    foreach ($assignments as $a) {
      if ((string)($a['date'] ?? '') !== $date) continue;
      $mid = (int)($a['match_id'] ?? 0);
      if ($mid <= 0 || !isset($matchMeta[$mid])) continue;
      $h = (int)($matchMeta[$mid]['home_squad_id'] ?? 0);
      $aw = (int)($matchMeta[$mid]['away_squad_id'] ?? 0);
      if ($h !== $squadId && $aw !== $squadId) continue;
      $games[] = [
        'slot_no' => (int)($a['slot_no'] ?? 0),
        'min_rest' => (int)($matchMeta[$mid]['min_rest_slots'] ?? 0),
        'match_id' => $mid,
      ];
    }
    if (count($games) <= 1) return true;
    usort($games, fn($x,$y)=>((int)$x['slot_no'] <=> (int)$y['slot_no'])) ;
    for ($i=1; $i<count($games); $i++) {
      $gap = (int)$games[$i]['slot_no'] - (int)$games[$i-1]['slot_no'];
      $req = (int)$games[$i]['min_rest'];
      if ($gap <= $req) return false;
    }
    return true;
  }

  function _lab_coach_free_at(string $date, int $slotNo, int $coachId, int $ignoreIdx, array $assignments, array $matchMeta): bool {
    foreach ($assignments as $idx => $a) {
      if ($idx === $ignoreIdx) continue;
      if ((string)($a['date'] ?? '') !== $date) continue;
      if ((int)($a['slot_no'] ?? 0) !== $slotNo) continue;
      $mid = (int)($a['match_id'] ?? 0);
      if ($mid <= 0) continue;
      $cids = $matchMeta[$mid]['coach_ids'] ?? ($a['coach_ids'] ?? []);
      foreach ($cids as $cid) {
        if ((int)$cid === $coachId) return false;
      }
    }
    return true;
  }

  function _lab_can_place_idx(int $idx, string $date, int $slotNo, string $fieldCode, array $assignments, array $matchMeta, array $occupied, array $dayMap, int $slotStrideMinutes, string $prefer, array $singleCols, array $pairCols): bool {
    if (!isset($assignments[$idx])) return false;
    if (!isset($dayMap[$date])) return false;

    $cols = _lab_cols_for_slot($date, $slotNo, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols);
    if (!in_array($fieldCode, $cols, true)) return false;

    if (isset($occupied[$date][$slotNo][$fieldCode]) && (int)$occupied[$date][$slotNo][$fieldCode] !== $idx) return false;
    if (_lab_stage_event_violation($date, $slotNo, $dayMap, $slotStrideMinutes, (int)($GLOBALS['__lab_slot_last_minutes'] ?? $slotStrideMinutes))) return false;

    $mid = (int)($assignments[$idx]['match_id'] ?? 0);
    if ($mid <= 0 || !isset($matchMeta[$mid])) return false;

    $allowed = $matchMeta[$mid]['allowed_set'] ?? null;
    if (!$allowed || empty($allowed[$fieldCode])) return false;

    $cids = $matchMeta[$mid]['coach_ids'] ?? [];
    foreach ($cids as $cid) {
      $cid = (int)$cid;
      if ($cid <= 0) continue;
      if (!_lab_coach_free_at($date, $slotNo, $cid, $idx, $assignments, $matchMeta)) return false;
    }

    $tmp = $assignments;
    $tmp[$idx]['slot_no'] = $slotNo;
    $tmp[$idx]['time'] = _lab_time_for_slot($date, $slotNo, $dayMap, $slotStrideMinutes);
    $tmp[$idx]['field_code'] = $fieldCode;

    $h = (int)($matchMeta[$mid]['home_squad_id'] ?? 0);
    $aw = (int)($matchMeta[$mid]['away_squad_id'] ?? 0);

    // squad_constraints: hard rule
    if ($h > 0 && _lab_squad_constraint_violation($date, $slotNo, $h)) return false;
    if ($aw > 0 && _lab_squad_constraint_violation($date, $slotNo, $aw)) return false;
    if (_lab_shared_rule_violation($date, $slotNo, $assignments[$idx], $tmp, $matchMeta)) return false;
    if (_lab_playoff_dependency_violation($date, $slotNo, $assignments[$idx], $tmp, $matchMeta, $GLOBALS['__lab_day_index_by_date'] ?? [])) return false;

    if ($h > 0 && !_lab_check_rest_for_squad($date, $h, $tmp, $matchMeta)) return false;
    if ($aw > 0 && !_lab_check_rest_for_squad($date, $aw, $tmp, $matchMeta)) return false;

    return true;
  }

  function _lab_apply_move_idx(int $idx, string $date, int $slotNo, string $fieldCode, array &$assignments, array &$occupied, array $dayMap, int $slotStrideMinutes): void {
    $oldDate = (string)($assignments[$idx]['date'] ?? '');
    $oldSlot = (int)($assignments[$idx]['slot_no'] ?? 0);
    $oldField = (string)($assignments[$idx]['field_code'] ?? '');
    if ($oldDate !== '' && $oldSlot > 0 && $oldField !== '' && isset($occupied[$oldDate][$oldSlot][$oldField]) && (int)$occupied[$oldDate][$oldSlot][$oldField] === $idx) {
      unset($occupied[$oldDate][$oldSlot][$oldField]);
    }

    $assignments[$idx]['date'] = $date;
    $assignments[$idx]['slot_no'] = $slotNo;
    $assignments[$idx]['time'] = _lab_time_for_slot($date, $slotNo, $dayMap, $slotStrideMinutes);
    $assignments[$idx]['field_code'] = $fieldCode;

    $occupied[$date][$slotNo][$fieldCode] = $idx;
  }

  function _lab_rebuild_occupied(array $assignments): array {
    $occ = [];
    foreach ($assignments as $idx => $a) {
      $date = (string)($a['date'] ?? '');
      $slotNo = (int)($a['slot_no'] ?? 0);
      $fc = (string)($a['field_code'] ?? '');
      if ($date === '' || $slotNo <= 0 || $fc === '') continue;
      $occ[$date][$slotNo][$fc] = (int)$idx;
    }
    return $occ;
  }

  function _lab_reset_conflict_flags(array &$assignments): void {
    foreach ($assignments as &$a) {
      $a['coach_conflict'] = 0;
      $a['coach_conflict_ids'] = [];
      $a['coach_conflict_with'] = [];
    }
    unset($a);
  }

  function _lab_apply_conflict_flags(array &$assignments, array $matchMeta): array {
    _lab_reset_conflict_flags($assignments);

    $by = [];
    foreach ($assignments as $idx => $a) {
      $date = (string)($a['date'] ?? '');
      $slotNo = (int)($a['slot_no'] ?? 0);
      $mid = (int)($a['match_id'] ?? 0);
      if ($date === '' || $slotNo <= 0 || $mid <= 0) continue;
      $cids = $matchMeta[$mid]['coach_ids'] ?? ($a['coach_ids'] ?? []);
      foreach ($cids as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        $k = $date.'|'.$slotNo.'|'.$cid;
        if (!isset($by[$k])) $by[$k] = [];
        $by[$k][] = (int)$idx;
      }
    }

    $confKeys = [];
    foreach ($by as $k => $idxs) {
      if (count($idxs) <= 1) continue;
      $confKeys[] = $k;
      $parts = explode('|', $k);
      $cid = (int)($parts[2] ?? 0);

      foreach ($idxs as $i) {
        $assignments[$i]['coach_conflict'] = 1;
        if ($cid > 0 && !in_array($cid, $assignments[$i]['coach_conflict_ids'], true)) {
          $assignments[$i]['coach_conflict_ids'][] = $cid;
        }
        foreach ($idxs as $j) {
          if ($j === $i) continue;
          $mid2 = (int)($assignments[$j]['match_id'] ?? 0);
          if ($mid2 > 0 && !in_array($mid2, $assignments[$i]['coach_conflict_with'], true)) {
            $assignments[$i]['coach_conflict_with'][] = $mid2;
          }
        }
      }
    }

    return $confKeys;
  }

  function _lab_resolve_coach_conflicts(array &$assignments, array $calendar, int $slotStrideMinutes, string $prefer, array $singleCols, array $pairCols, array $matchMeta): array {
    $dayMap = _lab_day_map($calendar);
    $occupied = _lab_rebuild_occupied($assignments);

    $maxPasses = 6;
    $maxShift = 3;
    $moves = 0;

    for ($pass=0; $pass<$maxPasses; $pass++) {
      $conflicts = _lab_build_conflicts($assignments, $matchMeta);
      if (!$conflicts) break;

      $fixedThisPass = false;

      foreach ($conflicts as $conf) {
        $date = (string)$conf['date'];
        $slotNo = (int)$conf['slot_no'];
        $idxs = $conf['idxs'];

        $tryIdxs = $idxs;
        usort($tryIdxs, function($i, $j) use ($assignments, $matchMeta){
          $mi = (int)($assignments[$i]['match_id'] ?? 0);
          $mj = (int)($assignments[$j]['match_id'] ?? 0);
          $ai = isset($matchMeta[$mi]['allowed_set']) ? count($matchMeta[$mi]['allowed_set']) : 0;
          $aj = isset($matchMeta[$mj]['allowed_set']) ? count($matchMeta[$mj]['allowed_set']) : 0;
          return $aj <=> $ai;
        });

        foreach ($tryIdxs as $idxMove) {
          $midMove = (int)($assignments[$idxMove]['match_id'] ?? 0);
          if ($midMove <= 0) continue;
          if ((string)($assignments[$idxMove]['phase'] ?? '') === 'playoff') continue;

          for ($d=1; $d<=$maxShift; $d++) {
            foreach ([-$d, +$d] as $delta) {
              $tSlot = $slotNo + $delta;
              if ($tSlot <= 0) continue;

              if (_lab_stage_event_violation($date, $tSlot, $dayMap, $slotStrideMinutes, (int)($GLOBALS['__lab_slot_last_minutes'] ?? $slotStrideMinutes))) continue;
      $cols = _lab_cols_for_slot($date, $tSlot, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols);

              foreach ($cols as $fc) {
                $fc = (string)$fc;
                $allowed = $matchMeta[$midMove]['allowed_set'] ?? null;
                if (!$allowed || empty($allowed[$fc])) continue;

                if (empty($occupied[$date][$tSlot][$fc])) {
                  if (_lab_can_place_idx($idxMove, $date, $tSlot, $fc, $assignments, $matchMeta, $occupied, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols)) {
                    _lab_apply_move_idx($idxMove, $date, $tSlot, $fc, $assignments, $occupied, $dayMap, $slotStrideMinutes);
                    $moves++; $fixedThisPass = true;
                    break 4;
                  }
                }

                $idxBlock = (int)($occupied[$date][$tSlot][$fc] ?? -1);
                if ($idxBlock < 0 || !isset($assignments[$idxBlock])) continue;

                $oldSlot = (int)($assignments[$idxMove]['slot_no'] ?? 0);
                $oldField= (string)($assignments[$idxMove]['field_code'] ?? '');
                $colsOld = _lab_cols_for_slot($date, $oldSlot, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols);

                $midBlock = (int)($assignments[$idxBlock]['match_id'] ?? 0);
                if ($midBlock > 0) {
                  $allowedBlock = $matchMeta[$midBlock]['allowed_set'] ?? null;
                  if ($allowedBlock && !empty($allowedBlock[$oldField]) && in_array($oldField, $colsOld, true)) {
                    $tmp = $assignments;
                    $tmp[$idxMove]['slot_no'] = $tSlot;
                    $tmp[$idxMove]['time'] = _lab_time_for_slot($date, $tSlot, $dayMap, $slotStrideMinutes);
                    $tmp[$idxMove]['field_code'] = $fc;

                    $tmp[$idxBlock]['slot_no'] = $oldSlot;
                    $tmp[$idxBlock]['time'] = _lab_time_for_slot($date, $oldSlot, $dayMap, $slotStrideMinutes);
                    $tmp[$idxBlock]['field_code'] = $oldField;

                    $ok = true;
                    foreach (($matchMeta[$midMove]['coach_ids'] ?? []) as $cid) {
                      $cid=(int)$cid; if ($cid<=0) continue;
                      if (!_lab_coach_free_at($date, $tSlot, $cid, $idxMove, $tmp, $matchMeta)) { $ok=false; break; }
                    }
                    if ($ok) foreach (($matchMeta[$midBlock]['coach_ids'] ?? []) as $cid) {
                      $cid=(int)$cid; if ($cid<=0) continue;
                      if (!_lab_coach_free_at($date, $oldSlot, $cid, $idxBlock, $tmp, $matchMeta)) { $ok=false; break; }
                    }

                    if ($ok) {
                      $aff = [];
                      foreach ([$midMove, $midBlock] as $mid) {
                        $h=(int)($matchMeta[$mid]['home_squad_id'] ?? 0);
                        $aw=(int)($matchMeta[$mid]['away_squad_id'] ?? 0);
                        if ($h>0) $aff[$h]=true;
                        if ($aw>0) $aff[$aw]=true;
                      }
                      foreach (array_keys($aff) as $sid) {
                        if (!_lab_check_rest_for_squad($date, (int)$sid, $tmp, $matchMeta)) { $ok=false; break; }
                      }
                    }

                    if ($ok) {
                      _lab_apply_move_idx($idxMove, $date, $tSlot, $fc, $assignments, $occupied, $dayMap, $slotStrideMinutes);
                      _lab_apply_move_idx($idxBlock, $date, $oldSlot, $oldField, $assignments, $occupied, $dayMap, $slotStrideMinutes);
                      $moves++; $fixedThisPass = true;
                      break 4;
                    }
                  }
                }

                $maxShift2 = $maxShift + 1;
                for ($d2=1; $d2<=$maxShift2; $d2++) {
                  foreach ([-$d2, +$d2] as $delta2) {
                    $slotZ = $tSlot + $delta2;
                    if ($slotZ <= 0) continue;

                    $colsZ = _lab_cols_for_slot($date, $slotZ, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols);
                    $midBlock = (int)($assignments[$idxBlock]['match_id'] ?? 0);
                    if ($midBlock <= 0) continue;
                    $allowedBlock = $matchMeta[$midBlock]['allowed_set'] ?? null;
                    if (!$allowedBlock) continue;

                    foreach ($colsZ as $fcZ) {
                      $fcZ = (string)$fcZ;
                      if (empty($allowedBlock[$fcZ])) continue;
                      if (!empty($occupied[$date][$slotZ][$fcZ])) continue;

                      $tmp = $assignments;
                      $tmp[$idxBlock]['slot_no'] = $slotZ;
                      $tmp[$idxBlock]['time'] = _lab_time_for_slot($date, $slotZ, $dayMap, $slotStrideMinutes);
                      $tmp[$idxBlock]['field_code'] = $fcZ;

                      $tmp[$idxMove]['slot_no'] = $tSlot;
                      $tmp[$idxMove]['time'] = _lab_time_for_slot($date, $tSlot, $dayMap, $slotStrideMinutes);
                      $tmp[$idxMove]['field_code'] = $fc;

                      $ok = true;
                      foreach (($matchMeta[$midMove]['coach_ids'] ?? []) as $cid) {
                        $cid=(int)$cid; if ($cid<=0) continue;
                        if (!_lab_coach_free_at($date, $tSlot, $cid, $idxMove, $tmp, $matchMeta)) { $ok=false; break; }
                      }
                      if ($ok) foreach (($matchMeta[$midBlock]['coach_ids'] ?? []) as $cid) {
                        $cid=(int)$cid; if ($cid<=0) continue;
                        if (!_lab_coach_free_at($date, $slotZ, $cid, $idxBlock, $tmp, $matchMeta)) { $ok=false; break; }
                      }

                      if ($ok) {
                        $aff = [];
                        foreach ([$midMove, $midBlock] as $mid) {
                          $h=(int)($matchMeta[$mid]['home_squad_id'] ?? 0);
                          $aw=(int)($matchMeta[$mid]['away_squad_id'] ?? 0);
                          if ($h>0) $aff[$h]=true;
                          if ($aw>0) $aff[$aw]=true;
                        }
                        foreach (array_keys($aff) as $sid) {
                          if (!_lab_check_rest_for_squad($date, (int)$sid, $tmp, $matchMeta)) { $ok=false; break; }
                        }
                      }

                      if ($ok) {
                        _lab_apply_move_idx($idxBlock, $date, $slotZ, $fcZ, $assignments, $occupied, $dayMap, $slotStrideMinutes);
                        _lab_apply_move_idx($idxMove,  $date, $tSlot, $fc,  $assignments, $occupied, $dayMap, $slotStrideMinutes);
                        $moves++; $fixedThisPass = true;
                        break 5;
                      }
                    }
                  }
                }
              }
            }
          }
        }

        if ($fixedThisPass) break;
      }

      if (!$fixedThisPass) break;
    }

    _lab_apply_conflict_flags($assignments, $matchMeta);
    $confKeys = _lab_build_conflicts($assignments, $matchMeta);

    return ['moves'=>$moves, 'conflicts_left'=>count($confKeys)];
  }


  function _lab_half_free_for_pair(array $occSlot, string $pairCode): bool {
    if ($pairCode === '12') {
      return empty($occSlot['12']) && empty($occSlot['1']) && empty($occSlot['2']);
    }
    if ($pairCode === '34') {
      return empty($occSlot['34']) && empty($occSlot['3']) && empty($occSlot['4']);
    }
    return false;
  }

  function _lab_single_field_free(array $occSlot, string $fieldCode): bool {
    if ($fieldCode === '1' || $fieldCode === '2') {
      if (!empty($occSlot['12'])) return false;
      return empty($occSlot[$fieldCode]);
    }
    if ($fieldCode === '3' || $fieldCode === '4') {
      if (!empty($occSlot['34'])) return false;
      return empty($occSlot[$fieldCode]);
    }
    return false;
  }

  function _lab_can_place_any(int $idx, string $date, int $slotNo, string $fieldCode, array $assignments, array $matchMeta, array $occupied, array $dayMap, int $slotStrideMinutes): bool {
    if (!isset($assignments[$idx])) return false;
    if (!isset($dayMap[$date])) return false;

    $mid = (int)($assignments[$idx]['match_id'] ?? 0);
    if ($mid <= 0 || !isset($matchMeta[$mid])) return false;

    $allowed = $matchMeta[$mid]['allowed_set'] ?? null;
    if (!$allowed || empty($allowed[$fieldCode])) return false;

    $occSlot = $occupied[$date][$slotNo] ?? [];

    // occupancy / half-rules
    if ($fieldCode === '12' || $fieldCode === '34') {
      if (!_lab_half_free_for_pair($occSlot, $fieldCode)) return false;
    } else {
      if (!_lab_single_field_free($occSlot, $fieldCode)) return false;
    }

    if (isset($occupied[$date][$slotNo][$fieldCode]) && (int)$occupied[$date][$slotNo][$fieldCode] !== $idx) return false;
    if (_lab_stage_event_violation($date, $slotNo, $dayMap, $slotStrideMinutes, (int)($GLOBALS['__lab_slot_last_minutes'] ?? $slotStrideMinutes))) return false;

    // coach free: для playoff не блокируем постановку тренерскими проверками.
    if ((string)($matchMeta[$mid]['phase'] ?? '') !== 'playoff') {
      $cids = $matchMeta[$mid]['coach_ids'] ?? [];
      foreach ($cids as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        if (!_lab_coach_free_at($date, $slotNo, $cid, $idx, $assignments, $matchMeta)) return false;
      }
    }

    // rest check
    $tmp = $assignments;
    $tmp[$idx]['slot_no'] = $slotNo;
    $tmp[$idx]['time'] = _lab_time_for_slot($date, $slotNo, $dayMap, $slotStrideMinutes);
    $tmp[$idx]['field_code'] = $fieldCode;

    $h = (int)($matchMeta[$mid]['home_squad_id'] ?? 0);
    $aw = (int)($matchMeta[$mid]['away_squad_id'] ?? 0);
    if (_lab_shared_rule_violation($date, $slotNo, $assignments[$idx], $tmp, $matchMeta)) return false;
    if ($h > 0 && !_lab_check_rest_for_squad($date, $h, $tmp, $matchMeta)) return false;
    if ($aw > 0 && !_lab_check_rest_for_squad($date, $aw, $tmp, $matchMeta)) return false;

    return true;
  }


  function _lab_spread_quota_for_day(int $total, int $daysTotal, int $dayPos): int {
    if ($total <= 0 || $daysTotal <= 0) return 0;
    if ($dayPos < 0) $dayPos = 0;
    if ($dayPos >= $daysTotal) $dayPos = $daysTotal - 1;

    $prev = (int)floor(($dayPos * $total) / $daysTotal);
    $curr = (int)floor((($dayPos + 1) * $total) / $daysTotal);
    $quota = $curr - $prev;
    return max(0, $quota);
  }

function _lab_group_spread_hard_skip(
  array $m,
  int $dayIdx,
  int $lastDayIdx,
  array $remainingCatMatches,
  array $remainingGroupMatches,
  array $catPlacedByDay,
  array $groupPlacedByDay
): bool {
  // Только группы
  if ((string)($m['phase'] ?? '') !== 'group') return false;

  $mFirstDayIdx = (int)($m['first_day_idx'] ?? 0);
  $mLastDayIdx  = (int)($m['last_day_idx'] ?? $lastDayIdx);

  if ($dayIdx < $mFirstDayIdx) return false;
  if ($dayIdx >= $mLastDayIdx) return false; // последний день разрешаем

  $catKey = (string)($m['category'] ?? '');
  if ($catKey === '') return false;

  $grpKey = $catKey . '|' . (string)($m['group_code'] ?? '');

  $daysTotal = max(1, $mLastDayIdx - $mFirstDayIdx + 1);
  $dayPos = max(0, $dayIdx - $mFirstDayIdx);

  // Сколько уже поставили
  $catPlacedTotal = 0;
  foreach (($catPlacedByDay[$catKey] ?? []) as $dIdx => $cnt) {
    if ($dIdx < $mFirstDayIdx || $dIdx > $mLastDayIdx) continue;
    $catPlacedTotal += (int)$cnt;
  }

  $grpPlacedTotal = 0;
  foreach (($groupPlacedByDay[$grpKey] ?? []) as $dIdx => $cnt) {
    if ($dIdx < $mFirstDayIdx || $dIdx > $mLastDayIdx) continue;
    $grpPlacedTotal += (int)$cnt;
  }

  $catTotal = $catPlacedTotal + max(0, (int)($remainingCatMatches[$catKey] ?? 0));
  $grpTotal = $grpPlacedTotal + max(0, (int)($remainingGroupMatches[$grpKey] ?? 0));

  if ($catTotal <= 0 || $grpTotal <= 0) return false;

  $catQuotaToday = _lab_spread_quota_for_day($catTotal, $daysTotal, $dayPos);
  $grpQuotaToday = _lab_spread_quota_for_day($grpTotal, $daysTotal, $dayPos);

  $catToday = (int)($catPlacedByDay[$catKey][$dayIdx] ?? 0);
  $grpToday = (int)($groupPlacedByDay[$grpKey][$dayIdx] ?? 0);

  // 🔥 ключевая логика
  if ($catToday >= $catQuotaToday) return true;
  if ($grpToday >= $grpQuotaToday) return true;

  return false;
}

  function _lab_pack_singles_for_pair_half(string $date, int $slotNo, string $pairWanted, array &$assignments, array &$occupied, array $dayMap, int $slotStrideMinutes, array $matchMeta): int {
    // HARD: упаковать одиночные матчи в одну половину, чтобы освободить вторую под 12/34
    $occSlot = $occupied[$date][$slotNo] ?? [];

    if ($pairWanted === '34') {
      // хотим освободить 3-4 => пакуем в 1-2 (нельзя, если уже занято парой 12)
      if (!empty($occSlot['12'])) return 0;
      $keep = ['1','2'];
    } else {
      // хотим освободить 1-2 => пакуем в 3-4 (нельзя, если уже занято парой 34)
      if (!empty($occSlot['34'])) return 0;
      $keep = ['3','4'];
    }

    $singleFields = ['1','2','3','4'];
    $idxs = [];
    foreach ($singleFields as $f) {
      if (isset($occSlot[$f])) $idxs[] = (int)$occSlot[$f];
    }
    $idxs = array_values(array_unique($idxs));

    if (count($idxs) === 0) return 0;
    if (count($idxs) > 2) return 0;

    $idxSet = array_flip($idxs);

    $plans = [];
    if (count($idxs) === 1) {
      $plans = [[$keep[0]], [$keep[1]]];
    } else {
      $plans = [[$keep[0], $keep[1]], [$keep[1], $keep[0]]];
    }

    foreach ($plans as $plan) {
      $ok = true;
      for ($i=0; $i<count($idxs); $i++) {
        $idx = $idxs[$i];
        $targetField = $plan[$i];
        $mid = (int)($assignments[$idx]['match_id'] ?? 0);
        if ($mid <= 0 || !isset($matchMeta[$mid])) { $ok=false; break; }
        //$allowed = $matchMeta[$mid]['allowed_set'] ?? null;
        //if (!$allowed || empty($allowed[$targetField])) { $ok=false; break; }
        //if (isset($occSlot[$targetField]) && (int)$occSlot[$targetField] !== $idx) { $ok=false; break; }

$allowed = $matchMeta[$mid]['allowed_set'] ?? null;
if (!$allowed) { $ok=false; break; }

// если матч допускается хотя бы на одном одиночном поле — считаем, что внутри 1–4 его можно переставлять
$hasAnySingle =
  !empty($allowed['1']) || !empty($allowed['2']) || !empty($allowed['3']) || !empty($allowed['4']);

if (!$hasAnySingle && empty($allowed[$targetField])) { $ok=false; break; }
// если $hasAnySingle=true — целевое поле 1/2/3/4 разрешаем без точечной проверки


        if (isset($occSlot[$targetField])) {
          $occIdx = (int)$occSlot[$targetField];
          if ($occIdx !== $idx && !isset($idxSet[$occIdx])) { $ok=false; break; }
        }
      }
      if (!$ok) continue;

      $moved = 0;
      for ($i=0; $i<count($idxs); $i++) {
        $idx = $idxs[$i];
        $targetField = $plan[$i];
        $curField = (string)($assignments[$idx]['field_code'] ?? '');
        if ($curField === $targetField) continue;
        _lab_apply_move_idx($idx, $date, $slotNo, $targetField, $assignments, $occupied, $dayMap, $slotStrideMinutes);
        $moved++;
      }
      return $moved;
    }

    return 0;
  }

  function _lab_find_candidate_idx(string $date, int $slotNo, string $targetFieldCode, array $assignments, array $matchMeta, array $occupied, array $dayMap, int $slotStrideMinutes): ?int {
    $bestIdx = null;
    $bestSlot = PHP_INT_MAX;

    foreach ($assignments as $idx => $a) {
      if ((string)($a['date'] ?? '') !== $date) continue;
      $curSlot = (int)($a['slot_no'] ?? 0);
      if ($curSlot <= $slotNo) continue;

      $mid = (int)($a['match_id'] ?? 0);
      if ($mid <= 0 || !isset($matchMeta[$mid])) continue;
      $allowed = $matchMeta[$mid]['allowed_set'] ?? null;
      if (!$allowed || empty($allowed[$targetFieldCode])) continue;

      if ($curSlot >= $bestSlot) continue;

      if (_lab_can_place_any((int)$idx, $date, $slotNo, $targetFieldCode, $assignments, $matchMeta, $occupied, $dayMap, $slotStrideMinutes)) {
        $bestIdx = (int)$idx;
        $bestSlot = $curSlot;
      }
    }

    return $bestIdx;
  }

  function _lab_mix_12_34(string $mix, array &$assignments, array $calendar, int $slotStrideMinutes, string $prefer, array $singleCols, array $pairCols, array $matchMeta): array {
    $stats = ['moves'=>0, 'packed'=>0, 'pulled_singles'=>0];
    if ($mix === 'OFF') return $stats;

    $dayMap = _lab_day_map($calendar);
    $occupied = _lab_rebuild_occupied($assignments);

    foreach ($dayMap as $date => $di) {
      $maxSlotNo = (int)($di['slotCount'] ?? 0);
      foreach ($assignments as $a) {
        if ((string)($a['date'] ?? '') !== $date) continue;
        $maxSlotNo = max($maxSlotNo, (int)($a['slot_no'] ?? 0));
      }
      if ($maxSlotNo <= 0) continue;

      for ($slotNo=1; $slotNo <= $maxSlotNo; $slotNo++) {
        $colsBase = _lab_cols_for_slot($date, $slotNo, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols);
        $isPairBase = in_array('12', $colsBase, true) || in_array('34', $colsBase, true);

        if (!$isPairBase) {
          // base: single (1-4)
          foreach (['12','34'] as $pairCode) {
            $occSlot = $occupied[$date][$slotNo] ?? [];

            if (_lab_half_free_for_pair($occSlot, $pairCode)) {
              $idxCand = _lab_find_candidate_idx($date, $slotNo, $pairCode, $assignments, $matchMeta, $occupied, $dayMap, $slotStrideMinutes);
              if ($idxCand !== null) {
                _lab_apply_move_idx($idxCand, $date, $slotNo, $pairCode, $assignments, $occupied, $dayMap, $slotStrideMinutes);
                $stats['moves']++;
              }
              continue;
            }

            if ($mix === 'HARD') {
              $m = _lab_pack_singles_for_pair_half($date, $slotNo, $pairCode, $assignments, $occupied, $dayMap, $slotStrideMinutes, $matchMeta);
              if ($m > 0) {
                $stats['packed'] += $m;
                $occSlot = $occupied[$date][$slotNo] ?? [];
                if (_lab_half_free_for_pair($occSlot, $pairCode)) {
                  $idxCand = _lab_find_candidate_idx($date, $slotNo, $pairCode, $assignments, $matchMeta, $occupied, $dayMap, $slotStrideMinutes);
                  if ($idxCand !== null) {
                    _lab_apply_move_idx($idxCand, $date, $slotNo, $pairCode, $assignments, $occupied, $dayMap, $slotStrideMinutes);
                    $stats['moves']++;
                  }
                }
              }
            }
          }
        } else {
          // base: pair (12/34)
          // ВАЖНО (по требованию): если в парном слоте занята только одна половина (12 или 34),
          // то вторую половину можно заполнить ещё одним матчем 12/34 на то же время.
          // Это и есть «уплотнение», без смены базовой сетки.
          foreach (['12','34'] as $halfCode) {
            $occSlot = $occupied[$date][$slotNo] ?? [];
            if (!empty($occSlot[$halfCode])) continue; // половина уже занята парой

            // половина свободна только если не заняты её одиночные поля и не занята сама пара
            if (!_lab_half_free_for_pair($occSlot, $halfCode)) continue;

            $idxCand = _lab_find_candidate_idx($date, $slotNo, $halfCode, $assignments, $matchMeta, $occupied, $dayMap, $slotStrideMinutes);
            if ($idxCand !== null) {
              _lab_apply_move_idx($idxCand, $date, $slotNo, $halfCode, $assignments, $occupied, $dayMap, $slotStrideMinutes);
              $stats['moves']++;
            }
          }

          // Доп. режим (debug/эксперимент): если включён HARD и в парном слоте по какой-то причине
          // оказались одиночные матчи, пробуем подтянуть их раньше. Это не основная логика «уплотнения».
          if ($mix === 'HARD') {
            $pairs = ['12'=>['1','2'], '34'=>['3','4']];
            foreach ($pairs as $halfCode => $fields) {
              $occSlot = $occupied[$date][$slotNo] ?? [];
              if (!empty($occSlot[$halfCode])) continue; // половина занята парой

              foreach ($fields as $f) {
                $occSlot = $occupied[$date][$slotNo] ?? [];
                if (!_lab_single_field_free($occSlot, (string)$f)) continue;
                $idxCand = _lab_find_candidate_idx($date, $slotNo, (string)$f, $assignments, $matchMeta, $occupied, $dayMap, $slotStrideMinutes);
                if ($idxCand !== null) {
                  _lab_apply_move_idx($idxCand, $date, $slotNo, (string)$f, $assignments, $occupied, $dayMap, $slotStrideMinutes);
                  $stats['pulled_singles']++;
                }
              }
            }
          }
        }
      }
    }

    _lab_apply_conflict_flags($assignments, $matchMeta);
    return $stats;
  }

  // AUTO local-compress (anti-OOB):
  // если матч улетел в OOB, но внутри официального окна есть «дыры»,
  // пытаемся:
  //   1) напрямую переместить OOB в пустую ячейку
  //   2) если не выходит — сделать локальный swap-chain из 2 шагов:
  //        in-window матч -> пустая ячейка, OOB матч -> освобождённая ячейка
  // Это именно минимальные перестановки внутри дня, без HARD-перетасовки 1↔2↔3↔4.
  function _lab_auto_local_compress(array &$assignments, array &$occupied, array $calendar, int $slotStrideMinutes, string $prefer, array $singleCols, array $pairCols, array $matchMeta): array {
    $stats = ['moves'=>0, 'rescued'=>0, 'swap_rescues'=>0];

    $dayMap = _lab_day_map($calendar);

    // official slot counts by date
    $official = [];
    foreach ($calendar as $day) {
      $d = (string)($day['date'] ?? '');
      if ($d === '') continue;
      $official[$d] = is_array($day['slots'] ?? null) ? count($day['slots']) : 0;
    }

    foreach ($official as $date => $slotCount) {
      if ($slotCount <= 0) continue;

      // collect empty cells inside official window
      $empties = []; // each: ['slot'=>int,'field'=>string]
      for ($slotNo=1; $slotNo <= $slotCount; $slotNo++) {
        $cols = _lab_cols_for_slot($date, $slotNo, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols);
        foreach ($cols as $fc) {
          $fc = (string)$fc;
          if ($fc === '') continue;
          if (!isset($occupied[$date][$slotNo][$fc])) {
            $empties[] = ['slot'=>$slotNo, 'field'=>$fc];
          }
        }
      }
      if (!$empties) continue;

      // collect OOB indices for this date
      $oobIdxs = [];
      foreach ($assignments as $idx => $a) {
        if ((string)($a['date'] ?? '') !== $date) continue;
        $sn = (int)($a['slot_no'] ?? 0);
        if ($sn <= 0) continue;
        if (($sn - 1) >= $slotCount) $oobIdxs[] = (int)$idx;
      }
      if (!$oobIdxs) continue;

      // helper: list in-window idx by field
      $inByField = [];
      foreach ($assignments as $idx => $a) {
        if ((string)($a['date'] ?? '') !== $date) continue;
        $sn = (int)($a['slot_no'] ?? 0);
        if ($sn <= 0 || ($sn - 1) >= $slotCount) continue;
        $fc = (string)($a['field_code'] ?? '');
        if ($fc === '') continue;
        $inByField[$fc][] = (int)$idx;
      }
      foreach ($inByField as $fc => &$list) {
        usort($list, function($i,$j) use ($assignments){
          return ((int)($assignments[$i]['slot_no'] ?? 0) <=> (int)($assignments[$j]['slot_no'] ?? 0));
        });
      }
      unset($list);

      // 1) direct move OOB -> empty
      foreach ($oobIdxs as $idxOob) {
        foreach ($empties as $k => $e) {
          $slotE = (int)$e['slot'];
          $fcE = (string)$e['field'];
          if (_lab_can_place_idx($idxOob, $date, $slotE, $fcE, $assignments, $matchMeta, $occupied, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols)) {
            _lab_apply_move_idx($idxOob, $date, $slotE, $fcE, $assignments, $occupied, $dayMap, $slotStrideMinutes);
            $stats['moves']++;
            $stats['rescued']++;
            // remove this empty
            unset($empties[$k]);
            $empties = array_values($empties);
            break;
          }
        }
      }

      // обновим список OOB после прямых переносов
      $oobIdxs2 = [];
      foreach ($assignments as $idx => $a) {
        if ((string)($a['date'] ?? '') !== $date) continue;
        $sn = (int)($a['slot_no'] ?? 0);
        if ($sn <= 0) continue;
        if (($sn - 1) >= $slotCount) $oobIdxs2[] = (int)$idx;
      }
      if (!$oobIdxs2 || !$empties) continue;

      // 2) swap-rescue
      foreach ($oobIdxs2 as $idxOob) {
        foreach ($empties as $e) {
          $slotE = (int)$e['slot'];
          $fcE = (string)$e['field'];
          $cands = $inByField[$fcE] ?? [];
          if (!$cands) continue;

          foreach ($cands as $idxIn) {
            $slotIn = (int)($assignments[$idxIn]['slot_no'] ?? 0);
            $fcIn = (string)($assignments[$idxIn]['field_code'] ?? '');
            if ($slotIn <= 0 || $fcIn === '') continue;

            // in-window -> empty?
            if (!_lab_can_place_idx($idxIn, $date, $slotE, $fcE, $assignments, $matchMeta, $occupied, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols)) continue;

            // backup
            $bakIn = $assignments[$idxIn];
            $bakOob = $assignments[$idxOob];
            $occBak = $occupied;

            _lab_apply_move_idx($idxIn, $date, $slotE, $fcE, $assignments, $occupied, $dayMap, $slotStrideMinutes);

            // OOB -> freed cell?
            if (_lab_can_place_idx($idxOob, $date, $slotIn, $fcIn, $assignments, $matchMeta, $occupied, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols)) {
              _lab_apply_move_idx($idxOob, $date, $slotIn, $fcIn, $assignments, $occupied, $dayMap, $slotStrideMinutes);
              $stats['moves'] += 2;
              $stats['swap_rescues']++;
              $stats['rescued']++;
              break 2;
            }

            // rollback
            $assignments[$idxIn] = $bakIn;
            $assignments[$idxOob] = $bakOob;
            $occupied = $occBak;
          }
        }
      }

    }

    _lab_apply_conflict_flags($assignments, $matchMeta);
    return $stats;
  }


  function _lab_coach_day_objective(string $date, array $assignments): int {
    $byCoach = [];
    foreach ($assignments as $a) {
      if (!is_array($a)) continue;
      if ((string)($a['date'] ?? '') !== $date) continue;
      $slotNo = (int)($a['slot_no'] ?? 0);
      if ($slotNo <= 0) continue;
      $cids = $a['coach_ids'] ?? [];
      if (!is_array($cids) || !$cids) continue;
      foreach ($cids as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        $byCoach[$cid][] = $slotNo;
      }
    }

    $score = 0;
    foreach ($byCoach as $slots) {
      $slots = array_values(array_unique(array_map('intval', $slots)));
      sort($slots);
      if (count($slots) <= 1) continue;

      $maxGap = 0;
      $fragments = 0;
      for ($i = 1; $i < count($slots); $i++) {
        $gap = $slots[$i] - $slots[$i - 1] - 1;
        if ($gap < 0) $gap = 0;
        if ($gap > $maxGap) $maxGap = $gap;
        if ($gap > 0) $fragments++;
        $score += ($gap * $gap * 1700) + ($gap * 450);
      }
      $score += ($maxGap * $maxGap * 3200);
      $score += ($fragments * 4200);
    }

    return $score;
  }

  function _lab_assignment_valid_idx(int $idx, array $assignments, array $matchMeta, array $dayMap, int $slotStrideMinutes, string $prefer, array $singleCols, array $pairCols): bool {
    if (!isset($assignments[$idx])) return false;

    $a = $assignments[$idx];
    $date = (string)($a['date'] ?? '');
    $slotNo = (int)($a['slot_no'] ?? 0);
    $fieldCode = (string)($a['field_code'] ?? '');
    $mid = (int)($a['match_id'] ?? 0);
    if ($date === '' || $slotNo <= 0 || $fieldCode === '' || $mid <= 0) return false;
    if (!isset($dayMap[$date]) || !isset($matchMeta[$mid])) return false;
    if (_lab_stage_event_violation($date, $slotNo, $dayMap, $slotStrideMinutes, (int)($GLOBALS['__lab_slot_last_minutes'] ?? $slotStrideMinutes))) return false;

    $cols = _lab_cols_for_slot($date, $slotNo, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols);
    if (!in_array($fieldCode, $cols, true)) return false;

    $allowed = $matchMeta[$mid]['allowed_set'] ?? null;
    if (!$allowed || empty($allowed[$fieldCode])) return false;

    $occupied = _lab_rebuild_occupied($assignments);
    $occSlot = $occupied[$date][$slotNo] ?? [];
    $occSlotMinus = [];
    foreach ($occSlot as $fc => $occIdx) {
      if ((int)$occIdx === $idx) continue;
      $occSlotMinus[(string)$fc] = (int)$occIdx;
    }

    if ($fieldCode === '12' || $fieldCode === '34') {
      if (!_lab_half_free_for_pair($occSlotMinus, $fieldCode)) return false;
    } else {
      if (!_lab_single_field_free($occSlotMinus, $fieldCode)) return false;
    }

    if ((string)($matchMeta[$mid]['phase'] ?? '') !== 'playoff') {
      foreach (($matchMeta[$mid]['coach_ids'] ?? []) as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        if (!_lab_coach_free_at($date, $slotNo, $cid, $idx, $assignments, $matchMeta)) return false;
      }
    }

    $h = (int)($matchMeta[$mid]['home_squad_id'] ?? 0);
    $aw = (int)($matchMeta[$mid]['away_squad_id'] ?? 0);
    if ($h > 0 && _lab_squad_constraint_violation($date, $slotNo, $h)) return false;
    if ($aw > 0 && _lab_squad_constraint_violation($date, $slotNo, $aw)) return false;
    if (_lab_shared_rule_violation($date, $slotNo, $assignments[$idx], $assignments, $matchMeta)) return false;
    if (_lab_playoff_dependency_violation($date, $slotNo, $assignments[$idx], $assignments, $matchMeta, $GLOBALS['__lab_day_index_by_date'] ?? [])) return false;
    if ($h > 0 && !_lab_check_rest_for_squad($date, $h, $assignments, $matchMeta)) return false;
    if ($aw > 0 && !_lab_check_rest_for_squad($date, $aw, $assignments, $matchMeta)) return false;

    return true;
  }

  function _lab_optimize_day_by_coaches(array &$assignments, array $calendar, int $slotStrideMinutes, string $prefer, array $singleCols, array $pairCols, array $matchMeta): array {
    $stats = ['moves' => 0, 'days_tightened' => 0, 'score_before' => 0, 'score_after' => 0];

    $dayMap = _lab_day_map($calendar);
    $dates = [];
    foreach ($assignments as $a) {
      $d = (string)($a['date'] ?? '');
      if ($d !== '') $dates[$d] = true;
    }
    $dates = array_keys($dates);
    sort($dates);

    foreach ($dates as $date) {
      if (!isset($dayMap[$date])) continue;
      $officialSlotCount = (int)($dayMap[$date]['slotCount'] ?? 0);
      if ($officialSlotCount <= 0) continue;

      $before = _lab_coach_day_objective($date, $assignments);
      $stats['score_before'] += $before;
      if ($before <= 0) {
        $stats['score_after'] += $before;
        continue;
      }

      $improvedDay = false;
      $passes = 0;

      while ($passes < 12) {
        $passes++;
        $occupied = _lab_rebuild_occupied($assignments);
        $currentScore = _lab_coach_day_objective($date, $assignments);
        $bestScore = $currentScore;
        $bestAction = null;

        $dayIdxs = [];
        foreach ($assignments as $idx => $a) {
          if (!is_array($a)) continue;
          if ((string)($a['date'] ?? '') !== $date) continue;
          if (empty($a['coach_ids']) || !is_array($a['coach_ids'])) continue;
          if ((string)($a['phase'] ?? '') === 'playoff') continue;
          $dayIdxs[] = (int)$idx;
        }

        usort($dayIdxs, function($i, $j) use ($assignments) {
          return ((int)($assignments[$i]['slot_no'] ?? 0) <=> (int)($assignments[$j]['slot_no'] ?? 0));
        });

        foreach ($dayIdxs as $idxA) {
          $a = $assignments[$idxA] ?? null;
          if (!$a) continue;
          $curSlotA = (int)($a['slot_no'] ?? 0);
          $curFieldA = (string)($a['field_code'] ?? '');
          if ($curSlotA <= 0 || $curFieldA === '') continue;

          for ($targetSlotNo = 1; $targetSlotNo <= $officialSlotCount; $targetSlotNo++) {
            $cols = _lab_cols_for_slot($date, $targetSlotNo, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols);
            foreach ($cols as $targetField) {
              $targetField = (string)$targetField;
              if ($targetField === '') continue;
              if ($targetSlotNo === $curSlotA && $targetField === $curFieldA) continue;

              $idxB = $occupied[$date][$targetSlotNo][$targetField] ?? null;

              if ($idxB === null) {
                $tmp = $assignments;
                $tmpOccupied = $occupied;
                _lab_apply_move_idx($idxA, $date, $targetSlotNo, $targetField, $tmp, $tmpOccupied, $dayMap, $slotStrideMinutes);
                if (!_lab_assignment_valid_idx($idxA, $tmp, $matchMeta, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols)) continue;
                $afterScore = _lab_coach_day_objective($date, $tmp);
                if ($afterScore < $bestScore) {
                  $bestScore = $afterScore;
                  $bestAction = ['type' => 'move', 'idxA' => $idxA, 'slotA' => $targetSlotNo, 'fieldA' => $targetField];
                }
                continue;
              }

              $idxB = (int)$idxB;
              if ($idxB === $idxA) continue;
              if (!isset($assignments[$idxB])) continue;
              if ((string)($assignments[$idxB]['date'] ?? '') !== $date) continue;

              $tmp = $assignments;
              $tmpOccupied = $occupied;
              _lab_apply_move_idx($idxA, $date, $targetSlotNo, $targetField, $tmp, $tmpOccupied, $dayMap, $slotStrideMinutes);
              _lab_apply_move_idx($idxB, $date, $curSlotA, $curFieldA, $tmp, $tmpOccupied, $dayMap, $slotStrideMinutes);

              if (!_lab_assignment_valid_idx($idxA, $tmp, $matchMeta, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols)) continue;
              if (!_lab_assignment_valid_idx($idxB, $tmp, $matchMeta, $dayMap, $slotStrideMinutes, $prefer, $singleCols, $pairCols)) continue;

              $afterScore = _lab_coach_day_objective($date, $tmp);
              if ($afterScore < $bestScore) {
                $bestScore = $afterScore;
                $bestAction = ['type' => 'swap', 'idxA' => $idxA, 'slotA' => $targetSlotNo, 'fieldA' => $targetField, 'idxB' => $idxB, 'slotB' => $curSlotA, 'fieldB' => $curFieldA];
              }
            }
          }
        }

        if ($bestAction === null || $bestScore >= $currentScore) break;

        $occupied = _lab_rebuild_occupied($assignments);
        if ($bestAction['type'] === 'move') {
          _lab_apply_move_idx((int)$bestAction['idxA'], $date, (int)$bestAction['slotA'], (string)$bestAction['fieldA'], $assignments, $occupied, $dayMap, $slotStrideMinutes);
          $stats['moves']++;
        } else {
          _lab_apply_move_idx((int)$bestAction['idxA'], $date, (int)$bestAction['slotA'], (string)$bestAction['fieldA'], $assignments, $occupied, $dayMap, $slotStrideMinutes);
          _lab_apply_move_idx((int)$bestAction['idxB'], $date, (int)$bestAction['slotB'], (string)$bestAction['fieldB'], $assignments, $occupied, $dayMap, $slotStrideMinutes);
          $stats['moves'] += 2;
        }
        $improvedDay = true;
      }

      $after = _lab_coach_day_objective($date, $assignments);
      $stats['score_after'] += $after;
      if ($improvedDay && $after < $before) $stats['days_tightened']++;
    }

    _lab_apply_conflict_flags($assignments, $matchMeta);
    return $stats;
  }

try {
  // Подхватываем общий бутстрап проекта (авторизация/конфиг/DB) — ничего не выдумываем.
  _lab_require_if_exists(__DIR__ . '/_bootstrap.php');
  // auth может быть уже в bootstrap, но лишний require_once не вредит
  if (is_file(__DIR__ . '/_auth.php')) {
    require_once __DIR__ . '/_auth.php';
  }
  _lab_require_if_exists(__DIR__ . '/_schema.php');

  // Лаба — только для организатора (как и остальные organizer-экраны).
  if (function_exists('require_role')) {
    require_role('organizer');
  }

  $pdo = _lab_get_pdo();
  if (function_exists('ensure_stage_days_table')) ensure_stage_days_table($pdo);
  if (function_exists('ensure_schedule_stage_events_table')) ensure_schedule_stage_events_table($pdo);

  $stageId = isset($_GET['stage_id']) ? (int)$_GET['stage_id'] : 0;
  if ($stageId <= 0) {
    _lab_out(['ok'=>false, 'error'=>'stage_id is required'], 200);
  }

  // LAB: по умолчанию строим только групповой этап.
// Если include_playoff=1 — подмешиваем плей-офф матчи в тот же формат.
$includePlayoff = isset($_GET['include_playoff']) ? (int)$_GET['include_playoff'] : 0;
if ($includePlayoff !== 1) $includePlayoff = 0;

// include_group: 1 = генерировать группы; 0 = группы не пересчитывать (если есть сохранённое расписание)
$includeGroup = isset($_GET['include_group']) ? (int)$_GET['include_group'] : 1;
if ($includeGroup !== 1) $includeGroup = 0;

$phases = $includePlayoff === 1 ? ['group','playoff'] : ['group'];

  // prefer:
  // - single: сначала 4 одиночных поля (1/2/3/4), потом пары (12/34)
  // - pair:   сначала пары (12/34), потом одиночные
  // - auto/max/fill: синоним single (в UI текстом называем «Авто: максимум заполнения»)
  // - auto_pair/reverse: синоним pair
  $prefer = strtolower(trim((string)($_GET['prefer'] ?? 'single')));
  if (in_array($prefer, ['auto','max','fill','auto_single'], true)) $prefer = 'single';
  if (in_array($prefer, ['auto_pair','reverse'], true)) $prefer = 'pair';
  if (!in_array($prefer, ['single','pair'], true)) $prefer = 'single';

  // mix_12_34: OFF | AUTO(A) | HARD
  $mix = strtoupper(trim((string)($_GET['mix_12_34'] ?? 'OFF')));
  if ($mix === 'A') $mix = 'AUTO';
  if ($mix === 'H') $mix = 'HARD';
  if (!in_array($mix, ['OFF','AUTO','HARD'], true)) $mix = 'OFF';

  // switch_ratio (deprecated): раньше задавал ручной «переход» 4→2. Теперь раскладка авто, параметр игнорируется.
  $switchRatio = null;

  $show = (string)($_GET['show'] ?? 'code');
  if (!in_array($show, ['code','teams'], true)) $show = 'code';

  $allowOob = isset($_GET['allow_oob']) ? (int)$_GET['allow_oob'] : 1;
  if ($allowOob !== 1) $allowOob = 0;

  $maxOobSlots = isset($_GET['max_oob_slots']) ? (int)$_GET['max_oob_slots'] : 8;
  if ($maxOobSlots < 0) $maxOobSlots = 0;
  if ($maxOobSlots > 48) $maxOobSlots = 48;

  $stage = _lab_qOne($pdo, 'SELECT * FROM stages WHERE id = ?', [$stageId]);
  if (!$stage) {
    _lab_out(['ok'=>false, 'error'=>'Stage not found'], 200);
  }

  $tzName = (string)($stage['timezone'] ?? 'Europe/Moscow');
  try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('Europe/Moscow'); }

  $matchMinutes      = (int)($stage['match_minutes'] ?? 25);
  $breakMinutes      = (int)($stage['break_minutes'] ?? 5);
  $transitionMinutes = (int)($stage['transition_minutes'] ?? 15);
  $stageMinRestSlots = (int)($stage['min_rest_slots'] ?? 0);

  // Важно: матч = 2 тайма по match_minutes + перерыв break_minutes.
  // Слот = длительность матча + transition_minutes, а последний слот дня — без transition_minutes.
  $baseMinutes       = max(1, $matchMinutes * 2 + $breakMinutes);
  $slotStrideMinutes = $baseMinutes + max(0, $transitionMinutes);
  $slotLastMinutes   = $baseMinutes;
  $GLOBALS['__lab_slot_last_minutes'] = $slotLastMinutes;

  // Дни этапа: берём диапазон start_date..end_date и применяем overrides из stage_days.
  // Отсутствие записи в stage_days НЕ означает, что день выключен — это просто настройки по умолчанию.
  $startDate = (string)($stage['start_date'] ?? '');
  $endDate   = (string)($stage['end_date'] ?? '');

  // overrides (могут быть неполными по диапазону)
  $ovRows = _lab_qAll($pdo,
    'SELECT * FROM stage_days WHERE stage_id = ? ORDER BY day_date ASC',
    [$stageId]
  );
  $ov = [];
  foreach ($ovRows as $r) {
    $d = (string)($r['day_date'] ?? '');
    if ($d !== '') $ov[$d] = $r;
  }

  $daysRows = [];
  if ($startDate !== '' && $endDate !== '') {
    try {
      $d0 = new DateTimeImmutable($startDate, $tz);
      $d1 = new DateTimeImmutable($endDate, $tz);
      if ($d1 < $d0) throw new RuntimeException('end_date < start_date');
    } catch (Throwable $e) {
      $d0 = null;
      $d1 = null;
    }

    if ($d0 && $d1) {
      for ($d = $d0; $d <= $d1; $d = $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $r = $ov[$key] ?? null;
        $isActive = $r ? (int)($r['is_active'] ?? 1) : 1;
        if ($isActive !== 1) continue;

        $daysRows[] = [
          'day_date'  => $key,
          'day_start' => (string)($r['day_start'] ?? ($stage['day_start'] ?? '09:00:00')),
          'day_end'   => (string)($r['day_end']   ?? ($stage['day_end']   ?? '18:00:00')),
          'fields'    => (int)($r['fields']       ?? ($stage['fields']    ?? 4)),
          'is_active' => 1,
        ];
      }
    }
  }

  // Если даты этапа не заданы/битые — используем только stage_days (как есть).
  if (!$daysRows) {
    foreach ($ovRows as $r) {
      if ((int)($r['is_active'] ?? 1) !== 1) continue;
      $daysRows[] = $r;
    }
  }

  if (!$daysRows) {
    _lab_out(['ok'=>false, 'error'=>'No active days: set stages.start_date/end_date or stage_days'], 200);
  }


  // Поля этапа + masks (на будущее). Нам важны коды.
  $stageFields = _lab_qAll($pdo, 'SELECT * FROM stage_fields WHERE stage_id = ? ORDER BY field_code ASC', [$stageId]);
  $fieldMask = [];
  $fieldCodes = [];
  foreach ($stageFields as $f) {
    $fc = (string)($f['field_code'] ?? '');
    if ($fc === '') continue;
    $fieldCodes[] = $fc;
    $fieldMask[$fc] = (int)($f['units_mask'] ?? 0);
  }

  // Если stage_fields пуст — используем 1..N из stages.fields (минимально, чтобы лаба жила)
  if (!$fieldCodes) {
    $n = (int)($stage['fields'] ?? 4);
    for ($i=1; $i <= max(1,$n); $i++) { $fieldCodes[] = (string)$i; }
  }

  $singleCols = array_values(array_filter($fieldCodes, fn($c)=>preg_match('~^[1-4]$~', (string)$c)));
  $pairCols   = array_values(array_filter($fieldCodes, fn($c)=>in_array((string)$c, ['12','34'], true)));

  // Фоллбеки на случай отсутствия пар/одиночек
  if (!$singleCols) $singleCols = array_values(array_filter($fieldCodes, fn($c)=>preg_match('~^\d+$~', (string)$c)));
  if (!$pairCols)   $pairCols = $singleCols;

  // Категорийные ограничения и лимиты (+ плей-офф резервы)
  $catRows = _lab_qAll($pdo,
    'SELECT category, max_matches_per_day, min_rest_slots, playoff_enabled, playoff_days FROM stage_categories WHERE stage_id = ?',
    [$stageId]
  );
  $catMaxPerDay = [];
  $catMinRest   = [];
  $catPlayoffEnabled = [];
  $catPlayoffDays    = [];
  $reserveDays = 0;

  foreach ($catRows as $r) {
    $cat = (string)($r['category'] ?? '');
    if ($cat === '') continue;

    $catMaxPerDay[$cat] = (int)($r['max_matches_per_day'] ?? 99);
    $catMinRest[$cat]   = isset($r['min_rest_slots']) ? (int)$r['min_rest_slots'] : $stageMinRestSlots;

    // Плей-офф: если включён — резервируем последние N дней этапа под плей-офф (глобально по максимуму).
    if ((int)($r['playoff_enabled'] ?? 0) === 1) {
      $pd = (int)($r['playoff_days'] ?? 1);
      if ($pd < 1) $pd = 1;
      $catPlayoffEnabled[$cat] = true;
      $catPlayoffDays[$cat] = $pd;
      if ($pd > $reserveDays) $reserveDays = $pd;
    }
  }

  // Можно принудительно задать резерв через GET (для тестов): &reserve_days=0|1|2...
  if (isset($_GET['reserve_days'])) {
    $rd = (int)($_GET['reserve_days']);
    if ($rd < 0) $rd = 0;
    $reserveDays = $rd;
  }

  // Разрешённые поля по категориям
  $catFieldRows = _lab_qAll($pdo,
    'SELECT category, variant, field_code FROM stage_category_fields WHERE stage_id = ? ORDER BY category, variant, field_code',
    [$stageId]
  );
  $catFields = []; // key "cat|variant" => [field_code,...]
  foreach ($catFieldRows as $r) {
    $cat = (string)($r['category'] ?? '');
    $var = (int)($r['variant'] ?? 1);
    $fc  = (string)($r['field_code'] ?? '');
    if ($cat === '' || $fc === '') continue;
    $k = $cat . '|' . $var;
    if (!isset($catFields[$k])) $catFields[$k] = [];
    if (!in_array($fc, $catFields[$k], true)) $catFields[$k][] = $fc;
  }

  // Матчи
$sqlMatches = "SELECT * FROM matches WHERE stage_id = ? ";
if ($includePlayoff === 1) {
  $sqlMatches .= "AND phase IN ('group','playoff') ";
} else {
  $sqlMatches .= "AND phase = 'group' ";
}
$sqlMatches .= "ORDER BY category ASC, group_code ASC, round_no ASC, id ASC";

$matches = _lab_qAll($pdo, $sqlMatches, [$stageId]);

$hasPlayoffMatches = false;
if ($matches) {
  foreach ($matches as $mm) {
    if ((string)($mm['phase'] ?? '') === 'playoff') { $hasPlayoffMatches = true; break; }
  }
}

  // Подтянем названия команд (для show=teams)
  $squadName = [];
  if ($show === 'teams' && $matches) {
    $ids = [];
    foreach ($matches as $m) {
      $h = $m['home_squad_id'] ?? null;
      $a = $m['away_squad_id'] ?? null;
      if ($h !== null && $h !== '') $ids[(int)$h] = true;
      if ($a !== null && $a !== '') $ids[(int)$a] = true;
    }
    $ids = array_keys($ids);
    if ($ids) {
      $in = implode(',', array_fill(0, count($ids), '?'));
      $sq = _lab_qAll($pdo, "SELECT id, name FROM squads WHERE id IN ($in)", $ids);
      foreach ($sq as $r) {
        $sid = (int)$r['id'];
        $squadName[$sid] = (string)($r['name'] ?? ('#'.$sid));
      }
    }
  }


  // CH16+/Playoff labels: разрешаем GE:<group>:<pos> в реальные команды (для show=teams)
  // Логика: manual_place (если задан) имеет приоритет, иначе используем pos.
  $geSquad = []; // key "cat|var|G|pos" => squad_id
  if ($show === 'teams' && $includePlayoff === 1 && $matches) {
    $want = []; // key => true
    foreach ($matches as $m) {
      if ((string)($m['phase'] ?? '') !== 'playoff') continue;
      foreach (['home_ref','away_ref'] as $rk) {
        $ref = (string)($m[$rk] ?? '');
        if ($ref === '') continue;
        if (strpos($ref, 'GE:') !== 0) continue;
        // GE:<GROUP>:<POS>
        $parts = explode(':', $ref);
        if (count($parts) !== 3) continue;
        $g = (string)($parts[1] ?? '');
        $p = (int)($parts[2] ?? 0);
        if ($g === '' || $p <= 0) continue;
        $cat = (string)($m['category'] ?? '');
        $var = (int)($m['variant'] ?? 1);
        if ($cat === '') continue;
        $want[$cat.'|'.$var.'|'.$g.'|'.$p] = true;
      }
    }

    if ($want) {
      // Берём все записи group_entries по stage_id и строим мапу (cat,var,group,pos)->squad_id
      // Важно: pos в БД = рассчитанное место, manual_place = ручной override (жребий)
      $ge = _lab_qAll($pdo,
        "SELECT category, variant, group_code, squad_id, pos, manual_place
         FROM group_entries
         WHERE stage_id = ?",
        [$stageId]
      );
      $needSq = [];
      foreach ($ge as $r) {
        $cat = (string)($r['category'] ?? '');
        $var = (int)($r['variant'] ?? 1);
        $g   = (string)($r['group_code'] ?? '');
        $sid = (int)($r['squad_id'] ?? 0);
        if ($cat === '' || $g === '' || $sid <= 0) continue;
        $mp = $r['manual_place'];
        $effPos = ($mp !== null && $mp !== '') ? (int)$mp : (int)($r['pos'] ?? 0);
        if ($effPos <= 0) continue;
        $k = $cat.'|'.$var.'|'.$g.'|'.$effPos;
        if (!isset($want[$k])) continue;
        $geSquad[$k] = $sid;
        $needSq[$sid] = true;
      }

      // Подтянем имена этих команд (если не были загружены ранее)
      if ($needSq) {
        $ids = array_keys($needSq);
        $missing = [];
        foreach ($ids as $sid) {
          if (!isset($squadName[(int)$sid])) $missing[] = (int)$sid;
        }
        if ($missing) {
          $in = implode(',', array_fill(0, count($missing), '?'));
          $sq = _lab_qAll($pdo, "SELECT id, name FROM squads WHERE id IN ($in)", $missing);
          foreach ($sq as $r) {
            $sid = (int)$r['id'];
            $squadName[$sid] = (string)($r['name'] ?? ('#'.$sid));
          }
        }
      }
    }
  }

  // Тренеры: в текущей БД связка хранится в squads.coach_id (таблица squad_coaches сейчас пустая).
  // Для правила "две команды одного тренера не должны играть в одно время" строим мапу squad_id -> coach_id.
  $coachBySquad = [];   // [squad_id => coach_id]
  $coachNameById = [];  // [coach_id => name]
  if ($matches) {
    $sIds = [];
    foreach ($matches as $m) {
      $h = $m['home_squad_id'] ?? null;
      $a = $m['away_squad_id'] ?? null;
      if ($h !== null && $h !== '') $sIds[(int)$h] = true;
      if ($a !== null && $a !== '') $sIds[(int)$a] = true;
    }
    $sIds = array_keys($sIds);
    if ($sIds) {
      $in = implode(',', array_fill(0, count($sIds), '?'));
      $rows = _lab_qAll($pdo, "SELECT id, coach_id FROM squads WHERE id IN ($in)", $sIds);
      $cIds = [];
      foreach ($rows as $r) {
        $sid = (int)($r['id'] ?? 0);
        $cid = (int)($r['coach_id'] ?? 0);
        if ($sid > 0 && $cid > 0) {
          $coachBySquad[$sid] = $cid;
          $cIds[$cid] = true;
        }
      }
      $cIds = array_keys($cIds);
      if ($cIds) {
        $in2 = implode(',', array_fill(0, count($cIds), '?'));
        $cr = _lab_qAll($pdo, "SELECT id, name FROM coaches WHERE id IN ($in2)", $cIds);
        foreach ($cr as $r) {
          $cid = (int)($r['id'] ?? 0);
          if ($cid > 0) $coachNameById[$cid] = (string)($r['name'] ?? ('coach#'.$cid));
        }
      }
    }
  }

  // Дни и слоты (внутреннее представление)
  $calendar = []; // каждый элемент: ['date'=>..., 'dayStart'=>DateTimeImmutable, 'dayEnd'=>..., 'slots'=>[DateTimeImmutable...], 'slotCount'=>int, 'switchIdx'=>int]
  foreach ($daysRows as $dr) {
    $date = (string)($dr['day_date'] ?? '');
    if ($date === '') continue;

    $ds = (string)($dr['day_start'] ?? $stage['day_start'] ?? '09:00:00');
    $de = (string)($dr['day_end']   ?? $stage['day_end']   ?? '18:00:00');

    $dayStart = _lab_dt($date, $ds, $tz);
    $dayEnd   = _lab_dt($date, $de, $tz);
    if ($dayEnd <= $dayStart) continue;

    $slots = [];
    $t = $dayStart;
    while (true) {
      $end = $t->modify('+' . $slotLastMinutes . ' minutes');
      if ($end > $dayEnd) break; // ключевое правило: последний слот считаем без transition
      $slots[] = $t;
      $t = $t->modify('+' . $slotStrideMinutes . ' minutes');
    }

    $slotCount = count($slots);
    // auto: точка перехода (switchIdx) будет рассчитана во время размещения матчей по принципу «максимально заполнить».
    $switchIdx = $slotCount;
$calendar[] = [
      'date' => $date,
      'dayStart' => $dayStart,
      'dayEnd'   => $dayEnd,
      'slots'    => $slots,
      'slotCount'=> $slotCount,
      'switchIdx'=> $switchIdx,
    ];
  }

  if (!$calendar) {
    _lab_out(['ok'=>false, 'error'=>'No usable days/slots'], 200);
  }

  $numDays = count($calendar);

  // squad_constraints (not_before / not_after)
  $datesForConstraints = [];
  foreach ($calendar as $d) {
    $dt = (string)($d['date'] ?? '');
    if ($dt !== '') $datesForConstraints[] = $dt;
  }
  _lab_set_squad_constraints(_lab_load_squad_constraints($pdo, $stageId, $datesForConstraints));
  _lab_set_shared_load_rules(_lab_load_shared_load_rules($pdo, $stageId));

  $dayMap = _lab_day_map($calendar);
  $GLOBALS['__lab_day_index_by_date_map'] = $dayMap;
  _lab_set_stage_events(_lab_load_stage_events($pdo, $stageId, $tz, $datesForConstraints));
  $eventSlotsOut = _lab_build_event_slots_out($dayMap, $slotStrideMinutes, $slotLastMinutes);

  // Быстрый мап даты -> индекс дня (0-based)
  $dayIndexByDate = [];
  foreach ($calendar as $i => $drow) {
    $dd = (string)($drow['date'] ?? '');
    if ($dd !== '') $dayIndexByDate[$dd] = (int)$i;
  }
  $GLOBALS['__lab_day_index_by_date'] = $dayIndexByDate;

  // Если включён плей-офф: вычисляем "последний групповой день" по сохранённому расписанию
  // отдельно для каждой пары (category, variant). Это позволяет начинать плей-офф по категориям независимо.
  $lastGroupDayByCatVar = []; // key "cat|var" => 'YYYY-MM-DD'
  if ($includePlayoff === 1) {
    $rows = _lab_qAll($pdo,
      "SELECT m.category, m.variant, MAX(si.day_date) AS last_day
       FROM schedule_items si
       JOIN matches m ON m.id = si.match_id
       WHERE si.stage_id = ? AND m.phase = 'group'
       GROUP BY m.category, m.variant",
      [$stageId]
    );
    foreach ($rows as $r) {
      $cat = (string)($r['category'] ?? '');
      $var = (int)($r['variant'] ?? 1);
      $ld  = (string)($r['last_day'] ?? '');
      if ($cat === '' || $ld === '') continue;
      $lastGroupDayByCatVar[$cat . '|' . $var] = $ld;
    }
  }

  // Последний день этапа (логика: день отъезда). Используем как soft-ограничение для плей-офф:
  // при возможности стараемся завершать плей-офф ДО последнего дня.
  $lastStageDayDate = '';
  if ($numDays > 0) {
    $lastStageDayDate = (string)($calendar[$numDays - 1]['date'] ?? '');
  }

  // ВАЖНО (CH16+): Плей-офф может начинаться раньше/позже в разных категориях.
  // Поэтому НЕ делим дни этапа глобально на "group" и "playoff" резервы.
  // Вместо этого плей-офф матчи получат ограничение min_day_date (рассчитывается ниже).
  $reserveDaysEff = 0;
  $reserveStartIdx = $numDays;
  $groupLastDayIdx = $numDays - 1;
  $blockLastIdx = $numDays - 1;


  // Подготовка матчей к размещению
  $work = [];
  foreach ($matches as $m) {
    $id = (int)$m['id'];
    $cat = (string)($m['category'] ?? '');
    $var = (int)($m['variant'] ?? 1);
    $phase = (string)($m['phase'] ?? 'group');
    if (!in_array($phase, $phases, true)) {
      // не генерируем эту фазу сейчас
      continue;
    }
    $grp = (string)($m['group_code'] ?? '');
    $round = (int)($m['round_no'] ?? 0);

    $code = (string)($m['code'] ?? '');
    if ($code === '') {
      if ($phase === 'group') {
        $code = sprintf('G%s%s-R%d-%d', $cat, $grp, max(0,$round), $id);
      } else {
        $code = sprintf('P%s-%d', $cat, $id);
      }
    }

    $homeId = isset($m['home_squad_id']) ? (int)$m['home_squad_id'] : null;
    $awayId = isset($m['away_squad_id']) ? (int)$m['away_squad_id'] : null;

    $homeRef = (string)($m['home_ref'] ?? '');
    $awayRef = (string)($m['away_ref'] ?? '');

    // Для плей-офф: если home/away ещё не проставлены (GE:*), резолвим в реальные squad_id через group_entries.
    // Это нужно, чтобы работали ограничения команд и конфликты тренеров/команд.
    // ВАЖНО: это НЕ меняет таблицу matches, только локально для генератора.
    if ($phase === 'playoff') {
      if ((!$homeId || $homeId <= 0) && $homeRef !== '' && strpos($homeRef, 'GE:') === 0) {
        $p = explode(':', $homeRef);
        if (count($p) === 3) {
          $g  = (string)($p[1] ?? '');
          $pp = (int)($p[2] ?? 0);
          if ($g !== '' && $pp > 0) {
            $kge = $cat.'|'.$var.'|'.$g.'|'.$pp;
            $sid = (int)($geSquad[$kge] ?? 0);
            if ($sid > 0) $homeId = $sid;
          }
        }
      }
      if ((!$awayId || $awayId <= 0) && $awayRef !== '' && strpos($awayRef, 'GE:') === 0) {
        $p = explode(':', $awayRef);
        if (count($p) === 3) {
          $g  = (string)($p[1] ?? '');
          $pp = (int)($p[2] ?? 0);
          if ($g !== '' && $pp > 0) {
            $kge = $cat.'|'.$var.'|'.$g.'|'.$pp;
            $sid = (int)($geSquad[$kge] ?? 0);
            if ($sid > 0) $awayId = $sid;
          }
        }
      }
    }

    // Тренеры для правил расписания:
    // - НЕ ставить матчи команд одного тренера в одно время (жёстко)
    // - по возможности делать их игры ближе друг к другу (soft)
    $coachIds = [];
    $hc = ($homeId ? (int)($coachBySquad[$homeId] ?? 0) : 0);
    $ac = ($awayId ? (int)($coachBySquad[$awayId] ?? 0) : 0);
    if ($hc > 0) $coachIds[$hc] = true;
    if ($ac > 0) $coachIds[$ac] = true;
    $coachIds = array_keys($coachIds);

    $label = $code;
    $title = '';
    if ($show === 'teams') {
      $hName = ($homeId && isset($squadName[$homeId])) ? $squadName[$homeId] : '';
      $aName = ($awayId && isset($squadName[$awayId])) ? $squadName[$awayId] : '';

      // playoff: если home/away ещё не определены (GE:*), попробуем резолвить через group_entries (pos/manual_place)
      if ($phase === 'playoff') {
        if ($hName === '' && $homeRef !== '' && strpos($homeRef, 'GE:') === 0) {
          $p = explode(':', $homeRef);
          if (count($p) === 3) {
            $g = (string)($p[1] ?? '');
            $pp = (int)($p[2] ?? 0);
            if ($g !== '' && $pp > 0) {
              $kge = $cat.'|'.$var.'|'.$g.'|'.$pp;
              $sid = (int)($geSquad[$kge] ?? 0);
              if ($sid > 0 && isset($squadName[$sid])) $hName = $squadName[$sid];
            }
          }
        }
        if ($aName === '' && $awayRef !== '' && strpos($awayRef, 'GE:') === 0) {
          $p = explode(':', $awayRef);
          if (count($p) === 3) {
            $g = (string)($p[1] ?? '');
            $pp = (int)($p[2] ?? 0);
            if ($g !== '' && $pp > 0) {
              $kge = $cat.'|'.$var.'|'.$g.'|'.$pp;
              $sid = (int)($geSquad[$kge] ?? 0);
              if ($sid > 0 && isset($squadName[$sid])) $aName = $squadName[$sid];
            }
          }
        }
      }
      $h = $hName ?: ($homeRef ?: ($m['home_label'] ?? ''));
      $a = $aName ?: ($awayRef ?: ($m['away_label'] ?? ''));
      $h = (string)$h;
      $a = (string)$a;
      $label = trim($h . ' — ' . $a);
      $title = trim($code . ' • ' . $label);
      if ($label === '—' || $label === '') $label = $code;
    }

    // allowed fields
    $k = $cat . '|' . $var;
    $allowed = $catFields[$k] ?? [];
    if (!$allowed) {
      // если ограничений нет — разрешаем всё, что у этапа объявлено
      $allowed = $fieldCodes;
    }
    // Допустимое окно дней для матча и предпочитаемый день.
    $firstDayIdx = 0;
    $lastDayIdx = max(0, $numDays - 1);

    // reserve под плей-офф считаем ПО КАТЕГОРИИ, а не глобально по этапу.
    if ($phase === 'group') {
      $playoffEnabled = (int)($catPlayoffEnabled[$cat] ?? 0);
      $playoffDays = max(0, (int)($catPlayoffDays[$cat] ?? 0));
      if ($playoffEnabled > 0 && $playoffDays > 0) {
        $lastDayIdx = max(0, $numDays - $playoffDays - 1);
      }
    }

    // предпочитаемый день:
    // - group: равномерно по доступному окну категории/группы
    // - playoff: начиная с min_day_date (если вычислен), иначе в конец
$prefDay = 0;
$minDayDate = '';
if ($phase === 'group') {
  $windowLen = max(1, $lastDayIdx - $firstDayIdx + 1);
  // Равномерно размазываем раунды по доступному окну категории/группы,
  // а не утрамбовываем всё в первые дни.
  $roundCountForSpread = 0;
  foreach ($matches as $__mx) {
    if ((string)($__mx['phase'] ?? 'group') !== 'group') continue;
    if ((string)($__mx['category'] ?? '') !== $cat) continue;
    if ((int)($__mx['variant'] ?? 1) !== $var) continue;
    if ((string)($__mx['group_code'] ?? '') !== $grp) continue;
    $rr = (int)($__mx['round_no'] ?? 0);
    if ($rr > $roundCountForSpread) $roundCountForSpread = $rr;
  }
  if ($roundCountForSpread < 1) $roundCountForSpread = max(1, $round);
  $roundPos = max(0, $round - 1);
  if ($roundCountForSpread <= 1) {
    $prefDay = $firstDayIdx;
  } else {
    $prefDay = $firstDayIdx + (int) round(($windowLen - 1) * ($roundPos / max(1, $roundCountForSpread - 1)));
  }
  if ($prefDay < $firstDayIdx) $prefDay = $firstDayIdx;
  if ($prefDay > $lastDayIdx)  $prefDay = $lastDayIdx;
} else {
  // Плей-офф: "после групп" по конкретной категории/варианту
  if ($includePlayoff === 1) {
    $kcv = $cat . '|' . $var;
    $last = $lastGroupDayByCatVar[$kcv] ?? '';
    if ($last !== '') {
      $next = date('Y-m-d', strtotime($last . ' +1 day'));
      // если следующего дня нет в календаре — не автоплейсим (останется в unplaced)
      if (isset($dayIndexByDate[$next])) {
        $minDayDate = $next;
        $prefDay = (int)$dayIndexByDate[$next];
      } else {
        $minDayDate = '9999-12-31';
        $prefDay = $numDays - 1;
      }
    } else {
      // группы ещё не сохранены -> плей-офф не начинаем автоматически
      $minDayDate = '9999-12-31';
      $prefDay = $numDays - 1;
    }
    // Для плей-офф используем отдельное окно последних playoff_days дней категории.
    // Если playoff_days = 2, то:
    //   round <= 1  -> первый playoff-day (полуфиналы / 1/2)
    //   round >= 2  -> второй playoff-day (3 место / финалы)
    $playoffDays = max(1, (int)($catPlayoffDays[$cat] ?? 1));
    $playoffStartIdx = max($firstDayIdx, $lastDayIdx - $playoffDays + 1);
    $playoffEndIdx   = $lastDayIdx;

    if ($playoffDays >= 2) {
      if ($round <= 1) {
        $firstDayIdx = $playoffStartIdx;
        $lastDayIdx  = $playoffStartIdx;
        $prefDay     = $playoffStartIdx;
      } else {
        $forcedIdx   = min($playoffEndIdx, $playoffStartIdx + 1);
        $firstDayIdx = $forcedIdx;
        $lastDayIdx  = $forcedIdx;
        $prefDay     = $forcedIdx;
      }
    } else {
      $firstDayIdx = $playoffStartIdx;
      $lastDayIdx  = $playoffEndIdx;
      if ($prefDay < $firstDayIdx) $prefDay = $firstDayIdx;
      if ($prefDay > $lastDayIdx)  $prefDay = $lastDayIdx;
    }

    if (isset($calendar[$prefDay]['date'])) {
      $minDayDate = (string)$calendar[$prefDay]['date'];
    }

  } else {
    $prefDay = $numDays - 1;
  }
}


    $maxPerDay = $catMaxPerDay[$cat] ?? 99;
    $minRestSlots = $catMinRest[$cat] ?? $stageMinRestSlots;

    // Последний день этапа — день отъезда: плей-офф по возможности завершаем раньше.
    $avoidLastDay = (($phase === 'playoff') && ($lastStageDayDate !== '')) ? 1 : 0;

    $work[] = [
      'id' => $id,
      'phase' => $phase,
      'category' => $cat,
      'variant' => $var,
      'group_code' => $grp,
      'round_no' => $round,
      'code' => $code,
      'label' => $label,
      'title' => $title,
      'home_squad_id' => $homeId,
      'away_squad_id' => $awayId,
      'coach_ids' => $coachIds,
      'home_ref' => $homeRef,
      'away_ref' => $awayRef,
      'shared_rule_ids' => _lab_shared_rule_ids_for_squads([$homeId, $awayId]),
      'allowed_fields' => $allowed,
      'preferred_day' => $prefDay,
      'first_day_idx' => $firstDayIdx,
      'last_day_idx' => $lastDayIdx,
      'min_day_date' => $minDayDate,
      'avoid_last_day' => $avoidLastDay,
      // maxPerDay как жёсткое правило оставляем для групп. Для плей-офф делаем мягче:
      // матчей в один день избегаем, но не запрещаем.
      'max_matches_per_day' => $maxPerDay,
      'min_rest_slots' => max(0, (int)$minRestSlots),
    ];
  }

  if (!function_exists('_lab_playoff_code_priority')) {
    function _lab_playoff_code_priority(string $code): int {
      $code = strtoupper(trim($code));
      if ($code === '') return 50;

      // Внутри одного playoff round_no делаем порядок "по-человечески":
      // матч за 3 место раньше финала.
      if (preg_match('~(?:^|-)3$~', $code)) return 10;
      if (preg_match('~(?:^|-)F$~', $code)) return 20;

      return 50;
    }
  }

  // Сортировка: сначала узкие окна/узкие поля/поздние раунды групп, потом уже остальное.
  usort($work, function($a, $b){
    $pa = ($a['phase'] === 'group') ? 0 : 1;
    $pb = ($b['phase'] === 'group') ? 0 : 1;
    $d = ($pa <=> $pb);
    if ($d !== 0) return $d;

    $wa = ((int)($a['last_day_idx'] ?? 0) - (int)($a['first_day_idx'] ?? 0));
    $wb = ((int)($b['last_day_idx'] ?? 0) - (int)($b['first_day_idx'] ?? 0));
    $d = ($wa <=> $wb);
    if ($d !== 0) return $d;

    $fa = is_array($a['allowed_fields'] ?? null) ? count($a['allowed_fields']) : 99;
    $fb = is_array($b['allowed_fields'] ?? null) ? count($b['allowed_fields']) : 99;
    $d = ($fa <=> $fb);
    if ($d !== 0) return $d;

    if (($a['phase'] ?? '') === 'group' && ($b['phase'] ?? '') === 'group') {
      $d = ((int)$b['round_no'] <=> (int)$a['round_no']);
      if ($d !== 0) return $d;
    }

    if (($a['phase'] ?? '') === 'playoff' && ($b['phase'] ?? '') === 'playoff') {
      $d = ((int)$a['round_no'] <=> (int)$b['round_no']);
      if ($d !== 0) return $d;

      $d = (_lab_playoff_code_priority((string)($a['code'] ?? '')) <=> _lab_playoff_code_priority((string)($b['code'] ?? '')));
      if ($d !== 0) return $d;
    }

    $d = ((int)$a['preferred_day'] <=> (int)$b['preferred_day']);
    if ($d !== 0) return $d;
    $d = ((string)$a['category'] <=> (string)$b['category']);
    if ($d !== 0) return $d;
    $d = ((string)$a['group_code'] <=> (string)$b['group_code']);
    if ($d !== 0) return $d;
    return ((int)$a['id'] <=> (int)$b['id']);
  });

  $assignments = [];
  $placed = 0;

  // ограничения по командам
  $lastSlot = [];        // [$date][$squadId] => slotIndex (1..)
  $countPerDay = [];     // [$date][$squadId] => count

  // Ограничение по тренерам (soft): команды одного тренера не должны играть одновременно.
  // Плюс бонус за "подряд" (меньше пауз между играми одного тренера).
  // coachBusy: [$date][$slotNo][$coachId] => [assignmentIdx...]
  $coachBusy = [];
  // coachLastSlot: [$date][$coachId] => last slotNo
  $coachLastSlot = [];

  // быстрый доступ: allowed_fields set
  foreach ($work as &$m) {
    $set = [];
    foreach ($m['allowed_fields'] as $fc) $set[(string)$fc] = true;
    $m['_allowed_set'] = $set;

    // флаги для авто-раскладки режимов (4 одиночных / 2 пары)
    $hasSingle = isset($set['1']) || isset($set['2']) || isset($set['3']) || isset($set['4']);
    $hasPair   = isset($set['12']) || isset($set['34']);
    $m['_has_single'] = $hasSingle;
    $m['_has_pair']   = $hasPair;
    $m['_pair_only']  = (!$hasSingle && $hasPair);
    $m['_single_only']= ($hasSingle && !$hasPair);
  }
  
unset($m);

  // Плей-офф зависимости по ссылкам W:/L:.
  // Матч с W:/L: можно ставить только после исходного матча-источника.
  $playoffMatchIdByCode = [];
  foreach ($work as $__wm) {
    $mid = (int)($__wm['id'] ?? 0);
    $mcode = (string)($__wm['code'] ?? '');
    if ($mid > 0 && $mcode !== '') $playoffMatchIdByCode[$mcode] = $mid;
  }
  foreach ($work as &$__wm) {
    $deps = [];
    foreach (['home_ref','away_ref'] as $__rk) {
      $ref = (string)($__wm[$__rk] ?? '');
      if ($ref === '') continue;
      if (preg_match('~^[WL]:(.+)$~', $ref, $__mm)) {
        $srcCode = (string)$__mm[1];
        $srcMid = (int)($playoffMatchIdByCode[$srcCode] ?? 0);
        if ($srcMid > 0) $deps[$srcMid] = true;
      }
    }
    $__wm['dependency_match_ids'] = array_map('intval', array_keys($deps));
  }
  unset($__wm);

// Быстрая мета по матчам (нужна для repair-pass по тренерам)
$matchMeta = [];
foreach ($work as $mm) {
  $mid = (int)($mm['id'] ?? 0);
  if ($mid <= 0) continue;
  $matchMeta[$mid] = [
    'phase' => (string)($mm['phase'] ?? 'group'),
    'home_squad_id' => (int)($mm['home_squad_id'] ?? 0),
    'away_squad_id' => (int)($mm['away_squad_id'] ?? 0),
    'min_rest_slots' => (int)($mm['min_rest_slots'] ?? 0),
    'coach_ids' => array_values($mm['coach_ids'] ?? []),
    'shared_rule_ids' => array_values($mm['shared_rule_ids'] ?? []),
    'allowed_set' => $mm['_allowed_set'] ?? [],
    'dependency_match_ids' => array_values($mm['dependency_match_ids'] ?? []),
  ];
}

  // Авто-стратегия «максимально заполнить»:
  // - По умолчанию используем prefer-режим (single=4 поля или pair=2 пары).
  // - Если есть матчи, которые не могут быть сыграны в prefer-режиме, вычисляем минимально нужное число слотов вторичного режима
  //   на сегодня, чтобы всё влезло в оставшиеся дни.
  // - Дополнительно: если в prefer-слоте вообще нечего ставить, а во вторичном есть что — переключаемся раньше.


  // Размещаем по дням/слотам
  // Поддержка "выпадения из диапазона": если слоты по day_end закончились, но матчи ещё есть —
  // добавляем OOB-слоты (красные в UI). В БД ничего не пишем.

  $modeAt = function(int $slotIdx, int $switchIdx) use ($prefer): string {
    $primaryIsSingle = ($prefer === 'single');
    $isPrimaryZone = ($slotIdx < $switchIdx);
    return ($isPrimaryZone ? ($primaryIsSingle ? 'single' : 'pair') : ($primaryIsSingle ? 'pair' : 'single'));
  };

  $assignments = [];
  $placed = 0;

  // ограничения по командам
  $lastSlot = [];        // [$date][$squadId] => slotIndex (1..)
  $countPerDay = [];     // [$date][$squadId] => count

  // занятость сетки (для автопереключения режима и repair-pass)
  $occupied = []; // [$date][$slotNo][$fieldCode] => assignmentIdx

  // spread-контроль для group: категория и группа не должны бессмысленно схлопываться в первые дни.
  $catPlacedByDay = [];        // [$cat][$dayIdx] => count
  $groupPlacedByDay = [];      // [$cat|$group][$dayIdx] => count
  $remainingCatMatches = [];   // [$cat] => count
  $remainingGroupMatches = []; // [$cat|$group] => count
  foreach ($work as $__wm) {
    if ((string)($__wm['phase'] ?? '') !== 'group') continue;
    $catKey = (string)($__wm['category'] ?? '');
    $grpKey = $catKey . '|' . (string)($__wm['group_code'] ?? '');
    $remainingCatMatches[$catKey] = (int)($remainingCatMatches[$catKey] ?? 0) + 1;
    $remainingGroupMatches[$grpKey] = (int)($remainingGroupMatches[$grpKey] ?? 0) + 1;
  }



  // Если генерируем ТОЛЬКО плей-офф (include_group=0, include_playoff=1),
  // то группы оставляем как есть: подгружаем сохранённые group-слоты из schedule_items
  // и помечаем их как занятые, чтобы плей-офф не перезатирал.
  if ($includeGroup === 0 && $includePlayoff === 1) {
    try {
      $st = $pdo->prepare("
        SELECT si.day_date, si.slot_index, si.resource_code, si.match_id
        FROM schedule_items si
        JOIN matches m ON m.id = si.match_id
        WHERE si.stage_id = ? AND m.phase = 'group'
        ORDER BY si.day_date, si.slot_index, si.resource_code
      ");
      $st->execute([$stageId]);
      $fixedRows = $st->fetchAll(PDO::FETCH_ASSOC);

      if ($fixedRows) {
        // map match_id => row из matches
        $matchRowById = [];
        foreach ($matches as $__m) {
          $mid = (int)$__m['id'];
          if ($mid > 0) $matchRowById[$mid] = $__m;
        }

        foreach ($fixedRows as $r) {
          $date = (string)$r['day_date'];
          $slotNo = (int)$r['slot_index'];
          $fieldCode = (string)$r['resource_code'];
          $matchId = (int)$r['match_id'];

          if ($slotNo <= 0 || $fieldCode === '' || $matchId <= 0) continue;
          if (!isset($calendar[$date])) continue;

          $mx = $matchRowById[$matchId] ?? null;
          if (!$mx) continue;

          $cat = (string)($mx['category'] ?? '');
          $var = (int)($mx['variant'] ?? 1);
          $phase = (string)($mx['phase'] ?? 'group');
          if ($phase !== 'group') continue;

          $grp = (string)($mx['group_code'] ?? '');
          $round = (int)($mx['round_no'] ?? 0);

          $code = (string)($mx['code'] ?? '');
          if ($code === '') {
            $code = sprintf('G%s%s-R%d-%d', $cat, $grp, max(0, $round), $matchId);
          }

          $homeId = isset($mx['home_squad_id']) ? (int)$mx['home_squad_id'] : 0;
          $awayId = isset($mx['away_squad_id']) ? (int)$mx['away_squad_id'] : 0;

          $homeName = ($homeId > 0) ? (string)($squadNames[$homeId] ?? '') : '';
          $awayName = ($awayId > 0) ? (string)($squadNames[$awayId] ?? '') : '';
          $title = trim($homeName . ' — ' . $awayName);

          $coachIds = [];
          $hc = ($homeId > 0 ? (int)($coachBySquad[$homeId] ?? 0) : 0);
          $ac = ($awayId > 0 ? (int)($coachBySquad[$awayId] ?? 0) : 0);
          if ($hc > 0) $coachIds[$hc] = true;
          if ($ac > 0) $coachIds[$ac] = true;
          $coachIds = array_map('intval', array_keys($coachIds));

          $day = $calendar[$date];
          $slots = $day['slots'];
          $slotIdx = $slotNo - 1;
          $officialSlotCount = count($slots);
          if ($slotIdx >= 0 && $slotIdx < $officialSlotCount) {
            $slotDt = $slots[$slotIdx];
          } else {
            $slotDt = clone $day['dayStart'];
            $slotDt->modify('+' . ($slotIdx * $slotStrideMinutes) . ' minutes');
          }
          $timeStr = $slotDt->format('H:i');

          $assignments[] = [
            'match_id' => $matchId,
            'phase' => 'group',
            'code' => $code,
            'category' => $cat,
            'variant' => $var,
            'day' => $date,
            'time' => $timeStr,
            'slot_no' => $slotNo,
            'field_code' => $fieldCode,
            'label' => $code,
            'title' => ($title !== '' ? $title : $code),
            'coach_ids' => $coachIds,
          ];

          // занятость слота
          $slotKey = $date . '|' . $slotNo . '|' . $fieldCode;
          $occupied[$slotKey] = $matchId;

          // ограничения по командам (минимум — чтобы плей-офф уважал группы)
          if ($homeId > 0) {
            $lastSlot[$date][$homeId] = $slotNo;
            $countPerDay[$date][$homeId] = ($countPerDay[$date][$homeId] ?? 0) + 1;
          }
          if ($awayId > 0) {
            $lastSlot[$date][$awayId] = $slotNo;
            $countPerDay[$date][$awayId] = ($countPerDay[$date][$awayId] ?? 0) + 1;
          }

          // тренеры: просто отметим занятость (без конфликтов — они уже разрулены)
          foreach ($coachIds as $cid) {
            $cKey = $date . '|' . $slotNo . '|' . $cid;
            $coachBusy[$cKey] = $matchId;
          }
        }
      }
    } catch (Exception $e) {
      // молча: отсутствие сохранённых групп не должно ломать генерацию плей-офф
    }
  }

  // какие OOB-слоты реально использовались (чтобы UI дорисовал строки)
  $extraSlotIdxs = array_fill(0, $numDays, []); // [dayIdx][slotIdx] => true

  $overflowMax = ($allowOob === 1) ? (int)$maxOobSlots : 0;
  if ($overflowMax < 0) $overflowMax = 0;
  if ($overflowMax > 48) $overflowMax = 48;

  for ($dayIdx = 0; $dayIdx < $numDays; $dayIdx++) {
    $day = $calendar[$dayIdx];
    $date = $day['date'];
    $slots = $day['slots'];
    $officialSlotCount = count($slots);

    $isReservedDay = ($reserveDaysEff > 0 && $dayIdx >= $reserveStartIdx);

    // AUTO: рассчитываем switchIdx для этого дня (где переключаемся с prefer-режима на вторичный),
    // исходя из необходимости «вторичных» матчей в оставшихся днях.

    $switchIdx = $officialSlotCount; // по умолчанию без переключения
    $primary   = ($prefer === 'pair') ? 'pair' : 'single';
    $secondary = ($primary === 'single') ? 'pair' : 'single';

    // считаем «вторичные» матчи, которые не могут быть сыграны в primary-режиме, в текущем блоке дней (group/playoff)
    $only12 = 0; $only34 = 0; $both = 0; $totalSecondaryOnly = 0;
    $totalSecondaryOnlySingles = 0; // для secondary=single (примерная оценка)

    foreach ($work as $mm) {
      // Разделение фаз по дням: group — только до reserveStartIdx, playoff — только с reserveStartIdx
      if ($reserveDaysEff > 0) {
        if ($isReservedDay) {
          if ((string)$mm['phase'] === 'group') continue;
        } else {
          if ((string)$mm['phase'] !== 'group') continue;
        }
      }

      if ($secondary === 'pair') {
        if (!($mm['_pair_only'] ?? false)) continue; // secondary-only в этом случае = pair-only
        $set = $mm['_allowed_set'] ?? [];
        $a12 = isset($set['12']);
        $a34 = isset($set['34']);
        if ($a12 && $a34) $both++;
        elseif ($a12) $only12++;
        elseif ($a34) $only34++;
        else continue;
        $totalSecondaryOnly++;
      } else {
        // secondary === 'single': secondary-only = single-only (нет 12/34)
        if (!($mm['_single_only'] ?? false)) continue;
        $totalSecondaryOnlySingles++;
      }
    }

    // Минимум слотов вторичного режима, нужных на сегодня.
    // Ключевая логика: вторичный режим включаем НАСТОЛЬКО ПОЗДНО, НАСКОЛЬКО МОЖНО.
    // Не размазываем secondary равномерно по всем дням, а оставляем максимум single-слотов сегодня,
    // если оставшиеся secondary-only матчи гарантированно помещаются в последующие дни блока.
    $blockLastIdx = $isReservedDay ? ($numDays - 1) : (($groupLastDayIdx >= 0) ? $groupLastDayIdx : ($numDays - 1));
    $daysLeft = max(1, $blockLastIdx - $dayIdx + 1);

    if ($secondary === 'pair') {
      $total = $only12 + $only34 + $both;
      // Сколько pair-only матчей нужно уместить ВСЕГО.
      $needSecondaryMatches = $total;

      // Максимальная pair-вместимость будущих дней этого блока, если today оставить single как можно дольше.
      $futurePairCapacity = 0;
      for ($dj = $dayIdx + 1; $dj <= $blockLastIdx; $dj++) {
        if (!isset($calendar[$dj])) continue;
        $futureOfficial = (int)count($calendar[$dj]['slots'] ?? []);
        if ($futureOfficial <= 0) continue;
        // В одном pair-слоте максимум 2 матча (12 + 34).
        $futurePairCapacity += ($futureOfficial * 2);
      }

      // Сколько pair-матчей ОБЯЗАТЕЛЬНО нужно взять сегодня, иначе потом уже не поместятся даже при позднем старте single.
      $forcedPairMatchesToday = max(0, $needSecondaryMatches - $futurePairCapacity);
      $minTodaySecondary = (int)ceil($forcedPairMatchesToday / 2);

      if ($minTodaySecondary < 0) $minTodaySecondary = 0;
      if ($minTodaySecondary > $officialSlotCount) $minTodaySecondary = $officialSlotCount;

      // Если уже сегодня есть матчи, у которых окно дней заканчивается сегодня, учитываем их как жёсткое требование.
      $dueTodayOnly12 = 0; $dueTodayOnly34 = 0; $dueTodayBoth = 0;
      foreach ($work as $mm2) {
        if (($reserveDaysEff > 0 && $isReservedDay && (string)$mm2['phase'] === 'group') ||
            ($reserveDaysEff > 0 && !$isReservedDay && (string)$mm2['phase'] !== 'group')) {
          continue;
        }
        if (!($mm2['_pair_only'] ?? false)) continue;
        $mmLast = (int)($mm2['last_day_idx'] ?? ($numDays - 1));
        $mmFirst = (int)($mm2['first_day_idx'] ?? 0);
        if ($dayIdx < $mmFirst || $dayIdx > $mmLast) continue;
        if ($mmLast !== $dayIdx) continue;
        $set2 = $mm2['_allowed_set'] ?? [];
        $a12_2 = isset($set2['12']);
        $a34_2 = isset($set2['34']);
        if ($a12_2 && $a34_2) $dueTodayBoth++;
        elseif ($a12_2) $dueTodayOnly12++;
        elseif ($a34_2) $dueTodayOnly34++;
      }
      $dueTodaySecondarySlots = max($dueTodayOnly12, $dueTodayOnly34, (int)ceil(($dueTodayOnly12 + $dueTodayOnly34 + $dueTodayBoth) / 2));
      if ($dueTodaySecondarySlots > $minTodaySecondary) $minTodaySecondary = $dueTodaySecondarySlots;

      // ставим secondary-слоты в конце дня: primary...primary, затем пары
      $switchIdx = $officialSlotCount - $minTodaySecondary;
    } else {
      // secondary=single: оставляем reverse-режим совместимым, но тоже как можно дольше держим primary.
      $needSecondarySlots = (int) ceil(($totalSecondaryOnlySingles) / 4);
      $futureSingleCapacity = 0;
      for ($dj = $dayIdx + 1; $dj <= $blockLastIdx; $dj++) {
        if (!isset($calendar[$dj])) continue;
        $futureOfficial = (int)count($calendar[$dj]['slots'] ?? []);
        if ($futureOfficial <= 0) continue;
        $futureSingleCapacity += ($futureOfficial * 4);
      }
      $forcedSingleMatchesToday = max(0, $totalSecondaryOnlySingles - $futureSingleCapacity);
      $minTodaySecondary = (int)ceil($forcedSingleMatchesToday / 4);
      if ($minTodaySecondary < 0) $minTodaySecondary = 0;
      if ($minTodaySecondary > $officialSlotCount) $minTodaySecondary = $officialSlotCount;

      $switchIdx = $officialSlotCount - $minTodaySecondary;
    }

// сохраним в календарь, чтобы UI/вывод использовал финальную точку перехода
    $calendar[$dayIdx]['switchIdx'] = $switchIdx;
    $lastMode = null; // вычислим после финального switchIdx (нужно для OOB)

    // 0-based slotIdx, но для rest считаем 1-based slotNo
    $maxSlotIdx = $officialSlotCount + $overflowMax;
    $emptyOverflowStreak = 0;

    for ($slotIdx = 0; $slotIdx < $maxSlotIdx; $slotIdx++) {
      $slotNo = $slotIdx + 1;

      // время слота
      if ($slotIdx < $officialSlotCount) {
        $slotDt = $slots[$slotIdx];
      } else {
        // OOB слот: продолжаем сетку после day_end
        $slotDt = $day['dayStart']->modify('+' . ($slotIdx * $slotStrideMinutes) . ' minutes');
      }
      $timeStr = $slotDt->format('H:i');

      if (_lab_stage_event_violation($date, $slotNo, $dayMap, $slotStrideMinutes, $slotLastMinutes)) {
        continue;
      }

      // режим этого слота (после официальных слотов продолжаем в последнем режиме)

      $attempt = 0;
      RETRY_SLOT:;

      if ($slotIdx < $officialSlotCount) {
        $mode = $modeAt($slotIdx, $switchIdx);
      } else {
        if ($lastMode === null) $lastMode = ($officialSlotCount > 0) ? $modeAt($officialSlotCount - 1, $switchIdx) : ($prefer === 'pair' ? 'pair' : 'single');
        $mode = $lastMode;
      }

      $cols = ($mode === 'single') ? $singleCols : $pairCols;

      $placedThisSlot = 0;

      foreach ($cols as $fieldCode) {
        // Выбираем лучший матч для клетки.
        // 1) Жёсткие правила: allowed_fields, maxPerDay, minRest.
        // 2) Soft-правило по тренерам: стараемся НЕ ставить команды одного тренера в одно время;
        //    и по возможности делать их игры ближе друг к другу.
        $bestIndex = -1;
        $best = null;
        $bestScore = PHP_INT_MAX;

        for ($i = 0; $i < count($work); $i++) {
          $m = $work[$i];
          // Для групп preferred_day и окно дней АКТИВНЫ: тянем матчи по доступным дням, а не утрамбовываем в старт этапа.

          // Разделение фаз по дням: group — только до reserveStartIdx, playoff — только с reserveStartIdx
          if ($reserveDaysEff > 0) {
            if ($isReservedDay) {
              if ((string)$m['phase'] === 'group') continue;
            } else {
              if ((string)$m['phase'] !== 'group') continue;
            }
          }

          // CH16/PO: Плей-офф НЕ размещаем в OOB-слоты (вне диапазона дня).
          // Если в текущий день не влез — пусть перейдёт в официальный диапазон следующего дня.
          if ($slotIdx >= $officialSlotCount && (string)($m['phase'] ?? '') === 'playoff') continue;

          $mFirstDayIdx = (int)($m['first_day_idx'] ?? 0);
          $mLastDayIdx  = (int)($m['last_day_idx'] ?? ($numDays - 1));
          if ($dayIdx < $mFirstDayIdx || $dayIdx > $mLastDayIdx) continue;

          // Локальное правило плей-офф: не раньше min_day_date (после групп по категории/варианту)
          if (!empty($m['min_day_date'])) {
            if ($date < (string)$m['min_day_date']) continue;
          }

          // Hard spread guard for pair slots: do not overpack early days when the window still has future days.
if (_lab_group_spread_hard_skip(
  $m,
  $dayIdx,
  $lastDayIdx,
  $remainingCatMatches,
  $remainingGroupMatches,
  $catPlacedByDay,
  $groupPlacedByDay
)) {
  continue;
}

          // поле должно быть разрешено
          if (!isset($m['_allowed_set'][(string)$fieldCode])) continue;

          // ограничения по командам
          $homeId = $m['home_squad_id'];
          $awayId = $m['away_squad_id'];
          $minRest = (int)$m['min_rest_slots'];
          $maxPerDay = (int)$m['max_matches_per_day'];

          $ok = true;
          for ($k=0; $k<2; $k++) {
            $sid = ($k===0 ? $homeId : $awayId);
            if (!$sid) continue;

            $c = (int)($countPerDay[$date][$sid] ?? 0);
            if ($c >= $maxPerDay) { $ok = false; break; }

            $ls = $lastSlot[$date][$sid] ?? null;
            if ($ls !== null) {
              if (($slotNo - (int)$ls) <= $minRest) { $ok = false; break; }
            }

              $v = _lab_squad_constraint_violation($date, $slotNo, (int)$sid);
              if ($v) { $ok = false; break; }
          }
          if (!$ok) continue;

          $sharedViolation = _lab_shared_rule_violation($date, $slotNo, $m, $assignments, $matchMeta);
          if ($sharedViolation) continue;

          $playoffDepViolation = _lab_playoff_dependency_violation($date, $slotNo, $m, $assignments, $matchMeta, $dayIndexByDate);
          if ($playoffDepViolation) continue;

          // Тренеры: конфликт в одном слоте — ЖЁСТКО запрещён.
          $coachIds = $m['coach_ids'] ?? [];
          if (($m['phase'] ?? '') !== 'playoff' && $coachIds) {
            $coachBlock = false;
            foreach ($coachIds as $cid) {
              $cid = (int)$cid;
              if ($cid <= 0) continue;
              if (!empty($coachBusy[$date][$slotNo][$cid])) { $coachBlock = true; break; }
            }
            if ($coachBlock) continue;
          }

          // Soft-score по тренерам: чем ближе игры одного тренера друг к другу (gap), тем лучше.
          // Плюс: если у тренера уже был матч в предыдущем слоте — даём бонус, чтобы ставить игры подряд.

          //$score = 0;
          //if ($coachIds) {
          //  foreach ($coachIds as $cid) {
          //    $cid = (int)$cid;
          //    if ($cid <= 0) continue;
//
          //    $lsC = $coachLastSlot[$date][$cid] ?? null;
          //    if ($lsC !== null) {
          //      $gap = $slotNo - (int)$lsC - 1; // 0 = подряд
          //      if ($gap < 0) $gap = 0;
          //      $score += ($gap * 10);
          //      if ($gap === 0) $score -= 50;
          //      elseif ($gap === 1) $score -= 15;
          //    }
          //  }
          //}
          $score = 0;
          if ($coachIds) {
            foreach ($coachIds as $cid) {
              $cid = (int)$cid;
              if ($cid <= 0) continue;

              $lsC = $coachLastSlot[$date][$cid] ?? null;
              if ($lsC !== null) {
                $gap = $slotNo - (int)$lsC - 1; // 0 = подряд
                if ($gap < 0) $gap = 0;
                // Тренерская близость: в один слот по-прежнему hard-запрет,
                // а внутри дня заметно предпочитаем соседние слоты.
                // Делаем это аккуратно: вес уже ощутим, но всё ещё ниже, чем жёсткие spread/day-ограничения.
                if ($gap === 0) {
                  $score -= 2500;
                } elseif ($gap === 1) {
                  $score -= 400;
                } elseif ($gap === 2) {
                  $score += 2500;
                } else {
                  //$score += min(1800, $gap * 320);
                  $score += 12000 + ($gap * 1200);
                }
              }
            }
          }

          // Spread для групп: стараемся использовать всё доступное окно дней.
          if ((string)($m['phase'] ?? '') === 'group') {
            $mPrefDay = (int)($m['preferred_day'] ?? $mFirstDayIdx);
            $windowLen = max(1, $mLastDayIdx - $mFirstDayIdx + 1);
            $score += abs($dayIdx - $mPrefDay) * 12000;

            $catKey = (string)($m['category'] ?? '');
            $grpKey = $catKey . '|' . (string)($m['group_code'] ?? '');

            $daysTotalInWindow = max(1, $mLastDayIdx - $mFirstDayIdx + 1);
            $dayPosInWindow = max(0, $dayIdx - $mFirstDayIdx);

            $catPlacedTotal = 0;
            if (!empty($catPlacedByDay[$catKey]) && is_array($catPlacedByDay[$catKey])) {
              foreach ($catPlacedByDay[$catKey] as $dIdx => $cnt) {
                $dIdx = (int)$dIdx;
                if ($dIdx < $mFirstDayIdx || $dIdx > $mLastDayIdx) continue;
                $catPlacedTotal += (int)$cnt;
              }
            }

            $grpPlacedTotal = 0;
            if (!empty($groupPlacedByDay[$grpKey]) && is_array($groupPlacedByDay[$grpKey])) {
              foreach ($groupPlacedByDay[$grpKey] as $dIdx => $cnt) {
                $dIdx = (int)$dIdx;
                if ($dIdx < $mFirstDayIdx || $dIdx > $mLastDayIdx) continue;
                $grpPlacedTotal += (int)$cnt;
              }
            }

            $catTotalWindow = $catPlacedTotal + max(0, (int)($remainingCatMatches[$catKey] ?? 0));
            $grpTotalWindow = $grpPlacedTotal + max(0, (int)($remainingGroupMatches[$grpKey] ?? 0));

            $catQuotaToday = _lab_spread_quota_for_day($catTotalWindow, $daysTotalInWindow, $dayPosInWindow);
            $grpQuotaToday = _lab_spread_quota_for_day($grpTotalWindow, $daysTotalInWindow, $dayPosInWindow);

            $catToday = (int)($catPlacedByDay[$catKey][$dayIdx] ?? 0);
            $grpToday = (int)($groupPlacedByDay[$grpKey][$dayIdx] ?? 0);

            // Пока впереди есть доступные дни — не даём бессмысленно схлопывать категорию/группу в старт этапа.
            if ($dayIdx < $mLastDayIdx) {
              if ($catToday >= $catQuotaToday) {
                $score += 250000 + (($catToday - $catQuotaToday + 1) * 50000);
              }
              if ($grpToday >= $grpQuotaToday) {
                $score += 220000 + (($grpToday - $grpQuotaToday + 1) * 45000);
              }
            }

            if ($catToday < $catQuotaToday) {
              $score -= (($catQuotaToday - $catToday) * 14000);
            }
            if ($grpToday < $grpQuotaToday) {
              $score -= (($grpQuotaToday - $grpToday) * 12000);
            }
            // Слишком ранняя постановка раунда — почти запрет, если впереди ещё есть окно.
            $roundNo = (int)($m['round_no'] ?? 0);
            if ($dayIdx < $mPrefDay && $roundNo > 0) {
              $score += ($mPrefDay - $dayIdx) * (30000 + ($roundNo * 2500));
            }

            // И слишком позднюю постановку тоже не любим: раунд должен жить около своего окна.
            if ($dayIdx > $mPrefDay) {
              $score += ($dayIdx - $mPrefDay) * 8000;
            }
          }

          // Soft: матчи одной команды в один день нежелательны (особенно в плей-офф), но не запрещаем.
          $sameDayPenalty = 0;
          if ($homeId) $sameDayPenalty += (int)($countPerDay[$date][$homeId] ?? 0);
          if ($awayId) $sameDayPenalty += (int)($countPerDay[$date][$awayId] ?? 0);
          if ($sameDayPenalty > 0) {
            $score += $sameDayPenalty * 5000;
          }

          // Soft: плей-офф в последний день этапа (день отъезда) — нежелательно.
          if (!empty($m['avoid_last_day']) && (string)($m['phase'] ?? '') === 'playoff' && $lastStageDayDate !== '' && $date === $lastStageDayDate) {
            $score += 2000;
          }


          if ($score < $bestScore) {
            $bestScore = $score;
            $bestIndex = $i;
            $best = $m;
          }
        }

        if ($bestIndex < 0 || !$best) continue;

        $pickedIndex = $bestIndex;
        $picked = $best;
        $pickedScore = $bestScore;

        // размещаем
        array_splice($work, $pickedIndex, 1);

        $assignments[] = [
          'date' => $date,
          'time' => $timeStr,
          'slot_no' => (int)$slotNo,
          'field_code' => (string)$fieldCode,
          'match_id' => (int)$picked['id'],
          'code' => (string)$picked['code'],

          'home_squad_id' => (int)($picked['home_squad_id'] ?? 0),
          'away_squad_id' => (int)($picked['away_squad_id'] ?? 0),

          'label' => (string)$picked['label'],
          'title' => (string)$picked['title'],
          'phase' => (string)$picked['phase'],
          'category' => (string)$picked['category'],
          'group_code' => (string)$picked['group_code'],
          'round_no' => (int)$picked['round_no'],
          'code_view' => ($picked['phase']==='playoff') ? ($picked['code'].'-'.$picked['category']) : $picked['code'],
          'coach_ids' => array_values($picked['coach_ids'] ?? []),
          'coach_penalty' => (int)($pickedScore ?? 0),
          'coach_conflict' => 0,
          'coach_conflict_ids' => [],
          'coach_conflict_with' => [],
        ];

         //$a['code_view'] = $a['code'];
         //if (($a['phase'] ?? '') === 'playoff' && !empty($a['category'])) {
         //  $a['code_view'] = $a['code'] . '-' . $a['category']; // T1-2018
         //}

        $placed++;
        $placedThisSlot++;

        if ($slotIdx >= $officialSlotCount) {
          $extraSlotIdxs[$dayIdx][$slotIdx] = true;
        }

        // обновляем ограничения по командам
        foreach ([$picked['home_squad_id'], $picked['away_squad_id']] as $sid) {
          if (!$sid) continue;
          $lastSlot[$date][$sid] = $slotNo;
          if (!isset($countPerDay[$date][$sid])) $countPerDay[$date][$sid] = 0;
          $countPerDay[$date][$sid]++;
        }


        // обновляем spread-счётчики по group
        if ((string)($picked['phase'] ?? '') === 'group') {
          $catKey = (string)($picked['category'] ?? '');
          $grpKey = $catKey . '|' . (string)($picked['group_code'] ?? '');
          if (!isset($catPlacedByDay[$catKey])) $catPlacedByDay[$catKey] = [];
          if (!isset($groupPlacedByDay[$grpKey])) $groupPlacedByDay[$grpKey] = [];
          $catPlacedByDay[$catKey][$dayIdx] = (int)($catPlacedByDay[$catKey][$dayIdx] ?? 0) + 1;
          $groupPlacedByDay[$grpKey][$dayIdx] = (int)($groupPlacedByDay[$grpKey][$dayIdx] ?? 0) + 1;
          $remainingCatMatches[$catKey] = max(0, (int)($remainingCatMatches[$catKey] ?? 0) - 1);
          $remainingGroupMatches[$grpKey] = max(0, (int)($remainingGroupMatches[$grpKey] ?? 0) - 1);
        }

        // обновляем ограничения по тренерам (soft)
        $newIdx = count($assignments) - 1;

        // помечаем занятость клетки
        if (!isset($occupied[$date])) $occupied[$date] = [];
        if (!isset($occupied[$date][$slotNo])) $occupied[$date][$slotNo] = [];
        $occupied[$date][$slotNo][(string)$fieldCode] = $newIdx;
        $cids = $assignments[$newIdx]['coach_ids'] ?? [];
        foreach ($cids as $cid) {
          $cid = (int)$cid;
          if ($cid <= 0) continue;

          if (!isset($coachBusy[$date][$slotNo][$cid])) $coachBusy[$date][$slotNo][$cid] = [];
          // если уже есть матчи этого тренера в том же слоте — фиксируем конфликт и подсвечиваем обе стороны
          if (!empty($coachBusy[$date][$slotNo][$cid])) {
            $assignments[$newIdx]['coach_conflict'] = 1;
            if (!in_array($cid, $assignments[$newIdx]['coach_conflict_ids'], true)) {
              $assignments[$newIdx]['coach_conflict_ids'][] = $cid;
            }

            foreach ($coachBusy[$date][$slotNo][$cid] as $prevIdx) {
              $assignments[$prevIdx]['coach_conflict'] = 1;
              if (!isset($assignments[$prevIdx]['coach_conflict_ids'])) $assignments[$prevIdx]['coach_conflict_ids'] = [];
              if (!in_array($cid, $assignments[$prevIdx]['coach_conflict_ids'], true)) {
                $assignments[$prevIdx]['coach_conflict_ids'][] = $cid;
              }

              $prevMid = (int)($assignments[$prevIdx]['match_id'] ?? 0);
              $newMid  = (int)($assignments[$newIdx]['match_id'] ?? 0);

              if ($newMid && !in_array($newMid, $assignments[$prevIdx]['coach_conflict_with'] ?? [], true)) {
                if (!isset($assignments[$prevIdx]['coach_conflict_with'])) $assignments[$prevIdx]['coach_conflict_with'] = [];
                $assignments[$prevIdx]['coach_conflict_with'][] = $newMid;
              }
              if ($prevMid && !in_array($prevMid, $assignments[$newIdx]['coach_conflict_with'], true)) {
                $assignments[$newIdx]['coach_conflict_with'][] = $prevMid;
              }
            }
          }

          $coachBusy[$date][$slotNo][$cid][] = $newIdx;
          $coachLastSlot[$date][$cid] = $slotNo;
        }
      }



      // Если слот пустой в primary-режиме, но во вторичном режиме есть что ставить —
      // переносим switchIdx на текущий слот и пересчитываем этот же слот.
      if ($slotIdx < $officialSlotCount && $slotIdx < $switchIdx && $placedThisSlot === 0 && $attempt === 0) {
        $altMode = $secondary;
        $altCols = ($altMode === 'single') ? $singleCols : $pairCols;

        $canAlt = false;
        foreach ($altCols as $altFieldCode) {
          if (isset($occupied[$date][$slotNo][$altFieldCode])) continue;
          foreach ($work as $m) {
            if (!in_array($m['phase'], $phases, true)) continue;
            if (!empty($m['min_day_date'])) { if ($date < (string)$m['min_day_date']) continue; }
            // поле должно быть разрешено (используем тот же _allowed_set, что и основной подбор)
            if (!isset($m['_allowed_set'][(string)$altFieldCode])) continue;

            $h = $m['home_squad_id'];
            $a = $m['away_squad_id'];
            $minRest = (int)$m['min_rest_slots'];
            $maxPerDay = (int)$m['max_matches_per_day'];

            $ok = true;
            foreach ([$h, $a] as $sid) {
              if (!$sid) continue;

              $c = (int)($countPerDay[$date][$sid] ?? 0);
              if ($c >= $maxPerDay) { $ok = false; break; }

              $ls = $lastSlot[$date][$sid] ?? null;
              if ($ls !== null) {
                if (($slotNo - (int)$ls) <= $minRest) { $ok = false; break; }
              }

              $v = _lab_squad_constraint_violation($date, $slotNo, (int)$sid);
              if ($v) { $ok = false; break; }
            }
            if (!$ok) continue;

            $canAlt = true;
            break 2;
          }
        }

        if ($canAlt) {
          $switchIdx = $slotIdx;
          $calendar[$dayIdx]['switchIdx'] = $switchIdx;
          $lastMode = null; // пересчёт для OOB
          $attempt++;
          goto RETRY_SLOT;
        }
      }

      // Чтобы не плодить бесконечные пустые красные строки:
      if ($slotIdx >= $officialSlotCount) {
        if ($placedThisSlot === 0) {
          $emptyOverflowStreak++;
          if ($emptyOverflowStreak >= 2) break;
        } else {
          $emptyOverflowStreak = 0;
        }
      }

      // Если матчей уже не осталось — можно завершать день
      if (!$work) break;
    }
  }

  // repair-pass: пробуем локально устранить конфликты тренеров (soft-constraint)
  $repair = _lab_resolve_coach_conflicts($assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);
  $repairBeforeMix = $repair;

  // mix-pass: уплотнение 12/34 внутри слотов (не ломает базовую сетку, только добавляет/перетаскивает)
  $mixStats = ['moves'=>0, 'packed'=>0, 'pulled_singles'=>0];
  $needMix = ($mix === 'HARD') || ($mix === 'AUTO' && (count($work) > 0 || ((int)($repair['conflicts_left'] ?? 0) > 0)));
  if ($needMix) {
    $mixStats = _lab_mix_12_34($mix, $assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);

    // После mix-pass: ещё раз пробуем развести тренеров.
    // MIX может как уменьшить конфликты, так и создать новые — финальная картинка должна быть честной.
    $repair = _lab_resolve_coach_conflicts($assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);
  }

  // AUTO+Rescue: если после AUTO остались назначения в OOB-слотах,
  // пробуем более агрессивное уплотнение (HARD-mix) как «спасательный круг».
  // Важно: применяем rescue только если он реально уменьшил количество OOB-матчей.
  $autoRescueApplied = 0;
  $autoRescueStats = ['moves'=>0, 'packed'=>0, 'pulled_singles'=>0];

  // Для подсчёта OOB достаточно знать «официальное» кол-во слотов по каждой дате.
  // Важно: OOB — это не наличие OOB-слотов в каркасе, а реальные назначения в слотах за пределами official.
  $daySlotCounts = [];
  for ($i=0; $i<$numDays; $i++) {
    $d = (string)($calendar[$i]['date'] ?? '');
    if ($d === '') continue;
    $daySlotCounts[$d] = is_array($calendar[$i]['slots'] ?? null) ? count($calendar[$i]['slots']) : 0;
  }

  $countOobMatches = function(array $assignments) use ($daySlotCounts): int {
    $cnt = 0;
    foreach ($assignments as $a) {
      if (!is_array($a)) continue;
      $d = (string)($a['date'] ?? '');
      $sn = (int)($a['slot_no'] ?? 0);
      if ($d === '' || $sn <= 0) continue;
      $official = (int)($daySlotCounts[$d] ?? 0);
      if ($official <= 0) continue;
      if (($sn - 1) >= $official) $cnt++;
    }
    return $cnt;
  };

  $oobMatchesBefore = $countOobMatches($assignments);

  if ($mix === 'AUTO' && $oobMatchesBefore > 0) {
    // сохраняем состояние (чтобы откатиться, если rescue ухудшит ситуацию)
    $assignmentsBak = $assignments;
    $calendarBak = $calendar;
    $occupiedBak = $occupied;
    $repairBak = $repair;
    $mixStatsBak = $mixStats;

    // rescue-mix: агрессивно уплотняем
    $autoRescueStats = _lab_mix_12_34('HARD', $assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);
    $repair = _lab_resolve_coach_conflicts($assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);

    $oobMatchesAfter = $countOobMatches($assignments);
    if ($oobMatchesAfter < $oobMatchesBefore) {
      $autoRescueApplied = 1;
    } else {
      // откат (rescue не дал пользы)
      $assignments = $assignmentsBak;
      $calendar = $calendarBak;
      $occupied = $occupiedBak;
      $repair = $repairBak;
      $mixStats = $mixStatsBak;
      $autoRescueStats = ['moves'=>0, 'packed'=>0, 'pulled_singles'=>0];
      $autoRescueApplied = 0;
    }
  }

  // AUTO local-compress: если остались OOB-матчи, но внутри официального окна есть пустые клетки,
  // пробуем минимальными перестановками «втянуть» хвост в диапазон.
  $autoLocalCompressApplied = 0;
  $autoLocalCompressStats = ['moves'=>0,'rescued'=>0,'swap_rescues'=>0];
  $oobMatchesAfterRescue = $countOobMatches($assignments);
  if ($mix === 'AUTO' && $oobMatchesAfterRescue > 0) {
    $dayMap = _lab_day_map($calendar);
    $occupied = _lab_rebuild_occupied($assignments);

    $assignmentsBak2 = $assignments;
    $occupiedBak2 = $occupied;
    $repairBak2 = $repair;

    $autoLocalCompressStats = _lab_auto_local_compress($assignments, $occupied, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);
    $repair = _lab_resolve_coach_conflicts($assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);

    $oobAfterLC = $countOobMatches($assignments);
    if ($oobAfterLC < $oobMatchesAfterRescue) {
      $autoLocalCompressApplied = 1;
    } else {
      // откат (если не стало лучше)
      $assignments = $assignmentsBak2;
      $occupied = $occupiedBak2;
      $repair = $repairBak2;
      $autoLocalCompressStats = ['moves'=>0,'rescued'=>0,'swap_rescues'=>0];
      $autoLocalCompressApplied = 0;
    }
  }

  // final intra-day optimizer for coach proximity
  $genericRescueMoves = _lab_try_generic_rescue(
    $work,
    $assignments,
    $calendar,
    $slotStrideMinutes,
    $prefer,
    $singleCols,
    $pairCols,
    $matchMeta,
    $countPerDay,
    $lastSlot,
    $catPlacedByDay,
    $groupPlacedByDay,
    $remainingCatMatches,
    $remainingGroupMatches,
    $placed
  );
  if ($genericRescueMoves > 0) {
    $repair = _lab_resolve_coach_conflicts($assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);
  }

  $coachOptimizeStats = _lab_optimize_day_by_coaches($assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);
  $repair = _lab_resolve_coach_conflicts($assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);

  $sharedRescueMoves = _lab_try_shared_rescue(
    $work,
    $assignments,
    $calendar,
    $slotStrideMinutes,
    $prefer,
    $singleCols,
    $pairCols,
    $matchMeta,
    $countPerDay,
    $lastSlot,
    $catPlacedByDay,
    $groupPlacedByDay,
    $remainingCatMatches,
    $remainingGroupMatches,
    $placed
  );
  if ($sharedRescueMoves > 0) {
    $repair = _lab_resolve_coach_conflicts($assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);
  }

  $forcedPlacementStats = ['forced' => 0, 'dates' => []];
  if (!empty($work)) {
    $forcedPlacementStats = _lab_force_place_remaining_oob(
      $work,
      $assignments,
      $calendar,
      $slotStrideMinutes,
      $prefer,
      $singleCols,
      $pairCols,
      $countPerDay,
      $lastSlot,
      $placed,
      $lastStageDayDate
    );
    if (($forcedPlacementStats['forced'] ?? 0) > 0) {
      _lab_apply_conflict_flags($assignments, $matchMeta);
      $repair = _lab_resolve_coach_conflicts($assignments, $calendar, $slotStrideMinutes, $prefer, $singleCols, $pairCols, $matchMeta);
    }
  }

  // финальный счётчик OOB-матчей (не слотов!)
  $oobMatches = $countOobMatches($assignments);

  // Формируем days[] для рендера (может быть 1 или 2 карточки на дату — до/после switch).
  // Если использовались OOB-слоты — добавляем их в последний фрейм и помечаем slots_oob.
  $daysOut = [];
  $oobTotal = 0;

  for ($dayIdx = 0; $dayIdx < $numDays; $dayIdx++) {
    $day = $calendar[$dayIdx];
    $date = $day['date'];
    $slots = $day['slots'];
    $officialSlotCount = count($slots);

    // Точка переключения 1..4 ↔ 12/34 рассчитана при размещении матчей и лежит в $calendar.
    // Если не взять её отсюда — UI может показать только 1..4 и «потерять» пары.
    $switchIdxLocal = isset($day['switchIdx']) ? (int)$day['switchIdx'] : $officialSlotCount;

    $isReservedDay = ($reserveDaysEff > 0 && $dayIdx >= $reserveStartIdx);
    $block = 'group';
    if ($reserveDaysEff > 0 && $isReservedDay) {
      $block = in_array('playoff', $phases, true) ? 'playoff' : 'reserved';
    }

    if (!$slots) continue;

    // строим список режимов по слотам и режем на фреймы
    $frames = [];
    $curMode = null;
    $curFrom = 0;

    for ($slotIdx=0; $slotIdx < $officialSlotCount; $slotIdx++) {
      $mode = $modeAt($slotIdx, $switchIdxLocal);
      if ($curMode === null) {
        $curMode = $mode;
        $curFrom = $slotIdx;
        continue;
      }
      if ($mode !== $curMode) {
        $frames[] = [$curFrom, $slotIdx - 1, $curMode];
        $curMode = $mode;
        $curFrom = $slotIdx;
      }
    }
    $frames[] = [$curFrom, $officialSlotCount - 1, $curMode];

    $extraIdxMap = [];
    foreach (($extraSlotIdxs[$dayIdx] ?? []) as $idx => $_) {
      $idx = (int)$idx;
      if ($idx >= $officialSlotCount) $extraIdxMap[$idx] = true;
    }
    foreach ($assignments as $a) {
      if ((string)($a['date'] ?? '') !== $date) continue;
      $sn = (int)($a['slot_no'] ?? 0);
      if ($sn <= 0) continue;
      $idx = $sn - 1;
      if ($idx >= $officialSlotCount) $extraIdxMap[$idx] = true;
    }
    $extraIdxs = array_map('intval', array_keys($extraIdxMap));
    sort($extraIdxs);

    $extraFrames = [];
    if ($extraIdxs) {
      $lastOfficialMode = $frames ? (string)($frames[count($frames) - 1][2] ?? 'single') : (($prefer === 'pair') ? 'pair' : 'single');
      $segFrom = null;
      $segTo = null;
      $segMode = $lastOfficialMode;

      foreach ($extraIdxs as $idx) {
        $slotNoExtra = $idx + 1;
        $modeExtra = $lastOfficialMode;
        foreach ($assignments as $a) {
          if ((string)($a['date'] ?? '') !== $date) continue;
          if ((int)($a['slot_no'] ?? 0) !== $slotNoExtra) continue;
          $fc = (string)($a['field_code'] ?? '');
          if ($fc === '12' || $fc === '34') { $modeExtra = 'pair'; break; }
          if ($fc === '1' || $fc === '2' || $fc === '3' || $fc === '4') $modeExtra = 'single';
        }

        if ($segFrom === null) {
          $segFrom = $idx; $segTo = $idx; $segMode = $modeExtra;
          continue;
        }
        if ($modeExtra !== $segMode || $idx !== ($segTo + 1)) {
          $extraFrames[] = [$segFrom, $segTo, $segMode, true];
          $segFrom = $idx; $segTo = $idx; $segMode = $modeExtra;
          continue;
        }
        $segTo = $idx;
      }
      if ($segFrom !== null) $extraFrames[] = [$segFrom, $segTo, $segMode, true];
    }

    $allFrames = [];
    foreach ($frames as $f) $allFrames[] = [$f[0], $f[1], $f[2], false];
    foreach ($extraFrames as $f) $allFrames[] = $f;

    foreach ($allFrames as [$from, $to, $mode, $isExtra]) {
      if ($to < $from) continue;
      $cols = ($mode === 'single') ? $singleCols : $pairCols;

      $slotTimes = [];
      $slotOob = [];
      $frameStartDt = null;
      $frameEndDT = null;

      for ($i=$from; $i <= $to; $i++) {
        if ($i < $officialSlotCount) {
          $dt = $slots[$i];
          $isOob = 0;
        } else {
          $dt = $day['dayStart']->modify('+' . ($i * $slotStrideMinutes) . ' minutes');
          $endDt = $dt->modify('+' . $slotLastMinutes . ' minutes');
          $isOob = ($endDt > $day['dayEnd']) ? 1 : 0;
          if ($isOob) $oobTotal++;
        }
        if ($frameStartDt === null) $frameStartDt = $dt;
        $frameEndDT = $dt->modify('+' . $slotLastMinutes . ' minutes');
        $slotTimes[] = $dt->format('H:i');
        $slotOob[] = $isOob;
      }
      if (!$frameStartDt || !$frameEndDT) continue;
      if (!$isExtra && $frameEndDT > $day['dayEnd']) $frameEndDT = $day['dayEnd'];

      $daysOut[] = [
        'block'=> $block,
        'date' => $date,
        'start'=> $frameStartDt->format('H:i'),
        'end'  => $frameEndDT->format('H:i'),
        'end_official' => $day['dayEnd']->format('H:i'),
        'fields' => count($cols),
        'mode' => $mode,
        'cols' => array_values($cols),
        'slots'=> $slotTimes,
        'slots_oob' => $slotOob,
        'oob_count' => array_sum($slotOob),
        'slot_minutes' => $slotStrideMinutes,
        'last_slot_minutes' => $slotLastMinutes,
        'slot_count' => count($slotTimes),
        'slot_count_in_range' => $isExtra ? 0 : ($to - $from + 1),
      ];
    }
  }

  // Сводка конфликтов по тренерам (когда у одного тренера две команды играют в одно и то же время).
  // Это soft-правило: расписание всё равно строится, но конфликтные матчи должны легко подсвечиваться.
  $coachConflictList = [];
  $coachConflictedMatches = 0;
  if ($assignments) {
    $map = []; // key = date|time|coachId
    foreach ($assignments as $a) {
      if (empty($a['coach_conflict'])) continue;
      $coachConflictedMatches++;

      $date = (string)($a['date'] ?? '');
      $time = (string)($a['time'] ?? '');
      $cids = $a['coach_conflict_ids'] ?? [];
      foreach ($cids as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        $k = $date . '|' . $time . '|' . $cid;
        if (!isset($map[$k])) {
          $map[$k] = [
            'date' => $date,
            'time' => $time,
            'coach_id' => $cid,
            'coach_name' => (string)($coachNameById[$cid] ?? ('coach#'.$cid)),
            'matches' => [],
          ];
        }
        $map[$k]['matches'][] = [
          'match_id' => (int)($a['match_id'] ?? 0),
          'code' => (string)($a['code_view'] ?? $a['code'] ?? ''),
          'field_code' => (string)($a['field_code'] ?? ''),
        ];
      }
    }
    $coachConflictList = array_values($map);
  }

  
  // Диагностика: почему матчи остались неразмещёнными (unplaced)
  // Возвращаем для каждого матча набор "жёстких причин" + статистику по попыткам.
  $unplaced_reasons = [];
  if (!empty($work)) {

    // day map: date => ['day_idx'=>..,'slots'=>..,'slotCount'=>..,'dayStart'=>..]
    $dayMap = _lab_day_map($calendar);

    // max slot per date (учитываем OOB-слоты, если они уже появились в assignments)
    $maxSlotByDate = [];
    foreach ($calendar as $drow) {
      $dd = (string)($drow['date'] ?? '');
      if ($dd === '') continue;
      $maxSlotByDate[$dd] = (int)($drow['slotCount'] ?? 0);
    }
    foreach ($assignments as $a) {
      $dd = (string)($a['date'] ?? '');
      $sn = (int)($a['slot_no'] ?? 0);
      if ($dd === '' || $sn <= 0) continue;
      if (!isset($maxSlotByDate[$dd]) || $sn > $maxSlotByDate[$dd]) $maxSlotByDate[$dd] = $sn;
    }

    foreach ($work as $m) {
      $mid = (int)($m['id'] ?? 0);
      $code = (string)($m['code'] ?? '');
      $phase = (string)($m['phase'] ?? '');
      $allowed = $m['_allowed_set'] ?? [];
      $minDayDate = (string)($m['min_day_date'] ?? '');
      $avoidLast = (!empty($m['avoid_last_day']) && $phase === 'playoff' && $lastStageDayDate !== '');

      $homeId = (int)($m['home_squad_id'] ?? 0);
      $awayId = (int)($m['away_squad_id'] ?? 0);
      $minRest = (int)($m['min_rest_slots'] ?? 0);
      $maxPerDay = (int)($m['max_matches_per_day'] ?? 0);
      $coachIds = $m['coach_ids'] ?? [];

      $stats = [
        'checked_cells' => 0,
        'free_cells' => 0,
        'ok_cells' => 0,
        'reject' => [
          'day_out_of_range' => 0,
          'oob_playoff' => 0,
          'min_day_date' => 0,
          'field_not_allowed' => 0,
          'max_per_day' => 0,
          'min_rest' => 0,
          'constraint' => 0,
          'event_reserved' => 0,
          'shared_max_per_day' => 0,
          'shared_min_rest' => 0,
          'playoff_source_unplaced' => 0,
          'playoff_order' => 0,
          'coach_conflict' => 0,
          'occupied' => 0,
        ],
      ];

      $hasAnyFree = false;
      $hasAnyOk = false;

      foreach ($dayMap as $dd => $day) {
        $dayIdx = (int)($day['day_idx'] ?? 0);
        $slotCount = (int)($maxSlotByDate[$dd] ?? 0);
        if ($slotCount <= 0) continue;

        $mFirstDayIdx = (int)($m['first_day_idx'] ?? 0);
        $mLastDayIdx  = (int)($m['last_day_idx'] ?? ($numDays - 1));
        if ($dayIdx < $mFirstDayIdx || $dayIdx > $mLastDayIdx) {
          $stats['reject']['day_out_of_range'] += $slotCount;
          continue;
        }

        for ($slotNo=1; $slotNo <= $slotCount; $slotNo++) {
          $slotIdx = $slotNo - 1;
          $officialSlotCount = (int)(count($day['slots'] ?? []));

          // Плей-офф не размещаем в OOB-слоты
          if ($phase === 'playoff' && $slotIdx >= $officialSlotCount) { $stats['reject']['oob_playoff']++; continue; }

          // min_day_date
          if ($minDayDate !== '' && $minDayDate !== '0000-00-00' && $dd < $minDayDate) { $stats['reject']['min_day_date']++; continue; }

          $eventViolation = _lab_stage_event_violation($dd, $slotNo, $dayMap, $slotStrideMinutes, $slotLastMinutes);
          if ($eventViolation) { $stats['reject']['event_reserved']++; continue; }

          // колонки по режиму слота
          $switchIdx = (int)($day['switchIdx'] ?? $officialSlotCount);
          $mode = $modeAt($slotIdx, $switchIdx);
          $cols = ($mode === 'single') ? $singleCols : $pairCols;

          foreach ($cols as $fieldCode) {
            $stats['checked_cells']++;

            // занятость
            if (!empty($occupied[$dd][$slotNo][$fieldCode])) { $stats['reject']['occupied']++; continue; }

            $stats['free_cells']++;
            $hasAnyFree = true;

            // поле должно быть разрешено
            if (!isset($allowed[(string)$fieldCode])) { $stats['reject']['field_not_allowed']++; continue; }

            // ограничения по командам: maxPerDay + minRest + squad_constraints
            $ok = true;

            // maxPerDay
            if ($maxPerDay > 0) {
              if ($homeId > 0 && (int)($countPerDay[$dd][$homeId] ?? 0) >= $maxPerDay) { $stats['reject']['max_per_day']++; $ok=false; }
              if ($ok && $awayId > 0 && (int)($countPerDay[$dd][$awayId] ?? 0) >= $maxPerDay) { $stats['reject']['max_per_day']++; $ok=false; }
            }
            if (!$ok) continue;

            // minRest
            if ($minRest > 0) {
              if ($homeId > 0) {
                $ls = $lastSlot[$dd][$homeId] ?? null;
                if ($ls !== null && (($slotNo - (int)$ls) <= $minRest)) { $stats['reject']['min_rest']++; $ok=false; }
              }
              if ($ok && $awayId > 0) {
                $ls = $lastSlot[$dd][$awayId] ?? null;
                if ($ls !== null && (($slotNo - (int)$ls) <= $minRest)) { $stats['reject']['min_rest']++; $ok=false; }
              }
            }
            if (!$ok) continue;

            // squad_constraints
            if ($homeId > 0) {
              $v = _lab_squad_constraint_violation($dd, $slotNo, $homeId);
              if ($v) { $stats['reject']['constraint']++; $ok=false; }
            }
            if ($ok && $awayId > 0) {
              $v = _lab_squad_constraint_violation($dd, $slotNo, $awayId);
              if ($v) { $stats['reject']['constraint']++; $ok=false; }
            }
            if (!$ok) continue;

            $sharedViolation = _lab_shared_rule_violation($dd, $slotNo, $m, $assignments, $matchMeta);
            if ($sharedViolation) {
              $svCode = strtoupper((string)($sharedViolation['code'] ?? ''));
              if ($svCode === 'SHARED_MAX_PER_DAY') $stats['reject']['shared_max_per_day']++;
              elseif ($svCode === 'SHARED_MIN_REST') $stats['reject']['shared_min_rest']++;
              else $stats['reject']['shared_max_per_day']++;
              continue;
            }

            $playoffDepViolation = _lab_playoff_dependency_violation($dd, $slotNo, $m, $assignments, $matchMeta, $dayIndexByDate);
            if ($playoffDepViolation) {
              $pvCode = strtoupper((string)($playoffDepViolation['code'] ?? ''));
              if ($pvCode === 'PLAYOFF_SOURCE_UNPLACED') $stats['reject']['playoff_source_unplaced']++;
              else $stats['reject']['playoff_order']++;
              continue;
            }

            // тренеры: конфликт в одном слоте запрещён
            if ($coachIds) {
              $coachBlock = false;
              foreach ($coachIds as $cid) {
                $cid = (int)$cid;
                if ($cid <= 0) continue;
                if (!empty($coachBusy[$dd][$slotNo][$cid])) { $coachBlock = true; break; }
              }
              if ($coachBlock) { $stats['reject']['coach_conflict']++; continue; }
            }

            // всё ок
            $stats['ok_cells']++;
            $hasAnyOk = true;
          }
        }
      }

      // Топ причин (по reject counts)
      $rej = $stats['reject'];
      arsort($rej);

      $reasons = [];

      if (!$hasAnyFree) {
        $reasons[] = ['code'=>'no_free_cells', 'title'=>'Нет свободных клеток в сетке (в рамках текущих дней/слотов)'];
      } elseif (!$hasAnyOk) {
        // Собираем главные жёсткие блокеры
        foreach ($rej as $k => $cnt) {
          if ($cnt <= 0) continue;
          $title = $k;
          if ($k === 'day_out_of_range') $title = 'День вне допустимого диапазона для этого матча / категории';
          elseif ($k === 'oob_playoff') $title = 'Плей-офф не размещаем в OOB-слоты';
          elseif ($k === 'min_day_date') $title = 'Матч нельзя раньше min_day_date (после групп)';
          elseif ($k === 'field_not_allowed') $title = 'Нет подходящего поля по allowed_fields';
          elseif ($k === 'max_per_day') $title = 'Превышен лимит матчей в день (max_matches_per_day)';
          elseif ($k === 'min_rest') $title = 'Недостаточный отдых между матчами (min_rest_slots)';
          elseif ($k === 'constraint') $title = 'Запрещено ограничениями команды (squad_constraints)';
          elseif ($k === 'event_reserved') $title = 'Слот зарезервирован служебным событием';
          elseif ($k === 'shared_max_per_day') $title = 'Превышен общий лимит матчей в день для связанных команд';
          elseif ($k === 'shared_min_rest') $title = 'Недостаточный общий отдых для связанных команд';
          elseif ($k === 'playoff_source_unplaced') $title = 'Источник W/L ещё не размещён';
          elseif ($k === 'playoff_order') $title = 'Матч плей-офф нельзя ставить раньше матча-источника';
          elseif ($k === 'coach_conflict') $title = 'Конфликт тренера в одном слоте';
          elseif ($k === 'occupied') $title = 'Клетка занята другими матчами';
          $reasons[] = ['code'=>$k, 'title'=>$title, 'count'=>(int)$cnt];
          if (count($reasons) >= 4) break;
        }
      } else {
        // Есть допустимые клетки, но greedy не нашёл решение без перестановок
        $reasons[] = ['code'=>'needs_swaps', 'title'=>'Есть допустимые клетки, но текущая жадная раскладка не нашла решение без перестановок'];
      }

      if ($avoidLast) {
        $reasons[] = ['code'=>'soft_avoid_last_day', 'title'=>'Мягкое правило: плей-офф нежелателен в последний день этапа'];
      }

      $unplaced_reasons[] = [
        'id' => $mid,
        'code' => $code,
        'phase' => $phase,
        'title' => (string)($m['title'] ?? ''),
        'home_squad_id' => $homeId,
        'away_squad_id' => $awayId,
        'min_day_date' => $minDayDate,
        'stats' => [
          'checked_cells' => (int)$stats['checked_cells'],
          'free_cells' => (int)$stats['free_cells'],
          'ok_cells' => (int)$stats['ok_cells'],
          'reject' => $rej,
        ],
        'reasons' => $reasons,
      ];
    }
  }

  // --- meta for UI/diagnostics: match -> coach ids, coach -> name
  $match_coach_map = [];
  if (isset($matchMeta) && is_array($matchMeta)) {
    foreach ($matchMeta as $mid => $mm) {
      $mid = (int)$mid;
      if ($mid <= 0) continue;
      $cids = $mm['coach_ids'] ?? [];
      if (!is_array($cids)) $cids = [];
      $cids = array_values(array_filter(array_map('intval', $cids)));
      $match_coach_map[(string)$mid] = $cids;
    }
  }

  $coach_name_map = [];
  if (isset($coachNameById) && is_array($coachNameById)) {
    foreach ($coachNameById as $cid => $nm) {
      $cid = (int)$cid;
      if ($cid <= 0) continue;
      $coach_name_map[(string)$cid] = (string)$nm;
    }
  }

$out = [
  'ok' => true,
  'days' => $daysOut,
  'assignments' => $assignments,
  'coach_conflicts' => $coachConflictList,
  'coach_conflicts_total' => count($coachConflictList),
  'stage_events' => array_values(array_reduce(_lab_get_stage_events(), function($carry, $list){
    foreach ((array)$list as $ev) {
      $carry[] = [
        'id' => (int)($ev['id'] ?? 0),
        'event_date' => (string)($ev['event_date'] ?? ''),
        'time_from' => (string)($ev['time_from'] ?? ''),
        'time_to' => (string)($ev['time_to'] ?? ''),
        'event_type' => (string)($ev['event_type'] ?? 'custom'),
        'title' => (string)($ev['title'] ?? ''),
        'is_active' => (int)($ev['is_active'] ?? 1),
      ];
    }
    return $carry;
  }, [])),
  'event_slots' => $eventSlotsOut ?? [],
  'unplaced' => array_map(function($m){
    return [
      'id' => (int)$m['id'],
      'code' => (string)$m['code'],
      'label' => (string)$m['label'],
      'title' => (string)$m['title'],
      'phase' => (string)$m['phase'],
      'category' => (string)$m['category'],
      'group_code' => (string)$m['group_code'],
      'round_no' => (int)$m['round_no'],
      'allowed_fields' => array_values($m['allowed_fields'] ?? []),
      'preferred_day' => (int)$m['preferred_day'],
      'first_day_idx' => (int)($m['first_day_idx'] ?? 0),
      'last_day_idx' => (int)($m['last_day_idx'] ?? 0),
    ];
  }, $work),
  'unplaced_reasons' => $unplaced_reasons,

  'meta' => [
    'stage_id' => $stageId,
    'phases' => $phases,
    'prefer' => $prefer,
    'mix_12_34' => $mix,
    'mix_12_34_effective' => ($autoRescueApplied ? 'AUTO+RESCUE' : $mix),
    'mix_moves' => (int)($mixStats['moves'] ?? 0),
    'mix_packed' => (int)($mixStats['packed'] ?? 0),
    'mix_pulled_singles' => (int)($mixStats['pulled_singles'] ?? 0),
    'auto_rescue_applied' => (int)$autoRescueApplied,
    'auto_rescue_moves' => (int)($autoRescueStats['moves'] ?? 0),
    'auto_rescue_packed' => (int)($autoRescueStats['packed'] ?? 0),
    'auto_rescue_pulled_singles' => (int)($autoRescueStats['pulled_singles'] ?? 0),

    'auto_local_compress_applied' => (int)$autoLocalCompressApplied,
    'auto_local_compress_moves' => (int)($autoLocalCompressStats['moves'] ?? 0),
    'auto_local_compress_rescued' => (int)($autoLocalCompressStats['rescued'] ?? 0),
    'auto_local_compress_swap_rescues' => (int)($autoLocalCompressStats['swap_rescues'] ?? 0),
    'playoff_days' => ($reserveDaysEff > 0 ? $reserveDaysEff : 0),
    'reserve_mode' => 'per_category',
    'reserve_start_idx' => $reserveStartIdx,
    'group_days' => ($groupLastDayIdx >= 0 ? ($groupLastDayIdx + 1) : 0),
    'reserved_days' => ($reserveDaysEff > 0 ? ($numDays - $reserveStartIdx) : 0),
    'slot_minutes' => $slotStrideMinutes,
    'last_slot_minutes' => $slotLastMinutes,
    'matches_total' => count($matches),
    'placed' => $placed,
    'unplaced' => count($work),
    'coach_conflicts_total' => count($coachConflictList),
    'coach_conflicted_matches' => $coachConflictedMatches,
    'coach_repair_moves_before_mix' => (int)($repairBeforeMix['moves'] ?? 0),
    'coach_conflicts_left_before_mix' => (int)($repairBeforeMix['conflicts_left'] ?? 0),
    'coach_repair_moves' => (int)($repair['moves'] ?? 0),
    'coach_conflicts_left' => (int)($repair['conflicts_left'] ?? 0),
    'coach_optimize_moves' => (int)($coachOptimizeStats['moves'] ?? 0),
    'coach_optimize_days_tightened' => (int)($coachOptimizeStats['days_tightened'] ?? 0),
    'coach_optimize_score_before' => (int)($coachOptimizeStats['score_before'] ?? 0),
    'coach_optimize_score_after' => (int)($coachOptimizeStats['score_after'] ?? 0),
    'generic_rescue_moves' => (int)($genericRescueMoves ?? 0),
    'shared_rescue_moves' => (int)($sharedRescueMoves ?? 0),
    'forced_oob_placed' => (int)($forcedPlacementStats['forced'] ?? 0),
    'shared_load_rules_total' => count((_lab_get_shared_load_rules()['rules'] ?? [])),
    'oob_slots' => $oobTotal,
    'oob_matches' => (int)$oobMatches,
    'events_total' => count($eventSlotsOut ?? []),
    'fields' => [
      'single' => $singleCols,
      'pair' => $pairCols,
      'mask' => $fieldMask,
    ],
    'match_coach_map' => $match_coach_map,
    'coach_name_map' => $coach_name_map,
    'ms' => _lab_ms((float)($GLOBALS['__lab_startedAt'] ?? $__startedAt)),
  ],
];

  _lab_out($out, 200);

} catch (Throwable $e) {
  _lab_out([
    'ok' => false,
    'error' => $e->getMessage(),
    'meta' => [
      'type' => get_class($e),
      'file' => $e->getFile(),
      'line' => $e->getLine(),
      'ms'   => _lab_ms((float)($GLOBALS['__lab_startedAt'] ?? $__startedAt)),
    ],
  ], 200);
}
