<?php

namespace App\Services\Sites;

use App\Models\SiteContact;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Who can be picked as a Site lead or a staff-linked Site contact (to-do #10).
 *
 * The same scope as the Site-lead picker has always used
 * (UserSiteAccessService::applyStaffScope with sites.viewAll as the bypass):
 * current approved staff with a current HR employee profile, limited to the
 * viewer's approved Sites unless they can see every Site. Each option carries
 * the staff member's work details only (see SiteContact).
 */
final class SiteContactStaffDirectory
{
    public const BYPASS_PERMISSIONS = ['sites.viewAll'];

    public function __construct(private readonly UserSiteAccessService $access) {}

    /** @return Builder<User> */
    public function query(?User $viewer): Builder
    {
        $query = User::query();
        $this->access->applyStaffScope($query, $viewer, self::BYPASS_PERMISSIONS);

        return $query;
    }

    /**
     * @return list<array{id: int, name: string, role: string|null, phone: string|null, email: string|null}>
     */
    public function options(?User $viewer): array
    {
        return $this->query($viewer)
            ->with(['hrEmployeeProfile:'.implode(',', SiteContact::STAFF_PROFILE_COLUMNS)])
            ->select(['users.id', 'users.name'])
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $user): array => ['id' => (int) $user->id, ...SiteContact::workDetailsFor($user)])
            ->values()
            ->all();
    }

    /**
     * Refuse staff the viewer may not pick. A contact that keeps the staff
     * member it is already linked to is left alone, so a narrower viewer can
     * still save the Site without having to re-pick someone they can't see.
     *
     * @param  array<int, array<string, mixed>>  $contacts
     * @param  array<int, int>  $existingLinks  contact id => linked user id, for this Site
     */
    public function assertPickable(array $contacts, ?User $viewer, string $path, array $existingLinks = []): void
    {
        $toCheck = [];
        foreach (array_values($contacts) as $index => $contact) {
            $userId = $contact['user_id'] ?? null;
            if (! filled($userId)) {
                continue;
            }
            $userId = (int) $userId;
            $contactId = filled($contact['id'] ?? null) ? (int) $contact['id'] : null;
            if ($contactId !== null && ($existingLinks[$contactId] ?? null) === $userId) {
                continue;
            }
            $toCheck[$index] = $userId;
        }

        if ($toCheck === []) {
            return;
        }

        $visible = $this->query($viewer)
            ->whereKey(array_values(array_unique($toCheck)))
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $errors = [];
        foreach ($toCheck as $index => $userId) {
            if (! in_array($userId, $visible, true)) {
                $errors["{$path}.{$index}.user_id"] = 'Choose a current staff member from your approved Sites.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** The single-contact form of assertPickable (Site profile › Contacts). */
    public function assertPickableUser(mixed $userId, ?User $viewer, ?int $currentLink = null): void
    {
        if (! filled($userId) || (int) $userId === $currentLink) {
            return;
        }

        if (! $this->query($viewer)->whereKey((int) $userId)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'Choose a current staff member from your approved Sites.',
            ]);
        }
    }
}
