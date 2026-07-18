<?php

namespace App\Http\Requests\Sites;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Services\Clients\ClientWorkerEligibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LinkSiteClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $site = $this->route('site');

        return $site instanceof Site
            && ($this->user()?->can('update', $site) ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'service_context_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'key_worker_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $site = $this->route('site');
            if (! $site instanceof Site) {
                return;
            }

            $organizationId = $site->tenant_id ?? $this->user()?->organization_id;
            $clientId = $this->integer('client_id');
            $client = $clientId > 0
                ? Client::query()
                    ->whereKey($clientId)
                    ->whereNull('site_id')
                    ->when(
                        $organizationId !== null,
                        fn ($query) => $query->where('organization_id', $organizationId),
                    )
                    ->first()
                : null;

            if (! $client) {
                $validator->errors()->add(
                    'client_id',
                    'Choose an unassigned client from this organisation.',
                );
            }

            $roomId = $this->integer('room_id');
            if ($roomId > 0) {
                $roomIsAvailable = SiteHouseRoom::query()
                    ->whereKey($roomId)
                    ->where('site_id', $site->id)
                    ->when(
                        $organizationId !== null,
                        fn ($query) => $query->where('tenant_id', $organizationId),
                    )
                    ->where('is_active', true)
                    ->where('is_assignable', true)
                    ->where(function ($query) use ($clientId) {
                        $query->whereNull('assigned_client_id');
                        if ($clientId > 0) {
                            $query->orWhere('assigned_client_id', $clientId);
                        }
                    })
                    ->exists();

                if (! $roomIsAvailable) {
                    $validator->errors()->add(
                        'room_id',
                        'Choose an available room at this Site.',
                    );
                }
            }

            $serviceContextId = $this->integer('service_context_id');
            if ($serviceContextId > 0) {
                $contextIsAvailable = ServiceContext::query()
                    ->forOrganization($organizationId)
                    ->whereKey($serviceContextId)
                    ->where('is_active', true)
                    ->where(fn ($query) => $query
                        ->whereNull('site_id')
                        ->orWhere('site_id', $site->id))
                    ->exists();

                if (! $contextIsAvailable) {
                    $validator->errors()->add(
                        'service_context_id',
                        'Choose a service context available at this Site.',
                    );
                }
            }

            $keyWorkerId = $this->integer('key_worker_id');
            if (
                $keyWorkerId > 0
                && ! app(ClientWorkerEligibility::class)
                    ->queryForOrganization($organizationId)
                    ->whereKey($keyWorkerId)
                    ->exists()
            ) {
                $validator->errors()->add(
                    'key_worker_id',
                    'Choose an eligible key worker from this organisation.',
                );
            }
        });
    }
}
