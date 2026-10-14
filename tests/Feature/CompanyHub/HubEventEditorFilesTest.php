<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $role = Role::where('name', 'hr')->firstOrFail();
    $role->permissions()->syncWithoutDetaching(Permission::where('key', 'calendar.manage_recurring')->pluck('id'));
    $this->editor->roles()->attach($role);
    ensureCanonicalHrStaffProfile($this->editor);
    $this->event = HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => 'Private editor check', 'event_type' => 'company',
        'starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(11, 0), 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'none']);
    $this->url = '/hr/calendar/events/'.$this->event->id;
    $this->date = $this->event->starts_at->toDateString();
    Storage::fake('private');
    Notification::fake();
    $this->actingAs($this->editor);
});

test('a single-date multipart save binds files to its actual child and rejects removing inherited or unrelated files', function () {
    $rootFile = $this->post($this->url.'/attachments', ['edit_version' => 1, 'file' => UploadedFile::fake()->createWithContent('series.txt', 'Shared by every date')])->assertOk()->json('attachment.id');
    $this->post($this->url, ['_method' => 'put', 'edit_version' => 2, 'scope' => 'this', 'occurrence_date' => $this->date,
        'title' => 'One date only', 'reminders' => [['offset_minutes' => 60, 'channel' => 'notification']], 'files' => [UploadedFile::fake()->createWithContent('date.txt', 'This date only')]])->assertRedirect()->assertSessionHasNoErrors();
    $child = HrCalendarEvent::where('recurrence_parent_id', $this->event->id)->sole();
    $file = $child->attachments()->sole();
    expect($this->event->attachments()->count())->toBe(1)->and($file->original_name)->toBe('date.txt')->and((int) $this->event->fresh()->edit_version)->toBe(3);
    Storage::disk('private')->assertExists($file->path);
    $feed = app(HrCalendarAggregator::class)->feed($this->date, $this->date, ['event'], [], $this->editor);
    $inherited = collect($feed[0]['extendedProps']['attachments'])->firstWhere('id', $rootFile);
    expect($inherited['inherited'])->toBeTrue()->and($inherited['eventId'])->toBe($this->event->id);
    $childUrl = '/hr/calendar/events/'.$child->id;
    $this->putJson($childUrl, ['edit_version' => 3, 'removed_attachment_ids' => [$rootFile]])->assertNotFound();
    $this->putJson($this->url, ['edit_version' => 3, 'scope' => 'this', 'occurrence_date' => $this->date, 'removed_attachment_ids' => [$rootFile]])->assertUnprocessable();
    $this->deleteJson('/hr/calendar/attachments/'.$rootFile, ['event_id' => $child->id, 'edit_version' => 3])->assertNotFound();
    $this->put($childUrl, ['edit_version' => 3, 'replace_reminders' => true, 'removed_attachment_ids' => [$file->id]])->assertRedirect()->assertSessionHasNoErrors();
    expect($child->attachments()->count())->toBe(0)->and($this->event->attachments()->count())->toBe(1)->and($child->reminders()->count())->toBe(0);
    $this->delete($childUrl, ['edit_version' => 4, 'scope' => 'all'])->assertRedirect()->assertSessionHasNoErrors();
    expect(app(HrCalendarAggregator::class)->feed($this->date, $this->date, ['event'], [], $this->editor))->toBe([]);
    $this->post($childUrl.'/restore')->assertRedirect()->assertSessionHasNoErrors();
    expect(app(HrCalendarAggregator::class)->feed($this->date, $this->date, ['event'], [], $this->editor))->toHaveCount(1);
});

