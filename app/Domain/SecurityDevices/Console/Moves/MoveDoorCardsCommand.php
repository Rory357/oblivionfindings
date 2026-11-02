<?php

namespace App\Domain\SecurityDevices\Console\Moves;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Access\Models\AccessPolicy;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * S&D data move "Old local door cards and schedules → UniFi people" (plan: "Matched to UniFi people by
 * employee number in a dry run; anything that doesn't match is listed for a manager, and nothing is
 * sent until they confirm").
 *
 * The old local records (access_control_credentials, _schedules, _credential_device) were never sent
 * to UniFi. Each card that is still meant to work is matched to the UniFi Access person at the same
 * Site's console with the holder's employee number, by the rule the Access sync uses (an active HR
 * profile at that Site). `--apply` only records the match as "suggested", the state the sync itself
 * writes, so a manager confirms each one in pop-up 90. Nothing is sent to UniFi; the old records are
 * not changed or deleted. Schedules are listed beside the UniFi policy with the same name, if any.
 */
final class MoveDoorCardsCommand extends DataMoveCommand
{
    protected $signature = 'security-devices:moves:door-cards
        {--apply : Record the suggested matches for a manager to confirm. Without it, a dry run that changes nothing}
        {--actor= : The user id every change is recorded against (needed with --apply)}
        {--force : Let --apply run in production}';

    protected $description = 'S&D data move: match old local door cards to UniFi Access people by employee number, as suggestions (dry run unless --apply)';

    private const string MOVE = 'door_cards';

    /** Cards still meant to open doors. Revoked, revoking and failed-to-revoke cards need nothing. */
    private const array LIVE_STATUSES = [
        AccessControlCredential::STATUS_PENDING_ISSUE,
        AccessControlCredential::STATUS_ACTIVE,
        AccessControlCredential::STATUS_ISSUE_FAILED,
    ];

    public function handle(): int
    {
        $actor = null;
        if ($this->applying() && ! ($actor = $this->actorForApply()) instanceof User) {
            return self::FAILURE;
        }

        $credentials = AccessControlCredential::query()->orderBy('id')->get();
        $live = $credentials->filter(fn (AccessControlCredential $card): bool => in_array($card->status, self::LIVE_STATUSES, true)
            && $card->revoked_at === null
            && ($card->valid_until === null || $card->valid_until->isFuture()))->values();
        $siteIds = $credentials->pluck('site_id')->unique()->values();
        $siteNames = Site::query()->whereIn('id', $siteIds)->pluck('name', 'id');
        $consoles = AccessConsole::query()->whereIn('site_id', $siteIds)->get(['id', 'site_id'])->keyBy('site_id');
        $staffIds = $live->where('holder_type', AccessControlCredential::HOLDER_STAFF)->pluck('holder_id')->unique()->values();
        $staff = User::query()->whereIn('id', $staffIds)->get(['id', 'name'])->keyBy('id');
        $profiles = HrEmployeeProfile::query()->whereIn('user_id', $staffIds)->where('is_active', true)
            ->get(['user_id', 'employee_number', 'primary_site_id', 'secondary_site_ids'])->groupBy('user_id');

        /** @var array<int, array{person: AccessPerson, user_id: int, cards: list<int>, state: string, site: string, holder: string}> $matches person id => match */
        $matches = [];
        $unmatched = [];
        foreach ($live as $card) {
            $site = (string) ($siteNames[$card->site_id] ?? 'Site #'.$card->site_id);
            if ($card->holder_type !== AccessControlCredential::HOLDER_STAFF) {
                // A resident's card: never named here, and there is no employee number to match by.
                $unmatched[] = [$card->id, $site, 'A resident', '—', 'A resident’s card has no employee number to match by. A manager sorts it out in Access.'];

                continue;
            }

            $holder = $staff->get((int) $card->holder_id);
            $holderName = $holder instanceof User ? (string) $holder->name : 'Staff #'.$card->holder_id;
            $why = null;
            $console = $consoles->get($card->site_id);
            $employeeNumber = $this->employeeNumberAt($profiles->get((int) $card->holder_id, collect()), (int) $card->site_id);
            $people = collect();
            if ($console === null) {
                $why = 'This Site has no UniFi Access console connected yet';
            } elseif (! $holder instanceof User) {
                $why = 'The card holder is no longer a user';
            } elseif ($employeeNumber === null) {
                $why = 'No employee number in HR for them at this Site';
            } else {
                $people = AccessPerson::query()->where('console_id', $console->id)->where('employee_number', $employeeNumber)->get();
                $why = match (true) {
                    $people->isEmpty() => 'Not in UniFi Access at this Site',
                    $people->count() > 1 => 'More than one UniFi person has their employee number',
                    default => null,
                };
            }
            if ($why === null) {
                /** @var AccessPerson $person */
                $person = $people->first();
                $state = (string) ($person->match_state ?: 'unmatched');
                $samePerson = (int) $person->user_id === (int) $holder->id;
                $why = match (true) {
                    // A resident's fob is never made a staff card here; a manager sorts it out.
                    $person->kind === 'resident' || $person->client_id !== null => 'That UniFi person is a resident’s fob: a manager checks it',
                    in_array($state, ['matched', 'suggested'], true) && ! $samePerson => 'Their UniFi person is '.$state.' to someone else',
                    isset($matches[$person->id]) && $matches[$person->id]['user_id'] !== (int) $holder->id => 'Two card holders point to the same UniFi person',
                    default => null,
                };
            }
            if ($why !== null) {
                $unmatched[] = [$card->id, $site, $holderName, (string) $card->label, $why];

                continue;
            }

            $matches[$person->id] ??= ['person' => $person, 'user_id' => (int) $holder->id, 'cards' => [], 'state' => $state, 'site' => $site, 'holder' => $holderName];
            $matches[$person->id]['cards'][] = (int) $card->id;
        }

        $toSuggest = array_filter($matches, fn (array $match): bool => $match['state'] === 'unmatched');
        $matchRows = array_values(array_map(fn (array $match): array => [
            implode(', ', $match['cards']),
            $match['site'],
            $match['holder'],
            trim($match['person']->first_name.' '.$match['person']->last_name) ?: 'UniFi person #'.$match['person']->id,
            match ($match['state']) {
                'matched' => 'Already confirmed',
                'suggested' => 'Already suggested',
                default => 'Will be suggested',
            },
        ], $matches));

        $schedules = AccessControlSchedule::query()->whereIn('site_id', $siteIds)->orderBy('site_id')->orderBy('name')->get(['id', 'site_id', 'name', 'is_active']);
        $scheduleRows = $schedules->map(function (AccessControlSchedule $schedule) use ($siteNames, $consoles, $live): array {
            $console = $consoles->get($schedule->site_id);
            $policy = $console !== null && AccessPolicy::query()->where('console_id', $console->id)->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $schedule->name)])->exists();

            return [
                $schedule->id,
                (string) ($siteNames[$schedule->site_id] ?? 'Site #'.$schedule->site_id),
                (string) $schedule->name,
                $schedule->is_active ? 'Yes' : 'No',
                $live->where('access_schedule_id', $schedule->id)->count(),
                $console === null ? 'No console yet' : ($policy ? 'Yes' : 'No: a manager sets one up in UniFi'),
            ];
        })->all();
        $doorLinks = DB::table('access_control_credential_device')->count();

        $this->components->info('Old local door cards → UniFi people'.($this->applying() ? '' : ' (dry run)'));
        $this->line('  Local cards: '.$credentials->count().' · still meant to work: '.$live->count().' · ended (revoked or expired): '.($credentials->count() - $live->count()));
        $this->line('  Matched to a UniFi person: '.count($matches).' ('.count($toSuggest).' to suggest) · not matched, for a manager: '.count($unmatched));
        $this->line('  Local schedules: '.$schedules->count().' · local card-to-door links: '.$doorLinks.' (listed only; UniFi’s own policies decide its doors)');
        $this->line('  Nothing is sent to UniFi, and the old local records are not changed or deleted.');

        $this->section('Matched by employee number (a manager confirms each in pop-up 90)', ['Cards', 'Site', 'Staff', 'UniFi person', 'Now'], $matchRows);
        $this->section('Not matched: for a manager', ['Card', 'Site', 'Holder', 'Label', 'Why'], $unmatched);
        $this->section('Local schedules', ['ID', 'Site', 'Schedule', 'Active', 'Cards using it', 'UniFi policy with this name'], $scheduleRows);

        if (! $actor instanceof User) {
            $this->dryRunFooter(count($toSuggest), 'record '.count($toSuggest).' suggested matches');

            return self::SUCCESS;
        }

        $suggested = 0;
        $skipped = [];
        foreach ($toSuggest as $personId => $match) {
            try {
                $changed = DB::transaction(function () use ($personId, $match, $actor): bool {
                    $person = AccessPerson::query()->whereKey($personId)->lockForUpdate()->first();
                    if (! $person instanceof AccessPerson
                        || (string) ($person->match_state ?: 'unmatched') !== 'unmatched'
                        || $person->employee_number !== $match['person']->employee_number) {
                        return false;
                    }
                    // The same suggestion the Access sync writes (UnifiAccessSync::people): a manager confirms it.
                    $person->forceFill(['user_id' => $match['user_id'], 'match_state' => 'suggested', 'kind' => 'staff', 'client_id' => null])->save();
                    AuditLogger::logOrFail('security_devices.data_move.'.self::MOVE.'.match_suggested', $person, [
                        'actor_id' => (int) $actor->id,
                        'console_id' => (int) $person->console_id,
                        'user_id' => $match['user_id'],
                        'access_control_credential_ids' => $match['cards'],
                    ]);

                    return true;
                }, 3);
                if ($changed) {
                    $suggested++;
                } else {
                    $skipped[] = [$personId, 'It changed since (confirmed, suggested or renumbered), so it was left alone'];
                }
            } catch (Throwable $exception) {
                $skipped[] = [$personId, 'It couldn’t be recorded ('.class_basename($exception).')'];
            }
        }

        $this->newLine();
        $this->components->info("Done: recorded {$suggested} suggested matches. A manager confirms each one in pop-up 90. Nothing was sent to UniFi.");
        $this->section('Left alone', ['UniFi person', 'Why'], $skipped);

        return self::SUCCESS;
    }

    /**
     * The holder's employee number from an active HR profile at the Site, the rule the Access sync and
     * pop-up 90 use (primary Site, or one of their other Sites).
     *
     * @param  iterable<HrEmployeeProfile>  $profiles
     */
    private function employeeNumberAt(iterable $profiles, int $siteId): ?string
    {
        foreach ($profiles as $profile) {
            $secondary = is_array($profile->secondary_site_ids) ? array_map('intval', $profile->secondary_site_ids) : [];
            $number = trim((string) $profile->employee_number);
            if ($number !== '' && ((int) $profile->primary_site_id === $siteId || in_array($siteId, $secondary, true))) {
                return $number;
            }
        }

        return null;
    }
}
