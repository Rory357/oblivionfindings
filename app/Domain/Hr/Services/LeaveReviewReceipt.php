<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\StaffTimeOff;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;

/** Optional single-attempt confirmation; a UUID is correlation, never replay authority. */
class LeaveReviewReceipt
{
    private bool $modern = false;

    private bool $root = false;

    private ?Request $request = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?array $intent = null;

    private ?array $source = null;

    private ?string $notes = null;

    public function begin(Request $request, string $action, int $leaveRequestId): bool
    {
        $this->modern = false;
        $this->root = false;
        $this->request = $request;
        $this->connection = null;
        $this->pdo = null;
        $this->intent = null;
        $this->source = null;
        $this->notes = null;
        $request->session()->forget('leave_review_result');
        $this->modern = $request->header('X-Leave-Review-Result') === 'committed-v1';
        if ($this->modern && in_array($action, ['approve', 'decline'], true) && $request->user()) {
            $this->intent = ['version' => 1, 'scope' => 'leave_review', 'action' => $action,
                'actor_id' => (int) $request->user()->getKey(), 'leave_request_id' => $leaveRequestId];
        }

        return $this->modern;
    }

    /** Called after existing authority/source lookup and successful input validation. */
    public function capture(Request $request, HrLeaveRequest $source, array $validated): void
    {
        if (! $this->modern || $request !== $this->request || ! $this->intent) {
            return;
        }
        $id = $validated['request_id'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id)) {
            return;
        }
        $this->intent['request_id'] = $id;
        $this->notes = $validated['review_notes'] ?? null;
        try {
            $this->connection = DB::connection();
            $this->pdo = $this->connection->getPdo();
            $this->source = $this->sourceValues($source);
            $this->root = $source->getConnection() === $this->connection
                && $this->connection->transactionLevel() === 0 && ! $this->pdo->inTransaction();
        } catch (\Throwable) {
            // Receipt failures cannot prevent the existing writer from running.
            $this->root = false;
        }
    }

    /** Only the successful checked service return reaches this boundary, before webhook delivery. */
    public function committed(Request $request, HrLeaveRequest $result): void
    {
        if ($request !== $this->request || ! $this->intent || ! isset($this->intent['request_id'])
            || $request->user()?->getKey() !== $this->intent['actor_id']) {
            return;
        }
        $outcome = [...$this->intent, 'outcome' => 'not_confirmed'];
        try {
            if ($this->root && $this->atPhysicalExit() && $result->getConnection() === $this->connection
                && $result->exists && $result->getKey() === $this->intent['leave_request_id']
                && $this->sourceValues($result) === $this->source
                && $result->status === ($this->intent['action'] === 'approve' ? 'approved' : 'declined')
                && $result->reviewed_by === $this->intent['actor_id'] && $result->review_notes === $this->notes
                && $result->reviewed_at instanceof CarbonInterface && $result->reviewed_at->micro === 0) {
                $fresh = $this->readRequest($this->connection, $result->getKey());
                if ($fresh && $this->sourceValues($fresh) === $this->source
                    && $this->decisionValues($fresh) === $this->decisionValues($result)
                    && $this->projectionMatches($fresh) && $this->atPhysicalExit()) {
                    $outcome = [...$this->intent, 'outcome' => 'confirmed', 'saved' => $this->decisionValues($fresh),
                        // This is the verification instant, not a recovered historical commit timestamp.
                        'committed_at' => now('UTC')->startOfSecond()->format('Y-m-d\TH:i:s.000\Z')];
                }
            }
        } catch (\Throwable) {
            // A committed write may exist. Unknown is never represented as not_saved or replayable.
        }
        try {
            $request->session()->flash('leave_review_result', $outcome);
        } catch (\Throwable) {
            // Missing transport is not a claim that the database rolled back.
        }
    }

    protected function readRequest(Connection $connection, int $id): ?HrLeaveRequest
    {
        return HrLeaveRequest::on($connection->getName())->find($id);
    }

    protected function readProjection(Connection $connection, int $id): ?StaffTimeOff
    {
        return StaffTimeOff::on($connection->getName())->find($id);
    }

    private function atPhysicalExit(): bool
    {
        return $this->connection !== null && $this->pdo !== null && DB::connection() === $this->connection
            && $this->connection->getPdo() === $this->pdo && $this->connection->transactionLevel() === 0
            && ! $this->pdo->inTransaction();
    }

    private function sourceValues(HrLeaveRequest $row): array
    {
        return ['id' => $row->getKey(), 'user_id' => $row->user_id, 'leave_type' => $row->leave_type,
            'period' => $row->period, 'starts_at' => $row->starts_at?->format('Y-m-d H:i:s'),
            'ends_at' => $row->ends_at?->format('Y-m-d H:i:s'), 'hours_requested' => $row->hours_requested];
    }

    private function decisionValues(HrLeaveRequest $row): array
    {
        return ['status' => $row->status, 'reviewed_by' => $row->reviewed_by,
            'reviewed_at' => $row->reviewed_at?->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'time_off_id' => $row->time_off_id === null ? null : (int) $row->time_off_id];
    }

    private function projectionMatches(HrLeaveRequest $row): bool
    {
        // Readback includes submitted notes privately, never in the returned scalar DTO.
        if ($row->review_notes !== $this->notes || $row->reviewed_at?->micro !== 0) {
            return false;
        }
        if ($row->time_off_id === null) {
            return $this->intent['action'] === 'decline';
        }
        $projection = $this->readProjection($this->connection, (int) $row->time_off_id);
        if (! $projection || $projection->hr_leave_request_id !== $row->getKey() || $projection->user_id !== $row->user_id) {
            return false;
        }

        // Decline retains an existing projection; it does not claim a new approved projection.
        return $this->intent['action'] === 'decline' || ($projection->type === $row->leave_type
            && $projection->period === ($row->period ?: 'full_day')
            && $projection->starts_at?->equalTo($row->starts_at) && $projection->ends_at?->equalTo($row->ends_at)
            && $projection->created_by === $this->intent['actor_id'] && $projection->notes === $this->notes);
    }

    /** Lazy projection: early middleware denies may retain raw flash; UI must match its current UUID too. */
    public static function forRequester(Request $request): ?array
    {
        $result = $request->session()->get('leave_review_result');
        if (! is_array($result) || ! $request->user() || ($result['version'] ?? null) !== 1
            || ($result['scope'] ?? null) !== 'leave_review' || ($result['actor_id'] ?? null) !== $request->user()->getKey()
            || ! is_int($result['leave_request_id'] ?? null) || $result['leave_request_id'] < 1
            || ! in_array($result['action'] ?? null, ['approve', 'decline'], true)
            || ! is_string($result['request_id'] ?? null) || ! Str::isUuid($result['request_id'])
            || ($request->exists('request_id') && $request->input('request_id') !== $result['request_id'])) {
            return null;
        }
        $keys = ['version', 'scope', 'action', 'actor_id', 'leave_request_id', 'request_id', 'outcome'];
        if (($result['outcome'] ?? null) === 'confirmed') {
            $keys = [...$keys, 'saved', 'committed_at'];
            $saved = $result['saved'] ?? null;
            if (! is_array($saved) || count($saved) !== 4
                || array_diff(array_keys($saved), ['status', 'reviewed_by', 'reviewed_at', 'time_off_id']) !== []
                || ($saved['status'] ?? null) !== ($result['action'] === 'approve' ? 'approved' : 'declined')
                || ($saved['reviewed_by'] ?? null) !== $result['actor_id']
                || ! self::isUtc($saved['reviewed_at'] ?? null) || ! self::isUtc($result['committed_at'] ?? null)
                || ! array_key_exists('time_off_id', $saved)
                || ($saved['time_off_id'] !== null && (! is_int($saved['time_off_id']) || $saved['time_off_id'] < 1))
                || ($result['action'] === 'approve' && $saved['time_off_id'] === null)) {
                return null;
            }
        } elseif (($result['outcome'] ?? null) !== 'not_confirmed') {
            return null;
        }
        if (count($result) !== count($keys) || array_diff(array_keys($result), $keys) !== []) {
            return null;
        }

        return $result;
    }

    private static function isUtc(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/D', $value) === 1;
    }
}
