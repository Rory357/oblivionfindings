import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    ArrowRight,
    ClipboardCheck,
    HeartHandshake,
    LockKeyhole,
    Phone,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { audiences, helpOptions, provider, type HelpOption } from './data';
import {
    EmergencyNote,
    NoteLine,
    PageIntro,
    RadioTiles,
    SitePage,
    useSiteName,
} from './site';

/** Wizards use the 1100 px pop-up width (480 / 720 / 900 / 1100 only). */
export const WIZARD_WIDTH = 'min(94vw, 1100px)';

const steps = [
    {
        key: 'contact',
        label: 'Safe contact',
        blurb: 'How and when to reach you',
        icon: Phone,
    },
    {
        key: 'support',
        label: 'Support and funding',
        blurb: 'Share what you know so far',
        icon: Wallet,
    },
    {
        key: 'review',
        label: 'Review and send',
        blurb: 'Privacy and your next step',
        icon: ClipboardCheck,
    },
] as const;

const channels = [
    { value: 'phone', title: 'Phone call', icon: 'PhoneCall' },
    { value: 'text', title: 'Text first', icon: 'MessageSquareText' },
    { value: 'email', title: 'Email', icon: 'Mail' },
] as const;

const fundingOptions = [
    { value: 'help', title: 'I’d like help with funding', icon: 'Wallet' },
    {
        value: 'underway',
        title: 'An assessment is underway',
        icon: 'Stethoscope',
    },
    { value: 'arranged', title: 'Funding is arranged', icon: 'HandHeart' },
    { value: 'unsure', title: 'Not sure', icon: 'User' },
] as const;

