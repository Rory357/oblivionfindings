<?php

namespace App\Services\Assurance;

use App\Models\Site;
use App\Models\SiteCertification;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SiteCertificationService
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /** @param array<string, mixed> $attributes */
    public function create(Site $site, ?User $actor, array $attributes): SiteCertification
    {
        return DB::transaction(function () use ($site, $actor, $attributes): SiteCertification {
            $site = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $this->assertReviewerBelongsToSite($attributes['reviewed_by'] ?? null, $site);

            if (($attributes['certification_type'] ?? null) !== NzsAssuranceResolver::CERTIFICATION_TYPE) {
                return SiteCertification::query()->create([
                    ...$this->withEvidenceProvenance($attributes),
                    'site_id' => $site->id,
                    'created_by' => $actor?->id,
                ]);
            }

            $previous = SiteCertification::withTrashed()
                ->where('site_id', $site->id)
                ->where('certification_type', NzsAssuranceResolver::CERTIFICATION_TYPE)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $successor = SiteCertification::query()->create([
                ...$this->withEvidenceProvenance($attributes, $site->id),
                'site_id' => $site->id,
                'supersedes_certification_id' => $previous?->id,
                'created_by' => $actor?->id,
            ]);

            if ($previous && ! $previous->trashed()) {
                $this->revokeLocked($previous, $actor, 'Superseded by a newer Ngā Paerewa certification record.');
            }

            return $successor;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Site $site, int $certificationId, ?User $actor, array $attributes): SiteCertification
    {
        return DB::transaction(function () use ($site, $certificationId, $actor, $attributes): SiteCertification {
            $site = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $certification = SiteCertification::query()
                ->where('site_id', $site->id)
                ->whereKey($certificationId)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($certification->revoked_at !== null, 409, 'Revoked certification evidence cannot be changed.');

            $merged = [
                ...$certification->only([
                    'certification_type', 'name', 'issuing_body', 'reference_number', 'status',
                    'issued_date', 'expiry_date', 'next_review_date', 'notes', 'document_path',
                    'document_disk', 'evidence_sha256', 'reviewed_by', 'reviewed_at',
                ]),
                ...$attributes,
            ];
            $this->assertReviewerBelongsToSite($merged['reviewed_by'] ?? null, $site);

            $typeChanged = $certification->certification_type !== ($merged['certification_type'] ?? null);
            if ($typeChanged && in_array(
                NzsAssuranceResolver::CERTIFICATION_TYPE,
                [$certification->certification_type, $merged['certification_type'] ?? null],
                true,
            )) {
                throw ValidationException::withMessages([
                    'certification_type' => 'Create a new record when changing Ngā Paerewa certification scope.',
                ]);
            }

            if (($merged['certification_type'] ?? null) !== NzsAssuranceResolver::CERTIFICATION_TYPE
                && $certification->certification_type !== NzsAssuranceResolver::CERTIFICATION_TYPE) {
                $certification->update($this->withEvidenceProvenance($attributes));

                return $certification->refresh();
            }

            $head = SiteCertification::withTrashed()
                ->where('site_id', $site->id)
                ->where('certification_type', NzsAssuranceResolver::CERTIFICATION_TYPE)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            abort_if(
                ! $head || $head->id !== $certification->id,
                409,
                'This certification evidence has already been superseded.',
            );

            $successor = SiteCertification::query()->create([
                ...$this->withEvidenceProvenance($merged, $site->id),
                'site_id' => $site->id,
                'supersedes_certification_id' => $certification->id,
                'created_by' => $actor?->id,
            ]);
            $this->revokeLocked($certification, $actor, 'Superseded by edited Ngā Paerewa certification evidence.');

            return $successor;
        }, 3);
    }

    public function revoke(Site $site, int $certificationId, ?User $actor): void
    {
        DB::transaction(function () use ($site, $certificationId, $actor): void {
            $site = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $certification = SiteCertification::query()
                ->where('site_id', $site->id)
                ->whereKey($certificationId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->revokeLocked($certification, $actor, 'Removed from the Site compliance record.');
        }, 3);
    }

    private function revokeLocked(SiteCertification $certification, ?User $actor, string $reason): void
    {
        if ($certification->revoked_at === null) {
            $certification->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $actor?->id,
                'revocation_reason' => $reason,
            ])->save();
        }

        if (! $certification->trashed()) {
            $certification->delete();
        }
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function withEvidenceProvenance(array $attributes, ?int $siteId = null): array
    {
        if (! array_key_exists('document_path', $attributes)) {
            return $attributes;
        }

        if (blank($attributes['document_path'] ?? null)) {
            return [
                ...$attributes,
                'document_disk' => null,
                'evidence_sha256' => null,
            ];
        }

        $disk = (string) ($attributes['document_disk'] ?? 'private');
        $path = (string) $attributes['document_path'];
        if ($siteId !== null && $disk !== 'private') {
            throw ValidationException::withMessages([
                'document_path' => 'Certification evidence must use the private evidence store.',
            ]);
        }
        if ($siteId !== null && ! NzsAssuranceResolver::evidencePathBelongsToSite($path, $siteId)) {
            throw ValidationException::withMessages([
                'document_path' => 'Certification evidence must belong to this Site.',
            ]);
        }
        $hash = null;

        try {
            if (Storage::disk($disk)->exists($path)) {
                $stream = Storage::disk($disk)->readStream($path);
                if (is_resource($stream)) {
                    try {
                        $context = hash_init('sha256');
                        hash_update_stream($context, $stream);
                        $hash = hash_final($context);
                    } finally {
                        fclose($stream);
                    }
                }
            }
        } catch (Throwable) {
            // Persist the submitted record without an attestation digest. The resolver
            // will report unknown/action-required and can never turn this into green.
        }

        return [
            ...$attributes,
            'document_disk' => $disk,
            'evidence_sha256' => $hash,
        ];
    }

    private function assertReviewerBelongsToSite(?int $reviewerId, Site $site): void
    {
        if ($reviewerId === null) {
            return;
        }

        $reviewer = User::query()->whereNotNull('approved_at')->find($reviewerId);
        if (! $reviewer || ! in_array($site->id, $this->siteAccess->accessibleSiteIds($reviewer, ['sites.viewAll']), true)) {
            throw ValidationException::withMessages([
                'reviewed_by' => 'Choose a current approved reviewer with access to this Site.',
            ]);
        }
    }
}
