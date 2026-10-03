<?php

namespace App\Services\Medication\Reporting;

use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MedicationExportAudit
{
    public const PURPOSES = ['care' => 'Care and handover', 'review' => 'Clinical review', 'audit' => 'Audit or inspection', 'incident' => 'Incident review', 'records' => 'Record request', 'other' => 'Something else'];

    public function purpose(Request $request): string
    {
        $data = $request->validate(['purpose' => ['required', Rule::in(array_keys(self::PURPOSES))], 'purpose_detail' => ['required_if:purpose,other', 'nullable', 'string', 'min:3', 'max:500']]);

        return $data['purpose'] === 'other' ? trim($data['purpose_detail']) : self::PURPOSES[$data['purpose']];
    }

    /** Must finish before streaming/file bytes can leave the server. */
    public function record(User $actor, string $type, array $siteIds, MedicationReportPeriod $period, string $purpose, ?int $clientId = null, array $extra = [], ?callable $recheck = null): void
    {
        // Rendering can be expensive. Repeat current grants/Site/person scope
        // immediately before appending and releasing the file.
        app(MedicationExportReleaseGuard::class)->run($actor, $siteIds, $clientId ? [$clientId] : [],
            function (User $current, CurrentAuthorizationReads $reads) use ($type, $siteIds, $clientId) {
                abort_unless(app(MedicationReportAccess::class)->canExport($current, $type), 403);
                $approved = app(MedicationReportAccess::class)->siteIds($current, null, $clientId, $type === 'stock' ? 'stock' : (in_array($type, ['controlled', 'cd_register'], true) ? 'controlled' : 'doses'), $reads);
                abort_if(array_diff($siteIds, $approved) !== [], 404);
            },
            function (User $current) use ($recheck) { if ($recheck !== null) { $recheck($current); } },
            fn (User $current) => app(MedicationEventRecorder::class)->appendMany(array_map(fn ($id) => new MedicationEventData((int) $id, 'export.created', 'report_export', $type, $current->id, CarbonImmutable::now('UTC'), 'Export made — '.$type, ['purpose' => $purpose, 'date_from' => $period->from, 'date_to' => $period->to] + $extra, $clientId, in_array($type, ['controlled', 'cd_register'], true)), $siteIds)));
    }
}
