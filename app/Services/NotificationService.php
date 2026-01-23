<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class NotificationService
{
    /**
     * Roles that should generally be kept in-the-loop for operational changes.
     * Portal roles (client/next_of_kin) are intentionally excluded from these
     * database notifications to avoid accidentally leaking internal operations.
     */
    public const MANAGER_ROLES = [
        'admin',
        'provider_manager',
        'coordinator',
        'hr',
        'finance',
        'auditor',
    ];

    /**
     * Send a simple, consistent "CRUD event" notification.
     */
    public function notifyCrud(
        ?User $actor,
        string $action,
        string $entityLabel,
        ?Model $entity,
        ?Client $client = null,
        array $extra = []
    ): void {
        $recipients = $this->resolveRecipients($actor, $entity, $client, $extra);

        if ($recipients->isEmpty()) {
            return;
        }

        $payload = array_merge([
            'kind' => 'crud',
            'action' => $action,
            'entity' => $entityLabel,
            'entity_id' => $entity?->getKey(),
            'client_id' => $client?->id ?? ($entity?->client_id ?? null),
            'title' => $extra['title'] ?? $this->defaultTitle($action, $entityLabel, $entity, $client),
            'body' => $extra['body'] ?? null,
            'url' => $extra['url'] ?? $this->defaultUrl($entity, $client),
            'actor' => $actor ? [
                'id' => $actor->id,
                'name' => $actor->name,
            ] : null,
        ], $extra['data'] ?? []);

        $notification = new AppEventNotification($payload);

        $recipients->each(fn(User $u) => $u->notify($notification));
    }

    /**
     * Determine who should receive a notification for this event.
     */
    protected function resolveRecipients(?User $actor, ?Model $entity, ?Client $client, array $extra): Collection
    {
        $ids = collect();

        // 1) Always include managers (roles)
        $managerIds = User::query()
            ->whereHas('roles', fn($q) => $q->whereIn('name', self::MANAGER_ROLES))
            ->pluck('id');
        $ids = $ids->merge($managerIds);

        // 2) If event is client-related, include assigned support workers
        $clientModel = $client;
        if (!$clientModel && $entity && property_exists($entity, 'client_id')) {
            // fallback - if model has client_id column
            if (!empty($entity->client_id)) {
                $clientModel = Client::query()->find($entity->client_id);
            }
        }

        if ($clientModel) {
            $ids = $ids->merge($clientModel->supportWorkers()->pluck('users.id'));
        }

        // 3) If entity has a user_id (staff-specific), notify that staff member
        if ($entity && isset($entity->user_id) && !empty($entity->user_id)) {
            $ids = $ids->merge([$entity->user_id]);
        }

        // 4) Explicit targets (optional)
        if (!empty($extra['target_user_ids']) && is_array($extra['target_user_ids'])) {
            $ids = $ids->merge($extra['target_user_ids']);
        }

        // 5) Remove actor
        if ($actor) {
            $ids = $ids->reject(fn($id) => (int) $id === (int) $actor->id);
        }

        $ids = $ids->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get();
    }

    protected function defaultTitle(string $action, string $entityLabel, ?Model $entity, ?Client $client): string
    {
        $who = $client ? " for {$client->first_name} {$client->last_name}" : '';

        $label = $entityLabel;
        $verb = match ($action) {
            'created' => 'created',
            'updated' => 'updated',
            'deleted' => 'deleted',
            default => $action,
        };

        return ucfirst($label) . " {$verb}{$who}.";
    }

    protected function defaultUrl(?Model $entity, ?Client $client): ?string
    {
        // Keep it simple - point to the most relevant view.
        if ($entity instanceof Client) {
            return url("/clients/{$entity->id}");
        }

        // Many client sub-resources live under the client.
        $cid = $client?->id ?? ($entity?->client_id ?? null);
        if ($cid) {
            // Prefer timeline - it's a good universal landing page.
            return url("/clients/{$cid}/timeline");
        }

        // Staff entities
        if ($entity && isset($entity->user_id) && !empty($entity->user_id)) {
            return url("/staff/{$entity->user_id}");
        }

        return null;
    }
}
