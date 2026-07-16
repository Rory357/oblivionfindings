<?php

namespace App\Services\Tasks;

use Illuminate\Support\Carbon;

/**
 * Normalised company-wide work item — the single shape returned by the
 * /tasks aggregator regardless of which module owns the record. Items are
 * never persisted; each deep-links back to its source record.
 */
class TaskItem
{
    public const BUCKET_OPEN = 'open';

    public const BUCKET_IN_PROGRESS = 'in_progress';

    public const BUCKET_DONE = 'done';

    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    public function __construct(
        public string $id,                 // e.g. "incident-42"
        public string $source,             // provider key, e.g. "incident"
        public string $sourceLabel,        // "Client Incidents"
        public ?string $ref,               // ticket number, e.g. "INC-2026-0042"
        public string $title,
        public string $status,             // raw module status, e.g. "investigating"
        public string $bucket,             // open | in_progress | done
        public string $severity,           // critical | high | medium | low | info
        public ?array $assignee = null,    // ['id' => int, 'name' => string]
        public ?array $client = null,      // ['id' => int, 'name' => string]
        public ?array $site = null,        // ['id' => int, 'name' => string]
        public ?string $dueAt = null,      // ISO-8601
        public ?string $createdAt = null,  // ISO-8601
        public ?string $link = null,       // deep link back to the source record
        public ?string $type = null,       // human row label, e.g. "Corrective action"
        public ?string $description = null,
        public bool $restricted = false,   // need-to-know row: drawer attaches no extra context
        public ?array $journey = null,     // shared incident journey key, source and official references
        public ?string $sourceContext = null,
        public string $actionLabel = 'Open record',
        public ?string $displayState = null,
        /** @var string[] Server-side search material; never serialised to the browser. */
        public array $searchTerms = [],
        public ?string $actionHelp = null,
    ) {
        $journeySearchTerms = data_get($this->journey, 'search_terms', []);
        if (is_array($this->journey)) {
            unset($this->journey['search_terms']);
        }

        $this->searchTerms = collect([
            ...$this->searchTerms,
            ...(is_array($journeySearchTerms) ? $journeySearchTerms : []),
        ])
            ->filter(fn ($term) => is_scalar($term) && filled((string) $term))
            ->map(fn ($term) => (string) $term)
            ->unique()
            ->values()
            ->all();
    }

    private ?bool $overdue = null;

    public function isOverdue(): bool
    {
        // Memoised — the aggregator's sort comparator calls this O(n log n) times.
        return $this->overdue ??= $this->bucket !== self::BUCKET_DONE
            && $this->dueAt !== null
            && Carbon::parse($this->dueAt)->isPast();
    }

    /**
     * Map an arbitrary module severity vocabulary onto the shared scale.
     */
    public static function normaliseSeverity(?string $severity): string
    {
        return match (strtolower((string) $severity)) {
            'critical', 'extreme', 'fatal' => 'critical',
            'high', 'major', 'severe' => 'high',
            'medium', 'moderate' => 'medium',
            'low', 'minor', 'light' => 'low',
            default => 'info',
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'sourceLabel' => $this->sourceLabel,
            'ref' => $this->ref,
            'title' => $this->title,
            'status' => $this->status,
            'bucket' => $this->bucket,
            'severity' => $this->severity,
            'assignee' => $this->assignee,
            'client' => $this->client,
            'site' => $this->site,
            'dueAt' => $this->dueAt,
            'createdAt' => $this->createdAt,
            'link' => $this->link,
            'type' => $this->type,
            'description' => $this->description,
            'restricted' => $this->restricted,
            'journey' => $this->journey,
            'sourceContext' => $this->sourceContext,
            'actionLabel' => $this->actionLabel,
            'displayState' => $this->displayState,
            'actionHelp' => $this->actionHelp,
            'overdue' => $this->isOverdue(),
        ];
    }
}
