<?php

namespace App\Observers;

use App\Services\Eligibility\WorkforceEligibilityRefresh;
use App\Services\Eligibility\WorkforceEligibilitySources;
use Illuminate\Database\Eloquent\Model;

final class WorkforceEligibilitySourceObserver
{
    public function saved(Model $model): void
    {
        $fields = WorkforceEligibilitySources::definitions()[$model::class]['fields'] ?? [];
        if ($model->wasRecentlyCreated || $model->wasChanged($fields)) {
            app(WorkforceEligibilityRefresh::class)->sourceChanged($model);
        }
    }

    public function deleted(Model $model): void
    {
        app(WorkforceEligibilityRefresh::class)->sourceChanged($model, true);
    }

    public function restored(Model $model): void
    {
        app(WorkforceEligibilityRefresh::class)->sourceChanged($model);
    }
}
