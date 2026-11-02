<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Modes\HouseModeRunner;
use App\Domain\SecurityDevices\Modes\HouseModes;
use App\Domain\SecurityDevices\Screens\Sites\SitePagePresenter;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * A Site's house modes (S&D Phase F): add or edit one (pop-up 43, securityDevices.automations.manage)
 * and switch by hand (pop-up 42, securityDevices.modes.switch). Another Site's mode is 404; every
 * action must be a safe one (422 otherwise); every save and switch is audited.
 */
final class SiteModesController extends Controller
{
    public function __construct(
        private readonly SitePagePresenter $sitePage,
        private readonly HouseModes $modes,
    ) {}

    public function store(Request $request, int $site): RedirectResponse
    {
        $user = $this->user($request, 'securityDevices.automations.manage');
        $model = $this->sitePage->site($user, $site);
        $mode = $this->modes->save($user, $model, null, $this->input($request, true));

        return back()->with('success', $mode->name.' mode added.');
    }

    public function update(Request $request, int $site, int $mode): RedirectResponse
    {
        $user = $this->user($request, 'securityDevices.automations.manage');
        $model = $this->sitePage->site($user, $site);
        $saved = $this->modes->save($user, $model, HouseModes::find((int) $model->id, $mode), $this->input($request, false));

        return back()->with('success', $saved->name.' mode saved.');
    }

    public function switch(Request $request, int $site, int $mode): RedirectResponse
    {
        $user = $this->user($request, 'securityDevices.modes.switch');
        $model = $this->sitePage->site($user, $site);
        $target = HouseModes::find((int) $model->id, $mode);
        if (HouseModeRunner::currentSwitch((int) $model->id)?->mode_id === (int) $target->id) {
            throw ValidationException::withMessages(['mode' => 'The house is already in '.$target->name.'.']);
        }
        $this->modes->switchByHand($user, $target);

        return back()->with('success', 'Switched to '.$target->name.'.');
    }

    private function user(Request $request, string $key): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view') && $user->canDo($key), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function input(Request $request, bool $adding): array
    {
        $request->validate([
            'name' => $adding ? ['required', 'string', 'max:40'] : ['prohibited'],
            'schedule' => ['nullable', 'array'],
            'actions' => ['present', 'array', 'max:10'],
        ], [
            'name.required' => 'Give the mode a short name (up to 40 letters).',
            'name.max' => 'Give the mode a short name (up to 40 letters).',
        ]);

        return $request->only(['name', 'schedule', 'actions']);
    }
}
