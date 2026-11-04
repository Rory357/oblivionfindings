<?php

namespace App\Enums;

/**
 * The one list of gender options for a supported person (`clients.gender`).
 *
 * It follows the Stats NZ 2021 data standard for gender (female, male, another
 * gender with a write-in) plus a decline option. The write-in ("describe in
 * their own words") is stored in `clients.gender_description` and kept only for
 * Another gender. Text typed into the old free-text box that could not be
 * mapped is kept in `clients.gender_recorded_as` until someone chooses an
 * option, so nothing a person told us is lost or guessed.
 *
 * NHI gender codes (HISO 10046), for anyone exporting to Health NZ:
 *   Female            → F
 *   Male              → M
 *   Another gender    → O
 *   Prefer not to say → U (unknown)
 *   Not recorded      → U (unknown)
 *
 * The front end has the same list in `resources/js/lib/client-gender.ts`;
 * keep the two in step.
 */
enum ClientGender: string
{
    case Female = 'female';
    case Male = 'male';
    case AnotherGender = 'another_gender';
    case PreferNotToSay = 'prefer_not_to_say';

    /** Longest "describe their gender" text we keep. */
    public const DESCRIPTION_MAX_LENGTH = 100;

    /**
     * Old typed answers that clearly mean one option (compared lower-case,
     * trimmed, with macrons, apostrophes and separators flattened).
     */
    private const FEMALE_WORDS = [
        'f', 'female', 'woman', 'girl', 'lady', 'wahine',
        'cis female', 'cis woman', 'cisgender female', 'cisgender woman',
    ];

    private const MALE_WORDS = [
        'm', 'male', 'man', 'boy', 'tane',
        'cis male', 'cis man', 'cisgender male', 'cisgender man',
    ];

    private const PREFER_NOT_TO_SAY_WORDS = [
        'prefer not to say', 'prefers not to say', 'prefer not to answer', 'rather not say',
        'not stated', 'not specified', 'unspecified', 'not disclosed', 'undisclosed', 'withheld',
        'declined', 'declined to answer', 'declined to say', 'declined to state',
        'unknown', 'not known', 'u',
    ];

