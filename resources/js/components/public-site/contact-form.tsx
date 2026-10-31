// Contact us form for the public website (mockup v2, approved 23 Oct 2026).
// A message becomes a general enquiry in referral intake: its own queue, not
// counted as a referral, with an ENQ- reference (Stephan, 20–21 Oct 2026).
// The page around it (office details, photo) belongs to the Contact page.
import InputError from '@/components/input-error';
import { enquiryUrl } from '@/components/public-provider';
import {
    EmergencyNote,
    RadioTiles,
    type TileOption,
} from '@/components/public-site/primitives';
import { siteLinks } from '@/components/public-site/site';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { ArrowRight, LockKeyhole, Send } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';

type Channel = 'phone' | 'text' | 'email';
type Topic = 'support' | 'referral' | 'careers' | 'other';
type Fields = {
    name: string;
    contact: string;
    channel: Channel | null;
    topic: Topic | null;
    message: string;
    leave_message: boolean;
};
type FieldKey = keyof Fields;
type Errors = Partial<Record<FieldKey, string>>;
type Payload = Fields & { submission_token: string; website: string };

const channels: readonly TileOption<Channel>[] = [
    { value: 'phone', title: 'Phone call', icon: 'PhoneCall' },
    { value: 'text', title: 'Text', icon: 'MessageSquareText' },
    { value: 'email', title: 'Email', icon: 'Mail' },
];

const topics: readonly TileOption<Topic>[] = [
    {
        value: 'support',
        title: 'Ask about support',
        body: 'For yourself or someone in your whānau',
        icon: 'HeartHandshake',
    },
    {
        value: 'referral',
        title: 'Make a referral',
        body: 'For a client, patient or family member',
        icon: 'Stethoscope',
    },
    {
        value: 'careers',
        title: 'A careers question',
        body: 'About working with us',
        icon: 'Briefcase',
    },
    {
        value: 'other',
        title: 'Something else',
        body: 'Feedback, a supplier, or anything else',
        icon: 'MessageSquareText',
    },
];

/** The tiles that have a better place to go say so, without blocking. */
const suggestions: Partial<
    Record<Topic, { text: string; href: string; action: string }>
> = {
    support: {
        text: 'Our referral form asks the right questions and gives you a reference straight away.',
        href: enquiryUrl('support'),
        action: 'Ask about support',
    },
    referral: {
        text: 'The referral form gets the right details to our intake team first time, and gives you a reference.',
        href: enquiryUrl('referral'),
        action: 'Make a referral',
    },
    careers: {
        text: 'You can see our open roles and apply online.',
        href: siteLinks.careers,
        action: 'See open roles',
    },
};

const EMPTY: Fields = {
    name: '',
    contact: '',
    channel: null,
    topic: null,
    message: '',
    leave_message: true,
};
const FIELD_ORDER: FieldKey[] = [
    'name',
    'contact',
    'channel',
    'topic',
    'message',
    'leave_message',
];
const MESSAGE_MAX = 2000;

const isEmail = (value: string) => /^\S+@\S+\.\S+$/.test(value.trim());
const isPhone = (value: string) =>
    /^\d{7,15}$/.test(value.replace(/[\s().+-]/g, ''));

