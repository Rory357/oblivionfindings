/* The IT & Provisioning dialogs — Log ticket (3-step), Fulfil request and
 * Assign owner (single-step). All built on the shared HR wizard kit
 * (WizardShell + primitives) so they are visually identical to the
 * Add-Client / Asset lifecycle modals. Zero confirm(): every action is a
 * reviewed modal ending in a success pane. */
import { useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Flag,
    KeyRound,
    Laptop,
    Mail,
    Search,
    Server,
    Ticket,
    User,
    UserCheck,
    Wifi,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

/* ------------------------------------------------------------------ */
/*  Shared types                                                       */
/* ------------------------------------------------------------------ */

export interface AssigneeOption {
    id: number;
    name: string;
}

export interface RequestRow {
    id: number;
    employee: { name: string; role: string | null };
    item: string;
    type: string;
    status: string;
    priority: string;
    due_date: string | null;
    assignee: AssigneeOption | null;
    external_ref: string | null;
    notes: string | null;
    from_onboarding: boolean;
    sign_off_required: boolean;
    created: string | null;
    fulfilled: string | null;
}

export interface TicketRow {
    id: number;
    reference: string | null;
    title: string;
    description: string | null;
    category: string;
    priority: string;
    status: string;
    sla_state: string;
    requester: string;
    assignee: AssigneeOption | null;
    age: string | null;
    updated: string | null;
    resolved: string | null;
}

export type ItModal =
    | { type: 'ticket' }
    | { type: 'raise' }
    | { type: 'fulfil'; request: RequestRow }
    | { type: 'assign-request'; request: RequestRow }
    | { type: 'assign-ticket'; ticket: TicketRow };

/** Flash error carried by an Inertia redirect (validation / logic-guard). Read
 *  from the page passed to onSuccess — `back()->with('error')` fires onSuccess,
 *  not onError (see reference_inertia_flash_error). */
function pageFlashError(page: { props: Record<string, unknown> }): string | null {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
}

/** Sentinel — Radix <SelectItem value=""> crashes at runtime. */
const UNASSIGNED = 'unassigned';

/* ================================================================== */
/*  Dispatcher                                                        */
/* ================================================================== */

export function ItWizard({
    modal,
    assignees,
    onClose,
}: {
    modal: ItModal | null;
    assignees: AssigneeOption[];
    onClose: () => void;
}) {
    if (!modal) return null;
    switch (modal.type) {
        case 'ticket':
            return <CreateTicketWizard assignees={assignees} onClose={onClose} />;
        case 'raise':
            return <RaiseTicketDialog onClose={onClose} />;
        case 'fulfil':
            return <FulfilRequestDialog request={modal.request} onClose={onClose} />;
        case 'assign-request':
            return (
                <AssignDialog
                    heading="Assign request"
                    subject={modal.request.item}
                    currentId={modal.request.assignee?.id ?? null}
                    endpoint={{ method: 'post', url: `/it/provisioning/${modal.request.id}/assign`, field: 'assigned_to_user_id' }}
                    assignees={assignees}
                    onClose={onClose}
                />
            );
        case 'assign-ticket':
            return (
                <AssignDialog
                    heading="Assign ticket"
                    subject={modal.ticket.title}
                    currentId={modal.ticket.assignee?.id ?? null}
                    endpoint={{ method: 'patch', url: `/it/tickets/${modal.ticket.id}`, field: 'assigned_to_user_id' }}
                    assignees={assignees}
                    onClose={onClose}
                />
            );
    }
}

/* ================================================================== */
/*  Log ticket (3 steps)                                              */
/* ================================================================== */

const TICKET_STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'What & where', icon: FileText },
    { key: 'triage', label: 'Triage', blurb: 'Priority & owner', icon: Flag },
    { key: 'review', label: 'Review', blurb: 'Confirm & log', icon: CheckCircle2 },
];

