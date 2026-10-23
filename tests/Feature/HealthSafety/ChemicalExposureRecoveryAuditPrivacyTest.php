<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalExposureRecoveryCase;
use App\Models\ChemicalExposureRecoveryReceipt;
use App\Services\HealthSafety\ChemicalExposureAuditAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Support\Facades\DB;

/** Whole actual consumer suite. Requires reviewed recovery/audit composition; SOURCE_ONLY_UNRUN. */
final class ChemicalExposureRecoveryAuditPrivacyTest extends ChemicalExposureRecoveryTestCase
{
    private function accepted(): ChemicalExposureRecoveryReceipt
    {
        $root = $this->legacy(); $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput());
        return ChemicalExposureRecoveryReceipt::sole();
    }
    private function visible(int $id): array
    {
        $guard = app(ChemicalExposureAuditAccessService::class);
        return $guard->materialize($this->manager, function ($current, $context) use ($guard, $id) {
            $query = $guard->scope(AuditLog::whereKey($id), $current, $context);
            return ['count' => (clone $query)->count(), 'ids' => $query->orderBy('id')->pluck('id')->all()];
        });
    }
    public function test_exact_current_recovery_audit_count_search_page_and_export_rows_share_one_live_scope(): void
    {
        $receipt = $this->accepted(); $this->allow($this->manager, 'audit.viewAny'); $guard = app(ChemicalExposureAuditAccessService::class);
        $dto = $guard->materialize($this->manager, function ($current, $context) use ($guard, $receipt) {
            $query = $guard->scope(AuditLog::whereKey($receipt->audit_log_id), $current, $context)
                ->where('action', 'like', '%record_legacy_review%');
            $page = (clone $query)->paginate(1);
            $cells = $query->get(['id', 'action', 'meta'])->map(fn ($row) => [(int) $row->id, $row->action, $row->meta])->all();
            return ['count' => $page->total(), 'cells' => $cells];
        });
        $this->assertSame(1, $dto['count']); $this->assertSame($receipt->audit_log_id, $dto['cells'][0][0]);
        $this->assertSame($receipt->audit_metadata, $dto['cells'][0][2]);
        $this->assertStringNotContainsString('SEALED-ORIGINAL', json_encode($dto, JSON_THROW_ON_ERROR));
    }
    public function test_no_context_wrappers_exclude_recovery_subject_even_with_an_unrelated_action_and_keep_unrelated_audit(): void
    {
        $receipt = $this->accepted(); $case = ChemicalExposureRecoveryCase::sole();
        $private = AuditLog::create(['action' => 'unrelated.lookalike', 'auditable_type' => $case->getMorphClass(), 'auditable_id' => $case->id, 'meta' => ['notes' => 'PRIVATE-FAKE']]);
        $ordinary = AuditLog::create(['action' => 'it.change.updated', 'auditable_type' => \App\Models\User::class, 'auditable_id' => $this->manager->id, 'meta' => ['after' => ['status' => 'open']]]);
        $ids = app(ChemicalExposureAuditAccessService::class)->scope(AuditLog::whereKey([$receipt->audit_log_id, $private->id, $ordinary->id]))->pluck('id')->all();
        $this->assertSame([$ordinary->id], $ids);
    }
    public function test_warmed_manage_and_wellbeing_revocation_and_restore_do_not_reuse_a_prior_audit_whitelist(): void
    {
        $receipt = $this->accepted(); $this->allow($this->manager, 'audit.viewAny');
        $this->manager->load(['roles.permissions', 'permissionOverrides']);
        foreach (['hazards.manage', 'hr.wellbeing.view'] as $key) {
            $this->allow($this->manager, $key, false); $this->assertSame(['count' => 0, 'ids' => []], $this->visible($receipt->audit_log_id));
            $this->allow($this->manager, $key); $this->assertSame(['count' => 1, 'ids' => [$receipt->audit_log_id]], $this->visible($receipt->audit_log_id));
        }
    }
    public function test_foreign_current_site_excludes_recovery_before_full_audit_meta_or_actor_name_hydration(): void
    {
        $receipt = $this->accepted(); $this->allow($this->manager, 'audit.viewAny');
        $profile = \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->sole();
        $profile->update(['primary_site_id' => $this->foreignSite->id]);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->visible($receipt->audit_log_id));
        $this->assertSame(['count' => 0, 'ids' => []], $dto); $this->noOldBody($queries);
        foreach ($queries as $query) {
            if (str_contains($query['query'], 'from `audit_logs`')) { $this->assertDoesNotMatchRegularExpression('/select\s+\*/i', $query['query']); }
        }
        $profile->update(['primary_site_id' => $this->site->id]); $this->assertSame(1, $this->visible($receipt->audit_log_id)['count']);
    }
    public function test_rewritten_or_unanchored_case_audit_never_counts_as_an_accepted_recovery(): void
    {
        $receipt = $this->accepted(); $this->allow($this->manager, 'audit.viewAny'); $meta = $receipt->audit_metadata; $meta['revision']++;
        DB::table('audit_logs')->where('id', $receipt->audit_log_id)->update(['meta' => json_encode($meta, JSON_THROW_ON_ERROR)]);
        $this->assertSame(['count' => 0, 'ids' => []], $this->visible($receipt->audit_log_id));
    }
    public function test_supplied_audit_builder_lifetime_wrong_actor_and_missing_preparation_remain_visible_errors(): void
    {
        $receipt = $this->accepted(); $this->allow($this->manager, 'audit.viewAny'); $guard = app(ChemicalExposureAuditAccessService::class); $escaped = null;
        $guard->materialize($this->manager, function ($current, $context) use ($guard, $receipt, &$escaped) {
            $escaped = $guard->scope(AuditLog::whereKey($receipt->audit_log_id), $current, $context);
            $this->assertSame(1, $escaped->count()); $this->integrity(fn () => $guard->scope(AuditLog::query(), $this->reporter, $context)); return null;
        });
        $this->integrity(fn () => $escaped->count());
        HealthSafetyReadContext::within($this->manager, ['audit.viewAny'], function ($context) use ($guard) {
            $this->integrity(fn () => $guard->scope(AuditLog::query(), $this->manager, $context)); return null;
        });
    }
}
