<?php

namespace App\Services\Operations;

use App\Models\OpsNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OpsNotificationService
{
    public function notifyCrud(User $actor, string $action, string $entityType, Model $entity, ?Model $related = null): void
    {
        $title = ucfirst($action) . ' ' . $entityType;
        $body = sprintf(
            '%s %s a %s%s.',
            $actor->name,
            $action,
            $entityType,
            $related ? ' for ' . ($related->full_name ?? $related->title ?? $related->name ?? '') : ''
        );

        // Notify org managers (users with operations management permissions)
        $recipients = User::where('organization_id', $actor->organization_id)
            ->where('id', '!=', $actor->id)
            ->whereIn('role', ['admin', 'manager', 'coordinator'])
            ->get();

        foreach ($recipients as $recipient) {
            OpsNotification::create([
                'organization_id' => $actor->organization_id,
                'user_id' => $recipient->id,
                'title' => $title,
                'body' => $body,
                'type' => $entityType . '.' . $action,
                'data' => [
                    'entity_type' => get_class($entity),
                    'entity_id' => $entity->id,
                    'actor_id' => $actor->id,
                    'action' => $action,
                ],
            ]);
        }
    }

    public function notifySpecific(int $userId, int $organizationId, string $title, string $body, string $type, array $data = []): void
    {
        OpsNotification::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'data' => $data,
        ]);
    }

    public function notifyBulk(array $userIds, int $organizationId, string $title, string $body, string $type, array $data = []): void
    {
        $records = collect($userIds)->map(fn ($userId) => [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'data' => json_encode($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        OpsNotification::insert($records->toArray());
    }
}
