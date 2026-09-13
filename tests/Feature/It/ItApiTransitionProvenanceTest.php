<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItApiWorkItemService;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Models\AuditLog;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function apiTransitionProvenanceUser(): User
{
    $user = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);

    return $user;
}

function apiTransitionProvenanceGrant(User $user, string ...$permissionKeys): void
{
    $role = Role::query()->create([
        'name' => 'api-transition-provenance-'.str()->uuid(),
        'label' => 'API transition provenance authority',
        'level' => 50,
        'type' => 'custom',
    ]);
    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'it', 'module' => 'Operations'],
        );
        $role->permissions()->attach($permission);
    }
    $user->roles()->attach($role);
}

function apiTransitionProvenanceAssignSite(User $user, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'created_by' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => null,
        'updated_by' => $user->id,
    ]);
}

/** @return array{identity: ItServiceIdentity, secret: string, token: string} */
function apiTransitionProvenanceIdentity(User $executionAccount, Site $site): array
{
    return app(ItServiceIdentityCredentialService::class)->create($executionAccount, [
        'name' => 'Transition provenance connector',
        'description' => 'A narrow connector used to prove canonical API attribution.',
        'actor_user_id' => $executionAccount->id,
        'abilities' => ['work:transition'],
        'allowed_work_types' => ['incident'],
        'allowed_site_ids' => [$site->id],
        'allowed_fields' => ['create' => [], 'read' => []],
        'require_signature' => false,
        'rate_limit_per_minute' => 60,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

test('direct API transition cannot replace service identity actor, source, or channel provenance', function () {
    $site = Site::factory()->create();
    $executionAccount = apiTransitionProvenanceUser();
    $substitutedAgent = apiTransitionProvenanceUser();
    apiTransitionProvenanceGrant($executionAccount, 'it.manage', 'it.view');
    apiTransitionProvenanceGrant($substitutedAgent, 'it.manage', 'it.view');
    apiTransitionProvenanceAssignSite($executionAccount, $site);
    apiTransitionProvenanceAssignSite($substitutedAgent, $site);
    $credential = apiTransitionProvenanceIdentity($executionAccount, $site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => 'incident',
        'workflow_state' => ItWorkflowState::Submitted->value,
        'status' => 'open',
    ]);

    $saved = app(ItApiWorkItemService::class)->transition($credential['identity'], $ticket,
        new ItTransitionInput(
            actor: $substitutedAgent,
            to: ItWorkflowState::InProgress,
            reason: 'The connector confirmed the monitor recovery.',
            source: 'legacy_status',
            expectedVersion: (int) $ticket->lock_version,
            channel: ItTicketCommandChannel::Browser,
        ),
    );

    $event = $saved->events()->where('type', 'workflow_transitioned')->latest('id')->firstOrFail();
    $transitionAudit = AuditLog::query()
        ->where('action', 'it.work.transitioned')
        ->where('auditable_type', $saved->getMorphClass())
        ->where('auditable_id', $saved->id)
        ->latest('id')
        ->firstOrFail();

    expect($saved->workflow_state)->toBe(ItWorkflowState::InProgress->value)
        ->and($event->actor_user_id)->toBe($executionAccount->id)
        ->and($event->payload)->toMatchArray(['via' => 'service_api'])
        ->and($transitionAudit->user_id)->toBe($executionAccount->id)
        ->and($transitionAudit->meta)->toMatchArray([
            'actor_id' => $executionAccount->id,
            'source' => 'service_api',
        ]);
});

test('direct API transition cannot borrow requester confirmation semantics', function () {
    $site = Site::factory()->create();
    $executionAccount = apiTransitionProvenanceUser();
    apiTransitionProvenanceGrant($executionAccount, 'it.manage', 'it.view');
    apiTransitionProvenanceAssignSite($executionAccount, $site);
    $credential = apiTransitionProvenanceIdentity($executionAccount, $site);
    $requester = User::factory()->create(['approved_at' => now()]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $requester->id,
        'requested_for_user_id' => $requester->id,
        'work_type' => 'incident',
        'workflow_state' => ItWorkflowState::Resolved->value,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);
    $existingAuditIds = AuditLog::query()->where('auditable_type', $ticket->getMorphClass())
        ->where('auditable_id', $ticket->id)->orderBy('id')->pluck('id')->all();

    expect(fn () => app(ItApiWorkItemService::class)->transition($credential['identity'], $ticket,
        new ItTransitionInput(
            actor: $requester,
            to: ItWorkflowState::Closed,
            source: 'requester_confirmation',
            channel: ItTicketCommandChannel::Browser,
        ),
    ))->toThrow(DomainException::class, 'Record a reason');

    expect($ticket->fresh()->status)->toBe('resolved')
        ->and($ticket->events()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', $ticket->getMorphClass())
            ->where('auditable_id', $ticket->id)->orderBy('id')->pluck('id')->all())->toBe($existingAuditIds);
});
