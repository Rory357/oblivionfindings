<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetChecklistTemplateVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\JsonEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The controlled vehicle checklist library, shared with Maintenance
 * checklists. A checklist's current content lives on its template row (which
 * PKG-01 check rules approve and snapshot); every published change adds the
 * next immutable version. Earlier versions and submitted checks are never
 * rewritten. Lock order: vehicle (when a check is recorded), template row,
 * then its versions.
 */
class VehicleCheckLibraryService
{
    /** Answer values of a condition question, in the order they are offered. */
    public const CONDITION_VALUES = ['pass', 'fail', 'unable'];

    public const KINDS = ['condition', 'text', 'number', 'select', 'checkbox'];

    /** Readable answers for the stored values PKG-01 check rules evaluate. */
    private const OPTION_LABELS = [
        'pass' => 'No issue recorded',
        'fail' => 'Issue recorded',
        'unable' => 'Unable to assess',
        'na' => 'Not applicable',
        'yes' => 'Yes',
        'no' => 'No',
        'good' => 'Good',
        'fair' => 'Fair',
        'poor' => 'Poor',
        'issue' => 'Issue',
    ];

    public const ASSIGNMENT_LABELS = [
        'all_vehicles' => 'All vehicles',
        'accessible_vehicles' => 'Accessible vehicles',
        'vehicle' => 'This vehicle',
    ];

    private const STALE = 'This checklist changed while you were editing. Review the latest version before publishing.';

    private const LIBRARY_LIMIT = 200;

    public function __construct(
        private readonly MaintenanceAccessService $maintenance,
        private readonly SecurityDevicesAccessService $vehicles,
    ) {}

    public function canManage(User $actor): bool
    {
        return $this->maintenance->canManage($actor);
    }

