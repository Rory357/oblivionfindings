<?php

use App\Domain\Hr\Jobs\PublishDueAnnouncementsJob;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrAnnouncementAcknowledgement;
use App\Domain\Hr\Services\AnnouncementAudienceResolver;
use App\Domain\Hr\Services\AnnouncementInboxBridge;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2027-01-31 10:00:00', 'Pacific/Auckland')->utc());
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    Notification::fake();
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    $site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->reader, $site);
    $this->payload = ['title' => 'Repeating announcement', 'content' => 'Original message', 'priority' => 'normal', 'intent' => 'publish', 'targets' => [['type' => 'user', 'value' => (string) $this->reader->id]], 'push_to_bell' => false, 'requires_acknowledgement' => true, 'recurrence' => 'weekly', 'expires_at' => '2027-02-01T23:59:59+13:00', 'ack_deadline' => '2027-02-01T23:59:59+13:00'];
    $this->actingAs($this->editor)->post('/hr/announcements', $this->payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->root = HrAnnouncement::whereNull('recurrence_source_id')->where('title', $this->payload['title'])->sole();
    $this->next = HrAnnouncement::where('recurrence_source_id', $this->root->id)->sole();
    $this->edit = function (HrAnnouncement $notice, array $changes = []) {
        $notice = $notice->fresh();

        return $this->actingAs($this->editor)->put('/hr/announcements/'.$notice->id, [
            ...$this->payload, ...$notice->only(['title', 'content', 'priority', 'requires_acknowledgement', 'push_to_bell', 'recurrence']),
            'intent' => $notice->status === 'scheduled' ? 'schedule' : 'publish',
            'published_at' => $notice->status === 'scheduled' ? $notice->published_at->toIso8601String() : null,
            'expires_at' => $notice->expires_at?->toIso8601String(), 'ack_deadline' => $notice->ack_deadline?->toIso8601String(),
            'recurrence_ends_at' => $notice->recurrence_ends_at?->toIso8601String(), ...$changes,
        ]);
    };
    $this->dispatchDue = fn () => (new PublishDueAnnouncementsJob)->handle(app(AnnouncementAudienceResolver::class), app(AnnouncementInboxBridge::class));
});

test('stopping and restarting reuses the next occurrence and preserves messages and historical receipts', function () {
    HrAnnouncementAcknowledgement::create(['announcement_id' => $this->root->id, 'user_id' => $this->reader->id, 'acknowledged_at' => now()]);
    $this->next->update(['content' => 'Separately edited upcoming message']);
    ($this->edit)($this->root, ['recurrence' => null])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->next->fresh()->status)->toBe('archived')->and($this->next->fresh()->recurrence_cancelled_at)->not->toBeNull()
        ->and($this->root->acknowledgements()->count())->toBe(1);
    $this->travelTo(Carbon::parse('2027-03-05 10:00:00', 'Pacific/Auckland')->utc());
    Notification::fake();
    ($this->dispatchDue)();
    Notification::assertNothingSent();
    ($this->edit)($this->root, ['recurrence' => 'weekly'])->assertRedirect()->assertSessionHasNoErrors();
    $next = $this->next->fresh();
    expect($next->status)->toBe('scheduled')->and($next->recurrence_cancelled_at)->toBeNull()
        ->and($next->content)->toBe('Separately edited upcoming message')
        ->and($next->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-03-07 10:00')
        ->and(HrAnnouncement::where('recurrence_source_id', $this->root->id)->count())->toBe(1);
    Notification::assertNothingSent();
});

