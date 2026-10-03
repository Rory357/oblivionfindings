<?php

namespace App\Services\Medication\Reporting;

use App\Models\AppSetting;
use App\Models\MedicationError;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\CarbonImmutable;

/** Medication governance measures share the reports' current person/Site scope. */
final class MedicationGovernanceReports
{
    public const TARGET_KEY = 'medications.reports.governance.reached_target';

    public function target(): ?int
    {
        $value = AppSetting::query()->where('key', self::TARGET_KEY)->first()?->value;

        return is_array($value) && isset($value['target']) && is_int($value['target']) && $value['target'] >= 0 ? $value['target'] : null;
    }

    public function status(int $count): string
    {
        $target = $this->target();

        return $target === null ? 'not_configured' : ($count <= $target ? 'normal' : 'warning');
    }

    public function values(?User $actor, string $from, string $to, ?int $siteId = null): array
    {
        if (! $actor?->canDo('medications.reports.view') || app(MedicationReportAccess::class)->financeOnly($actor)) {
            return array_fill_keys(['HCG-001', 'HCG-005'], ['value' => null, 'status' => 'no_access', 'recorded' => false, 'source_href' => null]);
        }
        $access = app(MedicationReportAccess::class);
        $sites = $access->siteIds($actor, $siteId);
        $period = new MedicationReportPeriod($from, $to);
        $data = app(MedicationReportDataset::class)->read($actor, 'errors', $period, $sites);
        $used = app(MedicationReportDataset::class)->errorQuery($actor, $sites)->where('status', '!=', 'in_error')->exists();
        $base = '/emar/reports?'.http_build_query(['report' => 'errors', 'period' => 'custom', 'date_from' => $from, 'date_to' => $to] + ($siteId ? ['site_id' => $siteId] : []));

        return ['HCG-001' => ['value' => $data['totals']['reached'], 'status' => $this->status($data['totals']['reached']), 'recorded' => $used, 'source_href' => $base.'&reached=yes'], 'HCG-005' => ['value' => $data['totals']['near_misses'], 'status' => 'reported', 'recorded' => $used, 'source_href' => $base.'&reached=no']];
    }

    /** Organisation snapshot writer; reader-facing values are recomputed above. */
    public function count(CarbonImmutable $start, CarbonImmutable $end, string $reached): int
    {
        return app(MedicationGovernanceScopeService::class)->scopeCanonicalClientMedicationRows(MedicationError::query(), null, true)->where('status', '!=', 'in_error')->where('reached_client', $reached)->whereRaw('COALESCE(occurred_at, reported_at) BETWEEN ? AND ?', [$start->startOfDay()->utc(), $end->endOfDay()->utc()])->count();
    }
}
