<?php

namespace App\Http\Requests\Sites;

use App\Models\SiteContact;
use Illuminate\Validation\Rule;

/**
 * The contact-row rules shared by Add site, Edit site and Site profile ›
 * Contacts (to-do #10). A row is either someone outside the organisation
 * (name required, details typed in) or someone who works here (user_id; the
 * name, role, phone and email come from their staff record, so whatever the
 * form sent for those is dropped before validation).
 */
final class SiteContactRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rows(string $prefix): array
    {
        return [
            "{$prefix}.id" => ['nullable', 'integer'],
            "{$prefix}.user_id" => ['nullable', 'integer', 'exists:users,id'],
            "{$prefix}.type" => ['required_with:'.$prefix, 'string', Rule::in(SiteContact::TYPES)],
            "{$prefix}.name" => ['nullable', "required_without:{$prefix}.user_id", 'string', 'max:255'],
            "{$prefix}.role" => ['nullable', 'string', 'max:255'],
            "{$prefix}.phone" => ['nullable', 'string', 'max:60'],
            "{$prefix}.email" => ['nullable', 'string', 'email', 'max:255'],
            "{$prefix}.is_primary" => ['nullable', 'boolean'],
            "{$prefix}.notes" => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @param  array<int, mixed>  $contacts
     * @return array<int, mixed>
     */
    public static function withoutLinkedDetails(array $contacts): array
    {
        return array_map(function ($contact) {
            return is_array($contact) ? self::rowWithoutLinkedDetails($contact) : $contact;
        }, $contacts);
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    public static function rowWithoutLinkedDetails(array $contact): array
    {
        if (! filled($contact['user_id'] ?? null)) {
            return $contact;
        }

        unset($contact['name'], $contact['role'], $contact['phone'], $contact['email']);

        return $contact;
    }
}
