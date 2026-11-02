<?php

namespace App\Domain\Obli\Fixes\Screens;

use App\Domain\Obli\Fixes\ObliFixInstructions;
use App\Domain\Obli\Fixes\ObliFixList;
use App\Domain\Obli\Fixes\ObliFixRequests;
use App\Domain\Obli\Fixes\ObliFixSettings;
use App\Domain\Obli\Fixes\ObliScreenPictures;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSupportSettings;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * S&D Phase I: a computer's Obli help tab (drawing pg-sdr-9d) and one help session's record
 * (pop-up 96). Every time someone asked Obli for help on this PC: what they asked, the plan they
 * agreed to, each step and how it ended. Pictures are never sent here: they open only through
 * pop-up 94 (ObliScreenPictures), with a reason.
 */
final class ObliHelpTab
{
    /** IT people who turn fixes on or may open the pictures see the sessions. */
    public static function canSee(User $viewer): bool
    {
        return $viewer->canDo(ObliFixList::MANAGE) || $viewer->canDo(ObliScreenPictures::PERMISSION);
    }

    /** @return array<string, mixed> */
    public function present(User $viewer, Device $device): array
    {
        abort_unless(self::canSee($viewer), 403);
        $rows = DB::table('obli_fix_sessions')->where('device_id', $device->id)->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();
        $names = DB::table('users')->whereIn('id', $rows->pluck('user_id')->unique()->all())->pluck('name', 'id');
        $sessions = $rows->map(fn (object $row): array => $this->summary($row, $names))->values()->all();
        $fixes = app(ObliFixList::class);
        $on = count($fixes->onFor($device));
        $available = count($fixes->catalogue());
        $settings = app(ObliFixSettings::class);
        $blocker = $settings->blocker();
        $requests = DB::table('obli_fix_requests')->where('device_id', $device->id)->where('state', 'requested')->orderByDesc('asked_at')->limit(5)->get();

        return [
            'sessions' => $sessions,
            'latest' => $rows->isEmpty() ? null : $this->record($viewer, (string) $rows->first()->uuid),
            'may' => [
                ['Use the computer', $blocker === null ? 'After showing its plan and one OK' : $blocker],
                ['Fixes through the Oblivion service', $on.' of '.$available.' turned on (Setup)'],
                ['Screen pictures', ObliFixSettings::PICTURE_DAYS.' days, IT admins only'],
                ['Remote support', app(EndpointRemoteSupportSettings::class)->sessionBrokerEnabled() ? 'After the person clicks Allow' : 'Off'],
            ],
            'requests' => $requests->map(fn (object $request): array => [
                'id' => (int) $request->id,
                'asked_at' => CarbonImmutable::parse($request->asked_at)->toIso8601String(),
                'who' => (string) (DB::table('users')->where('id', $request->user_id)->value('name') ?? 'Someone'),
            ])->values()->all(),
            'can' => ['open_pictures' => ObliScreenPictures::canOpen($viewer), 'decide' => ObliFixRequests::canDecide($viewer), 'manage_fixes' => $viewer->canDo(ObliFixList::MANAGE)],
        ];
    }

