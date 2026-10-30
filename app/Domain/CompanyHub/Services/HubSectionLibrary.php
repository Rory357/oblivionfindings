<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HubSectionLibrary
{
    public function __construct(private readonly HubAccess $access, private readonly HubContent $content) {}

    private function available(User $user, object $preset, ?HubPage $source): bool
    {
        if ($preset->scope === 'personal') {
            return (int) $preset->owner_id === (int) $user->id;
        }

        // Sharing a layout never bypasses the source page's author/site rules.
        // Retain the saved audience too, in case the source later becomes broader.
        return $source && $this->access->canEdit($user, $source)
            && $this->access->canAuthorAudience($user, ['audience' => json_decode($preset->source_audience, true)]);
    }

    public function workspace(User $user, HubPage $page): array
    {
        $this->access->assertEdit($user, $page);
        $rows = DB::table('hub_section_presets')
            ->where(fn ($query) => $query->where('owner_id', $user->id)->orWhere('scope', 'shared'))->orderBy('name')->get();
        $sources = HubPage::whereIn('id', $rows->pluck('source_page_id')->filter())->get()->keyBy('id');
        $available = $rows->filter(fn ($preset) => $this->available($user, $preset, $sources->get($preset->source_page_id)));

        return [
            'can_share' => $user->canDo('company_hub.layout'),
            'presets' => $available->whereNull('deleted_at')
                ->map(fn ($preset) => [
                    'id' => $preset->id, 'name' => $preset->name, 'scope' => $preset->scope,
                    'version' => $preset->version, 'section' => json_decode($preset->section, true),
                    'can_remove' => $preset->scope === 'personal' || $user->canDo('company_hub.layout'),
                ])->values()->all(),
            'removed_presets' => $available->whereNotNull('deleted_at')
                ->filter(fn ($preset) => $preset->scope === 'personal' || $user->canDo('company_hub.layout'))
                ->sortByDesc('deleted_at')->map(fn ($preset) => [
                    'id' => $preset->id, 'name' => $preset->name, 'scope' => $preset->scope, 'version' => $preset->version,
                ])->values()->all(),
        ];
    }

    public function save(User $user, HubPage $page, string $name, array $section, string $scope): void
    {
        $this->access->assertEdit($user, $page);
        abort_if($scope === 'shared' && ! $user->canDo('company_hub.layout'), 403);
        $section = $this->content->validate([...$page->draft, 'sections' => [$section]], $user, $page)['sections'][0];
        foreach ($section['blocks'] as &$block) {
            unset($block['media_id']);
        }
        unset($block);
        DB::transaction(function () use ($user, $page, $name, $section, $scope): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('hub_section_presets')->where('owner_id', $user->id)->whereNull('deleted_at')->count() >= 50, 422, 'You can keep up to 50 reusable sections.');
            $id = DB::table('hub_section_presets')->insertGetId([
                'owner_id' => $user->id, 'name' => $name, 'section' => json_encode($section),
                'scope' => $scope, 'source_page_id' => $page->id,
                'source_audience' => json_encode($page->draft['audience']),
                'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($user, $id, 'save', 1, $scope);
        });
    }

    public function use(User $user, HubPage $page, int $id, int $version): array
    {
        $this->access->assertEdit($user, $page);
        $preset = DB::table('hub_section_presets')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($preset && $this->available($user, $preset, HubPage::find($preset->source_page_id)), 404);
        abort_unless((int) $preset->version === $version, 409, 'This section changed. Refresh the library before inserting it.');

        return json_decode($preset->section, true);
    }

    public function removeOrRestore(User $user, HubPage $page, int $id, ?int $version, bool $restore): int
    {
        $this->access->assertEdit($user, $page);

        return DB::transaction(function () use ($user, $id, $version, $restore): int {
            $preset = DB::table('hub_section_presets')->where('id', $id)->lockForUpdate()->first();
            abort_unless($preset && $this->available($user, $preset, HubPage::find($preset->source_page_id)), 404);
            abort_if($preset->scope === 'shared' && ! $user->canDo('company_hub.layout'), 403);
            abort_unless($version !== null && (int) $preset->version === $version, 409, 'The library changed. Refresh it before trying again.');
            abort_unless($restore === ($preset->deleted_at !== null), 409, 'The library changed. Refresh it before trying again.');
            if ($restore) {
                User::whereKey($preset->owner_id)->lockForUpdate()->firstOrFail();
                abort_if(DB::table('hub_section_presets')->where('owner_id', $preset->owner_id)->whereNull('deleted_at')->count() >= 50, 422, 'Remove another saved section before restoring this one.');
            }
            $next = (int) $preset->version + 1;
            DB::table('hub_section_presets')->where('id', $id)->update(['deleted_at' => $restore ? null : now(), 'version' => $next, 'updated_at' => now()]);
            $this->audit($user, $id, $restore ? 'restore' : 'remove', $next, $preset->scope);

            return $next;
        });
    }

    private function audit(User $user, int $id, string $action, int $version, string $scope): void
    {
        AuditLog::create(['user_id' => $user->id, 'action' => 'company_hub.section_'.$action, 'auditable_type' => self::class, 'auditable_id' => $id, 'meta' => ['version' => $version, 'scope' => $scope]]);
    }
}
