<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Explicit staff-care release of immutable evidence; never an automatic funding-file transfer. */
final class ReferralDocumentHandovers
{
    public function validate(array $input): array
    {
        $data = Validator::make(['documents' => $input], [
            'documents' => 'array|max:10',
            'documents.*' => 'required|array:evidence_id,title,purpose,confirmed',
            'documents.*.evidence_id' => 'required|integer|min:1|distinct',
            'documents.*.title' => 'required|string|min:3|max:200',
            'documents.*.purpose' => 'required|string|min:10|max:2000',
            'documents.*.confirmed' => 'accepted',
        ])->validate()['documents'];
        foreach ($data as &$row) {
            $row['evidence_id'] = (int) $row['evidence_id'];
            $row['title'] = trim($row['title']);
            $row['purpose'] = trim($row['purpose']);
            abort_unless(mb_strlen($row['title']) >= 3 && mb_strlen($row['purpose']) >= 10, 422, 'Record a meaningful document title and care purpose.');
            $row['confirmed'] = true;
        }
        unset($row);
        usort($data, fn ($a, $b) => $a['evidence_id'] <=> $b['evidence_id']);

        return $data;
    }

    /** Called inside the locked conversion or care-revision transaction. */
    public function apply(User $actor, Client $person, CareReferral $referral, Collection $evidence, array $reviews, CurrentAuthorizationReads $reads): array
    {
        if ($reviews === []) {
            return [];
        }
        $access = app(ReferralAccess::class);
        abort_unless($access->can($actor, 'evidence.manage') && $access->audience($actor, 'assessment', true), 403);
        abort_unless(app(ClientPolicy::class)->viewFromCurrentEvidence($actor, $person, $reads)
            && app(ClientPolicy::class)->updateFromCurrentEvidence($actor, $person, $reads), 403);
        abort_unless(ReferralCareHandoverRevisions::reviewReady($referral), 422, 'Review current authority and assessment before releasing documents for care.');
        $mappings = [];
        foreach ($reviews as $review) {
            $source = $evidence->firstWhere('id', $review['evidence_id']);
            abort_unless($source && $source->referral_id === $referral->id && $source->audience === 'assessment', 422, 'Release only selected assessment evidence from this referral. Private funding documents remain separate.');
            abort_unless($this->available($source) && app(ReferralEvidenceFiles::class)->bytes($source) !== null, 422, 'The document must still be current, available and intact.');
            $document = ClientDocument::query()->where('client_id', $person->id)->where('source_referral_evidence_id', $source->id)->lockForUpdate()->first();
            $releaseVersion = (int) ($document?->referral_release['version'] ?? 0) + 1;
            $extension = match ($source->metadata['mime']) {
                'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
            };
            $release = ['version' => $releaseVersion, 'evidence_id' => $source->id, 'sha256' => $source->sha256,
                'authority_version' => $referral->authority['review']['version'], 'assessment_version' => $referral->assessment['version'],
                'purpose' => $review['purpose'], 'confirmed' => true, 'released_by' => $actor->id, 'released_at' => now()->toISOString()];
            $document ??= new ClientDocument;
            $document->forceFill(['client_id' => $person->id, 'uploaded_by_user_id' => $actor->id, 'title' => $review['title'],
                'category' => 'referral_handover', 'folder' => $document->folder ?? 'Referral handover', 'version' => (string) $releaseVersion,
                'effective_date' => today('Pacific/Auckland')->toDateString(), 'expiry_date' => empty($source->metadata['expires_at']) ? null : CarbonImmutable::parse($source->metadata['expires_at'])->timezone('Pacific/Auckland')->toDateString(),
                'portal_visible' => false, 'notes' => $document->notes, 'storage_disk' => $source->disk, 'storage_path' => $source->storage_path,
                'original_name' => (Str::slug($review['title']) ?: 'care-document').'.'.$extension, 'mime_type' => $source->metadata['mime'],
                'size_bytes' => $source->size_bytes, 'source_referral_evidence_id' => $source->id, 'referral_release' => $release])->save();
            $mapping = ['document_id' => $document->id, 'evidence_id' => $source->id, 'title' => $review['title'], ...$release];
            $mappings[] = $mapping;
            app(ReferralCommand::class)->record($referral, $actor, 'document.released_for_care', 'assessment', $mapping);
        }

        return $mappings;
    }

    public function visible(Builder $query, User $actor): Builder
    {
        $sites = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['clinical.accessAllSites', 'sites.viewAll']);
        $sources = CareReferral::query()->whereIn('site_id', $sites)
            ->when(! $actor->canDo('referrals.restricted'), fn ($q) => $q->where('restricted', false));

        return $query->where(fn ($q) => $q->whereNull('source_referral_evidence_id')
            ->orWhereIn('source_referral_evidence_id', ReferralEvidence::query()->whereIn('referral_id', $sources->select('id'))->select('id')));
    }

    /** Canonical client/document authorization must already have passed. */
    public function bytes(User $actor, Client $person, ClientDocument $document): string
    {
        abort_unless($this->visible(ClientDocument::query()->whereKey($document->id), $actor)->exists(), 404);
        $source = ReferralEvidence::findOrFail($document->source_referral_evidence_id);
        $referral = CareReferral::findOrFail($source->referral_id);
        $release = $document->referral_release ?? [];
        abort_unless((int) $referral->client_id === (int) $person->id && $source->audience === 'assessment', 404);
        abort_unless(($release['confirmed'] ?? false) && ReferralCareHandoverRevisions::reviewReady($referral)
            && ($release['authority_version'] ?? null) === ($referral->authority['review']['version'] ?? null)
            && ($release['assessment_version'] ?? null) === ($referral->assessment['version'] ?? null)
            && $this->available($source), 409, 'This handover document needs a current review. Ask the referral team to update the care handover.');
        abort_unless(hash_equals($source->sha256, $release['sha256'] ?? ''), 409, 'The reviewed document version changed. Review the care handover.');
        $bytes = app(ReferralEvidenceFiles::class)->bytes($source);
        abort_unless($bytes !== null, 409, 'This document is unavailable. Ask the referral team to review its evidence.');

        return $bytes;
    }

    /** Bounded status only; callers must apply visible() before projecting. No file reads on lists. */
    public function project(Collection $documents): void
    {
        $sources = ReferralEvidence::query()->whereIn('id', $documents->pluck('source_referral_evidence_id')->filter())->get()->keyBy('id');
        $referrals = CareReferral::query()->whereIn('id', $sources->pluck('referral_id'))->get()->keyBy('id');
        foreach ($documents as $document) {
            if (! $document->source_referral_evidence_id) {
                continue;
            }
            $source = $sources->get($document->source_referral_evidence_id);
            $referral = $source ? $referrals->get($source->referral_id) : null;
            $release = $document->referral_release ?? [];
            $current = $source && $referral && (int) $referral->client_id === (int) $document->client_id
                && $source->audience === 'assessment' && ($release['confirmed'] ?? false)
                && $this->available($source) && ReferralCareHandoverRevisions::reviewReady($referral)
                && ($release['authority_version'] ?? null) === ($referral->authority['review']['version'] ?? null)
                && ($release['assessment_version'] ?? null) === ($referral->assessment['version'] ?? null)
                && hash_equals($source->sha256, $release['sha256'] ?? '');
            $document->setAttribute('care_release', ['review_required' => ! $current]);
        }
    }

    private function available(ReferralEvidence $source): bool
    {
        return $source->state === 'available' && empty($source->metadata['superseded_by_id'])
            && (empty($source->metadata['expires_at']) || CarbonImmutable::parse($source->metadata['expires_at'])->isFuture());
    }
}
