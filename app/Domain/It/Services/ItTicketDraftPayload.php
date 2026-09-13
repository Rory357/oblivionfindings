<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTicketResolutionInput;
use App\Domain\It\Enums\ItTicketDraftPurpose as Purpose;
use App\Domain\It\Exceptions\ItTicketDraftException;
use App\Domain\It\ItStaffDirectory;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\ItProvisioningRequest;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** Partial fields have the canonical form vocabulary, never arbitrary JSON. */
final class ItTicketDraftPayload
{
    public function __construct(
        private readonly ItWorkAccessService $access,
        private readonly ItProvisioningAccessService $provisioning,
        private readonly SecurityDevicesAccessService $devices,
    ) {}

    public function validate(Purpose $purpose, array $fields): array
    {
        $text = fn (int $max): array => ['sometimes', 'nullable', 'string', 'max:'.$max];
        $id = ['sometimes', 'nullable', 'integer', 'min:1'];
        $boolean = ['sometimes', 'boolean'];
        $enum = fn (array $values): array => ['sometimes', 'nullable', Rule::in($values)];
        $commonIntake = [
            'title' => $text(255), 'description' => $text(5000), 'category' => $enum(ItTicket::CATEGORIES),
            'site_id' => $id, 'impact' => $enum(ItTicket::IMPACTS), 'urgency' => $enum(ItTicket::URGENCIES),
        ];
        $triage = [
            'category' => $enum(ItTicket::CATEGORIES), 'subcategory' => $text(255),
            'priority' => $enum(ItTicket::PRIORITIES), 'impact' => $enum(ItTicket::IMPACTS), 'urgency' => $enum(ItTicket::URGENCIES),
            'priority_reason' => $text(1000), 'routing_reason' => $text(1000),
            'site_id' => $id, 'is_organisation_wide' => $boolean, 'it_service_id' => $id,
            'work_type' => $enum(ItTicket::INTAKE_WORK_TYPES), 'assigned_to_user_id' => $id, 'asset_id' => $id,
        ];
        $rules = match ($purpose) {
            Purpose::RequesterIntake => $commonIntake,
            Purpose::TechnicianIntake => [
                ...$commonIntake, ...$triage, 'requester_user_id' => $id,
                'device_id' => $id, 'provisioning_request_id' => $id,
                'watchers' => ['sometimes', 'nullable', 'array', 'max:100'],
            ],
            Purpose::PublicReply, Purpose::InternalNote => ['body' => $text(5000)],
            Purpose::PublicResolution => [...ItTicketResolutionInput::rules(partial: true), 'notify_requester' => $boolean],
            Purpose::TicketEdit => [
                ...$triage, 'status' => $enum(ItTicket::OPEN_STATUSES), 'queue_id' => $id, 'owner_user_id' => $id,
                'release_priority_override' => $boolean, 'release_routing_override' => $boolean,
                'waiting_party' => $enum(['requester', 'vendor', 'approver', 'team', 'change', 'other']),
                'waiting_reason' => $text(1000), 'next_action' => $text(2000),
                'resolution_code' => $text(100), 'resolution_summary' => $text(5000),
            ],
        };
        $validation = ['fields' => ['present', 'array:'.implode(',', array_keys($rules))]];
        foreach ($rules as $key => $rule) {
            $validation['fields.'.$key] = $rule;
        }
        if (isset($rules['watchers'])) {
            $validation['fields.watchers.*'] = ['integer', 'min:1', 'distinct'];
        }
        $safe = Validator::make(['fields' => $fields], $validation)->validate()['fields'];
        if (strlen(json_encode($safe, JSON_THROW_ON_ERROR)) > 65536) {
            throw ValidationException::withMessages(['fields' => 'Keep this draft below 64 KB of text and selected references.']);
        }

        return $safe;
    }

    /**
     * Recheck old bindings before accepting any replacement/cleared fields.
     * A cleared reference remains bound until an explicit accessible replacement.
     *
     * @return array<string, mixed>
     */
    public function bind(User $actor, ?ItTicket $ticket, array $fields, array $old = []): array
    {
        $this->assertScope($actor, $ticket, $old);
        $scope = $old;
        foreach (['site_id', 'is_organisation_wide', 'assigned_to_user_id', 'owner_user_id', 'requester_user_id', 'asset_id', 'device_id', 'it_service_id', 'provisioning_request_id', 'queue_id', 'watchers'] as $field) {
            if (array_key_exists($field, $fields) && $fields[$field] !== null && $fields[$field] !== '' && $fields[$field] !== []) {
                $scope[$field] = $fields[$field];
            }
        }
        // A real Site choice explicitly replaces an earlier wide binding.
        if (! empty($fields['site_id'])) {
            $scope['is_organisation_wide'] = false;
        } elseif (($fields['is_organisation_wide'] ?? false) === true) {
            $scope['site_id'] = null;
        } elseif (! empty($old['is_organisation_wide'])) {
            // Incomplete replacement does not remove the previous privacy bind.
            $scope['is_organisation_wide'] = true;
        }
        $this->assertScope($actor, $ticket, $scope);

        return $scope;
    }