/** An idempotency key for one message; also works where crypto.randomUUID isn't available (plain http). */
export function newSubmissionToken(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** The same checks the server makes, so most mistakes show before sending. */
export function checkContactForm(fields: Fields): Errors {
    const errors: Errors = {};
    const contact = fields.contact.trim();
    if (!fields.name.trim()) errors.name = 'Enter your name.';
    if (!contact) errors.contact = 'Enter an email address or phone number.';
    else if (contact.includes('@') ? !isEmail(contact) : !isPhone(contact))
        errors.contact =
            'Check the email address or phone number. We need it to reply.';
    else if (
        contact.includes('@') &&
        fields.channel &&
        fields.channel !== 'email'
    )
        errors.channel = `To get a ${fields.channel === 'text' ? 'text' : 'call'}, give us a phone number instead of an email address.`;
    else if (!contact.includes('@') && fields.channel === 'email')
        errors.channel =
            'To get an email, give us an email address instead of a phone number.';
    const message = fields.message.trim();
    if (!message) errors.message = 'Write a short message.';
    else if (message.length < 5)
        errors.message = 'Write a little more so we know how to help.';
    else if (message.length > MESSAGE_MAX)
        errors.message = 'Keep your message under 2,000 characters.';
    return errors;
}

const replyWay = (fields: Fields) =>
    fields.channel === 'email'
        ? 'email'
        : fields.channel === 'text'
          ? 'text'
          : fields.channel === 'phone'
            ? 'phone'
            : 'the contact details you gave us';

/**
 * The Contact us form. Sends to `POST /contact`; the reply is a minimal receipt
 * (an ENQ- reference), never the message back.
 */
export function ContactForm({
    providerName,
    className,
}: {
    /** The provider's own name (Settings › Branding or Referrals); null reads neutrally. */
    providerName: string | null;
    className?: string;
}) {
    const [fields, setFields] = useState<Fields>(EMPTY);
    const [website, setWebsite] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [failure, setFailure] = useState<{
        text: string;
        /** An earlier version under the same key already reached us. */
        conflict?: boolean;
    } | null>(null);
    const [sending, setSending] = useState(false);
    const [receipt, setReceipt] = useState<string | null>(null);
    const [sent, setSent] = useState<Fields | null>(null);
    const token = useRef(newSubmissionToken());
    // After an unconfirmed send, a retry repeats exactly what was sent, so it is never saved twice.
    const attempted = useRef<Payload | null>(null);
    const result = useRef<HTMLDivElement>(null);
    const suggestion = fields.topic ? suggestions[fields.topic] : undefined;

    const set = <K extends FieldKey>(key: K, value: Fields[K]) => {
        setFields((previous) => ({ ...previous, [key]: value }));
        // The reply channel is checked against the contact, so changing either clears both.
        setErrors((previous) => ({
            ...previous,
            [key]: undefined,
            ...(key === 'contact' ? { channel: undefined } : {}),
        }));
        attempted.current = null;
    };
    const focusFirst = (found: Errors) => {
        const first = FIELD_ORDER.find((key) => found[key]);
        if (first)
            window.setTimeout(
                () => document.getElementById(`contact-${first}`)?.focus(),
                0,
            );
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (sending) return;
        const found = checkContactForm(fields);
        setErrors(found);
        setFailure(null);
        if (Object.keys(found).length) {
            focusFirst(found);
            return;
        }
        const payload: Payload = attempted.current ?? {
            ...fields,
            name: fields.name.trim(),
            contact: fields.contact.trim(),
            message: fields.message.trim(),
            submission_token: token.current,
            website,
        };
        attempted.current = payload;
        setSending(true);
        try {
            const response = await axios.post<{
                reference: string;
                status: string;
            }>('/contact', payload, {
                headers: { Accept: 'application/json' },
            });
            if (response.data.status !== 'received' || !response.data.reference)
                throw new Error('Receipt unavailable');
            attempted.current = null;
            setSent(fields);
            setReceipt(response.data.reference);
            window.setTimeout(() => result.current?.focus(), 0);
        } catch (error) {
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            const data = axios.isAxiosError(error)
                ? (error.response?.data as
                      | { errors?: Record<string, string[]>; message?: string }
                      | undefined)
                : undefined;
            if (status === 422 && data?.errors) {
                attempted.current = null;
                const mapped: Errors = {};
                const other: string[] = [];
                for (const [key, messages] of Object.entries(data.errors)) {
                    const field = key.replace(/^form\./, '') as FieldKey;
                    if (FIELD_ORDER.includes(field))
                        mapped[field] = messages[0];
                    else other.push(messages[0]);
                }
                setErrors(mapped);
                if (other.length) setFailure({ text: other.join(' ') });
                focusFirst(mapped);
            } else if (status === 409) {
                setFailure({
                    text: 'We already have an earlier version of this message. If you want to add something, send it as a new message.',
                    conflict: true,
                });
            } else {
                setFailure({
                    text:
                        status === 429
                            ? 'Please wait a minute before trying again. Your message is still here.'
                            : status === 503 && data?.message
                              ? data.message
                              : status === 413
                                ? 'Please shorten your message before sending it.'
                                : 'We couldn’t confirm your message was sent. It’s still here: try again, and it won’t be sent twice.',
                });
            }
        } finally {
            setSending(false);
        }
    };

    const startAgain = () => {
        token.current = newSubmissionToken();
        attempted.current = null;
        setFields(EMPTY);
        setErrors({});
        setFailure(null);
        setReceipt(null);
        setSent(null);
    };
    const sendAsNew = () => {
        token.current = newSubmissionToken();
        attempted.current = null;
        setFailure(null);
    };

    const described = (key: FieldKey, hint?: string) =>
        [hint, errors[key] && `contact-${key}-error`]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <section
            aria-labelledby="contact-form-heading"
            className={cn(
                'rounded-3xl border border-border bg-card p-6 md:p-8',
                className,
            )}
        >
            {receipt && sent ? (
                <div
                    ref={result}
                    tabIndex={-1}
                    role="status"
                    className="flex flex-col items-start gap-5 outline-none"
                >
                    {/* Signature: success is the brand ring closing. */}
                    <span className="journey-site-ring-done" aria-hidden />
                    <h2
                        id="contact-form-heading"
                        className="journey-site-section-title"
                    >
                        Thanks — our intake team has your message
                    </h2>
                    <p className="max-w-xl text-muted-foreground">
                        We reply within two working days, by {replyWay(sent)}.
                        {sent.leave_message
                            ? ''
                            : ' We won’t leave a message if we can’t reach you.'}
                    </p>
                    <div className="w-full rounded-2xl bg-muted p-5">
                        <p className="text-sm text-muted-foreground">
                            Your reference
                        </p>
                        <p className="text-page-title">{receipt}</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Keep it if you call us. It isn’t a login.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        onClick={startAgain}
                    >
                        Send another message
                    </Button>
                </div>
            ) : (
                <form onSubmit={submit} noValidate className="space-y-7">
                    <h2
                        id="contact-form-heading"
                        className="journey-site-section-title"
                    >
                        Send us a message
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="contact-name">Your name</Label>
                            <Input
                                id="contact-name"
                                autoComplete="name"
                                maxLength={160}
                                className="frontline-tap"
                                value={fields.name}
                                onChange={(event) =>
                                    set('name', event.target.value)
                                }
                                aria-invalid={errors.name ? true : undefined}
                                aria-describedby={described('name')}
                            />
                            <InputError
                                id="contact-name-error"
                                message={errors.name}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="contact-contact">
                                Email or phone
                            </Label>
                            <Input
                                id="contact-contact"
                                autoComplete="email"
                                maxLength={254}
                                className="frontline-tap"
                                value={fields.contact}
                                onChange={(event) =>
                                    set('contact', event.target.value)
                                }
                                aria-invalid={errors.contact ? true : undefined}
                                aria-describedby={described('contact')}
                            />
                            <InputError
                                id="contact-contact-error"
                                message={errors.contact}
                            />
                        </div>
                    </div>

                    <RadioTiles
                        id="contact-channel"
                        label="How should we reply?"
                        value={fields.channel}
                        onChange={(value) => set('channel', value)}
                        columns="sm:grid-cols-3"
                        options={channels}
                        error={errors.channel}
                    />

                    <div className="space-y-3">
                        <RadioTiles
                            id="contact-topic"
                            label="What’s it about?"
                            value={fields.topic}
                            onChange={(value) => set('topic', value)}
                            options={topics}
                            error={errors.topic}
                        />
                        {suggestion && (
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-muted p-4">
                                <p className="max-w-md text-sm">
                                    {suggestion.text} Or keep writing here —
                                    that’s fine too.
                                </p>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="frontline-tap"
                                >
                                    <Link href={suggestion.href}>
                                        {suggestion.action}
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="contact-message">Your message</Label>
                        <Textarea
                            id="contact-message"
                            rows={5}
                            maxLength={MESSAGE_MAX}
                            value={fields.message}
                            onChange={(event) =>
                                set('message', event.target.value)
                            }
                            aria-invalid={errors.message ? true : undefined}
                            aria-describedby={described(
                                'message',
                                'contact-message-hint',
                            )}
                        />
                        <p
                            id="contact-message-hint"
                            className="text-caption text-muted-foreground"
                        >
                            Please don’t include health details here. We’ll ask
                            for what we need when we reply.
                        </p>
                        <InputError
                            id="contact-message-error"
                            message={errors.message}
                        />
                    </div>

                    <div className="flex items-center justify-between gap-4 rounded-2xl bg-muted p-4">
                        <span>
                            <Label
                                htmlFor="contact-leave_message"
                                className="block font-medium"
                            >
                                Safe to leave a message
                            </Label>
                            <span
                                id="contact-leave_message-hint"
                                className="block text-sm text-muted-foreground"
                            >
                                If we miss you, we’ll only say who we are and
                                ask you to call back.
                            </span>
                        </span>
                        <span className="flex items-center gap-2 text-sm font-medium">
                            <span aria-hidden>
                                {fields.leave_message ? 'Yes' : 'No'}
                            </span>
                            <Switch
                                id="contact-leave_message"
                                checked={fields.leave_message}
                                aria-describedby="contact-leave_message-hint"
                                onCheckedChange={(value) =>
                                    set('leave_message', value)
                                }
                            />
                        </span>
                    </div>

                    {/* Left empty by people; automated senders fill it in. */}
                    <div className="hidden" aria-hidden="true">
                        <Input
                            name="website"
                            tabIndex={-1}
                            autoComplete="off"
                            value={website}
                            onChange={(event) => setWebsite(event.target.value)}
                        />
                    </div>

                    <div className="space-y-4 border-t border-border pt-6">
                        <p className="flex items-start gap-2.5 text-sm text-muted-foreground">
                            <LockKeyhole
                                className="mt-0.5 size-4 shrink-0 text-primary"
                                aria-hidden
                            />
                            <span>
                                We use your details only to reply to you. Your
                                message goes to{' '}
                                {providerName
                                    ? `the ${providerName} intake team`
                                    : 'our intake team'}{' '}
                                and isn’t shared outside it. Read our{' '}
                                <Link
                                    href={siteLinks.privacy}
                                    className="font-semibold text-primary underline-offset-2 hover:underline"
                                >
                                    privacy statement
                                </Link>
                                .
                            </span>
                        </p>
                        <EmergencyNote />
                        {failure && (
                            <Alert variant="critical">
                                <AlertDescription>
                                    {failure.text}
                                    {failure.conflict && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="frontline-tap mt-3"
                                            onClick={sendAsNew}
                                        >
                                            Send as a new message
                                        </Button>
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                size="lg"
                                className="frontline-tap"
                                disabled={sending}
                                aria-busy={sending || undefined}
                            >
                                <Send className="size-4" aria-hidden />
                                {sending ? 'Sending…' : 'Send message'}
                            </Button>
                        </div>
                    </div>
                </form>
            )}
        </section>
    );
}
