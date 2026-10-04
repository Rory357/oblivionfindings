/* Medication › Settings › Alerts & access (eMAR P11 v5, B2): who gets each
 * medication alert. Stephan, 29 Sep 2026: the organisation sets each alert's
 * channels and who gets it, one set for every house; house managers add extra
 * people for their own houses; the routing he decided stays locked on.
 * Everything here goes into the page draft and applies when the Alerts tab
 * is reviewed and saved.
 *
 * Built so far: the Alerts tab (in-app, email, push, Follow up, who gets
 * it), Delivery (follow-up, email, push, quiet hours, in-app, after hours
 * and the privacy switch, then Who can't be reached), the message preview
 * and their Overview cards; On-call contacts is in _oncall, the Alert log
 * in _alert-log, and the canonical emergency policy in _emergency. */
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
import { StatusBadge } from '@/components/ui/status-badge';
import {
    ChipMulti,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { Sections } from '@/pages/fleet-assets/settings/_ui';
import {
    AlertTriangle,
    ArrowUpRight,
    Bell,
    BellRing,
    Building2,
    Check,
    ChevronLeft,
    ChevronRight,
    Eye,
    History,
    Home,
    LockKeyhole,
    Mail,
    Phone,
    Plus,
    Shield,
    Siren,
    Smartphone,
    Timer,
    User,
    Users,
    UserX,
} from 'lucide-react';
import { useState } from 'react';
import { useSettings, type Dialog } from './_context';
import { NotFound } from './_dialogs';
import {
    channelWords,
    decisionReviewer,
    draftValue,
    encodeAlert,
    encodeGroups,
    encodePeople,
    fallbackHouses,
    fallbackWarnings,
    fmtT,
    inAppLocked,
    isDirty,
    isSiteDirty,
    parseAlert,
    parseGroups,
    parsePeople,
    quietAt,
    siteDraftValue,
    siteSlot,
    withDraft,
    type AlertMeta,
    type AlertSetting,
    type SettingsPayload,
} from './_model';
import type { OnCallData } from './_oncall';
import { QuietHoursGroup } from './_quiet';
import {
    reachMatters,
    reachRows,
    WhoCantBeReached,
    type ReachGap,
} from './_reach';
import { NoMatches, useRow } from './_sections';
import {
    Changed,
    Choice,
    DefaultNotReviewed,
    GroupGrid,
    GroupRow,
    NumberInput,
    OnOff,
    Overview,
    RecordPicker,
    RowMenu,
    Section,
    SettingGroup,
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

/** An alert outside the app: the email and the push, rendered by the real notification. */
type Outside = {
    email: { subject: string; lines: string[]; action: string };
    push: { title: string; body: string };
};

/** Message preview (B2 C2): a synthetic sample, rendered by the real notification. */
export type AlertPreview = {
    controlled: boolean;
    inapp: { title: string; message: string };
    /** "Keep client names and medicines out of email and push" on. */
    private: Outside;
    open: Outside;
};

export type AlertData = {
    access: AlertAccess;
    people: AlertPerson[];
    /** Houses this person sees. */
    sites: { id: number; name: string }[];
    readOnlyAudit: boolean;
    /** Open alerts nobody could be told about, not even the safety net. */
    nobodyOpen: number;
    previews: Record<string, AlertPreview>;
    /** Of the people who can get alerts, how many have push set up. */
    delivery: { push_ready: number; people: number };
    /** On-call contacts (B2 C4), one per house this person sees. */
    onCall: OnCallData;
    /** Houses this person sees, for their quiet hours (B2 C5). */
    houses: { id: number; name: string }[];
    /** People with a contact gap: Who can't be reached (B2 C5). */
    reachGaps: ReachGap[];
    logSummary?: { recent: number; open: number };
};

const G = 'alerts';
const X = 'alertExtra';
const D = 'delivery';
type Channel = 'inapp' | 'email' | 'push';

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
    const { s, draft, go } = useSettings();
    const reach = reachRows(s, draft, data.reachGaps);
    const reachNow = reach.filter(reachMatters).length;
    const keys = alertKeys(s);
    const on = (c: Channel) =>
        keys.filter((k) => parseAlert(draftValue(s, {}, G, k))?.[c]).length;
    const open = keys.filter((k) => !decisionReviewer(s, G, k)).length;
    const priv = draftValue(s, {}, D, 'private') !== 'no';
    const saved = (key: string) => draftValue(s, {}, D, key);
    const ocHouses = data.onCall.houses;
    const ocSet = ocHouses.filter((h) => h.rule).length;
    const ocRoster = ocHouses.filter((h) => h.rule?.mode === 'roster').length;
    const off = s.definitions[D]?.realert_every?.numeric?.off ?? 'off';
    const deliveryOpen = [
        'realert_every',
        'attended',
        'escalate_after',
        'private',
    ]
        .filter((k) => s.definitions[D]?.[k])
        .filter((k) => !decisionReviewer(s, D, k)).length;
    return (
        <Overview
            q={q}
            title="Alerts & access"
            caption="Who is told, how, and who to call"
            cards={[
                {
                    icon: LockKeyhole,
                    title: 'Emergency access',
                    lines: [
                        'Grant length, extensions and repeat-use checks.',
                        'How access starts, ends and is reviewed.',
                    ],
                    cta: 'Review emergency access',
                    onClick: () => go('alerts', 'emergency'),
                },
                {
                    icon: History,
                    title: 'Alert log',
                    lines: [
                        (data.logSummary?.recent ?? 0) +
                            ' alerts in the last 3 days · ' +
                            (data.logSummary?.open ?? 0) +
                            ' not attended.',
                        'Who was told, how, and who attended.',
                    ],
                    cta: 'Review the alert log',
                    onClick: () => go('alerts', 'log'),
                },
                {
                    icon: Bell,
                    title: 'Alerts',
                    lines: [
                        `${keys.length} alert types · in-app on for ${on('inapp')}, email for ${on('email')}, push for ${on('push')}.`,
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
                {
                    icon: Mail,
                    title: 'Delivery & follow-up',
                    lines: [
                        saved('realert_every') !== off
                            ? `Re-alerts every ${saved('realert_every')} minutes until attended${saved('escalate_after') !== off ? `; escalates after ${saved('escalate_after')} minutes` : ''}.`
                            : saved('escalate_after') !== off
                              ? `Escalates after ${saved('escalate_after')} minutes if nobody attends.`
                              : 'Each alert is sent once — no re-alerts or escalation yet.',
                        priv
                            ? 'Email and push leave out client names and medicines.'
                            : 'Email and push include client names and medicines.',
                    ],
                    badge: deliveryOpen ? (
                        <StatusBadge variant="warning" size="sm">
                            {deliveryOpen} not yet reviewed
                        </StatusBadge>
                    ) : (
                        <StatusBadge variant="success" size="sm">
                            All reviewed
                        </StatusBadge>
                    ),
                    cta: 'Review delivery & follow-up',
                    onClick: () => go('alerts', 'delivery'),
                },
                {
                    icon: Phone,
                    title: 'On-call contacts',
                    lines: [
                        `${ocSet} of ${ocHouses.length} houses have one · ${ocRoster} follow the roster.`,
                        'Whoever is rostered on call is shown after hours, with a backup person.',
                    ],
                    badge:
                        ocSet < ocHouses.length ? (
                            <StatusBadge variant="warning" size="sm">
                                {ocHouses.length - ocSet} not configured
                            </StatusBadge>
                        ) : (
                            <StatusBadge variant="success" size="sm">
                                Every house has one
                            </StatusBadge>
                        ),
                    cta: 'Review on-call contacts',
                    onClick: () => go('alerts', 'oncall'),
                },
                {
                    icon: UserX,
                    title: 'Who can’t be reached',
                    lines: [
                        reach.length
                            ? `${reach.length} people have a contact gap · ${reachNow} matter now.`
                            : 'Everyone can be reached.',
                        'From staff records, push set-up and approved leave.',
                    ],
                    badge: reachNow ? (
                        <StatusBadge variant="warning" size="sm">
                            {reachNow} can’t be reached
                        </StatusBadge>
                    ) : undefined,
                    cta: 'Review who can’t be reached',
                    onClick: () => go('alerts', 'delivery'),
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
    const { s, draft, setDraft, open, go, errors, clearError } = useSettings();
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
            const a = valueOf(s, draft, key);
            const shown =
                show === 'open'
                    ? !decisionReviewer(s, G, key)
                    : show === 'changed'
                      ? dirty(key)
                      : show === 'email'
                        ? a.email
                        : show === 'push'
                          ? a.push
                          : true;
            return shown && match(q, meta.label, meta.subline);
        });
    // At least one channel stays on: the last one left can't be switched
    // off (in-app comes back on, as the server keeps it).
    const setChannel = (k: string, c: Channel, on: boolean) => {
        const meta = metaOf(s, k)!;
        setDraft((d) =>
            withDraft(
                d,
                G,
                k,
                encodeAlert(meta, { ...valueOf(s, d, k), [c]: on }),
            ),
        );
        clearError(`${G}.${k}`);
    };
    const channelCell = (r: AlertRow, c: 'email' | 'push') => (
        <div onClick={(e) => e.stopPropagation()}>
            <OnOff
                id={`al-${r.key}-${c}`}
                checked={valueOf(s, draft, r.key)[c]}
                disabled={orgRo}
                label={`${r.meta.label}: ${c}`}
                onChange={(v) => setChannel(r.key, c, v)}
            />
        </div>
    );
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
            !!data.previews[r.key] && {
                label: 'Preview message',
                icon: Eye,
                onClick: () => open({ kind: 'msgpreview', key: r.key }),
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
                    minWidth={1080}
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
                            // Room for its lock caption on one line (v5 gives this to Follow up, C3).
                            width: '0.75fr',
                            cell: (r) => {
                                const locked = r.meta.locked.length > 0;
                                const only =
                                    !locked &&
                                    inAppLocked(
                                        r.meta,
                                        valueOf(s, draft, r.key),
                                    );
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
                                            disabled={orgRo || locked || only}
                                            label={`${r.meta.label}: in-app`}
                                            invalid={!!errors[`${G}.${r.key}`]}
                                            onChange={(v) =>
                                                setChannel(r.key, 'inapp', v)
                                            }
                                        />
                                        {locked ? (
                                            <p className="text-caption mt-1 flex items-center gap-1">
                                                <LockKeyhole className="size-3" />
                                                Always on
                                            </p>
                                        ) : only ? (
                                            <p className="text-caption mt-1 flex items-center gap-1">
                                                <LockKeyhole className="size-3" />
                                                Only way it’s sent
                                            </p>
                                        ) : null}
                                    </div>
                                );
                            },
                        },
                        {
                            key: 'email',
                            label: 'Email',
                            width: '0.55fr',
                            cell: (r) => channelCell(r, 'email'),
                        },
                        {
                            key: 'push',
                            label: 'Push',
                            width: '0.55fr',
                            cell: (r) => channelCell(r, 'push'),
                        },
                        {
                            key: 'fu',
                            label: 'Follow up',
                            width: '0.7fr',
                            cell: (r) => (
                                <div onClick={(e) => e.stopPropagation()}>
                                    <OnOff
                                        id={`al-${r.key}-fu`}
                                        checked={
                                            valueOf(s, draft, r.key).follow_up
                                        }
                                        disabled={orgRo}
                                        label={`${r.meta.label}: follow up until attended`}
                                        onChange={(v) =>
                                            setDraft((d) =>
                                                withDraft(
                                                    d,
                                                    G,
                                                    r.key,
                                                    encodeAlert(r.meta, {
                                                        ...valueOf(s, d, r.key),
                                                        follow_up: v,
                                                    }),
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            ),
                        },
                        {
                            key: 'to',
                            label: 'Goes to',
                            width: '1.3fr',
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
            <div className="text-subtle flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                <span className="inline-flex items-center gap-2">
                    <Shield className="size-3.5" />
                    Alerts about controlled medicines only reach people with
                    controlled-medicine access. Control Room shows these alerts
                    in its queue; who is told is set here.
                </span>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => go('alerts', 'delivery')}
                >
                    Review delivery & follow-up
                    <ArrowUpRight className="size-3.5" />
                </Button>
            </div>
        </Section>
    );
}

const PRIVATE_HINT =
    'Emails and push notifications, including on lock screens, say what happened and link to the app. The details stay in the app.';

/* ── Alerts & access › Delivery (v5 "Delivery & follow-up"): follow-up
 * (B2 C3), email, push and the privacy switch (C2), quiet hours (C5),
 * in-app, after hours (C4), what happens if nobody attends, then Who can't
 * be reached (C5). ── */
export function AlertsDelivery({
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
    const { s, draft, go, open, errors, clearError } = useSettings();
    const { value, edit, state, disabled, shown } = useRow(D);
    const keys = alertKeys(s);
    const count = (c: Channel) =>
        keys.filter((k) => valueOf(s, draft, k)[c]).length;
    // Counts and links aren't settings: only with every setting shown.
    const link = (...text: string[]) => show === 'all' && match(q, ...text);
    const toAlerts = (
        <Button variant="link" onClick={() => go('alerts', 'alerts')}>
            Alerts
            <ArrowUpRight className="size-4" />
        </Button>
    );
    const counted = (c: Channel) => (
        <span className="inline-flex items-center gap-3">
            <span className="text-subtle">
                {count(c)} of {keys.length}
            </span>
            {toAlerts}
        </span>
    );
    const privLabel =
        s.definitions[D]?.private?.label ??
        'Keep client names and medicines out of email and push';
    const def = (key: string) => s.definitions[D]?.[key];
    const off = def('realert_every')?.numeric?.off ?? 'off';
    const savedOf = (key: string) => s.values[D]?.[key] ?? off;
    const reOn = value('realert_every') !== off;
    const escOn = value('escalate_after') !== off;
    const fu = keys.filter((k) => valueOf(s, draft, k).follow_up);
    const fuLabels = fu.map((k) => metaOf(s, k)?.label ?? k);
    const toOnCall = keys
        .filter((k) => valueOf(s, draft, k).groups.includes('onCall'))
        .map((k) => metaOf(s, k)?.label ?? k);
    const groups = def('escalate_to')?.group_options ?? [];
    const escalateTo = parseGroups(value('escalate_to')) ?? [];
    const err = (key: string) => errors[`${D}.${key}`];
    const num = (
        key: string,
        label: string,
        unit: string,
        errorId?: string,
    ) => (
        <NumberInput
            id={`dl-${key}`}
            label={label}
            value={value(key)}
            unit={unit}
            min={def(key)?.range?.[0]}
            max={def(key)?.range?.[1]}
            disabled={disabled}
            error={err(key)}
            errorId={errorId}
            onChange={(v) => {
                edit(key, v);
                clearError(`${D}.${key}`);
            }}
        />
    );
    // On asks for numbers (v5): the saved ones, or empty boxes — never invented.
    const switchOn = (key: string, on: boolean) => {
        const was = savedOf(key);
        edit(key, on ? (was !== off ? was : '') : off);
        clearError(`${D}.${key}`);
    };
    const pairState =
        state('realert_every') === 'changed' ||
        state('realert_max') === 'changed'
            ? 'changed'
            : state('realert_every');
    const reLabel =
        def('realert_every')?.label ?? 'Re-alert until someone attends';
    const escLabel =
        def('escalate_after')?.label ?? 'Escalate if still not attended';
    const attLabel =
        def('attended')?.label ?? 'An alert counts as attended when';
    return (
        <>
            <Section
                id="sc-delivery"
                title="Delivery & follow-up"
                caption="How alerts reach people, and what happens if nobody attends"
                right={<EntityChip icon={Building2}>Every house</EntityChip>}
            >
                {fu.length && !reOn && !escOn ? (
                    <InfoCard icon={AlertTriangle}>
                        <b>
                            Follow up is on for {fu.length} alert{' '}
                            {fu.length === 1 ? 'type' : 'types'}, but
                            re-alerting and escalation are both off.
                        </b>{' '}
                        Each alert is still sent once, as today.
                    </InfoCard>
                ) : null}
                <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                    {def('realert_every') && def('realert_max') ? (
                        <SettingGroup
                            id="realert"
                            icon={BellRing}
                            title="Re-alert until attended"
                            caption={`For the ${fu.length} alert ${fu.length === 1 ? 'type' : 'types'} with Follow up on`}
                        >
                            <GroupRow
                                id="dl-reon"
                                label={reLabel}
                                hint={
                                    reOn
                                        ? `Sent again to everyone told so far every ${value('realert_every') || '…'} minutes, up to ${value('realert_max') === off ? '…' : value('realert_max') || '…'} times, until someone attends.`
                                        : 'Off — each alert is sent once (today).'
                                }
                                state={pairState}
                                error={
                                    err('realert_every') || err('realert_max')
                                }
                                errorId="dl-realert-error"
                                hidden={
                                    !shown(
                                        show,
                                        q,
                                        'realert_every',
                                        reLabel,
                                        're-alert repeat',
                                    )
                                }
                                control={
                                    <OnOff
                                        id="dl-reon"
                                        checked={reOn}
                                        disabled={disabled}
                                        label={reLabel}
                                        onChange={(v) => {
                                            switchOn('realert_every', v);
                                            switchOn('realert_max', v);
                                        }}
                                    />
                                }
                            >
                                {reOn ? (
                                    <span className="inline-flex flex-wrap items-center gap-2">
                                        <span className="text-subtle">
                                            Every
                                        </span>
                                        {num(
                                            'realert_every',
                                            'Minutes between re-alerts',
                                            'minutes, up to',
                                            'dl-realert-error',
                                        )}
                                        {num(
                                            'realert_max',
                                            'Most re-alerts',
                                            'times',
                                            'dl-realert-error',
                                        )}
                                    </span>
                                ) : null}
                            </GroupRow>
                            {def('attended') ? (
                                <GroupRow
                                    id="dl-attended"
                                    label={attLabel}
                                    hint="Stops re-alerts and escalation. Recorded with the person’s name and the time."
                                    state={state('attended')}
                                    hidden={
                                        !shown(
                                            show,
                                            q,
                                            'attended',
                                            attLabel,
                                            'acknowledge opens dealt',
                                        )
                                    }
                                >
                                    <Choice
                                        value={value('attended')}
                                        disabled={disabled}
                                        onChange={(v) => edit('attended', v)}
                                        options={(
                                            def('attended')?.options ?? []
                                        ).map(
                                            (o) =>
                                                [o.value, o.label] as [
                                                    string,
                                                    string,
                                                ],
                                        )}
                                    />
                                </GroupRow>
                            ) : null}
                            <GroupRow
                                id="dl-fu"
                                label="Alerts followed up"
                                hint={
                                    fuLabels.length
                                        ? fuLabels.join(', ')
                                        : 'None — turn on Follow up in the Alerts table.'
                                }
                                hidden={
                                    !link('Alerts followed up', 'follow up')
                                }
                                control={toAlerts}
                            />
                        </SettingGroup>
                    ) : null}
                    {def('escalate_after') && def('escalate_to') ? (
                        <SettingGroup
                            id="escalate"
                            icon={Siren}
                            title="Escalate if still not attended"
                            caption="Tells more people, as well as the first ones"
                        >
                            <GroupRow
                                id="dl-escon"
                                label={escLabel}
                                hint={
                                    escOn
                                        ? `After ${value('escalate_after') || '…'} minutes with nobody attending.`
                                        : 'Off — nobody else is told (today).'
                                }
                                state={state('escalate_after')}
                                error={err('escalate_after')}
                                errorId="dl-escalate_after-error"
                                hidden={
                                    !shown(
                                        show,
                                        q,
                                        'escalate_after',
                                        escLabel,
                                        'escalate',
                                    )
                                }
                                control={
                                    <OnOff
                                        id="dl-escon"
                                        checked={escOn}
                                        disabled={disabled}
                                        label={escLabel}
                                        onChange={(v) => {
                                            switchOn('escalate_after', v);
                                            if (!v)
                                                edit(
                                                    'escalate_to',
                                                    s.values[D]?.escalate_to ??
                                                        def('escalate_to')
                                                            ?.default ??
                                                        '[]',
                                                );
                                            clearError(`${D}.escalate_to`);
                                        }}
                                    />
                                }
                            >
                                {escOn
                                    ? num(
                                          'escalate_after',
                                          'Minutes before escalating',
                                          'minutes',
                                      )
                                    : null}
                            </GroupRow>
                            {escOn ? (
                                <GroupRow
                                    id="dl-escalate_to"
                                    label={
                                        def('escalate_to')?.label ??
                                        'Escalate to'
                                    }
                                    hint="After hours, the on-call person is whoever the roster says."
                                    state={state('escalate_to')}
                                    error={err('escalate_to')}
                                    errorId="dl-escalate_to-error"
                                    hidden={
                                        !shown(
                                            show,
                                            q,
                                            'escalate_to',
                                            'Escalate to',
                                            'escalate',
                                        )
                                    }
                                >
                                    {disabled ? (
                                        <p className="text-[13px]">
                                            {escalateTo
                                                .map(
                                                    (g) =>
                                                        groups.find(
                                                            (o) =>
                                                                o.value === g,
                                                        )?.label ?? g,
                                                )
                                                .join(', ') || 'Nobody chosen'}
                                        </p>
                                    ) : (
                                        <ChipMulti
                                            values={escalateTo.map(
                                                (g) =>
                                                    groups.find(
                                                        (o) => o.value === g,
                                                    )?.label ?? g,
                                            )}
                                            options={groups.map((o) => o.label)}
                                            onChange={(labels) => {
                                                edit(
                                                    'escalate_to',
                                                    encodeGroups(
                                                        def('escalate_to')!,
                                                        groups
                                                            .filter((o) =>
                                                                labels.includes(
                                                                    o.label,
                                                                ),
                                                            )
                                                            .map(
                                                                (o) => o.value,
                                                            ),
                                                    ),
                                                );
                                                clearError(`${D}.escalate_to`);
                                            }}
                                        />
                                    )}
                                </GroupRow>
                            ) : null}
                        </SettingGroup>
                    ) : null}
                    <SettingGroup
                        id="email"
                        icon={Mail}
                        title="Email"
                        caption="Sent to each person’s work email"
                    >
                        <GroupRow
                            id="dl-emailn"
                            label="Alert types sent by email"
                            hint="Turn email on per alert in the Alerts table. People without a work email aren’t emailed."
                            hidden={!link('Alert types sent by email', 'email')}
                            control={counted('email')}
                        />
                        <GroupRow
                            id="dl-preview"
                            label="See what a message looks like"
                            hint="In the bell, by email and on a lock screen — with this switch applied."
                            hidden={
                                !link(
                                    'See what a message looks like',
                                    'preview',
                                    'message',
                                )
                            }
                            control={
                                <Button
                                    variant="link"
                                    onClick={() =>
                                        open({
                                            kind: 'msgpreview',
                                            key: 'overdue',
                                        })
                                    }
                                >
                                    Preview
                                    <ArrowUpRight className="size-4" />
                                </Button>
                            }
                        />
                        <GroupRow
                            id="dl-private"
                            label={privLabel}
                            hint={PRIVATE_HINT}
                            state={state('private')}
                            hidden={
                                !shown(
                                    show,
                                    q,
                                    'private',
                                    privLabel,
                                    PRIVATE_HINT,
                                    'privacy email push',
                                )
                            }
                            control={
                                <OnOff
                                    id="dl-private"
                                    checked={value('private') === 'yes'}
                                    disabled={disabled}
                                    label={privLabel}
                                    onChange={(v) =>
                                        edit('private', v ? 'yes' : 'no')
                                    }
                                />
                            }
                        />
                    </SettingGroup>
                    <SettingGroup
                        id="push"
                        icon={Smartphone}
                        title="Push"
                        caption="To the phone app and browsers people have allowed"
                    >
                        <GroupRow
                            id="dl-pushn"
                            label="Alert types sent by push"
                            hint="Turn push on per alert in the Alerts table."
                            hidden={!link('Alert types sent by push', 'push')}
                            control={counted('push')}
                        />
                        <GroupRow
                            id="dl-pushready"
                            label="Staff with push set up"
                            hint="People turn push on for their own phone or browser, in their account › Notifications. Gaps are listed under Who can’t be reached."
                            hidden={!link('Staff with push set up', 'push')}
                            control={
                                <span className="text-subtle">
                                    {data.delivery.push_ready} of{' '}
                                    {data.delivery.people}
                                </span>
                            }
                        />
                    </SettingGroup>
                    <QuietHoursGroup
                        q={q}
                        show={show}
                        houses={data.houses}
                        houseIds={data.access.house_ids}
                        readOnlyAudit={data.readOnlyAudit}
                    />
                    <SettingGroup
                        id="inapp"
                        icon={Bell}
                        title="In-app"
                        caption="The bell (notifications)"
                    >
                        <GroupRow
                            id="dl-inappn"
                            label="Alert types sent in-app"
                            hint="Decided alerts are always in-app; any other alert stays in-app unless email or push is on."
                            hidden={!link('Alert types sent in-app', 'in-app')}
                            control={counted('inapp')}
                        />
                        {s.definitions[D]?.pin_unattended && (
                            <GroupRow
                                id="dl-pin_unattended"
                                label="Keep unattended alerts at the top of the bell"
                                hint="Off by default. Medication follow-up alerts only; shared attendance ends the priority."
                                state={state('pin_unattended')}
                                hidden={
                                    !shown(
                                        show,
                                        q,
                                        'pin_unattended',
                                        'Keep unattended alerts at the top of the bell',
                                    )
                                }
                                control={
                                    <OnOff
                                        id="dl-pin_unattended"
                                        checked={
                                            value('pin_unattended') === 'yes'
                                        }
                                        disabled={disabled}
                                        onChange={(v) =>
                                            edit(
                                                'pin_unattended',
                                                v ? 'yes' : 'no',
                                            )
                                        }
                                    />
                                }
                            />
                        )}
                    </SettingGroup>
                    <SettingGroup
                        id="afterhours"
                        icon={Phone}
                        title="After hours"
                        caption="Nobody is phoned automatically"
                    >
                        <GroupRow
                            id="dl-oncall"
                            label="On-call contacts"
                            hint="Follows the roster: on-call shift, then the team lead on shift, then a backup person."
                            hidden={!link('On-call contacts', 'after hours')}
                            control={
                                <Button
                                    variant="link"
                                    onClick={() => go('alerts', 'oncall')}
                                >
                                    {
                                        data.onCall.houses.filter((h) => h.rule)
                                            .length
                                    }{' '}
                                    of {data.onCall.houses.length} houses
                                    <ArrowUpRight className="size-4" />
                                </Button>
                            }
                        />
                        <GroupRow
                            id="dl-oncallgets"
                            label="Alerts that go to the on-call person"
                            hint={
                                toOnCall.length
                                    ? toOnCall.join(', ')
                                    : escOn && escalateTo.includes('onCall')
                                      ? 'Only through escalation.'
                                      : 'None yet — add them in an alert’s groups, or escalate to them.'
                            }
                            hidden={
                                !link(
                                    'Alerts that go to the on-call person',
                                    'on-call',
                                )
                            }
                            control={toAlerts}
                        />
                    </SettingGroup>
                    {show === 'all' ? (
                        <FollowUpPreview houses={data.houses} />
                    ) : null}
                </GroupGrid>
            </Section>
            <WhoCantBeReached gaps={data.reachGaps} />
        </>
    );
}

/** v5: whole hours as hours ("1 hour", "2 hours"), anything else in minutes ("90 minutes"). */
const fmtMin = (m: number) =>
    m >= 60 && m % 60 === 0
        ? `${m / 60} ${m === 60 ? 'hour' : 'hours'}`
        : `${m} minutes`;

/* ── What happens if nobody attends (v5): worked out from the draft for one
 * alert; a re-alert and an escalation at the same moment are one step, and
 * after an escalation re-alerts go to everyone told so far. Nothing is sent. ── */
function FollowUpPreview({
    houses,
}: {
    houses: { id: number; name: string }[];
}) {
    const { s, draft } = useSettings();
    const keys = alertKeys(s);
    const [k, setK] = useState(keys.includes('overdue') ? 'overdue' : keys[0]);
    const meta = metaOf(s, k);
    if (!meta) return null;
    const x = valueOf(s, draft, k);
    const v = (key: string) => draftValue(s, draft, D, key);
    const off = s.definitions[D]?.realert_every?.numeric?.off ?? 'off';
    const num = (key: string) => (v(key) !== off ? Number(v(key)) || 0 : 0);
    const every = num('realert_every');
    const max = num('realert_max');
    const after = num('escalate_after');
    const groupOptions = s.definitions[D]?.escalate_to?.group_options ?? [];
    const via =
        (['inapp', 'email', 'push'] as const)
            .filter((c) => x[c])
            .map((c) => ({ inapp: 'in-app', email: 'email', push: 'push' })[c])
            .join(', ')
            .replace(/, ([^,]*)$/, ' and $1') || 'no channel';
    const first =
        [
            ...x.groups.map((g) => meta.group_labels[g]?.label ?? g),
            ...x.people.map((id) => nameOf(s, id)),
        ].join(', ') || 'Nobody';
    const esc =
        x.follow_up && after
            ? (parseGroups(v('escalate_to')) ?? [])
                  .map(
                      (g) =>
                          groupOptions.find((o) => o.value === g)?.label ?? g,
                  )
                  .join(', ')
            : '';
    const reAt =
        x.follow_up && every && max
            ? Array.from({ length: max }, (_, i) => every * (i + 1))
            : [];
    const times = [...new Set([...reAt, ...(esc ? [after] : [])])].sort(
        (p, q) => p - q,
    );
    const ev = times.map((t): [number, string, string] => {
        const re = reAt.includes(t);
        const up = !!esc && t === after;
        const since = !!esc && t > after;
        if (re && up)
            return [
                t,
                'Re-alert and escalate',
                `The same people again, plus ${esc} · ${via}`,
            ];
        if (up) return [t, 'Escalate', `${esc} · ${via}`];
        return [
            t,
            'Re-alert',
            since
                ? `Everyone told so far, including ${esc} · ${via}`
                : `The same people · ${via}`,
        ];
    });
    const shownEv = ev.slice(0, 5);
    // Quiet hours (B2 C5): email and push wait; never for Follow up alerts.
    const quiet = houses
        .map((h) => [h, quietAt(s, draft, h.id)] as const)
        .filter(([, w]) => w);
    const stop = (
        s.definitions[D]?.attended?.options.find(
            (o) => o.value === v('attended'),
        )?.label ?? 'Someone acknowledges it'
    ).toLowerCase();
    return (
        <ReviewCard icon={Timer} title="What happens if nobody attends" span>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <p className="text-subtle">
                    Worked out from your draft, for one alert. Nothing is sent.
                </p>
                <div className="w-72">
                    <SelectInput
                        value={k}
                        onChange={setK}
                        placeholder="Choose an alert"
                        ariaLabel="Alert to preview"
                        options={keys.map((y) => ({
                            value: y,
                            label: metaOf(s, y)?.label ?? y,
                        }))}
                    />
                </div>
            </div>
            <ReviewRow
                label="When it happens"
                value={
                    <span className="text-right">
                        <b>{first}</b>
                        <span className="text-caption block">
                            {via === 'no channel'
                                ? 'Nobody is told'
                                : `By ${via}`}
                        </span>
                    </span>
                }
            />
            {shownEv.map(([t, what, who]) => (
                <ReviewRow
                    key={`${t}-${what}`}
                    label={`After ${fmtMin(t)}`}
                    value={
                        <span className="text-right">
                            <b>{what}</b>
                            <span className="text-caption block">{who}</span>
                        </span>
                    }
                />
            ))}
            {ev.length > shownEv.length ? (
                <ReviewRow
                    label="Then"
                    value={`${ev.length - shownEv.length} more re-alerts, up to ${max} in all`}
                />
            ) : null}
            {quiet.length && !x.follow_up && (x.email || x.push) ? (
                <ReviewRow
                    label="Overnight"
                    value={
                        <span className="text-right">
                            Email and push wait; the bell shows it straight away
                            <span className="text-caption block">
                                {quiet
                                    .map(
                                        ([h, w]) =>
                                            `${h.name}: ${fmtT(w!.from)} to ${fmtT(w!.until)}${w!.source === 'house' ? ' (own hours)' : ''}`,
                                    )
                                    .join(' · ')}
                            </span>
                        </span>
                    }
                />
            ) : null}
            <ReviewRow
                label="Stops"
                value={
                    !x.follow_up
                        ? `Follow up is off for “${meta.label}” — it’s sent once.`
                        : !ev.length
                          ? 'Sent once — re-alerting and escalation are off.'
                          : `When ${stop}. The alert itself stays ${meta.until.toLowerCase()}.`
                }
            />
        </ReviewCard>
    );
}

const CHANNEL_NAME: Record<Channel, string> = {
    inapp: 'In-app',
    email: 'Email',
    push: 'Push',
};

/* ── Message preview (v5): what an alert looks like in the bell, by email and
 * on a lock screen, from the draft's channels and privacy switch. The words
 * come from the server, rendered by the real notification from a synthetic
 * sample; nothing is sent. ── */
export function MessagePreview({
    k: k0,
    data,
}: {
    k: string;
    data: AlertData;
}) {
    const { s, draft, close } = useSettings();
    const keys = alertKeys(s).filter((k) => data.previews[k]);
    const [k, setK] = useState(keys.includes(k0) ? k0 : keys[0]);
    const [step, setStep] = useState(0);
    const [ch, setCh] = useState<Channel>('inapp');
    const meta = k ? metaOf(s, k) : undefined;
    const p = k ? data.previews[k] : undefined;
    if (!meta || !p) return <NotFound what="alert" />;
    const x = valueOf(s, draft, k);
    const priv = draftValue(s, draft, D, 'private') !== 'no';
    const out = priv ? p.private : p.open;
    const name = CHANNEL_NAME[ch];
    const body =
        ch === 'inapp' ? (
            <>
                <p className="text-[13px] font-semibold">{p.inapp.title}</p>
                <p className="text-subtle mt-1">{p.inapp.message}</p>
                <p className="text-caption mt-2">
                    In the bell · Open to see the record
                </p>
            </>
        ) : ch === 'email' ? (
            <>
                <p className="text-caption">Subject</p>
                <p className="text-[13px] font-semibold">{out.email.subject}</p>
                {out.email.lines.map((line) => (
                    <p key={line} className="text-subtle mt-2">
                        {line}
                    </p>
                ))}
                <p className="text-caption mt-2">
                    Button: {out.email.action} · Sent to your work email
                </p>
            </>
        ) : (
            <>
                <p className="text-caption">Oblivion Care · now</p>
                <p className="text-[13px] font-semibold">{out.push.title}</p>
                <p className="text-subtle mt-1">{out.push.body}</p>
                <p className="text-caption mt-2">Shows on the lock screen</p>
            </>
        );
    const names = [
        ...x.groups.map((g) => meta.group_labels[g]?.label ?? g),
        ...x.people.map((id) => nameOf(s, id)),
    ];
    return (
        <WizardShell
            frontline
            open
            onClose={close}
            title="Message preview"
            description="Synthetic sample; no message is sent."
            railIcon={Bell}
            railTitle={meta.label}
            railSub="Synthetic sample"
            steps={[
                {
                    key: 'sample',
                    label: 'Sample',
                    blurb: 'What people see',
                    icon: Eye,
                },
                {
                    key: 'source',
                    label: 'Who and how',
                    blurb: 'Channels and recipients',
                    icon: Shield,
                },
            ]}
            stepIndex={step}
            onStepClick={setStep}
            sequential={false}
            pct={null}
            headerLabel={step ? 'Who and how' : 'Message preview'}
            footerEnd={
                <Button variant="outline" onClick={close}>
                    Close preview
                </Button>
            }
        >
            <WizardStepPane key={step}>
                {step === 0 ? (
                    <div className="space-y-4">
                        <SelectInput
                            value={k}
                            onChange={setK}
                            placeholder="Choose an alert"
                            ariaLabel="Alert to preview"
                            options={keys.map((y) => ({
                                value: y,
                                label: metaOf(s, y)?.label ?? y,
                            }))}
                        />
                        <Sections
                            tabs={[
                                { key: 'inapp', label: 'In-app', icon: Bell },
                                { key: 'email', label: 'Email', icon: Mail },
                                {
                                    key: 'push',
                                    label: 'Push',
                                    icon: Smartphone,
                                },
                            ]}
                            value={ch}
                            onChange={(v) => setCh(v as Channel)}
                        />
                        <SettingsNotice role="note">
                            <span>
                                Showing your draft.{' '}
                                {x[ch]
                                    ? `${name} is on for this alert.`
                                    : `${name} is off for this alert — this is how it would look.`}
                                {ch !== 'inapp'
                                    ? priv
                                        ? ' Client names and medicines are left out, because “Keep client names and medicines out of email and push” is on.'
                                        : ' Client names and medicines are included — “Keep client names and medicines out of email and push” is off.'
                                    : ''}
                            </span>
                        </SettingsNotice>
                        <ReviewCard
                            icon={
                                ch === 'inapp'
                                    ? Bell
                                    : ch === 'email'
                                      ? Mail
                                      : Smartphone
                            }
                            title={`${name} · example`}
                        >
                            {body}
                            <p className="text-caption mt-3">
                                Sample content only. No real person or record is
                                used.
                            </p>
                        </ReviewCard>
                        {p.controlled || meta.controlled ? (
                            <p className="text-caption inline-flex items-center gap-1.5">
                                <LockKeyhole className="size-3.5" />A
                                controlled-medicine alert: only people with
                                controlled-medicine access get it.
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <ReviewCard
                        icon={Shield}
                        title="How it’s sent, and to whom"
                    >
                        <ReviewRow
                            label="In-app"
                            value={x.inapp ? 'On' : 'Off'}
                        />
                        <ReviewRow
                            label="Email"
                            value={x.email ? 'On' : 'Off'}
                        />
                        <ReviewRow label="Push" value={x.push ? 'On' : 'Off'} />
                        <ReviewRow
                            label="Goes to"
                            value={names.join(', ') || 'Nobody'}
                        />
                        <ReviewRow
                            label="Follow up"
                            value={
                                x.follow_up
                                    ? 'On — re-alert and escalation follow the Delivery settings'
                                    : 'Off — sent once'
                            }
                        />
                        <ReviewRow
                            label="Email and push"
                            value={
                                priv
                                    ? 'Leave out client names and medicines'
                                    : 'Include client names and medicines'
                            }
                        />
                    </ReviewCard>
                )}
            </WizardStepPane>
        </WizardShell>
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
    // v5: the on-call group says which houses have no contact yet (B2 C4).
    const noOnCall = data.onCall.houses
        .filter((h) => !h.rule)
        .map((h) => h.name);
    const groupCaption = (g: string, description?: string) =>
        g === 'onCall' && noOnCall.length
            ? `${description ?? ''}. ${noOnCall.length > 2 ? `${noOnCall.slice(0, 2).join(', ')} and ${noOnCall.length - 2} more houses have` : `${noOnCall.join(' and ')} ${noOnCall.length === 1 ? 'has' : 'have'}`} no on-call contact yet.`
            : description;
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
                frontline
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
                                                        : groupCaption(
                                                              g,
                                                              meta.group_labels[
                                                                  g
                                                              ]?.description,
                                                          )}
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
                frontline
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
            frontline
            title={`Add a person to “${meta.label}”`}
            width={720}
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
    if (dialog?.kind === 'msgpreview')
        return <MessagePreview k={dialog.key} data={data} />;
    return null;
}
