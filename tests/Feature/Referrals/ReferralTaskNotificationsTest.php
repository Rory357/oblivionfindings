<?php

use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\NotificationEscalationRule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\NotificationService;
use App\Services\Tasks\Providers\ReferralTaskProvider;
use App\Services\Tasks\TaskAggregator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

beforeEach(function () {
    foreach (ReferralPermissions::definitions() as $definition) {
        Permission::firstOrCreate(['key' => $definition['key']], $definition);
    }
});

it('limits overdue referral escalation to current authorized managers and keeps all notice text generic', function () {
    Notification::fake();
    $site = Site::factory()->create();
    $owner = referralTestUser($site, ['viewAny', 'funding.view']);
    $allowed = referralTestUser($site, ['viewAny', 'funding.view']);
    $denied = referralTestUser($site, ['viewAny']);
    $foreign = referralTestUser(Site::factory()->create(), ['viewAny', 'funding.view']);
    $role = Role::firstOrCreate(['name' => 'coordinator'], ['label' => 'Coordinator']);
    foreach ([$allowed, $denied, $foreign] as $manager) {
        $manager->roles()->attach($role);
    }
    $referral = referralTestRecord($site, ['owner_id' => $owner->id]);
    $work = ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $owner->id,
        'audience' => 'funding', 'kind' => 'follow_up', 'status' => 'open', 'due_at' => now()->subDays(4),
        'details' => ['title' => 'Secret diagnosis and funding decision']]);
    $referral->update(['primary_work_item_id' => $work->id]);
    TaskWatcher::create(['source' => 'referral_work', 'item_id' => $work->id, 'user_id' => $denied->id]);
    app()->instance(TaskAggregator::class, new TaskAggregator([new ReferralTaskProvider]));

    $this->artisan('tasks:escalate')->assertSuccessful();
    Notification::assertSentTo($owner, AppEventNotification::class);
    Notification::assertSentTo($allowed, AppEventNotification::class);
    Notification::assertNotSentTo($denied, AppEventNotification::class);
    Notification::assertNotSentTo($foreign, AppEventNotification::class);
    foreach ([$owner, $allowed] as $recipient) {
        $payload = Notification::sent($recipient, AppEventNotification::class)->sole()->payload;
        expect($payload['title'])->toBe('Referral follow-up needs attention')
            ->and($payload['context'])->toBe(['Module' => 'Referrals'])
            ->and($payload['recipient_scope'])->toBe('referral_task')
            ->and($payload['task_source'])->toBe('referral_work')
            ->and($payload['task_id'])->toBe($work->id)
            ->and(json_encode($payload))->not->toContain('Secret diagnosis');
    }
    expect(DB::table('task_escalations')->where('level', 2)->pluck('assignee_id')->all())->toBe([$allowed->id]);
    $count = Notification::sent($allowed, AppEventNotification::class)->count();
    $this->artisan('tasks:escalate')->assertSuccessful();
    expect(Notification::sent($allowed, AppEventNotification::class)->count())->toBe($count);
});

it('rechecks live audience scope and open overdue state for delayed referral reminders', function () {
    $site = Site::factory()->create();
    $owner = referralTestUser($site, ['viewAny', 'funding.view']);
    $referral = referralTestRecord($site, ['owner_id' => $owner->id, 'next_due_at' => now()->subDay()]);
    $work = ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $owner->id,
        'audience' => 'funding', 'kind' => 'follow_up', 'status' => 'open', 'due_at' => now()->subDay(), 'details' => ['title' => 'Private follow-up']]);
    $service = app(NotificationService::class);
    $payload = ['recipient_scope' => 'referral_task', 'task_source' => 'referral_work', 'task_id' => $work->id];
    $recipients = collect([$owner]);
    expect($service->filterRecipientScope($recipients, $payload))->toHaveCount(1);
    $work->update(['due_at' => now()->addDay()]);
    expect($service->filterRecipientScope($recipients, $payload))->toBeEmpty();
    $work->update(['due_at' => now()->subDay(), 'status' => 'completed']);
    expect($service->filterRecipientScope($recipients, $payload))->toBeEmpty();
    $work->update(['status' => 'open']);
    $owner->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'referrals.funding.view')->sole()->id => ['allowed' => false]]);
    expect($service->filterRecipientScope($recipients, $payload))->toBeEmpty();
    $next = ['recipient_scope' => 'referral_task', 'task_source' => 'referral_next', 'task_id' => $referral->id];
    expect($service->filterRecipientScope($recipients, $next))->toHaveCount(1);
    $referral->update(['primary_work_item_id' => $work->id]);
    expect($service->filterRecipientScope($recipients, $next))->toBeEmpty();
    $referral->update(['primary_work_item_id' => null, 'restricted' => true]);
    expect($service->filterRecipientScope($recipients, $next))->toBeEmpty();
    $referral->update(['restricted' => false, 'stage' => 'closed']);
    expect($service->filterRecipientScope($recipients, $next))->toBeEmpty();
    expect($service->filterRecipientScope($recipients, ['entity' => 'task', 'context' => ['Module' => 'Referrals']]))->toBeEmpty();
    expect($service->filterRecipientScope($recipients, ['recipient_scope' => 'referral_task', 'task_source' => 'referrals', 'task_id' => $work->id]))->toBeEmpty();
});