    /** Previous selections remain a privacy boundary for document-memory recovery. */
    public function validateScope(array $scope): array
    {
        $ids = ['site_id', 'assigned_to_user_id', 'owner_user_id', 'requester_user_id', 'asset_id', 'device_id', 'it_service_id', 'provisioning_request_id', 'queue_id'];
        $rules = ['scope' => ['present', 'array:'.implode(',', [...$ids, 'is_organisation_wide', 'watchers'])],
            'scope.is_organisation_wide' => ['sometimes', 'boolean'],
            'scope.watchers' => ['sometimes', 'nullable', 'array', 'max:100'],
            'scope.watchers.*' => ['integer', 'min:1', 'distinct']];
        foreach ($ids as $field) {
            $rules['scope.'.$field] = ['sometimes', 'nullable', 'integer', 'min:1'];
        }

        return Validator::make(['scope' => $scope], $rules)->validate()['scope'];
    }

    public function assertScope(User $actor, ?ItTicket $ticket, array $scope): void
    {
        if (array_key_exists('site_id', $scope) || ! empty($scope['is_organisation_wide'])) {
            if (! $this->access->canAssignScope($actor, isset($scope['site_id']) ? (int) $scope['site_id'] : null, (bool) ($scope['is_organisation_wide'] ?? false))) {
                throw ItTicketDraftException::unavailable();
            }
        }
        $siteId = isset($scope['site_id']) ? (int) $scope['site_id'] : $ticket?->site_id;
        $wide = (bool) ($scope['is_organisation_wide'] ?? $ticket?->is_organisation_wide ?? false);
        $probe = new ItTicket(['site_id' => $siteId, 'is_organisation_wide' => $wide]);
        $staffIds = array_filter([
            $scope['assigned_to_user_id'] ?? null, $scope['owner_user_id'] ?? null, $scope['requester_user_id'] ?? null,
            ...($scope['watchers'] ?? []),
        ]);
        $agents = null;
        foreach (array_unique($staffIds) as $id) {
            $staff = User::query()->whereKey((int) $id)->whereNotNull('approved_at')->first();
            if (! $staff || $staff->hasRole('client') || $staff->hasRole('next_of_kin')
                || in_array($staff->role, ['client', 'next_of_kin'], true)
                || (! $wide && ($siteId === null || ! in_array((int) $siteId, $this->access->approvedSiteIds($staff), true)))) {
                throw ItTicketDraftException::unavailable();
            }
            if (in_array((int) $id, [(int) ($scope['assigned_to_user_id'] ?? 0), (int) ($scope['owner_user_id'] ?? 0)], true)) {
                $agents ??= ItStaffDirectory::agents()->pluck('id')->all();
                if (! in_array((int) $id, $agents, true) || ($wide && ! $staff->canDo('it.organisationWide'))) {
                    throw ItTicketDraftException::unavailable();
                }
            }
        }
        if (! empty($scope['asset_id'])) {
            $asset = Asset::query()->whereKey((int) $scope['asset_id'])->where('status', 'active')->first();
            if (! $asset || (! $wide && ($siteId === null || (int) $asset->site_id !== (int) $siteId))) {
                throw ItTicketDraftException::unavailable();
            }
        }
        if (! empty($scope['device_id'])) {
            if (! $actor->canDo('securityDevices.devices.view') || ! $this->devices->visibleDevices($actor)->whereKey((int) $scope['device_id'])->exists()) {
                throw ItTicketDraftException::unavailable();
            }
            try {
                $deviceSite = app(CanonicalDeviceSiteResolver::class)->resolveForContext((int) $scope['device_id']);
            } catch (UnexpectedValueException) {
                throw ItTicketDraftException::unavailable();
            }
            if (! $wide && ($siteId === null || $deviceSite !== (int) $siteId)) {
                throw ItTicketDraftException::unavailable();
            }
        }
        if (! empty($scope['it_service_id']) && ! ItService::query()->whereKey((int) $scope['it_service_id'])->where('is_active', true)->exists()) {
            throw ItTicketDraftException::unavailable();
        }
        if (! empty($scope['provisioning_request_id'])) {
            $request = ItProvisioningRequest::query()->find((int) $scope['provisioning_request_id']);
            if (! $request || ! $this->provisioning->canManage($actor, $request)) {
                throw ItTicketDraftException::unavailable();
            }
        }
        if (! empty($scope['queue_id']) && ! in_array((int) $scope['queue_id'], array_column(app(ItTicketRoutingService::class)->queueOptions($probe), 'id'), true)) {
            throw ItTicketDraftException::unavailable();
        }
    }
}
