<?php

namespace App\Domain\Referrals\Services;

use Illuminate\Validation\ValidationException;

/**
 * Staff message templates (Settings › Referrals › Workflow › Delivery & channels, specification §9).
 *
 * Templates are versioned with the rest of the referral settings. Only the safe merge fields below
 * can be used: names, references and the provider's own contact details. No merge field carries
 * health, assessment, funding or authority information. A published template keeps its reference,
 * channel and recipient for good: it can be renamed, reworded or turned off, never removed.
 */
final class ReferralMessageTemplates
{
    /** Safe merge fields. `document_link` is resolved at delivery from an open secure document request. */
    public const FIELDS = ['first_name', 'reference', 'provider_name', 'coordinator_name', 'contact_phone', 'response_hours', 'document_link'];

    public const CHANNELS = ['email', 'sms'];

    /**
     * Who a template is written for: the person (when they contacted us themselves), the referrer
     * (the enquiry's own contact, or a reviewed contact whose role is referrer), or someone else
     * involved from People involved (family, supporter, advocate, representative, professional).
     */
    public const AUDIENCES = ['person', 'referrer', 'contact'];

    /** A text message may run to three parts (459 plain characters, or 201 with macrons or curly quotes). */
    public const SMS_MAX_PARTS = 3;

    public const EMAIL_SUBJECT_MAX = 150;

    public const EMAIL_BODY_MAX = 4000;

    /** "A short edit": staff may add at most this many characters to a template's wording. */
    public const EDIT_ALLOWANCE = 400;

    /** Typical longest values, used to check a text message template stays within its parts. */
    public const ALLOWANCES = ['first_name' => 20, 'reference' => 26, 'coordinator_name' => 30];

    /** The secure link path; the 64-character token is never stored in a message or history entry. */
    public const LINK_PATH = '/referrals/documents/';

    private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENSION = '^{}\\[~]|€';

    /**
     * The standard templates. They start switched off (Stephan, 21 Oct 2026): someone reviews the
     * wording in Settings › Referrals and switches on the ones they want.
     */
    public static function defaults(): array
    {
        return ['templates' => array_map(fn (array $template) => [...$template, 'enabled' => false], [
            [
                'id' => 'first_contact', 'label' => 'First contact attempt', 'channel' => 'email', 'audience' => 'person',
                'subject' => 'About your enquiry with {provider_name}',
                'body' => "Kia ora {first_name},\n\nThank you for contacting {provider_name}. {coordinator_name} would like to talk with you about the support you are looking for.\n\nPlease call us on {contact_phone} ({response_hours}), or reply with a good time for us to call you.\n\nYour reference is {reference}.\n\nNgā mihi,\n{provider_name}",
                'enabled' => true,
            ],
            [
                'id' => 'tried_to_call', 'label' => 'We tried to call you', 'channel' => 'sms', 'audience' => 'person',
                'subject' => null,
                'body' => 'Kia ora {first_name}, we tried to call you about your enquiry with {provider_name}. Please call us on {contact_phone}, {response_hours}. Ref {reference}',
                'enabled' => true,
            ],
            [
                'id' => 'documents_needed', 'label' => 'Documents we still need', 'channel' => 'email', 'audience' => 'referrer',
                'subject' => 'Documents for your referral to {provider_name}',
                'body' => "Kia ora {first_name},\n\nThank you for your referral to {provider_name}. We still need a document before we can continue. Please upload it using this secure link:\n\n{document_link}\n\nWe will give you the access code separately. Please do not reply to this email with documents.\n\nIf you have any questions, call {coordinator_name} on {contact_phone} ({response_hours}).\n\nReference: {reference}\n\nNgā mihi,\n{provider_name}",
                'enabled' => true,
            ],
            [
                'id' => 'appointment_reminder', 'label' => 'Appointment reminder', 'channel' => 'sms', 'audience' => 'person',
                'subject' => null,
                'body' => 'Kia ora {first_name}, this is a reminder about your appointment with {coordinator_name} from {provider_name}. To change it, please call {contact_phone}. Ref {reference}',
                'enabled' => true,
            ],
            [
                // For someone in People involved. It never names the person the referral is about.
                'id' => 'please_call_us', 'label' => 'Please call us', 'channel' => 'email', 'audience' => 'contact',
                'subject' => 'A message from {provider_name}',
                'body' => "Kia ora {first_name},\n\n{coordinator_name} from {provider_name} would like to talk with you. Please call us on {contact_phone} ({response_hours}).\n\nReference: {reference}\n\nNgā mihi,\n{provider_name}",
                'enabled' => true,
            ],
        ])];
    }