export function ReferralPage({ purpose: initial }: { purpose?: string }) {
    const name = useSiteName();
    const known = helpOptions.some((option) => option.value === initial);
    const [purpose, setPurpose] = useState<HelpOption | null>(
        known ? (initial as HelpOption) : null,
    );
    const [audience, setAudience] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    const [step, setStep] = useState(0);
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);
    const [channel, setChannel] = useState<string>('phone');
    const [funding, setFunding] = useState<string>('help');
    const [leaveMessage, setLeaveMessage] = useState(true);
    const chosen = helpOptions.find((option) => option.value === purpose);
    const ready = purpose === 'callback' || (purpose && audience);

    const send = () => {
        setSending(true);
        window.setTimeout(() => {
            setSending(false);
            setSent(true);
        }, 900);
    };
    const requestClose = () => (sent ? setOpen(false) : setConfirmClose(true));

    return (
        <SitePage
            current="referral"
            intro={
                <PageIntro
                    eyebrow="Make a referral"
                    title="Make a referral or ask about support"
                    lede="For yourself, someone in your whānau, or someone you work with. You don’t need a diagnosis, NHI number, funding approval or a confirmed place to get in touch."
                />
            }
        >
            <div className="journey-site-container grid gap-6 py-12 lg:grid-cols-[1.6fr_1fr]">
                <section
                    aria-label="Start a referral"
                    className="space-y-8 rounded-3xl border border-border bg-card p-6 md:p-8"
                >
                    <RadioTiles
                        label="What would you like to do?"
                        value={purpose}
                        onChange={setPurpose}
                        options={helpOptions.map((option) => ({
                            value: option.value,
                            title: option.title,
                            body: option.time,
                            icon: option.icon,
                        }))}
                    />
                    {purpose !== 'callback' && (
                        <RadioTiles
                            label="Who is the support for?"
                            value={audience}
                            onChange={setAudience}
                            options={audiences}
                        />
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-6">
                        <p className="max-w-md text-sm text-muted-foreground">
                            {chosen?.body ??
                                'Choose an option to start. You can change it later.'}
                        </p>
                        <Button
                            size="lg"
                            disabled={!ready}
                            onClick={() => {
                                setStep(0);
                                setSent(false);
                                setOpen(true);
                            }}
                        >
                            Continue
                            <ArrowRight className="size-4" aria-hidden />
                        </Button>
                    </div>
                </section>
                <aside className="space-y-4">
                    <div className="rounded-3xl border border-border bg-card p-6">
                        <p className="journey-site-kicker">What happens next</p>
                        <ol className="journey-site-progress journey-site-progress--plan mt-4">
                            {[
                                [
                                    'We save your referral',
                                    'You get a reference straight away.',
                                ],
                                [
                                    'An intake coordinator contacts you',
                                    `Within two working days, the way you choose. ${provider.hours}.`,
                                ],
                                [
                                    'We work out the next step together',
                                    'Including funding, if you’d like help with it.',
                                ],
                            ].map(([title, body]) => (
                                <li key={title}>
                                    <span
                                        className="journey-site-progress-dot"
                                        aria-hidden
                                    />
                                    <span>
                                        <span className="block font-semibold">
                                            {title}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {body}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </div>
                    <div className="space-y-3 rounded-3xl border border-border bg-card p-6">
                        <NoteLine icon={LockKeyhole}>
                            Only our intake team sees what you share. You can
                            ask about the process before sharing anyone’s
                            personal details.
                        </NoteLine>
                        <NoteLine icon={Phone}>
                            Prefer to talk? Call {provider.phone}.
                        </NoteLine>
                        <EmergencyNote />
                    </div>
                </aside>
            </div>

            <WizardShell
                open={open}
                onClose={requestClose}
                maxWidth={WIZARD_WIDTH}
                title={chosen?.title ?? 'Make a referral'}
                description={`Send a referral safely to the ${name} intake team.`}
                railIcon={HeartHandshake}
                railTitle={chosen?.title ?? 'Make a referral'}
                railSub="Share what you know. We can work through the rest together."
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => index < step && setStep(index)}
                success={
                    sent ? (
                        <WizardSuccessPane
                            title="Thanks — your referral is with us"
                            blurb={
                                <>
                                    Your reference is <strong>REF-1047</strong>.
                                    An intake coordinator will contact you by{' '}
                                    {channel === 'email'
                                        ? 'email'
                                        : channel === 'text'
                                          ? 'text'
                                          : 'phone'}{' '}
                                    within two working days. This reference
                                    isn’t a login — keep it if you call us.
                                </>
                            }
                            actions={
                                <Button onClick={() => setOpen(false)}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                footerStart={
                    <Button
                        variant="outline"
                        onClick={() =>
                            step > 0 ? setStep(step - 1) : requestClose()
                        }
                        disabled={sending}
                    >
                        {step > 0 ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={sending}
                        onClick={() =>
                            step < steps.length - 1 ? setStep(step + 1) : send()
                        }
                    >
                        {sending && <Spinner aria-hidden />}
                        {step < steps.length - 1
                            ? 'Continue'
                            : sending
                              ? 'Sending…'
                              : 'Send referral'}
                    </Button>
                }
            >
                {step === 0 && (
                    <WizardStepPane>
                        <div className="space-y-5">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="ref-name">Your name</Label>
                                    <Input
                                        id="ref-name"
                                        defaultValue="Aroha Ngata"
                                        autoComplete="name"
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="ref-phone">Phone</Label>
                                    <Input
                                        id="ref-phone"
                                        defaultValue="021 000 0000"
                                        autoComplete="tel"
                                        inputMode="tel"
                                    />
                                </div>
                                <div className="space-y-1.5 sm:col-span-2">
                                    <Label htmlFor="ref-email">
                                        Email (optional)
                                    </Label>
                                    <Input
                                        id="ref-email"
                                        type="email"
                                        autoComplete="email"
                                    />
                                </div>
                            </div>
                            <RadioTiles
                                label="How should we contact you?"
                                value={channel}
                                onChange={setChannel}
                                columns="sm:grid-cols-3"
                                options={channels}
                            />
                            <div className="space-y-1.5">
                                <Label htmlFor="ref-best-time">
                                    Best time to reach you
                                </Label>
                                <Input
                                    id="ref-best-time"
                                    defaultValue="Weekday mornings"
                                />
                            </div>
                            <div className="flex items-center justify-between gap-4 rounded-2xl bg-muted p-4">
                                <span>
                                    <Label
                                        htmlFor="ref-leave-message"
                                        className="block font-medium"
                                    >
                                        We can leave a message
                                    </Label>
                                    <span className="block text-sm text-muted-foreground">
                                        We’ll only say who we are and ask you to
                                        call back.
                                    </span>
                                </span>
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    {leaveMessage ? 'Yes' : 'No'}
                                    <Switch
                                        id="ref-leave-message"
                                        checked={leaveMessage}
                                        onCheckedChange={setLeaveMessage}
                                    />
                                </span>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="ref-needs">
                                    Communication or interpreter needs
                                    (optional)
                                </Label>
                                <Input
                                    id="ref-needs"
                                    placeholder="For example, NZ Sign Language or te reo Māori"
                                />
                            </div>
                        </div>
                    </WizardStepPane>
                )}
                {step === 1 && (
                    <WizardStepPane>
                        <div className="space-y-5">
                            <div className="space-y-1.5">
                                <Label htmlFor="ref-goals">
                                    What would you like support with?
                                </Label>
                                <Textarea
                                    id="ref-goals"
                                    rows={4}
                                    defaultValue="Help to move into my own flat and keep up with cooking and appointments."
                                />
                                <p className="text-caption text-muted-foreground">
                                    A few words is fine. “Not sure yet” is a
                                    good answer too.
                                </p>
                            </div>
                            <RadioTiles
                                label="Where are you with funding?"
                                value={funding}
                                onChange={setFunding}
                                options={fundingOptions}
                            />
                        </div>
                    </WizardStepPane>
                )}
                {step === 2 && (
                    <WizardStepPane>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard
                                icon={Phone}
                                title="Safe contact"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow label="Name" value="Aroha Ngata" />
                                <ReviewRow
                                    label="Contact by"
                                    value={
                                        channels.find(
                                            (option) =>
                                                option.value === channel,
                                        )?.title
                                    }
                                />
                                <ReviewRow
                                    label="Leave a message"
                                    value={leaveMessage ? 'Yes' : 'No'}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Wallet}
                                title="Support and funding"
                                onEdit={() => setStep(1)}
                            >
                                <ReviewRow
                                    label="Help with"
                                    value={chosen?.title}
                                />
                                <ReviewRow
                                    label="Funding"
                                    value={
                                        fundingOptions.find(
                                            (option) =>
                                                option.value === funding,
                                        )?.title
                                    }
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={LockKeyhole}
                                title="Your information"
                                span
                            >
                                <p className="text-sm text-muted-foreground">
                                    We collect this to respond to your referral
                                    and plan support with you. Only our intake
                                    team sees it. You can ask to see or correct
                                    it at any time.
                                </p>
                            </ReviewCard>
                        </div>
                    </WizardStepPane>
                )}
            </WizardShell>

            <ConfirmDialog
                open={confirmClose}
                onClose={() => setConfirmClose(false)}
                onConfirm={() => setOpen(false)}
                variant="destructive"
                frontline
                title="Discard this referral?"
                description="What you’ve entered so far won’t be sent or saved."
                confirmText="Discard referral"
                cancelText="Keep going"
            />
        </SitePage>
    );
}
