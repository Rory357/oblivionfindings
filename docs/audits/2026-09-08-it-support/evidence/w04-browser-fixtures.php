<?php

/**
 * Review before --create-fixtures. Default is a read-only preview.
 * Adds seven synthetic tickets only, with their own explicit 24/7 policy
 * snapshots. Never edits existing users, roles, sites, policies or tickets.
 * A repeated run reports the existing complete set; it never resets fixtures.
 */
use App\Domain\It\Services\ItAutomationScheduleCatalog;
use App\Domain\It\Services\ItSlaClockService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItSlaPolicy;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const W04_PREFIX = 'ITSupportW04 20260909';

$expectedRoot = 'C:/Users/steph/Herd/oblivionfindings';
$connection = config('database.connections.mysql');
$safe = app()->environment('local')
    && strcasecmp(str_replace('\\', '/', realpath(base_path())), $expectedRoot) === 0
    && parse_url(config('app.url'), PHP_URL_HOST) === 'oblivionfindings.test'
    && config('database.default') === 'mysql'
    && $connection['host'] === '127.0.0.1' && (string) $connection['port'] === '3306'
    && $connection['database'] === 'oblivion_findings_codex_test'
    && empty($connection['url']) && ! app()->configurationIsCached()
    && config('mail.default') === 'array' && config('mail.mailers.array.transport') === 'array'
    && config('queue.default') === 'sync'
    && in_array(config('broadcasting.default'), [null, 'null'], true)
    && config('filesystems.disks.private.driver') === 'local'
    && strcasecmp(str_replace('\\', '/', config('filesystems.disks.private.root')), $expectedRoot.'/storage/app/private') === 0;
if (! $safe) {
    fwrite(STDERR, "Refused: local checkout/database/notification/private-storage guard failed.\n");
    exit(1);
}

$tech = User::query()->findOrFail(230);
$requester = User::query()->findOrFail(231);
$sites = Site::query()->whereIn('id', [9403, 9404])->get()->keyBy('id');
$access = app(ItWorkAccessService::class);
if ($tech->name !== 'IT Support W01 20260909 Restricted technician'
    || $requester->name !== 'IT Support W01 20260909 Requester'
    || $tech->approved_at === null || $requester->approved_at === null
    || ! $tech->canDo('it.manage') || ! $requester->canDo('it.request')
    || $tech->canDo('it.organisationWide') || $tech->canDo('it.viewSensitive')
    || $sites->get(9403)?->name !== 'IT Support W01 20260909 Site A'
    || $sites->get(9404)?->name !== 'IT Support W01 20260909 Site B'
    || array_diff([9403, 9404], $access->approvedSiteIds($tech)) !== []
    || array_diff([9403, 9404], $access->approvedSiteIds($requester)) !== []) {
    throw new RuntimeException('The existing approved W01 actor/site fixture identities do not match.');
}

$cases = [
    'ok' => ['label' => 'On track', 'site_id' => 9403, 'expected_state' => 'ok', 'coverage' => 'full'],
    'at_risk' => ['label' => 'Response at risk', 'site_id' => 9403, 'expected_state' => 'at_risk', 'coverage' => 'full'],
    'breached' => ['label' => 'Late response retained', 'site_id' => 9403, 'expected_state' => 'breached', 'coverage' => 'full'],
    'met' => ['label' => 'Resolved within both targets', 'site_id' => 9404, 'expected_state' => 'met', 'coverage' => 'full'],
    'paused' => ['label' => 'Waiting for requester', 'site_id' => 9404, 'expected_state' => 'paused', 'coverage' => 'full'],
    'unmeasured' => ['label' => 'No historical clocks recorded', 'site_id' => 9404, 'expected_state' => 'unmeasured', 'coverage' => 'none'],
    'partial' => ['label' => 'Response clock not recorded', 'site_id' => 9404, 'expected_state' => 'unmeasured', 'coverage' => 'partial'],
];

