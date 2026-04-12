<?php

namespace App\Domain\Governance\Support;

use App\Domain\Governance\Models\BoardPack;

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
                    'title' => $section['title'] ?? $this->sectionTitle($section['id'] ?? 'section'),
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

    protected function sectionTitle(string $key, mixed $content = null): string
    {
        return match ($key) {
            'cover' => 'Cover & Meeting Overview',
            'agenda' => 'Agenda',
            'dashboard' => 'Executive Dashboard Snapshot',
            'risk_report' => 'Risk Report',
            'finance_report' => 'Financial Summary',
            'ceo_report' => 'CEO Board Report',
            'committee_reports' => 'Committee Updates',
            'supporting_documents' => 'Supporting Documents',
            'resolutions' => 'Decision Papers',
            default => is_array($content) && isset($content['title']) ? $content['title'] : str($key)->replace('_', ' ')->title()->toString(),
        };
    }

    protected function sectionSummary(string $key, mixed $content): string
    {
        return match ($key) {
            'cover' => trim(($content['type'] ?? 'Board meeting') . ' ' . ($content['date'] ?? '')),
            'agenda' => count($content ?? []) . ' agenda item(s)',
            'dashboard' => count($content ?? []) . ' dashboard widget(s)',
            'risk_report' => (string) (data_get($content, 'executive_summary.total_active', count(data_get($content, 'top_10_risks', []))) . ' active risks'),
            'finance_report' => trim('Variance ' . ($content['variance'] ?? 'Unavailable')),
            'ceo_report' => (string) ($content['status'] ?? 'Included'),
            'committee_reports' => count($content['items'] ?? $content ?? []) . ' committee update(s)',
            'supporting_documents' => count($content['items'] ?? $content ?? []) . ' supporting document(s)',
            'resolutions' => count($content['items'] ?? $content ?? []) . ' pending resolution(s)',
            default => is_array($content) ? count($content) . ' item(s)' : 'Included',
        };
    }
}
