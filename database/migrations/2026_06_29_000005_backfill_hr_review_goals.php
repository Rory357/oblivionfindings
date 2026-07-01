<?php

use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrReviewGoal;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill structured hr_review_goals rows from the legacy
 * `hr_performance_reviews.goals` JSON blob. Idempotent (skips reviews that
 * already have child rows) and non-destructive — the JSON column is left intact,
 * so `down()` simply clears the child rows and the app falls back to the blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        HrPerformanceReview::query()
            ->whereNotNull('goals')
            ->chunkById(200, function ($reviews) {
                foreach ($reviews as $review) {
                    if (HrReviewGoal::where('performance_review_id', $review->id)->exists()) {
                        continue; // already backfilled
                    }

                    $goals = $review->goals;
                    if (! is_array($goals)) {
                        continue;
                    }

                    foreach (array_values($goals) as $i => $entry) {
                        $text = is_array($entry)
                            ? ($entry['description'] ?? $entry['title'] ?? json_encode($entry))
                            : (string) $entry;
                        $text = trim($text);
                        if ($text === '') {
                            continue;
                        }

                        HrReviewGoal::create([
                            'performance_review_id' => $review->id,
                            'tenant_id' => $review->tenant_id,
                            'description' => mb_substr($text, 0, 500),
                            'status' => 'open',
                            'sort_order' => $i,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Non-destructive rollback: drop the derived rows, keep the JSON source.
        HrReviewGoal::query()->delete();
    }
};
