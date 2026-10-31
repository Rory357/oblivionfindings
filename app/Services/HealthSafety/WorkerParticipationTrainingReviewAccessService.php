<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use WeakMap;

/** Explicit positive scope at consumers; no global model scope or code-prefix policy. */
final class WorkerParticipationTrainingReviewAccessService
{
    private WeakMap $frames;

    public function __construct(private readonly WorkerParticipationAccessService $participation)
    {
        $this->frames = new WeakMap;
    }

    public function materialize(User $viewer, string $capability, callable $operation): array
    {
        if (! in_array($capability, ['governance.compliance.view', 'governance.compliance.manage'], true)) {
            throw new LogicException('Annual reviews require an existing Governance compliance capability.');
        }
        $keys = array_values(array_unique([...WorkerParticipationAccessService::PERMISSIONS, $capability,
            'governance.compliance.view', 'governance.compliance.manage']));

        return HealthSafetyReadContext::within($viewer, $keys, function ($context) use ($viewer, $capability, $keys, $operation): array {
            return $context->consumeSourceViewer($keys, function (User $current, $reads) use ($viewer, $context, $capability, $operation): array {
                abort_unless($current->canDo($capability), 403);
                $hasSource = $current->approved_at !== null && ! $current->isExternalClinicianAccount()
                    && $current->canDo('hazards.view') && $current->canDo('governance.compliance.view')
                    && $reads->query(User::query()->staff())->whereKey($current->id)->exists();
                $run = function () use ($context, $current, $operation, $hasSource): array {
                    $this->frames[$context] = $hasSource;
                    try { return $operation($this, $context, $current); }
                    finally { unset($this->frames[$context]); }
                };

                return $hasSource ? $this->participation->materialize($viewer, fn () => $run(), $context) : $run();
            });
        });
    }

    /** Actual body/count SQL, usable only in its original collector. */
    public function obligations(HealthSafetyReadContext $context): Builder
    {
        $context->assertActive();
        if (! isset($this->frames[$context])) { throw new LogicException('Annual review queries require their original live collector.'); }
        $query = ComplianceObligation::query();
        if (! Schema::hasTable('hs_training_review_sources')) { return $context->query($query); }
        $bridge = DB::table('hs_training_review_sources')->whereColumn('hs_training_review_sources.compliance_obligation_id', 'compliance_obligations.id');
        $query->where(function (Builder $admitted) use ($bridge, $context): void {
            $admitted->whereNotExists((clone $bridge)->selectRaw('1'));
            if ($this->frames[$context]) {
                $source = $this->participation->query('representatives', $context)
                    ->whereColumn('hs_representatives.id', 'hs_training_review_sources.hs_representative_id')
                    ->where(function (Builder $site): void {
                        $site->whereColumn('hs_representatives.site_id', 'hs_training_review_sources.site_id')
                            ->orWhere(fn (Builder $unknown) => $unknown->whereNull('hs_representatives.site_id')->whereNull('hs_training_review_sources.site_id'));
                    });
                $case = DB::table('hs_training_review_cases')->whereColumn('hs_training_review_cases.id', 'hs_training_review_sources.case_id')
                    ->whereColumn('hs_training_review_cases.hs_representative_id', 'hs_training_review_sources.hs_representative_id')
                    ->whereColumn('hs_training_review_cases.root_obligation_id', 'hs_training_review_sources.root_obligation_id')
                    ->whereColumn('hs_training_review_cases.anniversary_date', 'hs_training_review_sources.anniversary_date')
                    ->where(fn ($site) => $site->whereColumn('hs_training_review_cases.site_id', 'hs_training_review_sources.site_id')
                        ->orWhere(fn ($unknown) => $unknown->whereNull('hs_training_review_cases.site_id')->whereNull('hs_training_review_sources.site_id')));
                $admitted->orWhereExists((clone $bridge)->whereExists($source->selectRaw('1')->toBase())->whereExists($case->selectRaw('1'))->selectRaw('1'));
            }
        });

        return $context->query($query);
    }

    public function allows(User $viewer, int $obligationId, string $capability): bool
    {
        return $this->materialize($viewer, $capability,
            fn ($scope, $context): array => ['allowed' => $scope->obligations($context)->whereKey($obligationId)->exists()])['allowed'];
    }

    public function representatives(HealthSafetyReadContext $context): Builder
    {
        $context->assertActive();
        abort_unless(isset($this->frames[$context]) && $this->frames[$context], 404);

        return $this->participation->query('representatives', $context);
    }

    public function cases(HealthSafetyReadContext $context): Builder
    {
        $source = $this->representatives($context)
            ->whereColumn('hs_representatives.id', 'hs_training_review_cases.hs_representative_id')
            ->where(fn (Builder $site) => $site->whereColumn('hs_representatives.site_id', 'hs_training_review_cases.site_id')
                ->orWhere(fn (Builder $unknown) => $unknown->whereNull('hs_representatives.site_id')->whereNull('hs_training_review_cases.site_id')));

        return $context->query(\App\Models\HsTrainingReviewCase::query()->whereExists($source->selectRaw('1')->toBase()));
    }
}