    /**
     * Active checklists this vehicle can use, each with its current version.
     *
     * @return list<array<string,mixed>>
     */
    public function forVehicle(Asset $asset): array
    {
        $templates = FleetChecklistTemplate::query()->where('is_active', true)
            ->orderBy('name')->orderBy('id')->limit(self::LIBRARY_LIMIT)->get();
        $latest = $this->latestVersions($templates->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        return $templates
            ->map(fn (FleetChecklistTemplate $template): array => $this->describe($template, $latest[(int) $template->id] ?? null))
            ->filter(fn (array $definition): bool => $definition['questions'] !== [] && $this->appliesTo($definition, $asset))
            ->values()->all();
    }

    /**
     * The current definition of a checklist. When its content has no version
     * row yet (an existing Maintenance checklist), the version it will be
     * recorded as is shown and version_id stays null until first use.
     *
     * @return array<string,mixed>
     */
    public function describe(FleetChecklistTemplate $template, ?FleetChecklistTemplateVersion $latest): array
    {
        $items = is_array($template->items) ? array_values($template->items) : [];
        $sha = MaintenanceFingerprint::of($items);
        $current = $latest !== null && hash_equals((string) $latest->items_sha256, $sha);

        return [
            'template_id' => (int) $template->id,
            'version_id' => $current ? (int) $latest->id : null,
            'version' => $current ? (int) $latest->version : (int) ($latest?->version ?? 0) + 1,
            'name' => $current ? (string) $latest->name : (string) $template->name,
            'use' => $latest?->use_label ?? $this->defaultUse($template),
            'assignment' => $latest?->assignment ?? 'all_vehicles',
            'assignment_asset_id' => $latest?->assignment_asset_id !== null ? (int) $latest->assignment_asset_id : null,
            'evidence_required' => (bool) ($latest?->evidence_required ?? false),
            'items' => $items,
            'items_sha256' => $sha,
            'questions' => $this->questions($items),
            'source' => $current ? (string) $latest->source : FleetChecklistTemplateVersion::SOURCE_EXISTING,
            'published_at' => $current ? $latest->published_at : null,
            'published_by' => $current ? $latest->publishedBy?->name : null,
        ];
    }

    /** @param array<string,mixed> $definition */
    public function appliesTo(array $definition, Asset $asset): bool
    {
        return match ($definition['assignment']) {
            'accessible_vehicles' => (bool) ($asset->has_wheelchair_ramp || $asset->has_hoist),
            'vehicle' => (int) $definition['assignment_asset_id'] === (int) $asset->id,
            default => true,
        };
    }

    /**
     * The version a new check is recorded against. Call with the template row
     * locked. Content without a matching version row (a Maintenance checklist
     * not yet used here, or changed outside the library) is captured first,
     * so the check always points at a version holding exactly what it showed.
     */
    public function ensureCurrentVersion(FleetChecklistTemplate $template): FleetChecklistTemplateVersion
    {
        $latest = $this->lockedLatest((int) $template->id);
        $items = is_array($template->items) ? array_values($template->items) : [];
        if ($latest !== null && hash_equals((string) $latest->items_sha256, MaintenanceFingerprint::of($items))) {
            return $latest;
        }

        return $this->capture($template, $latest);
    }

    /**
     * Publish a new checklist, or the next version of an existing one. The
     * template row then carries the new content for new checks; earlier
     * versions and submitted checks keep theirs. An identical retry returns
     * the version it already published.
     *
     * @param  array<string,mixed>  $input
     */
    public function publish(User $actor, int $assetId, ?int $templateId, array $input, string $requestKey): FleetChecklistTemplateVersion
    {
        abort_unless($this->canManage($actor), 403);
        if (trim($requestKey) === '' || mb_strlen($requestKey) > 100) {
            throw ValidationException::withMessages(['request_key' => 'A request key is required.']);
        }
        $vehicle = $this->vehicles->assignableVehicle($actor, $assetId) ?? abort(404);
        $definition = $this->validatedDefinition($input, $templateId !== null, (int) $vehicle->id);
        $expectedVersionId = isset($input['expected_version_id']) ? (int) $input['expected_version_id'] : null;
        $expectedSha = (string) ($input['expected_items_sha256'] ?? '');
        $fingerprint = MaintenanceFingerprint::of([
            'actor' => (int) $actor->id, 'operation' => 'vehicle.check_template.publish',
            'template' => $templateId, 'asset' => (int) $vehicle->id, 'definition' => $definition,
            'expected_version_id' => $expectedVersionId, 'expected_items_sha256' => $expectedSha,
        ]);

        return DB::transaction(function () use ($actor, $vehicle, $templateId, $definition, $expectedVersionId, $expectedSha, $requestKey, $fingerprint): FleetChecklistTemplateVersion {
            $prior = FleetChecklistTemplateVersion::query()->where('published_by_user_id', $actor->id)
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($prior !== null) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different checklist change. Reload and try again.');

                return $prior;
            }
            $current = User::query()->findOrFail($actor->id);
            abort_unless($this->canManage($current), 403);
            $this->assertUniqueName($definition['name'], $templateId);

            if ($templateId === null) {
                $template = FleetChecklistTemplate::query()->create([
                    'name' => $definition['name'], 'type' => 'custom', 'items' => $definition['items'], 'is_active' => true,
                ]);
                $version = $this->insertVersion($template, 1, $definition, $current, $requestKey, $fingerprint);
            } else {
                $template = FleetChecklistTemplate::query()->whereKey($templateId)->where('is_active', true)
                    ->lockForUpdate()->first() ?? abort(404);
                $items = is_array($template->items) ? array_values($template->items) : [];
                $sha = MaintenanceFingerprint::of($items);
                abort_unless(hash_equals($sha, $expectedSha), 409, self::STALE);
                $latest = $this->lockedLatest((int) $template->id);
                $matching = $latest !== null && hash_equals((string) $latest->items_sha256, $sha) ? $latest : null;
                abort_unless(($matching?->id !== null ? (int) $matching->id : null) === $expectedVersionId, 409, self::STALE);
                $base = $matching ?? $this->capture($template, $latest);
                // A checklist assigned to another vehicle can't be changed from this one.
                abort_if($base->assignment === 'vehicle' && (int) $base->assignment_asset_id !== (int) $vehicle->id, 404);
                // Unchanged questions keep their stored form, so a change to the
                // name, use or assignment alone leaves the approved content intact.
                $definition['items'] = $this->preserveUnchanged(is_array($base->items) ? $base->items : [], $definition['items']);
                if ($this->sameDefinition($base, $definition)) {
                    throw ValidationException::withMessages([
                        'questions' => 'Nothing has changed. Edit the checklist before publishing a new version.',
                    ]);
                }
                $version = $this->insertVersion($template, (int) $base->version + 1, $definition, $current, $requestKey, $fingerprint);
                $template->forceFill(['name' => $definition['name'], 'items' => $definition['items']])->save();
            }

            AuditLogger::logOrFail('fleet.checklist_template.publish', $template, [
                'asset_id' => $vehicle->id, 'template_id' => $template->id, 'version' => $version->version,
                'version_id' => $version->id, 'items_sha256' => $version->items_sha256,
                'assignment' => $version->assignment, 'evidence_required' => $version->evidence_required,
            ]);

            return $version;
        }, 3);
    }

