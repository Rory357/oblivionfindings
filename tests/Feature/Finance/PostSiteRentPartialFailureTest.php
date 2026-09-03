<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Jobs\PostSiteRentJob;
use App\Domain\Finance\Services\FinancialEventService;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PostSiteRentPartialFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_completes_normally_when_all_sites_succeed(): void
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'rent_amount' => 1500.00,
            'rent_frequency' => 'monthly',
            'tenant_id' => 1,
        ]);

        $mockService = $this->createMock(FinancialEventService::class);
        $mockService->expects($this->once())
            ->method('record');

        $job = new PostSiteRentJob('2026-08');
        $job->handle($mockService);

        $this->assertTrue(true);
    }

    public function test_job_throws_runtime_exception_when_site_posting_fails(): void
    {
        $site1 = Site::factory()->create([
            'name' => 'Site Success',
            'is_active' => true,
            'rent_amount' => 1200.00,
            'rent_frequency' => 'monthly',
            'tenant_id' => 1,
        ]);

        $site2 = Site::factory()->create([
            'name' => 'Site Failing',
            'is_active' => true,
            'rent_amount' => 1800.00,
            'rent_frequency' => 'monthly',
            'tenant_id' => 1,
        ]);

        $mockService = $this->createMock(FinancialEventService::class);
        $mockService->expects($this->exactly(2))
            ->method('record')
            ->willReturnCallback(function (array $data) {
                if ($data['description'] && str_contains($data['description'], 'Site Failing')) {
                    throw new \Exception('Ledger connection refused');
                }
            });

        $job = new PostSiteRentJob('2026-08');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PostSiteRentJob encountered partial failures');
        $this->expectExceptionMessage('Site Failing');

        $job->handle($mockService);
    }
}
