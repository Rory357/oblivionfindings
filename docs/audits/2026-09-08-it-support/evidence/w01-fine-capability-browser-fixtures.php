<?php

/** Additive local-only fixtures. Default is a read-only preflight; root reviews
 * this source before --create-fixtures. Never updates an existing actor/record. */
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItKbArticle;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const W01_FINE_PREFIX = 'it-support-w01-fine-20260909';

function w01FineRequire(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new RuntimeException($reason);
    }
}

function w01FineSnapshot(?array &$rowDigests = null): array
{
    $snapshot = [];
    foreach (['users', 'roles', 'role_user', 'role_permission', 'permission_user', 'hr_employee_profiles', 'sites', 'it_tickets', 'it_kb_articles', 'it_email_deliveries', 'site_credentials', 'site_credential_audit_logs'] as $table) {
        // Hash existing content in memory, never print bodies, passwords or secrets.
        $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $rowDigests[$table] = array_map(fn (array $row): string => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)), $rows);
        sort($rowDigests[$table]);
        $snapshot[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rowDigests[$table], JSON_THROW_ON_ERROR))];
    }

    return $snapshot;
}

try {
    $connection = config('database.connections.mysql');
    w01FineRequire(app()->environment('local')
        && strcasecmp(str_replace('\\', '/', realpath(base_path())), 'C:/Users/steph/Herd/oblivionfindings') === 0
        && parse_url(config('app.url'), PHP_URL_HOST) === 'oblivionfindings.test'
        && config('database.default') === 'mysql' && $connection['host'] === '127.0.0.1'
        && (string) $connection['port'] === '3306' && $connection['database'] === 'oblivion_findings_codex_test'
        && empty($connection['url']) && ! app()->configurationIsCached()
        && config('mail.default') === 'array' && config('mail.mailers.array.transport') === 'array'
        && config('queue.default') === 'sync' && in_array(config('broadcasting.default'), [null, 'null'], true),
        'Exact local checkout, isolated browser database or notification sink guard failed.');
    Notification::fake();
    Mail::fake();
    Bus::fake();
    Http::preventStrayRequests();
    foreach ([9403 => 'A', 9405 => 'C'] as $id => $letter) {
        $site = Site::query()->findOrFail($id);
        w01FineRequire($site->name === 'IT Support W01 20260909 Site '.$letter && $site->is_active && ! $site->archived,
            'Named synthetic Site differs.');
    }
    $newKeys = ['it.knowledge.author', 'it.knowledge.review', 'credentials.audit'];
    w01FineRequire(Permission::query()->whereIn('key', $newKeys)->count() === 3,
        'Apply reviewed permission migration 000006 before preparing fixtures.');
    w01FineRequire(Role::query()->whereHas('permissions', fn ($q) => $q->whereIn('key', $newKeys))->pluck('name')->all() === ['admin'],
        'New permissions are not currently admin-only; inspect grants before creating fixtures.');
    w01FineRequire(! User::query()->where('email', 'like', W01_FINE_PREFIX.'%')->exists()
        && ! Role::query()->where('name', 'like', W01_FINE_PREFIX.'%')->exists()
        && ! ItKbArticle::query()->where('slug', 'like', W01_FINE_PREFIX.'%')->exists()
        && ! SiteCredential::query()->where('label', 'like', W01_FINE_PREFIX.'%')->exists(),
        'Fixture set already exists or collides; do not recreate or overwrite it.');
    $beforeRows = [];
    $before = w01FineSnapshot($beforeRows);
    if (($argv[1] ?? null) !== '--create-fixtures') {
        echo json_encode(['guard_passed' => true, 'mutation_performed' => false,
            'admin_only_grants_verified' => $newKeys, 'immutable_before' => $before,
            'proposed' => ['users' => 3, 'custom_roles' => 3, 'profiles' => 3, 'articles' => 2, 'credentials' => 2, 'credential_audit_rows' => 2],
            'requires_reviewed_create_flag' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $result = DB::transaction(function () use ($before, $beforeRows): array {
        w01FineRequire(w01FineSnapshot() === $before, 'Records changed after preflight; inspect before retrying.');
        $helperSource = file_get_contents(base_path('tests/e2e/helpers.ts'));
        w01FineRequire(preg_match("/password = '([^']+)'/", $helperSource, $credentialMatch) === 1,
            'The existing repository demo credential convention could not be resolved.');
        $fixturePassword = $credentialMatch[1];
        unset($helperSource, $credentialMatch);
        $users = [];
        $definitions = [
            'author' => ['it.knowledge.author'],
            'reviewer' => ['it.knowledge.review'],
            'auditor' => ['credentials.view', 'credentials.audit', 'sites.viewAny'],
        ];
        foreach ($definitions as $key => $keys) {
            $role = Role::query()->create(['name' => W01_FINE_PREFIX.'-'.$key, 'label' => 'W01 synthetic '.$key, 'type' => 'custom', 'level' => 10]);
            $ids = Permission::query()->whereIn('key', $keys)->pluck('id');
            w01FineRequire($ids->count() === count($keys), 'Required canonical permission missing.');
            $role->permissions()->sync($ids);
            $user = User::query()->create([
                'name' => 'IT Support W01 Fine '.ucfirst($key), 'email' => W01_FINE_PREFIX.'-'.$key.'@demo.test',
                'password' => $fixturePassword, 'role' => 'support_worker',
                'approved_at' => now(), 'email_verified_at' => now(),
            ]);
            $user->roles()->sync([$role->id]);
            HrEmployeeProfile::query()->create([
                'user_id' => $user->id, 'employee_number' => 'W01-FINE-'.$user->id, 'work_email' => $user->email,
                'position_role' => 'support_worker', 'position_title' => 'Synthetic '.$key, 'employment_type' => 'full_time',
                'primary_site_id' => 9403, 'secondary_site_ids' => [], 'is_active' => true,
                'start_date' => today()->subMonth(), 'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            $user = $user->fresh()->load(['permissionOverrides', 'roles.permissions']);
            $actual = Permission::query()->orderBy('key')->pluck('key')->filter(fn (string $permission): bool => $user->canDo($permission))->values()->all();
            $expected = $keys;
            sort($expected);
            w01FineRequire($actual === $expected && ! $user->permissionOverrides()->exists(),
                'Synthetic actor has unexpected effective capabilities; rollback.');
            $users[$key] = $user;
        }
        unset($fixturePassword);
        $articles = [];
        $credentials = [];
        foreach ([9403 => 'a', 9405 => 'c'] as $siteId => $letter) {
            $article = ItKbArticle::query()->create([
                'title' => 'W01 Fine Site '.strtoupper($letter).' draft', 'slug' => W01_FINE_PREFIX.'-draft-'.$letter,
                'category' => 'other', 'body' => 'Synthetic '.($letter === 'a' ? 'approved' : 'unapproved').' audience body for separate author and reviewer.',
                'status' => 'draft', 'audience' => 'specific_sites', 'site_scope' => [$siteId],
                'author_user_id' => $users['author']->id, 'owner_user_id' => $users['author']->id,
            ]);
            $articles[$letter] = ['id' => $article->id, 'title' => $article->title, 'site_id' => $siteId];
            $credential = SiteCredential::query()->create([
                'site_id' => $siteId, 'label' => W01_FINE_PREFIX.'-credential-'.$letter, 'credential_type' => 'password',
                'encrypted_value' => Crypt::encryptString('synthetic-not-an-operational-secret'), 'requires_reauth' => false, 'is_shareable' => false,
            ]);
            SiteCredentialAuditLog::query()->create([
                'credential_id' => $credential->id, 'site_id' => $siteId, 'credential_label' => $credential->label,
                'credential_type' => 'password', 'user_id' => $users['auditor']->id, 'action' => 'create', 'created_at' => now(),
            ]);
            $credentials[$letter] = ['id' => $credential->id, 'label' => $credential->label, 'site_id' => $siteId];
        }
        $afterRows = [];
        $after = w01FineSnapshot($afterRows);
        foreach ($beforeRows as $table => $rows) {
            w01FineRequire(array_diff($rows, $afterRows[$table]) === [], 'An existing record changed; rollback.');
        }
        foreach (['sites', 'it_tickets', 'it_email_deliveries', 'permission_user'] as $table) {
            w01FineRequire($after[$table] === $before[$table], 'An unrelated table changed; rollback.');
        }

        return ['prefix' => W01_FINE_PREFIX, 'created' => true, 'notifications_sent' => false,
            'existing_records_modified' => false, 'immutable_before' => $before, 'after' => $after,
            'users' => collect($users)->map(fn (User $user, string $key) => ['id' => $user->id, 'name' => $user->name, 'permissions' => $definitions[$key], 'approved_site_ids' => [9403]])->all(),
            'articles' => $articles, 'credentials' => $credentials];
    });
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'W01 fine-capability fixtures refused: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
