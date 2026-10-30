import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    ArrowRight,
    Clock,
    LockKeyhole,
    Mail,
    MapPin,
    Phone,
    Send,
} from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { photos, provider } from './data';
import {
    EmergencyNote,
    Lens,
    PageIntro,
    Photo,
    RadioTiles,
    SitePage,
    useSiteName,
} from './site';

const channels = [
    { value: 'phone', title: 'Phone call', icon: 'PhoneCall' },
    { value: 'text', title: 'Text', icon: 'MessageSquareText' },
    { value: 'email', title: 'Email', icon: 'Mail' },
] as const;

const topics = [
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
] as const;
type Topic = (typeof topics)[number]['value'];

/** The tiles that have a better place to go say so, without blocking. */
const suggestions: Partial<
    Record<Topic, { text: string; href: string; action: string }>
> = {
    support: {
        text: 'Our referral form asks the right questions and gives you a reference straight away.',
        href: '#/referral/support',
        action: 'Ask about support',
    },
    referral: {
        text: 'The referral form gets the right details to our intake team first time, and gives you a reference.',
        href: '#/referral/referral',
        action: 'Make a referral',
    },
    careers: {
        text: 'You can see our open roles and apply online.',
        href: '#/careers',
        action: 'See open roles',
    },
};

const looksLikeContact = (value: string) =>
    /^\S+@\S+\.\S+$/.test(value.trim()) ||
    value.replace(/[\s()+-]/g, '').match(/^\d{7,}$/) !== null;

type Form = {
    name: string;
    contact: string;
    channel: string | null;
    topic: Topic | null;
    message: string;
    leaveMessage: boolean;
};
const empty: Form = {
    name: '',
    contact: '',
    channel: null,
    topic: null,
    message: '',
    leaveMessage: true,
};

