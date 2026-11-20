<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

admin_require();

$dir = dirname(__DIR__) . '/data/presets';
if (!is_dir($dir)) {
    json_out(['presets' => []]);
}

$id = isset($_GET['id']) ? preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$_GET['id'])) : '';

if ($id !== '') {
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
    json_out(['preset' => $data]);
}

$files = glob($dir . '/*.json') ?: [];
$presets = [];
foreach ($files as $path) {
    $base = basename($path, '.json');
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