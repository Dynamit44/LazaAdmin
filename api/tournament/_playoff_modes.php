<?php
// /api/tournament/_playoff_modes.php
// Единый справочник режимов плей-офф (source of truth)
// Добавление/переименование режимов делаем ТОЛЬКО здесь.

declare(strict_types=1);

function playoff_modes_all(): array {
  return [
    'none' => [
      'title' => 'Нет плей-офф',
      'min_groups' => 0,
      'max_groups' => 99,
      'implemented' => true,
      'types' => ['none'],
    ],

    // ✅ Canon Festival
    'canon_festival' => [
      'title' => 'Canon: фестивальный плей-офф (все места 1…N)',
      'min_groups' => 1,
      'max_groups' => 3,
      'implemented' => true,
      'types' => ['festival'],
    ],

    // 1 группа
    'top4_semis_finals' => [
      'title' => 'Топ-4: 1/2 + финалы (за 1/2 и 3/4)',
      'min_groups' => 1,
      'max_groups' => 1,
      'implemented' => true,
      'types' => ['semis_finals', 'quarter_semis_finals'],
    ],
    'blocks4_all_places_1g' => [
      'title' => '1 группа: блоки по 4 (все играют за места)',
      'min_groups' => 1,
      'max_groups' => 1,
      'implemented' => true,
      'types' => ['place_matches', 'festival'],
    ],
    'top2_final_plus_3rd_1g' => [
      'title' => '1 группа (5 команд): 1–2 финал, 3–4 матч за 3 место',
      'min_groups' => 1,
      'max_groups' => 1,
      'implemented' => true,
      'types' => ['semis_finals'],
    ],

    // 2 группы
    'ties_all_places_2g' => [
      'title' => '2 группы: стыки 1–1, 2–2, 3–3…',
      'min_groups' => 2,
      'max_groups' => 2,
      'implemented' => true,
      'types' => ['place_matches'],
    ],
    'blocks4_all_places_2g' => [
      'title' => '2 группы: блоки по 4 (A1–B2 / B1–A2 и т.д.)',
      'min_groups' => 2,
      'max_groups' => 2,
      'implemented' => true,
      'types' => ['semis_finals', 'place_matches', 'festival'],
    ],

    // 3 группы
    'blocks4_all_places_3g' => [
      'title' => '3 группы: блоки по 4 (защита победителей + топ-8 для вторых)',
      'min_groups' => 3,
      'max_groups' => 3,
      'implemented' => true,
      'types' => ['semis_finals', 'place_matches', 'festival'],
    ],

    // служебный/нестандартный
    'manual' => [
      'title' => 'Вручную (нестандартный кейс)',
      'min_groups' => 0,
      'max_groups' => 99,
      'implemented' => false,
      'types' => ['semis_finals', 'quarter_semis_finals', 'place_matches', 'festival', 'manual'],
    ],
  ];
}

function playoff_mode_exists(string $mode): bool {
  $all = playoff_modes_all();
  return isset($all[$mode]);
}

function playoff_type_normalize(string $type): string {
  $type = trim($type);
  if ($type === '') return 'semis_finals';
  $known = ['none','semis_finals','quarter_semis_finals','place_matches','festival','manual'];
  return in_array($type, $known, true) ? $type : 'semis_finals';
}

function playoff_mode_allowed_for_groups(string $mode, int $groupsCount): bool {
  $all = playoff_modes_all();
  if (!isset($all[$mode])) return false;
  $m = $all[$mode];
  $min = (int)($m['min_groups'] ?? 0);
  $max = (int)($m['max_groups'] ?? 99);
  return ($groupsCount >= $min && $groupsCount <= $max);
}

function playoff_mode_allowed_for_type(string $mode, string $type): bool {
  $all = playoff_modes_all();
  if (!isset($all[$mode])) return false;
  $types = $all[$mode]['types'] ?? [];
  if (!is_array($types) || !$types) return true;
  return in_array(playoff_type_normalize($type), $types, true);
}

function playoff_modes_for_groups(int $groupsCount): array {
  $all = playoff_modes_all();
  $out = [];
  foreach ($all as $id => $m) {
    $min = (int)($m['min_groups'] ?? 0);
    $max = (int)($m['max_groups'] ?? 99);
    if ($groupsCount >= $min && $groupsCount <= $max) {
      $out[$id] = $m;
    }
  }
  return $out;
}

function playoff_modes_for_type_groups(string $type, int $groupsCount): array {
  $type = playoff_type_normalize($type);
  $out = [];
  foreach (playoff_modes_for_groups($groupsCount) as $id => $m) {
    if (playoff_mode_allowed_for_type((string)$id, $type)) {
      $out[$id] = $m;
    }
  }
  return $out;
}

function playoff_mode_items_for_groups(int $groupsCount): array {
  $modes = playoff_modes_for_groups($groupsCount);
  $items = [];
  foreach ($modes as $id => $m) {
    $items[] = [
      'id' => (string)$id,
      'title' => (string)($m['title'] ?? $id),
      'implemented' => (bool)($m['implemented'] ?? false),
      'types' => array_values(array_map('strval', (array)($m['types'] ?? []))),
    ];
  }
  return $items;
}

function playoff_mode_items_for_type_groups(string $type, int $groupsCount): array {
  $modes = playoff_modes_for_type_groups($type, $groupsCount);
  $items = [];
  foreach ($modes as $id => $m) {
    $items[] = [
      'id' => (string)$id,
      'title' => (string)($m['title'] ?? $id),
      'implemented' => (bool)($m['implemented'] ?? false),
      'types' => array_values(array_map('strval', (array)($m['types'] ?? []))),
    ];
  }
  return $items;
}