const CATEGORY_OPTIONS = [
    { key: 'hardware', label: 'Hardware', description: 'Laptops, phones, printers & devices', icon: Laptop },
    { key: 'account', label: 'Account', description: 'Logins, email & software access', icon: Mail },
    { key: 'network', label: 'Network', description: 'Wi-Fi, VPN & connectivity', icon: Wifi },
    { key: 'other', label: 'Other', description: 'Anything else IT should look at', icon: Server },
] as const;

const PRIORITY_OPTIONS = [
    { key: 'low', label: 'Low', description: 'When time allows' },
    { key: 'normal', label: 'Normal', description: 'Business as usual' },
    { key: 'high', label: 'High', description: 'Blocking someone’s work' },
    { key: 'urgent', label: 'Urgent', description: 'Site-wide / safety impact' },
] as const;

function CreateTicketWizard({
    assignees,
    onClose,
}: {
    assignees: AssigneeOption[];
    onClose: () => void;
}) {
    const wizard = useWizard(TICKET_STEPS.length);
    const [done, setDone] = useState(false);

    const form = useForm({
        title: '',
        description: '',
        category: 'hardware',
        priority: 'normal',
        assigned_to_user_id: UNASSIGNED,
    });

    const assignee = assignees.find((a) => String(a.id) === form.data.assigned_to_user_id) ?? null;
    const detailsValid = form.data.title.trim().length > 0;

    const submit = () => {
        form.transform((data) => ({
            ...data,
            assigned_to_user_id: data.assigned_to_user_id === UNASSIGNED ? null : Number(data.assigned_to_user_id),
        }));
        form.post('/it/tickets', {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Log IT ticket"
            description="Log a new helpdesk ticket for the IT queue."
            railIcon={Ticket}
            railTitle="Log ticket"
            railSub="IT helpdesk"
            steps={TICKET_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Ticket logged"
                        blurb={
                            <>
                                “{form.data.title}” is now in the helpdesk queue
                                {assignee ? <> with {assignee.name}</> : null}.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button onClick={submit} disabled={form.processing || !detailsValid}>
                            {form.processing ? 'Logging…' : 'Log ticket'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={wizard.index === 0 && !detailsValid}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="What’s the issue?"
                        blurb="A clear one-liner plus any detail IT needs to act."
                    />
                    <div className="grid gap-3.5">
                        <Field label="Title" required error={form.errors.title}>
                            <Input
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder="e.g. Printer offline — Sunnyside Lodge"
                                maxLength={255}
                            />
                        </Field>
                        <Field label="Detail" hint="optional" error={form.errors.description}>
                            <Textarea
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Anything that helps IT reproduce or locate the issue…"
                                rows={4}
                            />
                        </Field>
                        <Field label="Category" error={form.errors.category}>
                            <TilePicker
                                value={form.data.category}
                                onChange={(v) => form.setData('category', v)}
                                options={[...CATEGORY_OPTIONS]}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Flag}
                        title="Priority & owner"
                        blurb="How urgent is it, and who should pick it up?"
                    />
                    <div className="grid gap-3.5">
                        <Field label="Priority" error={form.errors.priority}>
                            <TilePicker
                                value={form.data.priority}
                                onChange={(v) => form.setData('priority', v)}
                                options={[...PRIORITY_OPTIONS]}
                            />
                        </Field>
                        {/* Triage is agent work — self-service requesters get no
                            assignee list from the server, so the field hides. */}
                        {assignees.length > 0 ? (
                            <Field label="Assign to" hint="optional — leave unassigned for triage" error={form.errors.assigned_to_user_id}>
                                <SelectInput
                                    value={form.data.assigned_to_user_id}
                                    onChange={(v) => form.setData('assigned_to_user_id', v)}
                                    placeholder="Unassigned"
                                    options={[
                                        { value: UNASSIGNED, label: 'Unassigned' },
                                        ...assignees.map((a) => ({ value: String(a.id), label: a.name })),
                                    ]}
                                />
                            </Field>
                        ) : null}
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & log"
                        blurb="Check the ticket before it lands in the queue."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={FileText} title="Details" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Title" value={form.data.title} />
                            <ReviewRow
                                label="Category"
                                value={CATEGORY_OPTIONS.find((c) => c.key === form.data.category)?.label}
                            />
                            <ReviewRow label="Detail" value={form.data.description || undefined} />
                        </ReviewCard>
                        <ReviewCard icon={Flag} title="Triage" onEdit={() => wizard.goTo(1)}>
                            <ReviewRow
                                label="Priority"
                                value={PRIORITY_OPTIONS.find((p) => p.key === form.data.priority)?.label}
                            />
                            {assignees.length > 0 ? (
                                <ReviewRow label="Assign to" value={assignee?.name} />
                            ) : null}
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Raise a ticket (self-service, single step — speed IS the spec)    */
/* ================================================================== */

const RAISE_STEPS: readonly WizardStep[] = [
    { key: 'raise', label: 'Raise a ticket', blurb: 'Under 30 seconds', icon: Ticket },
];

/** Plain-language categories for people mid-shift — no IT jargon. */
const RAISE_CATEGORY_OPTIONS = [
    { key: 'hardware', label: 'Device or hardware', description: 'Phone, laptop, printer, charger…', icon: Laptop },
    { key: 'account', label: 'Account or sign-in', description: 'Locked out, passwords, email', icon: User },
    { key: 'network', label: 'Wi-Fi or network', description: 'No internet, VPN trouble', icon: Wifi },
    { key: 'other', label: 'Something else', description: 'Anything IT should look at', icon: Server },
] as const;

/** Plain-language urgency → priority. The requester never sees "P1". */
const URGENCY_OPTIONS: { value: string; label: string }[] = [
    { value: 'urgent', label: 'Stops me supporting someone right now' },
    { value: 'high', label: 'Blocking my work' },
    { value: 'normal', label: 'Annoying but I can work' },
    { value: 'low', label: 'Whenever' },
];

function RaiseTicketDialog({ onClose }: { onClose: () => void }) {
    const wizard = useWizard(RAISE_STEPS.length);
    const [done, setDone] = useState(false);
    const [moreDetails, setMoreDetails] = useState(false);
    const [reference, setReference] = useState<string | null>(null);

    const form = useForm<{
        title: string;
        description: string;
        category: string;
        priority: string;
        attachments: File[];
    }>({
        title: '',
        description: '',
        category: 'hardware',
        priority: 'normal',
        attachments: [],
    });

    const valid = form.data.title.trim().length > 0;

    const submit = () => {
        form.post('/it/tickets', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                const flash = page.props.flash as
                    | { it_ticket?: { reference?: string | null } }
                    | undefined;
                setReference(flash?.it_ticket?.reference ?? null);
                setDone(true);
                toast.success('Ticket raised — IT can see it now.');
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Raise a ticket"
            description="Tell IT what's broken — they see it instantly."
            railIcon={Ticket}
            railTitle="Raise a ticket"
            railSub="IT helpdesk"
            steps={RAISE_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title={reference ? `Raised — ${reference}` : 'Ticket raised'}
                        blurb={
                            <>
                                IT can see it now. We’ll email you when it’s picked up or
                                resolved — and you can track it any time in <strong>My tickets</strong>.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={null}
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing || !valid}>
                        {form.processing ? 'Raising…' : 'Raise ticket'}
                    </Button>
                </>
            }
        >
            <WizardStepPane>
                <StepHead
                    icon={Ticket}
                    title="What’s the problem?"
                    blurb="One line is enough — you can add detail if it helps."
                />
                <div className="grid gap-3.5">
                    <Field label="What's broken?" required error={form.errors.title}>
                        <Input
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder="e.g. My work phone won’t charge"
                            maxLength={255}
                            autoFocus
                        />
                    </Field>
                    <Field label="What kind of thing is it?" error={form.errors.category}>
                        <TilePicker
                            value={form.data.category}
                            onChange={(v) => form.setData('category', v)}
                            options={[...RAISE_CATEGORY_OPTIONS]}
                        />
                    </Field>
                    <Field label="How urgent is it?" error={form.errors.priority}>
                        <Segmented
                            value={form.data.priority}
                            onChange={(v) => form.setData('priority', v)}
                            options={URGENCY_OPTIONS}
                        />
                    </Field>
                    {moreDetails ? (
                        <>
                            <Field label="More details" hint="optional" error={form.errors.description}>
                                <Textarea
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    placeholder="Anything that helps IT find or fix it — where you are, what you tried…"
                                    rows={4}
                                />
                            </Field>
                            <Field
                                label="Photos or files"
                                hint="optional — a photo says a lot"
                                error={form.errors.attachments}
                            >
                                <FileDropzone
                                    onFiles={(files) =>
                                        form.setData(
                                            'attachments',
                                            [...form.data.attachments, ...files].slice(0, 5),
                                        )
                                    }
                                    accept=".jpg,.jpeg,.png,.webp,.gif,.heic,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx"
                                    title="Drop a photo of the problem"
                                    hint="Images, PDF or documents — up to 5 files"
                                />
                                {form.data.attachments.map((file, i) => (
                                    <StagedFileCard
                                        key={`${file.name}-${i}`}
                                        file={file}
                                        onRemove={() =>
                                            form.setData(
                                                'attachments',
                                                form.data.attachments.filter((_, j) => j !== i),
                                            )
                                        }
                                    />
                                ))}
                            </Field>
                        </>
                    ) : (
                        // eslint-disable-next-line no-restricted-syntax -- text-link disclosure, not a button chrome
                        <button
                            type="button"
                            onClick={() => setMoreDetails(true)}
                            className="justify-self-start text-[12.5px] font-semibold text-primary hover:underline"
                        >
                            + Add more details
                        </button>
                    )}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ================================================================== */
/*  Fulfil request (single step)                                      */
/* ================================================================== */

const FULFIL_STEPS: readonly WizardStep[] = [
    { key: 'fulfil', label: 'Fulfil request', blurb: 'Confirm it’s done', icon: CheckCircle2 },
];

function FulfilRequestDialog({
    request,
    onClose,
}: {
    request: RequestRow;
    onClose: () => void;
}) {
    const [done, setDone] = useState(false);

    const form = useForm({
        external_ref: request.external_ref ?? '',
        notes: request.notes ?? '',
    });

    const submit = () => {
        form.post(`/it/provisioning/${request.id}/fulfil`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Fulfil provisioning request"
            description={`Confirm “${request.item}” has been provisioned.`}
            railIcon={KeyRound}
            railTitle="Fulfil request"
            railSub="IT provisioning"
            steps={FULFIL_STEPS}
            stepIndex={0}
            onStepClick={() => undefined}
            maxHeight="min(78vh, 560px)"
            success={
                done ? (
                    <WizardSuccessPane
                        title="Request fulfilled"
                        blurb={
                            <>
                                “{request.item}” for {request.employee.name} is done
                                {request.from_onboarding ? <> — the linked onboarding task has been completed too</> : null}.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {form.processing ? 'Fulfilling…' : 'Mark fulfilled'}
                    </Button>
                </>
            }
        >
            <WizardStepPane>
                <StepHead
                    icon={CheckCircle2}
                    title={`Fulfil “${request.item}”`}
                    blurb={`Record how ${request.employee.name}’s request was provisioned.`}
                />
                <div className="grid gap-3.5">
                    {request.from_onboarding ? (
                        <InfoCard icon={ClipboardCheck}>
                            Fulfilling this request also completes the linked onboarding task
                            {request.sign_off_required ? ' and records you as the sign-off' : ''}.
                        </InfoCard>
                    ) : null}
                    <Field
                        label="External reference"
                        hint="optional — ticket id / account id"
                        error={form.errors.external_ref}
                    >
                        <Input
                            value={form.data.external_ref}
                            onChange={(e) => form.setData('external_ref', e.target.value)}
                            placeholder="e.g. M365 user id, helpdesk #4821"
                            maxLength={255}
                        />
                    </Field>
                    <Field label="Notes" hint="optional" error={form.errors.notes}>
                        <Textarea
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            placeholder="Anything worth recording about how this was set up…"
                            rows={4}
                        />
                    </Field>
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ================================================================== */
/*  Assign owner (single step, shared by requests & tickets)          */
/* ================================================================== */

const ASSIGN_STEPS: readonly WizardStep[] = [
    { key: 'assign', label: 'Pick an owner', blurb: 'Who works this?', icon: User },
];

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');
}

function AssignDialog({
    heading,
    subject,
    currentId,
    endpoint,
    assignees,
    onClose,
}: {
    heading: string;
    subject: string;
    currentId: number | null;
    endpoint: { method: 'post' | 'patch'; url: string; field: string };
    assignees: AssigneeOption[];
    onClose: () => void;
}) {
    const [done, setDone] = useState(false);
    const [search, setSearch] = useState('');

    const form = useForm({
        [endpoint.field]: currentId != null ? String(currentId) : '',
    } as Record<string, string>);

    const pickedId = form.data[endpoint.field];
    const picked = assignees.find((a) => String(a.id) === pickedId) ?? null;

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return assignees;
        return assignees.filter((a) => a.name.toLowerCase().includes(q));
    }, [search, assignees]);

    const submit = () => {
        form.transform((data) => ({
            [endpoint.field]: Number(data[endpoint.field]),
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        };
        if (endpoint.method === 'patch') {
            form.patch(endpoint.url, opts);
        } else {
            form.post(endpoint.url, opts);
        }
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title={heading}
            description={`Pick who owns “${subject}”.`}
            railIcon={UserCheck}
            railTitle={heading}
            railSub="IT & Provisioning"
            steps={ASSIGN_STEPS}
            stepIndex={0}
            onStepClick={() => undefined}
            maxHeight="min(78vh, 600px)"
            success={
                done ? (
                    <WizardSuccessPane
                        title="Owner assigned"
                        blurb={
                            <>
                                “{subject}” is now with {picked?.name ?? 'the new owner'}.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing || !picked}>
                        {form.processing ? 'Assigning…' : 'Assign'}
                    </Button>
                </>
            }
        >
            <WizardStepPane>
                <StepHead
                    icon={User}
                    title="Who works this?"
                    blurb={`Pick the person taking ownership of “${subject}”.`}
                />
                <div className="relative mb-3">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search staff by name…"
                        className="pl-8"
                    />
                </div>
                <div className="flex max-h-72 flex-col gap-1.5 overflow-y-auto">
                    {filtered.map((a) => {
                        const active = String(a.id) === pickedId;
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- selector card (avatar + name + check), not a Button case
                            <button
                                key={a.id}
                                type="button"
                                onClick={() => form.setData(endpoint.field, String(a.id))}
                                className={`flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5 text-left transition-colors ${active ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50'}`}
                            >
                                <span className="grid h-9 w-9 flex-none place-items-center rounded-full bg-primary/10 text-[12.5px] font-bold text-primary">
                                    {initials(a.name)}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-[13.5px] font-bold">
                                    {a.name}
                                </span>
                                {active ? <CheckCircle2 className="h-5 w-5 shrink-0 text-primary" /> : null}
                            </button>
                        );
                    })}
                    {filtered.length === 0 ? (
                        <div className="py-6 text-center text-[13px] text-muted-foreground">
                            No staff match “{search}”.
                        </div>
                    ) : null}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}
