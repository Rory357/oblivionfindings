<?php

namespace Tests\Feature\Governance;

use App\Domain\Finance\Services\BudgetActualsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class UpdateBudgetVariancesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_variance_command_syncs_actuals_before_recalculating(): void
    {
        $this->mock(BudgetActualsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('syncActuals')
                ->once()
                ->with(null);
        });

        $this->artisan('governance:update-budget-variances')
            ->expectsOutput('Updating budget variances...')
            ->expectsOutput('Budget variance updates complete.')
            ->assertSuccessful();
    }
}
