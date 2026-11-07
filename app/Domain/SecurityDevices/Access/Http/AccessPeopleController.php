<?php

namespace App\Domain\SecurityDevices\Access\Http;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Access\AccessJobs;
use App\Domain\SecurityDevices\Access\Models\AccessCard;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessJob;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Access\Models\AccessPolicy;
use App\Domain\SecurityDevices\Access\Models\AccessVisitor;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * S&D Phase H (pop-ups 81–84, 90): who can get in, their cards and visitor passes, changed in
 * UniFi Access through the Site's hub. Each change is a job the hub runs; the pop-up waits on it.
 * Another Site's console, person, card or pass is 404. Names for a staff member come from HR,
 * never from the browser.
 */
final class AccessPeopleController extends Controller
{
    private const string MANAGE = 'securityDevices.accessControl.manage';

    private const string VIEW = 'securityDevices.accessControl.view';

    public function __construct(
        private readonly AccessJobs $jobs,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** Pop-up 81: give a staff member (or a contractor, by name) access to some doors. */
    public function store(Request $request, int $console): JsonResponse
    {
        $user = $this->user($request, self::MANAGE);
        $model = $this->console($user, $console);
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'first_name' => ['required_without:user_id', 'nullable', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'policy_ids' => ['present', 'array', 'max:20'],
            'policy_ids.*' => ['string', 'max:64'],
        ]);
        $policies = $this->policies($model, $validated['policy_ids']);
        $link = [];
        if (isset($validated['user_id'])) {
            $profile = HrEmployeeProfile::query()->where('user_id', (int) $validated['user_id'])->where('is_active', true)
                ->where(fn ($query) => $query->where('primary_site_id', $model->site_id)->orWhereJsonContains('secondary_site_ids', (int) $model->site_id))
                ->first();
            $staff = $profile ? User::query()->find((int) $validated['user_id']) : null;
            if (! $staff instanceof User) {
                throw ValidationException::withMessages(['user_id' => 'Choose someone who works at this Site.']);
            }
            if (AccessPerson::query()->where('console_id', $model->id)->where('user_id', $staff->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'They’re already in UniFi Access here. Change their doors on the People tab.']);
            }
            [$first, $last] = self::split((string) $staff->name);
            $params = ['first_name' => $first, 'last_name' => $last, 'employee_number' => (string) ($profile->employee_number ?? ''), 'policy_ids' => $policies];
            $link = ['_kind' => 'staff', '_user_id' => (int) $staff->id];
        } else {
            $params = ['first_name' => trim((string) $validated['first_name']), 'last_name' => trim((string) ($validated['last_name'] ?? '')), 'employee_number' => '', 'policy_ids' => $policies];
        }

