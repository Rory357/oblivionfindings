<?php

namespace App\Domain\Referrals\Models;

use App\Domain\Referrals\Models\Concerns\HasReferralReference;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CareReferral extends Model
{
    use HasReferralReference;

    /**
     * Entry kinds that are general enquiries rather than care referrals (Stephan, 20–21 Oct 2026):
     * Contact us messages, and general questions from the enquiry form. While still a general
     * enquiry they sit in their own queue, are left out of referral counts and reports, and carry an
     * ENQ- reference. Completing the intake (general_enquiry false) makes one a referral.
     */
    public const ENQUIRY_KINDS = ['contact', 'business'];

    public const ENQUIRY_PREFIX = 'ENQ-';

    protected static function booted(): void
    {
        // Each general enquiry gets the next number from a counter that never goes back.
        static::creating(function (CareReferral $referral): void {
            if ($referral->enquiry_number === null && $referral->isGeneralEnquiry()) {
                $referral->enquiry_number = self::nextEnquiryNumber();
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    protected $table = 'care_referrals';

    protected $fillable = [
        'reference',
        'enquiry_number',
        'site_id',
        'owner_id',
        'settings_version_id',
        'client_id',
        'stage',
        'source',
        'entry_kind',
        'requested_service',
        'team',
        'priority',
        'restricted',
        'general_enquiry',
        'identity',
        'intake',
        'authority',
        'assessment',
        'next_action',
        'next_due_at',
        'hold_reason',
        'closure_reason',
        'stage_changed_at',
        'first_contact_attempt_at',
        'first_human_contact_at',
        'primary_work_item_id',
        'converted_at',
        'closed_at',
        'lock_version',
    ];

    protected $hidden = ['identity', 'intake', 'authority', 'assessment', 'next_action'];

    protected $casts = [
        'identity' => 'encrypted:array',
        'intake' => 'encrypted:array',
        'authority' => 'encrypted:array',
        'assessment' => 'encrypted:array',
        'next_action' => 'encrypted',
        'enquiry_number' => 'integer',
        'restricted' => 'boolean',
        'general_enquiry' => 'boolean',
        'next_due_at' => 'immutable_datetime',
        'stage_changed_at' => 'immutable_datetime',
        'first_contact_attempt_at' => 'immutable_datetime',
        'first_human_contact_at' => 'immutable_datetime',
        'primary_work_item_id' => 'integer',
        'converted_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'lock_version' => 'integer',
    ];

    /** A general enquiry, not (yet) a care referral. */
    public function isGeneralEnquiry(): bool
    {
        return (bool) $this->general_enquiry && in_array($this->entry_kind, self::ENQUIRY_KINDS, true);
    }

    /** "ENQ-1001" for a numbered enquiry, otherwise null. */
    public function enquiryReference(): ?string
    {
        return $this->enquiry_number ? self::ENQUIRY_PREFIX.$this->enquiry_number : null;
    }

    /** The reference the person was given: ENQ- for an enquiry, the referral reference otherwise. */
    public function displayReference(): string
    {
        return $this->enquiryReference() ?? $this->reference;
    }

    /** Only general enquiries (their own workspace queue). */
    public function scopeGeneralEnquiries(Builder $query): Builder
    {
        return $query->where('care_referrals.general_enquiry', true)->whereIn('care_referrals.entry_kind', self::ENQUIRY_KINDS);
    }

    /** Everything except general enquiries: referral queues, meters and reports. */
    public function scopeExceptGeneralEnquiries(Builder $query): Builder
    {
        return $query->where(fn (Builder $referrals) => $referrals->where('care_referrals.general_enquiry', false)
            ->orWhereNotIn('care_referrals.entry_kind', self::ENQUIRY_KINDS));
    }

    /**
     * The next ENQ- number. The counter row stays locked until the caller's transaction ends, so
     * concurrent enquiries never share a number, and a rolled-back enquiry never consumes one.
     */
    public static function nextEnquiryNumber(): int
    {
        return DB::transaction(function (): int {
            DB::table('care_referral_enquiry_sequence')->insertOrIgnore(['id' => 1, 'last_number' => 1000, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('care_referral_enquiry_sequence')->where('id', 1)->increment('last_number', 1, ['updated_at' => now()]);

            return (int) DB::table('care_referral_enquiry_sequence')->where('id', 1)->value('last_number');
        });
    }
}
