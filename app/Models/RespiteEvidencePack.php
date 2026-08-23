<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

class RespiteEvidencePack extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    public const SEALED_MANIFEST_VERSION = 1;

    protected $fillable = [
        'stay_id',
        'booking_id',
        'status',
        'pack_type',
        'summary',
        'items',
        'included_documents',
        'included_incidents',
        'included_medications',
        'included_daily_notes',
        'included_handovers',
        'coordinator_notes',
        'family_feedback',
        'sealed_at',
        'sealed_by_user_id',
        'sealed_manifest_version',
        'sealed_manifest_digest',
        'exported',
        'exported_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'items' => 'array',
        'included_documents' => 'array',
        'included_incidents' => 'array',
        'included_medications' => 'array',
        'included_daily_notes' => 'array',
        'included_handovers' => 'array',
        'sealed_at' => 'datetime',
        'sealed_manifest_version' => 'integer',
        'exported' => 'boolean',
        'exported_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $pack): void {
            if (! $pack->originalHasSealEvidence()) {
                return;
            }

            $mutableAfterSeal = ['exported', 'exported_at', 'updated_at'];
            if (array_diff(array_keys($pack->getDirty()), $mutableAfterSeal) !== []) {
                throw new LogicException('Sealed respite evidence pack content is immutable.');
            }
        });

        static::deleting(function (self $pack): void {
            if ($pack->hasSealEvidence()) {
                throw new LogicException('Sealed respite evidence packs cannot be deleted.');
            }
        });
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }

    public function sealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sealed_by_user_id');
    }

    /** @param array<string, mixed> $content */
    public static function sealedContentDigestFor(
        array $content,
        int $version = self::SEALED_MANIFEST_VERSION,
    ): string {
        return hash('sha256', json_encode(
            self::canonicalise([
                'version' => $version,
                'content' => $content,
            ]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<mixed>|null  $manifest
     * @return array<string, mixed>
     */
    public function sealedContentProjection(
        int $siteId,
        int $clientId,
        ?array $manifest = null,
        DateTimeInterface|string|null $sealedAt = null,
        ?string $status = null,
    ): array {
        $sealedAt ??= $this->sealed_at;

        return [
            'pack_id' => (int) $this->id,
            'stay_id' => (int) $this->stay_id,
            'booking_id' => (int) $this->booking_id,
            'client_id' => $clientId,
            'site_id' => $siteId,
            'status' => $status ?? (string) $this->status,
            'sealed_at' => self::normaliseSealedAt($sealedAt),
            'summary' => $this->summary,
            'manifest' => $manifest ?? (is_array($this->items) ? $this->items : []),
        ];
    }

    public function hasSealEvidence(): bool
    {
        return $this->status === 'sealed'
            || $this->sealed_at !== null
            || $this->sealed_manifest_version !== null
            || (string) $this->sealed_manifest_digest !== '';
    }

    public function hasValidSealedManifest(int $siteId, int $clientId): bool
    {
        $manifest = $this->items;

        return $this->hasSealEvidence()
            && $this->status === 'sealed'
            && (int) $this->id > 0
            && (int) $this->stay_id > 0
            && (int) $this->booking_id > 0
            && $siteId > 0
            && $clientId > 0
            && $this->sealed_at !== null
            && $this->sealed_manifest_version === self::SEALED_MANIFEST_VERSION
            && preg_match('/\A[a-f0-9]{64}\z/', (string) $this->sealed_manifest_digest) === 1
            && is_array($manifest)
            && self::hasDeterministicManifestStructure($manifest)
            && hash_equals(
                (string) $this->sealed_manifest_digest,
                self::sealedContentDigestFor(
                    $this->sealedContentProjection($siteId, $clientId, $manifest),
                    $this->sealed_manifest_version,
                ),
            );
    }

    /** @param array<mixed> $manifest */
    public static function hasDeterministicManifestStructure(array $manifest): bool
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

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonicalise(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalise($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private static function normaliseSealedAt(DateTimeInterface|string|null $sealedAt): ?string
    {
        if ($sealedAt === null || $sealedAt === '') {
            return null;
        }

        $date = $sealedAt instanceof DateTimeInterface
            ? CarbonImmutable::instance($sealedAt)
            : CarbonImmutable::parse($sealedAt, 'UTC');

        return $date->utc()->toIso8601String();
    }

    private function originalHasSealEvidence(): bool
    {
        return $this->getRawOriginal('status') === 'sealed'
            || $this->getRawOriginal('sealed_at') !== null
            || $this->getRawOriginal('sealed_manifest_version') !== null
            || (string) $this->getRawOriginal('sealed_manifest_digest') !== '';
    }
}
