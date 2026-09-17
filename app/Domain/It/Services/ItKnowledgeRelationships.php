<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItKnowledgeRelationshipUnavailable;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ItKbArticle;
use App\Models\ItProblem;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteCredentialAccess;
use App\Services\SiteTypeAccessService;
use App\Services\SiteVendorAccessService;
use App\Services\UserSiteAccessService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** Typed references to canonical records, resolved again for every reader and write. */
final class ItKnowledgeRelationships
{
    public const TYPES = ['service', 'asset', 'device', 'site', 'vendor', 'credential', 'article', 'problem'];

    public const RELATIONS = ['documents', 'depends_on', 'recovery_for', 'supports'];

    public function query(User $actor, string $type): Builder
    {
        $devices = app(SecurityDevicesAccessService::class);
        $knowledge = app(ItKbAccessService::class);
        $query = match ($type) {
            'service' => ItService::query()->when(! ($actor->canDo('it.view') || $actor->canDo('it.manage') || $knowledge->hasKnowledgeCapability($actor)), fn ($query) => $query->whereRaw('1 = 0')),
            'asset' => $devices->accessibleAssets($actor),
            'device' => $devices->visibleDevices($actor)->when(! Gate::forUser($actor)->allows('viewAny', Device::class), fn ($query) => $query->whereRaw('1 = 0')),
            'site' => Site::query()->whereIn('id', app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['sites.viewAll']))
                ->whereIn('type', app(SiteTypeAccessService::class)->allowedTypes($actor))
                ->when(! $actor->canDo('sites.viewAny'), fn ($query) => $query->whereRaw('1 = 0')),
            'vendor' => app(SiteVendorAccessService::class)->query($actor),
            'credential' => app(SiteCredentialAccess::class)->query($actor)->when(app(SiteCredentialAccess::class)->ready(),
                fn ($query) => $query->whereNull('retired_at'), fn ($query) => $query->whereRaw('1 = 0')),
            'article' => $knowledge->applyViewScope(ItKbArticle::query(), $actor),
            'problem' => ItProblem::query()->join('it_tickets as knowledge_source_ticket', 'knowledge_source_ticket.id', '=', 'it_problems.ticket_id')
                ->whereIn('it_problems.ticket_id', app(ItWorkAccessService::class)->applyViewScope(ItTicket::query(), $actor)->select('it_tickets.id'))
                ->when(! $actor->canDo('it.view'), fn ($query) => $query->whereRaw('1 = 0')),
            default => throw new DomainException('Choose a supported record type.'),
        };

