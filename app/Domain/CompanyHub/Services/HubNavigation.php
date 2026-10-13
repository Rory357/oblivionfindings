<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HubNavigation
{
    public const VIEWS = ['home' => 'Home', 'news' => 'News', 'shoutouts' => 'Shout-outs', 'events' => 'Events', 'resources' => 'Resources', 'pages' => 'Pages', 'people' => 'People', 'teams' => 'Teams', 'campaigns' => 'Campaigns', 'ideas' => 'Ideas', 'following' => 'Following', 'updates' => 'Updates', 'services' => 'Services', 'publishing' => 'Publishing', 'saved' => 'Saved'];

    public function __construct(private readonly HubAccess $access) {}

    public function defaults(): array
    {
        $items = collect(self::VIEWS)->map(fn ($label, $key) => ['id' => $key, 'type' => 'view', 'label' => $label, 'target' => $key, 'parent' => '', 'hidden' => false])->values()->all();
        // Preserve the previous page-navigation configuration until the shared
        // menu is first published; only published snapshot fields are used.
        $pages = HubPage::with('currentRelease')->where('kind', '!=', 'home')->get()
            ->map(fn ($page) => [$page, $page->liveRevision()?->content])
            ->filter(fn ($pair) => $pair[1]['show_in_navigation'] ?? false)
            ->sortBy(fn ($pair) => $pair[1]['navigation_order'] ?? 0)->take(33);
        foreach ($pages as [$page, $content]) {
            $items[] = ['id' => 'page-'.$page->id, 'type' => 'page', 'label' => $content['title'], 'target' => (string) $page->id, 'parent' => '', 'hidden' => false];
        }

        return $items;
    }

    private function pages(User $user): array
    {
        return HubPage::with('currentRelease')->where('kind', '!=', 'home')->get()
            ->mapWithKeys(function ($page) use ($user) {
                $revision = $this->access->visibleRevision($user, $page);

                return $revision ? [(string) $page->id => ['id' => (string) $page->id, 'title' => $revision->content['title'], 'url' => '/company-hub/read/'.$page->slug]] : [];
            })->all();
    }

    public function forUser(User $user): array
    {
        $this->access->assertEnter($user);
        $row = DB::table('hub_navigation')->where('id', 1)->first();
        $items = $row?->published ? json_decode($row->published, true) : $this->defaults();

        return $this->resolve($user, $items);
    }

    private function resolve(User $user, array $items): array
    {
        $pages = $this->pages($user);
        $items = collect($items)->filter(function ($item) use ($pages, $user) {
            if ($item['hidden']) {
                return false;
            }
            if ($item['type'] === 'page') {
                return isset($pages[$item['target']]);
            }
            if ($item['type'] === 'view' && $item['target'] === 'publishing') {
                return $user->canDo('company_hub.write') || $user->canDo('company_hub.edit_all') || $user->canDo('company_hub.layout');
            }

            return true;
        });
        $groups = $items->where('type', 'group')->pluck('id')->all();
        $items = $items->filter(fn ($item) => $item['parent'] === '' || in_array($item['parent'], $groups, true));
        $parents = $items->pluck('parent')->all();

        return $items->filter(fn ($item) => $item['type'] !== 'group' || in_array($item['id'], $parents, true))
            ->map(fn ($item) => [...$item, 'url' => match ($item['type']) {
                'view' => '/company-hub?view='.$item['target'],
                'page' => $pages[$item['target']]['url'],
                'link' => $item['target'],
                default => null,
            }])->values()->all();
    }

    public function workspace(User $user): array
    {
        $this->assertEditor($user);
        $row = DB::table('hub_navigation')->where('id', 1)->firstOrFail();
        $pages = $this->pages($user);
        $published = $row->published ? json_decode($row->published, true) : $this->defaults();
        $redact = fn ($items) => array_map(fn ($item) => $item['type'] === 'page' && ! isset($pages[$item['target']])
            ? [...$item, 'target' => '', 'label' => 'Unavailable page'] : $item, $items);

        return [
            'version' => $row->version, 'has_draft' => $row->draft !== null,
            'draft' => $redact($row->draft ? json_decode($row->draft, true) : $published),
            'published' => $redact($published), 'published_at' => $row->published_at,
            'pages' => array_values($pages), 'views' => self::VIEWS,
            'can_publish' => $user->canDo('company_hub.publish'),
        ];
    }

    private function assertEditor(User $user): void
    {
        $this->access->assertEnter($user);
        abort_unless($user->canDo('company_hub.layout'), 403);
    }

    public function validate(User $user, array $items): array
    {
        $data = Validator::make(['items' => $items], [
            'items' => 'required|array|list|min:1|max:48',
            'items.*' => 'array:id,type,label,target,parent,hidden',
            'items.*.id' => 'required|string|max:80|regex:/^[a-zA-Z0-9_-]+$/|distinct',
            'items.*.type' => ['required', Rule::in(['view', 'page', 'link', 'group'])],
            'items.*.label' => 'required|string|max:60',
            'items.*.target' => 'present|nullable|string|max:2000',
            'items.*.parent' => 'present|nullable|string|max:80',
            'items.*.hidden' => 'required|boolean',
        ])->validate()['items'];
        $pages = $this->pages($user);
        $groups = collect($data)->where('type', 'group')->pluck('id')->all();
        $targets = [];
        foreach ($data as $index => &$item) {
            $item['label'] = trim($item['label']);
            $item['target'] = trim($item['target'] ?? '');
            $item['parent'] = $item['parent'] ?? '';
            $fail = function (string $message) use ($index): never {
                throw ValidationException::withMessages(['items.'.$index => $message]);
            };
            if ($item['label'] === '') {
                $fail('Give each menu item a label.');
            }
            if ($item['parent'] !== '' && (! in_array($item['parent'], $groups, true) || $item['type'] === 'group')) {
                $fail('Choose a top-level menu group. Groups cannot be nested.');
            }
            if ($item['type'] === 'group' && $item['target'] !== '') {
                $fail('A menu group does not have a destination.');
            }
            if ($item['type'] === 'view' && ! isset(self::VIEWS[$item['target']])) {
                $fail('Choose an available hub view.');
            }
            if ($item['type'] === 'page' && ! isset($pages[$item['target']])) {
                $fail('Choose a published page you can currently read, or remove the unavailable link.');
            }
            if ($item['type'] === 'link' && (! HubContent::safeUrl($item['target']) || preg_match('#^/company-hub/read(?:/|$)#i', $item['target']))) {
                $fail('Use a secure https link or an application path. For a Company Hub page, choose Published page so its audience is respected.');
            }
            if ($item['type'] !== 'group') {
                $target = $item['type'].':'.$item['target'];
                if (in_array($target, $targets, true)) {
                    $fail('This destination is already in the menu. Edit the existing item instead.');
                }
                $targets[] = $target;
            }
        }
        unset($item);
        if ($data[0]['id'] !== 'home' || $data[0]['type'] !== 'view' || $data[0]['target'] !== 'home' || $data[0]['hidden'] || $data[0]['parent'] !== '') {
            throw ValidationException::withMessages(['items' => 'Home must stay visible and first, outside any group.']);
        }

        return $data;
    }

    public function change(User $user, string $action, int $version, ?array $items = null): array
    {
        $this->assertEditor($user);
        abort_unless(in_array($action, ['save', 'publish', 'discard'], true), 422);
        if ($action === 'publish') {
            abort_unless($user->canDo('company_hub.publish'), 403);
        }
        DB::transaction(function () use ($user, $action, $version, $items): void {
            $row = DB::table('hub_navigation')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_if((int) $row->version !== $version, 409, 'Another editor changed the menu. Reload the latest menu before saving. Your local edits are still here.');
            $changes = ['version' => $version + 1, 'updated_by' => $user->id, 'updated_at' => now()];
            if ($action === 'save') {
                $changes['draft'] = json_encode($this->validate($user, $items ?? []));
            } elseif ($action === 'publish') {
                abort_unless($row->draft, 422, 'Save a navigation draft before publishing.');
                // Recheck target audience, publication and expiry at publication.
                $changes['published'] = json_encode($this->validate($user, json_decode($row->draft, true)));
                $changes['draft'] = null;
                $changes['published_at'] = now();
            } else {
                $changes['draft'] = null;
            }
            DB::table('hub_navigation')->where('id', 1)->update($changes);
            AuditLog::create([
                'user_id' => $user->id, 'action' => 'company_hub.navigation_'.$action,
                'auditable_type' => self::class, 'auditable_id' => 1,
                'meta' => ['version' => $version + 1, 'previous_version' => $version],
            ]);
        });

        return $this->workspace($user);
    }
}
