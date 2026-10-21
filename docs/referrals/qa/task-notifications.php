<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\ReferralPermissions;
use App\Domain\Referrals\Services\ReferralFollowUpPolicy;
use App\Domain\Referrals\Services\ReferralSettings;
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
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
DB::beginTransaction();
try {
    Notification::fake(); // No real notifications or external deliveries, even inside this rollback.
    $site = Site::factory()->create(['name' => 'Fictional referral reminder QA', 'is_active' => true, 'archived' => false]);
    $foreignSite = Site::factory()->create(['name' => 'Fictional separate reminder QA site', 'is_active' => true, 'archived' => false]);
    $newUser = function ($site, $permissions) {
        $user = User::factory()->create(['name' => 'Fictional reminder QA staff', 'approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true, 'created_by' => $user->id, 'updated_by' => $user->id]);
        foreach (ReferralPermissions::definitions() as $definition) {
            $permission = Permission::firstOrCreate(['key' => $definition['key']], $definition);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => in_array($definition['key'], $permissions, true)]]);
        }

        return $user;
    };
    $owner = $newUser($site, ['referrals.viewAny', 'referrals.funding.view']);
    $allowed = $newUser($site, ['referrals.viewAny', 'referrals.funding.view']);
    $denied = $newUser($site, ['referrals.viewAny']);
    $foreign = $newUser($foreignSite, ['referrals.viewAny', 'referrals.funding.view']);
    $role = Role::firstOrCreate(['name' => 'coordinator'], ['label' => 'Coordinator']);
    foreach ([$allowed, $denied, $foreign] as $manager) {
        $manager->roles()->attach($role);
    }
    $referral = CareReferral::create(['settings_version_id' => $version->id, 'site_id' => $site->id, 'owner_id' => $owner->id, 'stage' => 'assessment',
        'source' => 'qa_reminder_privacy', 'entry_kind' => 'support', 'requested_service' => 'supported_living', 'team' => 'Fictional QA',
        'identity' => ['first_name' => 'Fictional', 'last_name' => 'ReminderQA'], 'intake' => [], 'authority' => [], 'assessment' => [],
        'next_action' => 'Secret fictional funding detail', 'next_due_at' => now()->subDays(4), 'stage_changed_at' => now()]);
    $work = ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $owner->id, 'kind' => 'follow_up', 'audience' => 'funding', 'status' => 'open',
        'due_at' => now()->subDays(4), 'details' => ['title' => 'Secret fictional diagnosis and funding decision']]);
    $referral->update(['primary_work_item_id' => $work->id]);
    TaskWatcher::create(['source' => 'referral_work', 'item_id' => $work->id, 'user_id' => $denied->id]);
    app()->instance(TaskAggregator::class, new TaskAggregator([new ReferralTaskProvider]));
    $check(Artisan::call('tasks:escalate') === 0, 'Real overdue command completes with notifications faked');
    $check(Notification::sent($owner, AppEventNotification::class)->count() === 1, 'The authorized assignee receives one reminder');
    $check(Notification::sent($allowed, AppEventNotification::class)->count() === 1, 'Only the authorized local manager receives escalation');
    $check(Notification::sent($denied, AppEventNotification::class)->isEmpty(), 'A manager/watcher without funding access receives nothing');
    $check(Notification::sent($foreign, AppEventNotification::class)->isEmpty(), 'A manager from another site receives nothing');
    $check(! TaskWatcher::where('source', 'referral_work')->where('item_id', $work->id)->where('user_id', $denied->id)->exists(), 'The stale unauthorized watcher is pruned');
    $payload = Notification::sent($allowed, AppEventNotification::class)->sole()->payload;
    $check($payload['title'] === 'Referral follow-up needs attention' && $payload['context'] === ['Module' => 'Referrals'] && ! str_contains(json_encode($payload), 'Secret fictional'), 'Notification text and context contain no private task details');
    $check($payload['recipient_scope'] === 'referral_task' && $payload['task_source'] === 'referral_work' && $payload['task_id'] === $work->id, 'The exact scoped item identity is retained for delayed reminders');
    $check(DB::table('task_escalations')->where('source', 'referral_work')->where('item_id', $work->id)->where('level', 2)->pluck('assignee_id')->all() === [$allowed->id], 'Manager deduplication is per authorized recipient');
    Artisan::call('tasks:escalate');
    $check(Notification::sent($allowed, AppEventNotification::class)->count() === 1 && Notification::sent($owner, AppEventNotification::class)->count() === 1, 'A repeated sweep does not duplicate delivery');
    $service = app(NotificationService::class);
    $recipients = collect([$owner, $allowed, $denied, $foreign]);
    $check($service->filterRecipientScope($recipients, $payload)->count() === 2, 'Delayed reminders reapply the same audience and site boundary');
    $work->update(['due_at' => now()->addDay()]);
    $check($service->filterRecipientScope($recipients, $payload)->isEmpty(), 'Rescheduling into the future stops old reminders');
    $work->update(['due_at' => now()->subDay(), 'status' => 'completed']);
    $check($service->filterRecipientScope($recipients, $payload)->isEmpty(), 'Completing the work stops old reminders');
    $work->update(['status' => 'open']);
    $owner->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'referrals.funding.view')->sole()->id => ['allowed' => false]]);
    $check($service->filterRecipientScope(collect([$owner]), $payload)->isEmpty(), 'Revoked permission is honored even with a previously loaded recipient');
    $referral->update(['restricted' => true]);
    $check($service->filterRecipientScope($recipients, $payload)->isEmpty(), 'New referral restrictions block delayed disclosure');
    $referral->update(['restricted' => false, 'stage' => 'closed']);
    $check($service->filterRecipientScope($recipients, $payload)->isEmpty(), 'Closing the referral stops outstanding reminders');
    $check($service->filterRecipientScope($recipients, ['entity' => 'task', 'context' => ['Module' => 'Referrals']])->isEmpty(), 'Unscoped historical referral task notifications cannot be replayed');
    $referral->update(['stage' => 'assessment', 'primary_work_item_id' => null]);
    $next = ['recipient_scope' => 'referral_task', 'task_source' => 'referral_next', 'task_id' => $referral->id];
    $check($service->filterRecipientScope(collect([$owner]), $next)->count() === 1, 'An overdue unlinked next action uses its own stable identity');
    $referral->update(['primary_work_item_id' => $work->id]);
    $check($service->filterRecipientScope(collect([$owner]), $next)->isEmpty(), 'Linking a primary work item prevents duplicate next-action reminders');
    NotificationEscalationRule::create(['event_key' => 'qa.referral_overdue', 'enabled' => true, 'require_ack' => false, 'force_delivery' => true,
        'remind_after_minutes' => 1, 'repeat_every_minutes' => 1, 'max_reminders' => 1, 'escalate_to_role_groups' => ['managers'], 'tiers' => []]);
    $reminderPayload = [...$payload, 'event_key' => 'qa.referral_overdue'];
    $notice = function ($data, $id = null) use ($owner) {
        return DatabaseNotification::create(['id' => $id ?? (string) Str::uuid(), 'type' => AppEventNotification::class,
            'notifiable_type' => User::class, 'notifiable_id' => $owner->id, 'data' => $data, 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
    };
    $notice($reminderPayload);
    $check(Artisan::call('notifications:escalate') === 0, 'Real delayed-reminder command accepts the scoped notice');
    $check(Notification::sent($allowed, AppEventNotification::class)->count() === 2 && Notification::sent($owner, AppEventNotification::class)->count() === 1
        && Notification::sent($denied, AppEventNotification::class)->isEmpty() && Notification::sent($foreign, AppEventNotification::class)->isEmpty(),
        'Forced reminder delivery cannot bypass revoked permission, funding audience or site scope');
    Artisan::call('notifications:escalate');
    $check(Notification::sent($allowed, AppEventNotification::class)->count() === 2, 'The configured reminder limit prevents repeated sends');
    $work->update(['status' => 'completed']);
    $notice($reminderPayload);
    $legacy = $reminderPayload;
    unset($legacy['recipient_scope'],$legacy['task_source'],$legacy['task_id']);
    $legacy['title'] = 'Secret fictional historical notification';
    $notice($legacy);
    Artisan::call('notifications:escalate');
    $check(Notification::sent($allowed, AppEventNotification::class)->count() === 2 && Notification::sent($denied, AppEventNotification::class)->isEmpty(),
        'Actual reminder processing withholds completed work and unscoped historical notices');
    $settings = app(ReferralSettings::class);
    $configuration = $version->configuration;
    $configuration['follow_up'] = ['manager_after_days' => 1, 'day_basis' => 'business', 'office_hours_only' => true];
    $configuration['business_calendar'] = ['timezone' => 'Pacific/Auckland', 'weekdays' => [1, 2, 3, 4, 5], 'holidays' => [], 'opening_time' => '09:00', 'closing_time' => '17:00'];
    $check($settings->requiresGovernance($version->configuration, $configuration), 'Changing escalation timing requires governance review');
    $invalid = $configuration;
    $invalid['business_calendar']['opening_time'] = null;
    try {
        $settings->validate($invalid);
        throw new RuntimeException('Unknown office hours accepted');
    } catch (ValidationException) {
        $checks[] = 'Working-day escalation requires known opening hours';
    }
    $invalid = $configuration;
    $invalid['follow_up']['manager_after_days'] = 0;
    try {
        $settings->validate($invalid);
        throw new RuntimeException('Zero escalation delay accepted');
    } catch (ValidationException) {
        $checks[] = 'Invalid escalation thresholds are rejected';
    }
    $author = User::where('email', 'author@referrals-preview.test')->sole();
    $reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
    $draft = $settings->draft($author, $configuration, $version->id, 'Fictional rollback-only working reminder policy');
    $published = $settings->publish($reviewer, $draft->id, now()->toISOString());
    $policy = app(ReferralFollowUpPolicy::class);
    $check(($policy->configuration('referral_work', $work->id)['follow_up'] ?? ReferralFollowUpPolicy::defaults()) === ReferralFollowUpPolicy::defaults(), 'Publishing a new policy does not rewrite the existing case pin');
    $referral->update(['settings_version_id' => $published->id]);
    $check($policy->configuration('referral_work', $work->id)['follow_up'] === $configuration['follow_up'], 'Reminder delivery reads the specific case policy rather than the global current publication');
    $owner->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'referrals.funding.view')->sole()->id => ['allowed' => true]]);
    $work->update(['status' => 'open', 'due_at' => CarbonImmutable::parse('2026-10-16 16:00', 'Pacific/Auckland')]);
    DB::table('task_escalations')->where('source', 'referral_work')->where('item_id', $work->id)->delete();
    Notification::fake();
    $clock = function ($local) {
        $at = CarbonImmutable::parse($local, 'Pacific/Auckland');
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    };
    $clock('2026-10-19 08:00');
    Artisan::call('tasks:escalate');
    $check(Notification::sent($owner, AppEventNotification::class)->isEmpty() && Notification::sent($allowed, AppEventNotification::class)->isEmpty(), 'Outside opening hours the actual sweep leaves reminders pending');
    $clock('2026-10-19 09:00');
    Artisan::call('tasks:escalate');
    $check(Notification::sent($owner, AppEventNotification::class)->count() === 1 && Notification::sent($allowed, AppEventNotification::class)->isEmpty(), 'At opening the assignee is nudged without prematurely escalating to managers');
    $clock('2026-10-19 15:59');
    Artisan::call('tasks:escalate');
    $check(Notification::sent($allowed, AppEventNotification::class)->isEmpty(), 'Weekend time does not satisfy the configured working-day threshold');
    $clock('2026-10-19 16:00');
    Artisan::call('tasks:escalate');
    $check(Notification::sent($allowed, AppEventNotification::class)->count() === 1, 'Manager escalation occurs after the full configured working-day duration');
    $clock('2026-10-19 17:00');
    $check($service->filterRecipientScope($recipients, $payload)->isEmpty(), 'Delayed reminders honor the same published opening-hours window');
    $deferred = $notice($reminderPayload);
    Artisan::call('notifications:escalate');
    $check((int) $deferred->fresh()->escalation_count === 0 && $deferred->fresh()->last_escalated_at === null, 'Waiting for opening hours does not consume the delayed-reminder budget');
    $quietIds = [];
    for ($i = 0; $i < 201; $i++) {
        $quietIds[] = $notice($reminderPayload)->id;
    }
    $general = $reminderPayload;
    unset($general['recipient_scope'], $general['task_source'], $general['task_id']);
    $general['context'] = ['Module' => 'Fictional general workflow'];
    $other = $notice($general, 'ffffffff-ffff-4fff-bfff-ffffffffffff');
    Artisan::call('notifications:escalate');
    $check((int) $other->fresh()->escalation_count === 1 && (int) $deferred->fresh()->escalation_count === 0,
        'More than one batch of quiet-hour notices cannot block another due workflow reminder');
    DatabaseNotification::whereIn('id', $quietIds)->delete();
    $beforeOpening = Notification::sent($allowed, AppEventNotification::class)->count();
    $clock('2026-10-20 09:00');
    Artisan::call('notifications:escalate');
    $check((int) $deferred->fresh()->escalation_count === 1 && Notification::sent($allowed, AppEventNotification::class)->count() === $beforeOpening + 1, 'The deferred reminder is delivered at the next opening window');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    DB::rollBack();
}