        return $query->when($actor->approved_at === null, fn ($query) => $query->whereRaw('1 = 0'));
    }

    /** Search the complete canonical register; return a bounded page of allowed metadata. */
    public function options(User $actor, string $type, string $search = '', int $page = 1, ?int $articleId = null): array
    {
        $column = match ($type) {
            'vendor' => 'company_name', 'credential' => 'label', 'article' => 'title', 'problem' => 'knowledge_source_ticket.title', default => 'name'
        };
        $query = $this->query($actor, $type);
        $this->activeOptions($query, $type);
        if ($type === 'article' && $articleId) {
            $query->whereKeyNot($articleId);
        }
        if ($search !== '') {
            $query->where(function ($query) use ($column, $type, $search): void {
                $query->where($column, 'like', '%'.$search.'%');
                if ($type === 'asset') {
                    $query->orWhere('asset_tag', 'like', '%'.$search.'%');
                }
            });
        }
        $result = $query->orderBy($column)->orderBy($query->getModel()->getQualifiedKeyName())->simplePaginate(20, $this->columns($type), 'page', $page);

        return [
            'actor_user_id' => $actor->id, 'type' => $type, 'page' => $result->currentPage(), 'has_more' => $result->hasMorePages(),
            'records' => collect($result->items())->map(fn (Model $model): array => $this->present($model, $type))->all(),
        ];
    }

    /** No names, URLs, ids or counts of inaccessible targets are returned. */
    public function resolve(User $actor, array $references): array
    {
        $resolved = [];
        foreach (self::TYPES as $type) {
            $refs = array_filter($references, fn ($ref): bool => is_array($ref) && ($ref['type'] ?? null) === $type && is_numeric($ref['id'] ?? null));
            if ($refs === []) {
                continue;
            }
            $records = $this->query($actor, $type)->whereKey(array_column($refs, 'id'))->get($this->columns($type))->keyBy('id');
            foreach ($refs as $ref) {
                $record = $records->get((int) $ref['id']);
                if ($record && in_array($ref['relation'] ?? 'documents', self::RELATIONS, true)) {
                    $resolved[] = [...$this->present($record, $type), 'relation' => $ref['relation'] ?? 'documents'];
                }
            }
        }

        return $resolved;
    }

    public function normalise(User $actor, array $references, ?int $articleId = null): array
    {
        if (count($references) > 30) {
            throw new DomainException('A document can link to at most 30 records.');
        }
        $normalised = [];
        foreach ($references as $reference) {
            if (! is_array($reference)) {
                throw new DomainException('Choose valid related records.');
            }
            $type = $reference['type'] ?? null;
            $id = $reference['id'] ?? null;
            $relation = $reference['relation'] ?? 'documents';
            if (! in_array($type, self::TYPES, true) || ! is_numeric($id) || (int) $id < 1 || ! in_array($relation, self::RELATIONS, true)
                || ($type === 'article' && (int) $id === $articleId)) {
                throw new DomainException('Choose valid related records. A document cannot link to itself.');
            }
            $normalised[$type.':'.(int) $id] = ['type' => $type, 'id' => (int) $id, 'relation' => $relation];
        }
        $normalised = array_values($normalised);
        $resolved = $this->resolve($actor, $normalised);
        if (count($resolved) !== count($normalised)) {
            throw new ItKnowledgeRelationshipUnavailable;
        }
        if (collect($resolved)->contains('inactive', true)) {
            throw new DomainException('Remove or replace links marked inactive before saving or submitting this document for review.');
        }

        return $normalised;
    }

    /** Strip reference metadata from snapshots and replace it with current permitted records. */
    public function contentFor(User $actor, array $content): array
    {
        return $this->contentsFor($actor, [$content])[0];
    }

    /** Resolve a page or history batch once per record type, never once per document. */
    public function contentsFor(User $actor, array $contents): array
    {
        $references = [];
        foreach ($contents as $content) {
            array_push($references, ...(array) ($content['related_records'] ?? []));
        }
        $records = collect($this->resolve($actor, $references))->keyBy(fn (array $row): string => $row['type'].':'.$row['id']);
        foreach ($contents as &$content) {
            $visible = [];
            foreach ((array) ($content['related_records'] ?? []) as $ref) {
                if (! is_array($ref) || ! in_array($ref['relation'] ?? 'documents', self::RELATIONS, true)) {
                    continue;
                }
                $record = $records->get(($ref['type'] ?? '').':'.($ref['id'] ?? ''));
                if ($record) {
                    $visible[] = [...$record, 'relation' => $ref['relation'] ?? 'documents'];
                }
            }
            $content['related_records'] = $visible;
        }
        unset($content);

        return $contents;
    }

    private function columns(string $type): array
    {
        return match ($type) {
            'service' => ['id', 'name', 'status', 'criticality', 'is_active'],
            'asset' => ['id', 'name', 'asset_tag', 'status'],
            'device' => ['id', 'name', 'device_uid', 'status'],
            'site' => ['id', 'name', 'type', 'is_active', 'archived', 'archived_at'],
            'vendor' => ['id', 'company_name', 'site_id', 'service_type', 'is_active'],
            'credential' => ['id', 'label', 'credential_type', 'site_id'],
            'article' => ['id', 'title', 'category', 'status'],
            'problem' => ['it_problems.id', 'knowledge_source_ticket.title as name', 'knowledge_source_ticket.reference', 'knowledge_source_ticket.status'],
        };
    }

    private function present(Model $record, string $type): array
    {
        $documentationHref = '/it/knowledge?'.http_build_query(['related_type' => $type, 'related_id' => $record->id]);

        return [
            'type' => $type, 'id' => (int) $record->id,
            'inactive' => match ($type) {
                'service', 'vendor' => ! $record->is_active,
                'site' => ! $record->is_active || $record->archived || $record->archived_at !== null,
                'asset' => $record->status === 'retired',
                'device' => $record->status instanceof DeviceStatus && $record->status->isRetired(),
                'article' => $record->status === 'retired',
                default => false,
            },
            'label' => match ($type) {
                'vendor' => $record->company_name, 'credential' => $record->label, 'article' => $record->title, default => $record->name
            },
            'detail' => match ($type) {
                'service' => $record->status.' · '.$record->criticality.' criticality',
                'asset' => $record->asset_tag,
                'device' => $record->device_uid,
                'site' => $record->type,
                'vendor' => $record->service_type,
                'credential' => $record->credential_type,
                'article' => $record->category.' · '.$record->status,
                'problem' => $record->reference.' · '.$record->status,
            },
            'href' => match ($type) {
                'asset' => '/fleet-assets/assets/'.$record->id,
                'device' => '/security-devices/devices/'.$record->id,
                'site' => '/sites/'.$record->id,
                'vendor' => '/vendors/'.$record->id,
                'credential' => '/vendors?tab=credentials&credential_id='.$record->id,
                'article' => '/it/knowledge/'.$record->id,
                'problem' => '/it/problems/'.$record->id,
                'service' => $documentationHref,
            },
            'documentation_href' => $documentationHref,
        ];
    }

    /** Existing visible links retain their identity and warning; pickers offer usable targets. */
    private function activeOptions(Builder $query, string $type): void
    {
        match ($type) {
            'service', 'vendor' => $query->where('is_active', true),
            'site' => $query->where('is_active', true)->where('archived', false)->whereNull('archived_at'),
            'asset' => $query->where('status', '!=', 'retired'),
            'device' => $query->whereNotIn('status', array_map(fn (DeviceStatus $status) => $status->value,
                array_filter(DeviceStatus::cases(), fn (DeviceStatus $status) => $status->isRetired()))),
            'article' => $query->where('status', '!=', 'retired'),
            default => null,
        };
    }
}
