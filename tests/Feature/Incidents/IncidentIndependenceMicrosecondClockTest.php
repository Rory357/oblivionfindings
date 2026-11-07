<?php

namespace Tests\Feature\Incidents;

use App\Models\HealthSafetySettingChange;
use App\Models\IncidentIndependentHandlingDeclaration as Declaration;
use App\Models\IncidentIndependentHandlingExclusion as Exclusion;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentIndependenceCaptureAccess;
use App\Services\Incidents\IncidentIndependenceIntakeService as Intake;
use App\Services\Incidents\IncidentIndependencePolicySettings as Policy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Live clocks carry microseconds. The policy and intake audits digest recorded_at to the microsecond and the replay
 * re-reads it from the retained rows, so the whole chain must agree at any fraction (including one MySQL would round up).
 */
final class IncidentIndependenceMicrosecondClockTest extends IncidentIndependenceTestCase
{
    public function test_policy_and_intake_replay_at_a_low_microsecond_fraction(): void
    {
        $this->chainAt('2026-10-12T03:00:00.123456Z', '123456');
    }

    public function test_policy_and_intake_replay_at_a_high_microsecond_fraction(): void
    {
        $this->chainAt('2026-10-12T03:00:00.987654Z', '987654');
    }

    private function chainAt(string $instant, string $micros): void
    {
        $this->travelTo(Carbon::parse($instant)->utc());
        $policy = app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Explicit independent-handling policy review.']);
        $this->assertSame($policy, app(Policy::class)->receiptForManager($this->manager, $policy['request_uuid']));
        $this->assertSame($micros, HealthSafetySettingChange::where('command_key', $policy['request_uuid'])->sole()->recorded_at->format('u'));

        $this->travelTo(now()->addSecond());
        $draft = $this->command(['intent' => 'draft', 'expected_policy_revision' => 1]);
        $first = $this->accept($draft);
        $this->assertSame('recorded', $first['action']);
        $this->assertSame($micros, HealthSafetySettingChange::where('command_key', $draft['request_uuid'])->sole()->recorded_at->format('u'));
        $declaration = Declaration::findOrFail($first['declaration_id']);
        $this->assertSame(1, Exclusion::where('declaration_id', $declaration->id)->count());

        $this->assertSame($first, app(Intake::class)->confirmationForKey($this->reporter, $draft['request_uuid']));
        $this->assertSame($first, app(Intake::class)->intake($this->reporter, $draft, fn () => throw new \LogicException('Replay must not write the report again.')));
        $proof = $this->protection($first['incident_id']);
        $this->assertSame('recorded', $proof['state']); $this->assertSame([(int) $this->lead->id], $proof['excluded_user_ids']);

        $this->travelTo(now()->addSecond());
        $submit = [...$draft, 'request_uuid' => (string) Str::uuid(), 'expected_revision' => 1, 'intent' => 'submit'];
        $second = $this->accept($submit);
        $this->assertSame('kept', $second['action']); $this->assertSame($first['declaration_id'], $second['declaration_id']);
        $this->assertSame($second, app(Intake::class)->confirmationForKey($this->reporter, $submit['request_uuid']));
        $this->assertSame($first, app(Intake::class)->confirmationForKey($this->reporter, $draft['request_uuid']));
    }

    private function protection(int $id): array
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($id): array {
            $actor = app(IncidentIndependenceCaptureAccess::class)->current($this->reporter, $reads);
            return app(Intake::class)->protectionInReads($id, $actor, $reads);
        }));
    }
}
