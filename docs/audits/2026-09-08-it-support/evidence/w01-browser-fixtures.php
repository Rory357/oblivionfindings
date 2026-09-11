<?php

/**
 * Reviewed local-only W01 fixture installer. Run without arguments to inspect;
 * --create-fixtures is required to create the synthetic, named fixture set.
 * Existing application users/roles/sites/tickets are never edited. A repeated
 * run only validates and reports the same fixture set; it never resets work.
 * Canonical ticket reference allocation and synthetic audit rows are retained.
 */
require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItAttachmentStorageService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

const W01_PREFIX = 'IT Support W01 20260909';
const W01_ROLE_PREFIX = 'it-support-w01-20260909';

$expectedRoot = 'C:/Users/steph/Herd/oblivionfindings';
$actualRoot = str_replace('\\', '/', realpath(base_path()));
$connection = config('database.connections.mysql');
$privateRoot = str_replace('\\', '/', config('filesystems.disks.private.root'));
$safe = app()->environment('local')
    && strcasecmp($actualRoot, $expectedRoot) === 0
    && parse_url(config('app.url'), PHP_URL_HOST) === 'oblivionfindings.test'
    && config('database.default') === 'mysql'
    && $connection['host'] === '127.0.0.1'
    && (string) $connection['port'] === '3306'
    && $connection['database'] === 'oblivion_findings_codex_test'
    && empty($connection['url'])
    && ! app()->configurationIsCached()
    && config('mail.default') === 'array'
    && config('mail.mailers.array.transport') === 'array'
    && config('queue.default') === 'sync'
    && in_array(config('broadcasting.default'), [null, 'null'], true)
    && config('filesystems.disks.private.driver') === 'local'
    && strcasecmp($privateRoot, $expectedRoot.'/storage/app/private') === 0;

if (! $safe) {
    fwrite(STDERR, "Refused: local checkout/database/notification/private-storage guard failed.\n");
    exit(1);
}

$siteNames = collect(['a', 'b', 'c'])->mapWithKeys(fn (string $key): array => [$key => W01_PREFIX.' Site '.strtoupper($key)])->all();
$userLabels = ['tech' => 'Restricted technician', 'requester' => 'Requester', 'other' => 'Other requester'];
$ticketLabels = [
    'requester_own' => 'Requester own request',
    'requester_other_same_site' => 'Other requester at approved site',
    'restricted_other_site' => 'Other requester at unapproved site',
    'technician_participant_sensitive' => 'Technician participating in sensitive work',
    'technician_participant_unapproved' => 'Technician requested-for at unapproved site',
    'technician_work_allowed' => 'Permitted technician work at second site',
];

