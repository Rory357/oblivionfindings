<?php

namespace Tests\Feature\Privacy;

use App\Jobs\PrivacyDeadlineRemindersJob;
use App\Models\DataBreachLog;
use App\Models\DataSubjectRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Privacy\PrivacyBreachNotifiedNotification;
use App\Notifications\Privacy\PrivacyDeadlineDigestNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PrivacyDeadlineRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected User $officer; // 'admin' role holds all privacy permissions

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->officer = $this->userWithRole('admin');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->first());

        return $user;
    }

    public function test_job_notifies_officers_about_an_overdue_request(): void
    {
        Notification::fake();

        DataSubjectRequest::factory()->create([
            'status' => 'received',
            'due_date' => now()->subDays(5),
            'extended_due_date' => null,
        ]);

        (new PrivacyDeadlineRemindersJob)->handle();

        Notification::assertSentTo($this->officer, PrivacyDeadlineDigestNotification::class);
    }

    public function test_job_notifies_officers_about_a_breach_awaiting_opc(): void
    {
        Notification::fake();

        DataBreachLog::factory()->create([
            'requires_authority_notification' => true,
            'authority_notified_at' => null,
            'status' => 'under_investigation',
        ]);

        (new PrivacyDeadlineRemindersJob)->handle();

        Notification::assertSentTo($this->officer, PrivacyDeadlineDigestNotification::class);
    }

    public function test_job_notifies_officers_about_a_breach_awaiting_subject_notification(): void
    {
        Notification::fake();

        DataBreachLog::factory()->create([
            'requires_authority_notification' => false,
            'requires_subject_notification' => true,
            'subjects_notified_at' => null,
            'status' => 'under_investigation',
        ]);

        (new PrivacyDeadlineRemindersJob)->handle();

        Notification::assertSentTo($this->officer, PrivacyDeadlineDigestNotification::class);
    }

    public function test_job_sends_nothing_when_no_deadlines_are_outstanding(): void
    {
        Notification::fake();

        // A completed request and a fully-notified breach are not outstanding.
        DataSubjectRequest::factory()->create([
            'status' => 'completed',
            'due_date' => now()->subDays(5),
        ]);
        DataBreachLog::factory()->create([
            'requires_authority_notification' => true,
            'authority_notified_at' => now(),
            'requires_subject_notification' => false,
            'subjects_notified_at' => null,
        ]);

        (new PrivacyDeadlineRemindersJob)->handle();

        Notification::assertNothingSent();
    }

    public function test_notify_opc_dispatches_a_real_notification(): void
    {
        Notification::fake();

        $breach = DataBreachLog::factory()->create([
            'requires_authority_notification' => true,
            'authority_notified_at' => null,
            'status' => 'under_investigation',
        ]);

        $this->actingAs($this->officer)
            ->post("/privacy/breaches/{$breach->id}/notify-opc", [
                'authority_reference' => 'OPC-2026-001',
            ])
            ->assertRedirect();

        $this->assertNotNull($breach->fresh()->authority_notified_at);
        Notification::assertSentTo($this->officer, PrivacyBreachNotifiedNotification::class);
    }

    public function test_notify_subjects_dispatches_a_real_notification(): void
    {
        Notification::fake();

        $breach = DataBreachLog::factory()->create([
            'requires_subject_notification' => true,
            'subjects_notified_at' => null,
            'status' => 'under_investigation',
        ]);

        $this->actingAs($this->officer)
            ->post("/privacy/breaches/{$breach->id}/notify-subjects", [
                'notification_method' => 'email',
            ])
            ->assertRedirect();

        $this->assertNotNull($breach->fresh()->subjects_notified_at);
        Notification::assertSentTo($this->officer, PrivacyBreachNotifiedNotification::class);
    }
}
