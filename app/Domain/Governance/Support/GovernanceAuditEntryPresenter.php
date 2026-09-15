<?php

namespace App\Domain\Governance\Support;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardEvaluation;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAllocation;
use App\Domain\Governance\Models\CeoBoardReport;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceDocument;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\RiskTreatment;
use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Models\StrategicPlan;
use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\User;
use App\Support\Security\SensitiveDataRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Turns raw Governance audit rows (governance_audit_log + governance_change_log)
 * into sentences people can read: who did what to which record, a link only
 * when the viewer can open that record, and "What changed" only when they're
 * allowed to see it. Everything is decided here, on the server, so nothing
 * the viewer can't open ever reaches the page or the CSV.
 */
final class GovernanceAuditEntryPresenter
{
    public const SYSTEM_ACTOR = 'Oblivion Care';

    public const DETAILS_WITHHELD = "You can't open this record, so its details are hidden.";

    /** Phrases for events the shared label map doesn't cover yet. */
    private const EVENT_PHRASES = [
        'action.escalated' => 'raised an action with the board',
        'action.evidence_added' => 'added evidence to an action',
        'action.evidence_removed' => 'removed evidence from an action',
        'budget.returned_to_drafting' => 'returned a budget to drafting',
        'compliance.evidence_downloaded' => 'downloaded evidence for a requirement',
        'compliance.updated' => 'updated a requirement',
        'policy.approved' => 'approved a policy',
        'resolution.published' => 'published a resolution',
        'risk.accepted' => 'accepted a risk',
        'risk.closed' => 'closed a risk',
        'risk_treatment.completed' => 'completed an action to reduce a risk',
        'risk_treatment.due_date_changed' => 'changed the due date of an action to reduce a risk',
    ];

    /** Verbs that read as "<verb> a <record type>" when there's no title to name. */
    private const BARE_VERBS = ['viewed', 'downloaded', 'edited', 'created', 'updated', 'deleted', 'approved', 'voted on', 'exported'];

    /**
     * Records the log can link to: [model, permission for the page, URL].
     *
     * @var array<string, array{0: class-string<Model>, 1: string, 2: string}>
     */
    private const RECORDS = [
        'ActionItem' => [ActionItem::class, 'governance.actions.view', '/governance/actions/%d'],
        'BoardEvaluation' => [BoardEvaluation::class, 'governance.evaluations.view', '/governance/evaluations/%d'],
        'BoardPack' => [BoardPack::class, 'governance.packs.view', '/governance/packs/%d'],
        'Budget' => [Budget::class, 'governance.budgets.view', '/governance/budgets/%d'],
        'BudgetAllocation' => [BudgetAllocation::class, 'governance.budgets.view', '/governance/budgets/%d'],
        'CeoBoardReport' => [CeoBoardReport::class, 'governance.ceo-reports.view', '/governance/ceo-reports/%d'],
        'ComplianceObligation' => [ComplianceObligation::class, 'governance.compliance.view', '/governance/compliance/%d'],
        'GovernanceDocument' => [GovernanceDocument::class, 'governance.documents.view', '/governance/documents/%d'],
        'GovernanceMeeting' => [GovernanceMeeting::class, 'governance.meetings.view', '/governance/meetings/%d'],
        'GovernancePolicy' => [GovernancePolicy::class, 'governance.policies.view', '/governance/policies/%d'],
        'PerformanceReview' => [PerformanceReview::class, 'governance.performance.view', '/governance/performance/%d'],
        'Resolution' => [Resolution::class, 'governance.resolutions.view', '/governance/resolutions/%d'],
        'RiskRegisterEntry' => [RiskRegisterEntry::class, 'governance.risks.view', '/governance/risks/%d'],
        'RiskTreatment' => [RiskTreatment::class, 'governance.risks.view', '/governance/risks/%d'],
        'SpendApproval' => [SpendApproval::class, 'governance.spend.view', '/governance/spend-approvals/%d'],
        'StrategicPlan' => [StrategicPlan::class, 'governance.strategy.view', '/governance/strategy/%d'],
    ];

