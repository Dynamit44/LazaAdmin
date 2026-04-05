<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_role('operator','organizer');

try {
  $pdo = db();

  $stageId  = (int)($_GET['stage_id'] ?? 0);
  $category = trim((string)($_GET['category'] ?? ''));
  $variant  = (int)($_GET['variant'] ?? 0);
  $dayNo    = (int)($_GET['day_no'] ?? 0);

  $where = [];
  $params = [];

  if ($stageId > 0) { $where[] = "stage_id=:sid"; $params[':sid'] = $stageId; }
  if ($category !== '') { $where[] = "category=:cat"; $params[':cat'] = $category; }
  if ($variant > 0) { $where[] = "variant=:v"; $params[':v'] = $variant; }
  if ($dayNo > 0) { $where[] = "day_no=:d"; $params[':d'] = $dayNo; }

  $sql = "SELECT id, created_at, kind, sid, files_count,
                 stage_id, category, variant, day_no, day_date,
                 vk_owner_id, vk_post_id, vk_post_url,
                 status, error_text
          FROM publish_log";

  if ($where) $sql .= " WHERE " . implode(" AND ", $where);
  $sql .= " ORDER BY id DESC LIMIT 50";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  respond_json(['ok'=>true, 'items'=>$items], 200);
} catch (Throwable $e) {
  respond_json(['ok'=>false, 'error'=>$e->getMessage()], 500);
}
