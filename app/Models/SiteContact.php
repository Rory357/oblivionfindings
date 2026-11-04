<?php

namespace App\Models;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Site contact is either someone outside the organisation (typed in) or
 * someone who works here (linked through user_id, to-do #10). For a linked
 * contact the name, role, phone and email shown everywhere come from the
 * staff record, so they stay current:
 *
 * - name  = users.name
 * - role  = hr_employee_profiles.position_title (job title)
 * - phone = hr_employee_profiles.work_phone
 * - email = hr_employee_profiles.work_email
 *
 * Work details only (DESIGN.md "Personal contact details copied into work
 * fields"): never users.cellphone (personal mobile), users.email (the sign-in
 * address, which can be personal) or the HR personal_phone / personal_email /
 * address. A missing work phone or email shows as blank, never a personal one.
 * The stored text columns keep the details from the last save and are shown
 * when the staff record is gone.
 */
class SiteContact extends Model
{
    use AuditableChanges, WritesLegacyStorageContext;

    public const TYPES = [
        'site_contact',
        'site_lead',
        'team_lead',
        'emergency',
        'manager',
        'clinical',
        'family',
        'next_of_kin',
        'maintenance',
        'other',
    ];

    /** Types that default to "Someone who works here" in the pickers. */
    public const STAFF_DEFAULT_TYPES = [
        'site_contact',
        'site_lead',
        'team_lead',
        'manager',
        'clinical',
    ];

    /** The staff-record columns a linked contact reads (work details only). */
    public const STAFF_PROFILE_COLUMNS = ['id', 'user_id', 'position_title', 'work_phone', 'work_email'];

    protected $fillable = [
        'tenant_id',
        'site_id',
        'user_id',
        'type',
        'name',
        'role',
        'phone',
        'email',
        'is_primary',
        'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'user_id' => 'integer',
    ];

    /**
     * Never serialise the linked user: it carries the sign-in email and the
     * personal mobile. Readers use name/role/phone/email, which are resolved
     * from the work details below.
     */
    protected $hidden = ['user'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->fromStaff('name', $value));
    }

    protected function role(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->fromStaff('role', $value));
    }

    protected function phone(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->fromStaff('phone', $value));
    }

    protected function email(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->fromStaff('email', $value));
    }

    /** True when this contact is linked to a staff record that still exists. */
    public function isStaffLinked(): bool
    {
        return $this->linkedStaff() !== null;
    }

    /**
     * The work details a staff member shows as a Site contact.
     *
     * @return array{name: string, role: string|null, phone: string|null, email: string|null}
     */
    public static function workDetailsFor(User $user): array
    {
        if (! $user->relationLoaded('hrEmployeeProfile')) {
            // One read per staff member, kept on the instance for the next field.
            $user->setRelation('hrEmployeeProfile', $user->hrEmployeeProfile()->first(self::STAFF_PROFILE_COLUMNS));
        }
        $profile = $user->getRelation('hrEmployeeProfile');
        $profile = $profile instanceof HrEmployeeProfile ? $profile : null;

        return [
            'name' => (string) $user->name,
            'role' => self::filledOrNull($profile?->position_title),
            'phone' => self::filledOrNull($profile?->work_phone),
            'email' => self::filledOrNull($profile?->work_email),
        ];
    }

    /**
     * Load the linked staff records for a list in two queries, and only when a
     * contact in it is linked (an unlinked list costs nothing extra).
     *
     * @param  Collection<int, SiteContact>  $contacts
     * @return Collection<int, SiteContact>
     */
    public static function primeLinkedStaff(Collection $contacts): Collection
    {
        $linked = $contacts->contains(fn (self $contact): bool => ($contact->getAttributes()['user_id'] ?? null) !== null);
        if ($linked) {
            $contacts->loadMissing([
                'user:id,name',
                'user.hrEmployeeProfile:'.implode(',', self::STAFF_PROFILE_COLUMNS),
            ]);
        }

        return $contacts;
    }

    private function linkedStaff(): ?User
    {
        if (($this->attributes['user_id'] ?? null) === null) {
            return null;
        }

        $user = $this->relationLoaded('user') ? $this->getRelation('user') : $this->getRelationValue('user');

        return $user instanceof User ? $user : null;
    }

    private function fromStaff(string $field, ?string $stored): ?string
    {
        $user = $this->linkedStaff();

        return $user === null ? $stored : self::workDetailsFor($user)[$field];
    }

    private static function filledOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
