<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'oblivion_findings_ops_pl01_preview') {
    throw new RuntimeException('Synthetic preview database required');
}
$user = \App\Models\User::where('email', 'ops-pl01-preview@example.test')->firstOrFail();
$backup = storage_path('app/ops-pl01-brand-backup.json');
$mode = $argv[1] ?? 'default-dark';
if (!is_file($backup)) {
    file_put_contents($backup, json_encode(['user' => $user->only(['theme', 'accent_colour']),
        'settings' => \App\Models\AppSetting::whereIn('key', ['theme.light', 'theme.dark'])->get()->mapWithKeys(fn($s) => [$s->key => $s->value])->all()], JSON_PRETTY_PRINT));
}
$original = json_decode(file_get_contents($backup), true);
foreach (['theme.light', 'theme.dark'] as $key) {
    if (array_key_exists($key, $original['settings'])) {
        \App\Models\AppSetting::updateOrCreate(['key' => $key], ['value' => $original['settings'][$key]]);
    } else {
        \App\Models\AppSetting::where('key', $key)->delete();
    }
}
if ($mode === 'restore') {
    $user->forceFill($original['user'])->save();
    echo "Restored synthetic preview branding and reviewer preferences\n";
    exit;
}
[$brand, $theme] = explode('-', $mode);
if (!in_array($brand, ['default', 'alternate'], true) || !in_array($theme, ['light', 'dark'], true)) throw new RuntimeException('Unknown case');
$user->forceFill(['theme' => $theme, 'accent_colour' => null])->save();
if ($brand === 'alternate') {
    foreach (['light', 'dark'] as $variant) {
        $values = $original['settings']['theme.'.$variant] ?? [];
        $values['--primary'] = $variant === 'light' ? 'oklch(0.5 0.12 190)' : 'oklch(0.72 0.12 190)';
        \App\Models\AppSetting::updateOrCreate(['key' => 'theme.'.$variant], ['value' => $values]);
    }
}
echo "Synthetic branding case: $mode\n";
