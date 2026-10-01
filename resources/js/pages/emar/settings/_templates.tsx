/* Medication › Settings › Rounds & timing › Round templates (eMAR P11 v5
 * `settings.tsx` RoundTemplates, `dialogs-settings.tsx` TemplateWizard /
 * TemplateView / TemplateToggle / CreateRounds). Moved here from Meds today ›
 * Rounds. Who can change a house's templates is unchanged: people who manage
 * orders at that house (all houses for an organisation-wide template); the
 * existing round-template endpoints enforce it. Every change is recorded in
 * the change history and the audit log. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { EntityChip } from '@/components/lists/entity-cells';
import {
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    ChipMulti,
    Field,
    InfoCard,
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
import { WORKER_TIMEZONE } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    Eye,
    Home,
    Layers,
    Pause,
    Pencil,
    Play,
    Plus,
    Repeat,
    Trash2,
    User,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useSettings, type Dialog } from './_context';
import { dayText, ERROR_BAG, NotFound } from './_dialogs';
import {
    Note,
    NumberInput,
    OnOff,
    RecordPicker,
    RowMenu,
    Section,
    type PickItem,
} from './_ui';

export type RoundTemplate = {
    id: number;
    name: string;
    /** "HH:mm", Pacific/Auckland. */
    scheduled_time: string;
    window_minutes: number;
    /** ISO weekdays, 1 = Monday; empty = every day. */
    days_of_week: number[];
    status: 'active' | 'paused' | 'retired';
    site_id: number | null;
    site_name: string | null;
    default_assigned_to: number | null;
    default_staff: string | null;
    /** Today's round from this template, or null when there isn't one. */
    today: { doses: number; people: number } | null;
    last_changed_by: string | null;
    last_changed_at: string | null;
    can_change: boolean;
};
export type TemplateAccess = {
    manage: boolean;
    all_houses: boolean;
    /** Houses where this person can add a template. */
    sites: { id: number; name: string }[];
};
export type TemplateStaff = { id: number; name: string; site_ids: number[] };
export type TemplateData = {
    templates: RoundTemplate[];
    access: TemplateAccess;
    staff: TemplateStaff[];
    readOnlyAudit: boolean;
};

/** A template without a house never generates rounds; it is never shown as "all houses". */
export const LEGACY_HOUSE = 'No house assigned (legacy template)';
const DAYS: [number, string][] = [
    [1, 'Mon'],
    [2, 'Tue'],
    [3, 'Wed'],
    [4, 'Thu'],
    [5, 'Fri'],
    [6, 'Sat'],
    [7, 'Sun'],
];

