import { EventHorizonWordmark } from '@/components/event-horizon-wordmark';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { TooltipProvider } from '@/components/ui/tooltip';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import {
    ArrowLeft,
    ArrowRight,
    ArrowUpRight,
    Bell,
    BookOpen,
    CalendarDays,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleHelp,
    ClipboardList,
    Clock,
    Eye,
    FileCheck2,
    FileText,
    Heart,
    History,
    Home,
    Info,
    Layers,
    ListTodo,
    LockKeyhole,
    MapPin,
    MessageSquare,
    Navigation,
    Plus,
    Radio,
    RefreshCw,
    Search,
    Settings,
    Shield,
    ShieldCheck,
    ShieldOff,
    SlidersHorizontal,
    User,
    Users,
    WifiOff,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';

const VERSION = 'PKG-02A v1';
const scenarios = [
    ['authorised', 'Authorised staff · no recipient grant'],
    ['no-device', 'No tracking device'],
    ['missing', 'Missing collection decision'],
    ['unknown', 'Authority cannot be verified'],
    ['purpose', 'Decision is for another purpose'],
    ['denied', 'Staff viewer denied'],
    ['expired', 'Collection decision expired'],
    ['withdrawn', 'Collection decision withdrawn'],
    ['reassigned', 'Tracking assignment changed'],
    ['stale', 'Older observation'],
    ['unavailable', 'Tracker unavailable'],
    ['loading', 'Checking access / loading'],
    ['empty', 'Authorised · no observations'],
    ['network', 'Access check failed'],
    ['limited', 'Staff can view · cannot manage'],
    ['accuracy', 'Accuracy and battery unknown'],
    ['shared', 'Illustrative named sharing grant'],
    ['share-expired', 'Recipient sharing expired'],
    ['share-withdrawn', 'Recipient sharing withdrawn'],
    ['self', 'Client self-access distinction'],
] as const;
type Scenario = (typeof scenarios)[number][0];
type Section = 'location' | 'consents';
type Modal =
    | 'none'
    | 'collection'
    | 'staff'
    | 'assignment'
    | 'sharing'
    | 'share-review'
    | 'withdraw'
    | 'export'
    | 'destination'
    | 'find'
    | 'policy'
    | 'request';
const restricted = new Set<Scenario>([
    'no-device',
    'missing',
    'unknown',
    'purpose',
    'denied',
    'expired',
    'withdrawn',
    'reassigned',
    'loading',
    'network',
]);
const blockCopy: Record<
    string,
    { title: string; body: string; cta: string; action: Modal | Section }
> = {
    'no-device': {
        title: 'No tracking device is assigned',
        body: 'A location observation needs a current device assignment linked to the applicable collection decision.',
        cta: 'View tracking assignment',
        action: 'assignment',
    },
    missing: {
        title: 'No collection decision is linked',
        body: 'Location and history stay hidden until the applicable authority is recorded and linked to this assignment.',
        cta: 'Open Consents',
        action: 'consents',
    },
    unknown: {
        title: 'Collection authority needs review',
        body: 'The decision is recorded, but its authority evidence could not be verified. No location is shown.',
        cta: 'Review collection decision',
        action: 'collection',
    },
    purpose: {
        title: 'This decision does not cover location tracking',
        body: 'The linked decision covers a different purpose. It cannot authorise this location view.',
        cta: 'Open Consents',
        action: 'consents',
    },
    denied: {
        title: 'You do not have access to this location',
        body: 'Client profile access does not include location access. Location, history and sensitive authority details are hidden.',
        cta: 'View access guidance',
        action: 'staff',
    },
    expired: {
        title: 'The collection decision has expired',
        body: 'Location access has ended. A permitted reviewer must check the current decision before tracking can resume.',
        cta: 'Open Consents',
        action: 'consents',
    },
    withdrawn: {
        title: 'The collection decision was withdrawn',
        body: 'Location and history have been removed from this view. Retained evidence remains subject to the approved retention policy.',
        cta: 'View withdrawn decision',
        action: 'collection',
    },
    reassigned: {
        title: 'The tracking assignment has changed',
        body: 'The previous device’s location has been cleared. The current assignment and its decision must be checked before another observation is shown.',
        cta: 'Review tracking assignment',
        action: 'assignment',
    },
    loading: {
        title: 'Checking location access',
        body: 'Collection authority, your access and the current assignment are being checked before any location is displayed.',
        cta: '',
        action: 'none',
    },
    network: {
        title: 'Location access could not be checked',
        body: 'Location and history are hidden because access could not be revalidated. This is a connection failure, not an empty history.',
        cta: 'Retry access check',
        action: 'none',
    },
};
const people = [
    {
        id: 'demo-recipient-01',
        name: 'Mara Hale',
        sub: 'Named portal account · R-DEMO-01',
    },
    {
        id: 'demo-recipient-02',
        name: 'Leon Hale',
        sub: 'Named portal account · R-DEMO-02',
    },
    {
        id: 'demo-recipient-03',
        name: 'Jo Ellis',
        sub: 'Named portal account · R-DEMO-03',
    },
];
const evidence = [
    {
        id: 'demo-evidence-17',
        name: 'Recipient disclosure decision',
        sub: 'C-DEMO-017 · client decision · evidence E-DEMO-17',
    },
    {
        id: 'demo-evidence-18',
        name: 'Authority review pending',
        sub: 'C-DEMO-018 · authority not verified',
    },
];
const groupItems = [
    { key: 'snapshot', label: 'Snapshot', icon: User },
    { key: 'daily', label: 'Daily care', icon: Heart },
    { key: 'plans', label: 'Plans & goals', icon: BookOpen },
    { key: 'health', label: 'Health & safety', icon: Shield },
    { key: 'operations', label: 'Day-to-day', icon: CalendarDays },
    { key: 'governance', label: 'Relationships & governance', icon: Users },
];
const snapshotTabs = [
    { key: 'profile', label: 'Overview', icon: User },
    { key: 'personal_details', label: 'Personal details', icon: FileText },
    { key: 'onboarding', label: 'Onboarding', icon: ClipboardList },
    { key: 'location', label: 'Location', icon: Navigation },
    { key: 'assignments', label: 'Assignments', icon: Users },
];
const consentTabs = [
    { key: 'family_tree', label: 'Family tree', icon: Users },
    { key: 'consents', label: 'Consents', icon: Shield },
    { key: 'consent-requests', label: 'Consent requests', icon: FileCheck2 },
    { key: 'portal', label: 'Family portal', icon: Users },
    { key: 'actions_reviews', label: 'Actions & reviews', icon: ListTodo },
    { key: 'audit_history', label: 'Audit history', icon: History },
    { key: 'privacy', label: 'Privacy', icon: LockKeyhole },
];

function Notice({
    children,
    tone = 'neutral',
    icon: Icon = Info,
}: {
    children: React.ReactNode;
    tone?: string;
    icon?: typeof Info;
}) {
    return (
        <div className={`notice ${tone}`}>
            <Icon aria-hidden="true" />
            <div>{children}</div>
        </div>
    );
}
function Panel({
    title,
    sub,
    icon: Icon,
    action,
    children,
    className = '',
}: {
    title: string;
    sub?: string;
    icon?: typeof Info;
    action?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section className={`panel ${className}`}>
            <div className="panel-head">
                <div>
                    <h3>
                        {Icon && <Icon className="size-4 text-primary" />}
                        {title}
                    </h3>
                    {sub && <p>{sub}</p>}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}
function KV({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="keyline">
            <dt>{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}
function LinkButton({
    children,
    onClick,
}: {
    children: React.ReactNode;
    onClick: () => void;
}) {
    return (
        <button type="button" className="inline-link" onClick={onClick}>
            {children}
            <ArrowUpRight className="size-3.5" />
        </button>
    );
}

function RecordPicker({
    label,
    value,
    onChange,
    items,
    error,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    items: typeof people;
    error?: string;
}) {
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState('ready');
    const chosen = items.find((p) => p.id === value);
    const fieldId = `picker-${label.toLowerCase().replaceAll(' ', '-')}`;
    return (
        <div>
            <label className="field-label" htmlFor={fieldId}>
                {label} <span className="required">*</span>
            </label>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={fieldId}
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-label={label}
                        aria-invalid={!!error}
                        aria-describedby={
                            error ? `${fieldId}-error` : undefined
                        }
                        className="h-auto min-h-12 w-full justify-between py-3"
                    >
                        <span className="text-left">
                            <strong className="block font-medium">
                                {chosen?.name || 'Search and choose a record'}
                            </strong>
                            {chosen && (
                                <small className="micro">{chosen.sub}</small>
                            )}
                        </span>
                        <ChevronDown className="size-4" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="p-0"
                    style={{ width: 'min(430px,80vw)' }}
                    align="start"
                    onEscapeKeyDown={(e) => e.stopPropagation()}
                >
                    <Command>
                        <CommandInput
                            placeholder={`Search ${label.toLowerCase()}…`}
                        />
                        <div className="flex items-center gap-2 border-b px-3 py-2 text-[10px] text-muted-foreground">
                            <span>Demo search:</span>
                            {['ready', 'loading', 'error', 'denied'].map(
                                (m) => (
                                    <button
                                        key={m}
                                        onClick={() => setMode(m)}
                                        className={
                                            mode === m
                                                ? 'font-bold text-primary'
                                                : ''
                                        }
                                    >
                                        {m}
                                    </button>
                                ),
                            )}
                        </div>
                        {mode === 'ready' ? (
                            <CommandList>
                                <CommandEmpty>
                                    No matching synthetic records.
                                </CommandEmpty>
                                <CommandGroup>
                                    {items.map((p) => (
                                        <CommandItem
                                            key={p.id}
                                            value={`${p.name} ${p.sub}`}
                                            onSelect={() => {
                                                onChange(p.id);
                                                setOpen(false);
                                            }}
                                        >
                                            <div className="py-2">
                                                <strong className="block font-medium">
                                                    {p.name}
                                                </strong>
                                                <small className="text-muted-foreground">
                                                    {p.sub}
                                                </small>
                                            </div>
                                            {value === p.id && (
                                                <Check className="ml-auto size-4" />
                                            )}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            </CommandList>
                        ) : (
                            <div className="p-5 text-sm">
                                {mode === 'loading'
                                    ? 'Loading permitted records…'
                                    : mode === 'error'
                                      ? 'The directory could not be loaded. Your selection is retained.'
                                      : 'The permitted directory is unavailable for this action.'}
                                {mode !== 'loading' && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="mt-3"
                                        onClick={() => setMode('ready')}
                                    >
                                        Retry search
                                    </Button>
                                )}
                            </div>
                        )}
                    </Command>
                </PopoverContent>
            </Popover>
            {error && (
                <p id={`${fieldId}-error`} className="field-error" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}

function SyntheticMap({
    stale,
    accuracyKnown,
}: {
    stale: boolean;
    accuracyKnown: boolean;
}) {
    const [zoom, setZoom] = useState(1);
    const [popup, setPopup] = useState(true);
    return (
        <div
            className="map"
            aria-label="Illustrative map of fictional places; no live map provider"
        >
            <svg
                className="map-art"
                viewBox="0 0 700 292"
                role="img"
                aria-label="Synthetic observation beside Example Gardens"
            >
                <g
                    transform={`translate(350 146) scale(${zoom}) translate(-350 -146)`}
                >
                    <rect width="700" height="292" fill="var(--muted)" />
                    {Array.from({ length: 24 }, (_, i) => (
                        <rect
                            key={i}
                            x={(i % 8) * 93 + 8}
                            y={Math.floor(i / 8) * 98 + 9}
                            width={58 + (i % 3) * 5}
                            height={66}
                            rx="5"
                            className="map-block"
                        />
                    ))}
                    <path
                        d="M0 84H700 M0 222H700 M174 0V292 M495 0V292 M295 0L412 292"
                        className="map-outline"
                    />
                    <path
                        d="M0 84H700 M0 222H700 M174 0V292 M495 0V292 M295 0L412 292"
                        className="map-street"
                    />
                    <rect
                        x="218"
                        y="111"
                        width="87"
                        height="83"
                        rx="13"
                        fill="var(--background)"
                    />
                    <text x="225" y="146" className="map-label">
                        Example
                    </text>
                    <text x="225" y="162" className="map-label">
                        Gardens
                    </text>
                    <text x="44" y="79" className="map-label">
                        EXAMPLE LANE
                    </text>
                    <text x="518" y="215" className="map-label">
                        DEMO ROAD
                    </text>
                    {accuracyKnown && (
                        <circle
                            cx="358"
                            cy="162"
                            r="39"
                            fill="color-mix(in oklch,var(--primary) 10%,transparent)"
                            stroke="var(--primary)"
                            strokeWidth="1"
                            strokeDasharray="4 4"
                        />
                    )}
                    <circle
                        cx="358"
                        cy="162"
                        r="13"
                        fill={
                            stale ? 'var(--status-warning)' : 'var(--primary)'
                        }
                        stroke="var(--card)"
                        strokeWidth="4"
                    />
                </g>
            </svg>
            <button
                className="map-popup"
                onClick={() => setPopup(!popup)}
                aria-label="Toggle observation summary"
            >
                {popup ? (
                    <>
                        <strong>Example Gardens</strong>
                        <small>
                            {stale
                                ? 'Last observation · 11:10 am'
                                : 'Last observation · 2:32 pm'}
                        </small>
                        <small>20 Sep 2026 · NZST (UTC+12)</small>
                    </>
                ) : (
                    <strong>Show observation details</strong>
                )}
            </button>
            <div className="map-controls">
                <Button
                    size="icon"
                    variant="outline"
                    aria-label="Zoom in on synthetic map"
                    disabled={zoom >= 1.6}
                    onClick={() => setZoom(Math.min(1.6, zoom + 0.2))}
                >
                    <ZoomIn className="size-4" />
                </Button>
                <Button
                    size="icon"
                    variant="outline"
                    aria-label="Zoom out on synthetic map"
                    disabled={zoom <= 0.8}
                    onClick={() => setZoom(Math.max(0.8, zoom - 0.2))}
                >
                    <ZoomOut className="size-4" />
                </Button>
                <Button
                    size="icon"
                    variant="outline"
                    aria-label="Reset synthetic map"
                    onClick={() => setZoom(1)}
                >
                    <Navigation className="size-4" />
                </Button>
            </div>
            <span className="map-caption">
                Fictional map ·{' '}
                {accuracyKnown
                    ? 'illustrative accuracy area'
                    : 'accuracy not supplied'}{' '}
                · no live tracking
            </span>
        </div>
    );
}

const reviewSteps = [
    {
        key: 'recipient',
        label: 'Recipient',
        blurb: 'One named audience',
        icon: Users,
    },
    {
        key: 'scope',
        label: 'Purpose & scope',
        blurb: 'What can be disclosed',
        icon: Eye,
    },
    {
        key: 'period',
        label: 'Time bounds',
        blurb: 'Start, end and review',
        icon: Clock,
    },
    {
        key: 'authority',
        label: 'Authority & evidence',
        blurb: 'Link the decision',
        icon: FileCheck2,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before any grant',
        icon: ShieldCheck,
    },
];
function SharingWizard({
    open,
    onClose,
    onDone,
    returnFocus,
    existing = false,
}: {
    open: boolean;
    onClose: () => void;
    onDone: () => void;
    returnFocus: () => void;
    existing?: boolean;
}) {
    const [step, setStep] = useState(0),
        [recipient, setRecipient] = useState(
            existing ? 'demo-recipient-01' : '',
        ),
        [purpose, setPurpose] = useState(
            existing ? 'Agreed community outing check-in' : '',
        ),
        [scope, setScope] = useState(existing ? 'latest' : ''),
        [start, setStart] = useState(existing ? '2026-09-20T13:00' : ''),
        [end, setEnd] = useState(existing ? '2026-09-20T17:00' : ''),
        [review, setReview] = useState(existing ? '2026-09-20T15:00' : ''),
        [proof, setProof] = useState(existing ? 'demo-evidence-17' : ''),
        [notes, setNotes] = useState(''),
        [errors, setErrors] = useState<Record<string, string>>({}),
        [discard, setDiscard] = useState(false),
        [outcome, setOutcome] = useState(false),
        [failure, setFailure] = useState(false),
        [retry, setRetry] = useState(false);
    const dirty = !!(
        recipient ||
        purpose ||
        scope ||
        start ||
        end ||
        review ||
        proof ||
        notes
    );
    const requestClose = () =>
        dirty && !outcome ? setDiscard(true) : onClose();
    const validate = (all = false) => {
        const e: Record<string, string> = {};
        if (all || step === 0) {
            if (!recipient) e.recipient = 'Choose the named recipient.';
        }
        if (all || step === 1) {
            if (!purpose.trim())
                e.purpose = 'Record the specific purpose for this disclosure.';
            if (!scope) e.scope = 'Choose the requested scope.';
        }
        if (all || step === 2) {
            if (!validLocalDateTime(start))
                e.start = 'Choose a complete start date and time.';
            if (!validLocalDateTime(end))
                e.end = 'Choose a complete end date and time.';
            if (!validLocalDateTime(review))
                e.review = 'Choose a complete review date and time.';
            if (start && end && end <= start)
                e.end = 'The end must be after the start.';
            if (review && start && end && (review < start || review > end))
                e.review = 'Place the review within the requested period.'; // Preview only. No local wall-clock interval becomes a grant.
        }
        if (all || step === 3) {
            if (!proof)
                e.proof =
                    'Link the applicable decision and authority evidence.';
        }
        setErrors(e);
        if (Object.keys(e).length) {
            if (all)
                setStep(
                    e.recipient
                        ? 0
                        : e.purpose || e.scope
                          ? 1
                          : e.start || e.end || e.review
                            ? 2
                            : 3,
                );
            requestAnimationFrame(() =>
                document
                    .querySelector<HTMLElement>(
                        '[role="dialog"] [aria-invalid="true"]',
                    )
                    ?.focus(),
            );
        }
        return Object.keys(e).length === 0;
    };
    const complete = () => {
        if (!validate(true)) {
            return;
        }
        if (failure && !retry) {
            setRetry(true);
            return;
        }
        setOutcome(true);
        onDone();
    };
    return (
        <>
            <WizardShell
                open={open}
                onClose={requestClose}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    returnFocus();
                }}
                title="Review recipient sharing"
                description="Synthetic review only. This flow does not grant access or save a decision."
                railIcon={ShieldCheck}
                railTitle="Recipient sharing"
                railSub="Alex Hale · CL-DEMO-024"
                steps={reviewSteps}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    ([
                        recipient,
                        purpose,
                        scope,
                        start,
                        end,
                        review,
                        proof,
                    ].filter(Boolean).length /
                        7) *
                        100,
                )}
                pctLabel="Draft detail"
                maxWidth="min(94vw,1100px)"
                maxHeight="min(86vh,780px)"
                railExtra={
                    <div className="draft-note">
                        Illustrative review
                        <br />
                        No recipient has access through this draft. Policy and
                        authority validation remain required.
                    </div>
                }
                footerStart={
                    <>
                        <Button variant="outline" onClick={requestClose}>
                            Cancel
                        </Button>
                        {step > 0 && !outcome && (
                            <Button
                                variant="ghost"
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    outcome ? (
                        <Button onClick={onClose}>Return to Location</Button>
                    ) : step < 4 ? (
                        <Button
                            onClick={() => {
                                if (validate()) setStep(step + 1);
                            }}
                        >
                            Continue
                            <ArrowRight className="size-4" />
                        </Button>
                    ) : (
                        <Button onClick={complete}>
                            {retry
                                ? 'Retry illustrative review'
                                : 'Preview review outcome'}
                        </Button>
                    )
                }
            >
                {outcome ? (
                    <div className="dialog-stack p-8">
                        <div className="wizard-intro">
                            <ShieldCheck className="mb-4 size-10 text-primary" />
                            <h3>Review prepared. No access change.</h3>
                            <p>
                                This synthetic draft demonstrates the review
                                outcome. It has not been saved, submitted or
                                turned into a sharing grant.
                            </p>
                        </div>
                        <Notice tone="warning">
                            <strong>
                                Privacy-owner decisions are still required
                            </strong>
                            Confirm permitted audience and purpose, authority
                            checks, disclosure scope, time limits and review
                            rules before implementation can enable a grant.
                        </Notice>
                        <ReviewCard title="Named recipient" icon={Users}>
                            <ReviewRow
                                label="Recipient"
                                value={
                                    people.find((p) => p.id === recipient)?.name
                                }
                            />
                            <ReviewRow
                                label="Disclosure"
                                value={
                                    scope === 'latest'
                                        ? 'Latest authorised observation only'
                                        : 'History requested — separate policy review'
                                }
                            />
                            <ReviewRow
                                label="Decision"
                                value="No access change from this review"
                            />
                        </ReviewCard>
                    </div>
                ) : (
                    <WizardStepPane key={step}>
                        <div className="dialog-stack">
                            <Notice icon={User}>
                                <strong>Alex Hale · CL-DEMO-024</strong>Locked
                                client context · all names, accounts and records
                                are synthetic.
                            </Notice>
                            {step === 0 && (
                                <>
                                    <div className="wizard-intro">
                                        <h3>Who would receive location?</h3>
                                        <p>
                                            Select an individual portal account.
                                            A family relationship never supplies
                                            permission on its own.
                                        </p>
                                    </div>
                                    <RecordPicker
                                        label="Recipient"
                                        value={recipient}
                                        onChange={setRecipient}
                                        items={people}
                                        error={errors.recipient}
                                    />
                                    <Notice icon={LockKeyhole}>
                                        Client self-access is a separate route
                                        and decision. This form only reviews
                                        disclosure to another person.
                                    </Notice>
                                </>
                            )}
                            {step === 1 && (
                                <>
                                    <div className="wizard-intro">
                                        <h3>Make the disclosure specific</h3>
                                        <p>
                                            The stated purpose and information
                                            scope must be covered by the
                                            applicable decision.
                                        </p>
                                    </div>
                                    <div>
                                        <label
                                            className="field-label"
                                            htmlFor="sharing-purpose"
                                        >
                                            Purpose{' '}
                                            <span className="required">*</span>
                                        </label>
                                        <Textarea
                                            id="sharing-purpose"
                                            value={purpose}
                                            onChange={(e) =>
                                                setPurpose(e.target.value)
                                            }
                                            placeholder="Describe the reason this named person needs this location information."
                                            aria-invalid={!!errors.purpose}
                                        />
                                        {errors.purpose && (
                                            <p
                                                className="field-error"
                                                role="alert"
                                            >
                                                {errors.purpose}
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <span className="field-label">
                                            Requested information{' '}
                                            <span className="required">*</span>
                                        </span>
                                        <div className="grid grid-cols-2 gap-3">
                                            <button
                                                className="choice"
                                                aria-pressed={
                                                    scope === 'latest'
                                                }
                                                onClick={() =>
                                                    setScope('latest')
                                                }
                                            >
                                                <MapPin className="size-5 text-primary" />
                                                <span>
                                                    <strong>
                                                        Latest observation
                                                    </strong>
                                                    <small>
                                                        Location, observed time
                                                        and accuracy. No history
                                                        or export.
                                                    </small>
                                                </span>
                                            </button>
                                            <button
                                                className="choice"
                                                aria-pressed={
                                                    scope === 'history'
                                                }
                                                onClick={() =>
                                                    setScope('history')
                                                }
                                            >
                                                <History className="size-5 text-primary" />
                                                <span>
                                                    <strong>
                                                        History requested
                                                    </strong>
                                                    <small>
                                                        Needs a separately
                                                        defined period and
                                                        approved scope.
                                                    </small>
                                                </span>
                                            </button>
                                        </div>
                                        {errors.scope && (
                                            <p
                                                className="field-error"
                                                role="alert"
                                            >
                                                {errors.scope}
                                            </p>
                                        )}
                                    </div>
                                    <Notice tone="warning">
                                        These are illustrative scope choices.
                                        Permitted disclosure scopes have not
                                        been adopted as policy.
                                    </Notice>
                                </>
                            )}
                            {step === 2 && (
                                <>
                                    <div className="wizard-intro">
                                        <h3>
                                            Set the proposed period and review
                                        </h3>
                                        <p>
                                            No default duration is applied.
                                            Enter the requested bounds for
                                            review; they do not establish
                                            approved policy.
                                        </p>
                                    </div>
                                    <DateTimeField
                                        id="share-start"
                                        label="Starts"
                                        value={start}
                                        onChange={setStart}
                                        error={errors.start}
                                    />
                                    <DateTimeField
                                        id="share-end"
                                        label="Ends"
                                        value={end}
                                        onChange={setEnd}
                                        error={errors.end}
                                    />
                                    <DateTimeField
                                        id="share-review"
                                        label="Review due"
                                        value={review}
                                        onChange={setReview}
                                        error={errors.review}
                                    />
                                    <Notice tone="warning">
                                        Policy limits and timezone checks must
                                        be confirmed before a grant. A partial
                                        date or time cannot pass this review.
                                    </Notice>
                                </>
                            )}
                            {step === 3 && (
                                <>
                                    <div className="wizard-intro">
                                        <h3>Link authority to its evidence</h3>
                                        <p>
                                            Use the canonical consent decision
                                            and its evidence. This flow does not
                                            create another consent record.
                                        </p>
                                    </div>
                                    <RecordPicker
                                        label="Decision and evidence"
                                        value={proof}
                                        onChange={setProof}
                                        items={evidence}
                                        error={errors.proof}
                                    />
                                    {proof && (
                                        <Notice
                                            tone={
                                                proof === 'demo-evidence-18'
                                                    ? 'warning'
                                                    : 'info'
                                            }
                                        >
                                            <strong>
                                                {proof === 'demo-evidence-18'
                                                    ? 'Authority not verified'
                                                    : 'Illustrative client decision'}
                                            </strong>
                                            {proof === 'demo-evidence-18'
                                                ? 'This record cannot enable sharing while its authority review is unresolved.'
                                                : 'Decision C-DEMO-017 · evidence E-DEMO-17. An approved reviewer must validate purpose, recipient and current authority.'}
                                        </Notice>
                                    )}
                                    <div>
                                        <label
                                            className="field-label"
                                            htmlFor="sharing-notes"
                                        >
                                            Review notes
                                        </label>
                                        <Textarea
                                            id="sharing-notes"
                                            value={notes}
                                            onChange={(e) =>
                                                setNotes(e.target.value)
                                            }
                                            placeholder="Record what needs checking. Do not enter real personal information."
                                        />
                                    </div>
                                    <p className="micro">
                                        Evidence files stay on the existing
                                        consent record. This preview accepts no
                                        evidence uploads.
                                    </p>
                                </>
                            )}
                            {step === 4 && (
                                <>
                                    <div className="wizard-intro">
                                        <h3>Check the proposed sharing</h3>
                                        <p>
                                            Nothing in this preview grants
                                            access. Unsettled rules stay visible
                                            for the privacy owner.
                                        </p>
                                    </div>
                                    <ReviewCard
                                        icon={Users}
                                        title="Audience & purpose"
                                        onEdit={() => setStep(0)}
                                    >
                                        <ReviewRow
                                            label="Named recipient"
                                            value={
                                                people.find(
                                                    (p) => p.id === recipient,
                                                )?.name
                                            }
                                        />
                                        <ReviewRow
                                            label="Purpose"
                                            value={purpose}
                                        />
                                        <ReviewRow
                                            label="Scope"
                                            value={
                                                scope === 'latest'
                                                    ? 'Latest observation; no history or export'
                                                    : scope === 'history'
                                                      ? 'History requested; scope unresolved'
                                                      : undefined
                                            }
                                        />
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={Clock}
                                        title="Time bounds"
                                        onEdit={() => setStep(2)}
                                    >
                                        <ReviewRow
                                            label="Starts"
                                            value={localDateTimeLabel(start)}
                                        />
                                        <ReviewRow
                                            label="Ends"
                                            value={localDateTimeLabel(end)}
                                        />
                                        <ReviewRow
                                            label="Review due"
                                            value={localDateTimeLabel(review)}
                                        />
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={FileCheck2}
                                        title="Authority"
                                        onEdit={() => setStep(3)}
                                    >
                                        <ReviewRow
                                            label="Linked decision"
                                            value={
                                                evidence.find(
                                                    (p) => p.id === proof,
                                                )?.sub
                                            }
                                        />
                                        <ReviewRow
                                            label="Policy validation"
                                            value="Required — no grant can be activated"
                                        />
                                    </ReviewCard>
                                    <Notice tone="warning">
                                        Allowed purposes, decision-maker
                                        authority, maximum period, review,
                                        retention and recipient channels require
                                        approved rules. No emergency override is
                                        included.
                                    </Notice>
                                    <label className="micro flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={failure}
                                            onChange={(e) => {
                                                setFailure(e.target.checked);
                                                setRetry(false);
                                            }}
                                        />
                                        Demonstrate a failed review attempt
                                    </label>
                                    {retry && (
                                        <Notice tone="critical">
                                            <strong>
                                                The illustrative review failed
                                            </strong>
                                            Your recipient, dates, purpose and
                                            evidence reference are retained.
                                            Retry performs only this local
                                            demonstration.
                                        </Notice>
                                    )}
                                </>
                            )}
                        </div>
                    </WizardStepPane>
                )}
            </WizardShell>
            <Dialog open={discard} onOpenChange={setDiscard}>
                <DialogContent
                    style={{
                        width: 'min(92vw,480px)',
                        maxWidth: 'min(92vw,480px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Discard this sharing draft?</DialogTitle>
                        <DialogDescription>
                            Your entries exist only in this preview. Closing
                            will discard them without changing anyone’s access.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDiscard(false)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                setDiscard(false);
                                onClose();
                            }}
                        >
                            Discard draft
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function App() {
    const urlScenario = new URLSearchParams(location.search).get(
        'scenario',
    ) as Scenario;
    const [scenario, setScenario] = useState<Scenario>(
        scenarios.some((s) => s[0] === urlScenario)
            ? urlScenario
            : 'authorised',
    );
    const [section, setSection] = useState<Section>('location'),
        [modal, setModal] = useState<Modal>('none'),
        [destination, setDestination] = useState(''),
        [collapsed, setCollapsed] = useState(false),
        [historyOpen, setHistoryOpen] = useState(false),
        [historyMode, setHistoryMode] = useState('ready'),
        [historyLoaded, setHistoryLoaded] = useState(false),
        [historyFrom, setHistoryFrom] = useState('2026-09-20'),
        [historyTo, setHistoryTo] = useState('2026-09-20'),
        [historyError, setHistoryError] = useState(''),
        [checked, setChecked] = useState('2:35 pm'),
        [refreshing, setRefreshing] = useState(false),
        [notice, setNotice] = useState(''),
        [withdrawReason, setWithdrawReason] = useState(''),
        [withdrawError, setWithdrawError] = useState(''),
        [search, setSearch] = useState(''),
        [reviewPrepared, setReviewPrepared] = useState(false),
        [opsOpen, setOpsOpen] = useState(true);
    const opener = useRef<HTMLElement | null>(null);
    const denied = scenario === 'denied';
    const limited = scenario === 'limited';
    const hidden = restricted.has(scenario);
    const stale = scenario === 'stale';
    const grant = scenario === 'shared';
    const shareEnded = ['share-expired', 'share-withdrawn'].includes(scenario);
    const collectionBad = [
        'missing',
        'unknown',
        'purpose',
        'expired',
        'withdrawn',
    ].includes(scenario);
    const open = (m: Modal) => {
        opener.current = document.activeElement as HTMLElement;
        setModal(
            denied &&
                [
                    'collection',
                    'assignment',
                    'sharing',
                    'share-review',
                    'withdraw',
                    'export',
                    'request',
                ].includes(m)
                ? 'staff'
                : m,
        );
        setWithdrawError('');
    };
    const close = () => {
        setModal('none');
        requestAnimationFrame(() => opener.current?.focus());
    };
    const navigate = (s: Section) => {
        if (denied && s === 'consents') {
            open('staff');
            return;
        }
        setSection(s);
        setModal('none');
        setNotice('');
        window.scrollTo({ top: 0, behavior: 'instant' });
    };
    const dest = (name: string) => {
        setDestination(name);
        open('destination');
    };
    const changeScenario = (v: Scenario) => {
        setScenario(v);
        setSection('location');
        setModal('none');
        setHistoryLoaded(false);
        setHistoryOpen(false);
        setHistoryMode('ready');
        setNotice('');
        setReviewPrepared(false);
        setChecked('2:35 pm');
        const u = new URL(location.href);
        u.searchParams.set('scenario', v);
        window.history.replaceState({}, '', u);
    };
    const refresh = () => {
        setRefreshing(true);
        setNotice('');
        setTimeout(() => {
            setRefreshing(false);
            setChecked('2:36 pm');
            if (scenario === 'network') {
                setNotice(
                    'The access check still failed. Location remains hidden.',
                );
            } else {
                setNotice(
                    'View checked at 2:36 pm. The observation timestamp has not changed.',
                );
            }
        }, 550);
    };
    useEffect(() => {
        const fn = (e: KeyboardEvent) => {
            if (
                e.key === '/' &&
                !['INPUT', 'TEXTAREA', 'SELECT'].includes(
                    (e.target as HTMLElement)?.tagName,
                ) &&
                modal === 'none'
            ) {
                e.preventDefault();
                open('find');
            }
        };
        window.addEventListener('keydown', fn);
        return () => window.removeEventListener('keydown', fn);
    }, [modal]);
    const shareLabel = grant
        ? 'One named recipient'
        : scenario === 'share-expired'
          ? 'Sharing expired'
          : scenario === 'share-withdrawn'
            ? 'Sharing withdrawn'
            : reviewPrepared
              ? 'Review prepared · no grant'
              : 'No sharing grant';
    const collectionLabel = denied
        ? 'Details restricted'
        : scenario === 'loading'
          ? 'Checking'
          : scenario === 'network'
            ? 'Not verified'
            : scenario === 'missing'
              ? 'Not recorded'
              : scenario === 'unknown'
                ? 'Needs review'
                : scenario === 'purpose'
                  ? 'Purpose mismatch'
                  : scenario === 'expired'
                    ? 'Expired'
                    : scenario === 'withdrawn'
                      ? 'Withdrawn'
                      : 'Decision recorded';
    const state = blockCopy[scenario];
    function blockedAction() {
        if (scenario === 'network') refresh();
        else if (state?.action === 'consents') navigate('consents');
        else if (state) open(state.action as Modal);
    }
    function showHistory() {
        if (!historyFrom || !historyTo || historyTo < historyFrom) {
            setHistoryError(
                'Choose a complete period with the end on or after the start.',
            );
            return;
        }
        if (historyTo > '2026-09-20') {
            setHistoryError(
                'The synthetic history ends on 20 Sep 2026. Choose this date or earlier.',
            );
            return;
        }
        setHistoryError('');
        setHistoryLoaded(true);
    }
    const normalHistory =
        historyMode === 'ready' &&
        scenario !== 'empty' &&
        historyFrom <= '2026-09-20' &&
        historyTo >= '2026-09-20';
    return (
        <TooltipProvider>
            <div className="preview-bar">
                <strong>{VERSION}</strong>
                <span className="preview-tag">Synthetic desktop mockup</span>
                <span className="micro">No live data or record changes</span>
                <label htmlFor="scenario" className="micro ml-auto">
                    Review state
                </label>
                <select
                    id="scenario"
                    value={scenario}
                    onChange={(e) => changeScenario(e.target.value as Scenario)}
                >
                    {scenarios.map(([k, v]) => (
                        <option value={k} key={k}>
                            {v}
                        </option>
                    ))}
                </select>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => open('policy')}
                >
                    <CircleHelp className="size-4" />
                    Design notes
                </Button>
            </div>
            <header className="chrome">
                <div className="wordmark">
                    <EventHorizonWordmark />
                </div>
                <span className="chrome-date">
                    <strong>Sunday</strong> 20 September 2026
                </span>
                <button
                    className="command-search"
                    onClick={() => dest('Global command search')}
                >
                    <Search className="size-4" />
                    <span>Search or jump to…</span>
                    <kbd className="ml-auto">Ctrl K</kbd>
                </button>
                <div className="chrome-tools">
                    <Button size="sm" onClick={() => dest('Report incident')}>
                        <Plus className="size-4" />
                        <span className="incident-label">Report incident</span>
                    </Button>
                    <button
                        className="flex items-center gap-2 text-xs"
                        onClick={() => dest('Clock in')}
                    >
                        <Clock className="size-4" />
                        Clock in
                    </button>
                    <button
                        className="chrome-icon"
                        aria-label="Messages"
                        onClick={() => dest('Messages')}
                    >
                        <MessageSquare className="size-4" />
                    </button>
                    <button
                        className="chrome-icon"
                        aria-label="Notifications"
                        onClick={() => dest('Notifications')}
                    >
                        <Bell className="size-4" />
                    </button>
                    <button
                        className="chrome-avatar"
                        aria-label="Demo staff profile"
                        onClick={() => dest('Demo staff profile')}
                    >
                        JT
                    </button>
                </div>
            </header>
            <div className={`layout ${collapsed ? 'collapsed' : ''}`}>
                <aside className="sidebar" aria-label="Application navigation">
                    {[
                        [Home, 'My day'],
                        [Layers, 'Overview'],
                        [Clock, 'Today'],
                        [CalendarDays, 'My calendar'],
                        [ListTodo, 'All tasks'],
                    ].map(([Icon, label]: any) => (
                        <button
                            className="side-item"
                            key={label}
                            onClick={() => dest(label)}
                        >
                            <Icon />
                            <span className="side-label">{label}</span>
                        </button>
                    ))}
                    <div className="side-separator" />
                    {[
                        [Home, 'Sites & locations'],
                        [Users, 'Operations'],
                        [User, 'People & HR'],
                        [ShieldCheck, 'Compliance'],
                        [Shield, 'Incidents'],
                        [BookOpen, 'Governance'],
                        [Navigation, 'Fleet & assets'],
                        [Radio, 'Security & devices'],
                    ].map(([Icon, label]: any) => (
                        <React.Fragment key={label}>
                            <button
                                className="side-item"
                                onClick={() =>
                                    label === 'Operations'
                                        ? setOpsOpen(!opsOpen)
                                        : dest(label)
                                }
                                aria-expanded={
                                    label === 'Operations' ? opsOpen : undefined
                                }
                            >
                                <Icon />
                                <span className="side-label flex-1">
                                    {label}
                                </span>
                                {label === 'Operations' && (
                                    <ChevronDown className="side-label size-3" />
                                )}
                            </button>
                            {label === 'Operations' && opsOpen && (
                                <>
                                    <button
                                        className="side-item side-child active"
                                        onClick={() => navigate('location')}
                                    >
                                        Clients
                                    </button>
                                    <button
                                        className="side-item side-child"
                                        onClick={() => dest('Daily operations')}
                                    >
                                        Daily operations
                                    </button>
                                    <button
                                        className="side-item side-child"
                                        onClick={() => dest('Care planning')}
                                    >
                                        Care planning
                                    </button>
                                </>
                            )}
                        </React.Fragment>
                    ))}
                    <div className="side-separator" />
                    <button
                        className="side-item"
                        onClick={() => dest('Settings')}
                    >
                        <Settings />
                        <span className="side-label">Settings</span>
                    </button>
                    <button
                        className="side-collapse"
                        aria-label={
                            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
                        }
                        onClick={() => setCollapsed(!collapsed)}
                    >
                        {collapsed ? (
                            <ChevronRight className="size-3" />
                        ) : (
                            <ChevronLeft className="size-3" />
                        )}
                    </button>
                </aside>
                <main className="content">
                    <nav className="breadcrumbs" aria-label="Breadcrumb">
                        <button onClick={() => dest('Home')}>Home</button>
                        <ChevronRight className="size-3" />
                        <button onClick={() => dest('Clients')}>Clients</button>
                        <ChevronRight className="size-3" />
                        <span>Alex Hale</span>
                        <ChevronRight className="size-3" />
                        <strong>
                            {section === 'location' ? 'Location' : 'Consents'}
                        </strong>
                    </nav>
                    <PageHeader
                        variant="profile"
                        mark={
                            <div className="eh-mark-ring profile-mark">AH</div>
                        }
                        title="Alex Hale"
                        titleChip={
                            <PageHeaderStatusChip variant="success">
                                Active
                            </PageHeaderStatusChip>
                        }
                        subline={
                            <>
                                CL-DEMO-024 · Example House
                                <br />
                                Residential support · Synthetic profile
                            </>
                        }
                        actions={
                            <>
                                <PageHeaderSearchTrigger
                                    onClick={() => open('find')}
                                    placeholder="Find in this client…"
                                />
                                <PageHeaderGlassButton
                                    onClick={() =>
                                        dest('Client profile actions')
                                    }
                                    aria-label="Client profile actions"
                                >
                                    <SlidersHorizontal className="size-4" />
                                </PageHeaderGlassButton>
                            </>
                        }
                        meters={
                            <div className="grid w-full grid-cols-5 gap-2">
                                {[
                                    [
                                        'Next shift',
                                        '3:00 pm',
                                        'Jamie Taylor · Example House',
                                    ],
                                    [
                                        'Needs attention',
                                        '1',
                                        'A care review is due',
                                    ],
                                    [
                                        'Safety',
                                        'Care plan',
                                        'View current support guidance',
                                    ],
                                    [
                                        'Care plan goals',
                                        '2 of 3',
                                        'Recorded goal progress',
                                    ],
                                    [
                                        'Daily notes',
                                        '4',
                                        'Notes recorded today',
                                    ],
                                ].map(([label, value, caption], i) => (
                                    <PageHeaderMeterBlock
                                        key={label}
                                        label={label}
                                        tone={i === 1 ? 'warning' : 'brand'}
                                        onClick={() => dest(label)}
                                    >
                                        {i === 3 ? (
                                            <>
                                                <PageHeaderMeterBar
                                                    percent={67}
                                                />
                                                <span className="text-xs">
                                                    {value}
                                                </span>
                                            </>
                                        ) : (
                                            <PageHeaderMeterBig>
                                                {value}
                                            </PageHeaderMeterBig>
                                        )}
                                        <PageHeaderMeterCaption>
                                            {caption}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ))}
                            </div>
                        }
                        filters={
                            <button
                                className="flex h-[23px] items-center gap-1 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2 text-[11px]"
                                onClick={() => open('staff')}
                            >
                                <Eye className="size-3" />
                                {limited
                                    ? 'View-only staff'
                                    : denied
                                      ? 'Staff · location restricted'
                                      : 'Staff view'}
                                <ChevronDown className="size-3" />
                            </button>
                        }
                        rail={
                            <PageHeaderRail
                                items={groupItems}
                                value={
                                    section === 'location'
                                        ? 'snapshot'
                                        : 'governance'
                                }
                                onSelect={(k) =>
                                    k === 'snapshot'
                                        ? navigate('location')
                                        : k === 'governance'
                                          ? navigate('consents')
                                          : dest(
                                                groupItems.find(
                                                    (g) => g.key === k,
                                                )!.label,
                                            )
                                }
                                onFind={() => open('find')}
                            />
                        }
                    />
                    <div className="profile-subnav">
                        <TierTwoTabs
                            tabs={
                                section === 'location'
                                    ? snapshotTabs
                                    : consentTabs
                            }
                            activeTab={section}
                            onTab={(k) =>
                                k === 'location' || k === 'consents'
                                    ? navigate(k)
                                    : dest(
                                          [
                                              ...snapshotTabs,
                                              ...consentTabs,
                                          ].find((t) => t.key === k)!.label,
                                      )
                            }
                            panelId="client-section"
                            testIdPrefix="demo"
                            renderLink={(tab, className, inner, a11y) => (
                                <button
                                    key={tab.key}
                                    className={className}
                                    {...a11y}
                                    onClick={() =>
                                        tab.key === 'location' ||
                                        tab.key === 'consents'
                                            ? navigate(tab.key)
                                            : dest(tab.label)
                                    }
                                >
                                    {inner}
                                </button>
                            )}
                        />
                    </div>
                    <div
                        id="client-section"
                        role="tabpanel"
                        aria-labelledby={`demo-tab-${section}`}
                    >
                        {section === 'location' ? (
                            <>
                                <div className="section-heading">
                                    <div>
                                        <h2>Location</h2>
                                        <p>
                                            Last recorded observation, access
                                            and sharing for Alex.
                                        </p>
                                    </div>
                                    <div className="section-actions">
                                        {!denied && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    navigate('consents')
                                                }
                                            >
                                                <ShieldCheck className="size-4" />
                                                Open Consents
                                            </Button>
                                        )}
                                        <Button
                                            variant="outline"
                                            onClick={refresh}
                                            disabled={
                                                refreshing ||
                                                scenario === 'loading'
                                            }
                                        >
                                            <RefreshCw
                                                className={`size-4 ${refreshing ? 'animate-spin' : ''}`}
                                            />
                                            {refreshing
                                                ? 'Checking…'
                                                : 'Refresh view'}
                                        </Button>
                                    </div>
                                </div>
                                {notice && (
                                    <div className="mb-4" role="status">
                                        <Notice>{notice}</Notice>
                                    </div>
                                )}
                                <div className="access-strip">
                                    <div className="access-cell">
                                        <div className="access-label">
                                            <ShieldCheck className="size-3.5" />
                                            Collection authority
                                        </div>
                                        <StatusBadge
                                            variant={
                                                collectionBad
                                                    ? 'warning'
                                                    : denied ||
                                                        scenario ===
                                                            'network' ||
                                                        scenario === 'loading'
                                                      ? 'neutral'
                                                      : 'success'
                                            }
                                        >
                                            {collectionLabel}
                                        </StatusBadge>
                                        <p>
                                            {denied
                                                ? 'Sensitive decision details are restricted.'
                                                : scenario === 'no-device'
                                                  ? 'A decision alone does not assign a device.'
                                                  : collectionBad
                                                    ? 'Review the applicable canonical decision.'
                                                    : 'Bound to the current client, purpose and assignment.'}
                                        </p>
                                        {!denied && (
                                            <LinkButton
                                                onClick={() =>
                                                    open('collection')
                                                }
                                            >
                                                View decision
                                            </LinkButton>
                                        )}
                                    </div>
                                    <div className="access-cell">
                                        <div className="access-label">
                                            <Eye className="size-3.5" />
                                            Your staff access
                                        </div>
                                        <StatusBadge
                                            variant={
                                                denied
                                                    ? 'critical'
                                                    : scenario === 'loading' ||
                                                        scenario === 'network'
                                                      ? 'neutral'
                                                      : 'success'
                                            }
                                        >
                                            {denied
                                                ? 'Not permitted'
                                                : scenario === 'loading'
                                                  ? 'Checking'
                                                  : scenario === 'network'
                                                    ? 'Not verified'
                                                    : 'Permitted for this client'}
                                        </StatusBadge>
                                        <p>
                                            {limited
                                                ? 'You can view location. Management and export are restricted.'
                                                : 'Staff role, approved site and location permission are checked separately.'}
                                        </p>
                                        <LinkButton
                                            onClick={() => open('staff')}
                                        >
                                            View access
                                        </LinkButton>
                                    </div>
                                    <div className="access-cell">
                                        <div className="access-label">
                                            <Users className="size-3.5" />
                                            Recipient sharing
                                        </div>
                                        <StatusBadge
                                            variant={
                                                denied
                                                    ? 'neutral'
                                                    : grant
                                                      ? 'success'
                                                      : shareEnded
                                                        ? 'warning'
                                                        : 'neutral'
                                            }
                                        >
                                            {denied
                                                ? 'Details restricted'
                                                : shareLabel}
                                        </StatusBadge>
                                        <p>
                                            {denied
                                                ? 'No recipient or grant details are disclosed.'
                                                : 'A portal relationship or tracking decision does not grant sharing.'}
                                        </p>
                                        {!denied && (
                                            <LinkButton
                                                onClick={() => open('sharing')}
                                            >
                                                Review sharing
                                            </LinkButton>
                                        )}
                                    </div>
                                </div>
                                {hidden ? (
                                    <Panel
                                        title="Location access"
                                        icon={LockKeyhole}
                                    >
                                        <div className="state-empty">
                                            <div>
                                                <div className="state-icon">
                                                    {scenario === 'loading' ? (
                                                        <RefreshCw className="size-6 animate-spin" />
                                                    ) : scenario ===
                                                      'network' ? (
                                                        <WifiOff className="size-6" />
                                                    ) : (
                                                        <ShieldOff className="size-6" />
                                                    )}
                                                </div>
                                                <h3>{state.title}</h3>
                                                <p>{state.body}</p>
                                                {state.cta && (
                                                    <Button
                                                        onClick={blockedAction}
                                                        disabled={refreshing}
                                                    >
                                                        {state.cta}
                                                        <ArrowRight className="size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                        {scenario === 'loading' && (
                                            <div className="flex gap-3 px-8 pb-8">
                                                <div className="skeleton h-14 flex-1" />
                                                <div className="skeleton h-14 flex-1" />
                                                <div className="skeleton h-14 flex-1" />
                                            </div>
                                        )}
                                    </Panel>
                                ) : (
                                    <>
                                        <div className="location-grid">
                                            <div className="stack">
                                                <Panel
                                                    title="Last observation"
                                                    icon={MapPin}
                                                    sub={
                                                        scenario === 'empty' ||
                                                        scenario ===
                                                            'unavailable'
                                                            ? `View checked ${checked} · Pacific/Auckland`
                                                            : `Observed 20 Sep 2026 · ${stale ? '11:10 am' : '2:32 pm'} NZST (UTC+12)`
                                                    }
                                                    action={
                                                        <StatusBadge
                                                            variant={
                                                                stale
                                                                    ? 'warning'
                                                                    : scenario ===
                                                                        'unavailable'
                                                                      ? 'warning'
                                                                      : 'neutral'
                                                            }
                                                        >
                                                            {stale
                                                                ? 'Older observation'
                                                                : scenario ===
                                                                    'unavailable'
                                                                  ? 'Unavailable'
                                                                  : scenario ===
                                                                      'empty'
                                                                    ? 'No observations'
                                                                    : 'Recorded observation'}
                                                        </StatusBadge>
                                                    }
                                                >
                                                    {scenario === 'empty' ||
                                                    scenario ===
                                                        'unavailable' ? (
                                                        <div className="state-empty">
                                                            <div>
                                                                <div className="state-icon">
                                                                    {scenario ===
                                                                    'empty' ? (
                                                                        <MapPin className="size-6" />
                                                                    ) : (
                                                                        <WifiOff className="size-6" />
                                                                    )}
                                                                </div>
                                                                <h3>
                                                                    {scenario ===
                                                                    'empty'
                                                                        ? 'No observations have been received'
                                                                        : 'A location observation is unavailable'}
                                                                </h3>
                                                                <p>
                                                                    {scenario ===
                                                                    'empty'
                                                                        ? 'Access is authorised, but the current assignment has no observation to show.'
                                                                        : 'The tracker is not providing an available location. This view cannot establish Alex’s present location.'}
                                                                </p>
                                                                <Button
                                                                    variant="outline"
                                                                    onClick={() =>
                                                                        open(
                                                                            'assignment',
                                                                        )
                                                                    }
                                                                >
                                                                    View
                                                                    tracking
                                                                    assignment
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <>
                                                            <SyntheticMap
                                                                stale={stale}
                                                                accuracyKnown={
                                                                    scenario !==
                                                                    'accuracy'
                                                                }
                                                            />
                                                            <div className="observation-summary">
                                                                <p className="micro mb-2">
                                                                    View checked{' '}
                                                                    {checked} ·
                                                                    this does
                                                                    not change
                                                                    the
                                                                    observation
                                                                    time
                                                                </p>
                                                                <div className="flex items-start justify-between gap-4">
                                                                    <div>
                                                                        <h4>
                                                                            Example
                                                                            Gardens
                                                                        </h4>
                                                                        <p className="subtle">
                                                                            Observed
                                                                            20
                                                                            Sep
                                                                            2026
                                                                            ·{' '}
                                                                            {stale
                                                                                ? '11:10 am'
                                                                                : '2:32 pm'}{' '}
                                                                            NZST
                                                                            (UTC+12)
                                                                        </p>
                                                                    </div>
                                                                    <span className="text-xs font-medium whitespace-nowrap">
                                                                        {stale
                                                                            ? checked ===
                                                                              '2:36 pm'
                                                                                ? '3 hr 26 min ago'
                                                                                : '3 hr 25 min ago'
                                                                            : checked ===
                                                                                '2:36 pm'
                                                                              ? '4 min ago'
                                                                              : '3 min ago'}
                                                                    </span>
                                                                </div>
                                                                <div className="fact-grid">
                                                                    <div>
                                                                        <span className="eyebrow">
                                                                            Reported
                                                                            accuracy
                                                                        </span>
                                                                        <strong>
                                                                            {scenario ===
                                                                            'accuracy'
                                                                                ? 'Unknown'
                                                                                : '± 24 m'}
                                                                        </strong>
                                                                    </div>
                                                                    <div>
                                                                        <span className="eyebrow">
                                                                            Location
                                                                            source
                                                                        </span>
                                                                        <strong>
                                                                            Tracker
                                                                            T-DEMO-08
                                                                        </strong>
                                                                    </div>
                                                                    <div>
                                                                        <span className="eyebrow">
                                                                            Received
                                                                        </span>
                                                                        <strong>
                                                                            {stale
                                                                                ? '11:10 am'
                                                                                : '2:32 pm'}
                                                                        </strong>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </>
                                                    )}
                                                    <div className="px-[18px] pb-[18px]">
                                                        <Notice
                                                            tone={
                                                                stale
                                                                    ? 'warning'
                                                                    : 'neutral'
                                                            }
                                                            icon={Info}
                                                        >
                                                            {stale ? (
                                                                <>
                                                                    <strong>
                                                                        This is
                                                                        an older
                                                                        observation
                                                                    </strong>
                                                                    It does not
                                                                    show Alex’s
                                                                    current
                                                                    position.
                                                                    Use the
                                                                    current
                                                                    support plan
                                                                    for the
                                                                    appropriate
                                                                    next action.
                                                                </>
                                                            ) : (
                                                                'An observation shows where the device reported. It does not establish Alex’s wellbeing or present position.'
                                                            )}
                                                        </Notice>
                                                    </div>
                                                </Panel>
                                            </div>
                                            <div className="stack">
                                                <Panel
                                                    title="Tracking assignment"
                                                    icon={Radio}
                                                    action={
                                                        <LinkButton
                                                            onClick={() =>
                                                                open(
                                                                    'assignment',
                                                                )
                                                            }
                                                        >
                                                            View
                                                        </LinkButton>
                                                    }
                                                >
                                                    <div className="panel-body">
                                                        <div className="recipient mb-3">
                                                            <div className="recipient-avatar">
                                                                <Radio className="size-5" />
                                                            </div>
                                                            <div>
                                                                <strong className="text-sm">
                                                                    Personal
                                                                    tracker ·
                                                                    T-DEMO-08
                                                                </strong>
                                                                <p className="micro">
                                                                    Assignment
                                                                    A-DEMO-052 ·
                                                                    Alex Hale
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <dl>
                                                            <KV label="Registry status">
                                                                Active
                                                            </KV>
                                                            <KV label="Last device contact">
                                                                {scenario ===
                                                                'unavailable'
                                                                    ? '19 Sep · 5:42 pm'
                                                                    : '20 Sep · ' +
                                                                      (stale
                                                                          ? '11:10 am'
                                                                          : '2:32 pm')}
                                                            </KV>
                                                            <KV label="Battery sample">
                                                                {scenario ===
                                                                    'accuracy' ||
                                                                scenario ===
                                                                    'unavailable'
                                                                    ? 'Unknown'
                                                                    : '68% · ' +
                                                                      (stale
                                                                          ? '11:10 am'
                                                                          : '2:32 pm')}
                                                            </KV>
                                                            <KV label="Freshness rule">
                                                                Awaiting
                                                                approved rule
                                                            </KV>
                                                        </dl>
                                                        <p className="micro mt-3">
                                                            An active register
                                                            entry does not mean
                                                            the device is
                                                            online. All times:
                                                            Pacific/Auckland.
                                                        </p>
                                                        {!limited && (
                                                            <div className="card-actions">
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        open(
                                                                            'request',
                                                                        )
                                                                    }
                                                                >
                                                                    <Radio className="size-4" />
                                                                    Request
                                                                    observation
                                                                </Button>
                                                            </div>
                                                        )}
                                                        {limited && (
                                                            <p className="micro mt-3">
                                                                Device
                                                                management
                                                                requires
                                                                additional
                                                                permission.
                                                            </p>
                                                        )}
                                                    </div>
                                                </Panel>
                                                <Panel
                                                    title="Recipient sharing"
                                                    icon={Users}
                                                >
                                                    <div className="panel-body">
                                                        {grant || shareEnded ? (
                                                            <div className="recipient">
                                                                <div className="recipient-avatar">
                                                                    MH
                                                                </div>
                                                                <div>
                                                                    <strong className="text-sm">
                                                                        Mara
                                                                        Hale
                                                                    </strong>
                                                                    <p className="micro">
                                                                        Named
                                                                        portal
                                                                        account
                                                                        ·
                                                                        R-DEMO-01
                                                                    </p>
                                                                    <div className="mt-2">
                                                                        <StatusBadge
                                                                            variant={
                                                                                grant
                                                                                    ? 'success'
                                                                                    : 'warning'
                                                                            }
                                                                        >
                                                                            {grant
                                                                                ? 'Illustrative active grant'
                                                                                : scenario ===
                                                                                    'share-expired'
                                                                                  ? 'Expired · no access'
                                                                                  : 'Withdrawn · no access'}
                                                                        </StatusBadge>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        ) : (
                                                            <>
                                                                <strong className="text-sm">
                                                                    {reviewPrepared
                                                                        ? 'Review prepared; no access granted'
                                                                        : 'No recipient can see this location'}
                                                                </strong>
                                                                <p className="subtle mt-2">
                                                                    A separate,
                                                                    valid
                                                                    sharing
                                                                    decision is
                                                                    required for
                                                                    each named
                                                                    recipient.
                                                                </p>
                                                            </>
                                                        )}
                                                        <div className="card-actions">
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    open(
                                                                        'sharing',
                                                                    )
                                                                }
                                                            >
                                                                View sharing
                                                                review
                                                                <ArrowRight className="size-4" />
                                                            </Button>
                                                        </div>
                                                        <p className="micro mt-3">
                                                            Client self-access
                                                            follows a separate
                                                            decision. It is not
                                                            a family sharing
                                                            grant.
                                                        </p>
                                                    </div>
                                                </Panel>
                                            </div>
                                        </div>
                                        <Panel
                                            className="history"
                                            title="Observation history"
                                            icon={History}
                                            sub="History remains subject to the same current access and assignment checks."
                                            action={
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        setHistoryOpen(
                                                            !historyOpen,
                                                        );
                                                        setHistoryLoaded(false);
                                                    }}
                                                    aria-expanded={historyOpen}
                                                >
                                                    {historyOpen
                                                        ? 'Hide history'
                                                        : 'View history'}
                                                    <ChevronDown className="size-4" />
                                                </Button>
                                            }
                                        >
                                            {historyOpen && (
                                                <>
                                                    <div className="history-filter">
                                                        <div>
                                                            <label htmlFor="history-from">
                                                                From
                                                            </label>
                                                            <DatePicker
                                                                id="history-from"
                                                                label="History from"
                                                                value={
                                                                    historyFrom
                                                                }
                                                                onChange={
                                                                    setHistoryFrom
                                                                }
                                                            />
                                                        </div>
                                                        <div>
                                                            <label htmlFor="history-to">
                                                                To
                                                            </label>
                                                            <DatePicker
                                                                id="history-to"
                                                                label="History to"
                                                                value={
                                                                    historyTo
                                                                }
                                                                onChange={
                                                                    setHistoryTo
                                                                }
                                                            />
                                                        </div>
                                                        <Button
                                                            onClick={
                                                                showHistory
                                                            }
                                                        >
                                                            Show observations
                                                        </Button>
                                                    </div>
                                                    {historyError && (
                                                        <p
                                                            role="alert"
                                                            className="field-error px-5 pb-3"
                                                        >
                                                            {historyError}
                                                        </p>
                                                    )}
                                                    <div className="flex flex-wrap items-center gap-3 px-5 py-3">
                                                        <span className="micro">
                                                            Pacific/Auckland ·
                                                            local calendar dates
                                                        </span>
                                                        <label
                                                            className="micro ml-auto"
                                                            htmlFor="history-demo"
                                                        >
                                                            Demo history
                                                            response
                                                        </label>
                                                        <select
                                                            id="history-demo"
                                                            value={historyMode}
                                                            onChange={(e) => {
                                                                if (
                                                                    e.target
                                                                        .value ===
                                                                    'ended'
                                                                ) {
                                                                    changeScenario(
                                                                        'withdrawn',
                                                                    );
                                                                    return;
                                                                }
                                                                setHistoryMode(
                                                                    e.target
                                                                        .value,
                                                                );
                                                                setHistoryLoaded(
                                                                    true,
                                                                );
                                                            }}
                                                            className="rounded border px-2 py-1 text-xs"
                                                        >
                                                            <option value="ready">
                                                                Observations
                                                            </option>
                                                            <option value="empty">
                                                                No observations
                                                            </option>
                                                            <option value="loading">
                                                                Loading
                                                            </option>
                                                            <option value="error">
                                                                Network failure
                                                            </option>
                                                            <option value="ended">
                                                                Access ended
                                                            </option>
                                                        </select>
                                                    </div>
                                                    {historyLoaded &&
                                                        (historyMode ===
                                                        'loading' ? (
                                                            <div
                                                                className="p-5"
                                                                role="status"
                                                            >
                                                                Loading
                                                                observations…
                                                            </div>
                                                        ) : historyMode ===
                                                          'error' ? (
                                                            <div className="p-5">
                                                                <Notice tone="critical">
                                                                    <strong>
                                                                        Observation
                                                                        history
                                                                        could
                                                                        not be
                                                                        loaded
                                                                    </strong>
                                                                    This is not
                                                                    an empty
                                                                    result. Your
                                                                    date range
                                                                    has been
                                                                    kept.
                                                                </Notice>
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="mt-3"
                                                                    onClick={() =>
                                                                        setHistoryMode(
                                                                            'ready',
                                                                        )
                                                                    }
                                                                >
                                                                    Retry
                                                                    history
                                                                </Button>
                                                            </div>
                                                        ) : historyMode ===
                                                          'ended' ? (
                                                            <div className="p-5">
                                                                <Notice tone="warning">
                                                                    <strong>
                                                                        Location
                                                                        access
                                                                        has
                                                                        ended
                                                                    </strong>
                                                                    Map, history
                                                                    and export
                                                                    are now
                                                                    hidden.
                                                                </Notice>
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="mt-3"
                                                                    onClick={() =>
                                                                        changeScenario(
                                                                            'withdrawn',
                                                                        )
                                                                    }
                                                                >
                                                                    View
                                                                    ended-access
                                                                    state
                                                                </Button>
                                                            </div>
                                                        ) : normalHistory ? (
                                                            <>
                                                                {(stale
                                                                    ? [
                                                                          [
                                                                              '11:10 am',
                                                                              'Example Gardens',
                                                                              '± 24 m',
                                                                          ],
                                                                          [
                                                                              '10:56 am',
                                                                              'Demo Walkway',
                                                                              '± 31 m',
                                                                          ],
                                                                      ]
                                                                    : [
                                                                          [
                                                                              '2:32 pm',
                                                                              'Example Gardens',
                                                                              '± 24 m',
                                                                          ],
                                                                          [
                                                                              '2:18 pm',
                                                                              'Demo Walkway',
                                                                              '± 31 m',
                                                                          ],
                                                                          [
                                                                              '2:05 pm',
                                                                              'Example House',
                                                                              'Accuracy unknown',
                                                                          ],
                                                                      ]
                                                                ).map(
                                                                    ([
                                                                        time,
                                                                        place,
                                                                        accuracy,
                                                                    ]) => (
                                                                        <div
                                                                            className="history-row"
                                                                            key={
                                                                                time
                                                                            }
                                                                        >
                                                                            <strong>
                                                                                {
                                                                                    time
                                                                                }
                                                                                <small>
                                                                                    20
                                                                                    Sep
                                                                                    2026
                                                                                    ·
                                                                                    NZST
                                                                                </small>
                                                                            </strong>
                                                                            <div>
                                                                                {
                                                                                    place
                                                                                }
                                                                                <small>
                                                                                    Device
                                                                                    T-DEMO-08
                                                                                    ·
                                                                                    assignment
                                                                                    A-DEMO-052
                                                                                </small>
                                                                            </div>
                                                                            <span>
                                                                                {
                                                                                    accuracy
                                                                                }
                                                                            </span>
                                                                        </div>
                                                                    ),
                                                                )}
                                                                <div className="flex items-center justify-between p-4">
                                                                    <span className="micro">
                                                                        Synthetic
                                                                        history
                                                                        · no
                                                                        continuous
                                                                        route
                                                                        inferred
                                                                    </span>
                                                                    {!limited ? (
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            onClick={() =>
                                                                                open(
                                                                                    'export',
                                                                                )
                                                                            }
                                                                        >
                                                                            Review
                                                                            export
                                                                            access
                                                                        </Button>
                                                                    ) : (
                                                                        <span className="micro">
                                                                            Export
                                                                            permission
                                                                            required
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </>
                                                        ) : (
                                                            <div className="p-8 text-center">
                                                                <MapPin className="mx-auto mb-3 size-6 text-muted-foreground" />
                                                                <strong className="text-sm">
                                                                    No
                                                                    observations
                                                                    in this
                                                                    period
                                                                </strong>
                                                                <p className="micro mt-2">
                                                                    This does
                                                                    not
                                                                    establish
                                                                    anyone’s
                                                                    location or
                                                                    wellbeing.
                                                                </p>
                                                            </div>
                                                        ))}
                                                </>
                                            )}
                                        </Panel>
                                    </>
                                )}
                            </>
                        ) : (
                            <>
                                <div className="section-heading">
                                    <div>
                                        <h2>Consents · location context</h2>
                                        <p>
                                            Existing canonical decisions and
                                            their connection to Location.
                                        </p>
                                    </div>
                                    <Button
                                        variant="outline"
                                        onClick={() => navigate('location')}
                                    >
                                        <ArrowLeft className="size-4" />
                                        Return to Location
                                    </Button>
                                </div>
                                <div className="governance-grid">
                                    <Panel
                                        title="Relevant decisions"
                                        icon={FileCheck2}
                                        sub="Synthetic subset of the existing Consents view"
                                    >
                                        <div className="decision-row">
                                            <ShieldCheck className="mt-1 size-5 text-primary" />
                                            <div className="flex-1">
                                                <strong className="text-sm">
                                                    {scenario === 'missing'
                                                        ? 'No linked collection decision'
                                                        : scenario === 'purpose'
                                                          ? 'Photography permission'
                                                          : 'Collection for supported community outings'}
                                                </strong>
                                                <p className="micro mt-1">
                                                    {scenario === 'missing'
                                                        ? 'Collection authority needs review'
                                                        : scenario ===
                                                            'no-device'
                                                          ? 'C-DEMO-014 · no current tracking assignment'
                                                          : scenario ===
                                                              'reassigned'
                                                            ? 'C-DEMO-014 · assignment needs revalidation'
                                                            : 'C-DEMO-014 · linked to assignment A-DEMO-052'}
                                                </p>
                                                <div className="mt-2">
                                                    <StatusBadge
                                                        variant={
                                                            collectionBad
                                                                ? 'warning'
                                                                : 'success'
                                                        }
                                                    >
                                                        {collectionLabel}
                                                    </StatusBadge>
                                                </div>
                                                <div className="mt-3">
                                                    <LinkButton
                                                        onClick={() =>
                                                            open('collection')
                                                        }
                                                    >
                                                        View decision and
                                                        evidence
                                                    </LinkButton>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="decision-row">
                                            <Users className="mt-1 size-5 text-primary" />
                                            <div className="flex-1">
                                                <strong className="text-sm">
                                                    Disclosure to a named
                                                    recipient
                                                </strong>
                                                <p className="micro mt-1">
                                                    Independent of the
                                                    collection decision and
                                                    family relationship
                                                </p>
                                                <div className="mt-2">
                                                    <StatusBadge
                                                        variant={
                                                            grant
                                                                ? 'success'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {shareLabel}
                                                    </StatusBadge>
                                                </div>
                                                <div className="mt-3">
                                                    <LinkButton
                                                        onClick={() =>
                                                            open('sharing')
                                                        }
                                                    >
                                                        Review recipient sharing
                                                    </LinkButton>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="p-4">
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    dest(
                                                        'Manage Consents — canonical register',
                                                    )
                                                }
                                            >
                                                Manage Consents
                                                <ArrowUpRight className="size-4" />
                                            </Button>
                                        </div>
                                    </Panel>
                                    <div className="stack">
                                        <Panel
                                            title="Connected location"
                                            icon={MapPin}
                                        >
                                            <div className="panel-body">
                                                <p className="subtle">
                                                    Collection authority, staff
                                                    viewing and recipient
                                                    disclosure are evaluated
                                                    separately. Updating one
                                                    does not approve the others.
                                                </p>
                                                <div className="card-actions">
                                                    <Button
                                                        onClick={() =>
                                                            navigate('location')
                                                        }
                                                    >
                                                        Open Location
                                                        <ArrowRight className="size-4" />
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        onClick={() =>
                                                            open('assignment')
                                                        }
                                                    >
                                                        View assignment
                                                    </Button>
                                                </div>
                                            </div>
                                        </Panel>
                                        <Notice tone="info">
                                            This destination is a contextual
                                            synthetic preview. The wider consent
                                            register, recording workflow and
                                            evidence store remain canonical.
                                        </Notice>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                    <footer className="prototype-footer">
                        <span>
                            All people, places, references and events are
                            fictional.
                        </span>
                        <span>{VERSION} · baseline 2302ca3 · design only</span>
                    </footer>
                </main>
            </div>
            {modal === 'share-review' && (
                <SharingWizard
                    open
                    existing={grant}
                    onClose={close}
                    onDone={() => setReviewPrepared(true)}
                    returnFocus={() => opener.current?.focus()}
                />
            )}
            <Dialog
                open={modal !== 'none' && modal !== 'share-review'}
                onOpenChange={(o) => !o && close()}
            >
                <DialogContent
                    style={{
                        width:
                            modal === 'find'
                                ? 'min(92vw,720px)'
                                : 'min(92vw,720px)',
                        maxWidth: 'min(92vw,720px)',
                        maxHeight: '88vh',
                        overflowY: 'auto',
                    }}
                    onCloseAutoFocus={(e) => {
                        e.preventDefault();
                        opener.current?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {
                                (
                                    {
                                        collection: 'Collection decision',
                                        staff: 'Your location access',
                                        assignment:
                                            'Canonical tracking assignment',
                                        sharing: 'Recipient sharing review',
                                        withdraw:
                                            'Withdraw this recipient’s sharing?',
                                        export: 'Location export',
                                        destination,
                                        find: 'Find in Alex’s profile',
                                        policy: 'Design boundaries and open decisions',
                                        request:
                                            'Request a location observation',
                                    } as any
                                )[modal]
                            }
                        </DialogTitle>
                        <DialogDescription>
                            {modal === 'find'
                                ? 'Jump to the bounded Location design or its consent context.'
                                : 'Synthetic preview · no live record, grant or device is changed.'}
                        </DialogDescription>
                    </DialogHeader>
                    {modal === 'collection' && (
                        <div className="dialog-stack">
                            <Notice
                                tone={collectionBad ? 'warning' : 'info'}
                                icon={ShieldCheck}
                            >
                                <strong>{collectionLabel}</strong>
                                {scenario === 'purpose'
                                    ? 'The linked decision concerns photographs, not resident location.'
                                    : scenario === 'withdrawn'
                                      ? 'Decision withdrawn at 1:50 pm on 20 Sep 2026. Collection and future disclosure stop under the illustrated decision.'
                                      : 'The applicable decision must match the client, purpose, current type/version, authority evidence and assignment.'}
                            </Notice>
                            {scenario !== 'missing' && (
                                <dl>
                                    <KV label="Decision">C-DEMO-014</KV>
                                    <KV label="Purpose">
                                        {scenario === 'purpose'
                                            ? 'Photography permission'
                                            : 'Supported community outings'}
                                    </KV>
                                    <KV label="Decision maker">
                                        Alex Hale · client decision
                                    </KV>
                                    <KV label="Type / version">
                                        {scenario === 'purpose'
                                            ? 'Photography · demo version 1'
                                            : 'Resident location · demo version 3'}
                                    </KV>
                                    <KV label="Evidence reference">
                                        E-DEMO-14 · synthetic evidence
                                    </KV>
                                    <KV label="Assignment">
                                        {scenario === 'no-device'
                                            ? 'No current assignment'
                                            : scenario === 'reassigned'
                                              ? 'Previous assignment; revalidation required'
                                              : 'A-DEMO-052 · T-DEMO-08'}
                                    </KV>
                                    <KV label="Review due">
                                        {scenario === 'expired'
                                            ? '19 Sep 2026 · 3:00 pm NZST'
                                            : '24 Sep 2026 · illustrative date'}
                                    </KV>
                                    <KV label="Decision ends">
                                        {scenario === 'expired'
                                            ? '19 Sep 2026 · 5:00 pm NZST'
                                            : '30 Sep 2026 · illustrative date'}
                                    </KV>
                                    <KV label="Retention">
                                        Requires approved policy
                                    </KV>
                                </dl>
                            )}
                            {scenario === 'missing' && (
                                <p className="subtle">
                                    No applicable decision or evidence is linked
                                    to this assignment. Review the canonical
                                    Consents register before collecting or
                                    disclosing observations.
                                </p>
                            )}
                            <Notice>
                                A collection decision does not supply your staff
                                permission or a grant for Mara, Leon or another
                                recipient.
                            </Notice>
                            <Button onClick={() => navigate('consents')}>
                                Open Consents
                                <ArrowRight className="size-4" />
                            </Button>
                        </div>
                    )}
                    {modal === 'staff' && (
                        <div className="dialog-stack">
                            <Notice
                                tone={denied ? 'warning' : 'info'}
                                icon={Eye}
                            >
                                <strong>
                                    {denied
                                        ? 'Location access not permitted'
                                        : limited
                                          ? 'Location viewing only'
                                          : 'Staff view for Alex Hale'}
                                </strong>
                                {denied
                                    ? 'No consent evidence, device details, observations or sharing audience are disclosed to this viewer.'
                                    : 'This synthetic actor has client access at Example House and location viewing permission for the illustrated purpose.'}
                            </Notice>
                            <dl>
                                <KV label="Actor">
                                    Jamie Taylor · synthetic staff
                                </KV>
                                <KV label="Client profile">Permitted</KV>
                                <KV label="Location viewing">
                                    {denied
                                        ? 'Not permitted'
                                        : scenario === 'network'
                                          ? 'Not verified'
                                          : 'Permitted'}
                                </KV>
                                <KV label="Device management">
                                    {denied || limited
                                        ? 'Not permitted'
                                        : 'Illustrative permission'}
                                </KV>
                                <KV label="Location export">
                                    {denied || limited
                                        ? 'Not permitted'
                                        : 'Separate governed review'}
                                </KV>
                                <KV label="Recipient grant">
                                    Not supplied by staff permission
                                </KV>
                            </dl>
                            <p className="subtle">
                                If access is needed for your work, follow the
                                organisation’s access review process. No generic
                                emergency bypass is available.
                            </p>
                            {scenario === 'self' && (
                                <Notice tone="warning">
                                    <strong>
                                        Client self-access remains distinct
                                    </strong>
                                    A verified client identity and the
                                    applicable self-access decision must be
                                    checked separately. No family sharing grant
                                    is inferred.
                                </Notice>
                            )}
                        </div>
                    )}
                    {modal === 'assignment' && (
                        <div className="dialog-stack">
                            <Notice
                                tone={
                                    scenario === 'no-device' ||
                                    scenario === 'reassigned'
                                        ? 'warning'
                                        : 'info'
                                }
                                icon={Radio}
                            >
                                <strong>
                                    {scenario === 'no-device'
                                        ? 'No current tracking assignment'
                                        : scenario === 'reassigned'
                                          ? 'Assignment changed — review required'
                                          : 'Assignment A-DEMO-052'}
                                </strong>
                                {scenario === 'reassigned'
                                    ? 'Previous T-DEMO-08 data cannot follow the new assignment. New assignment details await authorised resolution.'
                                    : 'Canonical DeviceAssignment owns the device-to-client link. Collection needs the applicable current decision.'}
                            </Notice>
                            {scenario !== 'no-device' &&
                                scenario !== 'reassigned' && (
                                    <dl>
                                        <KV label="Device">
                                            T-DEMO-08 · personal tracker
                                        </KV>
                                        <KV label="Assigned to">
                                            Alex Hale · CL-DEMO-024
                                        </KV>
                                        <KV label="Approved site">
                                            Example House
                                        </KV>
                                        <KV label="Linked decision">
                                            C-DEMO-014
                                        </KV>
                                        <KV label="Collection purpose">
                                            Supported community outings
                                        </KV>
                                        <KV label="Assignment starts">
                                            18 Sep 2026 · 9:00 am NZST
                                        </KV>
                                    </dl>
                                )}
                            <Notice>
                                Assignment review belongs to the canonical
                                device record. This preview does not assign,
                                release, resume collection or reassign a device.
                            </Notice>
                            {!limited && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        dest(
                                            'Security & Devices — assignment destination',
                                        )
                                    }
                                >
                                    Open device assignment
                                    <ArrowUpRight className="size-4" />
                                </Button>
                            )}
                            {limited && (
                                <p className="micro">
                                    You can view the permitted summary. Device
                                    management requires additional permission.
                                </p>
                            )}
                        </div>
                    )}
                    {modal === 'sharing' && (
                        <div className="dialog-stack">
                            <Notice
                                tone={grant ? 'info' : 'warning'}
                                icon={Users}
                            >
                                <strong>
                                    {grant
                                        ? 'One illustrative named grant'
                                        : shareEnded
                                          ? shareLabel
                                          : reviewPrepared
                                            ? 'Review prepared — no grant'
                                            : 'No recipient sharing grant'}
                                </strong>
                                {grant
                                    ? 'This example shows how an independently approved, time-bounded grant would be presented. It does not establish approved policy.'
                                    : 'Tracking authority or a portal relationship cannot substitute for an independent sharing decision.'}
                            </Notice>
                            {(grant || shareEnded) && (
                                <>
                                    <div className="recipient">
                                        <div className="recipient-avatar">
                                            MH
                                        </div>
                                        <div>
                                            <strong>Mara Hale</strong>
                                            <p className="micro">
                                                R-DEMO-01 · named portal account
                                            </p>
                                        </div>
                                    </div>
                                    <dl>
                                        <KV label="Purpose">
                                            Agreed community outing check-in
                                        </KV>
                                        <KV label="Scope">
                                            Latest observation only
                                        </KV>
                                        <KV label="History / export / messages">
                                            Not granted
                                        </KV>
                                        <KV label="Decision / evidence">
                                            C-DEMO-017 / E-DEMO-17
                                        </KV>
                                        <KV label="Starts">
                                            {scenario === 'share-expired'
                                                ? '20 Sep 2026 · 9:00 am NZST'
                                                : '20 Sep 2026 · 1:00 pm NZST'}
                                        </KV>
                                        <KV label="Ends">
                                            {scenario === 'share-expired'
                                                ? '20 Sep 2026 · 1:00 pm NZST'
                                                : '20 Sep 2026 · 5:00 pm NZST'}
                                        </KV>
                                        <KV label="Review">
                                            {scenario === 'share-expired'
                                                ? '20 Sep 2026 · 12:00 pm NZST'
                                                : '20 Sep 2026 · 3:00 pm NZST'}
                                        </KV>
                                        <KV label="State">{shareLabel}</KV>
                                    </dl>
                                    <p className="micro">
                                        All times and scope values above are
                                        illustrative, not default policy.
                                    </p>
                                </>
                            )}
                            <Notice icon={User}>
                                <strong>Alex’s own access is separate</strong>
                                Self-access needs its own identity and
                                applicable access checks. This recipient review
                                neither grants nor removes Alex’s self-access.
                            </Notice>
                            {!limited ? (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        onClick={() => setModal('share-review')}
                                    >
                                        {grant
                                            ? 'Review this recipient'
                                            : 'Review a recipient'}
                                        <ArrowRight className="size-4" />
                                    </Button>
                                    {grant && (
                                        <Button
                                            variant="destructive"
                                            onClick={() => {
                                                setWithdrawReason('');
                                                setModal('withdraw');
                                            }}
                                        >
                                            Withdraw sharing
                                        </Button>
                                    )}
                                </div>
                            ) : (
                                <p className="subtle">
                                    You can see the permitted summary. Reviewing
                                    or withdrawing sharing requires additional
                                    permission.
                                </p>
                            )}
                        </div>
                    )}
                    {modal === 'withdraw' && (
                        <div className="dialog-stack">
                            <Notice tone="warning">
                                <strong>
                                    Mara Hale · latest observation only
                                </strong>
                                Withdrawal ends this recipient’s future access
                                and queued disclosure. It does not withdraw
                                collection authority or delete retained
                                evidence.
                            </Notice>
                            <div>
                                <label
                                    className="field-label"
                                    htmlFor="withdraw-reason"
                                >
                                    Reason for withdrawal{' '}
                                    <span className="required">*</span>
                                </label>
                                <Textarea
                                    id="withdraw-reason"
                                    value={withdrawReason}
                                    onChange={(e) =>
                                        setWithdrawReason(e.target.value)
                                    }
                                    aria-invalid={!!withdrawError}
                                />
                                {withdrawError && (
                                    <p className="field-error" role="alert">
                                        {withdrawError}
                                    </p>
                                )}
                            </div>
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setModal('sharing')}
                                >
                                    Keep sharing
                                </Button>
                                <Button
                                    variant="destructive"
                                    onClick={() => {
                                        if (!withdrawReason.trim()) {
                                            setWithdrawError(
                                                'Record why this recipient’s sharing is being withdrawn.',
                                            );
                                            document
                                                .getElementById(
                                                    'withdraw-reason',
                                                )
                                                ?.focus();
                                            return;
                                        }
                                        setScenario('share-withdrawn');
                                        close();
                                        setNotice(
                                            'Synthetic withdrawal shown. Mara’s access has ended in this preview; collection and staff viewing are unchanged.',
                                        );
                                    }}
                                >
                                    Show withdrawn state
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                    {modal === 'export' && (
                        <div className="dialog-stack">
                            <Notice tone="warning">
                                <strong>
                                    Export needs a separate current
                                    authorisation
                                </strong>
                                The governed export path checks the client,
                                site, assignment, consent, permission, retention
                                period and recorded operational reason.
                            </Notice>
                            <dl>
                                <KV label="Client">Alex Hale</KV>
                                <KV label="Requested dates">
                                    {historyFrom} to {historyTo}
                                </KV>
                                <KV label="Export in this preview">
                                    Unavailable · no file produced
                                </KV>
                            </dl>
                            <p className="subtle">
                                Recipient sharing does not imply export
                                permission. No download is simulated as a
                                successful disclosure.
                            </p>
                        </div>
                    )}
                    {modal === 'request' && (
                        <div className="dialog-stack">
                            <Notice icon={Radio}>
                                <strong>
                                    A request is not a new observation
                                </strong>
                                The existing device command pathway may request
                                a report. Sending a request does not confirm
                                delivery, a new location or Alex’s wellbeing.
                            </Notice>
                            <dl>
                                <KV label="Device">T-DEMO-08</KV>
                                <KV label="Actor">
                                    Jamie Taylor · synthetic staff
                                </KV>
                                <KV label="Current access">
                                    Revalidation required at action
                                </KV>
                            </dl>
                            <Button
                                onClick={() => {
                                    close();
                                    setNotice(
                                        'Illustrative request state: awaiting device response. The last observation time remains unchanged. No command was sent.',
                                    );
                                }}
                            >
                                Preview awaiting-response state
                            </Button>
                        </div>
                    )}
                    {modal === 'destination' && (
                        <div className="dialog-stack">
                            <Notice>
                                <strong>{destination}</strong>This is an
                                existing contextual destination outside the
                                bounded Location mockup. No live route was
                                opened.
                            </Notice>
                            <p className="subtle">
                                Client profile navigation and canonical source
                                ownership are preserved. The wider profile,
                                device register and operational modules are not
                                redesigned here.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => navigate('location')}
                            >
                                Return to Location
                            </Button>
                        </div>
                    )}
                    {modal === 'find' && (
                        <div>
                            <Input
                                autoFocus
                                aria-label="Find a client section"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search Location, Consents or assignment…"
                            />
                            <div className="mt-4 grid gap-2">
                                {[
                                    [
                                        'Location',
                                        'Snapshot · last observation and privacy',
                                        'location',
                                    ],
                                    [
                                        'Consents',
                                        'Relationships & governance · canonical decisions',
                                        'consents',
                                    ],
                                    [
                                        'Tracking assignment',
                                        'Security & Devices · canonical assignment',
                                        'assignment',
                                    ],
                                ]
                                    .filter((r) =>
                                        r
                                            .join(' ')
                                            .toLowerCase()
                                            .includes(search.toLowerCase()),
                                    )
                                    .map(([title, sub, key]) => (
                                        <button
                                            className="choice"
                                            key={key}
                                            onClick={() =>
                                                key === 'assignment'
                                                    ? open('assignment')
                                                    : navigate(key as Section)
                                            }
                                        >
                                            <MapPin className="size-4 text-primary" />
                                            <span>
                                                <strong>{title}</strong>
                                                <small>{sub}</small>
                                            </span>
                                            <ArrowRight className="ml-auto size-4" />
                                        </button>
                                    ))}
                                {![
                                    'location snapshot',
                                    'consents relationships governance',
                                    'tracking assignment security devices',
                                ].some((t) =>
                                    t.includes(search.toLowerCase()),
                                ) && (
                                    <p className="micro">
                                        Only the bounded design destinations are
                                        searchable in this preview.
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                    {modal === 'policy' && (
                        <div className="dialog-stack">
                            <Notice tone="info">
                                <strong>
                                    {VERSION} · desktop only · design for review
                                </strong>
                                Verified source baseline 2302ca3. All people,
                                places, observations, grants and evidence
                                references are synthetic. This preview cannot
                                prove backend enforcement.
                            </Notice>
                            <div>
                                <h3 className="mb-2 font-semibold">
                                    Focused decisions before implementation
                                </h3>
                                <ul className="subtle list-disc space-y-2 pl-5">
                                    <li>
                                        Which recipient purposes, information
                                        scopes and channels may be granted, by
                                        which authorised reviewer?
                                    </li>
                                    <li>
                                        Which decision-maker authority/evidence,
                                        time bounds, review and retention rules
                                        apply?
                                    </li>
                                    <li>
                                        Which observation-age and
                                        device-availability rules may drive
                                        operational labels and actions?
                                    </li>
                                    <li>
                                        How must self-access, withdrawal, expiry
                                        and reassignment apply to history,
                                        exports, queued messages, realtime and
                                        cached views?
                                    </li>
                                </ul>
                            </div>
                            <Notice tone="warning">
                                Unsettled values are not defaults. No emergency
                                bypass, automated sharing grant, safety score or
                                wellbeing inference is proposed.
                            </Notice>
                            <p className="micro">
                                Future gates: exact mockup approval, PKG-01
                                required closure, policy and technical
                                readiness. Astra Extra High owns frontend; one
                                supervised Sol assignment may own backend later.
                            </p>
                        </div>
                    )}
                    {modal !== 'withdraw' && modal !== 'find' && (
                        <DialogFooter>
                            <Button variant="outline" onClick={close}>
                                Close
                            </Button>
                        </DialogFooter>
                    )}
                </DialogContent>
            </Dialog>
        </TooltipProvider>
    );
}
createRoot(document.getElementById('root')!).render(<App />);
