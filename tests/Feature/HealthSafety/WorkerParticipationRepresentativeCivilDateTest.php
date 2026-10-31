<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsRepresentative;
use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/** Actual create/update validation at NZ day boundaries; native execution UNRUN. */
final class WorkerParticipationRepresentativeCivilDateTest extends RestraintEventCommandTestCase
{
    private User $representativeUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->actor = $this->staff(['hazards.view', 'hazards.manage']);
        $this->representativeUser = $this->staff(['hazards.view']);
    }

    public static function civilBoundaries(): array
    {
        return [
            ['2026-10-12T12:30:00Z', '2026-10-13', '2029-10-13'],
            ['2026-07-15T12:30:00Z', '2026-07-16', '2029-07-16'],
        ];
    }

    private function values(string $today, string $term): array
    {
        return ['user_id' => $this->representativeUser->id, 'site_id' => $this->site->id,
            'election_method' => 'elected', 'elected_at' => '2026-01-01',
            'training_days_completed' => 2, 'initial_training_completed_at' => $today,
            'term_expires_at' => $term];
    }

    #[DataProvider('civilBoundaries')]
    public function test_create_accepts_current_worker_training_day_and_three_year_worker_bound(string $instant, string $today, string $term): void
    {
        $this->travelTo(Carbon::parse($instant));
        $this->actingAs($this->actor)->post('/health-safety/worker-participation/representatives', $this->values($today, $term))->assertRedirect()->assertSessionHasNoErrors();
        $record = HsRepresentative::where('user_id', $this->representativeUser->id)->sole();
        $this->assertSame($today, $record->initial_training_completed_at->toDateString());
        $this->assertSame($term, $record->term_expires_at->toDateString());
    }

    #[DataProvider('civilBoundaries')]
    public function test_update_uses_the_same_worker_civil_bounds(string $instant, string $today, string $term): void
    {
        $this->travelTo(Carbon::parse($instant));
        $record = HsRepresentative::create([...$this->values('2026-01-01', '2027-01-01'), 'status' => 'active', 'created_by' => $this->actor->id]);
        $this->actingAs($this->actor)->put('/health-safety/worker-participation/representatives/'.$record->id, ['initial_training_completed_at' => $today, 'term_expires_at' => $term])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($today, $record->fresh()->initial_training_completed_at->toDateString());
        $this->assertSame($term, $record->fresh()->term_expires_at->toDateString());
    }

    public static function futureOperands(): array
    {
        return [
            ['create', 'initial_training_completed_at', '2026-10-14'],
            ['update', 'initial_training_completed_at', '2026-10-14'],
            ['create', 'term_expires_at', '2029-10-14'],
            ['update', 'term_expires_at', '2029-10-14'],
        ];
    }

    #[DataProvider('futureOperands')]
    public function test_next_worker_day_beyond_each_bound_is_rejected_without_changing_record(string $command, string $field, string $value): void
    {
        $this->travelTo(Carbon::parse('2026-10-12T12:30:00Z'));
        $payload = $this->values('2026-10-13', '2029-10-13');
        if ($command === 'create') {
            $this->actingAs($this->actor)->postJson('/health-safety/worker-participation/representatives', [...$payload, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertFalse(HsRepresentative::where('user_id', $this->representativeUser->id)->exists());
        } else {
            $record = HsRepresentative::create([...$this->values('2026-01-01', '2027-01-01'), 'status' => 'active', 'created_by' => $this->actor->id]);
            $this->actingAs($this->actor)->putJson('/health-safety/worker-participation/representatives/'.$record->id, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame('2026-01-01', $record->fresh()->initial_training_completed_at->toDateString());
            $this->assertSame('2027-01-01', $record->fresh()->term_expires_at->toDateString());
        }
    }
}