it('defers quiet-hour reminders without consuming attempts or blocking other due workflow notices', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-19 18:00', 'Pacific/Auckland'));
    try {
        $site = Site::factory()->create();
        $owner = referralTestUser($site, ['viewAny', 'funding.view']);
        $referral = referralTestRecord($site, ['owner_id' => $owner->id]);
        $version = ReferralSettingsVersion::findOrFail($referral->settings_version_id);
        $configuration = $version->configuration;
        $configuration['follow_up'] = ['manager_after_days' => 1, 'day_basis' => 'business', 'office_hours_only' => true];
        $timing = ReferralSettingsVersion::create(['version' => 91, 'state' => 'published', 'configuration' => $configuration,
            'published_at' => now(), 'effective_at' => now()]);
        $referral->update(['settings_version_id' => $timing->id]);
        $work = ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $owner->id,
            'audience' => 'funding', 'kind' => 'follow_up', 'status' => 'open', 'due_at' => now()->subDays(4), 'details' => ['title' => 'Private work']]);
        NotificationEscalationRule::create(['event_key' => 'qa.referral_reminder', 'enabled' => true, 'require_ack' => false, 'force_delivery' => true,
            'remind_after_minutes' => 1, 'repeat_every_minutes' => 1, 'max_reminders' => 1, 'escalate_to_role_groups' => [], 'tiers' => []]);
        $payload = ['entity' => 'task', 'event_key' => 'qa.referral_reminder', 'title' => 'Referral follow-up needs attention',
            'context' => ['Module' => 'Referrals'], 'recipient_scope' => 'referral_task', 'task_source' => 'referral_work', 'task_id' => $work->id];
        $notice = fn ($data, $id = null) => DatabaseNotification::create(['id' => $id ?? (string) Str::uuid(), 'type' => AppEventNotification::class,
            'notifiable_type' => User::class, 'notifiable_id' => $owner->id, 'data' => $data, 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
        $quiet = [];
        for ($i = 0; $i < 201; $i++) {
            $quiet[] = $notice($payload)->id;
        }
        $other = $notice(['event_key' => 'qa.referral_reminder', 'title' => 'Fictional other workflow', 'entity' => 'task', 'context' => ['Module' => 'Other']], 'ffffffff-ffff-4fff-bfff-ffffffffffff');
        $this->artisan('notifications:escalate')->assertSuccessful();
        expect((int) $other->fresh()->escalation_count)->toBe(1)
            ->and(DatabaseNotification::whereIn('id', $quiet)->where('escalation_count', '>', 0)->count())->toBe(0);
        Notification::assertSentToTimes($owner, AppEventNotification::class, 1);
        Carbon::setTestNow(Carbon::parse('2026-10-20 09:00', 'Pacific/Auckland'));
        $this->artisan('notifications:escalate')->assertSuccessful();
        expect(DatabaseNotification::whereIn('id', $quiet)->where('escalation_count', 1)->count())->toBe(200);
        $this->artisan('notifications:escalate')->assertSuccessful();
        expect(DatabaseNotification::whereIn('id', $quiet)->where('escalation_count', 1)->count())->toBe(201);
    } finally {
        Carbon::setTestNow();
    }
});
