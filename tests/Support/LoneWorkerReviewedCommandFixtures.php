<?php

namespace Tests\Support;

use App\Models\ControlRoomAlert;
use App\Models\LoneWorkerSession;
use Illuminate\Support\Str;

/** Adds reviewed command transport to retained pre-workspace regression fixtures. */
trait LoneWorkerReviewedCommandFixtures
{
    private function reviewedLoneWorkerFixture(string $uri, array $data = []): array
    {
        if (! str_starts_with($uri, '/health-safety/lone-workers/')) {
            return $data;
        }
        $data['idempotency_key'] ??= (string) Str::uuid();
        $id = null;
        if (preg_match('~/sessions/([1-9][0-9]*)(?:/|$)~', $uri, $match)) {
            $id = (int) $match[1];
        } elseif (preg_match('~/alerts/([1-9][0-9]*)/~', $uri, $match)) {
            $id = data_get(ControlRoomAlert::find((int) $match[1])?->context, 'normalized_data.lone_worker_session_id');
        }
        if ($id !== null) {
            $data['expected_version'] ??= LoneWorkerSession::withTrashed()->find((int) $id)?->lock_version ?? 1;
        }

        return $data;
    }

    public function post($uri, array $data = [], array $headers = [])
    {
        return parent::post($uri, $this->reviewedLoneWorkerFixture($uri, $data), $headers);
    }

    public function patch($uri, array $data = [], array $headers = [])
    {
        return parent::patch($uri, $this->reviewedLoneWorkerFixture($uri, $data), $headers);
    }

    public function delete($uri, array $data = [], array $headers = [])
    {
        return parent::delete($uri, $this->reviewedLoneWorkerFixture($uri, $data), $headers);
    }
}