    /**
     * Questions as presented to a person answering the checklist.
     *
     * @param  array<int|string,mixed>  $items
     * @return list<array{id:string,label:string,kind:string,required:bool,options:list<array{value:string,label:string}>}>
     */
    public function questions(array $items): array
    {
        $questions = [];
        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = isset($item['id']) && is_scalar($item['id']) && (string) $item['id'] !== ''
                ? (string) $item['id'] : (string) $index;
            $kind = $this->kindOf($item);
            $label = is_scalar($item['label'] ?? null) ? trim((string) $item['label']) : '';
            $questions[] = [
                'id' => $id,
                'label' => $label !== '' ? $label : 'Question '.($index + 1),
                'kind' => $kind,
                'required' => (bool) ($item['required'] ?? false),
                'options' => array_map(
                    fn (string $value): array => ['value' => $value, 'label' => $this->optionLabel($value)],
                    $this->optionValues($item, $kind),
                ),
            ];
        }

        return $questions;
    }

    /** A readable answer for a stored response value. */
    public function answerLabel(array $question, mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['result'] ?? null;
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $text = is_scalar($value) ? trim((string) $value) : '';
        if (in_array($question['kind'], ['condition', 'select', 'checkbox'], true)) {
            return $this->optionLabel($text);
        }

        return $text;
    }

    public function optionLabel(string $value): string
    {
        return self::OPTION_LABELS[strtolower($value)] ?? ucfirst(str_replace('_', ' ', $value));
    }

    /** @return array<int, FleetChecklistTemplateVersion> keyed by template id */
    public function latestVersions(array $templateIds): array
    {
        if ($templateIds === []) {
            return [];
        }
        $latest = FleetChecklistTemplateVersion::query()->whereIn('template_id', $templateIds)
            ->groupBy('template_id')->selectRaw('template_id, MAX(version) as latest_version');

        return FleetChecklistTemplateVersion::query()
            ->joinSub($latest, 'latest', function ($join): void {
                $join->on('latest.template_id', '=', 'fleet_checklist_template_versions.template_id')
                    ->on('latest.latest_version', '=', 'fleet_checklist_template_versions.version');
            })
            ->with('publishedBy:id,name')
            ->get(['fleet_checklist_template_versions.*'])
            ->keyBy(fn (FleetChecklistTemplateVersion $version): int => (int) $version->template_id)
            ->all();
    }

    private function lockedLatest(int $templateId): ?FleetChecklistTemplateVersion
    {
        return FleetChecklistTemplateVersion::query()->where('template_id', $templateId)
            ->orderByDesc('version')->lockForUpdate()->first();
    }

    /** Record content that has no version row yet, keeping the latest settings. */
    private function capture(FleetChecklistTemplate $template, ?FleetChecklistTemplateVersion $latest): FleetChecklistTemplateVersion
    {
        $items = is_array($template->items) ? array_values($template->items) : [];

        return FleetChecklistTemplateVersion::query()->create([
            'template_id' => $template->id,
            'version' => (int) ($latest?->version ?? 0) + 1,
            'name' => (string) $template->name,
            'use_label' => $latest?->use_label ?? $this->defaultUse($template),
            'assignment' => $latest?->assignment ?? 'all_vehicles',
            'assignment_asset_id' => $latest?->assignment_asset_id,
            'evidence_required' => (bool) ($latest?->evidence_required ?? false),
            'items' => $items,
            'items_sha256' => MaintenanceFingerprint::of($items),
            'source' => FleetChecklistTemplateVersion::SOURCE_EXISTING,
            'published_by_user_id' => null,
            'published_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $definition */
    private function insertVersion(FleetChecklistTemplate $template, int $number, array $definition, User $actor, string $requestKey, string $fingerprint): FleetChecklistTemplateVersion
    {
        return FleetChecklistTemplateVersion::query()->create([
            'template_id' => $template->id,
            'version' => $number,
            'name' => $definition['name'],
            'use_label' => $definition['use_label'],
            'assignment' => $definition['assignment'],
            'assignment_asset_id' => $definition['assignment_asset_id'],
            'evidence_required' => $definition['evidence_required'],
            'items' => $definition['items'],
            'items_sha256' => MaintenanceFingerprint::of($definition['items']),
            'source' => FleetChecklistTemplateVersion::SOURCE_LIBRARY,
            'published_by_user_id' => $actor->id,
            'published_at' => now(),
            'request_key' => $requestKey,
            'request_fingerprint' => $fingerprint,
        ]);
    }

    /** @param array<string,mixed> $definition */
    private function sameDefinition(FleetChecklistTemplateVersion $base, array $definition): bool
    {
        return $base->name === $definition['name']
            && $base->use_label === $definition['use_label']
            && $base->assignment === $definition['assignment']
            && ($base->assignment_asset_id === null ? null : (int) $base->assignment_asset_id) === $definition['assignment_asset_id']
            && (bool) $base->evidence_required === $definition['evidence_required']
            && JsonEvidence::matches($base->items, $definition['items']);
    }

    /**
     * @param  array<int|string,mixed>  $baseItems
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function preserveUnchanged(array $baseItems, array $items): array
    {
        $stored = [];
        foreach (array_values($baseItems) as $index => $raw) {
            if (is_array($raw)) {
                $question = $this->questions([$raw])[0] ?? null;
                if ($question !== null) {
                    // Legacy items without an id are answered by position.
                    $stored[isset($raw['id']) && is_scalar($raw['id']) && (string) $raw['id'] !== '' ? (string) $raw['id'] : (string) $index] = [$raw, $question];
                }
            }
        }

        return array_map(function (array $item) use ($stored): array {
            [$raw, $before] = $stored[(string) $item['id']] ?? [null, null];
            if ($raw === null) {
                return $item;
            }
            $after = $this->questions([$item])[0];
            $same = $before['label'] === $after['label'] && $before['kind'] === $after['kind']
                && $before['required'] === $after['required']
                && array_column($before['options'], 'value') === array_column($after['options'], 'value');

            return $same ? $raw : $item;
        }, $items);
    }

    private function assertUniqueName(string $name, ?int $templateId): void
    {
        $taken = FleetChecklistTemplate::query()->where('is_active', true)
            ->when($templateId !== null, fn ($query) => $query->whereKeyNot($templateId))
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Another checklist already has this name. Choose a different name.']);
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{name:string,use_label:string,assignment:string,assignment_asset_id:?int,evidence_required:bool,items:list<array<string,mixed>>}
     */
    private function validatedDefinition(array $input, bool $customise, int $vehicleId): array
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'use' => ['required', 'string', 'max:120'],
            'assignment' => ['required', 'in:'.implode(',', FleetChecklistTemplateVersion::ASSIGNMENTS)],
            'evidence_required' => ['required', 'boolean'],
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*' => ['array'],
            'questions.*.id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'questions.*.label' => ['required', 'string', 'max:255'],
            'questions.*.kind' => ['required', 'in:'.implode(',', self::KINDS)],
            'questions.*.required' => ['required', 'boolean'],
            'questions.*.options' => ['nullable', 'array', 'max:50'],
            'questions.*.options.*' => ['required', 'string', 'max:100'],
            'confirmed' => ['accepted'],
            'expected_version_id' => ['nullable', 'integer', 'min:1'],
            'expected_items_sha256' => [$customise ? 'required' : 'nullable', 'string', 'size:64'],
        ], [
            'name.required' => 'Name the checklist.',
            'use.required' => 'Choose when the checklist is used.',
            'questions.required' => 'Add at least one question.',
            'questions.min' => 'Add at least one question.',
            'questions.*.label.required' => 'Give every question a label.',
            'confirmed.accepted' => 'Confirm that this version should be published for new checks.',
        ], [
            'use' => 'when to use', 'evidence_required' => 'evidence requirement',
        ])->validate();

        $questions = [];
        $labels = [];
        $ids = [];
        foreach (array_values($input['questions']) as $index => $question) {
            $label = trim((string) preg_replace('/\s+/u', ' ', (string) $question['label']));
            if ($label === '') {
                throw ValidationException::withMessages(["questions.{$index}.label" => 'Give every question a label.']);
            }
            $labels[] = mb_strtolower($label);
            $ids[] = (string) $question['id'];
            $kind = (string) $question['kind'];
            $options = null;
            if ($kind === 'select') {
                $options = array_values(array_map(fn (mixed $option): string => trim((string) $option), (array) ($question['options'] ?? [])));
                if ($options === [] || in_array('', $options, true) || count($options) !== count(array_unique($options))) {
                    throw ValidationException::withMessages(["questions.{$index}.options" => 'Enter at least one distinct, non-empty choice.']);
                }
            }
            $questions[] = [
                'id' => (string) $question['id'],
                'label' => $label,
                'type' => match ($kind) {
                    'condition', 'select' => 'select',
                    'number' => 'number',
                    'checkbox' => 'checkbox',
                    default => 'text',
                },
                'kind' => $kind,
                'options' => match ($kind) {
                    'condition' => self::CONDITION_VALUES,
                    'checkbox' => ['yes', 'no'],
                    'select' => $options,
                    default => null,
                },
                'required' => (bool) $question['required'],
            ];
        }
        if (count($labels) !== count(array_unique($labels))) {
            throw ValidationException::withMessages(['questions' => 'Question labels must be unique.']);
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['questions' => 'Each question needs its own reference. Reload the checklist and try again.']);
        }
        $hasCondition = collect($questions)->contains(fn (array $question): bool => $question['required']
            && ($question['kind'] === 'condition'
                || ($question['kind'] === 'select' && in_array('pass', (array) $question['options'], true)
                    && in_array('fail', (array) $question['options'], true))));
        if (! $hasCondition) {
            throw ValidationException::withMessages(['questions' => 'Include at least one required condition question, so a check can record an issue.']);
        }

        $assignment = (string) $input['assignment'];

        return [
            'name' => trim((string) preg_replace('/\s+/u', ' ', (string) $input['name'])),
            'use_label' => trim((string) preg_replace('/\s+/u', ' ', (string) $input['use'])),
            'assignment' => $assignment,
            'assignment_asset_id' => $assignment === 'vehicle' ? $vehicleId : null,
            'evidence_required' => filter_var($input['evidence_required'], FILTER_VALIDATE_BOOL),
            'items' => $questions,
        ];
    }

    /** @param array<string,mixed> $item */
    private function kindOf(array $item): string
    {
        $kind = $item['kind'] ?? null;
        if (is_string($kind) && in_array($kind, self::KINDS, true)) {
            return $kind;
        }

        return match ($item['type'] ?? 'text') {
            'number' => 'number',
            'select' => 'select',
            'checkbox' => 'checkbox',
            default => 'text',
        };
    }

    /**
     * @param  array<string,mixed>  $item
     * @return list<string>
     */
    private function optionValues(array $item, string $kind): array
    {
        return match ($kind) {
            'condition' => self::CONDITION_VALUES,
            'checkbox' => ['yes', 'no'],
            'select' => array_values(array_unique(array_filter(
                array_map(fn (mixed $option): string => is_scalar($option) ? trim((string) $option) : '', (array) ($item['options'] ?? [])),
                fn (string $option): bool => $option !== '',
            ))),
            default => [],
        };
    }

    private function defaultUse(FleetChecklistTemplate $template): string
    {
        return match ($template->type) {
            'daily_check' => 'Daily vehicle check',
            'inspection' => 'Scheduled inspection',
            default => 'Maintenance checklist',
        };
    }
}
