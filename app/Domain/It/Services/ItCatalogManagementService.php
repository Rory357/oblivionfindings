<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogVersion;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ItCatalogManagementService
{
    private const EDITABLE = [
        'it_service_id',
        'name',
        'description',
        'outcome_type',
        'category',
        'provisioning_type',
        'provisioning_template_version_id',
        'default_priority',
        'requires_approval',
        'approver_user_id',
        'cover_approver_user_id',
        'approval_window_days',
        'internal_only',
        'site_scope',
        'form_schema',
        'search_terms',
        'sort_order',
    ];

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ItCatalogItem
    {
        return DB::transaction(function () use ($actor, $data): ItCatalogItem {
            $actor = $this->guardActor($actor);
            $data['site_scope'] = app(ItCatalogAccessService::class)->validateSiteScope($actor, $data['site_scope'] ?? null);
            $item = ItCatalogItem::query()->create([
                ...Arr::only($this->normalise($data), self::EDITABLE),
                'slug' => $this->uniqueSlug((string) $data['name']),
                'is_published' => false,
                'form_schema_version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            AuditLogger::logOrFail('it.catalogue.item.created', $item, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'outcome_type' => $item->outcome_type,
                'field_count' => count($item->form_schema['fields'] ?? []),
            ]);

            return $item->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ItCatalogItem $item, User $actor, array $data): ItCatalogItem
    {
        return DB::transaction(function () use ($item, $actor, $data): ItCatalogItem {
            [$item, $actor] = $this->lock($item, $actor);
            $this->expectVersion($item, (int) ($data['expected_version'] ?? 0));
            $data['site_scope'] = app(ItCatalogAccessService::class)->validateSiteScope($actor, array_key_exists('site_scope', $data) ? $data['site_scope'] : $item->site_scope);
            $before = $item->only(self::EDITABLE);
            $item->fill(Arr::only($this->normalise($data), self::EDITABLE));
            $changedFields = array_keys($item->getDirty());
            if ($changedFields === []) {
                return $item;
            }
            $item->form_schema_version = (int) $item->form_schema_version + 1;
            $item->lock_version++;
            $item->updated_by = $actor->id;
            $item->save();
            AuditLogger::logOrFail('it.catalogue.item.updated', $item, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'before' => $before,
                'changed_fields' => $changedFields,
                'form_schema_version' => $item->form_schema_version,
            ]);

            return $item->refresh();
        });
    }

    public function publish(ItCatalogItem $item, User $actor, int $expectedVersion): ItCatalogItem
    {
        return DB::transaction(function () use ($item, $actor, $expectedVersion): ItCatalogItem {
            [$item, $actor] = $this->lock($item, $actor);
            $this->expectVersion($item, $expectedVersion);
            if ($item->is_published && $item->publishedVersion?->version === $item->form_schema_version) {
                return $item;
            }
            app(ItCatalogAccessService::class)->validateSiteScope($actor, $item->site_scope);
            $this->assertAttachmentLimits($item->form_schema['fields'] ?? []);
            if ($item->provisioning_template_version_id !== null) {
                $template = ItProvisioningTemplate::query()->where('published_version_id', $item->provisioning_template_version_id)->lockForUpdate()->first();
                if ($item->outcome_type !== 'provisioning' || ! $template
                    || ! app(ItProvisioningTemplatePublicationService::class)->canView($actor, $template)) {
                    throw new DomainException('Choose a currently published provisioning template version before publishing this catalogue request.');
                }
                if (in_array($template->publishedVersion->contract['lifecycle_type'], ['mover', 'leaver'], true) && ! $item->internal_only) {
                    throw new DomainException('Mover and leaver catalogue workflows must be restricted to IT staff. HR retains ownership of employment changes.');
                }
            }
            if ($item->it_service_id !== null && ! $item->service()->where('is_active', true)->exists()) {
                throw new DomainException('Choose an active service before publishing this request.');
            }
            if ($item->outcome_type === 'provisioning' && ! in_array($item->provisioning_type, ItProvisioningRequest::TYPES, true)) {
                throw new DomainException('Choose a supported provisioning type before publishing this request.');
            }
            $this->assertApprovalRouting($item);

            $version = ItCatalogVersion::query()->firstOrCreate([
                'catalog_item_id' => $item->id,
                'version' => $item->form_schema_version,
            ], [
                'contract' => $item->only(ItCatalogItem::CONTRACT_FIELDS),
                'provenance' => 'reviewed_publication',
                'published_by' => $actor->id,
            ]);
            if ([...ItCatalogItem::CONTRACT_DEFAULTS, ...$version->contract] != $item->only(ItCatalogItem::CONTRACT_FIELDS)) {
                throw new DomainException('This draft differs from its recorded version. Save a new revision before publishing.');
            }
            $item->forceFill([
                'is_published' => true,
                'published_version_id' => $version->id,
                'lock_version' => $item->lock_version + 1,
                'updated_by' => $actor->id,
            ])->save();
            AuditLogger::logOrFail('it.catalogue.item.published', $item, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'form_schema_version' => $item->form_schema_version,
            ]);

            return $item->refresh();
        });
    }

    public function unpublish(ItCatalogItem $item, User $actor, string $reason, int $expectedVersion): ItCatalogItem
    {
        return DB::transaction(function () use ($item, $actor, $reason, $expectedVersion): ItCatalogItem {
            [$item, $actor] = $this->lock($item, $actor);
            $this->expectVersion($item, $expectedVersion);
            $reason = trim($reason);
            if ($reason === '') {
                throw new DomainException('Record why this request is being unpublished.');
            }
            if (! $item->is_published) {
                throw new DomainException('This request is already a draft.');
            }

            $item->forceFill([
                'is_published' => false,
                'lock_version' => $item->lock_version + 1,
                'updated_by' => $actor->id,
            ])->save();
            AuditLogger::logOrFail('it.catalogue.item.unpublished', $item, [
                'application_scope' => 'single_application',
                'actor_id' => $actor->id,
                'reason' => $reason,
                'form_schema_version' => $item->form_schema_version,
            ]);

            return $item->refresh();
        });
    }

    public function canManage(User $actor, ItCatalogItem $item): bool
    {
        if ($actor->approved_at === null || ! $actor->canDo('it.manage')
            || ! app(HrCurrentStaffService::class)->isCurrent($actor)) {
            return false;
        }
        $item = ItCatalogItem::query()->with('publishedVersion')->find($item->id);
        if (! $item) {
            return false;
        }
        try {
            $access = app(ItCatalogAccessService::class);
            $access->validateSiteScope($actor, $item->site_scope);
            $access->validateSiteScope($actor, $item->publishedVersion?->contract['site_scope'] ?? null);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    private function lock(ItCatalogItem $item, User $actor): array
    {
        $actor = $this->guardActor($actor);
        $item = ItCatalogItem::query()->lockForUpdate()->findOrFail($item->getKey());
        abort_unless($this->canManage($actor, $item), 404);

        return [$item, $actor];
    }

    private function expectVersion(ItCatalogItem $item, int $version): void
    {
        if ($item->lock_version !== $version) {
            throw ValidationException::withMessages(['expected_version' => 'This request changed in another window. Reload and review the current draft before saving or publishing.']);
        }
    }

    private function guardActor(User $actor): User
    {
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if ($actor->approved_at === null || ! $actor->canDo('it.manage')
            || ! app(HrCurrentStaffService::class)->isCurrent($actor)) {
            throw new DomainException('You are not allowed to manage the service catalogue.');
        }

        return $actor;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalise(array $data): array
    {
        if (! Schema::hasColumn('it_catalog_items', 'provisioning_template_version_id')) {
            if (! empty($data['provisioning_template_version_id'])) {
                throw ValidationException::withMessages(['provisioning_template_version_id' => 'Complete provisioning history setup before linking a workflow.']);
            }
            unset($data['provisioning_template_version_id']);
        } elseif (($data['outcome_type'] ?? null) !== 'provisioning') {
            $data['provisioning_template_version_id'] = null;
        }
        $data['provisioning_type'] = ($data['outcome_type'] ?? null) === 'provisioning'
            ? ($data['provisioning_type'] ?? null)
            : null;
        if (! Schema::hasColumn('it_catalog_items', 'approver_user_id')) {
            unset($data['approver_user_id'], $data['cover_approver_user_id'], $data['approval_window_days']);
        } elseif (empty($data['requires_approval'])) {
            // Routing defaults only mean something for approval-gated requests.
            $data['approver_user_id'] = null;
            $data['cover_approver_user_id'] = null;
            $data['approval_window_days'] = null;
        } else {
            $data['approver_user_id'] = ! empty($data['approver_user_id']) ? (int) $data['approver_user_id'] : null;
            $data['cover_approver_user_id'] = ! empty($data['cover_approver_user_id']) ? (int) $data['cover_approver_user_id'] : null;
            $data['approval_window_days'] = ! empty($data['approval_window_days']) ? (int) $data['approval_window_days'] : null;
        }
        $data['search_terms'] = array_values(array_unique(array_filter(array_map(
            fn (mixed $term): string => trim((string) $term),
            (array) ($data['search_terms'] ?? []),
        ))));
        $fields = collect($data['form_schema']['fields'] ?? [])->map(function (array $field): array {
            $normalised = [
                'key' => trim((string) $field['key']),
                'label' => trim((string) $field['label']),
                'type' => (string) $field['type'],
                'required' => (bool) $field['required'],
                'visibility' => (string) $field['visibility'],
            ];
            foreach (['help', 'min', 'max'] as $optional) {
                if (array_key_exists($optional, $field) && $field[$optional] !== null && $field[$optional] !== '') {
                    $normalised[$optional] = $field[$optional];
                }
            }
            if (in_array($field['type'], ['select', 'multiselect'], true)) {
                $normalised['options'] = array_values(array_unique(array_map(
                    fn (mixed $option): string => trim((string) $option),
                    (array) ($field['options'] ?? []),
                )));
            }

            return $normalised;
        })->values()->all();
        $this->assertAttachmentLimits($fields);
        $data['form_schema'] = ['fields' => $fields];

        return $data;
    }

    /**
     * A published approver pair must be two distinct people with current
     * access to every Site the request serves; otherwise routing silently
     * falls back to the service desk queue, which the author should know.
     */
    private function assertApprovalRouting(ItCatalogItem $item): void
    {
        if (! $item->requires_approval || ! Schema::hasColumn('it_catalog_items', 'approver_user_id')) {
            return;
        }
        $primary = $item->approver_user_id ? (int) $item->approver_user_id : null;
        $cover = $item->cover_approver_user_id ? (int) $item->cover_approver_user_id : null;
        if ($primary === null && $cover === null) {
            return;
        }
        if ($primary === null || $cover === null || $primary === $cover) {
            throw new DomainException('Name both a default approver and a distinct cover approver, or leave both empty to route approvals through the service desk fallback queue.');
        }
        $responsibility = app(ItProvisioningResponsibilityService::class);
        $sites = $item->site_scope ? array_map('intval', $item->site_scope) : [null];
        foreach ($sites as $siteId) {
            if (! $responsibility->eligible($primary, $siteId) || ! $responsibility->eligible($cover, $siteId)) {
                throw new DomainException('Choose default approvers with current access to every Site this request serves before publishing.');
            }
        }
    }

    private function assertAttachmentLimits(array $fields): void
    {
        $requiredFiles = 0;
        foreach ($fields as $field) {
            if (($field['type'] ?? null) !== 'attachment') {
                continue;
            }
            if ((int) ($field['min'] ?? 0) > 5 || (int) ($field['max'] ?? 5) > 5) {
                throw ValidationException::withMessages(['form_schema.fields' => 'Each attachment field must fit within the five-file request limit.']);
            }
            if ($field['required'] ?? false) {
                $requiredFiles += max(1, (int) ($field['min'] ?? 0));
            }
        }
        if ($requiredFiles > 5) {
            throw ValidationException::withMessages(['form_schema.fields' => 'The required attachment fields need more than five files in total. Reduce their minimums or make a field optional.']);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'request';
        $slug = $base;
        $suffix = 1;
        while (ItCatalogItem::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