test('changing cadence adjusts dates and deadlines and keeps the original monthly anchor', function () {
    $this->next->update(['content' => 'Keep this future message']);
    ($this->edit)($this->root, ['recurrence' => 'monthly'])->assertRedirect()->assertSessionHasNoErrors();
    $next = $this->next->fresh();
    expect($next->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-02-28 10:00')
        ->and($next->expires_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i:s'))->toBe('2027-03-01 23:59:59')
        ->and($next->ack_deadline->eq($next->expires_at))->toBeTrue()->and($next->content)->toBe('Keep this future message');
    $this->travelTo($next->published_at);
    ($this->dispatchDue)();
    $march = HrAnnouncement::where('recurrence_source_id', $next->id)->sole();
    expect($march->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-03-31 10:00');
    ($this->edit)($this->root, ['recurrence' => null])->assertRedirect()->assertSessionHasNoErrors();
    expect($next->fresh()->status)->toBe('published')->and($march->fresh()->status)->toBe('archived');
});

test('shortening and extending the end date resumes only automatically stopped occurrences', function () {
    ($this->edit)($this->root, ['recurrence_ends_at' => '2027-02-05T23:59:59+13:00'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->next->fresh()->status)->toBe('archived');
    ($this->edit)($this->root, ['recurrence_ends_at' => '2027-03-05T23:59:59+13:00'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->next->fresh()->status)->toBe('scheduled')->and($this->next->fresh()->recurrence_cancelled_at)->toBeNull();
    $this->delete('/hr/announcements/'.$this->next->id)->assertRedirect();
    ($this->edit)($this->root, ['recurrence_ends_at' => '2027-04-05T23:59:59+12:00'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->next->fresh()->status)->toBe('archived')->and($this->next->fresh()->recurrence_cancelled_at)->toBeNull();
});

test('editing a scheduled occurrence establishes its new time and day for following repeats', function () {
    ($this->edit)($this->next, ['published_at' => '2027-04-09T14:00:00+12:00', 'recurrence' => 'monthly', 'expires_at' => '2027-04-10T23:59:59+12:00', 'ack_deadline' => '2027-04-10T23:59:59+12:00'])->assertRedirect()->assertSessionHasNoErrors();
    $this->travelTo($this->next->fresh()->published_at);
    ($this->dispatchDue)();
    $following = HrAnnouncement::where('recurrence_source_id', $this->next->id)->sole();
    expect($following->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-05-09 14:00')
        ->and($this->root->fresh()->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-01-31 10:00');
});

test('drafting and rescheduling a published occurrence pauses its next repeat until publication', function () {
    ($this->edit)($this->root, ['intent' => 'draft', 'published_at' => $this->root->published_at->toIso8601String()])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->next->fresh()->status)->toBe('archived');
    ($this->edit)($this->root, ['intent' => 'schedule', 'published_at' => '2027-02-03T10:00:00+13:00', 'expires_at' => '2027-02-04T23:59:59+13:00', 'ack_deadline' => '2027-02-04T23:59:59+13:00'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->next->fresh()->status)->toBe('archived')
        ->and($this->next->fresh()->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-02-10 10:00')
        ->and($this->next->fresh()->ack_deadline->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-02-11 23:59');
    $this->travelTo($this->root->fresh()->published_at);
    ($this->dispatchDue)();
    expect($this->next->fresh()->status)->toBe('scheduled')
        ->and($this->next->fresh()->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-02-10 10:00');
});

test('explicitly archiving a stopped repeat prevents a parent setting from restarting it', function () {
    ($this->edit)($this->root, ['recurrence' => null])->assertRedirect();
    expect($this->next->fresh()->recurrence_cancelled_at)->not->toBeNull();
    $this->delete('/hr/announcements/'.$this->next->id)->assertRedirect();
    ($this->edit)($this->root, ['recurrence' => 'weekly'])->assertRedirect();
    expect($this->next->fresh()->status)->toBe('archived')->and($this->next->fresh()->recurrence_cancelled_at)->toBeNull();
});

test('extending an end date preserves a separately edited next cadence and time', function () {
    ($this->edit)($this->next, ['published_at' => '2027-04-09T14:00:00+12:00', 'recurrence' => 'monthly', 'expires_at' => '2027-04-10T23:59:59+12:00', 'ack_deadline' => '2027-04-10T23:59:59+12:00'])->assertRedirect()->assertSessionHasNoErrors();
    ($this->edit)($this->root, ['recurrence_ends_at' => '2027-02-05T23:59:59+13:00'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->next->fresh()->status)->toBe('archived');
    ($this->edit)($this->root, ['recurrence_ends_at' => '2027-05-20T23:59:59+12:00'])->assertRedirect()->assertSessionHasNoErrors();
    $next = $this->next->fresh();
    expect($next->status)->toBe('scheduled')->and($next->recurrence)->toBe('monthly')
        ->and($next->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-04-09 14:00');
    $this->travelTo($next->published_at);
    ($this->dispatchDue)();
    expect(HrAnnouncement::where('recurrence_source_id', $next->id)->sole()->published_at->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2027-05-09 14:00');
});
