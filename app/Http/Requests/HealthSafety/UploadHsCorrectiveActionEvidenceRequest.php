<?php

namespace App\Http\Requests\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Services\UserSiteAccessService;
use Illuminate\Foundation\Http\FormRequest;

class UploadHsCorrectiveActionEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        $action = $this->route('action');
        $user = $this->user();

        if (! $event instanceof HsEvent
            || ! $action instanceof HsCorrectiveAction
            || ! $user
            || (int) $action->hs_event_id !== (int) $event->id) {
            return false;
        }

        $query = HsEvent::query()->whereKey($event->id);
        app(UserSiteAccessService::class)->applyHsEventScope(
            $query,
            $user,
            [],
        );

        return $query->exists()
            && ((int) $action->assigned_to_user_id === (int) $user->id
                || $user->canDo('hazards.manage'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png,webp,doc,docx',
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
