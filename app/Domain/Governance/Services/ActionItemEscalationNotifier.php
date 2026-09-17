<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Notifications\ActionItemRaisedWithBoardNotification;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * When a person raises an action with the board, the current board chair and
 * secretary are told (email + in-app notification, no push).
 *
 * Recipients are the active chair/secretary appointments whose login is
 * approved, who can see Governance actions and who may open THIS action — an
 * action tied to a private meeting is never announced to someone who can't
 * open it. The person who raised it isn't notified about their own request.
 */
final class ActionItemEscalationNotifier
{
    /**
     * @return array{chair: bool, secretary: bool} which roles were notified
     */
    public function notifyBoardLeaders(ActionItem $action, ?User $raisedBy): array
    {
        $notified = ['chair' => false, 'secretary' => false];

        $leaders = BoardMember::query()
            ->active()
            ->whereIn('board_role', ['chair', 'secretary'])
            ->with('user')
            ->get();

        $sent = [];

        foreach ($leaders as $leader) {
            $user = $leader->user;

            if (! $user instanceof User
                || ($raisedBy !== null && (int) $user->id === (int) $raisedBy->id)
                || ! $this->canReceive($user, $action)) {
                continue;
            }

            if (! isset($sent[$user->id])) {
                $user->notify(new ActionItemRaisedWithBoardNotification($action, $raisedBy?->name));
                $sent[$user->id] = true;
            }

            $notified[$leader->board_role] = true;
        }

        return $notified;
    }

    /** A plain toast line naming who was told. */
    public static function summary(array $notified): string
    {
        return match (true) {
            $notified['chair'] && $notified['secretary'] => 'Action raised with the board. Chair and secretary notified.',
            $notified['chair'] => 'Action raised with the board. The chair was notified.',
            $notified['secretary'] => 'Action raised with the board. The secretary was notified.',
            default => "Action raised with the board, but there's no chair or secretary we could notify. Check the Board members page.",
        };
    }

    private function canReceive(User $user, ActionItem $action): bool
    {
        if ($user->approved_at === null || ! $this->hasCurrentProfile($user)) {
            return false;
        }

        return $user->canDo('governance.actions.view')
            && Gate::forUser($user)->allows('view', $action);
    }

    /** Leavers (ended or inactive HR profile) are skipped; volunteers without a profile are not. */
    private function hasCurrentProfile(User $user): bool
    {
        $profile = HrEmployeeProfile::withTrashed()->where('user_id', $user->id)->first();

        if ($profile === null) {
            return true;
        }

        $today = Carbon::today();

        return ! $profile->trashed()
            && $profile->is_active
            && $profile->start_date
            && $profile->start_date->startOfDay()->lte($today)
            && (! $profile->end_date || $profile->end_date->startOfDay()->gte($today));
    }
}
