/* Staff message templates: the safe merge fields, rendering and text-length
 * rules shared by Settings › Referrals (Workflow › Delivery & channels) and the profile's
 * "Send a message" dialog. Mirrors app/Domain/Referrals/Services/
 * ReferralMessageTemplates.php; the server re-checks everything. */

export const MESSAGE_FIELDS = [
    'first_name',
    'reference',
    'provider_name',
    'coordinator_name',
    'contact_phone',
    'response_hours',
    'document_link',
] as const;
export type MessageField = (typeof MESSAGE_FIELDS)[number];
export type MessageChannel = 'email' | 'sms';
/** The person (self-referral), the referrer, or someone else in People involved. */
export type MessageAudience = 'person' | 'referrer' | 'contact';

export type MessageTemplate = {
    id: string;
    label: string;
    channel: MessageChannel;
    audience: MessageAudience;
    subject: string | null;
    body: string;
    enabled: boolean;
};

export const messageFieldInfo: Record<
    MessageField,
    { label: string; hint: string }
> = {
    first_name: {
        label: 'First name',
        hint: 'The first name of the person receiving the message',
    },
    reference: { label: 'Reference', hint: 'The referral reference' },
    provider_name: {
        label: 'Provider name',
        hint: 'Your organisation’s name from the website settings',
    },
    coordinator_name: {
        label: 'Coordinator',
        hint: 'The referral’s accountable owner',
    },
    contact_phone: {
        label: 'Your phone number',
        hint: 'The phone number from the website settings',
    },
    response_hours: {
        label: 'Response hours',
        hint: 'When people can expect to reach you',
    },
    document_link: {
        label: 'Secure upload link',
        hint: 'The link of an open document request. The access code is never included.',
    },
};

export const channelLabels: Record<MessageChannel, string> = {
    email: 'Email',
    sms: 'Text message',
};
export const audienceLabels: Record<MessageAudience, string> = {
    person: 'The person',
    referrer: 'The referrer',
    contact: 'Someone involved',
};
export const audienceHints: Record<MessageAudience, string> = {
    person: 'When they contacted us themselves',
    referrer:
        'Whoever made the referral, or a reviewed contact recorded as the referrer',
    contact:
        'A family member, supporter, advocate or professional in People involved',
};

/** The standard templates. They start switched off until someone reviews them. */
export const STANDARD_TEMPLATE_IDS = [
    'first_contact',
    'tried_to_call',
    'documents_needed',
    'appointment_reminder',
    'please_call_us',
];

/** A text may run to three parts (459 plain characters, or 201 with macrons or curly quotes). */
export const SMS_MAX_PARTS = 3;
export const EMAIL_SUBJECT_MAX = 150;
export const EMAIL_BODY_MAX = 4000;
/** Typical longest values used to check a text template's length. */
const ALLOWANCES = { first_name: 20, reference: 26, coordinator_name: 30 };

const GSM =
    '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
const GSM_EXTENSION = '^{}\\[~]|€';

/** Every {token} in the text, in order and with repeats (unknown ones included). */
export function templateFields(text: string | null | undefined): string[] {
    return [...(text ?? '').matchAll(/\{([^{}]*)\}/g)].map((match) => match[1]);
}

export function unknownFields(text: string | null | undefined): string[] {
    return templateFields(text).filter(
        (field) => !(MESSAGE_FIELDS as readonly string[]).includes(field),
    );
}

/** Braces are only for merge fields: a stray "{" or "}" is a mistake. */
export function strayBraces(text: string | null | undefined): boolean {
    const rest = (text ?? '').replace(/\{[^{}]*\}/g, '');
    return rest.includes('{') || rest.includes('}');
}

/** Replace merge fields; braces inside values are removed so a value never adds a field. */
export function renderTemplate(
    text: string | null | undefined,
    values: Partial<Record<MessageField, string | null>>,
): string {
    return (text ?? '').replace(/\{([a-z_]+)\}/g, (token, field: string) =>
        field in values && values[field as MessageField] != null
            ? String(values[field as MessageField]).replace(/[{}]/g, '')
            : token,
    );
}

/** How many parts a text takes: GSM-7 (160, then 153 a part) or UCS-2 (70, then 67). */
export function smsParts(text: string): number {
    if (!text) return 0;
    let units = 0;
    for (const character of text) {
        if (GSM.includes(character)) units += 1;
        else if (GSM_EXTENSION.includes(character)) units += 2;
        else {
            const wide = text.length; // UTF-16 code units
            return wide <= 70 ? 1 : Math.ceil(wide / 67);
        }
    }
    return units <= 160 ? 1 : Math.ceil(units / 153);
}

/** True when the text uses a character that halves each text part (macrons, curly quotes, emoji). */
export function smsWide(text: string): boolean {
    for (const character of text)
        if (!GSM.includes(character) && !GSM_EXTENSION.includes(character))
            return true;
    return false;
}

type ProviderDetails = {
    provider_name: string;
    contact_phone: string | null;
    response_hours: string;
};

/** The longest values a text template is checked with (the server uses the same). */
export function allowanceValues(
    provider: ProviderDetails,
    linkLength: number,
): Record<MessageField, string> {
    return {
        first_name: 'x'.repeat(ALLOWANCES.first_name),
        reference: 'X'.repeat(ALLOWANCES.reference),
        coordinator_name: 'x'.repeat(ALLOWANCES.coordinator_name),
        provider_name: provider.provider_name,
        contact_phone: provider.contact_phone ?? '',
        response_hours: provider.response_hours,
        document_link: 'x'.repeat(linkLength),
    };
}

/** Synthetic details for previews. Never a real person. */
export function sampleValues(
    provider: ProviderDetails,
): Record<MessageField, string> {
    return {
        first_name: 'Aroha',
        reference: '01J0SAMPLE00REFERENCE00000',
        coordinator_name: 'Sam Taylor',
        provider_name: provider.provider_name,
        contact_phone: provider.contact_phone || '09 000 0000',
        response_hours: provider.response_hours,
        document_link: '[secure upload link]',
    };
}

/** Problems with a template's wording, in plain words; null when it can be used. */
export function templateProblem(
    template: Pick<MessageTemplate, 'channel' | 'subject' | 'body'>,
    provider: ProviderDetails,
    linkLength: number,
): string | null {
    for (const text of [template.subject, template.body]) {
        if (strayBraces(text))
            return 'Use braces only around a merge field, like {first_name}.';
        const unknown = unknownFields(text);
        if (unknown.length)
            return `Remove {${unknown[0]}}. Only the listed merge fields can be used, so no health or funding details are ever added.`;
    }
    if (!template.body.trim()) return 'Write the message.';
    if (template.channel === 'email') {
        if (!(template.subject ?? '').trim())
            return 'Give the email a subject.';
        if (templateFields(template.subject).includes('document_link'))
            return 'Put the secure link in the message, not the subject.';
        return null;
    }
    if (
        smsParts(
            renderTemplate(
                template.body,
                allowanceValues(provider, linkLength),
            ),
        ) > SMS_MAX_PARTS
    )
        return `Shorten this text message. With typical details it is longer than ${SMS_MAX_PARTS} text parts.`;
    return null;
}

/** Same merge fields, same number of times: staff edits can't add, drop or change one. */
export function sameFields(template: string, edited: string): boolean {
    const a = templateFields(template).sort();
    const b = templateFields(edited).sort();
    return (
        a.length === b.length && a.every((field, index) => field === b[index])
    );
}
