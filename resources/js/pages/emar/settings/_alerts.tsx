/* Medication › Settings › Alerts & access (eMAR P11 v5, B2): who gets each
 * medication alert. Stephan, 29 Sep 2026: the organisation sets each alert's
 * channels and who gets it, one set for every house; house managers add extra
 * people for their own houses; the routing he decided stays locked on.
 * Everything here goes into the page draft and applies when the Alerts tab
 * is reviewed and saved.
 *
 * Built so far: the Alerts tab (in-app, who gets it) and its Overview card.
 * Email, push and Follow up arrive with their chunks (P11 B2 C2, C3), as do
 * Delivery, On-call contacts and the Alert log — until then they aren't
 * shown (hide-unbuilt). */
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { EntityChip } from '@/components/lists/entity-cells';
import {
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { InfoCard, StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import {
    AlertTriangle,
    Bell,
    Building2,
    Check,
    ChevronLeft,
    ChevronRight,
    Home,
    LockKeyhole,
    Plus,
    Shield,
    User,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { useSettings, type Dialog } from './_context';
import { NotFound } from './_dialogs';
import {
    channelWords,
    decisionReviewer,
    draftValue,
    encodeAlert,
    encodePeople,
    fallbackHouses,
    fallbackWarnings,
    isDirty,
    isSiteDirty,
    parseAlert,
    parsePeople,
    siteDraftValue,
    siteSlot,
    withDraft,
    type AlertMeta,
    type AlertSetting,
    type SettingsPayload,
} from './_model';
import {
    Changed,
    DefaultNotReviewed,
    OnOff,
    Overview,
    RecordPicker,
    RowMenu,
    Section,
    type PickItem,
} from './_ui';

/** Someone who can be named on an alert: an approved account with medication access. */
export type AlertPerson = {
    id: number;
    name: string;
    role: string;
    houses: string[];
    site_ids: number[];
    all_houses: boolean;
    controlled: boolean;
};

export type AlertAccess = {
    view: boolean;
    /** Sets channels, groups and named people for every house (all-sites authority). */
    manage_org: boolean;
    /** Houses whose extra people this person changes. */
    house_ids: number[];
};

export type AlertData = {
    access: AlertAccess;
    people: AlertPerson[];
    /** Houses this person sees. */
    sites: { id: number; name: string }[];
    readOnlyAudit: boolean;
    /** Open alerts nobody could be told about, not even the safety net. */
    nobodyOpen: number;
};

const G = 'alerts';
const X = 'alertExtra';

const match = (q: string, ...s: (string | null | undefined)[]) =>
    !q || s.some((x) => (x ?? '').toLowerCase().includes(q.toLowerCase()));

/** The alerts offered today, in v5's order. */
export function alertKeys(s: SettingsPayload): string[] {
    return (s.groups[G]?.keys ?? []).filter((k) => s.definitions[G]?.[k]);
}

const metaOf = (s: SettingsPayload, k: string): AlertMeta | undefined =>
    s.definitions[G]?.[k]?.alert;

const valueOf = (
    s: SettingsPayload,
    draft: Record<string, Record<string, string>>,
    k: string,
): AlertSetting =>
    parseAlert(draftValue(s, draft, G, k)) ?? {
        inapp: true,
        email: false,
        push: false,
        follow_up: false,
        groups: [],
        people: [],
    };

const nameOf = (s: SettingsPayload, id: number) =>
    s.people_names?.[id] ?? 'A former staff member';

/** "In-app on" (and email and push once they send) — the channels that send today. */
const channelsText = (meta: AlertMeta, a: AlertSetting) =>
    meta.channels
        .map(
            (c) =>
                `${{ inapp: 'In-app', email: 'email', push: 'push' }[c] ?? c} ${a[c as 'inapp' | 'email' | 'push'] ? 'on' : 'off'}`,
        )
        .join(' · ');

/** v5 "Goes to": the first two names, "+N", then house extras. */
function goesTo(
    s: SettingsPayload,
    meta: AlertMeta,
    a: AlertSetting,
    extras: number,
) {
    const names = [
        ...a.groups.map((g) => meta.group_labels[g]?.label ?? g),
        ...a.people.map((id) => nameOf(s, id)),
    ];
    const head = `${names.slice(0, 2).join(', ')}${names.length > 2 ? ` +${names.length - 2}` : ''}`;
    const tail = extras
        ? `${head ? ' · ' : ''}${extras} house ${extras === 1 ? 'extra' : 'extras'}`
        : '';
    return head || tail ? head + tail : 'Nobody';
}

export function AlertsOverview({ q, data }: { q: string; data: AlertData }) {
    const { s, go } = useSettings();
    const keys = alertKeys(s);
    const inapp = keys.filter(
        (k) => parseAlert(draftValue(s, {}, G, k))?.inapp,
    ).length;
    const open = keys.filter((k) => !decisionReviewer(s, G, k)).length;
    return (
        <Overview
            q={q}
            title="Alerts & access"
            caption="Who is told, how, and who to call"
            cards={[
                {
                    icon: Bell,
                    title: 'Alerts',
                    lines: [
                        `${keys.length} alert types · in-app on for ${inapp}.`,
                        data.access.manage_org
                            ? 'Choose who gets each alert, at every house and per house.'
                            : 'Who gets each alert, at every house and per house.',
                        // Never only a log row (Main, 2 Oct).
                        ...(data.nobodyOpen
                            ? [
                                  `${data.nobodyOpen} open ${data.nobodyOpen === 1 ? 'alert' : 'alerts'} couldn’t be told to anyone — not even medication settings managers.`,
                              ]
                            : []),
                    ],
                    badge: (
                        <span className="flex flex-wrap gap-1.5">
                            {open ? (
                                <StatusBadge variant="warning" size="sm">
                                    {open} not yet reviewed
                                </StatusBadge>
                            ) : (
                                <StatusBadge variant="success" size="sm">
                                    All reviewed
                                </StatusBadge>
                            )}
                            {data.nobodyOpen ? (
                                <StatusBadge variant="warning" size="sm">
                                    {data.nobodyOpen} reached nobody
                                </StatusBadge>
                            ) : null}
                        </span>
                    ),
                    cta: 'Review alerts',
                    onClick: () => go('alerts', 'alerts'),
                },
            ]}
        />
    );
}

type AlertRow = { key: string; meta: AlertMeta };

export function AlertsTable({
    q,
    show,
    clear,
    data,
}: {
    q: string;
    show: string;
    clear: () => void;
    data: AlertData;
}) {
    const { s, draft, setDraft, open, errors, clearError } = useSettings();
    const menu = useEntityContextMenu<AlertRow>();
    const orgRo = !data.access.manage_org || data.readOnlyAudit;
    const houseEdit = !data.readOnlyAudit && data.access.house_ids.length > 0;
    const keys = alertKeys(s);
    const extrasFor = (k: string) =>
        data.sites.reduce(
            (n, h) =>
                n +
                (parsePeople(siteDraftValue(s, draft, X, k, h.id))?.length ??
                    0),
            0,
        );
    const dirty = (k: string) =>
        isDirty(s, draft, G, k) ||
        data.sites.some((h) => isSiteDirty(s, draft, X, k, h.id));
    const rows: AlertRow[] = keys
        .map((key) => ({ key, meta: metaOf(s, key)! }))
        .filter(({ key, meta }) => {
            const shown =
                show === 'open'
                    ? !decisionReviewer(s, G, key)
                    : show === 'changed'
                      ? dirty(key)
                      : true;
            return shown && match(q, meta.label, meta.subline);
        });
    const setInApp = (k: string, on: boolean) => {
        const meta = metaOf(s, k)!;
        setDraft((d) =>
            withDraft(
                d,
                G,
                k,
                encodeAlert(meta, { ...valueOf(s, d, k), inapp: on }),
            ),
        );
        clearError(`${G}.${k}`);
    };
    const actions = (r: AlertRow): MenuItem[] =>
        compactMenu([
            {
                label:
                    orgRo && !houseEdit
                        ? 'View who gets it'
                        : 'Edit who gets it',
                icon: Users,
                onClick: () => open({ kind: 'alertwho', key: r.key }),
            },
            !orgRo && {
                label: 'Add a person',
                icon: Plus,
                onClick: () => open({ kind: 'alertperson', key: r.key }),
            },
        ]);
    const errs = Object.entries(errors).filter(([k]) => k.startsWith(`${G}.`));

    return (
        <Section
            id="sc-alerts"
            title="Medication alerts"
            caption={`${rows.length} of ${keys.length} alert types shown`}
            right={<EntityChip icon={Building2}>Every house</EntityChip>}
        >
            {errs.length ? (
                <div role="alert" className="space-y-1">
                    {errs.map(([k, v]) => (
                        <p
                            key={k}
                            className="flex items-center gap-1 text-xs text-status-critical"
                        >
                            <AlertTriangle className="size-3" />
                            {v}
                        </p>
                    ))}
                </div>
            ) : null}
            {rows.length ? (
                <EntityTable<AlertRow>
                    rows={rows}
                    rowKey={(r) => r.key}
                    identityLabel="Alert"
                    identityWidth="1.9fr"
                    minWidth={820}
                    rowHeight="content"
                    identity={(r) => ({
                        icon: Bell,
                        name: r.meta.label,
                        subline: r.meta.subline,
                    })}
                    columns={[
                        {
                            key: 'inapp',
                            label: 'In-app',
                            width: '0.7fr',
                            cell: (r) => {
                                const locked = r.meta.locked.length > 0;
                                return (
                                    <div
                                        onClick={(e) => e.stopPropagation()}
                                        data-setting={`${G}-${r.key}`}
                                    >
                                        <OnOff
                                            id={`al-${r.key}-inapp`}
                                            checked={
                                                valueOf(s, draft, r.key).inapp
                                            }
                                            disabled={orgRo || locked}
                                            label={`${r.meta.label}: in-app`}
                                            invalid={!!errors[`${G}.${r.key}`]}
                                            onChange={(v) => setInApp(r.key, v)}
                                        />
                                        {locked ? (
                                            <p className="text-caption mt-1 flex items-center gap-1">
                                                <LockKeyhole className="size-3" />
                                                Always on
                                            </p>
                                        ) : null}
                                    </div>
                                );
                            },
                        },
                        {
                            key: 'to',
                            label: 'Goes to',
                            width: '1.6fr',
                            cell: (r) => (
                                <div className="space-y-1 py-2">
                                    <span className="text-[12.5px]">
                                        {goesTo(
                                            s,
                                            r.meta,
                                            valueOf(s, draft, r.key),
                                            extrasFor(r.key),
                                        )}
                                    </span>
                                    {fallbackWarnings(
                                        fallbackHouses(s, draft, r.key),
                                    ).map((w) => (
                                        <StatusBadge
                                            key={w}
                                            variant="warning"
                                            size="sm"
                                            className="rounded-md text-left"
                                        >
                                            {w}
                                        </StatusBadge>
                                    ))}
                                </div>
                            ),
                        },
                        {
                            key: 'state',
                            label: 'Status',
                            width: '1.2fr',
                            cell: (r) =>
                                dirty(r.key) ? (
                                    <Changed />
                                ) : decisionReviewer(s, G, r.key) ? (
                                    <StatusBadge variant="neutral" size="sm">
                                        Reviewed
                                    </StatusBadge>
                                ) : (
                                    <DefaultNotReviewed />
                                ),
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={(r) => open({ kind: 'alertwho', key: r.key })}
                    onRowContextMenu={menu.open}
                />
            ) : (
                <EmptyState
                    icon={Bell}
                    title={
                        q
                            ? `No settings in this tab match “${q}”`
                            : 'No settings match this filter'
                    }
                    description="Try another tab, or clear the search and filter."
                    action={
                        <Button variant="outline" size="sm" onClick={clear}>
                            Clear search and filter
                        </Button>
                    }
                />
            )}
            <RowMenu
                ctx={menu.ctx}
                close={menu.close}
                icon={Bell}
                title={(r) => r.meta.label}
                items={actions}
            />
            <div className="text-subtle flex flex-wrap items-center gap-2 border-t border-border pt-4">
                <Shield className="size-3.5" />
                Alerts about controlled medicines only reach people with
                controlled-medicine access. Control Room shows these alerts in
                its queue; who is told is set here.
            </div>
        </Section>
    );
}

/** People for a picker: who can get the alert, and why anyone else can't be chosen. */
function peopleItems(
    pool: AlertPerson[],
    taken: number[],
    meta: AlertMeta,
): PickItem[] {
    return pool.map((p) => {
        const blocked = taken.includes(p.id)
            ? 'already added'
            : meta.controlled && !p.controlled
              ? 'no controlled-medicine access'
              : undefined;
        return {
            id: String(p.id),
            name: p.name,
            sub: `${p.role} · ${p.all_houses ? 'All houses' : p.houses.join(', ') || 'No house'}`,
            ok: !blocked,
            why: blocked,
        };
    });
}

function PeopleEditor({
    id,
    list,
    pool,
    meta,
    onChange,
    disabled,
    foot,
}: {
    id: string;
    list: number[];
    pool: AlertPerson[];
    meta: AlertMeta;
    onChange: (l: number[]) => void;
    disabled: boolean;
    foot: string;
}) {
    const { s } = useSettings();
    const role = (pid: number) => pool.find((p) => p.id === pid)?.role;
    return (
        <div className="space-y-3">
            {list.length ? (
                <ul className="divide-y divide-border rounded-xl border">
                    {list.map((pid) => (
                        <li
                            key={pid}
                            className="flex items-center justify-between gap-3 p-3 text-[13px]"
                        >
                            <span>
                                {nameOf(s, pid)}
                                {role(pid) ? (
                                    <span className="text-caption">
                                        {' '}
                                        · {role(pid)}
                                    </span>
                                ) : null}
                            </span>
                            {disabled ? null : (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    aria-label={`Remove ${nameOf(s, pid)}`}
                                    onClick={() =>
                                        onChange(list.filter((x) => x !== pid))
                                    }
                                >
                                    Remove
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-subtle">Nobody named.</p>
            )}
            {disabled ? null : (
                <RecordPicker
                    id={id}
                    label="Add a person"
                    value=""
                    items={peopleItems(pool, list, meta)}
                    onChange={(v) => onChange([...list, Number(v)])}
                    placeholder="Search and choose a person"
                    search="Search people…"
                    foot={foot}
                />
            )}
        </div>
    );
}

const AWHO_STEPS = [
    {
        key: 'groups',
        label: 'Groups',
        blurb: 'Who is told, by role',
        icon: Users,
    },
    {
        key: 'people',
        label: 'Named people',
        blurb: 'At every house',
        icon: User,
    },
    {
        key: 'extras',
        label: 'House extras',
        blurb: 'Only for one house',
        icon: Home,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Apply to your draft',
        icon: Check,
    },
];

/** Who gets an alert (P11 v5 AlertWho): Groups · Named people · House extras · Review, into the draft. */
export function AlertWho({ k, data }: { k: string; data: AlertData }) {
    const { s, draft, setDraft, close, flash } = useSettings();
    const meta = metaOf(s, k);
    const canO = data.access.manage_org && !data.readOnlyAudit;
    const canH = (h: number) =>
        !data.readOnlyAudit && data.access.house_ids.includes(h);
    const houses = data.sites;
    const [x, setX] = useState<AlertSetting>(() => valueOf(s, draft, k));
    const [extra, setExtra] = useState<Record<number, number[]>>(() =>
        Object.fromEntries(
            houses.map((h) => [
                h.id,
                parsePeople(siteDraftValue(s, draft, X, k, h.id)) ?? [],
            ]),
        ),
    );
    const [house, setHouse] = useState<number>(
        () => houses.find((h) => canH(h.id))?.id ?? houses[0]?.id ?? 0,
    );
    const [step, setStep] = useState(0);
    const [dirty, setDirty] = useState(false);
    const [guard, setGuard] = useState(false);
    if (!meta) return <NotFound what="alert" />;
    const editable = canO || houses.some((h) => canH(h.id));
    const houseName = (h: number) =>
        houses.find((y) => y.id === h)?.name ?? 'this house';
    const extrasPool = (h: number) =>
        data.people.filter((p) => p.all_houses || p.site_ids.includes(h));
    const toggle = (g: string, on: boolean) => {
        setX({
            ...x,
            groups: on ? [...x.groups, g] : x.groups.filter((y) => y !== g),
        });
        setDirty(true);
    };
    const apply = () => {
        setDraft((d) => {
            let next = d;
            if (canO) next = withDraft(next, G, k, encodeAlert(meta, x));
            houses
                .filter((h) => canH(h.id))
                .forEach((h) => {
                    next = withDraft(
                        next,
                        X,
                        siteSlot(k, h.id),
                        encodePeople(extra[h.id] ?? []),
                    );
                });
            return next;
        });
        close();
        flash(
            `Changes to “${meta.label}” are in your draft. Review and save the Alerts tab to apply them.`,
        );
    };
    const onClose = () => (dirty ? setGuard(true) : close());
    const names = (l: string[]) => l.join(', ') || 'Nobody';
    const told = meta.groups.filter(
        (g) => meta.locked.includes(g) || x.groups.includes(g),
    );

    return (
        <>
            <WizardShell
                open
                onClose={onClose}
                title={`Who gets “${meta.label}”`}
                description="Choose the groups and people who get this alert."
                railIcon={Bell}
                railTitle={meta.label}
                railSub={channelsText(meta, x)}
                steps={AWHO_STEPS}
                stepIndex={step}
                onStepClick={setStep}
                footerStart={
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onClose}>
                            {editable ? 'Cancel' : 'Close'}
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
                    step < 3 ? (
                        <Button onClick={() => setStep(step + 1)}>
                            Continue
                            <ChevronRight />
                        </Button>
                    ) : editable ? (
                        <Button onClick={apply}>
                            <Check />
                            Apply to draft
                        </Button>
                    ) : null
                }
            >
                <WizardStepPane key={step}>
                    {step === 0 ? (
                        <div className="space-y-4">
                            <StepHead
                                icon={Users}
                                title="Groups"
                                blurb={meta.subline}
                            />
                            {!canO && !data.readOnlyAudit ? (
                                <InfoCard icon={LockKeyhole}>
                                    Only someone with all-sites authority
                                    changes the groups. You can add extra people
                                    for your own houses.
                                </InfoCard>
                            ) : null}
                            <div className="divide-y divide-border rounded-xl border">
                                {meta.groups.map((g) => {
                                    const locked = meta.locked.includes(g);
                                    return (
                                        <div
                                            key={g}
                                            className="flex items-center justify-between gap-4 p-3"
                                        >
                                            <div>
                                                <label
                                                    htmlFor={`aw-${g}`}
                                                    className="flex items-center gap-1.5 text-[13px] font-medium"
                                                >
                                                    {locked ? (
                                                        <LockKeyhole
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                    ) : null}
                                                    {
                                                        meta.group_labels[g]
                                                            ?.label
                                                    }
                                                </label>
                                                <p className="text-caption">
                                                    {locked
                                                        ? 'Always on'
                                                        : meta.group_labels[g]
                                                              ?.description}
                                                </p>
                                            </div>
                                            <OnOff
                                                id={`aw-${g}`}
                                                checked={
                                                    locked ||
                                                    x.groups.includes(g)
                                                }
                                                disabled={locked || !canO}
                                                onChange={(v) => toggle(g, v)}
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                            <p className="text-caption">
                                How it’s sent ({channelWords(meta.channels)}) is
                                set in the Alerts table. Controlled-medicine
                                alerts only reach people with
                                controlled-medicine access.
                            </p>
                        </div>
                    ) : step === 1 ? (
                        <div className="space-y-4">
                            <StepHead
                                icon={User}
                                title="Named people"
                                blurb="They get this alert for every house they have access to, as well as the groups."
                            />
                            <PeopleEditor
                                id="aw-people"
                                list={x.people}
                                pool={data.people}
                                meta={meta}
                                disabled={!canO}
                                onChange={(l) => {
                                    setX({ ...x, people: l });
                                    setDirty(true);
                                }}
                                foot="Named people add to the groups; they never replace them."
                            />
                        </div>
                    ) : step === 2 ? (
                        <div className="space-y-4">
                            <StepHead
                                icon={Home}
                                title="House extras"
                                blurb="Extra people who get this alert only when it’s about one house."
                            />
                            {houses.length ? (
                                <>
                                    <RecordPicker
                                        id="aw-house"
                                        label="House"
                                        value={String(house)}
                                        items={houses.map((h) => ({
                                            id: String(h.id),
                                            name: h.name,
                                            sub: canH(h.id)
                                                ? `${extra[h.id]?.length ?? 0} extra`
                                                : 'Read-only for you',
                                            ok: true,
                                        }))}
                                        onChange={(v) => setHouse(Number(v))}
                                        placeholder="Choose a house"
                                        search="Search houses…"
                                    />
                                    <PeopleEditor
                                        id="aw-extra"
                                        list={extra[house] ?? []}
                                        pool={extrasPool(house)}
                                        meta={meta}
                                        disabled={!canH(house)}
                                        onChange={(l) => {
                                            setExtra({ ...extra, [house]: l });
                                            setDirty(true);
                                        }}
                                        foot={`Only for alerts about ${houseName(house)}.`}
                                    />
                                </>
                            ) : (
                                <p className="text-subtle">
                                    No houses to show.
                                </p>
                            )}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead
                                icon={Check}
                                title="Review"
                                blurb="These go into your draft. Nothing changes until you review and save the Alerts tab."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard
                                    icon={Users}
                                    title="Groups"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Told"
                                        value={names(
                                            told.map(
                                                (g) =>
                                                    meta.group_labels[g]
                                                        ?.label ?? g,
                                            ),
                                        )}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={User}
                                    title="Named people"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Every house"
                                        value={names(
                                            x.people.map((id) => nameOf(s, id)),
                                        )}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Home}
                                    title="House extras"
                                    onEdit={() => setStep(2)}
                                    span
                                >
                                    {/* Houses with extras by name; the rest as one row, so many houses stay readable. */}
                                    {houses
                                        .filter(
                                            (h) => (extra[h.id] ?? []).length,
                                        )
                                        .map((h) => (
                                            <ReviewRow
                                                key={h.id}
                                                label={h.name}
                                                value={names(
                                                    (extra[h.id] ?? []).map(
                                                        (id) => nameOf(s, id),
                                                    ),
                                                )}
                                            />
                                        ))}
                                    {houses.some(
                                        (h) => !(extra[h.id] ?? []).length,
                                    ) ? (
                                        <ReviewRow
                                            label={
                                                houses.some(
                                                    (h) =>
                                                        (extra[h.id] ?? [])
                                                            .length,
                                                )
                                                    ? 'Other houses'
                                                    : houses.length === 1
                                                      ? houses[0].name
                                                      : 'Every house'
                                            }
                                            value="Nobody"
                                        />
                                    ) : null}
                                </ReviewCard>
                            </div>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog
                open={guard}
                mode="edit"
                description="Your changes to who gets this alert haven’t been applied. Closing now loses them."
                onKeepEditing={() => setGuard(false)}
                onDiscard={() => {
                    setGuard(false);
                    close();
                }}
            />
        </>
    );
}

/** Add a named person to an alert, for every house they can see (P11 v5 AlertPerson). */
export function AlertPersonDialog({ k, data }: { k: string; data: AlertData }) {
    const { s, draft, setDraft, close, flash } = useSettings();
    const meta = metaOf(s, k);
    const [who, setWho] = useState('');
    const [err, setErr] = useState('');
    if (!meta || !data.access.manage_org || data.readOnlyAudit)
        return <NotFound what="alert" />;
    const add = () => {
        if (!who) {
            setErr('Choose a person.');
            setTimeout(() => document.getElementById('ap-who')?.focus(), 0);
            return;
        }
        const id = Number(who);
        setDraft((d) => {
            const v = valueOf(s, d, k);
            return withDraft(
                d,
                G,
                k,
                encodeAlert(meta, { ...v, people: [...v.people, id] }),
            );
        });
        close();
        flash(
            `${data.people.find((p) => p.id === id)?.name ?? 'They'} added to “${meta.label}” in your draft. Review and save to apply it.`,
        );
    };
    return (
        <SettingsModal
            title={`Add a person to “${meta.label}”`}
            description="They get it for every house they have access to."
            onClose={close}
            footer={
                <>
                    <Button variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button onClick={add}>Add to draft</Button>
                </>
            }
        >
            <RecordPicker
                id="ap-who"
                label="Person"
                required
                value={who}
                items={peopleItems(
                    data.people,
                    valueOf(s, draft, k).people,
                    meta,
                )}
                error={err}
                onChange={(v) => {
                    setWho(v);
                    setErr('');
                }}
                placeholder="Search and choose a person"
                search="Search people…"
            />
            <p className="text-caption">
                Named people add to the switched-on groups; they never replace
                them. Alerts about controlled medicines only reach people with
                controlled-medicine access. Nothing changes until you review and
                save.
            </p>
        </SettingsModal>
    );
}

export function AlertDialogHost({
    dialog,
    data,
}: {
    dialog: Dialog | null;
    data: AlertData;
}) {
    if (dialog?.kind === 'alertwho')
        return <AlertWho k={dialog.key} data={data} />;
    if (dialog?.kind === 'alertperson')
        return <AlertPersonDialog k={dialog.key} data={data} />;
    return null;
}
