<?php

namespace Tests\Unit\Services;

use App\Models\Shift;
use App\Services\ShiftStateGuardService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftStateGuardServiceTest extends TestCase
{
    protected ShiftStateGuardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShiftStateGuardService::class);
    }

    public function test_normalize_planning_status_allows_valid_planning_statuses(): void
    {
        $this->assertSame('draft', $this->service->normalizePlanningStatus('draft', false));
        $this->assertSame('scheduled', $this->service->normalizePlanningStatus('scheduled', true));
    }

    public function test_normalize_planning_status_rejects_live_lifecycle_statuses(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->normalizePlanningStatus('completed', true);
    }

    public function test_normalize_planning_status_downgrades_unassigned_scheduled_request_to_draft(): void
    {
        $this->assertSame('draft', $this->service->normalizePlanningStatus('scheduled', false));
    }

    public function test_invalid_transition_from_planning_edit_fails(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->assertEditableFromPlanning(
            new Shift(['status' => 'scheduled']),
            'completed',
        );
    }

    public function test_completed_shift_is_not_editable_from_planning_even_without_status_change(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->assertEditableFromPlanning(
            new Shift(['status' => 'completed']),
            'completed',
        );
    }

    public function test_cancelled_shift_is_not_editable_from_planning(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->assertEditableFromPlanning(
            new Shift(['status' => 'cancelled']),
            null,
        );
    }
}
