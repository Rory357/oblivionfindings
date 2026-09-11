<?php

namespace App\Domain\It\Services;

use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ItApiOperationsPresenter
{
    public function __construct(private readonly ItServiceIdentityCredentialService $credentials) {}

    /** @param Collection<int, ItServiceIdentity> $identities */
    public function operations(User $viewer, Collection $identities, int $page = 1, array $filters = []): array
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $identities = $identities->filter(fn (ItServiceIdentity $identity): bool => $this->credentials->canManage($viewer, $identity))->keyBy('id');
        $available = Schema::hasColumns('it_api_requests', ['execution_state', 'attempt_count', 'last_attempt_at']);
        $result = [
            'viewer_user_id' => (int) $viewer->id, 'available' => $available,
            'checked_at' => now()->toIso8601String(), 'total' => null, 'failures' => null,
            'pending' => null, 'oldest_pending_at' => null, 'last_success_at' => null,
            'history_total' => null, 'search_query' => $search, 'links' => [],
            'rows' => [], 'page' => 1, 'last_page' => 1, 'previous_url' => null, 'next_url' => null,
            'identities_url' => $identities->isEmpty() ? null : '/it/setup?tab=api',
        ];
        if (! $available) {
            return $result;
        }
        $query = ItApiRequest::query()->whereIn('service_identity_id', $identities->keys());
        $facts = (clone $query)->toBase()->selectRaw('COUNT(*) AS total,
            SUM(CASE WHEN response_status >= 400 THEN 1 ELSE 0 END) AS failures,
            SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) AS pending,
            MIN(CASE WHEN completed_at IS NULL THEN created_at ELSE NULL END) AS oldest_pending_at,
            MAX(CASE WHEN execution_state = ? AND response_status BETWEEN 200 AND 299 THEN completed_at ELSE NULL END) AS last_success_at', ['committed'])->first();
        $expressions = $this->diagnosticExpressions();
        if ($search !== '') {
            $this->applySearch($query, $identities, $search, $expressions);
        }
        $historyTotal = (clone $query)->count();
        $lastPage = max(1, (int) ceil($historyTotal / 25));
        $page = max(1, min($page, $lastPage));
        $url = fn (int $number) => '/it/setup?'.http_build_query([
            'tab' => 'operations', ...array_intersect_key($filters, array_flip(['automation_from', 'automation_to'])),
            ...($search !== '' ? ['q' => $search] : []), 'api_request_page' => $number,
        ]).'#it-api-request-history';
        $previousUrl = $page > 1 ? $url($page - 1) : null;
        $nextUrl = $page < $lastPage ? $url($page + 1) : null;
        $links = [['label' => 'Previous', 'url' => $previousUrl, 'active' => false]];
        for ($number = max(1, min($page - 1, $lastPage - 2)); $number <= min($lastPage, max(3, $page + 1)); $number++) {
            $links[] = ['label' => (string) $number, 'url' => $url($number), 'active' => $page === $number];
        }
        $links[] = ['label' => 'Next', 'url' => $nextUrl, 'active' => false];
        // Do not select stored response bodies, keys, hashes or ticket IDs.
        $rows = (clone $query)->latest('id')->forPage($page, 25)->select([
            'id', 'service_identity_id', 'response_status',
            'attempt_count', 'last_attempt_at', 'created_at', 'completed_at',
        ])->selectRaw('CASE WHEN CHAR_LENGTH(idempotency_key) BETWEEN 8 AND 100 THEN 1 ELSE 0 END AS has_retry_key')
            ->selectRaw($expressions['operation'].' AS diagnostic_operation')
            ->selectRaw($expressions['outcome'].' AS diagnostic_outcome')
            ->selectRaw($expressions['category'].' AS diagnostic_category')
            ->get()->map(function (ItApiRequest $receipt) use ($identities): array {
                $identity = $identities->get($receipt->service_identity_id);
                $outcome = $receipt->diagnostic_outcome;
                $category = $receipt->diagnostic_category;
                $active = $identity->isActive();
                $operation = $receipt->diagnostic_operation;

                return [
                    'id' => (int) $receipt->id, 'identity_name' => $identity->name,
                    'operation' => $operation, 'response_status' => $receipt->response_status,
                    'outcome' => $outcome, 'failure_category' => $category,
                    'attempt_count' => $receipt->attempt_count > 0 ? $receipt->attempt_count : null,
                    'last_attempt_at' => $receipt->last_attempt_at?->toIso8601String(),
                    'created_at' => $receipt->created_at?->toIso8601String(),
                    'completed_at' => $receipt->completed_at?->toIso8601String(),
                    'recovery' => match (true) {
                        ! $active => 'review_identity',
                        $operation === 'read' => 'read_again',
                        ! $receipt->has_retry_key || $operation === 'unknown' => 'reconcile',
                        $outcome === 'committed' => 'already_applied',
                        $outcome === 'not_applied' && $receipt->response_status >= 500 => 'retry_same_request',
                        $outcome === 'not_applied' => 'correct_request',
                        default => 'reconcile',
                    },
                ];
            })->values()->all();

        return array_replace($result, [
            'total' => (int) $facts->total, 'failures' => (int) $facts->failures, 'pending' => (int) $facts->pending,
            'oldest_pending_at' => $facts->oldest_pending_at ? CarbonImmutable::parse($facts->oldest_pending_at)->toIso8601String() : null,
            'last_success_at' => $facts->last_success_at ? CarbonImmutable::parse($facts->last_success_at)->toIso8601String() : null,
            'rows' => $rows, 'page' => $page, 'last_page' => $lastPage,
            'history_total' => $historyTotal, 'links' => $links,
            'previous_url' => $previousUrl, 'next_url' => $nextUrl,
        ]);
    }

    /** @return array{operation: string, outcome: string, category: string} */
    private function diagnosticExpressions(): array
    {
        $path = "CONCAT('/', TRIM(LEADING '/' FROM path))";
        $operation = "CASE WHEN CAST(method AS BINARY) = 'POST' AND CAST($path AS BINARY) = '/api/v1/it/work-items' THEN 'create'";
        foreach (['PATCH' => 'update', 'GET' => 'read'] as $method => $label) {
            // The final ASCII check rejects a trailing line terminator, even
            // when the database regexp end anchor would otherwise accept it.
            $operation .= " WHEN CAST(method AS BINARY) = '$method' AND REGEXP_LIKE($path, '^/api/v1/it/work-items/[0-9]+$', 'c') AND ASCII(RIGHT($path, 1)) BETWEEN 48 AND 57 THEN '$label'";
        }
        foreach (['relationships' => 'link', 'comments' => 'comment', 'transitions' => 'transition'] as $suffix => $label) {
            $operation .= " WHEN CAST(method AS BINARY) = 'POST' AND REGEXP_LIKE($path, '^/api/v1/it/work-items/[0-9]+/$suffix$', 'c') AND ASCII(RIGHT($path, 1)) = 115 THEN '$label'";
        }

        return [
            'operation' => $operation." ELSE 'unknown' END",
            'outcome' => "CASE WHEN completed_at IS NULL THEN 'pending'
                WHEN CAST(execution_state AS BINARY) = 'committed' AND response_status BETWEEN 200 AND 299 THEN 'committed'
                WHEN CAST(execution_state AS BINARY) = 'rolled_back' AND response_status >= 400 THEN 'not_applied'
                ELSE 'unknown' END",
            'category' => "CASE response_status WHEN 401 THEN 'authentication' WHEN 403 THEN 'permission'
                WHEN 404 THEN 'unavailable_record' WHEN 409 THEN 'conflict' WHEN 422 THEN 'validation' WHEN 429 THEN 'rate_limit'
                ELSE CASE WHEN response_status >= 500 THEN 'server_failure' WHEN response_status >= 400 THEN 'request_rejected' ELSE NULL END END",
        ];
    }

    /** @param Collection<int, ItServiceIdentity> $identities */
    private function applySearch(Builder $query, Collection $identities, string $search, array $expressions): void
    {
        $needle = Str::lower($search);
        $identityIds = $identities->filter(fn (ItServiceIdentity $identity) => str_contains(Str::lower($identity->name), $needle))->keys();
        $labels = [
            'operation' => ['create' => 'Create ticket', 'read' => 'Read ticket', 'update' => 'Update ticket',
                'link' => 'Link tickets', 'comment' => 'Add reply', 'transition' => 'Change status', 'unknown' => 'Unclassified request'],
            'outcome' => ['committed' => 'Completed', 'not_applied' => 'Not applied', 'pending' => 'No completed outcome', 'unknown' => 'Outcome unverified'],
            'category' => ['authentication' => 'authentication', 'permission' => 'permission', 'unavailable_record' => 'unavailable record',
                'conflict' => 'conflict', 'validation' => 'validation', 'rate_limit' => 'rate limit',
                'server_failure' => 'server failure', 'request_rejected' => 'request rejected'],
        ];
        $receiptId = preg_match('/^(?:receipt\s*)?([0-9]+)$/i', $search, $match) ? $match[1] : null;
        $status = preg_match('/^[1-5][0-9]{2}$/', $search) ? (int) $search : null;
        $query->where(function (Builder $matches) use ($needle, $identityIds, $labels, $expressions, $receiptId, $status): void {
            $matches->whereRaw('1 = 0')->orWhereIn('service_identity_id', $identityIds);
            foreach ($labels as $field => $vocabulary) {
                $keys = array_keys(array_filter($vocabulary, fn ($label) => str_contains(Str::lower($label), $needle)));
                if ($keys !== []) {
                    $matches->orWhereRaw('('.$expressions[$field].') IN ('.implode(',', array_fill(0, count($keys), '?')).')', $keys);
                }
            }
            if (str_contains('no recorded failure', $needle)) {
                $matches->orWhereRaw('('.$expressions['category'].') IS NULL');
            }
            if ($receiptId !== null) {
                $matches->orWhere('id', $receiptId);
            }
            if ($status !== null) {
                $matches->orWhere('response_status', $status);
            }
            if ($needle === 'receipt') {
                $matches->orWhereRaw('1 = 1');
            }
        });
    }
}
