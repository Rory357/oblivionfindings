<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\NotificationEscalationRule;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/** Tests delivery authorization without an application or database bootstrap. */
class MedicalNotificationRecipientScopeTest extends TestCase
{
    public function test_medical_routing_filters_all_candidates_before_preferences_and_persists_its_person_scope(): void
    {
        [$service, $client, $users] = $this->recipients();
        $service->notifyCrud(null, 'created', 'condition', null, $client, $this->medicalPayload());

        $this->assertSame([$users[0]], $service->preferenceRecipients);
        $this->assertCount(1, $users[0]->delivered);
        foreach (array_slice($users, 1) as $user) {
            $this->assertSame([], $user->delivered);
        }
        $payload = $users[0]->delivered[0]->payload;
        $this->assertSame('client_medical', $payload['recipient_scope']);
        $this->assertSame(42, $payload['client_id']);
        $this->assertSame('Condition recorded: clinical title', $payload['title']);
        $this->assertSame([42], $service->lookedUpClientIds);
    }

    public function test_force_delivery_cannot_bypass_medical_read_or_person_site_authority(): void
    {
        [$service, $client, $users] = $this->recipients();
        $service->rule = new NotificationEscalationRule;
        $service->rule->setRawAttributes(['enabled' => true, 'force_delivery' => true]);
        $service->notifyCrud(null, 'created', 'condition', null, $client, $this->medicalPayload());

        $this->assertNull($service->preferenceRecipients);
        $this->assertCount(1, $users[0]->delivered);
        foreach (array_slice($users, 1) as $user) {
            $this->assertSame([], $user->delivered);
        }
    }

    public function test_reminder_scope_rechecks_the_current_person_site_instead_of_the_original_recipients(): void
    {
        [$service, $client, $users] = $this->recipients();
        $payload = ['recipient_scope' => 'client_medical', 'client_id' => 42];
        $this->assertSame([$users[0]], $service->filterRecipientScope(collect($users), $payload)->all());
        // The person moved. The old recipient must lose delivery authority;
        // the approved reader at the new site may now receive the reminder.
        $client->setRawAttributes(['id' => 42, 'site_id' => 9]);
        $this->assertSame([$users[3]], $service->filterRecipientScope(collect($users), $payload)->all());
        $this->assertSame([42, 42], $service->lookedUpClientIds);
    }

    public function test_legacy_medical_reminders_without_a_marker_retain_the_same_read_boundary(): void
    {
        [$service, , $users] = $this->recipients();
        foreach (['medical profile', 'condition', 'emergency contact'] as $entity) {
            $this->assertSame([$users[0]], $service->filterRecipientScope(collect($users), [
                'entity' => $entity, 'client_id' => 42,
            ])->all());
        }
    }

    public function test_missing_removed_or_unknown_medical_scope_fails_closed(): void
    {
        [$service, , $users] = $this->recipients();
        foreach ([
            ['recipient_scope' => 'client_medical'],
            ['recipient_scope' => 'client_medical', 'client_id' => 'invalid'],
            ['recipient_scope' => 'unrecognised', 'client_id' => 42],
            ['recipient_scope' => 'client_medical', 'client_id' => 84],
        ] as $payload) {
            $this->assertTrue($service->filterRecipientScope(collect($users), $payload)->isEmpty());
        }
        $service->client = null;
        $this->assertTrue($service->filterRecipientScope(collect($users), [
            'recipient_scope' => 'client_medical', 'client_id' => 42,
        ])->isEmpty());
    }

    public function test_unscoped_notifications_keep_the_existing_delivery_and_preferences(): void
    {
        [$service, $client, $users] = $this->recipients();
        $service->notifyCrud(null, 'updated', 'client', null, $client, [
            'event_key' => 'client.updated', 'title' => 'Client updated', 'url' => '/clients/42', 'context' => [],
        ]);
        $this->assertSame($users, $service->preferenceRecipients);
        foreach ($users as $user) {
            $this->assertCount(1, $user->delivered);
            $this->assertArrayNotHasKey('recipient_scope', $user->delivered[0]->payload);
        }
        $this->assertSame([], $service->lookedUpClientIds);
    }

    private function medicalPayload(): array
    {
        return ['event_key' => 'condition.created', 'title' => 'Condition recorded: clinical title',
            'url' => '/operations/clients/42?tab=medical', 'context' => [],
            'data' => ['recipient_scope' => 'client_medical', 'client_id' => 999]];
    }

    private function recipients(): array
    {
        $client = new Client;
        $client->setRawAttributes(['id' => 42, 'site_id' => 7]);
        $users = [
            new MedicalNotificationRecipientDouble(true, true, 42, 7),
            new MedicalNotificationRecipientDouble(true, false, 42, 7),
            new MedicalNotificationRecipientDouble(false, true, 42, 7),
            new MedicalNotificationRecipientDouble(true, true, 42, 9),
            new MedicalNotificationRecipientDouble(true, true, 84, 7),
        ];
        $service = new MedicalNotificationServiceDouble;
        $service->client = $client;
        $service->candidates = collect($users);

        return [$service, $client, $users];
    }
}

class MedicalNotificationRecipientDouble extends User
{
    public array $delivered = [];

    public function __construct(
        private readonly bool $canView = false,
        private readonly bool $canViewMedications = false,
        private readonly int $personId = 0,
        private readonly int $siteId = 0,
    ) {
        parent::__construct();
    }

    public function can($abilities, $arguments = []): bool
    {
        return $arguments instanceof Client && (int) $arguments->id === $this->personId
            && (int) $arguments->site_id === $this->siteId
            && match ($abilities) {
                'view' => $this->canView,
                'viewMedications' => $this->canViewMedications,
                default => false,
            };
    }

    public function notify($instance): void
    {
        $this->delivered[] = $instance;
    }
}

class MedicalNotificationServiceDouble extends NotificationService
{
    public ?Client $client = null;

    public Collection $candidates;

    public ?NotificationEscalationRule $rule = null;

    public ?array $preferenceRecipients = null;

    public array $lookedUpClientIds = [];

    protected function escalationRuleFor(string $eventKey): ?NotificationEscalationRule
    {
        return $this->rule;
    }

    protected function applyRoutingDefaults(array $extra): array
    {
        return $extra;
    }

    protected function resolveRecipients(?User $actor, ?Model $entity, ?Client $client, array $extra): Collection
    {
        return $this->candidates;
    }

    public function applyPreferences(Collection $recipients, string $eventKey): Collection
    {
        $this->preferenceRecipients = $recipients->all();

        return $recipients;
    }

    protected function recipientScopeClient(int $clientId): ?Client
    {
        $this->lookedUpClientIds[] = $clientId;

        return $clientId === (int) $this->client?->id ? $this->client : null;
    }
}
