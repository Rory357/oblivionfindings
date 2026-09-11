import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { Input } from '@/components/ui/input';
import { SkeletonCard } from '@/components/ui/skeleton-card';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    readHandoffPreview,
    type HandoffPreview,
    type HandoffSelection,
    type HandoffTicket,
} from '@/hooks/it-control-room-handoff-contract';
import { useItControlRoomHandoffCommand } from '@/hooks/use-it-control-room-handoff-command';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    ClipboardCheck,
    FilePlus2,
    FileText,
    Link2,
    Network,
    UserRound,
    Wrench,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';

interface Props {
    actorId: number;
    alertId: number;
    alertReference: string;
    allowed: boolean;
    onClose: () => void;
}
type Creation = Extract<HandoffSelection, { action: 'create' }>;
const steps = [
    {
        key: 'choose',
        label: 'Choose IT work',
        blurb: 'Create or link a ticket',
        icon: Link2,
    },
    {
        key: 'details',
        label: 'Technical details',
        blurb: 'Describe the work and purpose',
        icon: Wrench,
    },
    {
        key: 'review',
        label: 'Review handoff',
        blurb: 'Check before saving',
        icon: ClipboardCheck,
    },
] as const;

export function ControlRoomItHandoffDialog(props: Props) {
    return <HandoffBody key={`${props.actorId}:${props.alertId}`} {...props} />;
}