/** "08:00" → "8:00 am". */
export function clockText(time: string): string {
    const [h, m] = time.split(':').map(Number);
    return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'am' : 'pm'}`;
}

export function daysText(days: number[]): string {
    const d = [...new Set(days)].sort();
    if (!d.length || d.length === 7) return 'Every day';
    if (d.join() === '1,2,3,4,5') return 'Monday to Friday';
    if (d.join() === '6,7') return 'Saturday and Sunday';
    return DAYS.filter(([k]) => d.includes(k))
        .map(([, l]) => l)
        .join(', ');
}

const toMin = (time: string) => {
    const [h, m] = time.split(':').map(Number);
    return h * 60 + m;
};
export const houseOf = (t: Pick<RoundTemplate, 'site_id' | 'site_name'>) =>
    t.site_id === null ? LEGACY_HOUSE : (t.site_name ?? 'House unavailable');
/** A paused template with no house can't be turned on until it has one. */
export const needsHouse = (t: Pick<RoundTemplate, 'site_id' | 'status'>) =>
    t.site_id === null && t.status === 'paused';

type Shape = Pick<
    RoundTemplate,
    | 'id'
    | 'name'
    | 'site_id'
    | 'scheduled_time'
    | 'window_minutes'
    | 'days_of_week'
>;

/** Active templates at the same house whose windows overlap on a shared day. */
export function templateOverlaps(all: RoundTemplate[], t: Shape) {
    const a0 = toMin(t.scheduled_time) - t.window_minutes;
    const a1 = toMin(t.scheduled_time) + t.window_minutes;
    const every = [1, 2, 3, 4, 5, 6, 7];
    const days = t.days_of_week.length ? t.days_of_week : every;
    return all.filter(
        (o) =>
            o.id !== t.id &&
            o.site_id === t.site_id &&
            o.status === 'active' &&
            (o.days_of_week.length ? o.days_of_week : every).some((d) =>
                days.includes(d),
            ) &&
            toMin(o.scheduled_time) - o.window_minutes < a1 &&
            toMin(o.scheduled_time) + o.window_minutes > a0,
    );
}

const match = (q: string, ...s: (string | null | undefined)[]) =>
    !q || s.some((x) => (x ?? '').toLowerCase().includes(q.toLowerCase()));

export const TEMPLATE_STATUS_OPTIONS = [
    { value: 'current', label: 'Active and paused' },
    { value: 'active', label: 'Active' },
    { value: 'paused', label: 'Paused' },
    { value: 'retired', label: 'Retired' },
    { value: 'all', label: 'All, including retired' },
];

const Recorded = () => (
    <p className="text-caption">
        Recorded in the change history and the audit log with your name and the
        time.
    </p>
);

/* ── Rounds & timing › Round templates ── */
export function RoundTemplates({
    data,
    q,
    house,
    status,
    clear,
}: {
    data: TemplateData;
    q: string;
    house: string;
    status: string;
    clear: () => void;
}) {
    const { open } = useSettings();
    const menu = useEntityContextMenu<RoundTemplate>();
    const all = data.templates;
    const canAny = data.access.manage;
    const rows = all.filter(
        (t) =>
            (house === 'all' || String(t.site_id ?? 'all-houses') === house) &&
            (status === 'current'
                ? t.status !== 'retired'
                : status === 'all' || t.status === status) &&
            match(q, t.name, houseOf(t), t.default_staff),
    );
    const actions = (t: RoundTemplate): MenuItem[] =>
        compactMenu([
            t.can_change
                ? {
                      label: 'Edit template',
                      icon: Pencil,
                      onClick: () => open({ kind: 'tpl', id: t.id }),
                  }
                : {
                      label: 'View template',
                      icon: Eye,
                      onClick: () => open({ kind: 'tplview', id: t.id }),
                  },
            t.can_change &&
                !needsHouse(t) && {
                    label:
                        t.status === 'active'
                            ? 'Pause — stop creating rounds'
                            : 'Turn back on — create rounds again',
                    icon: t.status === 'active' ? Pause : Play,
                    onClick: () => open({ kind: 'tpltoggle', id: t.id }),
                },
            t.can_change && { separator: true },
            t.can_change && {
                label: 'Retire template',
                icon: Trash2,
                danger: true,
                onClick: () => open({ kind: 'tplretire', id: t.id }),
            },
        ]);
    const right = canAny ? (
        <>
            <Button
                variant="outline"
                size="sm"
                onClick={() => open({ kind: 'gen' })}
            >
                <CalendarDays />
                Create rounds for a day
            </Button>
            <Button size="sm" onClick={() => open({ kind: 'tpl', id: 'new' })}>
                <Plus />
                Add a template
            </Button>
        </>
    ) : null;
    return (
        <Section
            id="sc-tpl"
            title="Round templates"
            caption={
                all.length
                    ? `${rows.length} of ${all.length} shown`
                    : 'None yet'
            }
            right={right}
        >
            <p className="text-subtle">
                When each medication round happens at a house. Rounds are
                created from active templates at 12:05 am every day; today’s
                rounds are in Meds today › Rounds.
            </p>
            {!all.length ? (
                <EmptyState
                    icon={Repeat}
                    title="No round templates yet"
                    description="Add one for each time doses are usually given at this house. Until then, doses still show on Meds today — rounds just aren’t created."
                    action={
                        canAny ? (
                            <Button
                                size="sm"
                                onClick={() => open({ kind: 'tpl', id: 'new' })}
                            >
                                <Plus />
                                Add a template
                            </Button>
                        ) : undefined
                    }
                />
            ) : rows.length ? (
                <EntityTable<RoundTemplate>
                    rows={rows}
                    rowKey={(t) => t.id}
                    identityLabel="Round"
                    identityWidth="1.8fr"
                    minWidth={900}
                    mutedFor={(t) => t.status === 'retired'}
                    identity={(t) => ({
                        icon: Repeat,
                        name: t.name,
                        subline: `${clockText(t.scheduled_time)}, ${t.window_minutes} minutes either side · ${daysText(t.days_of_week)}`,
                    })}
                    columns={[
                        {
                            key: 'house',
                            label: 'House',
                            width: '1fr',
                            cell: (t) => (
                                <EntityChip
                                    icon={t.site_id === null ? Building2 : Home}
                                >
                                    {houseOf(t)}
                                </EntityChip>
                            ),
                        },
                        {
                            key: 'who',
                            label: 'Staff',
                            width: '1.3fr',
                            cell: (t) =>
                                t.default_staff ? (
                                    <div>
                                        <div className="text-[13px]">
                                            {t.default_staff}
                                        </div>
                                        <div className="text-caption">
                                            Default — can change on the day
                                        </div>
                                    </div>
                                ) : (
                                    <span className="text-[13px]">
                                        Everyone rostered on a covering shift
                                    </span>
                                ),
                        },
                        {
                            key: 'today',
                            label: 'Today',
                            width: '0.9fr',
                            cell: (t) =>
                                t.status === 'active' && t.today ? (
                                    <span className="text-[13px]">
                                        {t.today.doses}{' '}
                                        {t.today.doses === 1 ? 'dose' : 'doses'}{' '}
                                        · {t.today.people}{' '}
                                        {t.today.people === 1
                                            ? 'person'
                                            : 'people'}
                                    </span>
                                ) : (
                                    <span className="text-caption">
                                        No round
                                    </span>
                                ),
                        },
                        {
                            key: 'active',
                            label: 'Active',
                            width: '1fr',
                            cell: (t) =>
                                t.status === 'retired' ? (
                                    <StatusBadge variant="neutral" size="sm">
                                        Retired{' '}
                                        {t.last_changed_at
                                            ? dayText(t.last_changed_at)
                                            : ''}
                                    </StatusBadge>
                                ) : (
                                    <div onClick={(e) => e.stopPropagation()}>
                                        <OnOff
                                            id={`ts-${t.id}`}
                                            checked={t.status === 'active'}
                                            disabled={
                                                !t.can_change || needsHouse(t)
                                            }
                                            label={`Create rounds from ${t.name}, ${houseOf(t)}`}
                                            onChange={() =>
                                                open({
                                                    kind: 'tpltoggle',
                                                    id: t.id,
                                                })
                                            }
                                        />
                                    </div>
                                ),
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={(t) =>
                        open({
                            kind: t.can_change ? 'tpl' : 'tplview',
                            id: t.id,
                        })
                    }
                    onRowContextMenu={menu.open}
                />
            ) : (
                <EmptyState
                    icon={Repeat}
                    title="No templates match these filters"
                    description="Clear the filters or the search to see every template."
                    action={
                        <Button variant="outline" size="sm" onClick={clear}>
                            Clear filters
                        </Button>
                    }
                />
            )}
            <RowMenu
                ctx={menu.ctx}
                close={menu.close}
                icon={Repeat}
                title={(t) => `${t.name} — ${houseOf(t)}`}
                items={actions}
            />
            {canAny ? null : (
                <Note>
                    Only people who manage orders at a house can change its
                    round templates
                    {data.readOnlyAudit ? '' : ' — for example the house lead'}.
                </Note>
            )}
        </Section>
    );
}

/* ── The day at a house: every active template's window, this one highlighted ── */
function DayTimeline({
    t,
    all,
    house,
}: {
    t: Shape;
    all: RoundTemplate[];
    house: string;
}) {
    const start = 6 * 60;
    const span = 17 * 60;
    const pos = (x: number) =>
        Math.max(0, Math.min(100, ((x - start) / span) * 100));
    const blocks = [
        ...all
            .filter(
                (o) =>
                    o.id !== t.id &&
                    o.site_id === t.site_id &&
                    o.status === 'active',
            )
            .map((o) => ({ o: o as Shape, mine: false })),
        { o: t, mine: true },
    ];
    return (
        <div className="rounded-xl border p-3">
            <p className="text-caption mb-2">
                Rounds at {house} across the day
            </p>
            <div
                className="relative h-8 rounded-md bg-muted"
                role="img"
                aria-label={`Rounds at ${house}: ${blocks.map(({ o }) => `${o.name || 'this round'} ${clockText(o.scheduled_time)}`).join(', ')}`}
            >
                {blocks.map(({ o, mine }, i) => {
                    const w = o.window_minutes || 0;
                    const l = pos(toMin(o.scheduled_time) - w);
                    const r = pos(toMin(o.scheduled_time) + w);
                    return (
                        <span
                            key={i}
                            className={
                                mine
                                    ? 'absolute top-1 bottom-1 rounded bg-primary text-[10px] font-semibold text-primary-foreground'
                                    : 'absolute top-1 bottom-1 rounded bg-primary/25 text-[10px] text-foreground'
                            }
                            style={{
                                left: `${l}%`,
                                width: `${Math.max(1.5, r - l)}%`,
                            }}
                        >
                            {r - l > 7 ? (
                                <span className="px-1">
                                    {clockText(o.scheduled_time)}
                                </span>
                            ) : null}
                        </span>
                    );
                })}
            </div>
            <div className="text-caption relative mt-1 h-4" aria-hidden="true">
                {(
                    [
                        [6, '6 am'],
                        [12, '12 pm'],
                        [18, '6 pm'],
                        [23, '11 pm'],
                    ] as const
                ).map(([h, l]) => (
                    <span
                        key={l}
                        className="absolute -translate-x-1/2 first:translate-x-0 last:-translate-x-full"
                        style={{ left: `${pos(h * 60)}%` }}
                    >
                        {l}
                    </span>
                ))}
            </div>
        </div>
    );
}

/* ── Add / edit: WizardShell (When · Who · Review & save) with the approved clock ── */
const TPL_STEPS = [
    { key: 'when', label: 'When', blurb: 'Time, window and days', icon: Clock },
    { key: 'who', label: 'Who', blurb: 'Staff for the round', icon: Users },
    {
        key: 'review',
        label: 'Review & save',
        blurb: 'Check the day',
        icon: Check,
    },
] as const;

type TemplateDraft = {
    name: string;
    site: string;
    time: string;
    win: string;
    everyDay: boolean;
    days: number[];
    mode: 'all' | 'one';
    who: number | null;
    active: boolean;
};

export function TemplateWizard({
    id,
    data,
}: {
    id: number | 'new';
    data: TemplateData;
}) {
    const { close, open } = useSettings();
    const src =
        id === 'new' ? undefined : data.templates.find((t) => t.id === id);
    const [t, setT] = useState<TemplateDraft>(() =>
        src
            ? {
                  name: src.name,
                  site: String(src.site_id ?? ''),
                  time: src.scheduled_time,
                  win: String(src.window_minutes),
                  everyDay: !src.days_of_week.length,
                  days: [...src.days_of_week],
                  mode: src.default_assigned_to ? 'one' : 'all',
                  who: src.default_assigned_to,
                  active: src.status === 'active',
              }
            : {
                  name: '',
                  site:
                      data.access.sites.length === 1
                          ? String(data.access.sites[0].id)
                          : '',
                  time: '08:00',
                  win: '60',
                  everyDay: true,
                  days: [],
                  mode: 'all',
                  who: null,
                  active: true,
              },
    );
    const [step, setStep] = useState(0);
    const [errs, setErrs] = useState<Record<string, string>>({});
    const [problem, setProblem] = useState('');
    const [dirty, setDirty] = useState(false);
    const [saved, setSaved] = useState(false);
    const [saving, setSaving] = useState(false);
    const [guard, setGuard] = useState(false);
    if (id !== 'new' && (!src || !src.can_change))
        return <NotFound what="template" />;

    const up = (patch: Partial<TemplateDraft>) => {
        setT({ ...t, ...patch });
        setDirty(true);
        setProblem('');
    };
    const choosesHouse = !src || src.site_id === null;
    const chosen = data.access.sites.find((s) => String(s.id) === t.site);
    const house =
        src && !choosesHouse
            ? houseOf(src)
            : (chosen?.name ?? (src ? LEGACY_HOUSE : 'this house'));
    const siteId =
        src && !choosesHouse ? src.site_id : chosen ? chosen.id : null;
    const win = Number(t.win);
    const days = t.everyDay ? [] : t.days;
    const shape: Shape = {
        id: src?.id ?? 0,
        name: t.name,
        site_id: siteId,
        scheduled_time: t.time,
        window_minutes: Number.isInteger(win) ? win : 0,
        days_of_week: days,
    };
    const people = data.staff.filter(
        (s) => siteId !== null && s.site_ids.includes(siteId),
    );
    const whoName =
        data.staff.find((s) => s.id === t.who)?.name ??
        (src && src.default_assigned_to === t.who ? src.default_staff : null);
    const validate = (only?: number) => {
        const e: Record<string, string> = {};
        if (only == null || only === 0) {
            if (!t.name.trim()) e.name = 'Give the round a name.';
            if (!src && !t.site) e.site = 'Choose a house.';
            else if (choosesHouse && t.active && !t.site)
                e.site = 'Choose a house before turning this template on.';
            if (!/^\d{2}:\d{2}$/.test(t.time))
                e.time = 'Choose the round time.';
            if (!Number.isInteger(win) || win < 5 || win > 120)
                e.win = 'Enter a whole number of minutes from 5 to 120.';
            if (!t.everyDay && !t.days.length)
                e.days = 'Choose at least one day, or turn on Every day.';
        }
        if ((only == null || only === 1) && t.mode === 'one' && !t.who)
            e.who =
                'Choose the default staff member, or choose everyone rostered.';
        return e;
    };
    const next = () => {
        const e = validate(step);
        setErrs(e);
        if (!Object.keys(e).length) setStep(Math.min(2, step + 1));
    };
    const sentence = `${t.name.trim() || 'This round'} at ${house}: ${clockText(t.time)}, doses due ${t.win || '…'} minutes either side · ${daysText(days).toLowerCase()} · ${t.mode === 'one' && whoName ? `default staff ${whoName}` : 'everyone rostered on a covering shift'}.`;
    const overlaps = templateOverlaps(data.templates, shape);
    const save = () => {
        const e = validate();
        setErrs(e);
        if (Object.keys(e).length) {
            setStep(e.name || e.site || e.time || e.win || e.days ? 0 : 1);
            return;
        }
        const payload = {
            name: t.name.trim(),
            scheduled_time: t.time,
            window_minutes: win,
            days_of_week: days,
            default_assigned_to: t.mode === 'one' ? t.who : null,
            active: t.active,
            ...(choosesHouse && t.site ? { site_id: Number(t.site) } : {}),
        };
        const options = {
            preserveScroll: true,
            preserveState: true,
            errorBag: ERROR_BAG,
            onStart: () => setSaving(true),
            onSuccess: () => {
                setSaved(true);
                setDirty(false);
            },
            onError: (errors: Record<string, string>) =>
                setProblem(
                    Object.values(errors)[0] ??
                        'Couldn’t save the template — nothing was changed.',
                ),
            onFinish: () => setSaving(false),
        };
        if (src)
            router.put(`/emar/rounds/templates/${src.id}`, payload, options);
        else router.post('/emar/rounds/templates', payload, options);
    };
    const onClose = () => (dirty && !saved ? setGuard(true) : close());
    const staffItems: PickItem[] = people.map((p) => ({
        id: String(p.id),
        name: p.name,
        sub: `Works at ${house}`,
        ok: true,
    }));
    const pct = Math.round(
        ([
            t.name.trim(),
            src || t.site,
            t.time,
            t.win,
            t.mode === 'all' || t.who,
        ].filter(Boolean).length /
            5) *
            100,
    );
    return (
        <>
            <WizardShell
                open
                onClose={onClose}
                title={src ? 'Edit round template' : 'Add a round template'}
                description="When a medication round happens at a house."
                railIcon={Repeat}
                railTitle={src ? 'Edit round template' : 'Add a round template'}
                railSub="Settings › Rounds & timing"
                steps={TPL_STEPS}
                stepIndex={step}
                onStepClick={(i) => setStep(i)}
                pct={pct}
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={src ? 'Template updated' : 'Template added'}
                            blurb={
                                <>
                                    {sentence}
                                    <br />
                                    {t.active
                                        ? 'Rounds are created from it from tomorrow (12:05 am). Today’s rounds keep their times.'
                                        : 'Saved as paused — no rounds are created until someone turns it on.'}
                                </>
                            }
                            actions={
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => open({ kind: 'gen' })}
                                    >
                                        Create rounds for a day
                                    </Button>
                                    <Button onClick={close} autoFocus>
                                        Done
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
                footerStart={
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        {step > 0 ? (
                            <Button
                                variant="outline"
                                onClick={() => setStep(step - 1)}
                            >
                                <ChevronLeft />
                                Back
                            </Button>
                        ) : null}
                    </div>
                }
                footerEnd={
                    step < 2 ? (
                        <Button onClick={next}>
                            Continue
                            <ChevronRight />
                        </Button>
                    ) : (
                        <Button onClick={save} disabled={saving}>
                            <Check />
                            {src ? 'Save changes' : 'Save template'}
                        </Button>
                    )
                }
            >
                <WizardStepPane key={step}>
                    {problem ? (
                        <SettingsNotice>
                            <span>
                                <b>Couldn’t save — nothing was changed.</b>{' '}
                                {problem}
                            </span>
                        </SettingsNotice>
                    ) : null}
                    {step === 0 ? (
                        <div className="space-y-5">
                            <StepHead
                                icon={Clock}
                                title="When"
                                blurb="The round time, how long doses count as due, and which days."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Name"
                                    required
                                    error={errs.name}
                                    htmlFor="tw-name"
                                    errorId="tw-name-error"
                                >
                                    <Input
                                        id="tw-name"
                                        value={t.name}
                                        placeholder="For example: Morning round"
                                        maxLength={255}
                                        aria-invalid={!!errs.name || undefined}
                                        onChange={(e) => {
                                            up({ name: e.target.value });
                                            setErrs({ ...errs, name: '' });
                                        }}
                                    />
                                </Field>
                                {!choosesHouse ? (
                                    <Field label="House">
                                        <InfoCard icon={Home}>
                                            <b>{house}</b> — a template stays
                                            with its house.
                                        </InfoCard>
                                    </Field>
                                ) : (
                                    <RecordPicker
                                        id="tw-house"
                                        label="House"
                                        required={!src || t.active}
                                        foot={
                                            src
                                                ? 'This template has no house, so no rounds are created from it. Choose one to turn it on.'
                                                : undefined
                                        }
                                        value={t.site}
                                        items={data.access.sites.map((s) => ({
                                            id: String(s.id),
                                            name: s.name,
                                            sub: `${data.templates.filter((x) => x.site_id === s.id && x.status === 'active').length} active templates`,
                                            ok: true,
                                        }))}
                                        onChange={(v) => {
                                            up({ site: v, who: null });
                                            setErrs({ ...errs, site: '' });
                                        }}
                                        error={errs.site}
                                        placeholder="Choose a house"
                                        search="Search houses…"
                                    />
                                )}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Round time"
                                    required
                                    hint={WORKER_TIMEZONE}
                                    htmlFor="tw-time"
                                    error={errs.time}
                                >
                                    <TimePicker
                                        id="tw-time"
                                        label="Round time"
                                        value={t.time}
                                        invalid={!!errs.time}
                                        onChange={(v) => up({ time: v })}
                                    />
                                </Field>
                                <Field
                                    label="Doses due within"
                                    required
                                    htmlFor="tw-win"
                                    errorId="tw-win-error"
                                    error={errs.win}
                                >
                                    <div>
                                        <NumberInput
                                            id="tw-win"
                                            value={t.win}
                                            unit="minutes either side"
                                            min={5}
                                            max={120}
                                            error={errs.win}
                                            onChange={(v) => {
                                                up({ win: v });
                                                setErrs({ ...errs, win: '' });
                                            }}
                                        />
                                        {errs.win ? null : (
                                            <p className="text-caption mt-1">
                                                5 to 120 minutes either side
                                                (today’s limit).
                                            </p>
                                        )}
                                    </div>
                                </Field>
                            </div>
                            <Field label="Days" required error={errs.days}>
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between gap-4 rounded-xl border p-3">
                                        <label
                                            htmlFor="tw-every"
                                            className="text-[13px] font-semibold"
                                        >
                                            Every day
                                        </label>
                                        <OnOff
                                            id="tw-every"
                                            checked={t.everyDay}
                                            onChange={(v) => {
                                                up({ everyDay: v });
                                                setErrs({ ...errs, days: '' });
                                            }}
                                        />
                                    </div>
                                    {t.everyDay ? null : (
                                        <ChipMulti
                                            values={t.days.map(
                                                (k) =>
                                                    DAYS.find(
                                                        (d) => d[0] === k,
                                                    )![1],
                                            )}
                                            options={DAYS.map((d) => d[1])}
                                            onChange={(v) => {
                                                up({
                                                    days: DAYS.filter((d) =>
                                                        v.includes(d[1]),
                                                    ).map((d) => d[0]),
                                                });
                                                setErrs({ ...errs, days: '' });
                                            }}
                                        />
                                    )}
                                </div>
                            </Field>
                            <InfoCard icon={Check}>
                                <b>{sentence}</b>
                            </InfoCard>
                            {siteId !== null || src ? (
                                <DayTimeline
                                    t={shape}
                                    all={data.templates}
                                    house={house}
                                />
                            ) : null}
                            {overlaps.length ? (
                                <InfoCard icon={Layers} tone="warn">
                                    <b>
                                        Overlaps the{' '}
                                        {overlaps
                                            .map(
                                                (o) =>
                                                    `${o.name} (${clockText(o.scheduled_time)})`,
                                            )
                                            .join(' and ')}
                                        .
                                    </b>{' '}
                                    A dose due in both windows shows in the
                                    earlier round. You can still save.
                                </InfoCard>
                            ) : null}
                        </div>
                    ) : step === 1 ? (
                        <div className="space-y-5">
                            <StepHead
                                icon={Users}
                                title="Who does this round"
                                blurb="Everyone rostered on a covering shift, unless you choose one person."
                            />
                            <TilePicker
                                value={t.mode}
                                onChange={(v) =>
                                    up(
                                        v === 'all'
                                            ? { mode: 'all', who: null }
                                            : { mode: 'one' },
                                    )
                                }
                                options={[
                                    {
                                        key: 'all',
                                        label: 'Everyone rostered on a covering shift',
                                        description:
                                            'A lead can narrow a round to one person on the day.',
                                        icon: Users,
                                    },
                                    {
                                        key: 'one',
                                        label: 'One person by default',
                                        description:
                                            'They get the round each day it’s created. Anyone rostered can still take over.',
                                        icon: User,
                                    },
                                ]}
                            />
                            {t.mode === 'one' ? (
                                <RecordPicker
                                    id="tw-person"
                                    label="Default staff"
                                    required
                                    value={t.who ? String(t.who) : ''}
                                    items={staffItems}
                                    error={errs.who}
                                    onChange={(v) => {
                                        up({ who: Number(v) });
                                        setErrs({ ...errs, who: '' });
                                    }}
                                    placeholder="Search and choose a person"
                                    search="Search by name…"
                                    foot={`People who work at ${house}.`}
                                />
                            ) : null}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead
                                icon={Check}
                                title="Review & save"
                                blurb="Check the round and the day."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard
                                    icon={Clock}
                                    title="When"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow label="Name" value={t.name} />
                                    <ReviewRow label="House" value={house} />
                                    <ReviewRow
                                        label="Round time"
                                        value={`${clockText(t.time)}, ${t.win} minutes either side`}
                                    />
                                    <ReviewRow
                                        label="Days"
                                        value={daysText(days)}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Users}
                                    title="Who"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Staff"
                                        value={
                                            t.mode === 'one' && whoName
                                                ? whoName
                                                : 'Everyone rostered on a covering shift'
                                        }
                                    />
                                </ReviewCard>
                            </div>
                            <DayTimeline
                                t={shape}
                                all={data.templates}
                                house={house}
                            />
                            {overlaps.length ? (
                                <InfoCard icon={Layers} tone="warn">
                                    <b>
                                        Overlaps the{' '}
                                        {overlaps
                                            .map(
                                                (o) =>
                                                    `${o.name} (${clockText(o.scheduled_time)})`,
                                            )
                                            .join(' and ')}
                                        .
                                    </b>{' '}
                                    A dose due in both windows shows in the
                                    earlier round.
                                </InfoCard>
                            ) : null}
                            <div className="flex items-center justify-between gap-4 rounded-xl border p-3">
                                <div>
                                    <label
                                        htmlFor="tw-active"
                                        className="text-[13px] font-semibold"
                                    >
                                        Create rounds from this template
                                    </label>
                                    <p className="text-caption">
                                        {t.active
                                            ? 'Rounds are created from tomorrow (12:05 am). Today’s rounds keep their times.'
                                            : 'Saved as paused — no rounds are created yet.'}
                                    </p>
                                </div>
                                <OnOff
                                    id="tw-active"
                                    checked={t.active}
                                    onChange={(v) => up({ active: v })}
                                />
                            </div>
                            <Recorded />
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog
                open={guard}
                mode={src ? 'edit' : 'create'}
                description="Nothing you’ve entered here has been saved. Closing now loses it."
                onKeepEditing={() => setGuard(false)}
                onDiscard={() => {
                    setGuard(false);
                    close();
                }}
            />
        </>
    );
}

export function TemplateView({ id, data }: { id: number; data: TemplateData }) {
    const { close } = useSettings();
    const t = data.templates.find((x) => x.id === id);
    if (!t) return <NotFound what="template" />;
    return (
        <SettingsModal
            title={`${t.name} — ${houseOf(t)}`}
            description={
                t.status === 'retired'
                    ? `Retired ${t.last_changed_at ? dayText(t.last_changed_at) : ''}${t.last_changed_by ? ` by ${t.last_changed_by}` : ''}. Retired templates can’t be changed or used again.`
                    : 'Only people who manage orders at this house can change it.'
            }
            onClose={close}
        >
            <ReviewCard icon={Clock} title="Round">
                <ReviewRow
                    label="Round time"
                    value={`${clockText(t.scheduled_time)}, ${t.window_minutes} minutes either side`}
                />
                <ReviewRow label="Days" value={daysText(t.days_of_week)} />
                <ReviewRow
                    label="Staff"
                    value={
                        t.default_staff ??
                        'Everyone rostered on a covering shift'
                    }
                />
                <ReviewRow
                    label="Status"
                    value={
                        {
                            active: 'Active',
                            paused: 'Paused',
                            retired: 'Retired',
                        }[t.status]
                    }
                />
                {t.last_changed_by ? (
                    <ReviewRow
                        label="Last changed"
                        value={`${t.last_changed_by}${t.last_changed_at ? ` · ${dayText(t.last_changed_at)}` : ''}`}
                    />
                ) : null}
            </ReviewCard>
            <DayTimeline t={t} all={data.templates} house={houseOf(t)} />
        </SettingsModal>
    );
}

/* ── Pause (a loosening: red), turn back on, retire ── */
export function TemplateToggle({
    id,
    retire,
    data,
}: {
    id: number;
    retire?: boolean;
    data: TemplateData;
}) {
    const { close } = useSettings();
    const [saving, setSaving] = useState(false);
    const [problem, setProblem] = useState('');
    const t = data.templates.find((x) => x.id === id);
    if (!t || !t.can_change) return <NotFound what="template" />;
    const act = retire ? 'retire' : t.status === 'active' ? 'pause' : 'resume';
    if (act === 'resume' && needsHouse(t))
        return (
            <SettingsModal
                title="Choose a house first"
                description={`${t.name} has no house, so no rounds can be created from it.`}
                onClose={close}
            >
                <p className="text-subtle">
                    Edit the template and choose its house; you can turn it on
                    in the same step.
                </p>
            </SettingsModal>
        );
    const copy = {
        pause: [
            'Stop creating rounds from this template?',
            `From tomorrow no ${t.name} is created at ${houseOf(t)}. Doses still show on Meds today.`,
            'Pause template',
        ],
        resume: [
            'Create rounds from this template again?',
            `From tomorrow a ${t.name} is created at ${houseOf(t)} ${daysText(t.days_of_week).toLowerCase()}.`,
            'Turn template on',
        ],
        retire: [
            'Retire this template?',
            'No new rounds are created from it. Past rounds keep it on their record. A retired template can’t be changed or used again.',
            'Retire template',
        ],
    }[act];
    const options = {
        preserveScroll: true,
        preserveState: true,
        errorBag: ERROR_BAG,
        onStart: () => setSaving(true),
        onSuccess: () => close(),
        onError: (errors: Record<string, string>) =>
            setProblem(
                Object.values(errors)[0] ??
                    'Couldn’t change the template — nothing was changed.',
            ),
        onFinish: () => setSaving(false),
    };
    return (
        <ConfirmDialog
            open
            onClose={close}
            processing={saving}
            variant={act === 'resume' ? 'default' : 'destructive'}
            title={copy[0]}
            confirmText={copy[2]}
            description={
                <>
                    <p>{copy[1]}</p>
                    <p className="mt-2 font-medium text-foreground">
                        {t.name} · {houseOf(t)} · {clockText(t.scheduled_time)},{' '}
                        {t.window_minutes} minutes either side ·{' '}
                        {daysText(t.days_of_week)}
                    </p>
                    <p className="mt-2">
                        Recorded in the change history with your name and the
                        time.
                    </p>
                    {problem ? (
                        <p className="mt-2 text-status-critical">{problem}</p>
                    ) : null}
                </>
            }
            onConfirm={() =>
                act === 'retire'
                    ? router.post(
                          `/emar/rounds/templates/${t.id}/retire`,
                          {},
                          options,
                      )
                    : router.put(
                          `/emar/rounds/templates/${t.id}`,
                          { active: act === 'resume' },
                          options,
                      )
            }
        />
    );
}

/* ── Create rounds for a day (moved from Meds today › Rounds): a preview from the same generation ── */
type GenPreview = {
    rounds: {
        template_id: number;
        name: string;
        scheduled_time: string;
        status: 'new' | 'exists';
    }[];
    create: number;
    exists: number;
};
const todayInNz = () =>
    new Intl.DateTimeFormat('en-CA', { timeZone: WORKER_TIMEZONE }).format(
        new Date(),
    );

export function CreateRounds({ data }: { data: TemplateData }) {
    const { close } = useSettings();
    const [site, setSite] = useState(
        data.access.sites.length ? String(data.access.sites[0].id) : '',
    );
    const [date, setDate] = useState(todayInNz);
    const [all, setAll] = useState('0');
    const [retry, setRetry] = useState(0);
    const [state, setState] = useState<{
        key: string;
        data: GenPreview | null;
        failed: boolean;
    }>({
        key: '',
        data: null,
        failed: false,
    });
    const [saving, setSaving] = useState(false);
    const [problem, setProblem] = useState('');
    const params = new URLSearchParams({
        date,
        generate_all: all === '1' ? '1' : '0',
        ...(site ? { site_id: site } : {}),
    }).toString();
    const key = `${params}#${retry}`;
    useEffect(() => {
        if (!site || !date) return;
        const abort = new AbortController();
        fetch(`/emar/rounds/generate/preview?${params}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: abort.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error(String(response.status));
                return (await response.json()) as GenPreview;
            })
            .then((preview) => setState({ key, data: preview, failed: false }))
            .catch(() => {
                if (!abort.signal.aborted)
                    setState({ key, data: null, failed: true });
            });
        return () => abort.abort();
    }, [key, params, site, date]);
    const loading = state.key !== key;
    const preview = loading ? null : state.data;
    const create = () =>
        router.post(
            '/emar/rounds/generate',
            { date, site_id: Number(site), generate_all: all === '1' },
            {
                preserveScroll: true,
                preserveState: true,
                errorBag: ERROR_BAG,
                onStart: () => setSaving(true),
                onSuccess: () => close(),
                onError: (errors) =>
                    setProblem(
                        Object.values(errors)[0] ??
                            'Couldn’t create the rounds — nothing was changed.',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    return (
        <SettingsModal
            title="Create rounds for a day"
            description="Rounds are created automatically at 12:05 am each day. Use this to create them sooner — for example after adding a template."
            onClose={close}
            footer={
                <>
                    <Button variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button
                        disabled={!preview?.create || saving}
                        onClick={create}
                    >
                        Create rounds
                    </Button>
                </>
            }
        >
            {problem ? (
                <SettingsNotice>
                    <span>{problem}</span>
                </SettingsNotice>
            ) : null}
            <RecordPicker
                id="gw-house"
                label="House"
                value={site}
                items={data.access.sites.map((s) => ({
                    id: String(s.id),
                    name: s.name,
                    sub: `${data.templates.filter((t) => t.site_id === s.id && t.status === 'active').length} active templates`,
                    ok: true,
                }))}
                onChange={setSite}
                placeholder="Choose a house"
                search="Search houses…"
            />
            <Field label="Day" hint={WORKER_TIMEZONE} htmlFor="gw-date">
                <DatePicker
                    id="gw-date"
                    label="Day"
                    value={date}
                    onChange={setDate}
                />
            </Field>
            <TilePicker
                value={all}
                onChange={setAll}
                options={[
                    {
                        key: '0',
                        label: 'Only those set for that day',
                        description: 'Follows each template’s days',
                        icon: CalendarDays,
                    },
                    {
                        key: '1',
                        label: 'All active templates',
                        description: 'Ignores the days — for a one-off change',
                        icon: Repeat,
                    },
                ]}
            />
            {state.failed && !loading ? (
                <SettingsNotice>
                    <span>
                        Couldn’t work out which rounds would be created.{' '}
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setRetry(retry + 1)}
                        >
                            Retry
                        </Button>
                    </span>
                </SettingsNotice>
            ) : (
                <ReviewCard
                    icon={Repeat}
                    title={
                        !preview
                            ? 'Working out the rounds…'
                            : preview.create
                              ? `Creates ${preview.create} ${preview.create === 1 ? 'round' : 'rounds'}`
                              : 'Nothing new to create'
                    }
                >
                    {preview ? (
                        preview.rounds.length ? (
                            preview.rounds.map((r) => (
                                <ReviewRow
                                    key={r.template_id}
                                    label={`${r.name} · ${clockText(r.scheduled_time)}`}
                                    value={
                                        r.status === 'exists' ? (
                                            'Already exists — skipped'
                                        ) : (
                                            <b>New</b>
                                        )
                                    }
                                />
                            ))
                        ) : (
                            <p className="text-caption">
                                No active templates apply that day.
                            </p>
                        )
                    ) : null}
                </ReviewCard>
            )}
            <p className="text-caption">
                Rounds that already exist are skipped — creating twice never
                makes duplicates.
            </p>
        </SettingsModal>
    );
}

export function TemplateDialogHost({
    dialog,
    data,
}: {
    dialog: Dialog | null;
    data: TemplateData;
}) {
    if (!dialog) return null;
    switch (dialog.kind) {
        case 'tpl':
            return <TemplateWizard id={dialog.id} data={data} />;
        case 'tplview':
            return <TemplateView id={dialog.id} data={data} />;
        case 'tpltoggle':
            return <TemplateToggle id={dialog.id} data={data} />;
        case 'tplretire':
            return <TemplateToggle id={dialog.id} retire data={data} />;
        case 'gen':
            return <CreateRounds data={data} />;
        default:
            return null;
    }
}
