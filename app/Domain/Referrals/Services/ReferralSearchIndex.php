<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Normalizer;
use RuntimeException;

/**
 * Blind index for finding referrals by name. Identity is encrypted at rest, so
 * SQL cannot match it; instead each name word's prefixes (2–32 characters,
 * lower-cased, accents folded) are stored as keyed HMAC-SHA256 hashes. A search
 * hashes the typed words the same way and intersects the matches with the
 * caller's ReferralAccess scope — the index never widens visibility.
 */
final class ReferralSearchIndex
{
    public const TABLE = 'care_referral_search_tokens';

    private const MIN = 2;

    private const MAX = 32;

    private ?string $key = null;

    /** Replace the stored tokens for one referral (called whenever identity is saved). */
    public function sync(CareReferral $referral): void
    {
        $hashes = $this->hashesFor($referral);
        DB::table(self::TABLE)->where('referral_id', $referral->id)->delete();
        foreach (array_chunk($hashes, 250) as $chunk) {
            DB::table(self::TABLE)->insert(array_map(fn (string $hash) => ['referral_id' => $referral->id, 'token_hash' => $hash], $chunk));
        }
    }

    /** Rebuild every referral's tokens, e.g. after an application key rotation. Returns rows indexed. */
    public function rebuild(): int
    {
        $count = 0;
        CareReferral::query()->select(['id', 'identity', 'intake'])->chunkById(200, function ($rows) use (&$count): void {
            foreach ($rows as $referral) {
                try {
                    $this->sync($referral);
                    $count++;
                } catch (DecryptException) {
                    // An unreadable record stays unsearchable rather than blocking the rebuild.
                    DB::table(self::TABLE)->where('referral_id', $referral->id)->delete();
                }
            }
        });

        return $count;
    }

    /**
     * Narrow a scoped referral query to a name or reference search. The caller's
     * query must already carry ReferralAccess scope; this only ever narrows it.
     */
    public function constrain(Builder $query, string $search): Builder
    {
        $search = trim($search);
        $hashes = $this->queryHashes($search);
        $reference = preg_match('/^[0-9A-Za-z]{2,26}$/', $search) === 1 ? strtoupper($search) : null;
        // A general enquiry's ENQ- number, exactly ("ENQ-1001", "enq 1001" or "ENQ1001").
        $enquiry = preg_match('/^enq[\s-]?(\d{1,9})$/i', $search, $number) === 1 ? (int) $number[1] : null;
        if ($hashes === [] && $reference === null && $enquiry === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $match) use ($hashes, $reference, $enquiry): void {
            if ($hashes !== []) {
                $match->whereIn('care_referrals.id', $this->matching($hashes));
            }
            if ($reference !== null) {
                $match->orWhere('care_referrals.reference', 'like', $reference.'%');
            }
            if ($enquiry !== null) {
                $match->orWhere('care_referrals.enquiry_number', $enquiry);
            }
        });
    }

    /** @return list<string> hashed tokens for the referral's displayed names */
    public function hashesFor(CareReferral $referral): array
    {
        $identity = is_array($referral->identity) ? $referral->identity : [];
        $values = [$identity['first_name'] ?? null, $identity['last_name'] ?? null, $identity['preferred_name'] ?? null];
        if (trim(($identity['first_name'] ?? '').($identity['last_name'] ?? '')) === '') {
            // The workspace shows "Enquiry from <contact>" when the person is not yet named.
            $intake = is_array($referral->intake) ? $referral->intake : [];
            $values[] = $intake['submitted_contact']['name'] ?? null;
        }
        $tokens = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $words = $this->words($value);
            if (count($words) > 1) {
                $words[] = implode('', $words);
            }
            foreach ($words as $word) {
                $length = min(mb_strlen($word), self::MAX);
                for ($size = self::MIN; $size <= $length; $size++) {
                    $tokens[mb_substr($word, 0, $size)] = true;
                }
            }
        }

        return array_values(array_map(fn (string $token) => $this->hash($token), array_keys($tokens)));
    }

    /** @return list<string> one hash per usable search word (every word must match) */
    public function queryHashes(string $search): array
    {
        $hashes = [];
        foreach ($this->words($search) as $word) {
            if (mb_strlen($word) >= self::MIN) {
                $hashes[$this->hash(mb_substr($word, 0, self::MAX))] = true;
            }
        }

        return array_slice(array_keys($hashes), 0, 6);
    }

    public static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        if (class_exists(Normalizer::class)) {
            $folded = Normalizer::normalize($value, Normalizer::FORM_KD);
            if (is_string($folded)) {
                $value = preg_replace('/\p{Mn}+/u', '', $folded) ?? $value;
            }
        }

        return $value;
    }

    /** @return list<string> */
    private function words(string $value): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', self::normalise($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @param  list<string>  $hashes */
    private function matching(array $hashes): QueryBuilder
    {
        return DB::table(self::TABLE)->select('referral_id')->whereIn('token_hash', $hashes)
            ->groupBy('referral_id')->havingRaw('COUNT(DISTINCT token_hash) = ?', [count($hashes)]);
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->key());
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new RuntimeException('An application key is required to search referrals by name.');
        }
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }

        // A purpose-bound sub-key: the index never reuses the encryption key directly.
        return $this->key = hash_hmac('sha256', 'oblivion.referrals.search-index.v1', $appKey, true);
    }
}
