<?php

namespace App\Http\Requests\It;

use App\Domain\It\Services\ItApiWorkItemService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItServiceIdentity;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreItServiceIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'actor_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ItServiceIdentity::ABILITIES)],
            'allowed_work_types' => ['required', 'array', 'min:1'],
            'allowed_work_types.*' => ['string', Rule::in(['incident', 'service_request', 'security_request'])],
            'allowed_site_ids' => ['present', 'array'],
            'allowed_site_ids.*' => ['integer', Rule::exists('sites', 'id')->where(fn ($site) => $site
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at'))],
            'create_fields' => ['required', 'array', 'min:4'],
            'create_fields.*' => ['string', Rule::in(ItServiceIdentity::CREATE_FIELDS)],
            'read_fields' => ['present', 'array'],
            'read_fields.*' => ['string', Rule::in(ItServiceIdentity::READ_FIELDS)],
            'require_signature' => ['required', 'boolean'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:300'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $missing = array_diff(ItServiceIdentity::REQUIRED_CREATE_FIELDS, (array) $this->input('create_fields', []));
            if ($missing !== []) {
                $validator->errors()->add(
                    'create_fields',
                    'Title, category, priority, and work type must remain enabled for intake.',
                );
            }

            $actorId = (int) $this->input('actor_user_id');
            $actor = User::query()->find($actorId);
            if (! $actor || ! app(ItApiWorkItemService::class)->isCurrentExecutionAccount($actor)) {
                $validator->errors()->add('actor_user_id', 'Choose a currently approved IT execution account.');

                return;
            }

            $approvedSiteIds = app(ItWorkAccessService::class)->approvedSiteIds($actor);
            $manager = $this->user();
            $managerSiteIds = $manager
                ? app(ItWorkAccessService::class)->approvedSiteIds($manager)
                : [];
            foreach ((array) $this->input('allowed_site_ids', []) as $index => $siteId) {
                if (! is_numeric($siteId)
                    || ! in_array((int) $siteId, $approvedSiteIds, true)
                    || ! in_array((int) $siteId, $managerSiteIds, true)) {
                    $validator->errors()->add(
                        "allowed_site_ids.{$index}",
                        'Both you and the execution account must be currently approved for this Site.',
                    );
                }
            }

            $abilities = (array) $this->input('abilities', []);
            if (in_array('work:sensitive', $abilities, true)
                && (! $manager?->canDo('it.viewSensitive') || ! $actor->canDo('it.viewSensitive'))) {
                $validator->errors()->add('abilities', 'Sensitive work requires matching authority on both accounts.');
            }
            if (in_array('work:organisation-wide', $abilities, true)
                && (! $manager?->canDo('it.organisationWide') || ! $actor->canDo('it.organisationWide'))) {
                $validator->errors()->add('abilities', 'Organisation-wide work requires matching authority on both accounts.');
            }
        }];
    }
}
