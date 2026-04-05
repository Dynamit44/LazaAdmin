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

if ($stageId <= 0 || $category === '' || $variant <= 0) {
  respond_json(['ok'=>false,'error'=>'stage_id/category/variant required'], 400);
}
if ($groupCnt < 1 || $groupCnt > 4) {
  respond_json(['ok'=>false,'error'=>'group_count must be 1..4'], 400);
}

$pdo = db();

// squads for category
$st = $pdo->prepare('
  SELECT
    s.id,
    s.club_id,
    s.name,
    s.rating,
    c.name AS club_name,
    c.city AS club_city
  FROM squads s
  LEFT JOIN clubs c ON c.id = s.club_id
  WHERE s.stage_id=:sid AND s.category=:cat AND s.category_variant=:v
  ORDER BY s.rating DESC, s.name ASC, s.id ASC
');
$st->execute([':sid'=>$stageId, ':cat'=>$category, ':v'=>$variant]);
$teams = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$n = count($teams);
if ($n < 3) {
  respond_json(['ok'=>false,'error'=>'need at least 3 teams'], 400);
}

// max groups by min size=3
$maxGroups = (int)floor($n / 3);
$maxGroups = max(1, min(4, $maxGroups));
if ($groupCnt > $maxGroups) {
  respond_json(['ok'=>false,'error'=>'too many groups for teams count'], 400);
}

function _norm_space(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
  return trim($s);
}

function _norm_dash(string $s): string {
  // normalize different dash chars to '-'
  return str_replace(["–","—","‑","−"], '-', $s);
}

function _family_candidate(string $name): string {
  $name = _norm_space(_norm_dash($name));

  // strip trailing roster suffix like "-1" / "-2" / " 1" / " 2" (only 1..4 to avoid DЮСШ-5 cases)
  $name2 = preg_replace('/\s*-\s*([1-4])$/u', '', $name) ?? $name;
  $name2 = preg_replace('/\s+([1-4])$/u', '', $name2) ?? $name2;

  return _norm_space($name2);
}

// build family map (only within текущей категории+варианта)
$familyCount = [];
$teamMeta = [];
foreach ($teams as $t) {
  $clubName = (string)($t['club_name'] ?? '');
  $clubCity = (string)($t['club_city'] ?? '');
  $nm = $clubName !== '' ? $clubName : (string)($t['name'] ?? '');
  $ct = $clubCity;

  $base = _family_candidate($nm);
  $ctN  = _norm_space($ct);

  // lowercase key for stable matching
  $key = mb_strtolower($base, 'UTF-8') . '|' . mb_strtolower($ctN, 'UTF-8');
  $teamMeta[(int)$t['id']] = ['family_key'=>$key, 'family_base'=>$base, 'family_city'=>$ctN];
  $familyCount[$key] = ($familyCount[$key] ?? 0) + 1;
}

// groups builder
function build_groups(array $teams, int $groupCnt, array $teamMeta, array $familyCount): array {
  $codes = ['A','B','C','D'];

  // init
  $groups = [];
  for ($i=0; $i<$groupCnt; $i++) {
    $groups[$codes[$i]] = ['teams'=>[], 'families'=>[], 'sum'=>0];
  }

  // greedy: by rating desc already in query
  $warnings = [];

  foreach ($teams as $t) {
    $tid = (int)$t['id'];
    $fam = (string)($teamMeta[$tid]['family_key'] ?? ('club:' . (int)$t['club_id']));
    $famCnt = (int)($familyCount[$fam] ?? 1);

    // pick eligible groups (avoid putting same "family" into same group if there are duplicates)
    $eligible = [];
    foreach ($groups as $code => $g) {
      if ($famCnt <= 1) {
        $eligible[$code] = $g;
      } else {
        if (!isset($g['families'][$fam])) {
          $eligible[$code] = $g;
        }
      }
    }

    if (!$eligible) {
      // cannot avoid collision
      $eligible = $groups;
    }

    // choose by: smallest size, then smallest sum rating
    $bestCode = null;
    $bestSize = 1e9;
    $bestSum = 1e18;
    foreach ($eligible as $code => $g) {
      $sz = count($g['teams']);
      $sm = (int)$g['sum'];
      if ($sz < $bestSize || ($sz === $bestSize && $sm < $bestSum)) {
        $bestSize = $sz;
        $bestSum = $sm;
        $bestCode = $code;
      }
    }

    if ($bestCode === null) {
      $bestCode = array_key_first($groups);
    }

    $groups[$bestCode]['teams'][] = $t;
    $groups[$bestCode]['sum'] += (int)$t['rating'];
    if ($famCnt > 1) {
      $groups[$bestCode]['families'][$fam] = true;
    }
  }

  // post-check: did we actually split duplicate families?
  $collisions = [];
  foreach ($groups as $code => $g) {
    $seen = [];
    foreach ($g['teams'] as $t) {
      $tid = (int)$t['id'];
      $fam = (string)($teamMeta[$tid]['family_key'] ?? ('club:' . (int)$t['club_id']));
      if ((int)($familyCount[$fam] ?? 1) <= 1) continue;
      $seen[$fam] = ($seen[$fam] ?? 0) + 1;
    }
    foreach ($seen as $fam => $cnt) {
      if ($cnt > 1) {
        $collisions[$fam][] = $code;
      }
    }
  }

  foreach ($collisions as $fam => $codes2) {
    $warnings[] = 'Не удалось развести два состава одной школы по разным группам: ' . $fam . ' (группа(ы): ' . implode(',', $codes2) . ')';
  }

  // format groups for response
  $out = [];
  foreach ($groups as $code => $g) {
    $arr = [];
    foreach ($g['teams'] as $t) {
      $arr[] = [
        'id' => (int)$t['id'],
        'club_id' => (int)$t['club_id'],
        'name' => (string)$t['name'],
        'rating' => (int)$t['rating'],
      ];
    }
    $out[$code] = $arr;
  }

  return [$out, $warnings];
}

[$groups, $warnings] = build_groups($teams, $groupCnt, $teamMeta, $familyCount);

// meta
$totalMatches = 0;
foreach ($groups as $code => $arr) {
  $k = count($arr);
  $totalMatches += (int)(($k * ($k - 1)) / 2);
}

respond_json([
  'ok' => true,
  'meta' => [
    'stage_id' => $stageId,
    'category' => $category,
    'variant' => $variant,
    'group_count' => $groupCnt,
    'teams' => $n,
    'total_matches' => $totalMatches,
  ],
  'groups' => $groups,
  'warnings' => $warnings,
]);
