<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\News2Band;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Clinical\ClinicalWatchDigestNotification;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClinicalDeteriorationRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
    }

    protected function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        if ($role = Role::where('name', $roleName)->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    private function onWatchVitals(Client $client, User $recorder): void
    {
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $client->id,
            'recorded_by' => $recorder->id,
            'recorded_at' => now(),
            'news2_score' => 6,
            'news2_band' => News2Band::Medium,
        ]);
    }

    public function test_digest_is_sent_to_oversight_staff_when_a_client_is_on_watch(): void
    {
        Notification::fake();

        $lead = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create();
        $this->onWatchVitals($client, $lead);

        $this->artisan('clinical:deterioration-reminders')->assertSuccessful();

        Notification::assertSentTo(
            $lead,
            ClinicalWatchDigestNotification::class,
            fn (ClinicalWatchDigestNotification $n) => $n->clientsOnWatch === 1,
        );
    }

    public function test_no_digest_when_nothing_on_watch_and_no_overdue(): void
    {
        Notification::fake();

        $this->createUserWithRole('clinical_lead');

        $this->artisan('clinical:deterioration-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_support_worker_is_not_an_oversight_recipient(): void
    {
        Notification::fake();

        $worker = $this->createUserWithRole('support_worker');
        $client = Client::factory()->create();
        $this->onWatchVitals($client, $worker);

        $this->artisan('clinical:deterioration-reminders')->assertSuccessful();

        Notification::assertNotSentTo($worker, ClinicalWatchDigestNotification::class);
    }
}
