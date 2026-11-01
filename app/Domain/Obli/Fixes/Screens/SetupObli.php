<?php

namespace App\Domain\Obli\Fixes\Screens;

use App\Domain\Obli\Fixes\ObliFixList;
use App\Domain\Obli\Fixes\ObliFixSettings;
use App\Domain\Obli\Fixes\ObliScreenPictures;
use App\Domain\Obli\ObliAiSettings;
use App\Domain\Obli\ObliDiagnostics;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSupportSettings;
use App\Models\Role;
use App\Models\User;

/**
 * S&D Phase I: Setup › Computers & Obli › Obli (pg-sdr-15i) and › Fixes Obli can make (15j).
 *
 * 15i shows Obli's switches from today's Settings › Obli as Setup rows: "Obli for staff" is today's
 * Obli helper switch (changed through today's settings save, by the same people), what Obli may
 * read is today's diagnostic choice (changed in Settings › Obli), and "Let Obli use the computer
 * to fix things" is the one new switch (off until an Obli settings admin turns it on). The rules
 * around it are facts, not switches: they're enforced every time.
 */
final class SetupObli
{
    public static function canSee(User $viewer): bool
    {
        return $viewer->canDo(ObliFixList::MANAGE) || $viewer->canDo(ObliScreenPictures::PERMISSION)
            || ObliFixSettings::canChange($viewer) || app(EndpointRemoteSupportSettings::class)->canManage($viewer);
    }

    public static function canSeeFixes(User $viewer): bool
    {
        return $viewer->canDo(ObliFixList::MANAGE);
    }

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $remote = app(EndpointRemoteSupportSettings::class);
        $ai = app(ObliAiSettings::class)->current();
        $fixes = app(ObliFixSettings::class);
        $current = $fixes->current();
        $categories = array_values(array_intersect(ObliDiagnostics::CATEGORIES, (array) $ai['diagnostic_categories']));
        $words = ['system' => 'disk, memory and Windows', 'software' => 'browsers and Teams', 'services' => 'printing, updates and protection'];
        $who = Role::query()->with('permissions:id,key')->orderBy('level')->get(['id', 'name', 'label', 'level'])
            ->filter(fn (Role $role): bool => $role->permissions->contains('key', ObliScreenPictures::PERMISSION))
            ->map(fn (Role $role): string => (string) ($role->label ?: ucfirst(str_replace('_', ' ', (string) $role->name))))
            ->values()->all();

        return [
            'helper' => [
                'on' => $remote->obliHelperEnabled(),
                'computers_on' => $remote->endpointEnabled(),
                // On by the server's own configuration: it can't be switched off here.
                'can_change' => $remote->canManage($viewer) && ! (bool) config('endpoint_management.obli.enabled'),
            ],
            'health' => [
                'on' => $categories !== [],
                'reads' => $categories === [] ? null : ucfirst(implode(', ', array_map(fn (string $category): string => $words[$category], $categories))),
            ],
            'fixes' => [
                'on' => $current['enabled'],
                'version' => $current['version'],
                'blocker' => $current['enabled'] ? $fixes->blocker() : null,
                'can_change' => ObliFixSettings::canChange($viewer) && $fixes->schemaReady(),
            ],
            'pictures' => [
                'days' => ObliFixSettings::PICTURE_DAYS,
                'who' => $who === [] ? 'Nobody yet' : implode(', ', array_slice($who, 0, 3)).(count($who) > 3 ? ' and '.(count($who) - 3).' more' : ''),
            ],
            'ai' => [
                'enabled' => (bool) $ai['enabled'],
                'provider' => match ($ai['provider']) {
                    'anthropic' => 'Anthropic Claude',
                    'openai' => 'OpenAI',
                    default => 'Not set',
                },
                'computer_use' => $ai['provider'] === 'anthropic',
                'limit' => '$'.number_format((float) $ai['daily_budget_usd'], 2).' a day · $'.number_format((float) $ai['monthly_budget_usd'], 2).' a month',
            ],
            'limits' => ['minutes' => ObliFixSettings::SESSION_MINUTES, 'actions' => ObliFixSettings::MAX_ACTIONS],
            'settings_href' => '/settings/obli',
        ];
    }

    /** "Obli for staff": today's Obli helper switch, through today's settings save (audited there). */
    public function setHelper(User $actor, bool $enabled): void
    {
        $settings = app(EndpointRemoteSupportSettings::class);
        abort_unless($settings->canManage($actor), 403);
        $current = $settings->current();
        $settings->save($actor, [
            'expected_actor_id' => (int) $actor->id,
            'expected_version' => (int) $current['version'],
            'endpoint_management_enabled' => (bool) $current['endpoint_management_enabled'],
            'obli_helper_enabled' => $enabled,
            'obli_technician_enabled' => (bool) $current['obli_technician_enabled'],
            'remote_support_enabled' => (bool) $current['remote_support_enabled'],
            'relay_urls' => $current['relay_urls'],
            'relay_secret' => null,
            'clear_relay_secret' => false,
        ]);
    }
}
