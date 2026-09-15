<?php

namespace App\Domain\Governance\Support;

use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\CeoBoardReport;
use App\Domain\Governance\Models\GovernanceDocument;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class BoardPackPresenter
{
    public function present(BoardPack $pack): array
    {
        $normalized = $this->normalizeManifest($pack->document_manifest ?? []);
        $recipientCount = count(array_unique($pack->distributed_to ?? []));
        $readCount = count(array_unique(array_column($pack->read_tracking ?? [], 'board_member_id')));
        $downloadCount = $pack->downloadCount();

        return [
            'manifestSections' => $normalized['manifest_sections'],
            'contentSections' => $normalized['content_sections'],
            'distributionStats' => [
                'intended_recipients' => $recipientCount,
                'read_count' => $readCount,
                'download_count' => $downloadCount,
                'outstanding_reads' => max($recipientCount - $readCount, 0),
                'read_rate' => $recipientCount > 0 ? round(($readCount / $recipientCount) * 100, 1) : 0,
                'download_rate' => $recipientCount > 0 ? round(($downloadCount / $recipientCount) * 100, 1) : 0,
            ],
        ];
    }

    /**
     * The pack as a reader sees it: each section in order, with a link to the
     * live record wherever this viewer is allowed to open it (the CEO report,
     * each resolution, each supporting document). Records the viewer can't
     * open are listed by title only — never linked.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readingSections(BoardPack $pack, User $viewer): array
    {
        $manifest = $pack->document_manifest ?? [];
        $content = $manifest['content_sections'] ?? $manifest['content'] ?? [];
        if (! is_array($content)) {
            return [];
        }

        $access = app(GovernanceRecordAccessService::class);
        $sections = [];

        foreach ($content as $key => $section) {
            $key = is_string($key) ? $key : (is_array($section) ? (string) ($section['id'] ?? 'section') : 'section');
            $row = [
                'key' => $key,
                'title' => $this->sectionTitle($key, $section),
                'summary' => $this->sectionSummary($key, $section),
                'href' => null,
                'items' => [],
            ];

            if ($key === 'ceo_report' && $pack->governance_meeting_id) {
                $report = CeoBoardReport::query()->where('governance_meeting_id', $pack->governance_meeting_id)->first();
                if ($report && Gate::forUser($viewer)->allows('view', $report)) {
                    $row['href'] = "/governance/ceo-reports/{$report->id}";
                }
            }

            if ($key === 'resolutions') {
                $items = $this->items($section);
                $ids = collect($items)->pluck('id')->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->all();
                $records = Resolution::query()->with('meeting')->whereIn('id', $ids)->get()->keyBy('id');

                $row['items'] = collect($items)->map(function ($item) use ($records, $access, $viewer) {
                    $id = is_numeric($item['id'] ?? null) ? (int) $item['id'] : null;
                    $record = $id !== null ? $records->get($id) : null;
                    $canOpen = $record && $access->canViewResolution($viewer, $record);

                    return [
                        'title' => (string) ($item['title'] ?? 'Resolution'),
                        'reference' => $item['reference'] ?? null,
                        'href' => $canOpen ? "/governance/resolutions/{$record->id}" : null,
                    ];
                })->values()->all();
            }

            if ($key === 'supporting_documents') {
                $items = $this->items($section);
                $ids = collect($items)->pluck('id')->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->all();
                $records = GovernanceDocument::query()->whereIn('id', $ids)->get()->keyBy('id');

                $row['items'] = collect($items)->map(function ($item) use ($records, $access, $viewer) {
                    $id = is_numeric($item['id'] ?? null) ? (int) $item['id'] : null;
                    $record = $id !== null ? $records->get($id) : null;
                    $canOpen = $record && $access->canViewDocument($viewer, $record);

                    return [
                        'title' => (string) ($item['title'] ?? 'Document'),
                        'reference' => null,
                        'href' => $canOpen ? "/governance/documents/{$record->id}" : null,
                    ];
                })->values()->all();
            }

            if ($key === 'committee_reports') {
                $row['items'] = collect($this->items($section))->map(fn ($item) => [
                    'title' => (string) ($item['name'] ?? $item['title'] ?? 'Committee'),
                    'reference' => null,
                    'href' => null,
                ])->values()->all();
            }

            $sections[] = $row;
        }

        return $sections;
    }

    public function normalizeManifest(array $manifest): array
    {
        [$manifestSections, $contentSections] = match (true) {
            isset($manifest['manifest_sections']) || isset($manifest['content_sections']) => [
                $manifest['manifest_sections'] ?? [],
                $manifest['content_sections'] ?? [],
            ],
            isset($manifest['content']) => [
                collect($manifest)
                    ->filter(fn ($value, $key) => $key !== 'content' && is_array($value) && isset($value['id']))
                    ->values()
                    ->all(),
                is_array($manifest['content']) ? $manifest['content'] : [],
            ],
            array_is_list($manifest) => [$manifest, []],
            default => [
                collect($manifest)
                    ->filter(fn ($value, $key) => is_numeric((string) $key) && is_array($value))
                    ->values()
                    ->all(),
                collect($manifest)
                    ->reject(fn ($value, $key) => is_numeric((string) $key))
                    ->all(),
            ],
        };

        if ($manifestSections === [] && is_array($contentSections) && $contentSections !== []) {
            $manifestSections = $this->manifestFromContent($contentSections);
        }

        return [
            'manifest_sections' => collect($manifestSections)
                ->filter(fn ($section) => is_array($section))
                ->map(fn (array $section) => [
                    'id' => $section['id'] ?? 'section',
                    'title' => $this->plainManifestTitle($section),
                    'type' => $section['type'] ?? 'auto',
                    'included' => (bool) ($section['included'] ?? true),
                ])
                ->values()
                ->all(),
            'content_sections' => collect($contentSections)
                ->map(function ($content, $key) {
                    $sectionKey = is_string($key) ? $key : ($content['id'] ?? 'section');

                    return [
                        'key' => $sectionKey,
                        'title' => $this->sectionTitle($sectionKey, $content),
                        'summary' => $this->sectionSummary($sectionKey, $content),
                        'type' => $this->sectionType($sectionKey),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    protected function manifestFromContent(array $contentSections): array
    {
        return collect($contentSections)
            ->map(fn ($content, $key) => [
                'id' => is_string($key) ? $key : ($content['id'] ?? 'section'),
                'title' => $this->sectionTitle(is_string($key) ? $key : ($content['id'] ?? 'section'), $content),
                'type' => $this->sectionType(is_string($key) ? $key : ($content['id'] ?? 'section')),
                'included' => true,
            ])
            ->values()
            ->all();
    }

    protected function sectionType(string $key): string
    {
        return match ($key) {
            'supporting_documents' => 'attachment',
            default => 'auto',
        };
    }

    /**
     * Stored manifests carry the titles written when the pack was built
     * ("Cover & Meeting Overview", "Paper: …"); show the plain names instead.
     */
    protected function plainManifestTitle(array $section): string
    {
        $id = (string) ($section['id'] ?? 'section');
        $stored = isset($section['title']) ? (string) $section['title'] : null;

        if (str_starts_with($id, 'res_') || str_starts_with($id, 'doc_')) {
            return $stored !== null ? (string) preg_replace('/^Paper:\s*/u', '', $stored) : 'Document';
        }

        return $this->sectionTitle($id, $stored !== null ? ['title' => $stored] : null);
    }

    protected function sectionTitle(string $key, mixed $content = null): string
    {
        return match ($key) {
            'cover' => 'Meeting details',
            'agenda' => 'Agenda',
            'dashboard' => 'Organisation dashboard',
            'risk_report' => 'Risk report',
            'finance_report' => 'Finance summary',
            'ceo_report' => 'CEO report',
            'committee_reports' => 'Committee updates',
            'supporting_documents' => 'Supporting documents',
            'resolutions' => 'Resolutions',
            default => is_array($content) && isset($content['title'])
                ? GovernanceLabels::sentence((string) $content['title'])
                : GovernanceLabels::humanise($key),
        };
    }

    protected function sectionSummary(string $key, mixed $content): string
    {
        return match ($key) {
            'cover' => trim(implode(' · ', array_filter([
                is_array($content) ? ($content['type'] ?? null) : null,
                is_array($content) ? ($content['date'] ?? null) : null,
            ]))) ?: 'Meeting details',
            'agenda' => $this->counted(is_array($content) ? count($content) : 0, 'agenda item', 'No agenda items'),
            'dashboard' => $this->counted(is_array($content) ? count($content) : 0, 'figure from the organisation dashboard', 'No dashboard figures', 'figures from the organisation dashboard'),
            'risk_report' => $this->counted(
                (int) data_get($content, 'executive_summary.total_active', count(data_get($content, 'top_10_risks', []))),
                'open risk',
                'No open risks',
            ),
            'finance_report' => $this->financeSummary(is_array($content) ? $content : []),
            'ceo_report' => is_array($content) && isset($content['status'])
                ? GovernanceLabels::label('ceo_report_status', (string) $content['status'])
                : 'Included',
            'committee_reports' => $this->counted(count($this->items($content)), 'committee update', 'No committee updates'),
            'supporting_documents' => $this->counted(count($this->items($content)), 'supporting document', 'No supporting documents'),
            'resolutions' => $this->counted(count($this->items($content)), 'resolution', 'No resolutions'),
            default => is_array($content) ? $this->counted(count($content), 'item', 'Nothing included') : 'Included',
        };
    }

    /** @return array<int, array<string, mixed>> */
    protected function items(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        $items = $content['items'] ?? $content;

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    protected function financeSummary(array $content): string
    {
        $utilisation = $content['utilization'] ?? null;
        if (is_string($utilisation) && $utilisation !== '' && $utilisation !== 'Unavailable') {
            return "{$utilisation} of the budget spent";
        }

        $difference = $content['variance'] ?? null;
        if (is_string($difference) && $difference !== '' && $difference !== 'Unavailable') {
            return "Difference from budget: {$difference}";
        }

        return 'Budget figures not available';
    }

    protected function counted(int $count, string $one, string $none, ?string $many = null): string
    {
        if ($count === 0) {
            return $none;
        }

        return GovernanceWording::count($count, $one, $many);
    }
}
