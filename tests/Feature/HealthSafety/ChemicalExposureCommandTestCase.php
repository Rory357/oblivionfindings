<?php

namespace Tests\Feature\HealthSafety;

use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureEvidenceService;
use App\Services\HealthSafety\ChemicalExposureSourceService;
use Illuminate\Support\Str;

abstract class ChemicalExposureCommandTestCase extends ChemicalCommandTestCase
{
    protected User $worker;
    protected User $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allow($this->manager, 'hr.wellbeing.view');
        $this->worker = $this->staff([]);
        $this->reporter = $this->staff(['hazards.create']);
        foreach (['hazards.view', 'hazards.manage', 'hr.wellbeing.view'] as $key) { $this->allow($this->reporter, $key, false); }
    }

    protected function exposureService(): ChemicalExposureSourceService { return app(ChemicalExposureSourceService::class); }
    protected function evidenceService(): ChemicalExposureEvidenceService { return app(ChemicalExposureEvidenceService::class); }
    protected function capture(array $facts = [], ?User $actor = null, ?int $substanceId = null): array
    {
        return $this->exposureService()->create($substanceId ?? $this->catalogue()->id, $actor ?? $this->manager,
            ['idempotency_key' => (string) Str::uuid(), 'user_id' => $this->worker->id, 'site_id' => $this->site->id,
                'exposed_at' => '2026-10-12T00:00:00Z', 'exposure_type' => 'skin_contact', ...$facts]);
    }

    protected function exposure(array $facts = []): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::findOrFail($this->capture($facts)['root_id']);
    }

    protected function privateCommand(SubstanceExposureRecord $root, array $facts = [], ?string $key = null): array
    {
        return ['idempotency_key' => $key ?? (string) Str::uuid(), 'expected_version' => $root->fresh()->source_revision, ...$facts];
    }

    protected function proof(SubstanceExposureRecord $root, ?User $viewer = null): array
    {
        return app(ChemicalExposureCommandAccess::class)->withCurrentViewer($viewer ?? $this->manager,
            fn ($current, $reads, $sites) => $this->exposureService()->sourceProof($root, $current, $reads, $sites));
    }

    protected function capturedQueries(callable $operation): array
    {
        \Illuminate\Support\Facades\DB::enableQueryLog(); \Illuminate\Support\Facades\DB::flushQueryLog();
        try { $result = $operation(); return [$result, \Illuminate\Support\Facades\DB::getQueryLog()]; }
        finally { \Illuminate\Support\Facades\DB::disableQueryLog(); }
    }
}