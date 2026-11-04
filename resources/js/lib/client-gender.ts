/**
 * The one list of gender options for a supported person, used by every form
 * that records `clients.gender`. It follows the Stats NZ 2021 data standard for
 * gender (female, male, another gender with a write-in) plus a decline option.
 *
 * The server has the same list in `app/Enums/ClientGender.php`; keep the two
 * in step. The stored values are stable codes; the words the person uses for
 * Another gender go in `gender_description`.
 *
 * NHI gender codes (HISO 10046), for anyone exporting to Health NZ:
 *   female            → F
 *   male              → M
 *   another_gender    → O
 *   prefer_not_to_say → U (unknown)
 *   not recorded      → U (unknown)
 */

export const ANOTHER_GENDER = 'another_gender';

export const GENDER_OPTIONS = [
    { value: 'female', label: 'Female' },
    { value: 'male', label: 'Male' },
    { value: ANOTHER_GENDER, label: 'Another gender' },
    { value: 'prefer_not_to_say', label: 'Prefer not to say' },
] as const;

export type ClientGenderValue = (typeof GENDER_OPTIONS)[number]['value'];

/** Longest "describe their gender" text the server keeps. */
export const GENDER_DESCRIPTION_MAX_LENGTH = 100;

export function isGenderValue(
    value: string | null | undefined,
): value is ClientGenderValue {
    return GENDER_OPTIONS.some((option) => option.value === value);
}

/**
 * What to show for a stored gender: "Female", or "Another gender: <their
 * words>". Null when nothing has been chosen.
 */
export function genderLabel(
    value: string | null | undefined,
    description?: string | null,
): string | null {
    const option = GENDER_OPTIONS.find((o) => o.value === value);
    if (!option) return null;
    const words = description?.trim();
    return option.value === ANOTHER_GENDER && words
        ? `${option.label}: ${words}`
        : option.label;
}

/** The notice shown while old typed text is waiting for someone to choose. */
export function genderRecordedAsNotice(recordedAs: string): string {
    return `Gender was recorded as ‘${recordedAs}’. Please choose an option.`;
}
