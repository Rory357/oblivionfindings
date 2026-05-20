<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteTypePlan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class SiteTypePlanPdfService
{
    public function __construct(
        private readonly SiteEmergencyPlanService $emergencyPlans,
    ) {}

    public function download(Site $site, SiteTypePlan $plan, string $paper = 'a4'): Response
    {
        $paper = strtolower($paper);
        abort_unless(in_array($paper, ['a3', 'a4', 'a5'], true), 422, 'Unsupported paper size.');

        $model = $this->emergencyPlans->viewModel($site, $plan);
        abort_unless($model['ready'], 409, 'Emergency plan needs an assembly point and at least one exit before export.');

        $canvas = $plan->layout['canvas'] ?? [];
        $storedOrientation = data_get($plan->layout, 'export.orientation');
        $orientation = in_array($storedOrientation, ['landscape', 'portrait'], true)
            ? $storedOrientation
            : ((($canvas['width'] ?? 1000) >= ($canvas['height'] ?? 700)) ? 'landscape' : 'portrait');
        if ($paper === 'a5') {
            $orientation = 'portrait';
        }

        $filename = Str::slug($site->name)."-emergency-plan-{$paper}.pdf";
        $model['paper'] = [
            'size' => $paper,
            'orientation' => $orientation,
        ];

        return Pdf::loadView('pdf.site-type-plan.emergency', $model)
            ->setPaper(strtoupper($paper), $orientation)
            ->download($filename);
    }
}