if (($argv[1] ?? null) !== '--create-fixtures') {
    DB::statement('SET TRANSACTION READ ONLY');
    DB::beginTransaction();
    try {
        $at = CarbonImmutable::now();
        $clocks = app(ItSlaClockService::class);
        $existing = ItTicket::query()->where('title', 'like', W04_PREFIX.'%')->get();
        $scopedOpen = $access->applyViewScope(ItTicket::query(), $tech)->whereIn('status', ItTicket::OPEN_STATUSES)->get();
        $scopedResolved = $access->applyViewScope(ItTicket::query(), $tech)->whereIn('status', ['resolved', 'closed'])
            ->where('resolved_at', '>=', $at->subDays(30))->get();
        $actual = [
            'existing_fixture_count' => $existing->count(),
            'fixture_aggregate' => $clocks->summarize($existing, $at),
            'technician_open_aggregate' => $clocks->summarize($scopedOpen, $at),
            'technician_resolved_30d_aggregate' => $clocks->summarize($scopedResolved, $at),
            'fixture_records' => $existing->map(fn (ItTicket $ticket) => [
                'id' => $ticket->id, 'reference' => $ticket->reference,
                'site_id' => $ticket->site_id, 'state' => $clocks->verdict($ticket, $at)['state'],
            ])->all(),
        ];
    } finally {
        DB::rollBack();
    }
    echo json_encode([
        'guard_passed' => true, 'mutation_performed' => false, 'prefix' => W04_PREFIX,
        'current_readonly_evidence' => $actual,
        'actors' => ['technician_id' => $tech->id, 'requester_id' => $requester->id],
        'site_ids' => [9403, 9404], 'proposed_tickets' => $cases,
        'policy_scope' => 'Only new synthetic ticket snapshots; application grid untouched.',
        'clock_minutes' => ['first_response' => 600, 'resolution' => 2400],
        'calendar' => 'Explicit continuous 24/7 in Pacific/Auckland; no holiday grid edit.',
        'expected_initial_by_state' => ['ok' => 1, 'at_risk' => 1, 'breached' => 1, 'met' => 1, 'paused' => 1, 'unmeasured' => 2],
        'expected_initial_by_coverage' => ['none' => 1, 'partial' => 1, 'full' => 5],
        'at_risk_remaining_minutes_on_creation' => 120,
        'requires_reviewed_create_flag' => true,
        'watchdog_freshness' => app(ItAutomationScheduleCatalog::class)->freshnessFor('it.check-sla'),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

Notification::fake();
Mail::fake();
Bus::fake();
Http::preventStrayRequests();
$lock = 'it-support-w04-20260909-fixtures';
if ((int) DB::scalar('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
    throw new RuntimeException('Another W04 fixture operation is active.');
}

try {
    $manifest = DB::transaction(function () use ($cases, $tech, $requester, $access): array {
        $protectedRows = fn () => [
            'ticket_hash' => hash('sha256', ItTicket::query()->where('title', 'not like', W04_PREFIX.'%')->orderBy('id')->get()->toJson()),
            'policies_hash' => hash('sha256', ItSlaPolicy::query()->orderBy('id')->get()->toJson()),
            'actor_access_hash' => hash('sha256', json_encode(User::query()->whereIn('id', [230, 231])
                ->with(['roles.permissions'])->get(['id', 'name', 'role', 'approved_at'])->toArray(), JSON_THROW_ON_ERROR)),
            'sites_hash' => hash('sha256', Site::query()->whereIn('id', [9403, 9404])->orderBy('id')->get()->toJson()),
            'nonfixture_ticket_count' => ItTicket::query()->where('title', 'not like', W04_PREFIX.'%')->count(),
        ];
        $before = $protectedRows();
        $tickets = ItTicket::query()->where('title', 'like', W04_PREFIX.'%')->get()->keyBy('title');
        $titles = collect($cases)->map(fn (array $case) => W04_PREFIX.' — '.$case['label']);
        if ($tickets->isNotEmpty() && ($tickets->count() !== count($cases) || $titles->diff($tickets->keys())->isNotEmpty())) {
            throw new RuntimeException('Incomplete or colliding W04 fixture set; refusing to overwrite it.');
        }
        $created = $tickets->isEmpty();
        $at = CarbonImmutable::now()->startOfSecond();
        $clocks = app(ItSlaClockService::class);
        if ($created) {
            foreach ($cases as $key => $case) {
                $anchor = match ($key) {
                    'at_risk' => $at->subMinutes(480),
                    'breached' => $at->subMinutes(720),
                    'met', 'paused' => $at->subMinutes(120),
                    default => $at,
                };
                $policy = (new ItSlaPolicy)->forceFill(['first_response_minutes' => 600, 'resolution_minutes' => 2400]);
                $snapshot = [...$clocks->policySnapshot('normal', $policy, $anchor), 'source' => 'synthetic_fixture'];
                $hasResponse = in_array($key, ['breached', 'met', 'paused'], true);
                $ticket = ItTicket::createWithReference([
                    'title' => $titles[$key],
                    'description' => 'Synthetic W04 desktop browser verification. This is not an operational support request.',
                    'requester_user_id' => $requester->id, 'site_id' => $case['site_id'],
                    'assigned_to_user_id' => $tech->id, 'owner_user_id' => $tech->id,
                    'category' => 'other', 'source' => 'portal', 'work_type' => 'incident',
                    'priority' => 'normal', 'impact' => 'individual', 'urgency' => 'normal',
                    'status' => $key === 'met' ? 'resolved' : ($key === 'paused' ? 'waiting' : 'open'),
                    'workflow_state' => $key === 'met' ? 'resolved' : ($key === 'paused' ? 'waiting' : 'submitted'),
                    'is_sensitive' => false, 'is_organisation_wide' => false,
                    'resolution_code' => $key === 'met' ? 'restored' : null,
                    'resolution_summary' => $key === 'met' ? 'Synthetic fixture outcome; no real repair was performed.' : null,
                    'waiting_party' => $key === 'paused' ? 'requester' : null,
                    'waiting_reason' => $key === 'paused' ? 'Synthetic requester-wait evidence.' : null,
                    'next_action' => 'Use only for W04 browser verification.',
                ]);
                $ticket->forceFill([
                    'created_at' => $anchor->utc(),
                    'sla_original_policy_snapshot' => $key === 'unmeasured' ? null : $snapshot,
                    'sla_policy_snapshot' => $key === 'unmeasured' ? null : $snapshot,
                    'first_response_due_at' => in_array($key, ['unmeasured', 'partial'], true) ? null : $anchor->addMinutes(600)->utc(),
                    'resolution_due_at' => $key === 'unmeasured' ? null : $anchor->addMinutes(2400)->utc(),
                    'first_responded_at' => $hasResponse ? $anchor->addMinutes($key === 'breached' ? 610 : 30)->utc() : null,
                    'resolved_at' => $key === 'met' ? $anchor->addMinutes(60)->utc() : null,
                    'waiting_since' => $key === 'paused' ? $at->subMinutes(60)->utc() : null,
                    'sla_paused_minutes' => 0, 'reopened_count' => 0,
                ]);
                $verdict = $clocks->synchronize($ticket, $at);
                if ($verdict['state'] !== $case['expected_state'] || $verdict['coverage'] !== $case['coverage']) {
                    throw new RuntimeException('The synthetic SLA fixture does not match its declared state.');
                }
                $ticket->save();
                if ($hasResponse) {
                    $comment = $ticket->comments()->make([
                        'author_user_id' => $tech->id, 'is_internal' => false,
                        'body' => 'Synthetic public response for SLA verification; no real communication occurred.',
                    ]);
                    $comment->forceFill(['created_at' => $ticket->first_responded_at])->save();
                }
                ItTicketEvent::record($ticket, 'verification_fixture_created', null, [
                    'fixture_prefix' => W04_PREFIX, 'case' => $key,
                    'expected_initial_state' => $case['expected_state'], 'expected_initial_coverage' => $case['coverage'],
                ]);
                AuditLogger::logOrFail('it.verification.fixture.created', $ticket, ['fixture_prefix' => W04_PREFIX, 'case' => $key]);
                $tickets[$titles[$key]] = $ticket->refresh();
            }
        }
        $after = $protectedRows();
        if ($before !== $after) {
            throw new RuntimeException('Protected existing records changed; fixture transaction will roll back.');
        }

        return [
            'guard_passed' => true, 'mutation_performed' => $created, 'prefix' => W04_PREFIX,
            'evaluated_at' => $at->utc()->toIso8601String(), 'existing_records_unchanged' => true,
            'before' => $before, 'after' => $after,
            'aggregate' => $clocks->summarize($tickets, $at),
            'tickets' => $tickets->values()->map(fn (ItTicket $ticket) => [
                'id' => $ticket->id, 'reference' => $ticket->reference, 'title' => $ticket->title,
                'site_id' => $ticket->site_id, 'url' => '/it/tickets/'.$ticket->id,
                'version' => $ticket->lock_version, 'verdict' => $clocks->verdict($ticket, $at),
                'technician_can_work' => $access->canWork($tech, $ticket),
                'requester_can_view' => $access->canView($requester, $ticket),
            ])->all(),
        ];
    });
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::scalar('SELECT RELEASE_LOCK(?)', [$lock]);
}