    /** References of the standard templates, so the editor can mark them "ready to review". */
    public static function standardIds(): array
    {
        return array_column(self::defaults()['templates'], 'id');
    }

    /**
     * The templates of a configuration. Versions published before templates existed read as the
     * standard templates, so every version shows (and sends) the same way.
     *
     * @return list<array{id: string, label: string, channel: string, audience: string, subject: ?string, body: string, enabled: bool}>
     */
    public static function templates(?array $configuration): array
    {
        $templates = $configuration['communications']['templates'] ?? self::defaults()['templates'];

        return array_values(array_map(fn (array $template) => [
            'id' => $template['id'], 'label' => $template['label'], 'channel' => $template['channel'], 'audience' => $template['audience'],
            'subject' => $template['channel'] === 'email' ? ($template['subject'] ?? '') : null,
            'body' => $template['body'], 'enabled' => (bool) $template['enabled'],
        ], $templates));
    }

    public static function find(?array $configuration, string $id): ?array
    {
        return collect(self::templates($configuration))->firstWhere('id', $id);
    }

    /**
     * Every {token} in the text, in order and with repeats (unknown ones included).
     *
     * @return list<string>
     */
    public static function fields(?string $text): array
    {
        preg_match_all('/\{([^{}]*)\}/u', (string) $text, $matches);

        return $matches[1];
    }

    /** Braces are only for merge fields: a stray "{" or "}" outside a {field} is a mistake. */
    public static function strayBraces(?string $text): bool
    {
        $rest = preg_replace('/\{[^{}]*\}/u', '', (string) $text);

        return str_contains($rest, '{') || str_contains($rest, '}');
    }