    /** Settings-style records: one page, no per-record access rules. */
    private const SETTINGS_RECORDS = ['GovernanceSetting', 'GovernanceVotingProfile'];

    /** Metadata that only ever holds bookkeeping, never something a reader needs. */
    private const HIDDEN_KEYS = ['id', 'created_at', 'updated_at', 'deleted_at', 'recorded_by', 'updated_by', 'activated_by', 'removed_by'];

    public function __construct(
        private readonly BoardPackAccessService $boardPacks,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @param  iterable<int, object|array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function present(iterable $rows, User $viewer): array
    {
        $rows = collect($rows)->map(fn ($row) => (array) $row)->values();

        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id')->filter()->unique()->values())
            ->get(['id', 'name'])
            ->keyBy('id');

        $records = $this->loadRecords($rows);

        return $rows
            ->map(fn (array $row) => $this->presentRow($row, $viewer, $users, $records))
            ->all();
    }

    /**
     * One CSV line per entry: names, not ids.
     *
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    public static function csvRow(array $entry): array
    {
        return [
            GovernanceLabels::date((string) $entry['created_at'], true),
            (string) $entry['actor'],
            (string) $entry['sentence'],
            (string) $entry['record_type'],
            (string) ($entry['record_title'] ?? ''),
            collect($entry['changes'] ?? [])
                ->map(fn (array $change) => "{$change['label']}: {$change['from']} → {$change['to']}")
                ->merge(collect($entry['details'] ?? [])->map(fn (array $detail) => "{$detail['label']}: {$detail['value']}"))
                ->implode('; '),
            (string) ($entry['ip_address'] ?? ''),
        ];
    }

    /** @return array<int, string> */
    public static function csvHeader(): array
    {
        return ['When', 'Who', 'What happened', 'Record type', 'Record', 'What changed', 'IP address'];
    }

    /** "Resolution" for BoardPack / App\…\Resolution / resolution. */
    public static function recordTypeLabel(?string $entityType): string
    {
        if ($entityType === null || trim($entityType) === '') {
            return 'Record';
        }

        return GovernanceLabels::label('audit_entity_type', class_basename($entityType));
    }

    /** "voted on a resolution" — the words after the person's name. */
    public static function activityPhrase(?string $type): string
    {
        return self::EVENT_PHRASES[(string) $type] ?? GovernanceLabels::auditEvent($type);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, Collection<int|string, Model>>
     */
    private function loadRecords(Collection $rows): array
    {
        $records = [];

        foreach ($rows->groupBy(fn (array $row) => class_basename((string) $row['entity_type'])) as $base => $group) {
            if (! isset(self::RECORDS[$base])) {
                continue;
            }

            $ids = $group->pluck('entity_id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
            if ($ids->isEmpty()) {
                continue;
            }

            $class = self::RECORDS[$base][0];
            $query = $class::query()->whereIn('id', $ids);

            match ($base) {
                'BoardPack', 'CeoBoardReport' => $query->with('meeting'),
                'RiskTreatment' => $query->with('risk'),
                'BudgetAllocation' => $query->with('budget'),
                default => null,
            };

            $records[$base] = $query->get()->keyBy('id');
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int|string, User>  $users
     * @param  array<string, Collection<int|string, Model>>  $records
     * @return array<string, mixed>
     */
    private function presentRow(array $row, User $viewer, Collection $users, array $records): array
    {
        $base = class_basename((string) $row['entity_type']);
        $entityId = (int) $row['entity_id'];
        $user = $row['user_id'] ? $users->get((int) $row['user_id']) : null;
        $actor = $user?->name ?: ($row['user_id'] ? 'A former user' : self::SYSTEM_ACTOR);

        [$canOpen, $title, $url] = $this->resolveRecord($base, $entityId, $viewer, $records);

        $recordType = self::recordTypeLabel((string) $row['entity_type']);
        $phrase = self::activityPhrase((string) $row['type']);

        $metadata = $this->decode($row['metadata'] ?? null);
        $oldValues = $this->decode($row['old_values'] ?? null);
        $newValues = $this->decode($row['new_values'] ?? null);

        return [
            'key' => "{$row['kind']}-{$row['id']}",
            'kind' => $row['kind'],
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'entity_type' => $row['entity_type'],
            'entity_id' => $entityId,
            'created_at' => $this->timestamp($row['created_at'] ?? null),
            'user' => $user ? ['id' => $user->id, 'name' => $user->name] : null,
            'actor' => $actor,
            'activity' => $phrase,
            'sentence' => $this->sentence($actor, $phrase, $recordType, $title),
            'record_type' => $recordType,
            'record_title' => $title,
            'record_url' => $url,
            'can_see_details' => $canOpen,
            'details_withheld_reason' => $canOpen ? null : self::DETAILS_WITHHELD,
            'changes' => $canOpen ? $this->changes($oldValues, $newValues) : [],
            'details' => $canOpen ? $this->details($metadata) : [],
            'description' => $canOpen && is_string($row['description'] ?? null) && trim($row['description']) !== ''
                ? $this->redactor->message(trim($row['description']))
                : null,
            // Raw values stay available to auditors, masked, and only for records they can open.
            'metadata' => $canOpen && $metadata !== null ? $this->redactor->context($metadata) : null,
            'old_values' => $canOpen && $oldValues !== null ? $this->redactor->context($oldValues) : null,
            'new_values' => $canOpen && $newValues !== null ? $this->redactor->context($newValues) : null,
            'ip_address' => $row['ip_address'] ?? null,
        ];
    }

    /**
     * @param  array<string, Collection<int|string, Model>>  $records
     * @return array{0: bool, 1: ?string, 2: ?string}
     */
    private function resolveRecord(string $base, int $entityId, User $viewer, array $records): array
    {
        if (in_array($base, self::SETTINGS_RECORDS, true)) {
            $canOpen = $viewer->canDo('governance.settings.view');

            return [$canOpen, $canOpen ? self::recordTypeLabel($base) : null, $canOpen ? '/governance/settings' : null];
        }

        if (! isset(self::RECORDS[$base])) {
            // Incidents, safeguarding and anything else outside these pages:
            // never titled, linked or detailed here.
            return [false, null, null];
        }

        $record = ($records[$base] ?? collect())->get($entityId);
        if (! $record instanceof Model) {
            return [false, null, null];
        }

        [, $permission, $urlPattern] = self::RECORDS[$base];
        if (! $viewer->canDo($permission)) {
            return [false, null, null];
        }

        $target = match (true) {
            $record instanceof RiskTreatment => $record->risk,
            $record instanceof BudgetAllocation => $record->budget,
            default => $record,
        };

        if (! $target instanceof Model) {
            return [false, null, null];
        }

        $canOpen = $target instanceof BoardPack
            ? $this->boardPacks->canView($viewer, $target)
            : Gate::forUser($viewer)->allows('view', $target);

        if (! $canOpen) {
            return [false, null, null];
        }

        return [true, $this->title($record), sprintf($urlPattern, (int) $target->getKey())];
    }

    private function title(Model $record): ?string
    {
        $title = match (true) {
            $record instanceof BoardPack => $record->meeting?->title
                ? "{$record->meeting->title} — board pack"
                : 'Board pack',
            $record instanceof CeoBoardReport => $record->meeting?->title
                ? "CEO report — {$record->meeting->title}"
                : 'CEO report',
            $record instanceof ComplianceObligation => $record->obligation_title,
            $record instanceof RiskTreatment => $record->action_description,
            $record instanceof BudgetAllocation => $record->budget?->title,
            $record instanceof PerformanceReview => 'Performance review'.($record->review_cycle ? " ({$record->review_cycle})" : ''),
            default => $record->getAttribute('title') ?: $record->getAttribute('name'),
        };

        $title = is_string($title) ? trim($title) : '';

        return $title === '' ? null : Str::limit($title, 120);
    }

    private function sentence(string $actor, string $phrase, string $recordType, ?string $title): string
    {
        $typeWords = preg_match('/^(CEO|Te Tiriti)\b/u', $recordType) === 1
            ? $recordType
            : mb_strtolower(mb_substr($recordType, 0, 1)).mb_substr($recordType, 1);
        $article = preg_match('/^[aeiou]/iu', $typeWords) === 1 ? 'an' : 'a';
        $object = "{$article} {$typeWords}";

        if ($title !== null) {
            $quoted = "“{$title}”";

            if (str_ends_with($phrase, " {$object}")) {
                return "{$actor} ".substr($phrase, 0, -strlen($object)).$quoted;
            }

            if (in_array($phrase, self::BARE_VERBS, true)) {
                return "{$actor} {$phrase} {$quoted}";
            }

            return "{$actor} {$phrase}";
        }

        return in_array($phrase, self::BARE_VERBS, true)
            ? "{$actor} {$phrase} {$object}"
            : "{$actor} {$phrase}";
    }

    /**
     * Old → new values for change rows, in plain words.
     *
     * @return array<int, array{label: string, from: string, to: string}>
     */
    private function changes(?array $old, ?array $new): array
    {
        if ($old === null && $new === null) {
            return [];
        }

        $old = $this->redactor->context($old ?? []);
        $new = $this->redactor->context($new ?? []);

        return collect(array_unique([...array_keys($old), ...array_keys($new)]))
            ->filter(fn ($key) => is_string($key) && $this->isReadableKey($key))
            ->map(fn (string $key) => [
                'label' => $this->fieldLabel($key),
                'from' => $this->formatValue($old[$key] ?? null),
                'to' => $this->formatValue($new[$key] ?? null),
            ])
            ->reject(fn (array $change) => $change['from'] === $change['to'])
            ->values()
            ->all();
    }

    /**
     * Labelled facts from an action row's metadata, internal references dropped.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function details(?array $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        return collect($this->redactor->context($metadata))
            ->filter(fn ($value, $key) => is_string($key) && $this->isReadableKey($key))
            ->map(function ($value, string $key) {
                if (is_array($value)) {
                    if (! array_is_list($value) || collect($value)->contains(fn ($item) => is_array($item))) {
                        return null;
                    }

                    $value = collect($value)->map(fn ($item) => is_string($item) ? GovernanceLabels::humanise($item) : $this->formatValue($item))->implode(', ');
                } else {
                    $value = $this->formatValue($value);
                }

                return $value === '' ? null : ['label' => $this->fieldLabel($key), 'value' => $value];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function isReadableKey(string $key): bool
    {
        return ! in_array($key, self::HIDDEN_KEYS, true)
            && ! str_ends_with($key, '_id')
            && ! str_ends_with($key, '_ids');
    }

    private function fieldLabel(string $key): string
    {
        return match ($key) {
            'changed_keys' => 'Settings changed',
            'fields' => 'Details changed',
            'count', 'change_count' => 'Number of items',
            'original_name' => 'File',
            'chair_notified' => 'Chair notified',
            'secretary_notified' => 'Secretary notified',
            'has_vote_note' => 'Added a note to the vote',
            'revision_number', 'version_number', 'version' => 'Version',
            default => GovernanceLabels::humanise($key),
        };
    }

    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            $text = trim($value);

            // Stored codes ("carried", "in_progress") and timestamps read as words and NZ dates.
            if (preg_match('/^[a-z][a-z0-9_]*$/', $text) === 1) {
                return GovernanceLabels::humanise($text);
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $text) === 1) {
                return GovernanceLabels::date($text, strlen($text) > 10);
            }
        }

        return match (true) {
            $value === null => 'Not set',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value === SensitiveDataRedactor::REDACTED => 'Hidden',
            is_array($value) => 'Changed',
            default => Str::limit(trim((string) $value), 200),
        };
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, 'UTC')->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
