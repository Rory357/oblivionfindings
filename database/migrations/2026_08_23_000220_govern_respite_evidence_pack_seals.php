<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MANIFEST_VERSION = 1;

    public function up(): void
    {
        [$hasVersion, $hasDigest] = $this->columnState();
        if ($hasVersion !== $hasDigest) {
            throw new RuntimeException(
                'Respite evidence seal governance has a partial schema; reconcile the seal columns before retrying.',
            );
        }

        $backfill = $this->preflightSealedPacks($hasVersion);

        if (! $hasVersion) {
            Schema::table('respite_evidence_packs', function (Blueprint $table): void {
                $table->unsignedSmallInteger('sealed_manifest_version')->nullable()->after('sealed_by_user_id');
                $table->char('sealed_manifest_digest', 64)->nullable()->after('sealed_manifest_version');
            });
        }

        foreach ($backfill as $packId => $digest) {
            $updated = DB::table('respite_evidence_packs')
                ->where('id', $packId)
                ->whereNull('sealed_manifest_version')
                ->whereNull('sealed_manifest_digest')
                ->update([
                    'sealed_manifest_version' => self::MANIFEST_VERSION,
                    'sealed_manifest_digest' => $digest,
                ]);

            if ($updated !== 1) {
                throw new RuntimeException(
                    "Sealed respite evidence pack {$packId} changed during governance backfill; retry after reconciliation.",
                );
            }
        }

        if ($this->preflightSealedPacks(true) !== []) {
            throw new RuntimeException(
                'A sealed respite evidence pack changed during governance backfill; retry after reconciliation.',
            );
        }
    }

    public function down(): void
    {
        [$hasVersion, $hasDigest] = $this->columnState();
        if (! $hasVersion && ! $hasDigest) {
            return;
        }
        if ($hasVersion !== $hasDigest) {
            throw new RuntimeException(
                'Respite evidence seal governance has a partial schema; reconcile the seal columns before rollback.',
            );
        }

        $hasGovernedSeals = DB::table('respite_evidence_packs')
            ->where(function ($query): void {
                $query->where('status', 'sealed')
                    ->orWhereNotNull('sealed_at')
                    ->orWhereNotNull('sealed_manifest_version')
                    ->orWhereNotNull('sealed_manifest_digest');
            })
            ->exists();

        if ($hasGovernedSeals) {
            throw new RuntimeException(
                'Cannot roll back respite evidence seal governance while sealed evidence packs exist.',
            );
        }

        Schema::table('respite_evidence_packs', function (Blueprint $table): void {
            $table->dropColumn(['sealed_manifest_version', 'sealed_manifest_digest']);
        });
    }

    /** @return array{0:bool,1:bool} */
    private function columnState(): array
    {
        return [
            Schema::hasColumn('respite_evidence_packs', 'sealed_manifest_version'),
            Schema::hasColumn('respite_evidence_packs', 'sealed_manifest_digest'),
        ];
    }

    /** @return array<int,string> */
    private function preflightSealedPacks(bool $sealColumnsExist): array
    {
        $query = DB::table('respite_evidence_packs as packs')
            ->leftJoin('respite_stays as stays', 'stays.id', '=', 'packs.stay_id')
            ->leftJoin('respite_bookings as bookings', 'bookings.id', '=', 'stays.booking_id')
            ->leftJoin('clients', 'clients.id', '=', 'stays.client_id')
            ->select([
                'packs.id',
                'packs.stay_id',
                'packs.booking_id',
                'packs.status',
                'packs.sealed_at',
                'packs.summary',
                'packs.items',
                'stays.id as canonical_stay_id',
                'stays.booking_id as canonical_booking_id',
                'stays.client_id as stay_client_id',
                'bookings.id as joined_booking_id',
                'bookings.client_id as booking_client_id',
                'bookings.location_id as booking_site_id',
                'clients.id as joined_client_id',
                'clients.site_id as client_site_id',
            ])
            ->when($sealColumnsExist, fn ($builder) => $builder->addSelect([
                'packs.sealed_manifest_version',
                'packs.sealed_manifest_digest',
            ]))
            ->where(function ($builder) use ($sealColumnsExist): void {
                $builder->where('packs.status', 'sealed')
                    ->orWhereNotNull('packs.sealed_at');
                if ($sealColumnsExist) {
                    $builder->orWhereNotNull('packs.sealed_manifest_version')
                        ->orWhereNotNull('packs.sealed_manifest_digest');
                }
            })
            ->orderBy('packs.id');

        $backfill = [];
        foreach ($query->get() as $pack) {
            $manifest = $this->decodeManifest($pack);
            $siteId = (int) ($pack->booking_site_id ?: $pack->client_site_id);
            $isCanonical = $pack->status === 'sealed'
                && $pack->sealed_at !== null
                && (int) $pack->id > 0
                && (int) $pack->stay_id > 0
                && (int) $pack->booking_id > 0
                && (int) $pack->canonical_stay_id === (int) $pack->stay_id
                && (int) $pack->canonical_booking_id === (int) $pack->booking_id
                && (int) $pack->joined_booking_id === (int) $pack->booking_id
                && (int) $pack->stay_client_id > 0
                && (int) $pack->booking_client_id === (int) $pack->stay_client_id
                && (int) $pack->joined_client_id === (int) $pack->stay_client_id
                && (int) $pack->client_site_id > 0
                && $siteId > 0
                && $this->hasDeterministicManifestStructure($manifest);

            if (! $isCanonical) {
                throw $this->reconciliationRequired((int) $pack->id);
            }

            $digest = $this->digest([
                'pack_id' => (int) $pack->id,
                'stay_id' => (int) $pack->stay_id,
                'booking_id' => (int) $pack->booking_id,
                'client_id' => (int) $pack->stay_client_id,
                'site_id' => $siteId,
                'status' => (string) $pack->status,
                'sealed_at' => CarbonImmutable::parse(
                    (string) $pack->sealed_at,
                    (string) config('app.timezone', 'UTC'),
                )->utc()->toIso8601String(),
                'summary' => $pack->summary,
                'manifest' => $manifest,
            ]);

            if ($sealColumnsExist) {
                $version = $pack->sealed_manifest_version;
                $existingDigest = $pack->sealed_manifest_digest;
                if ($version === null && $existingDigest === null) {
                    $backfill[(int) $pack->id] = $digest;

                    continue;
                }
                if ((int) $version !== self::MANIFEST_VERSION
                    || ! is_string($existingDigest)
                    || ! hash_equals($digest, $existingDigest)) {
                    throw $this->reconciliationRequired((int) $pack->id);
                }

                continue;
            }

            $backfill[(int) $pack->id] = $digest;
        }

        return $backfill;
    }

    /** @return array<mixed> */
    private function decodeManifest(object $pack): array
    {
        if (! is_string($pack->items)) {
            throw $this->reconciliationRequired((int) $pack->id);
        }

        try {
            $manifest = json_decode($pack->items, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->reconciliationRequired((int) $pack->id);
        }

        if (! is_array($manifest)) {
            throw $this->reconciliationRequired((int) $pack->id);
        }

        return $manifest;
    }

    /** @param array<mixed> $manifest */
    private function hasDeterministicManifestStructure(array $manifest): bool
    {
        if ($manifest === [] || ! array_is_list($manifest)) {
            return false;
        }

        $ids = [];
        foreach ($manifest as $item) {
            if (! is_array($item)
                || ! is_string($item['id'] ?? null)
                || trim($item['id']) === ''
                || isset($ids[$item['id']])) {
                return false;
            }
            $ids[$item['id']] = true;
        }

        return true;
    }

    /** @param array<string,mixed> $content */
    private function digest(array $content): string
    {
        return hash('sha256', json_encode(
            $this->canonicalise([
                'version' => self::MANIFEST_VERSION,
                'content' => $content,
            ]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonicalise(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalise($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function reconciliationRequired(int $packId): RuntimeException
    {
        return new RuntimeException(
            "Sealed respite evidence pack {$packId} requires reconciliation before seal governance can be applied.",
        );
    }
};