    /**
     * Words that always mean a gender other than only female or male. These are
     * real genders: they map to Another gender with the person's own words kept
     * as the description, and are never collapsed into Female or Male, even when
     * the answer also contains "woman" or "man" (e.g. "trans woman").
     */
    private const ANOTHER_GENDER_PATTERN = '/\b(non ?binary|nb|enby|gender ?(queer|fluid|diverse|non ?conforming)|agender|bi ?gender|pan ?gender|demi ?(boy|girl|gender)|two ?spirit|third gender|takatapui|faafafine|fakaleiti|leiti|akavaine|fakafifine|fiafifine|vakasalewalewa|mahu|trans|transgender|transsexual|trans ?(woman|man|female|male)|mtf|ftm)\b/u';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Female',
            self::Male => 'Male',
            self::AnotherGender => 'Another gender',
            self::PreferNotToSay => 'Prefer not to say',
        };
    }

    /** The NHI (HISO 10046) gender code for Health NZ exports. */
    public function nhiCode(): string
    {
        return match ($this) {
            self::Female => 'F',
            self::Male => 'M',
            self::AnotherGender => 'O',
            self::PreferNotToSay => 'U',
        };
    }

    /** NHI code for a stored value; anything not chosen is unknown (U). */
    public static function nhiCodeFor(?string $gender): string
    {
        return self::tryFrom((string) $gender)?->nhiCode() ?? 'U';
    }

    /**
     * What the profile, exports and summaries show: "Female", or
     * "Another gender: <their words>". Null when nothing has been chosen.
     */
    public static function displayLabel(?string $gender, ?string $description = null): ?string
    {
        $option = self::tryFrom((string) $gender);
        if ($option === null) {
            return null;
        }

        $description = trim((string) $description);

        return $option === self::AnotherGender && $description !== ''
            ? $option->label().': '.$description
            : $option->label();
    }

    /**
     * Old typed text still waiting for someone to choose an option, or null
     * once an option is chosen. Shown as "Gender was recorded as '…'".
     */
    public static function waitingForChoice(?string $gender, ?string $recordedAs): ?string
    {
        if (self::tryFrom((string) $gender) !== null) {
            return null;
        }

        $text = trim((string) ($recordedAs ?? $gender));

        return $text === '' ? null : $text;
    }

    /**
     * The gender fields of the Add client wizard's prefill. The wizard only
     * sends gender and gender_description back; the old typed text is shown.
     *
     * @return array{gender: string, gender_description: string, gender_recorded_as: string}
     */
    public static function wizardValues(?string $gender, ?string $description, ?string $recordedAs): array
    {
        $option = self::tryFrom((string) $gender);

        return [
            'gender' => $option?->value ?? '',
            'gender_description' => $option === self::AnotherGender ? (string) $description : '',
            'gender_recorded_as' => self::waitingForChoice($gender, $recordedAs) ?? '',
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Form request rules: gender stays optional; the description is required
     * for Another gender (the model clears it for every other option).
     *
     * @return array<string, list<string>>
     */
    public static function validationRules(): array
    {
        return [
            'gender' => ['nullable', 'string', 'in:'.implode(',', self::values())],
            'gender_description' => [
                'nullable',
                'string',
                'max:'.self::DESCRIPTION_MAX_LENGTH,
                'required_if:gender,'.self::AnotherGender->value,
            ],
        ];
    }

    /** @return array<string, string> */
    public static function validationMessages(): array
    {
        return [
            'gender.in' => 'Choose a gender from the list.',
            'gender_description.required_if' => 'Describe their gender in their own words, or choose another option.',
            'gender_description.max' => 'Keep the description to '.self::DESCRIPTION_MAX_LENGTH.' characters or fewer.',
        ];
    }

    /**
     * Map an answer typed into the old free-text box. Returns the option and,
     * for Another gender, the person's own words (trimmed, otherwise as typed).
     * Returns null when the text does not map clearly, so the caller keeps it
     * and asks someone to choose.
     *
     * @return array{0: self, 1: string|null}|null
     */
    public static function fromLegacyText(?string $text): ?array
    {
        $typed = trim((string) $text);
        if ($typed === '') {
            return null;
        }

        $words = self::normalise($typed);

        if (preg_match(self::ANOTHER_GENDER_PATTERN, $words) === 1) {
            return mb_strlen($typed) <= self::DESCRIPTION_MAX_LENGTH
                ? [self::AnotherGender, $typed]
                : null;
        }

        return match (true) {
            in_array($words, self::FEMALE_WORDS, true) => [self::Female, null],
            in_array($words, self::MALE_WORDS, true) => [self::Male, null],
            in_array($words, self::PREFER_NOT_TO_SAY_WORDS, true) => [self::PreferNotToSay, null],
            default => null,
        };
    }

    /**
     * The three gender columns as they should be saved. A chosen option clears
     * the old typed text; Another gender keeps its description and every other
     * option clears it. Nothing chosen keeps any old typed text so the profile
     * still asks for a choice. Free text from a writer that skipped the form is
     * mapped like the data migration did, never guessed.
     *
     * @return array{gender: string|null, gender_description: string|null, gender_recorded_as: string|null}
     */
    public static function forStorage(?string $gender, ?string $description, ?string $recordedAs): array
    {
        $gender = trim((string) $gender);
        $description = trim((string) $description);
        $description = $description === '' ? null : mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH);

        if ($gender === '') {
            return ['gender' => null, 'gender_description' => null, 'gender_recorded_as' => $recordedAs];
        }

        $option = self::tryFrom($gender);
        if ($option === null) {
            $mapped = self::fromLegacyText($gender);
            if ($mapped === null) {
                return ['gender' => null, 'gender_description' => null, 'gender_recorded_as' => mb_substr($gender, 0, 255)];
            }
            [$option, $description] = $mapped;
        }

        return [
            'gender' => $option->value,
            'gender_description' => $option === self::AnotherGender ? $description : null,
            'gender_recorded_as' => null,
        ];
    }

    private static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'ā' => 'a', 'ē' => 'e', 'ī' => 'i', 'ō' => 'o', 'ū' => 'u',
            "'" => '', '’' => '', '‘' => '', 'ʻ' => '', '`' => '',
        ]);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