        return $this->queued($model, 'user_create', [...$params, ...$link], $user, null, 'Adding them in UniFi Access…');
    }

    /** Pop-up 83 and the People tab: turn a person on or off in UniFi Access. */
    public function status(Request $request, int $person): JsonResponse
    {
        $user = $this->user($request, self::MANAGE);
        $model = $this->person($user, $person);
        $validated = $request->validate([
            'status' => ['required', 'in:active,deactivated'],
            'reason' => ['required_if:status,deactivated', 'nullable', 'string', 'max:191'],
        ], ['reason.required_if' => 'Say why, so the change history makes sense.']);

        return $this->queued($model->console, 'user_status', ['user_id' => $model->external_id, 'status' => $validated['status']], $user, $validated['reason'] ?? null,
            $validated['status'] === 'deactivated' ? 'Turning them off in UniFi Access…' : 'Turning them back on in UniFi Access…');
    }

    /** The People tab: which doors (access policies) a person can open. */
    public function doors(Request $request, int $person): JsonResponse
    {
        $user = $this->user($request, self::MANAGE);
        $model = $this->person($user, $person);
        $validated = $request->validate(['policy_ids' => ['present', 'array', 'max:20'], 'policy_ids.*' => ['string', 'max:64']]);

        return $this->queued($model->console, 'user_policies', ['user_id' => $model->external_id, 'policy_ids' => $this->policies($model->console, $validated['policy_ids'])], $user, null, 'Changing their doors in UniFi Access…');
    }

    /** Pop-up 82: a reader listens for a new card for a minute, then it's added to the person. */
    public function enrol(Request $request, int $person): JsonResponse
    {
        $user = $this->user($request, self::MANAGE);
        $model = $this->person($user, $person);
        $validated = $request->validate(['reader_id' => ['required', 'string', 'max:64']]);
        $readers = AccessDoor::query()->where('console_id', $model->console_id)->pluck('readers')->flatten(1)->filter(fn ($reader): bool => is_array($reader));
        if (! $readers->contains(fn (array $reader): bool => ($reader['id'] ?? null) === $validated['reader_id'])) {
            throw ValidationException::withMessages(['reader_id' => 'Choose a reader at this Site.']);
        }

        return $this->queued($model->console, 'card_enrol', ['user_id' => $model->external_id, 'reader_id' => $validated['reader_id']], $user, null, 'Tap the new card on the reader within a minute.', 300);
    }

    /** Pop-up 83: take a lost or returned card off a person (their other ways in stay). */
    public function removeCard(Request $request, int $card): JsonResponse
    {
        $user = $this->user($request, self::MANAGE);
        $model = AccessCard::query()->with('person.console')->findOrFail($card);
        $person = $this->person($user, (int) $model->person_id);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:191']], ['reason.required' => 'Say why, for example “Lost”.']);

        return $this->queued($person->console, 'card_remove', ['user_id' => $person->external_id, 'token_hash' => $model->token_hash], $user, $validated['reason'], 'Turning the card off in UniFi Access…');
    }

    /** Pop-up 84: a visitor pass for some doors, for at most a month. */
    public function storeVisitor(Request $request, int $console): JsonResponse
    {
        $user = $this->user($request, self::MANAGE);
        $model = $this->console($user, $console);
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'door_ids' => ['required', 'array', 'min:1', 'max:20'],
            'door_ids.*' => ['string', 'max:64'],
        ], ['ends_at.after' => 'The pass must end after it starts.', 'door_ids.required' => 'Choose at least one door.']);
        $starts = CarbonImmutable::parse($validated['starts_at'])->utc();
        $ends = CarbonImmutable::parse($validated['ends_at'])->utc();
        if ($ends->lte(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages(['ends_at' => 'The pass must end in the future.']);
        }
        if ($starts->diffInSeconds($ends) > 31 * 86_400) {
            throw ValidationException::withMessages(['ends_at' => 'A visitor pass lasts a month at most.']);
        }
        $known = AccessDoor::query()->where('console_id', $model->id)->whereIn('external_id', $validated['door_ids'])->pluck('external_id')->all();
        if (count(array_unique($validated['door_ids'])) !== count($known)) {
            throw ValidationException::withMessages(['door_ids' => 'Choose doors at this Site.']);
        }

        return $this->queued($model, 'visitor_create', [
            'first_name' => trim($validated['first_name']),
            'last_name' => trim((string) ($validated['last_name'] ?? '')),
            'starts_at' => $starts->getTimestamp(),
            'ends_at' => $ends->getTimestamp(),
            'door_ids' => array_values(array_unique($validated['door_ids'])),
        ], $user, null, 'Making the visitor pass in UniFi Access…');
    }

    public function destroyVisitor(Request $request, int $visitor): JsonResponse
    {
        $user = $this->user($request, self::MANAGE);
        $model = AccessVisitor::query()->findOrFail($visitor);
        $console = $this->console($user, (int) $model->console_id);

        return $this->queued($console, 'visitor_delete', ['visitor_id' => $model->external_id], $user, null, 'Cancelling the visitor pass in UniFi Access…');
    }

    /**
     * Pop-up 90: confirm (or undo) that a UniFi person is one of our staff. Only Oblivion changes;
     * the match is what lets their access end by itself when they leave (D20).
     */
    public function match(Request $request, int $person): JsonResponse
    {
        $user = $this->user($request, self::MANAGE);
        $model = $this->person($user, $person);
        $validated = $request->validate(['user_id' => ['present', 'nullable', 'integer', 'min:1']]);
        $staffId = $validated['user_id'];
        if ($staffId !== null) {
            $works = HrEmployeeProfile::query()->where('user_id', (int) $staffId)->where('is_active', true)
                ->where(fn ($query) => $query->where('primary_site_id', $model->console->site_id)->orWhereJsonContains('secondary_site_ids', (int) $model->console->site_id))
                ->exists();
            if (! $works) {
                throw ValidationException::withMessages(['user_id' => 'Choose someone who works at this Site.']);
            }
        }
        $model->forceFill($staffId !== null
            ? ['user_id' => (int) $staffId, 'kind' => 'staff', 'match_state' => 'matched', 'client_id' => null]
            : ['user_id' => null, 'kind' => 'unknown', 'match_state' => 'unmatched'])->save();
        AuditLogger::logOrFail('security_devices.access.person_matched', $model, [
            'console_id' => (int) $model->console_id,
            'user_id' => $staffId,
        ]);

        return response()->json(['message' => $staffId !== null ? 'Matched.' : 'No longer matched.']);
    }

    /** The pop-up's wait: how a change is going. Only who asked (or a manager at that Site) sees it. */
    public function job(Request $request, string $uuid): JsonResponse
    {
        $user = $this->user($request, self::VIEW);
        $job = AccessJob::query()->with('console')->where('uuid', strtolower($uuid))->firstOrFail();
        $this->console($user, (int) $job->console_id);
        $result = (array) ($job->result ?? []);

        return response()->json([
            'state' => $job->state,
            'message' => match ($job->state) {
                'succeeded' => match ($job->kind) {
                    'card_enrol' => 'Card added'.(is_string($result['display_id'] ?? null) ? ' ('.$result['display_id'].')' : '').'.',
                    'user_create' => 'Added in UniFi Access.',
                    'visitor_create' => 'Visitor pass made.',
                    default => 'Done in UniFi Access.',
                },
                'queued', 'sent' => null,
                default => $job->failure,
            },
        ]);
    }

    /** @param array<string, mixed> $params */
    private function queued(AccessConsole $console, string $kind, array $params, User $user, ?string $reason, string $message, int $ttl = AccessJobs::DEFAULT_TTL_SECONDS): JsonResponse
    {
        try {
            $job = $this->jobs->queue($console, $kind, $params, $user, $reason, $ttl);
        } catch (DomainException $refused) {
            throw ValidationException::withMessages(['access' => $refused->getMessage()]);
        }

        return response()->json(['job' => $job->uuid, 'message' => $message], 202);
    }

    /** @param list<string> $ids @return list<string> */
    private function policies(AccessConsole $console, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $known = AccessPolicy::query()->where('console_id', $console->id)->whereIn('external_id', $ids)->pluck('external_id')->all();
        if (count($known) !== count($ids)) {
            throw ValidationException::withMessages(['policy_ids' => 'Choose door groups at this Site.']);
        }

        return $ids;
    }

    private function user(Request $request, string $key): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo($key), 403);

        return $user;
    }

    private function console(User $user, int $id): AccessConsole
    {
        $console = AccessConsole::query()->findOrFail($id);
        abort_unless($this->access->canViewAllSites($user) || in_array((int) $console->site_id, $this->access->accessibleSiteIds($user), true), 404);

        return $console;
    }

    private function person(User $user, int $id): AccessPerson
    {
        $person = AccessPerson::query()->with('console')->findOrFail($id);
        $this->console($user, (int) $person->console_id);

        return $person;
    }

    /** @return array{0: string, 1: string} */
    private static function split(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [''];

        return [mb_substr($parts[0] !== '' ? $parts[0] : 'Staff', 0, 60), mb_substr($parts[1] ?? '', 0, 60)];
    }
}
