<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('operator','organizer');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'POST only'], 405);
}

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '', true);
if (!is_array($in)) {
  respond_json(['ok'=>false,'error'=>'bad json'], 400);
}

$stageId   = (int)($in['stage_id'] ?? 0);
$category  = (string)($in['category'] ?? '');
$variant   = (int)($in['variant'] ?? 0);
$groupCnt  = (int)($in['group_count'] ?? 0);

if ($stageId<=0 || $category==='' || $variant<=0 || $groupCnt<1 || $groupCnt>4) {
  respond_json(['ok'=>false,'error'=>'stage_id, category, variant, group_count required'], 400);
}

// ---------- helpers ----------
function _norm_spaces(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
  return $s;
}

function _dash_to_minus(string $s): string {
  return str_replace(["–","—","‑"], '-', $s);
}

function _strip_roster_suffix(string $name): string {
  $n = _dash_to_minus(_norm_spaces($name));
  // strip only 1..4 variants to avoid chopping DЮСШ-5 etc.
  $n2 = preg_replace('/\s*-\s*([1-4])$/u', '', $n);
  if ($n2 !== null && $n2 !== $n) return trim($n2);

  $n3 = preg_replace('/\s+([1-4])$/u', '', $n);
  if ($n3 !== null && $n3 !== $n) return trim($n3);

  return $n;
}

function _family_key(string $clubName, string $city): string {
  $base = _strip_roster_suffix($clubName);
  $base = _norm_spaces($base);
  $city = _norm_spaces($city);
  if ($base === '') $base = $clubName;

  $base = mb_strtolower($base, 'UTF-8');
  $city = mb_strtolower($city, 'UTF-8');
  return $base . '|' . $city;
}

/**
 * Build groups trying to separate duplicates by family_key.
 * Returns [groupsMap, warnings]
 */
function _build_groups(array $teams, int $groupCnt): array {
  $warnings = [];

  // family counts
  $familyCount = [];
  foreach ($teams as $t) {
    $fk = (string)($t['family_key'] ?? '');
    if ($fk === '') continue;
    $familyCount[$fk] = ($familyCount[$fk] ?? 0) + 1;
  }

  // sort: rating desc, name asc
  usort($teams, function($a,$b){
    $ra = (int)($a['rating'] ?? 0);
    $rb = (int)($b['rating'] ?? 0);
    if ($ra !== $rb) return $rb <=> $ra;
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });

  $codes = ['A','B','C','D'];
  $G = [];
  for ($i=0;$i<$groupCnt;$i++) {
    $G[$codes[$i]] = ['teams'=>[], 'families'=>[], 'sum_rating'=>0];
  }

  foreach ($teams as $t) {
    $fk = (string)($t['family_key'] ?? '');

    $eligible = [];
    foreach ($G as $code => $g) {
      $hasSameFamily = ($fk !== '' && isset($g['families'][$fk]));
      if (!$hasSameFamily) $eligible[] = $code;
    }

    // if impossible to separate (groupCnt < familyCount), allow but warn later
    if (!$eligible) {
      $eligible = array_keys($G);
    }

    // choose: smallest size, then lowest sum_rating
    usort($eligible, function($c1,$c2) use ($G){
      $s1 = count($G[$c1]['teams']);
      $s2 = count($G[$c2]['teams']);
      if ($s1 !== $s2) return $s1 <=> $s2;
      return ($G[$c1]['sum_rating'] ?? 0) <=> ($G[$c2]['sum_rating'] ?? 0);
    });

    $pick = $eligible[0];
    $G[$pick]['teams'][] = $t;
    $G[$pick]['sum_rating'] += (int)($t['rating'] ?? 0);
    if ($fk !== '') $G[$pick]['families'][$fk] = true;
  }

  // warnings about families not separated
  foreach ($familyCount as $fk => $cnt) {
    if ($cnt <= 1) continue;

    $inGroups = 0;
    foreach ($G as $g) {
      $has = false;
      foreach ($g['teams'] as $t) {
        if (($t['family_key'] ?? '') === $fk) { $has = true; break; }
      }
      if ($has) $inGroups++;
    }

    if ($inGroups < $cnt) {
      // decode readable base+city
      [$base, $city] = array_pad(explode('|', $fk, 2), 2, '');
      $base = $base !== '' ? mb_convert_case($base, MB_CASE_TITLE, 'UTF-8') : 'Клуб';
      $city = $city !== '' ? mb_convert_case($city, MB_CASE_TITLE, 'UTF-8') : '';
      $label = $city !== '' ? ($base . ' (' . $city . ')') : $base;
      $warnings[] = 'Не удалось развести по разным группам: ' . $label . ' — составов ' . $cnt . ', групп ' . $groupCnt . '.';
    }
  }

  // to plain map
  $out = [];
  foreach ($G as $code => $g) {
    $out[$code] = $g['teams'];
  }

  return [$out, $warnings];
}

// ---------- load teams ----------
$pdo = db();

$st = $pdo->prepare('SELECT tournament_id FROM stages WHERE id=:sid');
$st->execute([':sid'=>$stageId]);
$tournamentId = (int)($st->fetchColumn() ?: 0);
if ($tournamentId <= 0) {
  respond_json(['ok'=>false,'error'=>'stage not found or bad tournament_id'], 404);
}

lc_require_tournament_not_archived($pdo, $tournamentId);

$st = $pdo->prepare(
  'SELECT s.id, s.club_id, s.name, s.rating, c.name AS club_name, c.city AS club_city
   FROM squads s
   LEFT JOIN clubs c ON c.id = s.club_id
   WHERE s.stage_id=:sid AND s.category=:cat AND s.category_variant=:v
   ORDER BY s.rating DESC, s.name ASC'
);
$st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
$teams = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$teams) {
  respond_json(['ok'=>false,'error'=>'no teams'], 400);
}

// attach family_key
foreach ($teams as &$t) {
  $clubName = (string)($t['club_name'] ?? $t['name'] ?? '');
  $city     = (string)($t['club_city'] ?? '');
  $t['family_key'] = _family_key($clubName, $city);
}
unset($t);

if (count($teams) < 3) {
  respond_json(['ok'=>false,'error'=>'need at least 3 teams'], 400);
}

// build deterministic groups (same as preview)
[$groups, $warnings] = _build_groups($teams, $groupCnt);

// save: replace existing
$pdo->beginTransaction();
try {
  $pdo->prepare('DELETE FROM group_entries WHERE stage_id=:sid AND category=:cat AND variant=:v')
      ->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);

  $ins = $pdo->prepare(
    'INSERT INTO group_entries (tournament_id, stage_id, category, variant, group_code, pos, squad_id)
     VALUES (:tid, :sid, :cat, :v, :g, :pos, :sqid)'
  );

  foreach ($groups as $code => $list) {
    $pos = 1;
    foreach ($list as $t) {
      $ins->execute([
        ':tid'=>$tournamentId,
        ':sid'=>$stageId,
        ':cat'=>$category,
        ':v'=>$variant,
        ':g'=>$code,
        ':pos'=>$pos,
        ':sqid'=>(int)$t['id'],
      ]);
      $pos++;
    }
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>'db: '.$e->getMessage()], 500);
}

respond_json([
  'ok'=>true,
  'meta'=>[
    'stage_id'=>$stageId,
    'category'=>$category,
    'variant'=>$variant,
    'group_count'=>$groupCnt,
    'teams'=>count($teams),
  ],
  'warnings'=>$warnings,
]);
