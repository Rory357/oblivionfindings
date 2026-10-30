<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Models\User;
use Illuminate\Support\Str;

class HubCommandSearch
{
    private bool $documentTextIncomplete = false;

    public function documentTextIncomplete(): bool
    {
        return $this->documentTextIncomplete;
    }

    public function search(User $user, string $query): array
    {
        $this->documentTextIncomplete = false;
        $user = $user->fresh();
        abort_unless($user, 403);
        $access = app(HubAccess::class);
        $access->assertEnter($user);
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $stories = app(HubFeatured::class)->stories($user);
        $teams = app(HrCalendarAccessService::class)->visibleDepartmentsQuery($user)->get(['id', 'name'])->toArray();
        $entries = HubPage::with(['currentRelease', 'owner:id,name'])->where('kind', '!=', 'home')->orderByDesc('updated_at')->get()
            ->map(function (HubPage $page) use ($user, $access): ?array {
                $release = $access->visibleRevision($user, $page);
                if (! $release) {
                    return null;
                }

                return ['page' => $page, 'revision' => $release];
            })->filter();
        $names = app(HubReaderMetadata::class)->names($user, $entries->flatMap(fn ($entry) => [$entry['page']->owner_id, $entry['revision']->created_by])->all());
        $collections = app(HubCollections::class)->read($user, $entries->all());
        $documents = app(HubResourceDocuments::class);
        $files = $entries->flatMap(function ($entry) use ($documents, $user, $query) {
            $page = $entry['page'];
            $pageTitle = $entry['revision']->content['title'];

            return HubContent::visibleBlocks($entry['revision']->content)->where('type', 'file')->flatMap(function ($block) use ($documents, $user, $query, $page, $pageTitle) {
                $media = HubMedia::where('hub_page_id', $page->id)->find($block['media_id'] ?? 0);
                if (! $media) {
                    return [];
                }
                $resource = [...$documents->metadata($media), 'title' => $block['title'] ?? $media->name, 'summary' => $block['body'] ?? ''];
                if (! $documents->matches($user, $resource, $query)) {
                    return [];
                }

                return [['id' => 'document:'.$resource['document_id'], 'kind' => 'Document', 'title' => $resource['title'],
                    'description' => Str::limit($media->name.' · '.$pageTitle, 140),
                    'url' => $resource['reader_url'], 'document_id' => $resource['document_id'], 'media_id' => (int) $media->id]];
            });
        })->unique('id')->take(3)->values()->all();
        $this->documentTextIncomplete = $documents->textIncomplete();
        $pages = $entries->map(function ($entry) use ($stories, $teams, $names, $collections, $query): ?array {
            $page = $entry['page'];
            $release = $entry['revision'];
            $sourceTeams = $page->kind === 'department' ? ($release->content['team_ids'] ?? []) : null;
            $content = HubFeatured::readerContent(HubContent::readerContent($release->content), $stories, $sourceTeams);
            $summary = [...$content, ...HubReaderMetadata::fields($page, $release, $content, $teams, $names), ...($collections['fields'][$page->id] ?? [])];
            if (! HubReaderMetadata::matches($summary, $query, HubReaderMetadata::body($content))) {
                return null;
            }

            return ['id' => 'page:'.$page->id, 'kind' => $page->kind === 'news' ? 'Story' : 'Page', 'title' => $content['title'],
                'description' => Str::limit(strip_tags($content['summary'] ?? ''), 140), 'url' => '/company-hub/read/'.$page->slug];
        })->filter()->take(5)->values()->all();
        $people = collect(app(HubDirectory::class)->people($user, $query))->take(3)->map(fn ($person) => [
            'id' => 'person:'.$person['id'], 'kind' => 'Person', 'title' => $person['name'],
            'description' => implode(' · ', array_filter([$person['position'], $person['department'], $person['site']])),
            'url' => '/company-hub/people/'.$person['id'],
        ])->all();
        $services = collect(app(HubServices::class)->search($user, $query))->take(3)->map(fn ($service) => [
            'id' => 'service:'.$service['id'], 'kind' => 'Service', 'title' => $service['name'],
            'description' => Str::limit(strip_tags($service['description'] ?? ''), 140),
            'url' => $service['url'] ?? '/company-hub?view=services&search='.rawurlencode($service['name']),
        ])->all();

        return array_slice([...$pages, ...$files, ...$people, ...$services], 0, 9);
    }
}
