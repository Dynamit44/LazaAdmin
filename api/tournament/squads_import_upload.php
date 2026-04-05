<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$stageId = (int)($_POST['stage_id'] ?? 0);
$dry     = (int)($_POST['dry'] ?? 0); // 1 = прогон без записи

if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  respond_json(['ok'=>false,'error'=>'file required'], 400);
}

$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  respond_json(['ok'=>false,'error'=>'upload error'], 400);
}

$tmp = (string)($f['tmp_name'] ?? '');
if ($tmp === '' || !is_file($tmp)) respond_json(['ok'=>false,'error'=>'bad upload'], 400);

$csvRaw = (string)file_get_contents($tmp);
$csvRaw = trim($csvRaw);
if ($csvRaw === '') respond_json(['ok'=>false,'error'=>'empty file'], 400);

// normalize line endings
$csvRaw = str_replace(["\r\n","\r"], "\n", $csvRaw);

// convert to UTF-8 if needed
if (!mb_check_encoding($csvRaw, 'UTF-8')) {
  $csvRaw = (string)@mb_convert_encoding($csvRaw, 'UTF-8', 'Windows-1251');
}

// remove BOM
$csvRaw = (string)preg_replace('~^\xEF\xBB\xBF~', '', $csvRaw);

$lines = array_values(array_filter(explode("\n", $csvRaw), fn($l)=>trim($l)!==''));
if (count($lines) < 2) respond_json(['ok'=>false,'error'=>'csv must have header + rows'], 400);

function nrm(string $s): string {
  $s = trim($s);
  $s = (string)preg_replace('~^\xEF\xBB\xBF~', '', $s);
  $s = (string)preg_replace('/\s+/u', ' ', $s);
  return $s;
}
function nrmYear(string $s): string {
  $s = nrm($s);
  $s = (string)preg_replace('~[^0-9]~u', '', $s);
  return $s;
}

$pdo = db();

// stage exists + tournament_id
$st = $pdo->prepare("SELECT id, tournament_id FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id'=>$stageId]);
$stageRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$stageRow) respond_json(['ok'=>false,'error'=>'stage not found'], 400);

$tournamentId = (int)$stageRow['tournament_id'];
if ($tournamentId <= 0) respond_json(['ok'=>false,'error'=>'stage has no tournament_id'], 400);

lc_require_tournament_not_archived($pdo, $tournamentId);

// lock if schedule exists
$cnt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM schedule sh
  JOIN matches m ON m.id = sh.match_id
  WHERE m.stage_id = :sid
");
$cnt->execute([':sid'=>$stageId]);
if ((int)$cnt->fetch(PDO::FETCH_ASSOC)['c'] > 0) {
  respond_json(['ok'=>false,'error'=>'Нельзя импортировать команды после генерации расписания. Сначала очисти расписание этапа.'], 400);
}

// allowed categories from stage_categories
$st = $pdo->prepare("SELECT category FROM stage_categories WHERE stage_id=:sid");
$st->execute([':sid'=>$stageId]);
$allowed = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $allowed[(string)(int)$r['category']] = true;
}
if (!$allowed) {
  respond_json(['ok'=>false,'error'=>'Сначала задай "Категории этапа", потом импорт команд.'], 400);
}

// parse header blocks
$header = str_getcsv($lines[0], ';');
$blocks = [];
for ($i=0; $i<count($header); $i++) {
  $raw  = (string)($header[$i] ?? '');
  $year = nrmYear($raw);

  if ($year === '') continue;
  if (!preg_match('~^(20\d{2})$~', $year, $m)) continue;

  $blocks[] = ['col'=>$i, 'category'=>$m[1]];
}
if (!$blocks) respond_json(['ok'=>false,'error'=>'no categories in header'], 400);

