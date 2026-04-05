<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('organizer');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  $b = $_GET;
} elseif ($method === 'POST') {
  $b = read_json_body();
} else {
  respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

function nrm(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return $s ?? '';
}

$id = (int)($b['id'] ?? 0);
$tournamentId = (int)($b['tournament_id'] ?? 0);
$stageId = (int)($b['stage_id'] ?? 0);

$category = nrm((string)($b['category'] ?? ''));
$categoryVariant = (int)($b['category_variant'] ?? 1);

// squad.name (оставляем как поле, но по умолчанию будем синхронизировать с club_name)
$squadName = nrm((string)($b['name'] ?? ''));

// рейтинг/тренер
$rating = isset($b['rating']) ? (int)$b['rating'] : 3;
$coachIdRaw = $b['coach_id'] ?? null;
$coachId = ($coachIdRaw === null || $coachIdRaw === '' ? null : (int)$coachIdRaw);

// клуб: либо club_id, либо club_name+club_city
$clubIdRaw = $b['club_id'] ?? null;
$clubId = ($clubIdRaw === null || $clubIdRaw === '' ? null : (int)$clubIdRaw);

// новые поля (предпочтительны)
$clubName = nrm((string)($b['club_name'] ?? ''));
$clubCity = nrm((string)($b['club_city'] ?? ''));

// обратная совместимость со старым форматом (name+city)
$legacyCity = nrm((string)($b['city'] ?? ''));

// validate base
if ($tournamentId <= 0) respond_json(['ok'=>false,'error'=>'tournament_id required'], 400);
if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);
if ($category === '') respond_json(['ok'=>false,'error'=>'category required'], 400);

if ($rating < 1 || $rating > 4) respond_json(['ok'=>false,'error'=>'bad rating'], 400);
if ($categoryVariant < 1 || $categoryVariant > 9) respond_json(['ok'=>false,'error'=>'bad category_variant'], 400);
if ($coachId !== null && $coachId <= 0) respond_json(['ok'=>false,'error'=>'bad coach_id'], 400);
if ($clubId !== null && $clubId <= 0) respond_json(['ok'=>false,'error'=>'bad club_id'], 400);

$pdo = db();

// validate stage exists (avoid FK 1452)
$st = $pdo->prepare("SELECT id FROM stages WHERE id=:id LIMIT 1");
$st->execute([':id'=>$stageId]);
if (!$st->fetchColumn()) {
  respond_json(['ok'=>false,'error'=>'stage not found'], 400);
}

lc_require_tournament_not_archived($pdo, $tournamentId);

try {

  // --- 1) Подготовка данных клуба ---
  // Если club_id не передали — должны быть имя+город клуба.
  // Приоритет: club_name/club_city. Если их нет — fallback на name/city (старый формат).
  if ($clubId === null) {
    if ($clubName === '') $clubName = $squadName; // fallback
    if ($clubCity === '') $clubCity = $legacyCity; // fallback

    if ($clubName === '' || $clubCity === '') {
      respond_json(['ok'=>false,'error'=>'club_id or club_name+club_city required'], 400);
    }

    // ищем клуб по (club_name, club_city)
    $st = $pdo->prepare("SELECT id FROM clubs WHERE name=:name AND city=:city LIMIT 1");
    $st->execute([':name'=>$clubName, ':city'=>$clubCity]);
    $clubId = (int)($st->fetchColumn() ?: 0);

    if ($clubId <= 0) {
      // создаём
      $st = $pdo->prepare("INSERT INTO clubs(name, city) VALUES(:name,:city)");
      $st->execute([':name'=>$clubName, ':city'=>$clubCity]);
      $clubId = (int)$pdo->lastInsertId();
    }
  } else {
    // если club_id есть, но пришли club_name/club_city — НЕ правим сам clubs (чтобы не затронуть другие записи),
    // просто игнорим их на этом шаге.
    // Если захочешь — сделаем отдельную кнопку "переименовать клуб".
  }

  // --- 2) Имя сквада ---
  // Чтобы везде было одинаково, если пришёл club_name — синхронизируем squads.name с ним.
  // Если не пришёл — оставляем как есть (и требуем name для create).
  if ($clubName !== '') {
    $squadName = $clubName;
  }

  // validate name for insert/update
  if ($id > 0) {
    // для UPDATE разрешим пустой name? нет, лучше не надо
    if ($squadName === '') respond_json(['ok'=>false,'error'=>'name required'], 400);
  } else {
    if ($squadName === '') respond_json(['ok'=>false,'error'=>'name required'], 400);
  }

  // --- 3) update or insert squad ---
  if ($id > 0) {
    $st = $pdo->prepare("
      UPDATE squads
      SET tournament_id=:tournament_id,
          stage_id=:stage_id,
          category=:category,
          category_variant=:category_variant,
          club_id=:club_id,
          name=:name,
          rating=:rating,
          coach_id=:coach_id
      WHERE id=:id
    ");
    $st->execute([
      ':tournament_id'=>$tournamentId,
      ':stage_id'=>$stageId,
      ':category'=>$category,
      ':category_variant'=>$categoryVariant,
      ':club_id'=>$clubId,
      ':name'=>$squadName,
      ':rating'=>$rating,
      ':coach_id'=>$coachId,
      ':id'=>$id,
    ]);

    respond_json(['ok'=>true,'id'=>$id,'updated'=>true,'club_id'=>$clubId]);
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
    ':category'=>$category,
    ':category_variant'=>$categoryVariant,
    ':club_id'=>$clubId,
    ':name'=>$squadName,
    ':rating'=>$rating,
    ':coach_id'=>$coachId,
  ]);

  respond_json(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'created'=>true,'club_id'=>$clubId]);

} catch (PDOException $e) {

  if ((int)($e->errorInfo[1] ?? 0) === 1062) {
    respond_json(['ok'=>false,'error'=>'duplicate'], 409);
  }

  respond_json(['ok'=>false,'error'=>'db_error','details'=>$e->getMessage()], 500);
}
