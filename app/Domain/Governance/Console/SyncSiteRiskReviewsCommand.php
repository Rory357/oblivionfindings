<?php

namespace App\Domain\Governance\Console;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Services\GovernanceAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `ActionItem` rows 14 days before each Site's `risk_review_date`
 * so the governance lead has visibility of upcoming site-level risk
 * reviews. Sites with `is_high_risk = true` always produce an action even
 * if no review date is set (treated as immediately overdue).
 *
 * Idempotent — one action per (site_id, review_date).
 */
class SyncSiteRiskReviewsCommand extends Command
{
    protected $signature = 'governance:sync-site-risk-reviews
        {--lead-days=14 : Days ahead of the review date to create the action}';

    protected $description = 'Generate governance action items from upcoming Site risk_review_date deadlines';

    public function handle(): int
    {
        if (! Schema::hasTable('sites')) {
            $this->warn('sites table not present in this environment; skipping.');

            return self::SUCCESS;
        }

        $hasReviewColumn = Schema::hasColumn('sites', 'risk_review_date');
        $hasHighRiskColumn = Schema::hasColumn('sites', 'is_high_risk');

        if (! $hasReviewColumn && ! $hasHighRiskColumn) {
            $this->warn('Sites table is missing risk_review_date and is_high_risk — nothing to sync.');

            return self::SUCCESS;
        }

        $leadDays = max(1, (int) $this->option('lead-days'));
        $window = now()->addDays($leadDays);

        $query = DB::table('sites');
        if ($hasReviewColumn) {
            $query->where(function ($q) use ($window, $hasHighRiskColumn) {
                $q->where('risk_review_date', '<=', $window)
                    ->where('risk_review_date', '>=', now()->subYear()->startOfDay());

                if ($hasHighRiskColumn) {
                    $q->orWhereNull('risk_review_date')->where('is_high_risk', true);
                }
            });
        } elseif ($hasHighRiskColumn) {
            $query->where('is_high_risk', true);
        }

        $sites = $query->get();
        $created = 0;

        foreach ($sites as $site) {
            $reviewDate = $hasReviewColumn ? $site->risk_review_date : null;
            $sourceKey = "SITE-RISK-REVIEW-{$site->id}-" . ($reviewDate ?? 'undated');

            $existing = ActionItem::query()
                ->where('source_type', 'site_risk_review')
                ->where('source_id', $site->id)
                ->where('action_reference', $sourceKey)
                ->first();

            if ($existing) {
                continue;
            }

            try {
                $description = $reviewDate
                    ? sprintf('Site risk review due for %s on %s.', $site->name ?? "Site #{$site->id}", $reviewDate)
                    : sprintf('Site %s is flagged high-risk but has no scheduled risk review. Set one.', $site->name ?? "#{$site->id}");

                $action = ActionItem::create([
                    'action_reference' => $sourceKey,
                    'description' => $description,
                    'priority' => $hasHighRiskColumn && $site->is_high_risk ? 'high' : 'medium',
                    'status' => 'open',
                    'source_type' => 'site_risk_review',
                    'source_id' => $site->id,
                    'due_date' => $reviewDate,
                    'created_by' => null,
                ]);

                GovernanceAuditService::log(
                    'action.auto_created',
                    'ActionItem',
                    $action->id,
                    [
                        'source' => 'Site.risk_review_date',
                        'site_id' => $site->id,
                        'review_date' => $reviewDate,
                    ],
                );
                $created++;
            } catch (\Throwable $e) {
                $this->error("Failed for site #{$site->id}: " . $e->getMessage());
            }
        }

        $this->info("Created {$created} site risk-review action item(s).");

        return self::SUCCESS;
    }
}
