<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$b = read_json_body();
if (!is_array($b)) respond_json(['ok'=>false,'error'=>'Bad JSON'], 400);

$stageId = (int)($b['stage_id'] ?? 0);
$category = trim((string)($b['category'] ?? ''));
$categoryVariant = (int)($b['category_variant'] ?? 1);

$clubName = trim((string)($b['club_name'] ?? ($b['name'] ?? '')));
$clubCity = trim((string)($b['club_city'] ?? ($b['city'] ?? '')));

$rating = isset($b['rating']) ? (int)$b['rating'] : 3;
$coachName = trim((string)($b['coach_name'] ?? ''));

if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);

$catN = preg_replace('~[^0-9]~', '', $category);
if (!preg_match('~^(20\d{2})$~', $catN)) respond_json(['ok'=>false,'error'=>'bad category'], 400);

if ($clubName === '') respond_json(['ok'=>false,'error'=>'club_name required'], 400);
if ($clubCity === '') respond_json(['ok'=>false,'error'=>'club_city required'], 400);

if ($rating < 1 || $rating > 4) respond_json(['ok'=>false,'error'=>'bad rating'], 400);
if ($categoryVariant < 1 || $categoryVariant > 9) respond_json(['ok'=>false,'error'=>'bad category_variant'], 400);

function nrm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return $s ?? '';
}

$clubName  = nrm($clubName);
$clubCity  = nrm($clubCity);
$coachName = nrm($coachName);

$pdo = db();

$st = $pdo->prepare("SELECT id, tournament_id FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id'=>$stageId]);
$stageRow = $st->fetch(PDO::FETCH_ASSOC);
if (!$stageRow) {
  respond_json(['ok'=>false,'error'=>'stage not found'], 404);
}

$tournamentId = (int)$stageRow['tournament_id'];
if ($tournamentId <= 0) {
  respond_json(['ok'=>false,'error'=>'stage has no tournament_id'], 400);
}

lc_require_tournament_not_archived($pdo, $tournamentId);

try {
  $pdo->beginTransaction();

  // lock if schedule exists
  $cnt = $pdo->prepare("
    SELECT COUNT(*) AS c
    FROM schedule sh
    JOIN matches m ON m.id = sh.match_id
    WHERE m.stage_id = :sid
  ");
  $cnt->execute([':sid'=>$stageId]);
  if ((int)$cnt->fetch(PDO::FETCH_ASSOC)['c'] > 0) {
    $pdo->rollBack();
    respond_json(['ok'=>false,'error'=>'Нельзя добавлять команды после генерации расписания. Сначала очисти расписание этапа.'], 400);
  }

  // category must exist in stage_categories
  $st = $pdo->prepare("SELECT 1 FROM stage_categories WHERE stage_id=:sid AND category=:cat LIMIT 1");
  $st->execute([':sid'=>$stageId, ':cat'=>(int)$catN]);
  if (!$st->fetchColumn()) {
    $pdo->rollBack();
    respond_json(['ok'=>false,'error'=>"Категория {$catN} не задана в 'Категории этапа'."], 400);
  }

  // ensure club_id
  $st = $pdo->prepare("SELECT id FROM clubs WHERE name=:name AND city=:city LIMIT 1");
  $st->execute([':name'=>$clubName, ':city'=>$clubCity]);
  $clubId = (int)($st->fetchColumn() ?: 0);

  if ($clubId <= 0) {
    $st = $pdo->prepare("INSERT INTO clubs(name, city) VALUES(:name,:city)");
    $st->execute([':name'=>$clubName, ':city'=>$clubCity]);
    $clubId = (int)$pdo->lastInsertId();
  }

  // ensure coach_id by name (or null)
  $coachId = null;
  if ($coachName !== '') {
    $st = $pdo->prepare("SELECT id FROM coaches WHERE name=:name LIMIT 1");
    $st->execute([':name'=>$coachName]);
    $coachId = (int)($st->fetchColumn() ?: 0);

    if ($coachId <= 0) {
      $st = $pdo->prepare("INSERT INTO coaches(name) VALUES(:name)");
      $st->execute([':name'=>$coachName]);
      $coachId = (int)$pdo->lastInsertId();
    }
  }

  // duplicate guard
  $st = $pdo->prepare("
    SELECT id FROM squads
    WHERE tournament_id=:tournament_id AND stage_id=:stage_id AND category=:category AND name=:name
    LIMIT 1
  ");
  $st->execute([
    ':tournament_id'=>$tournamentId,
    ':stage_id'=>$stageId,
    ':category'=>$catN,
    ':name'=>$clubName,
  ]);
  $existingId = (int)($st->fetchColumn() ?: 0);

  if ($existingId > 0) {
    $pdo->commit();
    respond_json(['ok'=>true,'id'=>$existingId,'created'=>false,'club_id'=>$clubId,'coach_id'=>$coachId]);
  }

  $st = $pdo->prepare("
    INSERT INTO squads(
      tournament_id, stage_id, category, category_variant, club_id, name, rating, coach_id
    )
    VALUES(
      :tournament_id, :stage_id, :category, :category_variant, :club_id, :name, :rating, :coach_id
    )
  ");
  $st->execute([
    ':tournament_id'=>$tournamentId,
    ':stage_id'=>$stageId,
    ':category'=>$catN,
    ':category_variant'=>$categoryVariant,
    ':club_id'=>$clubId,
    ':name'=>$clubName,
    ':rating'=>$rating,
    ':coach_id'=>$coachId,
  ]);

  $newId = (int)$pdo->lastInsertId();
  $pdo->commit();

  respond_json(['ok'=>true,'id'=>$newId,'created'=>true,'club_id'=>$clubId,'coach_id'=>$coachId]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  respond_json(['ok'=>false,'error'=>'db_error','details'=>$e->getMessage()], 500);
}