    /**
     * Pop-up 96: one help session, with every step. Never the pictures themselves.
     *
     * @return array<string, mixed>
     */
    public function record(User $viewer, string $uuid): array
    {
        abort_unless(self::canSee($viewer), 403);
        $row = DB::table('obli_fix_sessions')->where('uuid', $uuid)->first();
        abort_unless($row && $row->device_id, 404);
        $device = Device::query()->find($row->device_id);
        abort_unless($device instanceof Device, 404);
        app(SecurityDevicesAccessService::class)->assertCanViewDevice($viewer, $device);
        $who = (string) (DB::table('users')->where('id', $row->user_id)->value('name') ?? 'Someone who has left');
        $plan = self::decrypt($row->plan_encrypted);
        $outcome = self::decrypt($row->outcome_encrypted);
        $definitions = app(ObliFixList::class)->catalogue();
        $pictures = DB::table('obli_screen_pictures')->where('session_id', $row->id)->get(['id', 'delete_after']);
        $live = $pictures->filter(fn (object $picture): bool => CarbonImmutable::parse($picture->delete_after)->isFuture());
        $steps = DB::table('obli_fix_steps')->where('session_id', $row->id)->orderBy('id')->limit(120)->get()
            ->map(function (object $step) use ($definitions, $live): array {
                $narration = $step->narration_encrypted ? self::text($step->narration_encrypted) : null;
                $detail = $step->result_encrypted ? self::text($step->result_encrypted) : null;
                $label = $step->fix_key ? ($definitions[$step->fix_key]['label'] ?? $step->fix_key) : null;
                [$text, $note, $tone] = match ($step->kind) {
                    'action' => [$narration ? ucfirst($narration) : 'Worked on the screen', self::resultWords($step->result, $detail), self::tone($step->result)],
                    'fix' => [$narration ? ucfirst($narration) : 'Asked IT to run a fix', 'Through the Oblivion service · fix “'.$label.'”'.($detail ? ' · '.$detail : ''), self::tone($step->result)],
                    'ask_it' => [$narration ? ucfirst($narration) : 'Asked IT to approve a one-off fix', 'Waiting for IT to approve it once', 'info'],
                    'refused' => ['Didn’t do something it isn’t allowed to', self::refusalWords((string) $step->refusal), 'warning'],
                    'password' => ['Waited for a password', 'The person typed it themselves; Obli never types passwords', 'info'],
                    'finish' => [$step->result === 'fixed' ? 'Checked it worked' : 'Finished', $narration, $step->result === 'fixed' ? 'success' : 'warning'],
                    'hand_to_it' => ['Handed over to IT', $narration, 'warning'],
                    default => [$detail ?? 'Note', null, 'none'],
                };

                return [
                    'key' => (int) $step->id, 'at' => CarbonImmutable::parse($step->at)->toIso8601String(), 'text' => $text, 'note' => $note, 'tone' => $tone,
                    'picture' => $step->picture_id !== null && $live->contains('id', (int) $step->picture_id),
                ];
            })->values()->all();
        $views = ObliScreenPictures::canOpen($viewer)
            ? DB::table('obli_picture_views')->where('session_id', $row->id)->orderByDesc('viewed_at')->limit(20)->get()
                ->map(fn (object $view): array => [
                    'who' => (string) (DB::table('users')->where('id', $view->user_id)->value('name') ?? 'Someone who has left'),
                    'at' => CarbonImmutable::parse($view->viewed_at)->toIso8601String(), 'reason' => (string) $view->reason,
                ])->values()->all()
            : [];
        $ticket = $row->ticket_id ? DB::table('it_tickets')->where('id', $row->ticket_id)->first(['id', 'reference']) : null;
        $summary = $this->summary($row, collect([$row->user_id => $who]));

        return [
            'uuid' => (string) $row->uuid,
            'title' => (string) ($plan['title'] ?? 'Help with this computer'),
            'who' => $who,
            'at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            'device' => ['id' => (int) $device->id, 'name' => (string) $device->name],
            'badge' => $summary['badge'],
            'sub' => $summary['sub'],
            'problem' => $row->problem_encrypted ? self::text($row->problem_encrypted) : null,
            'kept' => $row->scrubbed_at === null,
            'found' => array_values($plan['checks'] ?? []),
            'plan' => array_values(array_map(fn (array $item): array => [
                'text' => (string) $item['text'],
                'note' => match ($item['kind']) {
                    'fix' => 'IT has allowed this fix',
                    'ask_it' => 'Needs IT to approve it once',
                    default => null,
                },
            ], $plan['plan'] ?? [])),
            'refusal' => isset($plan['refusal']['text']) ? (string) $plan['refusal']['text'] : null,
            'planned_at' => $row->planned_at ? CarbonImmutable::parse($row->planned_at)->toIso8601String() : null,
            'approved_at' => $row->approved_at ? CarbonImmutable::parse($row->approved_at)->toIso8601String() : null,
            'outcome' => isset($outcome['summary']) ? ['text' => (string) $outcome['summary'], 'at' => $row->ended_at ? CarbonImmutable::parse($row->ended_at)->toIso8601String() : null] : null,
            'steps' => $steps,
            'pictures' => [
                'count' => $live->count(),
                'deleted_on' => $live->isEmpty() ? null : CarbonImmutable::parse($live->max('delete_after'))->toIso8601String(),
                'can_open' => ObliScreenPictures::canOpen($viewer) && $live->isNotEmpty(),
            ],
            'views' => $views,
            'ticket' => $ticket ? ['id' => (int) $ticket->id, 'reference' => (string) $ticket->reference, 'href' => '/it/tickets/'.$ticket->id] : null,
            'note' => ObliFixInstructions::NOTE,
        ];
    }