function HandoffBody({
    actorId,
    alertId,
    alertReference,
    allowed,
    onClose,
}: Props) {
    const command = useItControlRoomHandoffCommand(actorId, alertId);
    const commandRef = useRef(command);
    useLayoutEffect(() => {
        commandRef.current = command;
    });
    const [preview, setPreview] = useState<HandoffPreview | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [choice, setChoice] = useState<'create' | 'link' | ''>('');
    const [selected, setSelected] = useState<HandoffTicket | null>(null);
    const [query, setQuery] = useState('');
    const [step, setStep] = useState(0);
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [category, setCategory] = useState<Creation['category'] | ''>('');
    const [impact, setImpact] = useState<Creation['impact'] | ''>('');
    const [urgency, setUrgency] = useState<Creation['urgency'] | ''>('');
    const [serviceId, setServiceId] = useState('none');
    const [reason, setReason] = useState('');
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const request = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const heading = useRef<HTMLDivElement>(null);
    const successAction = useRef<HTMLButtonElement>(null);
    const permittedNavigation = useRef(false);
    const concealed = !allowed || ['access', 'session'].includes(command.stage);
    const pending = command.identity !== null && command.stage !== 'settled';
    const dirty =
        choice !== '' || title !== '' || description !== '' || reason !== '';
    const settled = command.result;
    const guard = !settled && (pending || (!concealed && dirty));
    const editable =
        !concealed &&
        !pending &&
        ['editing', 'rejected'].includes(command.stage);

    const load = useCallback(
        async (search = '') => {
            request.current?.abort();
            const token = ++epoch.current;
            if (!allowed) {
                commandRef.current.conceal('access');
                return;
            }
            const controller = new AbortController();
            request.current = controller;
            setLoading(true);
            setError(null);
            setSelected(null);
            setStep(0);
            try {
                const response = await axios.get(
                    `/it/control-room/alerts/${alertId}/handoff`,
                    {
                        params: { viewer_user_id: actorId, search },
                        signal: controller.signal,
                        timeout: 15000,
                        headers: { Accept: 'application/json' },
                    },
                );
                if (token !== epoch.current) return;
                const data = readHandoffPreview(
                    response.data,
                    actorId,
                    alertId,
                    window.location.origin,
                );
                if (!data) throw new Error('Unconfirmed preview');
                setPreview(data);
            } catch (failure) {
                if (token !== epoch.current) return;
                const status = axios.isAxiosError(failure)
                    ? failure.response?.status
                    : undefined;
                if ([401, 419, 403, 404].includes(status ?? 0)) {
                    commandRef.current.conceal(
                        status === 401 || status === 419 ? 'session' : 'access',
                    );
                    setPreview(null);
                    setTitle('');
                    setDescription('');
                    setReason('');
                    setQuery('');
                } else {
                    setPreview(null);
                    setError(
                        'The current alert and IT work could not be loaded. Try again before preparing a handoff.',
                    );
                }
            } finally {
                if (token === epoch.current) {
                    setLoading(false);
                    request.current = null;
                }
            }
        },
        [actorId, alertId, allowed],
    );
    useEffect(() => {
        void load();
        return () => {
            ++epoch.current;
            request.current?.abort();
        };
    }, [load]);
    useEffect(() => {
        if (successAction.current) successAction.current.focus();
        else heading.current?.focus();
    }, [step, error, command.stage]);
    useEffect(() => {
        if (!guard) return;
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (permittedNavigation.current) return;
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', beforeUnload);
        const unsubscribe = router.on('before', (event) => {
            if (permittedNavigation.current) return;
            event.preventDefault();
            setLeave(() => () => {
                permittedNavigation.current = true;
                router.visit(event.detail.visit.url, event.detail.visit);
            });
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            unsubscribe();
        };
    }, [guard]);

    const close = () => (guard ? setLeave(() => onClose) : onClose());
    const openTicket = (ticket: HandoffTicket) => {
        const navigate = () => {
            permittedNavigation.current = true;
            window.location.assign(ticket.href);
        };
        if (guard) setLeave(() => navigate);
        else navigate();
    };
    const validDetails =
        reason.trim() !== '' &&
        (choice === 'link' ||
            (title.trim() !== '' &&
                description.trim() !== '' &&
                category !== '' &&
                impact !== '' &&
                urgency !== ''));
    const advance = () => {
        if (step === 0 && (!choice || (choice === 'link' && !selected))) {
            setError(
                'Choose whether to create IT work or select an existing ticket.',
            );
            return;
        }
        if (step === 1 && !validDetails) {
            setError(
                'Complete the required technical details and handoff reason.',
            );
            return;
        }
        setError(null);
        setStep(step + 1);
    };
    const submit = () => {
        if (
            step !== 2 ||
            !preview?.can_start ||
            preview.has_existing_work ||
            !editable ||
            !validDetails ||
            loading
        )
            return;
        const base = { alertVersion: preview.alert_version, reason };
        if (choice === 'link' && selected)
            command.send({
                ...base,
                action: 'link',
                ticketId: selected.id,
                ticketVersion: selected.version,
            });
        else if (choice === 'create' && category && impact && urgency)
            command.send({
                ...base,
                action: 'create',
                title,
                description,
                category,
                impact,
                urgency,
                serviceId: serviceId === 'none' ? null : Number(serviceId),
            });
    };
    const fieldError = (field: string) => command.errors?.[field];
    const blocking = concealed || pending || command.stage === 'unavailable';
    const footer =
        settled?.status === 'committed' ? null : (
            <>
                <Button type="button" variant="outline" onClick={close}>
                    Back to alert
                </Button>
                {!blocking &&
                preview?.can_start &&
                !preview.has_existing_work &&
                command.stage !== 'settled' ? (
                    <>
                        {step > 0 ? (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={loading}
                                onClick={() => {
                                    setError(null);
                                    setStep(step - 1);
                                }}
                            >
                                Back
                            </Button>
                        ) : null}
                        {step < 2 ? (
                            <Button
                                type="button"
                                disabled={
                                    loading || command.stage === 'rejected'
                                }
                                onClick={advance}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                disabled={
                                    loading || command.stage !== 'editing'
                                }
                                onClick={submit}
                            >
                                {choice === 'create'
                                    ? 'Create and link ticket'
                                    : 'Link selected ticket'}
                            </Button>
                        )}
                    </>
                ) : null}
            </>
        );

    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="IT handoff"
                description="Create or link technical work without changing the operational alert."
                railIcon={Wrench}
                railTitle="IT handoff"
                railSub={alertReference}
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (editable && index < step) setStep(index);
                }}
                pct={
                    blocking || preview?.has_existing_work
                        ? null
                        : Math.round((step / 3) * 100)
                }
                footerEnd={
                    <div className="flex flex-wrap justify-end gap-2">
                        {footer}
                    </div>
                }
                success={
                    settled?.status === 'committed' && !concealed ? (
                        <WizardSuccessPane
                            title={
                                settled.outcome === 'existing'
                                    ? 'Existing IT work confirmed'
                                    : 'IT handoff saved'
                            }
                            blurb={`${settled.ticket.reference ?? `Ticket ${settled.ticket.id}`} · ${settled.ticket.title}. The operational alert remains in Control Room.`}
                            actions={
                                <>
                                    <Button variant="outline" onClick={onClose}>
                                        Back to alert
                                    </Button>
                                    <Button
                                        ref={successAction}
                                        onClick={() =>
                                            openTicket(settled.ticket)
                                        }
                                    >
                                        Open IT ticket
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div
                        ref={heading}
                        tabIndex={-1}
                        className="focus-visible:outline-none"
                        role={error || command.message ? 'alert' : undefined}
                    >
                        <StepHead
                            icon={steps[step].icon}
                            title={steps[step].label}
                            blurb="Keep the operational response in Control Room and technical delivery in IT."
                        />
                        {error ? (
                            <p className="text-caption mb-3 text-status-critical">
                                {error}
                            </p>
                        ) : null}
                        {command.message ? (
                            <p className="text-caption mb-3 text-status-warning">
                                {command.message}
                            </p>
                        ) : null}
                    </div>
                    {concealed ? (
                        <ErrorState
                            title="IT handoff access unavailable"
                            message={
                                command.message ??
                                'Your current access does not allow this handoff.'
                            }
                        />
                    ) : pending ? (
                        <div className="space-y-4" role="status">
                            <StatusBadge variant="warning">
                                {command.busy
                                    ? 'Waiting for a confirmed result'
                                    : 'Handoff outcome unconfirmed'}
                            </StatusBadge>
                            <p className="text-subtle">
                                A missing response does not prove the save
                                failed. Check this request before starting
                                another. Cancelling asks the server to prevent a
                                pending handoff; it cannot undo a committed one.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {command.busy ? (
                                    <Button
                                        variant="outline"
                                        onClick={command.stopWaiting}
                                    >
                                        Stop waiting
                                    </Button>
                                ) : (
                                    <>
                                        <Button onClick={command.check}>
                                            Check saved result
                                        </Button>
                                        {command.canRetry ? (
                                            <Button
                                                variant="outline"
                                                onClick={command.retry}
                                            >
                                                Retry unchanged request
                                            </Button>
                                        ) : null}
                                        <Button
                                            variant="outline"
                                            onClick={command.cancel}
                                        >
                                            Cancel pending handoff
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    ) : settled?.status === 'cancelled' ? (
                        <EmptyState
                            icon={FileText}
                            title="Handoff cancelled"
                            description="The server confirmed cancellation. No ticket was created or linked by this request."
                        />
                    ) : command.stage === 'unavailable' ? (
                        <ErrorState
                            title="Browser recovery unavailable"
                            message={command.message ?? undefined}
                        />
                    ) : loading ? (
                        <div
                            aria-label="Loading current IT handoff"
                            aria-busy="true"
                        >
                            <SkeletonCard rows={4} />
                        </div>
                    ) : !preview ? (
                        <ErrorState
                            title="Handoff could not be loaded"
                            message={error ?? undefined}
                            onRetry={() => void load(query)}
                        />
                    ) : preview.has_existing_work ? (
                        <div className="space-y-3">
                            <EmptyState
                                variant="compact"
                                icon={Link2}
                                title="This alert already has IT work"
                                description="Coordinate the linked ticket before starting another handoff."
                            />
                            {preview.existing_work.map((ticket) => (
                                <ReviewCard
                                    key={ticket.id}
                                    icon={FileText}
                                    title={
                                        ticket.reference ??
                                        `Ticket ${ticket.id}`
                                    }
                                >
                                    <ReviewRow
                                        label="Title"
                                        value={ticket.title}
                                    />
                                    <ReviewRow
                                        label="Status"
                                        value={ticket.status.replaceAll(
                                            '_',
                                            ' ',
                                        )}
                                    />
                                    <Button
                                        className="mt-3"
                                        variant="outline"
                                        onClick={() => openTicket(ticket)}
                                    >
                                        Open linked ticket
                                    </Button>
                                </ReviewCard>
                            ))}
                            {!preview.existing_work.length ? (
                                <p className="text-subtle">
                                    The linked work is outside your current
                                    ticket access. Ask its IT owner to
                                    coordinate this alert.
                                </p>
                            ) : null}
                        </div>
                    ) : !preview.can_start ? (
                        <EmptyState
                            icon={FileText}
                            title="This alert is no longer active"
                            description="New technical handoffs need an active alert. Existing requests can still be checked using their saved recovery reference."
                        />
                    ) : (
                        <div className="space-y-5">
                            <p className="text-caption text-muted-foreground">
                                Approved site:{' '}
                                {preview.site.name ?? `Site ${preview.site.id}`}
                            </p>
                            {command.stage === 'rejected' ? (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        command.reset();
                                        void load(query);
                                    }}
                                >
                                    Refresh records and review again
                                </Button>
                            ) : null}
                            {step === 0 ? (
                                <>
                                    <TilePicker
                                        value={choice}
                                        onChange={(value) => {
                                            setChoice(
                                                value as 'create' | 'link',
                                            );
                                            setSelected(null);
                                        }}
                                        options={[
                                            {
                                                key: 'link',
                                                label: 'Link existing IT work',
                                                description:
                                                    'Coordinate an open ticket at this site.',
                                                icon: Link2,
                                            },
                                            {
                                                key: 'create',
                                                label: 'Create IT work',
                                                description:
                                                    'Raise a new technical incident for the service desk.',
                                                icon: FilePlus2,
                                            },
                                        ]}
                                    />
                                    {choice === 'link' ? (
                                        <>
                                            <form
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    void load(query);
                                                }}
                                                className="flex items-end gap-2"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <Field label="Find an open ticket">
                                                        <Input
                                                            value={query}
                                                            maxLength={120}
                                                            onChange={(event) =>
                                                                setQuery(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                </div>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                >
                                                    Search
                                                </Button>
                                            </form>
                                            {preview.candidates.length ? (
                                                <Field
                                                    label="Existing IT incident"
                                                    required
                                                    error={fieldError(
                                                        'ticket_id',
                                                    )}
                                                >
                                                    <SelectInput
                                                        value={
                                                            selected
                                                                ? String(
                                                                      selected.id,
                                                                  )
                                                                : ''
                                                        }
                                                        onChange={(value) =>
                                                            setSelected(
                                                                preview.candidates.find(
                                                                    (ticket) =>
                                                                        ticket.id ===
                                                                        Number(
                                                                            value,
                                                                        ),
                                                                ) ?? null,
                                                            )
                                                        }
                                                        placeholder="Choose an open ticket"
                                                        ariaLabel="Existing IT incident"
                                                        options={preview.candidates.map(
                                                            (ticket) => ({
                                                                value: String(
                                                                    ticket.id,
                                                                ),
                                                                label: `${ticket.reference ?? ticket.id} · ${ticket.title}`,
                                                            }),
                                                        )}
                                                    />
                                                </Field>
                                            ) : (
                                                <EmptyState
                                                    variant="compact"
                                                    title="No matching open IT work"
                                                    description="Try another search or choose Create IT work."
                                                />
                                            )}
                                            <p className="text-caption text-muted-foreground">
                                                Up to 25 permitted open
                                                incidents at this site. Refine
                                                the search to find another
                                                ticket.
                                            </p>
                                        </>
                                    ) : null}
                                </>
                            ) : step === 1 ? (
                                <>
                                    {choice === 'create' ? (
                                        <>
                                            <Field
                                                label="Ticket title"
                                                required
                                                error={fieldError('title')}
                                            >
                                                <Input
                                                    value={title}
                                                    maxLength={255}
                                                    onChange={(event) =>
                                                        setTitle(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Technical work needed"
                                                required
                                                error={fieldError(
                                                    'description',
                                                )}
                                            >
                                                <Textarea
                                                    value={description}
                                                    maxLength={10000}
                                                    rows={4}
                                                    onChange={(event) =>
                                                        setDescription(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <p className="text-caption text-muted-foreground">
                                                Include only the technical
                                                details IT needs. Operational
                                                notes and private source context
                                                are not copied automatically.
                                            </p>
                                            <Field
                                                label="Category"
                                                required
                                                error={fieldError('category')}
                                            >
                                                <TilePicker
                                                    value={category}
                                                    onChange={(value) =>
                                                        setCategory(
                                                            value as Creation['category'],
                                                        )
                                                    }
                                                    options={[
                                                        {
                                                            key: 'hardware',
                                                            label: 'Hardware',
                                                            icon: Wrench,
                                                        },
                                                        {
                                                            key: 'account',
                                                            label: 'Account',
                                                            icon: UserRound,
                                                        },
                                                        {
                                                            key: 'network',
                                                            label: 'Network',
                                                            icon: Network,
                                                        },
                                                        {
                                                            key: 'other',
                                                            label: 'Other',
                                                            icon: FileText,
                                                        },
                                                    ]}
                                                />
                                            </Field>
                                            <div className="grid grid-cols-2 gap-4">
                                                <Field
                                                    label="Impact"
                                                    required
                                                    error={fieldError('impact')}
                                                >
                                                    <SelectInput
                                                        value={impact}
                                                        onChange={(value) =>
                                                            setImpact(
                                                                value as Creation['impact'],
                                                            )
                                                        }
                                                        placeholder="Choose impact"
                                                        options={[
                                                            'individual',
                                                            'team',
                                                            'site',
                                                            'organization',
                                                        ].map((value) => ({
                                                            value,
                                                            label:
                                                                value ===
                                                                'organization'
                                                                    ? 'Organisation'
                                                                    : value[0].toUpperCase() +
                                                                      value.slice(
                                                                          1,
                                                                      ),
                                                        }))}
                                                    />
                                                </Field>
                                                <Field
                                                    label="Urgency"
                                                    required
                                                    error={fieldError(
                                                        'urgency',
                                                    )}
                                                >
                                                    <SelectInput
                                                        value={urgency}
                                                        onChange={(value) =>
                                                            setUrgency(
                                                                value as Creation['urgency'],
                                                            )
                                                        }
                                                        placeholder="Choose urgency"
                                                        options={[
                                                            'low',
                                                            'normal',
                                                            'high',
                                                            'critical',
                                                        ].map((value) => ({
                                                            value,
                                                            label:
                                                                value[0].toUpperCase() +
                                                                value.slice(1),
                                                        }))}
                                                    />
                                                </Field>
                                            </div>
                                            <Field
                                                label="Affected service"
                                                error={fieldError(
                                                    'it_service_id',
                                                )}
                                            >
                                                <SelectInput
                                                    value={serviceId}
                                                    onChange={setServiceId}
                                                    placeholder="Choose a service"
                                                    options={[
                                                        {
                                                            value: 'none',
                                                            label: 'Not yet classified',
                                                        },
                                                        ...preview.services.map(
                                                            (service) => ({
                                                                value: String(
                                                                    service.id,
                                                                ),
                                                                label: service.name,
                                                            }),
                                                        ),
                                                    ]}
                                                />
                                            </Field>
                                        </>
                                    ) : (
                                        <ReviewCard
                                            icon={Link2}
                                            title="Selected IT work"
                                        >
                                            <ReviewRow
                                                label="Ticket"
                                                value={selected?.reference}
                                            />
                                            <ReviewRow
                                                label="Title"
                                                value={selected?.title}
                                            />
                                        </ReviewCard>
                                    )}
                                    <Field
                                        label="Why is this technical handoff needed?"
                                        required
                                        error={fieldError('reason')}
                                    >
                                        <Textarea
                                            value={reason}
                                            maxLength={2000}
                                            rows={3}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                        />
                                    </Field>
                                </>
                            ) : (
                                <>
                                    <ReviewCard
                                        icon={Link2}
                                        title="Handoff"
                                        onEdit={() => setStep(0)}
                                    >
                                        <ReviewRow
                                            label="Action"
                                            value={
                                                choice === 'create'
                                                    ? 'Create and link IT incident'
                                                    : 'Link existing IT incident'
                                            }
                                        />
                                        <ReviewRow
                                            label="Site"
                                            value={preview.site.name}
                                        />
                                        <ReviewRow
                                            label="Ticket"
                                            value={
                                                choice === 'create'
                                                    ? title
                                                    : `${selected?.reference ?? ''} · ${selected?.title ?? ''}`
                                            }
                                        />
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={Wrench}
                                        title="Technical details"
                                        onEdit={() => setStep(1)}
                                    >
                                        {choice === 'create' ? (
                                            <>
                                                <ReviewRow
                                                    label="Description"
                                                    value={description}
                                                />
                                                <ReviewRow
                                                    label="Category"
                                                    value={category}
                                                />
                                                <ReviewRow
                                                    label="Impact / urgency"
                                                    value={`${impact} / ${urgency}`}
                                                />
                                                <ReviewRow
                                                    label="Service"
                                                    value={
                                                        preview.services.find(
                                                            (service) =>
                                                                String(
                                                                    service.id,
                                                                ) === serviceId,
                                                        )?.name ??
                                                        'Not yet classified'
                                                    }
                                                />
                                            </>
                                        ) : null}
                                        <ReviewRow
                                            label="Handoff reason"
                                            value={reason}
                                        />
                                    </ReviewCard>
                                    <p className="text-subtle">
                                        Saving keeps the operational alert
                                        unchanged. New work uses the existing IT
                                        priority, SLA and routing rules. A
                                        competing handoff is checked again
                                        before any write.
                                    </p>
                                </>
                            )}
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={leave !== null}
                onClose={() => setLeave(null)}
                onConfirm={() => {
                    permittedNavigation.current = true;
                    if (command.busy) command.stopWaiting();
                    leave?.();
                }}
                title={
                    pending
                        ? 'Leave with the handoff unconfirmed?'
                        : 'Discard this handoff draft?'
                }
                description={
                    pending
                        ? 'The request may still complete. Only its recovery reference is retained; reopen this alert to check the result before submitting again.'
                        : 'Your unsaved technical details will be discarded. The alert and IT records will not change.'
                }
                confirmText={
                    pending ? 'Leave and check later' : 'Discard draft'
                }
                variant={pending ? 'default' : 'destructive'}
            />
        </>
    );
}
