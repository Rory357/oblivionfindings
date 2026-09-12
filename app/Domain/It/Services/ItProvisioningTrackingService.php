<?php

namespace App\Domain\It\Services;

use App\Models\ItCatalogSubmission;
use App\Models\ItProvisioningRequest;
use App\Models\User;
use Illuminate\Http\Request;

/** Public projection of canonical provisioning; never serialize work notes or HR context. */
final class ItProvisioningTrackingService
{
    private const EVENTS = [
        'created' => 'Request submitted',
        'assigned' => 'Assigned to IT',
        'approved' => 'Approval recorded',
        'fulfilled' => 'Work completed',
        'failed' => 'Needs IT attention',
        'cancelled' => 'Request cancelled',
    ];

    public function __construct(
        private readonly ItProvisioningAccessService $access,
        private readonly ItCatalogFieldOptionService $fieldOptions,
    ) {}

    public function listing(User $actor, Request $http): array
    {
        $query = $this->access->applyTrackingScope(ItProvisioningRequest::query(), $actor);
        $total = (clone $query)->count();
        $search = mb_substr(trim((string) $http->query('my_q', '')), 0, 200);
        if ($search !== '') {
            $query->where(function ($matching) use ($search): void {
                $matching->where('item', 'like', '%'.addcslashes($search, '%_\\').'%');
                if (preg_match('/^(?:IT-P)?0*([1-9][0-9]*)$/i', $search, $matches)) {
                    $matching->orWhere('it_provisioning_requests.id', (int) $matches[1]);
                }
            });
        }
        $status = $http->query('my_status');
        if (is_string($status) && $status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'my_provisioning_page')->withQueryString();

        return [
            'total' => $total,
            'matched' => $page->total(),
            'data' => $page->getCollection()->map(fn (ItProvisioningRequest $row) => $this->row($row))->all(),
            'links' => $page->linkCollection()->all(),
            'last_page' => $page->lastPage(),
        ];
    }

    public function detail(User $actor, ItProvisioningRequest $request): array
    {
        abort_unless($this->access->canTrack($actor, $request), 404);
        $request = $request->fresh();
        $submission = $request->catalogSubmissions()->where('requester_user_id', $actor->id)->oldest('id')->firstOrFail();

        return [
            ...$this->row($request),
            'viewer_user_id' => (int) $actor->id,
            'catalogue_version' => $submission->schema_version,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'answers' => $this->answers($submission, $actor),
            'events' => $request->events()->whereIn('type', array_keys(self::EVENTS))
                ->orderBy('created_at')->orderBy('id')->get(['id', 'type', 'created_at'])
                ->map(fn ($event) => [
                    'id' => $event->id,
                    'label' => self::EVENTS[$event->type],
                    'at' => $event->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    private function row(ItProvisioningRequest $request): array
    {
        return [
            'id' => $request->id,
            'reference' => 'IT-P'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT),
            'title' => $request->item,
            'type' => $request->type,
            'status' => $request->status,
            'approval_status' => $request->approval_required ? $request->approval_status : 'not_required',
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
            'due_date' => $request->due_date?->toDateString(),
            'href' => '/it/provisioning/'.$request->id,
        ];
    }

    private function answers(ItCatalogSubmission $submission, User $actor): array
    {
        $fields = collect($submission->schema_snapshot['fields'] ?? [])
            ->filter(fn ($field) => is_array($field) && ($field['visibility'] ?? 'requester') === 'requester');
        $options = $this->fieldOptions->forTypes($actor, $fields->pluck('type')->intersect(ItCatalogFieldOptionService::TYPES)->all());
        $answers = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if (! is_string($key) || ! array_key_exists($key, $submission->submitted_values ?? [])) {
                continue;
            }
            $value = $submission->submitted_values[$key];
            if (in_array($field['type'] ?? '', ItCatalogFieldOptionService::TYPES, true)) {
                $value = collect($options[$field['type']] ?? [])->firstWhere('id', $value)['name'] ?? 'Selection no longer available to your access';
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, fn ($part) => is_string($part) || is_numeric($part)));
            }
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $answers[] = [
                'label' => (string) ($field['label'] ?? $key),
                'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : (filled($value) ? (string) $value : 'Not supplied'),
            ];
        }

        return $answers;
    }
}