    /**
     * One line in "Help on this PC".
     *
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $names
     * @return array<string, mixed>
     */
    private function summary(object $row, $names): array
    {
        $plan = self::decrypt($row->plan_encrypted);
        $start = CarbonImmutable::parse($row->approved_at ?? $row->created_at);
        $minutes = $row->ended_at ? max(1, (int) ceil(abs($start->diffInSeconds(CarbonImmutable::parse($row->ended_at))) / 60)) : null;
        [$label, $tone, $ring] = match (true) {
            $row->outcome === 'fixed' => ['Fixed', 'success', 'success'],
            $row->outcome === 'not_fixed' => ['Not fixed', 'warning', 'warning'],
            in_array($row->outcome, ['handed_to_it', 'needs_it'], true) => ['Needs IT', 'warning', 'warning'],
            $row->outcome === 'refused' => ['Won’t do', 'neutral', 'none'],
            $row->outcome === 'stopped' || $row->outcome === 'expired' => ['Stopped', 'neutral', 'none'],
            $row->state === 'planned' => ['Waiting for Fix it', 'info', 'info'],
            $row->state === 'waiting_it' => ['Waiting for IT', 'info', 'info'],
            in_array($row->state, ['working', 'paused_password', 'fix_running'], true) => ['Working', 'info', 'info'],
            default => ['Checking', 'info', 'info'],
        };
        $who = (string) ($names[$row->user_id] ?? 'Someone who has left');
        $ticket = $row->ticket_id ? DB::table('it_tickets')->where('id', $row->ticket_id)->value('reference') : null;
        $sub = match (true) {
            $row->outcome === 'fixed' && $minutes !== null => 'fixed in '.$minutes.' min',
            $ticket !== null => 'needs IT · ticket '.$ticket,
            default => lcfirst($label),
        };

        return [
            'uuid' => (string) $row->uuid,
            'title' => (string) ($plan['title'] ?? 'Help with this computer'),
            'who' => $who,
            'at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            'badge' => ['label' => $label, 'tone' => $tone],
            'ring' => $ring,
            'sub' => $who.' · '.$sub,
        ];
    }

    private static function resultWords(?string $result, ?string $detail): ?string
    {
        return match ($result) {
            'done' => $detail,
            'blocked' => 'The computer didn’t let Obli do it'.($detail ? ': '.$detail : ''),
            'failed' => 'It didn’t work'.($detail ? ': '.$detail : ''),
            null => 'Not reported back',
            default => $detail,
        };
    }

    private static function refusalWords(string $code): string
    {
        return match ($code) {
            'password_focus' => 'Not done: a password box had focus, and Obli never types there',
            'blocked_window' => 'Not done: a security window was in front',
            'key_not_allowed' => 'Not done: that key or shortcut isn’t allowed',
            'outside_picture' => 'Not done: the point was outside the screen',
            'terminal' => 'Not done: Obli doesn’t type into command windows',
            'unsafe' => 'Not done: it would have weakened the computer’s protection',
            'fix_not_on' => 'Not done: that fix isn’t on for this computer',
            default => 'Not done: Oblivion’s safety checks stopped it',
        };
    }

    private static function tone(?string $result): string
    {
        return match ($result) {
            'done', 'fixed' => 'success',
            'blocked', 'failed', 'declined' => 'warning',
            'requested', 'approved' => 'info',
            default => 'none',
        };
    }

    /** @return array<string, mixed> */
    private static function decrypt(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        try {
            $decoded = json_decode(Crypt::decryptString($value), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function text(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return null;
        }
    }
}