export function ContactPage() {
    const name = useSiteName();
    const [form, setForm] = useState<Form>(empty);
    const [errors, setErrors] = useState<Partial<Record<keyof Form, string>>>(
        {},
    );
    const [state, setState] = useState<'editing' | 'sending' | 'sent'>(
        'editing',
    );
    const result = useRef<HTMLDivElement>(null);
    const set = <K extends keyof Form>(key: K, value: Form[K]) => {
        setForm((previous) => ({ ...previous, [key]: value }));
        setErrors((previous) => ({ ...previous, [key]: undefined }));
    };
    const suggestion = form.topic ? suggestions[form.topic] : undefined;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const next: Partial<Record<keyof Form, string>> = {};
        if (!form.name.trim()) next.name = 'Enter your name.';
        if (!form.contact.trim())
            next.contact = 'Enter an email address or phone number.';
        else if (!looksLikeContact(form.contact))
            next.contact =
                'Check the email address or phone number. We need it to reply.';
        if (!form.message.trim()) next.message = 'Write a short message.';
        setErrors(next);
        const first = Object.keys(next)[0];
        if (first) {
            document.getElementById(`contact-${first}`)?.focus();
            return;
        }
        setState('sending');
        window.setTimeout(() => {
            setState('sent');
            window.setTimeout(() => result.current?.focus(), 0);
        }, 900);
    };

    return (
        <SitePage
            current="contact"
            intro={
                <PageIntro
                    eyebrow="Contact us"
                    title="Get in touch"
                    lede="Send us a message and our intake team will reply. If you’d rather talk, call us during office hours."
                />
            }
        >
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1.6fr_1fr]">
                <section
                    aria-labelledby="contact-form-heading"
                    className="rounded-3xl border border-border bg-card p-6 md:p-8"
                >
                    {state === 'sent' ? (
                        <div
                            ref={result}
                            tabIndex={-1}
                            role="status"
                            className="flex flex-col items-start gap-5 outline-none"
                        >
                            {/* Signature: success is the brand ring closing. */}
                            <span
                                className="journey-site-ring-done"
                                aria-hidden
                            />
                            <h2
                                id="contact-form-heading"
                                className="journey-site-section-title"
                            >
                                Thanks — our intake team has your message
                            </h2>
                            <p className="max-w-xl text-muted-foreground">
                                We reply within two working days, by{' '}
                                {form.channel === 'email'
                                    ? 'email'
                                    : form.channel === 'text'
                                      ? 'text'
                                      : form.channel === 'phone'
                                        ? 'phone'
                                        : 'the contact details you gave us'}
                                .
                                {form.leaveMessage
                                    ? ''
                                    : ' We won’t leave a message if we can’t reach you.'}
                            </p>
                            <div className="w-full rounded-2xl bg-muted p-5">
                                <p className="text-sm text-muted-foreground">
                                    Your reference
                                </p>
                                <p className="text-page-title">ENQ-2048</p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Keep it if you call us. It isn’t a login.
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setForm(empty);
                                    setState('editing');
                                }}
                            >
                                Send another message
                            </Button>
                        </div>
                    ) : (
                        <form
                            onSubmit={submit}
                            noValidate
                            className="space-y-7"
                        >
                            <h2
                                id="contact-form-heading"
                                className="journey-site-section-title"
                            >
                                Send us a message
                            </h2>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="contact-name">
                                        Your name
                                    </Label>
                                    <Input
                                        id="contact-name"
                                        autoComplete="name"
                                        value={form.name}
                                        onChange={(event) =>
                                            set('name', event.target.value)
                                        }
                                        aria-invalid={
                                            errors.name ? true : undefined
                                        }
                                        aria-describedby={
                                            errors.name
                                                ? 'contact-name-error'
                                                : undefined
                                        }
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
                                        value={form.contact}
                                        onChange={(event) =>
                                            set('contact', event.target.value)
                                        }
                                        aria-invalid={
                                            errors.contact ? true : undefined
                                        }
                                        aria-describedby={
                                            errors.contact
                                                ? 'contact-contact-error'
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        id="contact-contact-error"
                                        message={errors.contact}
                                    />
                                </div>
                            </div>

                            <RadioTiles
                                label="How should we reply?"
                                value={form.channel}
                                onChange={(value) => set('channel', value)}
                                columns="sm:grid-cols-3"
                                options={channels}
                            />

                            <div className="space-y-3">
                                <RadioTiles
                                    label="What’s it about?"
                                    value={form.topic}
                                    onChange={(value) => set('topic', value)}
                                    options={topics}
                                />
                                {suggestion && (
                                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-muted p-4">
                                        <p className="max-w-md text-sm">
                                            {suggestion.text} Or keep writing
                                            here — that’s fine too.
                                        </p>
                                        <Button asChild variant="outline">
                                            <a href={suggestion.href}>
                                                {suggestion.action}
                                                <ArrowRight
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                            </a>
                                        </Button>
                                    </div>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="contact-message">
                                    Your message
                                </Label>
                                <Textarea
                                    id="contact-message"
                                    rows={5}
                                    value={form.message}
                                    onChange={(event) =>
                                        set('message', event.target.value)
                                    }
                                    aria-invalid={
                                        errors.message ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.message
                                            ? 'contact-message-error'
                                            : 'contact-message-hint'
                                    }
                                />
                                <p
                                    id="contact-message-hint"
                                    className="text-caption text-muted-foreground"
                                >
                                    Please don’t include health details here.
                                    We’ll ask for what we need when we reply.
                                </p>
                                <InputError
                                    id="contact-message-error"
                                    message={errors.message}
                                />
                            </div>

                            <div className="flex items-center justify-between gap-4 rounded-2xl bg-muted p-4">
                                <span>
                                    <Label
                                        htmlFor="contact-leave-message"
                                        className="block font-medium"
                                    >
                                        Safe to leave a message
                                    </Label>
                                    <span className="block text-sm text-muted-foreground">
                                        If we miss you, we’ll only say who we
                                        are and ask you to call back.
                                    </span>
                                </span>
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    {form.leaveMessage ? 'Yes' : 'No'}
                                    <Switch
                                        id="contact-leave-message"
                                        checked={form.leaveMessage}
                                        onCheckedChange={(value) =>
                                            set('leaveMessage', value)
                                        }
                                    />
                                </span>
                            </div>

                            <div className="space-y-4 border-t border-border pt-6">
                                <p className="flex items-start gap-2.5 text-sm text-muted-foreground">
                                    <LockKeyhole
                                        className="mt-0.5 size-4 shrink-0 text-primary"
                                        aria-hidden
                                    />
                                    <span>
                                        We use your details only to reply to
                                        you. Your message goes to the {name}{' '}
                                        intake team and isn’t shared outside it.
                                        Read our{' '}
                                        <a
                                            href="#/privacy"
                                            className="font-semibold text-primary underline-offset-2 hover:underline"
                                        >
                                            privacy statement
                                        </a>
                                        .
                                    </span>
                                </p>
                                <EmergencyNote />
                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        size="lg"
                                        disabled={state === 'sending'}
                                    >
                                        {state === 'sending' ? (
                                            <Spinner aria-hidden />
                                        ) : (
                                            <Send
                                                className="size-4"
                                                aria-hidden
                                            />
                                        )}
                                        {state === 'sending'
                                            ? 'Sending…'
                                            : 'Send message'}
                                    </Button>
                                </div>
                            </div>
                        </form>
                    )}
                </section>

                <aside
                    aria-labelledby="office-heading"
                    className="overflow-hidden rounded-3xl border border-border bg-card"
                >
                    <Photo
                        photo={photos.office}
                        className="aspect-[4/3] rounded-none"
                    />
                    <div className="space-y-5 p-6">
                        <h2 id="office-heading" className="text-section-title">
                            Our office
                        </h2>
                        <ul className="space-y-4 text-sm">
                            <li className="flex items-start gap-3">
                                <Lens icon={MapPin} />
                                <span>
                                    <span className="block font-semibold">
                                        Visit us
                                    </span>
                                    {provider.address.join(', ')}
                                    <span className="block text-muted-foreground">
                                        Step-free entrance and parking at the
                                        back.
                                    </span>
                                </span>
                            </li>
                            <li className="flex items-start gap-3">
                                <Lens icon={Phone} />
                                <span>
                                    <span className="block font-semibold">
                                        Call us
                                    </span>
                                    <a
                                        href={provider.phoneHref}
                                        className="font-semibold text-primary hover:underline"
                                    >
                                        {provider.phone}
                                    </a>
                                    <span className="block text-muted-foreground">
                                        Free from any phone
                                    </span>
                                </span>
                            </li>
                            <li className="flex items-start gap-3">
                                <Lens icon={Mail} />
                                <span>
                                    <span className="block font-semibold">
                                        Email us
                                    </span>
                                    <a
                                        href={`mailto:${provider.email}`}
                                        className="break-all text-primary hover:underline"
                                    >
                                        {provider.email}
                                    </a>
                                </span>
                            </li>
                            <li className="flex items-start gap-3">
                                <Lens icon={Clock} />
                                <span>
                                    <span className="block font-semibold">
                                        Office hours
                                    </span>
                                    {provider.hours}
                                    <span className="block text-muted-foreground">
                                        Closed on public holidays
                                    </span>
                                </span>
                            </li>
                        </ul>
                    </div>
                </aside>
            </div>
        </SitePage>
    );
}
