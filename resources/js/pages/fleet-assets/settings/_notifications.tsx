import {
    EntityContextMenu,
    useEntityContextMenu,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTimeLong } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Bell,
    CheckCircle2,
    Eye,
    Mail,
    Radio,
    RotateCcw,
    Shield,
    SlidersHorizontal,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    changedChannels,
    channelName,
    channels,
    clean,
    effective,
    mergeDraft,
    type Channel,
    type Check,
    type NotificationEvent,
    type NotificationSnapshot,
    type Overrides,
} from './_types';
import { api, Modal, Notice, Sections, SettingsError } from './_ui';

const sections = [
    { key: 'preferences', label: 'Preferences', icon: SlidersHorizontal },
    { key: 'delivery', label: 'Delivery & channels', icon: Radio },
    { key: 'rules', label: 'Rules & owners', icon: Shield },
];
type Recovery = { base: Overrides; draft: Overrides; revision: string };
export function Notifications({
    initial,
    userId,
    query,
    filter,
    scope,
    onDirty,
    canManageRoles,
}: {
    initial: NotificationSnapshot;
    userId: number;
    query: string;
    filter: string;
    scope: string;
    onDirty: (dirty: boolean) => void;
    canManageRoles: boolean;
}) {
    const [saved, setSaved] = useState(initial),
        [draft, setDraft] = useState<Overrides>(initial.overrides);
    const [section, setSection] = useState('preferences'),
        [busy, setBusy] = useState(false),
        [message, setMessage] = useState('');
    const [review, setReview] = useState(false),
        [discard, setDiscard] = useState(false),
        [latest, setLatest] = useState<NotificationSnapshot | null>(null);
    const [undo, setUndo] = useState<Overrides | null>(null),
        [recovery, setRecovery] = useState<Recovery | null>(null),
        [draftReady, setDraftReady] = useState(false);
    const [preview, setPreview] = useState<NotificationEvent | null>(null),
        [previewSection, setPreviewSection] = useState(0),
        [previewChannel, setPreviewChannel] = useState<Channel>('inapp');
    const [checkEvent, setCheckEvent] = useState(initial.events[0].key),
        [checks, setChecks] = useState<Check[]>([]),
        [check, setCheck] = useState<Check | null>(null);
    const context = useEntityContextMenu<NotificationEvent>();
    const storageKey = `fleet-settings.notifications.draft.${userId}`;
    const changes = changedChannels(saved.overrides, draft),
        dirty = changes.length > 0,
        personal = scope === 'personal';
    const values = personal ? draft : {};
    useEffect(() => {
        try {
            const stored = JSON.parse(
                localStorage.getItem(storageKey) ?? 'null',
            );
            const valid = (value: unknown): value is Overrides =>
                !!value &&
                typeof value === 'object' &&
                Object.entries(value).every(
                    ([key, entry]) =>
                        initial.events.some((event) => event.key === key) &&
                        !!entry &&
                        typeof entry === 'object' &&
                        Object.entries(entry).every(
                            ([channel, choice]) =>
                                channels.includes(channel as Channel) &&
                                typeof choice === 'boolean',
                        ),
                );
            if (
                stored &&
                valid(stored.base) &&
                valid(stored.draft) &&
                changedChannels(stored.base, stored.draft).length
            )
                setRecovery(stored);
        } catch {
            /* Storage is optional; saved preferences always come from the server. */
        } finally {
            setDraftReady(true);
        }
    }, [storageKey, initial.events]);
    useEffect(() => {
        onDirty(dirty || !!recovery);
        if (!draftReady || recovery) return;
        try {
            if (dirty)
                localStorage.setItem(
                    storageKey,
                    JSON.stringify({
                        base: saved.overrides,
                        draft,
                        revision: saved.revision,
                    }),
                );
            else localStorage.removeItem(storageKey);
        } catch {
            /* A disabled browser store must not prevent durable saves. */
        }
    }, [draft, saved, dirty, recovery, storageKey, onDirty, draftReady]);
    useEffect(() => {
        if (section === 'delivery')
            api<Check[]>('notification-checks')
                .then(setChecks)
                .catch((error) => setMessage(error.message));
    }, [section]);
    const visible = saved.events.filter(
        (event) =>
            (event.title + event.description + event.owner)
                .toLowerCase()
                .includes(query.toLowerCase()) &&
            (filter === 'all' ||
                (filter === 'overrides' && !!draft[event.key]) ||
                (filter === 'changed' &&
                    changes.some((change) => change.key === event.key)) ||
                (filter === 'inherited' && !draft[event.key])),
    );
    function reset(event?: NotificationEvent) {
        setUndo(structuredClone(draft));
        const next = { ...draft };
        if (event) delete next[event.key];
        else Object.keys(next).forEach((key) => delete next[key]);
        setDraft(clean(next));
        setMessage(
            'Defaults restored in your draft. Review and save to apply.',
        );
    }
    async function save() {
        setBusy(true);
        setMessage('');
        try {
            const result = await api<NotificationSnapshot>(
                'notification-preferences',
                'PUT',
                { revision: saved.revision, overrides: draft },
            );
            setSaved(result);
            setDraft(result.overrides);
            setUndo(null);
            setReview(false);
            setMessage('Your notification preferences are saved.');
        } catch (error) {
            if (
                error instanceof SettingsError &&
                error.status === 409 &&
                error.latest
            ) {
                setLatest(error.latest as NotificationSnapshot);
                setReview(false);
            } else
                setMessage(
                    error instanceof Error
                        ? error.message
                        : 'Could not save. Your draft is retained.',
                );
        } finally {
            setBusy(false);
        }
    }
    async function runCheck() {
        setBusy(true);
        setMessage('');
        try {
            const result = await api<Check>('notification-checks', 'POST', {
                key: checkEvent,
            });
            setCheck(result);
            setChecks(await api<Check[]>('notification-checks'));
        } catch (error) {
            setMessage(
                error instanceof Error
                    ? error.message
                    : 'The check could not complete.',
            );
        } finally {
            setBusy(false);
        }
    }
    const checkContext = useEntityContextMenu<Check>();
    const checkActions = (row: Check) => [
        { label: 'Review dry run', icon: Eye, onClick: () => setCheck(row) },
    ];
    const actions = (event: NotificationEvent) => [
        {
            label: 'Preview notification',
            icon: Eye,
            onClick: () => {
                setPreview(event);
                setPreviewSection(0);
            },
        },
        ...(personal && !busy && !recovery
            ? [
                  {
                      label: 'Use defaults',
                      icon: RotateCcw,
                      onClick: () => reset(event),
                  },
              ]
            : []),
        {
            label: 'Open source workspace',
            icon: ArrowUpRight,
            onClick: () => router.visit(event.href),
        },
    ];
    return (
        <div className="space-y-5">
            <Sections tabs={sections} value={section} onChange={setSection} />
            {message && (
                <div role="status">
                    <Notice>
                        {message}{' '}
                        {undo && (
                            <Button
                                variant="link"
                                onClick={() => {
                                    setDraft(undo);
                                    setUndo(null);
                                    setMessage('Draft restored.');
                                }}
                            >
                                Undo reset
                            </Button>
                        )}
                    </Notice>
                </div>
            )}
            {recovery && (
                <Notice>
                    You have an unsaved draft on this browser.{' '}
                    <Button
                        variant="outline"
                        onClick={() => {
                            setDraft(
                                mergeDraft(
                                    recovery.base,
                                    recovery.draft,
                                    saved.overrides,
                                ),
                            );
                            setRecovery(null);
                            setMessage(
                                'Draft recovered. Review it against the current saved preferences.',
                            );
                        }}
                    >
                        Resume draft
                    </Button>{' '}
                    <Button variant="ghost" onClick={() => setRecovery(null)}>
                        Discard browser draft
                    </Button>
                </Notice>
            )}
            {section === 'preferences' && (
                <>
                    <ListCaption
                        title={
                            personal
                                ? 'Your optional notifications'
                                : 'Your inherited defaults'
                        }
                        caption={`${visible.length} of ${saved.events.length} event types shown`}
                        right={
                            personal && (
                                <Button
                                    variant="outline"
                                    disabled={busy || !!recovery}
                                    onClick={() => reset()}
                                >
                                    <RotateCcw className="size-4" />
                                    Use defaults
                                </Button>
                            )
                        }
                    />
                    {!personal && (
                        <Notice>
                            These are the effective defaults for your current
                            roles. A personal choice can override an optional
                            channel. Defaults never grant access to a source
                            record.
                        </Notice>
                    )}
                    {visible.length ? (
                        <EntityTable<NotificationEvent>
                            rows={visible}
                            rowKey={(event) => event.key}
                            identityLabel="Event"
                            identity={(event) => ({
                                icon: Bell,
                                name: event.title,
                                subline: event.description,
                            })}
                            identityWidth="2fr"
                            minWidth={740}
                            actionsFor={actions}
                            onOpen={setPreview}
                            onRowContextMenu={context.open}
                            columns={channels.map((channel) => ({
                                key: channel,
                                label: channelName(channel),
                                width: '1fr',
                                cell: (event) => (
                                    <div
                                        className="flex items-center gap-3"
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <Switch
                                            aria-label={`${event.title} ${channelName(channel)}`}
                                            checked={effective(
                                                event,
                                                values,
                                                channel,
                                            )}
                                            disabled={
                                                !personal || busy || !!recovery
                                            }
                                            onCheckedChange={(choice) => {
                                                setDraft(
                                                    clean({
                                                        ...draft,
                                                        [event.key]: {
                                                            ...draft[event.key],
                                                            [channel]: choice,
                                                        },
                                                    }),
                                                );
                                                setUndo(null);
                                            }}
                                        />
                                        <div>
                                            <span className="text-subtle">
                                                {effective(
                                                    event,
                                                    values,
                                                    channel,
                                                )
                                                    ? 'On'
                                                    : 'Off'}
                                            </span>
                                            <div className="text-caption">
                                                {values[event.key]?.[
                                                    channel
                                                ] === undefined
                                                    ? event.defaultSource
                                                    : 'Personal choice'}
                                            </div>
                                        </div>
                                    </div>
                                ),
                            }))}
                        />
                    ) : (
                        <EmptyState
                            icon={Bell}
                            title="No matching notification settings"
                            description="Change your search or filter to see more event types."
                        />
                    )}
                    <Notice>
                        Required safety responses remain in Control Room.
                        Preferences change optional copies only; delivery does
                        not complete a task or grant personal-location sharing.
                    </Notice>
                    {personal && (
                        <Card className="sticky bottom-0 z-10 flex flex-row flex-wrap items-center justify-between gap-3 p-4">
                            <span className="text-subtle">
                                {dirty
                                    ? `${changes.length} channel ${changes.length === 1 ? 'change' : 'changes'} to review`
                                    : 'Your saved preferences are up to date'}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    disabled={!dirty || busy}
                                    onClick={() => setDiscard(true)}
                                >
                                    Discard changes
                                </Button>
                                <Button
                                    disabled={!dirty || busy || !!recovery}
                                    onClick={() => setReview(true)}
                                >
                                    Review changes
                                </Button>
                            </div>
                        </Card>
                    )}
                </>
            )}
            {section === 'delivery' && (
                <>
                    <ListCaption
                        title="Delivery & channels"
                        caption="Configuration presence, not a delivery guarantee"
                    />
                    <div className="grid gap-5 lg:grid-cols-2">
                        {channels.map((channel) => (
                            <ReviewCard
                                key={channel}
                                icon={channel === 'inapp' ? Bell : Mail}
                                title={saved.channels[channel].label}
                            >
                                <p className="text-subtle">
                                    {saved.channels[channel].detail}
                                </p>
                                <StatusBadge
                                    variant={
                                        saved.channels[channel].available
                                            ? 'info'
                                            : 'neutral'
                                    }
                                    className="mt-3"
                                >
                                    {saved.channels[channel].available
                                        ? 'Configured'
                                        : 'Not configured'}
                                </StatusBadge>
                            </ReviewCard>
                        ))}
                    </div>
                    <ReviewCard icon={Radio} title="Check saved choices">
                        <p className="text-subtle mb-4">
                            This dry run evaluates a synthetic example against
                            your saved choices. It does not send a message, test
                            recipient access or verify provider delivery.
                            Unsaved changes are excluded.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            <Select
                                value={checkEvent}
                                onValueChange={setCheckEvent}
                            >
                                <SelectTrigger
                                    className="w-72"
                                    aria-label="Event to check"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {saved.events.map((event) => (
                                        <SelectItem
                                            key={event.key}
                                            value={event.key}
                                        >
                                            {event.title}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button disabled={busy} onClick={runCheck}>
                                Check saved choices
                            </Button>
                        </div>
                    </ReviewCard>
                    <ListCaption
                        title="Recent dry runs"
                        caption="Your last five checks"
                    />
                    {checks.length ? (
                        <EntityTable
                            rows={checks}
                            rowKey={(row) => row.id ?? row.checked_at}
                            identity={(row) => ({
                                icon: Radio,
                                name: row.event,
                                subline: 'Synthetic dry run',
                            })}
                            identityLabel="Check"
                            minWidth={600}
                            columns={[
                                {
                                    key: 'time',
                                    label: 'Checked',
                                    width: '1fr',
                                    cell: (row) =>
                                        formatDateTimeLong(row.checked_at),
                                },
                            ]}
                            onOpen={setCheck}
                            onRowContextMenu={checkContext.open}
                            actionsFor={checkActions}
                        />
                    ) : (
                        <EmptyState
                            icon={Radio}
                            title="No checks yet"
                            description="Run a dry check to inspect your saved channel choices."
                        />
                    )}
                </>
            )}
            {section === 'rules' && (
                <div className="grid gap-5 lg:grid-cols-2">
                    <ReviewCard
                        icon={Shield}
                        title="Safety and location response"
                    >
                        <p className="text-subtle">
                            Control Room owns alert assignment, acknowledgement
                            and escalation. Optional preferences cannot dismiss
                            that work.
                        </p>
                        <Button
                            variant="link"
                            onClick={() => router.visit('/control-room')}
                        >
                            Open Control Room{' '}
                            <ArrowUpRight className="size-4" />
                        </Button>
                    </ReviewCard>
                    <ReviewCard icon={CheckCircle2} title="Assigned work">
                        <p className="text-subtle">
                            Delivery is not completion. Use the source task or
                            booking to record a response and preserve its
                            history.
                        </p>
                        <Button
                            variant="link"
                            onClick={() =>
                                router.visit(
                                    '/fleet-assets/maintenance/work-orders',
                                )
                            }
                        >
                            Open Maintenance <ArrowUpRight className="size-4" />
                        </Button>
                    </ReviewCard>
                    <ReviewCard icon={Shield} title="Privacy and sharing">
                        <p className="text-subtle">
                            Source workflows check authorised recipients. These
                            choices cannot grant consent, family access or
                            permission to view another person's location.
                        </p>
                    </ReviewCard>
                    <ReviewCard icon={SlidersHorizontal} title="Role defaults">
                        <p className="text-subtle">
                            An access administrator manages defaults in
                            Application settings. Any enabled role can supply an
                            optional channel; your explicit choices take
                            precedence.
                        </p>
                        {canManageRoles && (
                            <Button
                                variant="link"
                                onClick={() =>
                                    router.visit(
                                        '/settings/notifications/roles',
                                    )
                                }
                            >
                                Manage role defaults{' '}
                                <ArrowUpRight className="size-4" />
                            </Button>
                        )}
                    </ReviewCard>
                </div>
            )}
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={context.ctx.record.title}
                    items={actions(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            {preview && (
                <WizardShell
                    open
                    onClose={() => setPreview(null)}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        document
                            .querySelector<HTMLButtonElement>(
                                `button[aria-label="Actions for ${preview.title}"]`,
                            )
                            ?.focus();
                    }}
                    title="Notification preview"
                    description="Synthetic sample; no message is sent."
                    railIcon={Bell}
                    railTitle={preview.title}
                    railSub="Synthetic sample"
                    steps={[
                        {
                            key: 'sample',
                            label: 'Sample',
                            blurb: 'Preview channel choices',
                            icon: Eye,
                        },
                        {
                            key: 'source',
                            label: 'Rules & source',
                            blurb: 'Inheritance and response',
                            icon: Shield,
                        },
                    ]}
                    stepIndex={previewSection}
                    onStepClick={setPreviewSection}
                    sequential={false}
                    pct={null}
                    headerLabel={
                        previewSection
                            ? 'Rules & source'
                            : 'Notification preview'
                    }
                    footerEnd={
                        <Button
                            variant="outline"
                            onClick={() => setPreview(null)}
                        >
                            Close preview
                        </Button>
                    }
                >
                    <WizardStepPane key={previewSection}>
                        {previewSection === 0 ? (
                            <div className="space-y-4">
                                <Sections
                                    tabs={[
                                        {
                                            key: 'inapp',
                                            label: 'In-app',
                                            icon: Bell,
                                        },
                                        {
                                            key: 'email',
                                            label: 'Email',
                                            icon: Mail,
                                        },
                                    ]}
                                    value={previewChannel}
                                    onChange={(value) =>
                                        setPreviewChannel(value as Channel)
                                    }
                                />
                                <Notice>
                                    {personal && dirty
                                        ? 'Showing your unsaved draft.'
                                        : personal
                                          ? 'Showing your saved choices.'
                                          : 'Showing inherited defaults.'}{' '}
                                    {effective(preview, values, previewChannel)
                                        ? 'This optional channel is selected.'
                                        : 'This optional copy would be skipped.'}
                                </Notice>
                                <ReviewCard
                                    icon={Bell}
                                    title={`${preview.title} · example`}
                                >
                                    <p className="text-subtle">
                                        Your sample source record has an update.
                                        Open it to review the current result and
                                        any work required.
                                    </p>
                                    <p className="text-caption mt-3">
                                        Sample content only. No real person,
                                        vehicle or event is used.
                                    </p>
                                </ReviewCard>
                            </div>
                        ) : (
                            <ReviewCard
                                icon={Shield}
                                title="Effective preference"
                            >
                                {channels.map((channel) => (
                                    <ReviewRow
                                        key={channel}
                                        label={channelName(channel)}
                                        value={`${effective(preview, values, channel) ? 'On' : 'Off'} · ${values[preview.key]?.[channel] === undefined ? preview.defaultSource : 'Personal choice'}`}
                                    />
                                ))}
                                <ReviewRow
                                    label="Source owner"
                                    value={preview.owner}
                                />
                                <p className="text-subtle mt-4">
                                    Source access is checked separately. An
                                    optional copy does not replace an assigned
                                    response.
                                </p>
                            </ReviewCard>
                        )}
                    </WizardStepPane>
                </WizardShell>
            )}
            {review && (
                <Modal
                    title="Review notification changes"
                    description="These choices affect your optional copies. Required responses remain with their source owners."
                    onClose={() => !busy && setReview(false)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setReview(false)}
                            >
                                Back to preferences
                            </Button>
                            <Button disabled={busy} onClick={save}>
                                {busy ? 'Saving…' : 'Save preferences'}
                            </Button>
                        </>
                    }
                >
                    {changes.map(({ key, channel }) => {
                        const event = saved.events.find(
                            (item) => item.key === key,
                        )!;
                        return (
                            <ReviewCard
                                key={key + channel}
                                icon={Bell}
                                title={event.title}
                            >
                                <ReviewRow
                                    label={channelName(channel)}
                                    value={`${effective(event, saved.overrides, channel) ? 'On' : 'Off'} → ${effective(event, draft, channel) ? 'On' : 'Off'}`}
                                />
                                <ReviewRow
                                    label="New source"
                                    value={
                                        draft[key]?.[channel] === undefined
                                            ? event.defaultSource
                                            : 'Personal choice'
                                    }
                                />
                            </ReviewCard>
                        );
                    })}
                    {message && <Notice>{message}</Notice>}
                </Modal>
            )}
            {discard && (
                <Modal
                    title="Discard notification changes?"
                    description="Your saved preferences will be kept."
                    onClose={() => setDiscard(false)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDiscard(false)}
                            >
                                Keep editing
                            </Button>
                            <Button
                                onClick={() => {
                                    setDraft(saved.overrides);
                                    setUndo(null);
                                    setDiscard(false);
                                }}
                            >
                                Discard changes
                            </Button>
                        </>
                    }
                >
                    Your unsaved channel choices will be removed.
                </Modal>
            )}
            {latest && (
                <Modal
                    title="Preferences changed elsewhere"
                    description="Compare your draft with the newest saved choices. Keep your edited channels and adopt current values for the others."
                    onClose={() => setLatest(null)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setLatest(null)}
                            >
                                Keep editing
                            </Button>
                            <Button
                                onClick={() => {
                                    setDraft(
                                        mergeDraft(
                                            saved.overrides,
                                            draft,
                                            latest.overrides,
                                        ),
                                    );
                                    setSaved(latest);
                                    setLatest(null);
                                    setMessage(
                                        'Draft updated against current preferences. Review and save again.',
                                    );
                                }}
                            >
                                Keep my edited channels
                            </Button>
                        </>
                    }
                >
                    {changes.map(({ key, channel }) => {
                        const event = latest.events.find(
                            (item) => item.key === key,
                        )!;
                        return (
                            <ReviewCard
                                key={key + channel}
                                icon={Bell}
                                title={event.title}
                            >
                                <ReviewRow
                                    label={`${channelName(channel)} · current`}
                                    value={
                                        effective(
                                            event,
                                            latest.overrides,
                                            channel,
                                        )
                                            ? 'On'
                                            : 'Off'
                                    }
                                />
                                <ReviewRow
                                    label="Your draft"
                                    value={
                                        effective(event, draft, channel)
                                            ? 'On'
                                            : 'Off'
                                    }
                                />
                            </ReviewCard>
                        );
                    })}
                </Modal>
            )}
            {checkContext.ctx && (
                <EntityContextMenu
                    x={checkContext.ctx.x}
                    y={checkContext.ctx.y}
                    title={checkContext.ctx.record.event}
                    items={checkActions(checkContext.ctx.record)}
                    onClose={checkContext.close}
                />
            )}
            {check && (
                <Modal
                    title={`${check.event} · dry run`}
                    description="No notification was sent. Delivery and acknowledgement are not tested."
                    onClose={() => setCheck(null)}
                >
                    <p className="text-subtle">{check.source}</p>
                    <ReviewCard icon={Radio} title="Saved-choice evaluation">
                        <ReviewRow label="In-app" value={check.inapp} />
                        <ReviewRow label="Email" value={check.email} />
                        <ReviewRow
                            label="Checked"
                            value={formatDateTimeLong(check.checked_at)}
                        />
                        <ReviewRow
                            label="Preference revision"
                            value={check.revision.slice(0, 12)}
                        />
                    </ReviewCard>
                </Modal>
            )}
        </div>
    );
}