if (($argv[1] ?? null) !== '--create-fixtures') {
    echo json_encode([
        'guard_passed' => true,
        'mutation_performed' => false,
        'prefix' => W01_PREFIX,
        'proposed' => ['sites' => 3, 'custom_roles' => 2, 'users' => 3, 'staff_profiles' => 3, 'tickets' => 6, 'comments' => 12, 'private_files' => 12, 'activity_events' => 12],
        'technician_permissions' => ['it.request', 'it.view', 'it.manage'],
        'requester_permissions' => ['it.request'],
        'ticket_cases' => array_keys($ticketLabels),
        'requires_reviewed_create_flag' => true,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

// These fakes apply only to this disposable fixture process. No application
// setting or provider configuration is changed and no background job escapes.
Notification::fake();
Mail::fake();
Bus::fake();
Http::preventStrayRequests();

$lockName = W01_ROLE_PREFIX.'-fixtures';
if ((int) DB::scalar('SELECT GET_LOCK(?, 0)', [$lockName]) !== 1) {
    fwrite(STDERR, "Refused: another W01 fixture operation is active.\n");
    exit(1);
}

$storedPaths = [];
$temporaryPaths = [];
$committed = false;

try {
    $manifest = DB::transaction(function () use ($siteNames, $userLabels, $ticketLabels, &$storedPaths, &$temporaryPaths): array {
        $permissionIds = Permission::query()->whereIn('key', ['it.request', 'it.view', 'it.manage'])->pluck('id', 'key');
        if ($permissionIds->count() !== 3) {
            throw new RuntimeException('Required existing IT permissions are missing; no permissions will be seeded.');
        }

        $sites = collect($siteNames)->map(fn (string $name) => Site::withTrashed()->where('name', $name)->first());
        $users = collect($userLabels)->map(fn (string $label, string $key) => User::query()->where('email', W01_ROLE_PREFIX.'-'.$key.'@demo.test')->first());
        $roles = collect(['tech', 'requester'])->mapWithKeys(fn (string $key): array => [$key => Role::query()->where('name', W01_ROLE_PREFIX.'-'.$key)->first()]);
        $tickets = collect($ticketLabels)->map(fn (string $label) => ItTicket::query()->where('title', W01_PREFIX.' — '.$label)->first());
        $exists = $sites->filter()->isNotEmpty() || $users->filter()->isNotEmpty() || $roles->filter()->isNotEmpty() || $tickets->filter()->isNotEmpty();

        if ($exists && ($sites->filter()->count() !== 3 || $users->filter()->count() !== 3 || $roles->filter()->count() !== 2 || $tickets->filter()->count() !== 6)) {
            throw new RuntimeException('The named fixture set is incomplete or collides with existing data; refusing to overwrite or repair it.');
        }

        if (! $exists) {
            // Derive the established repository demo credential in memory.
            // Never print it, place it in evidence, or change existing users.
            $helperSource = file_get_contents(base_path('tests/e2e/helpers.ts'));
            if (preg_match("/password = '([^']+)'/", $helperSource, $credentialMatch) !== 1) {
                throw new RuntimeException('The existing demo credential convention could not be resolved.');
            }
            $fixturePassword = $credentialMatch[1];
            unset($helperSource, $credentialMatch);

            foreach ($siteNames as $key => $name) {
                $sites[$key] = Site::factory()->create([
                    'name' => $name, 'type' => 'head_office',
                    'address_line_1' => 'Synthetic browser verification site',
                    'suburb' => null, 'city' => 'Auckland', 'postcode' => null,
                    'region' => 'Auckland', 'phone' => null, 'email' => null,
                    'notes' => 'Disposable W01 IT authorization fixture. No operational assignments.',
                    'is_active' => true, 'archived' => false, 'archived_at' => null,
                ]);
            }
            foreach (['tech' => ['it.request', 'it.view', 'it.manage'], 'requester' => ['it.request']] as $key => $keys) {
                $roles[$key] = Role::query()->create([
                    'name' => W01_ROLE_PREFIX.'-'.$key,
                    'label' => W01_PREFIX.' '.ucfirst($key),
                    'level' => 10, 'type' => 'custom', 'landing_route' => '/it',
                    'description' => 'Disposable local IT browser verification role.',
                ]);
                $roles[$key]->permissions()->attach($permissionIds->only($keys)->values()->all());
            }
            foreach ($userLabels as $key => $label) {
                $users[$key] = User::factory()->withoutTwoFactor()->create([
                    'name' => W01_PREFIX.' '.$label,
                    'email' => W01_ROLE_PREFIX.'-'.$key.'@demo.test',
                    'password' => $fixturePassword, 'role' => 'support_worker',
                    'approved_at' => now(), 'email_verified_at' => now(),
                    'remember_token' => null, 'landing_route_preference' => '/it',
                ]);
                $users[$key]->roles()->attach($roles[$key === 'tech' ? 'tech' : 'requester']->id);
                HrEmployeeProfile::query()->create([
                    'user_id' => $users[$key]->id, 'employee_number' => W01_ROLE_PREFIX.'-'.$key,
                    'work_email' => $users[$key]->email, 'position_title' => 'Synthetic IT test fixture',
                    'position_role' => 'support_worker', 'employment_type' => 'casual',
                    'start_date' => today()->subDay()->toDateString(), 'end_date' => null, 'is_active' => true,
                    'primary_site_id' => $sites['a']->id,
                    'secondary_site_ids' => [$sites[$key === 'other' ? 'c' : 'b']->id],
                    'created_by' => $users[$key]->id, 'updated_by' => $users[$key]->id,
                    'notes' => 'Disposable local fixture; no real employment or capacity representation.',
                ]);
            }
            unset($fixturePassword);

            foreach ($ticketLabels as $key => $label) {
                $siteKey = match ($key) {
                    'restricted_other_site', 'technician_participant_unapproved' => 'c',
                    'technician_work_allowed' => 'b',
                    default => 'a',
                };
                $requesterKey = match ($key) {
                    'technician_participant_sensitive' => 'tech',
                    'requester_other_same_site', 'restricted_other_site', 'technician_participant_unapproved' => 'other',
                    default => 'requester',
                };
                $ticket = ItTicket::createWithReference([
                    'title' => W01_PREFIX.' — '.$label,
                    'description' => 'Synthetic W01 browser privacy fixture. No real support work or provider action is requested.',
                    'requester_user_id' => $users[$requesterKey]->id,
                    'requested_for_user_id' => $key === 'technician_participant_unapproved' ? $users['tech']->id : null,
                    'site_id' => $sites[$siteKey]->id, 'is_organisation_wide' => false,
                    'is_sensitive' => $key === 'technician_participant_sensitive',
                    'category' => 'other', 'source' => 'portal', 'work_type' => 'incident',
                    'priority' => 'normal', 'impact' => 'individual', 'urgency' => 'normal',
                    'status' => 'open', 'workflow_state' => 'submitted',
                    'assigned_to_user_id' => null, 'owner_user_id' => null, 'team_id' => null, 'queue_id' => null,
                    'first_response_due_at' => null, 'resolution_due_at' => null,
                ]);
                $tickets[$key] = $ticket;
                foreach (['public' => false, 'internal' => true] as $audience => $internal) {
                    $comment = $ticket->comments()->create([
                        'author_user_id' => $users['tech']->id,
                        'body' => $internal ? 'W01 synthetic internal diagnostic marker; restricted audience.' : 'W01 synthetic public update available to the request participant.',
                        'is_internal' => $internal,
                    ]);
                    $tempPath = tempnam(sys_get_temp_dir(), 'it-w01-');
                    if (! is_string($tempPath)) {
                        throw new RuntimeException('Could not allocate synthetic upload.');
                    }
                    $temporaryPaths[] = $tempPath;
                    file_put_contents($tempPath, $internal ? "Synthetic W01 internal attachment.\n" : "Synthetic W01 public attachment.\n");
                    app(ItAttachmentStorageService::class)->store($comment, [
                        new UploadedFile($tempPath, 'w01-'.$audience.'-fixture.txt', 'text/plain', null, true),
                    ], $users['tech'], $storedPaths);
                }
                ItTicketEvent::record($ticket, 'workflow_transitioned', $users['tech']->id, [
                    'from' => 'submitted', 'to' => 'submitted', 'reason' => 'W01 synthetic private transition explanation.',
                ]);
                ItTicketEvent::record($ticket, 'priority_changed', $users['tech']->id, [
                    'from' => 'normal', 'to' => 'normal', 'reason' => 'W01 synthetic private priority explanation.',
                ]);
            }
            AuditLogger::logOrFail('it.test_fixtures_created', null, [
                'fixture_prefix' => W01_PREFIX, 'user_ids' => $users->pluck('id')->all(),
                'site_ids' => $sites->pluck('id')->all(), 'ticket_ids' => $tickets->pluck('id')->all(),
            ]);
        }

        foreach (['tech' => ['it.manage', 'it.request', 'it.view'], 'requester' => ['it.request']] as $key => $expectedKeys) {
            if ($roles[$key]->permissions()->orderBy('key')->pluck('key')->all() !== $expectedKeys) {
                throw new RuntimeException('Existing fixture permissions differ; refusing to overwrite them.');
            }
        }
        $access = app(ItWorkAccessService::class);
        foreach ($users as $key => $user) {
            $roleId = $roles[$key === 'tech' ? 'tech' : 'requester']->id;
            if ($user->name !== W01_PREFIX.' '.$userLabels[$key] || $user->roles()->pluck('roles.id')->all() !== [$roleId] || $user->permissionOverrides()->exists()) {
                throw new RuntimeException('Existing fixture identity or access differs; refusing to overwrite it.');
            }
        }
        foreach ($tickets as $ticket) {
            if ($ticket->comments()->count() < 2 || $ticket->events()->count() < 2 || $ticket->site_id < 1) {
                throw new RuntimeException('Existing fixture evidence is incomplete; refusing to reset it.');
            }
        }

        return [
            'prefix' => W01_PREFIX, 'created' => ! $exists, 'existing_records_modified' => false,
            'reference_allocator_used' => ! $exists, 'notifications_sent' => false,
            'sites' => $sites->map(fn (Site $site): array => ['id' => $site->id, 'name' => $site->name])->all(),
            'users' => $users->map(fn (User $user): array => [
                'id' => $user->id, 'name' => $user->name,
                'approved_site_ids' => $access->approvedSiteIds($user),
                'can_request' => $user->canDo('it.request'), 'can_view' => $user->canDo('it.view'), 'can_manage' => $user->canDo('it.manage'),
                'can_sensitive' => $user->canDo('it.viewSensitive'), 'can_organisation_wide' => $user->canDo('it.organisationWide'),
            ])->all(),
            'tickets' => $tickets->map(fn (ItTicket $ticket): array => [
                'id' => $ticket->id, 'reference' => $ticket->reference, 'title' => $ticket->title,
                'site_id' => $ticket->site_id, 'requester_id' => $ticket->requester_user_id, 'requested_for_id' => $ticket->requested_for_user_id,
                'technician_can_view' => $access->canView($users['tech'], $ticket),
                'technician_can_work' => $access->canWork($users['tech'], $ticket),
                'requester_can_view' => $access->canView($users['requester'], $ticket),
                'comments' => $ticket->comments()->get()->map(fn ($comment): array => [
                    'id' => $comment->id, 'internal' => $comment->is_internal,
                    'attachments' => $comment->attachments()->get()->map(fn (ItAttachment $file): array => [
                        'id' => $file->id, 'download_path' => '/it/attachments/'.$file->id,
                        'stored' => Storage::disk(ItAttachment::DISK)->exists($file->path),
                    ])->all(),
                ])->all(),
            ])->all(),
        ];
    });
    $committed = true;
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    if (! $committed) {
        app(ItAttachmentStorageService::class)->deleteStored($storedPaths);
    }
    fwrite(STDERR, 'W01 fixture operation failed ('.get_class($exception)."). Database transaction rolled back; no private values emitted.\n");
    exit(1);
} finally {
    foreach ($temporaryPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
}
