<?php

namespace App\Enums;

/**
 * Canonical taxonomy of next-of-kin relationships.
 *
 * Stable codes are important for filtering and categorisation in the family
 * tree view, where the legacy free-text `relationship` field was
 * string-matched into family / guardian / other buckets.
 */
enum NextOfKinRelationship: string
{
    case Parent = 'parent';
    case Sibling = 'sibling';
    case Spouse = 'spouse';
    case Child = 'child';
    case Grandparent = 'grandparent';
    case Grandchild = 'grandchild';
    case AuntUncle = 'aunt_uncle';
    case NieceNephew = 'niece_nephew';
    case Cousin = 'cousin';
    case Guardian = 'guardian';
    case Friend = 'friend';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Parent => 'Parent',
            self::Sibling => 'Sibling',
            self::Spouse => 'Spouse',
            self::Child => 'Child',
            self::Grandparent => 'Grandparent',
            self::Grandchild => 'Grandchild',
            self::AuntUncle => 'Aunt/Uncle',
            self::NieceNephew => 'Niece/Nephew',
            self::Cousin => 'Cousin',
            self::Guardian => 'Legal Guardian',
            self::Friend => 'Friend',
            self::Other => 'Other',
        };
    }

    /**
     * High-level grouping used by the family-tree tab. Keep in sync with the
     * categories rendered there.
     */
    public function category(): string
    {
        return match ($this) {
            self::Parent,
            self::Sibling,
            self::Spouse,
            self::Child,
            self::Grandparent,
            self::Grandchild,
            self::AuntUncle,
            self::NieceNephew,
            self::Cousin => 'family',
            self::Guardian => 'guardian',
            self::Friend => 'friend',
            self::Other => 'other',
        };
    }

    /**
     * Best-effort coercion from a legacy free-text value. Returns null when
     * the string does not map to any case so callers can fall through to a
     * sensible default (typically `other`).
     */
    public static function tryFromLegacy(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    /** @return array<int, array{value: string, label: string, category: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'category' => $case->category(),
            ],
            self::cases(),
        );
    }
}