// header categories must exist in stage_categories
$missing = [];
foreach ($blocks as $b) {
  if (!isset($allowed[$b['category']])) $missing[] = $b['category'];
}
$missing = array_values(array_unique($missing));
if ($missing) {
  respond_json([
    'ok'=>false,
    'error'=>'В CSV есть категории, которых нет в "Категории этапа": ' . implode(', ', $missing)
  ], 400);
}

// prepared statements
$qClub = $pdo->prepare("SELECT id FROM clubs WHERE name=:name AND city=:city LIMIT 1");
$iClub = $pdo->prepare("INSERT INTO clubs(name, city) VALUES(:name,:city)");

$qSquad = $pdo->prepare("
  SELECT id FROM squads
  WHERE tournament_id=:tournament_id AND stage_id=:stage_id AND category=:category AND name=:name
  LIMIT 1
");
$iSquad = $pdo->prepare("
  INSERT INTO squads(tournament_id, stage_id, category, category_variant, club_id, name, rating, coach_id)
  VALUES(:tournament_id,:stage_id,:category,1,:club_id,:name,3,NULL)
");
$uSquad = $pdo->prepare("
  UPDATE squads
  SET club_id=:club_id, category_variant=1, rating=3, coach_id=NULL
  WHERE id=:id
");

// очистка этапа перед импортом
$delStageSquads = $pdo->prepare("DELETE FROM squads WHERE stage_id=:stage_id");

// counters
$created = 0; $updated = 0; $skipped = 0;
$errors = [];
$maxErrors = 200;

try {
  if (!$dry) $pdo->beginTransaction();

  $cleared = 0;
  if (!$dry) {
    $delStageSquads->execute([':stage_id'=>$stageId]);
    $cleared = (int)$delStageSquads->rowCount();
  }

  for ($r=1; $r<count($lines); $r++) {
    $row = str_getcsv($lines[$r], ';');

    foreach ($blocks as $b) {
      $c = (int)$b['col'];
      $cat = (string)$b['category'];

      $name = nrm((string)($row[$c+1] ?? ''));
      $city = nrm((string)($row[$c+2] ?? ''));

      if ($name === '' && $city === '') continue;

      if ($name === '' || $city === '') {
        $skipped++;
        if (count($errors) < $maxErrors) {
          $errors[] = ['line'=>$r+1,'category'=>$cat,'error'=>'name/city required'];
        }
        continue;
      }

      // club
      $qClub->execute([':name'=>$name, ':city'=>$city]);
      $clubId = (int)($qClub->fetchColumn() ?: 0);
      if ($clubId <= 0) {
        if (!$dry) {
          $iClub->execute([':name'=>$name, ':city'=>$city]);
          $clubId = (int)$pdo->lastInsertId();
        } else {
          $clubId = -1;
        }
      }

      // squad upsert
      $qSquad->execute([
        ':tournament_id'=>$tournamentId,
        ':stage_id'=>$stageId,
        ':category'=>$cat,
        ':name'=>$name,
      ]);
      $existingId = (int)($qSquad->fetchColumn() ?: 0);

      if ($existingId > 0) {
        if (!$dry) $uSquad->execute([':club_id'=>$clubId, ':id'=>$existingId]);
        $updated++;
      } else {
        if (!$dry) {
          $iSquad->execute([
            ':tournament_id'=>$tournamentId,
            ':stage_id'=>$stageId,
            ':category'=>$cat,
            ':club_id'=>$clubId,
            ':name'=>$name,
          ]);
        }
        $created++;
      }
    }
  }

  if (!$dry) $pdo->commit();

} catch (Throwable $e) {
  if (!$dry && $pdo->inTransaction()) $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>'import_failed','details'=>$e->getMessage()], 500);
}

respond_json([
  'ok'=>true,
  'stage_id'=>$stageId,
  'tournament_id'=>$tournamentId,
  'dry'=>$dry ? true : false,
  'cleared'=> $dry ? 0 : ($cleared ?? 0),
  'created'=>$created,
  'updated'=>$updated,
  'skipped'=>$skipped,
  'errors'=>$errors,
]);
