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
        $user = $this->user();

        return $site instanceof Site
            && $user !== null
            && $user->can('update', $site)
            && $user->canDo('clients.assignments.update')
            && $user->canDo('clients.viewAny');
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

            $clientId = $this->integer('client_id');
            $client = $clientId > 0
                ? Client::query()
                    ->whereKey($clientId)
                    ->whereNull('site_id')
                    ->first()
                : null;

            if (! $client) {
                $validator->errors()->add(
                    'client_id',
                    'Choose an unassigned client.',
                );
            }

            $roomId = $this->integer('room_id');
            if ($roomId > 0) {
                $roomIsAvailable = SiteHouseRoom::query()
                    ->whereKey($roomId)
                    ->where('site_id', $site->id)
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
            if (
                $serviceContextId > 0
                && ! ServiceContext::query()
                    ->availableToSite($site->id)
                    ->whereKey($serviceContextId)
                    ->where('is_active', true)
                    ->exists()
            ) {
                $validator->errors()->add(
                    'service_context_id',
                    'Choose a service context available at this Site.',
                );
            }

            $keyWorkerId = $this->integer('key_worker_id');
            if (
                $keyWorkerId > 0
                && ! app(ClientWorkerEligibility::class)
                    ->containsForSite($site->id, $keyWorkerId)
            ) {
                $validator->errors()->add(
                    'key_worker_id',
                    'Choose a current key worker assigned to this Site.',
                );
            }
        });
    }
}
