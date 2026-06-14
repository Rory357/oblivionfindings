<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinIrdFiling;
use App\Domain\Finance\Services\IrdFilingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IrdFilingStatusFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_gst_filing_moves_from_draft_to_validated_to_submitted(): void
    {
        // Live IRD submission isn't wired; an explicit simulation lets the status
        // flow reach 'submitted' without faking a real transmission.
        config(['services.ird.simulation_enabled' => true]);

        $user = User::factory()->create(['organization_id' => 1]);
        $this->actingAs($user);

        $gstReturn = FinGstReturn::factory()->create([
            'organization_id' => 1,
            'period_start' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'filing_frequency' => 'two_monthly',
            'basis' => 'invoice',
            'total_sales' => 1150,
            'total_gst_collected' => 150,
            'total_purchases' => 230,
            'total_gst_paid' => 30,
            'gst_payable' => 120,
            'status' => 'filed',
            'ird_period' => now()->subMonth()->format('Ym'),
            'created_by' => $user->id,
        ]);

        $service = app(IrdFilingService::class);

        $filing = $service->createGstFiling(1, $gstReturn, '49091850');

        $this->assertInstanceOf(FinIrdFiling::class, $filing);
        $this->assertSame('draft', $filing->status);
        $this->assertSame('gst', $filing->filing_type);
        $this->assertSame($gstReturn->id, $filing->gst_return_id);

        $this->assertSame([], $service->validateFiling($filing));
        $this->assertSame('validated', $filing->refresh()->status);

        $submitted = $service->submitFiling($filing);

        $this->assertSame('submitted', $submitted->status);
        $this->assertNotNull($submitted->submitted_at);
        $this->assertStringStartsWith('SIM-', $submitted->ird_reference);
        $this->assertSame('simulated', $submitted->ird_response['status']);
        $this->assertTrue($submitted->ird_response['simulated']);
        $this->assertNull($submitted->error_message);
    }

    public function test_invalid_ird_number_keeps_filing_in_draft(): void
    {
        $user = User::factory()->create(['organization_id' => 1]);
        $this->actingAs($user);

        $gstReturn = FinGstReturn::factory()->create([
            'organization_id' => 1,
            'period_start' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'total_gst_collected' => 150,
            'total_gst_paid' => 30,
            'gst_payable' => 120,
            'created_by' => $user->id,
        ]);

        $service = app(IrdFilingService::class);
        $filing = $service->createGstFiling(1, $gstReturn, '12345678');

        $errors = $service->validateFiling($filing);

        $this->assertNotEmpty($errors);
        $this->assertSame('draft', $filing->refresh()->status);
    }
}
