<?php

/** Correct only the three synthetic fixtures' omitted verification timestamp. */
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local')
    || strcasecmp(str_replace('\\', '/', realpath(base_path())), 'C:/Users/steph/Herd/oblivionfindings') !== 0
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'oblivion_findings_codex_test'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('mail.default') !== 'array' || config('queue.default') !== 'sync'
    || app()->configurationIsCached()) {
    throw new RuntimeException('Expected isolated local browser runtime is not active.');
}
Notification::fake(); Mail::fake(); Bus::fake(); Http::preventStrayRequests();
$definitions = [234 => 'author', 235 => 'reviewer', 236 => 'auditor'];
$apply = ($argv[1] ?? null) === '--repair-fixtures';
$result = DB::transaction(function () use ($definitions, $apply): array {
    $results = [];
    foreach ($definitions as $id => $role) {
        $user = User::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($user->email !== 'it-support-w01-fine-20260909-'.$role.'@demo.test'
            || $user->name !== 'IT Support W01 Fine '.ucfirst($role)
            || ! $user->approved_at) {
            throw new RuntimeException('Exact synthetic actor guard failed.');
        }
        $before = $user->getRawOriginal();
        $wasVerified = $user->hasVerifiedEmail();
        if ($apply && ! $wasVerified) {
            // User::create silently discards this non-fillable field. Keep mass-assignment protection.
            $user->forceFill(['email_verified_at' => now()])->saveQuietly();
        }
        $after = $user->fresh()->getRawOriginal();
        foreach (['email_verified_at', 'updated_at'] as $field) {
            unset($before[$field], $after[$field]);
        }
        if ($before !== $after) {
            throw new RuntimeException('Another user field changed; rollback.');
        }
        $results[] = ['id' => $id, 'fixture' => $role, 'verified_before' => $wasVerified,
            'verified_after' => $user->fresh()->hasVerifiedEmail(), 'other_fields_unchanged' => true];
    }
    return ['read_at' => now()->toIso8601String(), 'applied' => $apply,
        'real_communications' => false, 'synthetic_users' => $results];
});
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
