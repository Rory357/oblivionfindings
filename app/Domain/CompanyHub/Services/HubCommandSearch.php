<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Models\User;
use Illuminate\Support\Str;

class HubCommandSearch
{
    public function search(User $user, string $query): array
    {
        $access = app(HubAccess::class);
        $access->assertEnter($user);
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $needle = mb_strtolower($query);
        $pages = HubPage::with(['currentRelease', 'owner:id,name'])->where('kind', '!=', 'home')->orderByDesc('updated_at')->get()
            ->map(function (HubPage $page) use ($user, $access, $needle): ?array {
                $release = $access->visibleRevision($user, $page);
                if (! $release) {
                    return null;
                }
                $content = $release->content;
                $body = collect($content['sections'])->flatMap(fn ($section) => $section['blocks'])->pluck('body')->implode(' ');
                if (($content['resource_type'] ?? null) === 'Checklist') {
                    $body .= ' '.collect($content['checklist'] ?? [])->pluck('title')->implode(' ');
                }
                if (! str_contains(mb_strtolower(implode(' ', [$content['title'], $content['summary'] ?? '', $content['category'] ?? '', strip_tags($body)])), $needle)) {
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

        return array_slice([...$pages, ...$people, ...$services], 0, 9);
    }
}
