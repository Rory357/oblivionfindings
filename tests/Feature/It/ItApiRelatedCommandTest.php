<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Services\ItTicketLinkService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketLink;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

function apiRelatedCommandActor(Site $site): User
{
    $actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $actor->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => null,
    ]);

    return $actor;
}

/** @return array<string, int|string> */
function apiRelatedCommandInput(ItTicket $source, ItTicket $target, User $actor, string $requestUuid): array
{
    return [
        'actor_user_id' => $actor->id,
        'target_ticket_id' => $target->id,
        'source_version' => $source->fresh()->lock_version,
        'target_version' => $target->fresh()->lock_version,
        'request_uuid' => $requestUuid,
        'relationship' => 'related_ticket',
        'action' => 'add',
    ];
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->apiRelatedSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->apiRelatedActor = apiRelatedCommandActor($this->apiRelatedSite);
    $this->apiRelatedSource = ItTicket::factory()->create([
        'site_id' => $this->apiRelatedSite->id,
        'work_type' => 'incident',
        'status' => 'open',
    ]);
    $this->apiRelatedTarget = ItTicket::factory()->create([
        'site_id' => $this->apiRelatedSite->id,
        'work_type' => 'incident',
        'status' => 'open',
    ]);
    $this->apiRelatedBrowserTarget = ItTicket::factory()->create([
        'site_id' => $this->apiRelatedSite->id,
        'work_type' => 'incident',
        'status' => 'open',
    ]);
});

test('service API related commands keep their receipt and provenance separate from a browser command', function () {
    $service = app(ItTicketLinkService::class);
    $requestUuid = (string) Str::uuid();
    $apiInput = apiRelatedCommandInput(
        $this->apiRelatedSource,
        $this->apiRelatedTarget,
        $this->apiRelatedActor,
        $requestUuid,
    );

    $apiResult = $service->changeRelated(
        $this->apiRelatedSource,
        $this->apiRelatedTarget,
        $this->apiRelatedActor,
        $apiInput,
        ItTicketCommandChannel::ServiceApi,
    );

    $browserInput = apiRelatedCommandInput(
        $this->apiRelatedSource,
        $this->apiRelatedBrowserTarget,
        $this->apiRelatedActor,
        $requestUuid,
    );
    $browserResult = $service->changeRelated(
        $this->apiRelatedSource,
        $this->apiRelatedBrowserTarget,
        $this->apiRelatedActor,
        $browserInput,
    );

    $apiReceipt = ItTicketCommandReceipt::query()
        ->where('actor_user_id', $this->apiRelatedActor->id)
        ->where('channel', ItTicketCommandChannel::ServiceApi->value)
        ->where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)
        ->where('request_uuid', $requestUuid)
        ->sole();
    $browserReceipt = ItTicketCommandReceipt::query()
        ->where('actor_user_id', $this->apiRelatedActor->id)
        ->where('channel', ItTicketCommandReceipt::CHANNEL)
        ->where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)
        ->where('request_uuid', $requestUuid)
        ->sole();
    $apiLink = ItTicketLink::query()
        ->where('ticket_id', $this->apiRelatedSource->id)
        ->where('relationship', 'related_ticket')
        ->where('linkable_type', $this->apiRelatedTarget->getMorphClass())
        ->where('linkable_id', $this->apiRelatedTarget->id)
        ->sole();
    $browserLink = ItTicketLink::query()
        ->where('ticket_id', $this->apiRelatedSource->id)
        ->where('relationship', 'related_ticket')
        ->where('linkable_type', $this->apiRelatedBrowserTarget->getMorphClass())
        ->where('linkable_id', $this->apiRelatedBrowserTarget->id)
        ->sole();
    $apiEvent = $this->apiRelatedSource->events()
        ->where('type', 'related_work_linked')
        ->where('payload->target_id', $this->apiRelatedTarget->id)
        ->sole();
    $apiAudit = AuditLog::query()
        ->where('action', 'it.ticket.relationship.add')
        ->where('auditable_type', $this->apiRelatedSource->getMorphClass())
        ->where('auditable_id', $this->apiRelatedSource->id)
        ->where('meta->target_ticket_id', $this->apiRelatedTarget->id)
        ->sole();

    expect($apiResult['status'])->toBe('committed')
        ->and($apiResult['data']['changed'])->toBeTrue()
        ->and($browserResult['status'])->toBe('committed')
        ->and($browserResult['data']['changed'])->toBeTrue()
        ->and($apiReceipt->it_ticket_id)->toBe($this->apiRelatedSource->id)
        ->and($browserReceipt->it_ticket_id)->toBe($this->apiRelatedSource->id)
        ->and($apiReceipt->id)->not->toBe($browserReceipt->id)
        ->and($apiLink->context)->toBe(['source' => 'service_api'])
        ->and($browserLink->context)->toBe(['source' => 'ticket_workspace'])
        ->and($apiEvent->payload)->toMatchArray(['source_channel' => 'service_api'])
        ->and($apiAudit->meta)->toMatchArray(['source' => 'service_api']);
});

test('service API related commands reject a stale version for either current ticket before writing', function (string $staleParent) {
    $input = apiRelatedCommandInput(
        $this->apiRelatedSource,
        $this->apiRelatedTarget,
        $this->apiRelatedActor,
        (string) Str::uuid(),
    );
    $this->{$staleParent}->update(['title' => 'Changed after the API command was prepared']);

    expect(fn () => app(ItTicketLinkService::class)->changeRelated(
        $this->apiRelatedSource,
        $this->apiRelatedTarget,
        $this->apiRelatedActor,
        $input,
        ItTicketCommandChannel::ServiceApi,
    ))->toThrow(ItTicketVersionConflict::class);

    expect(ItTicketLink::query()->whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()
            ->where('channel', ItTicketCommandChannel::ServiceApi->value)
            ->where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)
            ->count())->toBe(0);
})->with(['source' => 'apiRelatedSource', 'target' => 'apiRelatedTarget']);

test('service API related commands deny a counterpart whose current Site is no longer approved', function () {
    $input = apiRelatedCommandInput(
        $this->apiRelatedSource,
        $this->apiRelatedTarget,
        $this->apiRelatedActor,
        (string) Str::uuid(),
    );
    $this->apiRelatedTarget->update([
        'site_id' => Site::factory()->create(['is_active' => true, 'archived' => false])->id,
    ]);

    expect(fn () => app(ItTicketLinkService::class)->changeRelated(
        $this->apiRelatedSource,
        $this->apiRelatedTarget,
        $this->apiRelatedActor,
        $input,
        ItTicketCommandChannel::ServiceApi,
    ))->toThrow(ModelNotFoundException::class);

    expect(ItTicketLink::query()->whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()
            ->where('channel', ItTicketCommandChannel::ServiceApi->value)
            ->where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)
            ->count())->toBe(0);
});
