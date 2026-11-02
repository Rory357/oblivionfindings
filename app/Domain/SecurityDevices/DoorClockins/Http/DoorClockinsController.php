<?php

namespace App\Domain\SecurityDevices\DoorClockins\Http;

use App\Domain\SecurityDevices\DoorClockins\DoorClockinChoices;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinReview;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinRules;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinSetup;
use App\Domain\SecurityDevices\DoorClockins\Screens\AccessClockinsPresenter;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Clock-ins from doors (S&D Phase H, D15): the person's own answer (My Day band, pop-up 88), the
 * manager's side panel and review (pop-up 89), and Setup's turn on, turn off and settings (15n,
 * pop-up 92). Each action checks its own permission and Site; another Site's row is 404.
 */
final class DoorClockinsController extends Controller
{
    public function __construct(
        private readonly DoorClockinChoices $choices,
        private readonly DoorClockinReview $review,
        private readonly DoorClockinSetup $setup,
        private readonly AccessClockinsPresenter $presenter,
    ) {}

    /** Pop-up 88 / the My Day band: clock in at the badge time, now instead, or not working. */
    public function answer(Request $request, int $clockin): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate(['choice' => ['required', 'string', 'in:badge,now,not_working']]);

        return response()->json($this->choices->answer($user, $clockin, (string) $validated['choice']));
    }

    /** Pop-up 89: one row, its badge, clock-in and roster side by side, and the person's morning. */
    public function panel(Request $request, int $clockin): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(DoorClockinRules::canReview($user), 403);
        $panel = $this->presenter->panel($user, $clockin);
        abort_if($panel === null, 404);

        return response()->json($panel);
    }

    /** Pop-up 89: keep the clock-in, ask the person, or mark a badge not work. */
    public function review(Request $request, int $clockin): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(DoorClockinRules::canReview($user), 403);
        $validated = $request->validate([
            'choice' => ['required', 'string', 'in:keep,ask,not_work'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($this->review->review($user, $clockin, (string) $validated['choice'], $validated['note'] ?? null));
    }

    /** Pop-up 92: turn it on, or change the doors that count and the window. */
    public function saveDoors(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(DoorClockinRules::canChange($user), 403);
        $validated = $request->validate([
            'door_ids' => ['present', 'array', 'max:100'],
            'door_ids.*' => ['integer', 'min:1'],
            'before' => ['required', 'integer'],
            'after' => ['required', 'integer'],
            'notice' => ['nullable', 'string', 'max:4000'],
            'email' => ['boolean'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json($this->setup->saveDoors($user, $validated));
    }

    /** 15n: turn it off at the viewer's Sites. */
    public function turnOff(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(DoorClockinRules::canChange($user), 403);

        return response()->json(['removed' => $this->setup->turnOff($user)]);
    }

    /** 15n: the badge-time switch and who hears about gaps. */
    public function settings(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(DoorClockinRules::canChange($user), 403);
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:10'],
        ]);

        return response()->json(['changed' => $this->setup->updateSettings($user, $validated['changes'])]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
