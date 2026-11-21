<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

admin_require();

$dir = dirname(__DIR__) . '/data/presets';
if (!is_dir($dir)) {
    json_out(['presets' => []]);
}

/**
 * @param list<string> $visited
 * @return array<string,mixed>
 */
function preset_load_merged(string $dir, string $id, array &$visited = []): array {
    if (in_array($id, $visited, true)) {
        json_error('Preset extends cycle', 500);
    }
    $visited[] = $id;
    $path = $dir . '/' . $id . '.json';
    if (!is_file($path)) {
        json_error('Preset not found', 404);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        json_error('Could not read preset', 500);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Invalid preset file', 500);
    }
    $extends = isset($data['extends']) ? preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$data['extends'])) : '';
    unset($data['extends']);
    if ($extends !== '') {
        $parent = preset_load_merged($dir, $extends, $visited);
        $data = array_replace_recursive($parent, $data);
    }
    return $data;
}

$id = isset($_GET['id']) ? preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$_GET['id'])) : '';

if ($id !== '') {
    json_out(['preset' => preset_load_merged($dir, $id)]);
}

$files = glob($dir . '/*.json') ?: [];
$presets = [];
foreach ($files as $path) {
    $base = basename($path, '.json');
    if ($base === '' || $base[0] === '_') {
        continue;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        continue;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        continue;
    }
    $presets[] = [
        'id' => $base,
        'label' => (string)($data['label'] ?? $base),
        'preset_id' => (string)($data['preset_id'] ?? $base),
    ];
}
usort($presets, static fn($a, $b) => strcmp((string)$a['label'], (string)$b['label']));
json_out(['presets' => $presets]);