test('following-date file edits leave earlier files intact and copy retained files independently', function () {
    $this->put($this->url, ['edit_version' => 1, 'files' => [UploadedFile::fake()->createWithContent('keep.txt', 'Keep'), UploadedFile::fake()->createWithContent('earlier.txt', 'Earlier')]])->assertRedirect()->assertSessionHasNoErrors();
    $files = $this->event->attachments()->orderBy('id')->get();
    $split = $this->event->starts_at->copy()->addWeek()->toDateString();
    $response = $this->post($this->url, ['_method' => 'put', 'edit_version' => 2, 'scope' => 'following', 'occurrence_date' => $split,
        'removed_attachment_ids' => [$files[1]->id], 'files' => [UploadedFile::fake()->createWithContent('later.txt', 'Later')]]);
    $response->assertRedirect()->assertSessionHasNoErrors();
    $next = HrCalendarEvent::whereKeyNot($this->event->id)->whereNull('recurrence_parent_id')->sole();
    $response->assertSessionHas('savedEventId', $next->id);
    expect($this->event->attachments()->count())->toBe(2)->and($next->attachments()->pluck('original_name')->sort()->values()->all())->toBe(['keep.txt', 'later.txt']);
    $copy = $next->attachments()->where('original_name', 'keep.txt')->sole();
    expect($copy->path)->not->toBe($files[0]->path)->and((int) $next->edit_version)->toBe(3);
    Storage::disk('private')->assertExists([$copy->path, $files[0]->path, $files[1]->path]);
    $this->putJson('/hr/calendar/events/'.$next->id, ['edit_version' => 3, 'removed_attachment_ids' => [$files[0]->id]])->assertNotFound();
});

test('stale metadata and file windows retain newer changes and cannot archive a different edit scope', function () {
    $this->putJson($this->url, ['title' => 'Missing version'])->assertUnprocessable();
    $this->put($this->url, ['edit_version' => 1, 'title' => 'Newer title'])->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson($this->url.'/attachments', ['edit_version' => 1, 'file' => UploadedFile::fake()->createWithContent('stale.txt', 'Stale')])->assertUnprocessable()->assertJsonValidationErrors('edit_version');
    $this->putJson($this->url, ['edit_version' => 1, 'title' => 'Old title'])->assertUnprocessable()->assertJsonValidationErrors('edit_version');
    $this->deleteJson($this->url, ['edit_version' => 2, 'scope' => 'this'])->assertUnprocessable();
    expect($this->event->fresh()->title)->toBe('Newer title')->and($this->event->fresh()->archived_at)->toBeNull()->and(Storage::disk('private')->allFiles())->toBe([]);
});

test('a failed second file rolls back event metadata, new files and a newly created exception', function () {
    Event::listen('eloquent.creating: '.HrCalendarEventAttachment::class, function ($file) {
        if ($file->original_name === 'fail.txt') {
            throw new RuntimeException('Simulated attachment record failure');
        }
    });
    $this->withoutExceptionHandling();
    try {
        $this->put($this->url, ['edit_version' => 1, 'scope' => 'this', 'occurrence_date' => $this->date, 'title' => 'Unsaved title',
            'files' => [UploadedFile::fake()->createWithContent('first.txt', 'First'), UploadedFile::fake()->createWithContent('fail.txt', 'Fail')]]);
        test()->fail('The simulated write failure must escape the transaction.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated attachment record failure');
    } finally {
        Event::forget('eloquent.creating: '.HrCalendarEventAttachment::class);
    }
    expect($this->event->fresh()->title)->toBe('Private editor check')->and((int) $this->event->fresh()->edit_version)->toBe(1)
        ->and(HrCalendarEvent::where('recurrence_parent_id', $this->event->id)->count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
});

test('an invalid staged file prevents creation and no success receipt is issued', function () {
    $this->postJson('/hr/calendar/events', ['title' => 'Not saved', 'event_type' => 'company', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        'files' => [UploadedFile::fake()->createWithContent('unsafe.html', '<script>alert(1)</script>')]])->assertUnprocessable()->assertJsonValidationErrors('files.0');
    expect(HrCalendarEvent::where('title', 'Not saved')->exists())->toBeFalse()->and(Storage::disk('private')->allFiles())->toBe([]);
});
