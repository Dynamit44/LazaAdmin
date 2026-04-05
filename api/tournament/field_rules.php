<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_role('organizer');

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** нормализация токенов */
function norm_token(string $s): string {
  $s = trim(mb_strtolower($s));
  $s = str_replace([" ", "\t", "\n", "\r"], '', $s);
  return $s;
}

/** распознаём то, что вводят в админке */
function field_to_code(string $token): string {
  $t = norm_token($token);

  if (in_array($t, ['полноеполе','поле','full','all','1234'], true)) return 'full';
  if (in_array($t, ['1','2','3','4','12','34'], true)) return $t;

  if ($t === '1,2' || $t === '2,1') return '12';
  if ($t === '3,4' || $t === '4,3') return '34';

  throw new RuntimeException("Unknown field token: {$token}");
}

/** маска занятости четвертей (1..4). full = все четверти */
function code_to_mask(string $code): int {
  return match($code) {
    '1' => 1,
    '2' => 2,
    '3' => 4,
    '4' => 8,
    '12' => 3,
    '34' => 12,
    'full' => 15,
    default => throw new RuntimeException("Unknown field_code: {$code}")
  };
}

function stage_exists(PDO $pdo, int $stageId): bool {
  $st = $pdo->prepare("SELECT id FROM stages WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$stageId]);
  return (bool)$st->fetchColumn();
}

function stage_has_schedule(PDO $pdo, int $stageId): bool {
  $cnt = $pdo->prepare("
    SELECT COUNT(*) AS c
    FROM schedule sh
    JOIN matches m ON m.id=sh.match_id
    WHERE m.stage_id=:sid
  ");
  $cnt->execute([':sid'=>$stageId]);
  return ((int)($cnt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0)) > 0;
}

function allowed_categories(PDO $pdo, int $stageId): array {
  $st = $pdo->prepare("SELECT category FROM stage_categories WHERE stage_id=:sid");
  $st->execute([':sid'=>$stageId]);
  $set = [];
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $set[(string)$c] = true;
  return $set;
}

if ($method === 'GET') {
  $stageId = (int)($_GET['stage_id'] ?? 0);
  if ($stageId <= 0) respond_json(['ok'=>false,'error'=>'stage_id required'], 400);
  if (!stage_exists($pdo, $stageId)) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

  $ln = $pdo->prepare("
    SELECT line_no, categories_csv AS categories, fields_csv AS fields
    FROM stage_field_rules_lines
    WHERE stage_id=:sid
    ORDER BY line_no
  ");
  $ln->execute([':sid'=>$stageId]);

  $rules = $pdo->prepare("
    SELECT category, variant, field_code
    FROM stage_category_fields
    WHERE stage_id=:sid
    ORDER BY category, variant, field_code
  ");
  $rules->execute([':sid'=>$stageId]);

  respond_json([
    'ok'=>true,
    'lines'=>$ln->fetchAll(PDO::FETCH_ASSOC),
    'rules'=>$rules->fetchAll(PDO::FETCH_ASSOC),
  ]);
}

if ($method === 'POST') {
  $b = read_json_body();
  $stageId = (int)($b['stage_id'] ?? 0);
  $lines = $b['lines'] ?? null;

  if ($stageId <= 0 || !is_array($lines)) {
    respond_json(['ok'=>false,'error'=>'stage_id and lines[] required'], 400);
  }
  if (!stage_exists($pdo, $stageId)) respond_json(['ok'=>false,'error'=>'stage not found'], 404);

  lc_require_tournament_not_archived($pdo, lc_tournament_id_for_stage($pdo, $stageId));

  if (stage_has_schedule($pdo, $stageId)) {
    respond_json(['ok'=>false,'error'=>'Нельзя менять правила после генерации расписания. Сначала очисти расписание этапа.'], 400);
  }

  $allowed = allowed_categories($pdo, $stageId);
  if (!$allowed) {
    respond_json(['ok'=>false,'error'=>'Сначала задай категории этапа (stage_categories), потом настраивай поля.'], 400);
  }

  $pdo->beginTransaction();
  try {
    $pdo->prepare("DELETE FROM stage_field_rules_lines WHERE stage_id=:sid")->execute([':sid'=>$stageId]);
    $pdo->prepare("DELETE FROM stage_category_fields WHERE stage_id=:sid")->execute([':sid'=>$stageId]);
    $pdo->prepare("DELETE FROM stage_fields WHERE stage_id=:sid")->execute([':sid'=>$stageId]);

    $variantMap = []; // category => current_variant
    $usedCodes  = []; // field_code => true
    $expanded   = [];

    $insLine = $pdo->prepare("
      INSERT INTO stage_field_rules_lines(stage_id, line_no, categories_csv, fields_csv)
      VALUES(:sid,:no,:c,:f)
    ");

    $lineNo = 1;
    foreach ($lines as $row) {
      $catsRaw = trim((string)($row['categories'] ?? ''));
      $fieldsRaw = trim((string)($row['fields'] ?? ''));

      if ($catsRaw === '' || $fieldsRaw === '') continue;

      $insLine->execute([
        ':sid'=>$stageId,
        ':no'=>$lineNo++,
        ':c'=>$catsRaw,
        ':f'=>$fieldsRaw
      ]);

      $cats = preg_split('~[,\s;]+~u', $catsRaw, -1, PREG_SPLIT_NO_EMPTY);
      $fields = preg_split('~[,\s;]+~u', $fieldsRaw, -1, PREG_SPLIT_NO_EMPTY);
      if (!$cats || !$fields) continue;

      $fieldCodes = [];
      foreach ($fields as $f) {
        $code = field_to_code($f);
        $fieldCodes[] = $code;
        $usedCodes[(string)$code] = true;
      }
      $fieldCodes = array_values(array_unique($fieldCodes));

      foreach ($cats as $catRaw) {
        $catRaw = trim($catRaw);
        if ($catRaw === '') continue;

        if (!preg_match('~^(20\d{2})$~', $catRaw)) {
          throw new RuntimeException("Bad category token: {$catRaw}");
        }
        if (!isset($allowed[$catRaw])) {
          throw new RuntimeException("Категория {$catRaw} не задана в 'Категории этапа'. Сначала добавь её там.");
        }

        $variantMap[$catRaw] = ($variantMap[$catRaw] ?? 0) + 1;
        $variant = $variantMap[$catRaw];

        foreach ($fieldCodes as $code) {
          $expanded[] = ['category'=>$catRaw, 'variant'=>$variant, 'field_code'=>$code];
        }
      }
    }

    if (!$expanded) {
      throw new RuntimeException('Пустые правила: добавь хотя бы одну строку с категориями и полями.');
    }

    $insF = $pdo->prepare("
      INSERT INTO stage_fields(stage_id, field_code, units_mask)
      VALUES(:sid,:fc,:mask)
    ");
    foreach (array_keys($usedCodes) as $code) {
      $code = (string)$code;
      $insF->execute([':sid'=>$stageId, ':fc'=>$code, ':mask'=>code_to_mask($code)]);
    }

    $insR = $pdo->prepare("
      INSERT INTO stage_category_fields(stage_id, category, variant, field_code)
      VALUES(:sid,:cat,:var,:fc)
    ");
    foreach ($expanded as $r) {
      $insR->execute([':sid'=>$stageId, ':cat'=>$r['category'], ':var'=>$r['variant'], ':fc'=>$r['field_code']]);
    }

    $pdo->commit();
    respond_json(['ok'=>true,'message'=>'Rules saved','lines_saved'=>$lineNo-1,'rules_saved'=>count($expanded)]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond_json(['ok'=>false,'error'=>$e->getMessage()], 400);
  }
}

respond_json(['ok'=>false,'error'=>'Method not allowed'], 405);
