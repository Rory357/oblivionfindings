<?php

namespace App\Domain\CompanyHub\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Individual birthday choices use the existing canonical celebration settings. */
class HubCelebrations
{
    public function __construct(
        private readonly HubAccess $access,
        private readonly UserSiteAccessService $sites,
    ) {}

    public function ready(): bool
    {
        return Schema::hasColumn('hub_celebration_settings', 'version');
    }

    public function forUser(User $user): array
    {
        $actor = $this->currentActor($user, false);
        $ready = $this->ready();

        return [
            'ready' => $ready,
            'actor_id' => (int) $actor->id,
            'people' => $actor->canDo('company_hub.celebrations') ? $this->people($actor, $ready) : [],
        ];
    }

    public function read(User $user, array $input = []): array
    {
        $actor = $this->currentActor($user);
        $data = Validator::make($input, ['actor_id' => 'sometimes|required|integer|min:1'])->validate();
        abort_if(isset($data['actor_id']) && (int) $data['actor_id'] !== (int) $actor->id, 409,
            'Your signed-in account changed. Reopen birthday sharing before continuing.');
        $ready = $this->ready();

        return ['ready' => $ready, 'actor_id' => (int) $actor->id, 'people' => $this->people($actor, $ready)];
    }

    public function save(User $user, int $personId, array $input, bool $guarded): array
    {
        return DB::transaction(function () use ($user, $personId, $input, $guarded): array {
            // The target User lock also serialises creation when no setting row exists yet.
            User::query()->whereIn('id', array_unique([$user->id, $personId]))
                ->orderBy('id')->lockForUpdate()->get();
            $actor = $this->currentActor($user);
            $person = $this->sites->applyStaffScope(User::query(), $actor)->whereKey($personId)->first();
            abort_unless($person, 404);

            $data = Validator::make($input, [
                'birthday_enabled' => 'required|boolean',
                'actor_id' => ($guarded ? 'required' : 'sometimes|required').'|integer|min:1',
                'version' => ($guarded ? 'required' : 'sometimes|required').'|integer|min:1',
                'user_id' => 'prohibited', 'person_id' => 'prohibited', 'updated_by' => 'prohibited',
                'date_of_birth' => 'prohibited', 'year' => 'prohibited', 'age' => 'prohibited',
            ])->validate();
            abort_if(isset($data['actor_id']) && (int) $data['actor_id'] !== (int) $actor->id, 409,
                'Your signed-in account changed. Reopen birthday sharing before saving.');
            $ready = $this->ready();
            abort_if(($guarded || isset($data['version'])) && ! $ready, 503,
                'Birthday preference editing is not available until the database update is installed.');
            $row = DB::table('hub_celebration_settings')->where('user_id', $personId)->lockForUpdate()->first();
            $version = (int) ($row?->version ?? 1);
            abort_if(isset($data['version']) && (int) $data['version'] !== $version, 409,
                'This birthday preference changed. Reload the saved choice before trying again.');
            $enabled = (bool) $data['birthday_enabled'];
            DB::table('hub_celebration_settings')->updateOrInsert(['user_id' => $personId], [
                'birthday_enabled' => $enabled,
                'updated_by' => $actor->id,
                ...($ready ? ['version' => $version + 1] : []),
                'updated_at' => now(),
                ...($row ? [] : ['created_at' => now()]),
            ]);
            AuditLog::create([
                'user_id' => $actor->id, 'action' => 'company_hub.birthday_visibility',
                'auditable_type' => User::class, 'auditable_id' => $personId,
                'meta' => ['enabled' => $enabled, ...($ready ? ['previous_version' => $version, 'version' => $version + 1] : [])],
            ]);

            // Build the receipt under the lock; a subsequent editor cannot change its version.
            return [
                'saved' => true, 'ready' => $ready, 'actor_id' => (int) $actor->id,
                'person' => ['id' => (int) $person->id, 'name' => $person->name, 'enabled' => $enabled, 'version' => $ready ? $version + 1 : null],
            ];
        });
    }

    private function currentActor(User $user, bool $manage = true): User
    {
        $actor = User::find($user->id);
        abort_unless($actor, 403);
        $this->access->assertEnter($actor);
        abort_unless($this->sites->applyStaffScope(User::query()->whereKey($actor->id), $actor)->exists(), 403);
        abort_if($manage && ! $actor->canDo('company_hub.celebrations'), 403);

        return $actor;
    }

    private function people(User $actor, bool $ready): array
    {
        return $this->sites->applyStaffScope(User::query(), $actor)
            ->leftJoin('hub_celebration_settings', 'hub_celebration_settings.user_id', '=', 'users.id')
            ->orderBy('users.name')->orderBy('users.id')
            ->get(['users.id', 'users.name', 'hub_celebration_settings.birthday_enabled', ...($ready ? ['hub_celebration_settings.version'] : [])])
            ->map(fn ($person) => ['id' => (int) $person->id, 'name' => $person->name,
                'enabled' => (bool) $person->birthday_enabled, 'version' => $ready ? (int) ($person->version ?? 1) : null])
            ->all();
    }
}
