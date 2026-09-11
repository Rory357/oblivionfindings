<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

test('ticket requester choices preserve HR profile identity and expose only eligible approved staff user identities', function () {
    $this->seed(RbacSeeder::class);
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $users = collect([
        'agent' => ['hr', $site, true],
        'employee' => ['support_worker', $site, true],
        'unapproved' => ['support_worker', $site, false],
        'client' => ['client', $site, true],
        'foreign' => ['support_worker', $otherSite, true],
    ])->map(function (array $values): User {
        [$role, $approvedSite, $approved] = $values;
        $user = User::factory()->create(['role' => $role, 'approved_at' => $approved ? now() : null]);
        $user->roles()->sync(Role::query()->where('name', $role)->pluck('id'));
        ensureCanonicalHrStaffProfile($user, $approvedSite);

        return $user;
    });
    $profile = HrEmployeeProfile::query()->where('user_id', $users['employee']->id)->firstOrFail();
    $intake = app(ItTicketIntakeService::class);
    expect($intake->requesterOption($users['agent'], $users['employee']))->toBe([
        'user_id' => $users['employee']->id, 'site_ids' => [$site->id],
    ]);
    foreach (['unapproved', 'client', 'foreign'] as $key) {
        expect($intake->requesterOption($users['agent'], $users[$key]))->toBeNull();
    }
    expect($intake->requesterOption($users['employee'], $users['agent']))->toBeNull();
    $this->actingAs($users['agent'])->get('/it')->assertInertia(fn ($page) => $page
        ->where('employeeOptions', function ($options) use ($users, $profile, $site): bool {
            $options = collect($options);
            $employee = $options->firstWhere('id', $profile->id);

            return $employee['requester'] === ['user_id' => $users['employee']->id, 'site_ids' => [$site->id]]
                && $options->whereNotNull('requester')->pluck('requester.user_id')->contains($users['employee']->id)
                && ! $options->whereNotNull('requester')->pluck('requester.user_id')->intersect([
                    $users['unapproved']->id, $users['client']->id, $users['foreign']->id,
                ])->isNotEmpty();
        }));
    $profile->update(['is_active' => false]);
    expect($intake->requesterOption($users['agent'], $users['employee']->fresh()))->toBeNull();
});