    /** Replace merge fields with their values. Braces in values are removed so a value can never add a field. */
    public static function render(?string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', function (array $match) use ($values): string {
            if (! array_key_exists($match[1], $values)) {
                return $match[0];
            }

            return str_replace(['{', '}'], '', (string) $values[$match[1]]);
        }, (string) $text);
    }

    /** How many parts a text message takes (GSM-7, or UCS-2 when any other character is used). */
    public static function smsParts(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $units = 0;
        $gsm = true;
        foreach (mb_str_split($text) as $character) {
            if (mb_strpos(self::GSM, $character) !== false) {
                $units++;
            } elseif (mb_strpos(self::GSM_EXTENSION, $character) !== false) {
                $units += 2;
            } else {
                $gsm = false;

                break;
            }
        }
        if ($gsm) {
            return $units <= 160 ? 1 : (int) ceil($units / 153);
        }
        $units = intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);

        return $units <= 70 ? 1 : (int) ceil($units / 67);
    }

    /** The longest values a template is checked with: typical names, the real provider details and a full secure link. */
    public static function allowanceValues(array $configuration): array
    {
        return [
            'first_name' => str_repeat('x', self::ALLOWANCES['first_name']),
            'reference' => str_repeat('X', self::ALLOWANCES['reference']),
            'coordinator_name' => str_repeat('x', self::ALLOWANCES['coordinator_name']),
            'provider_name' => (string) ($configuration['public']['provider_name'] ?? ''),
            'contact_phone' => (string) ($configuration['public']['contact_phone'] ?? ''),
            'response_hours' => (string) ($configuration['public']['response_hours'] ?? ''),
            'document_link' => rtrim((string) config('app.url'), '/').self::LINK_PATH.str_repeat('x', 64),
        ];
    }

    /** Synthetic details for previews. Never a real person. */
    public static function sampleValues(array $configuration): array
    {
        return [
            'first_name' => 'Aroha',
            'reference' => '01J0SAMPLE00REFERENCE00000',
            'coordinator_name' => 'Sam Taylor',
            'provider_name' => (string) ($configuration['public']['provider_name'] ?? ''),
            'contact_phone' => filled($configuration['public']['contact_phone'] ?? null) ? $configuration['public']['contact_phone'] : '09 000 0000',
            'response_hours' => (string) ($configuration['public']['response_hours'] ?? ''),
            'document_link' => rtrim((string) config('app.url'), '/').self::LINK_PATH.'(secure link)',
        ];
    }

    /** Problems with one template's wording, in plain words. Null when it can be used. */
    public static function problem(array $template, array $configuration): ?string
    {
        foreach (['subject', 'body'] as $part) {
            $text = $template[$part] ?? '';
            if (self::strayBraces($text)) {
                return 'Use braces only around a merge field, like {first_name}.';
            }
            $unknown = array_values(array_diff(self::fields($text), self::FIELDS));
            if ($unknown !== []) {
                return 'Remove {'.$unknown[0].'}. Only the listed merge fields can be used, so no health or funding details are ever added.';
            }
        }
        if (trim($template['body']) === '') {
            return 'Write the message.';
        }
        if ($template['channel'] === 'email') {
            if (trim((string) ($template['subject'] ?? '')) === '') {
                return 'Give the email a subject.';
            }
            if (in_array('document_link', self::fields($template['subject']), true)) {
                return 'Put the secure link in the message, not the subject.';
            }

            return null;
        }
        if (filled($template['subject'] ?? null)) {
            return 'Text messages have no subject.';
        }
        if (self::smsParts(self::render($template['body'], self::allowanceValues($configuration))) > self::SMS_MAX_PARTS) {
            return 'Shorten this text message. With typical details it is longer than '.self::SMS_MAX_PARTS.' text parts.';
        }

        return null;
    }

    /** Settings validation after the field rules: wording, names and text length. */
    public static function validateConfiguration(array $configuration): void
    {
        if (! isset($configuration['communications'])) {
            return;
        }
        $templates = $configuration['communications']['templates'];
        $labels = array_map(fn (array $template) => mb_strtolower(trim($template['label'])), $templates);
        if (count($labels) !== count(array_unique($labels))) {
            throw ValidationException::withMessages(['configuration.communications.templates' => 'Give each message template its own name.']);
        }
        foreach ($templates as $index => $template) {
            if ($message = self::problem($template, $configuration)) {
                throw ValidationException::withMessages(['configuration.communications.templates.'.$index => '“'.$template['label'].'”: '.$message]);
            }
        }
    }

    /**
     * A published template keeps its reference, channel and recipient. Wording and names can change;
     * a template that is no longer wanted is turned off.
     */
    public static function stableReferences(array $before, array $after): void
    {
        $current = array_column(self::templates($after), null, 'id');
        foreach (self::templates($before) as $template) {
            $next = $current[$template['id']] ?? null;
            if (! $next) {
                throw ValidationException::withMessages(['configuration.communications.templates' => 'Keep “'.$template['label'].'”. A published message template can’t be removed; turn it off instead.']);
            }
            if ($next['channel'] !== $template['channel'] || $next['audience'] !== $template['audience']) {
                throw ValidationException::withMessages(['configuration.communications.templates' => 'Keep how “'.$template['label'].'” is sent and who it goes to. Add a new template for a different channel or recipient.']);
            }
        }
    }

    /** Restoring an earlier version keeps every template published since, turned off. */
    public static function restorable(array $current, array $restored): array
    {
        $templates = self::templates($restored);
        $known = array_column($templates, 'id');
        foreach (self::templates($current) as $template) {
            if (! in_array($template['id'], $known, true)) {
                $templates[] = [...$template, 'enabled' => false];
            }
        }

        return ['templates' => $templates];
    }

    /**
     * Plain-language change lines for the settings impact preview.
     *
     * @return list<string>
     */
    public static function changes(array $before, array $after): array
    {
        $old = array_column(self::templates($before), null, 'id');
        $notes = [];
        foreach (self::templates($after) as $template) {
            $was = $old[$template['id']] ?? null;
            $name = '“'.$template['label'].'”';
            if (! $was) {
                $notes[] = $name.' added'.($template['enabled'] ? '' : ', turned off');

                continue;
            }
            if ($was['enabled'] !== $template['enabled']) {
                $notes[] = $name.' turned '.($template['enabled'] ? 'on' : 'off');
            }
            if ($was['label'] !== $template['label']) {
                $notes[] = '“'.$was['label'].'” renamed '.$name;
            }
            if ($was['subject'] !== $template['subject'] || $was['body'] !== $template['body']) {
                $notes[] = $name.' reworded';
            }
        }

        return $notes;
    }
}
