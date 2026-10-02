/* Medication › Settings › Alerts & access (eMAR P11 v5, B2): who gets each
 * medication alert. Stephan, 29 Sep 2026: the organisation sets each alert's
 * channels and who gets it, one set for every house; house managers add extra
 * people for their own houses; the routing he decided stays locked on.
 * Everything here goes into the page draft and applies when the Alerts tab
 * is reviewed and saved.
 *
 * Built so far: the Alerts tab (in-app, email, push, who gets it), Delivery
 * (email, push, in-app and the privacy switch), the message preview and their
 * Overview cards. Follow up, quiet hours, On-call contacts and the Alert log
 * arrive with their chunks (P11 B2 C3–C6) — until then they aren't shown
 * (hide-unbuilt). */
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
    Building2,
    Check,
    ChevronLeft,
    ChevronRight,
    Eye,
    Home,
    LockKeyhole,
    Mail,
    Plus,
    Shield,
    Smartphone,
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
    inAppLocked,
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
import { NoMatches, useRow } from './_sections';
import {
    Changed,
    DefaultNotReviewed,
    GroupGrid,
    GroupRow,
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
    const { s, go } = useSettings();
    const keys = alertKeys(s);
    const on = (c: Channel) =>
        keys.filter((k) => parseAlert(draftValue(s, {}, G, k))?.[c]).length;
    const open = keys.filter((k) => !decisionReviewer(s, G, k)).length;
    const priv = draftValue(s, {}, D, 'private') !== 'no';
    const deliveryOpen = decisionReviewer(s, D, 'private') ? 0 : 1;
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
                    title: 'Delivery',
                    lines: [
                        'Each alert is sent once, straight away.',
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
                    cta: 'Review delivery',
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
                    minWidth={1000}
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
                            width: '0.6fr',
                            cell: (r) => channelCell(r, 'email'),
                        },
                        {
                            key: 'push',
                            label: 'Push',
                            width: '0.6fr',
                            cell: (r) => channelCell(r, 'push'),
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
                    Review delivery
                    <ArrowUpRight className="size-3.5" />
                </Button>
            </div>
        </Section>
    );
}

const PRIVATE_HINT =
    'Emails and push notifications, including on lock screens, say what happened and link to the app. The details stay in the app.';

/* ── Alerts & access › Delivery (v5 "Delivery & follow-up"; B2 C2: email,
 * push, in-app and the privacy switch — follow-up, quiet hours and after
 * hours arrive with C3–C5). ── */
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
    const { s, draft, go, open } = useSettings();
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
    return (
        <Section
            id="sc-delivery"
            title="Delivery"
            caption="How alerts reach people"
            right={<EntityChip icon={Building2}>Every house</EntityChip>}
        >
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
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
                                    open({ kind: 'msgpreview', key: 'overdue' })
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
                        hint="People turn push on for their own phone or browser, in their account › Notifications."
                        hidden={!link('Staff with push set up', 'push')}
                        control={
                            <span className="text-subtle">
                                {data.delivery.push_ready} of{' '}
                                {data.delivery.people}
                            </span>
                        }
                    />
                </SettingGroup>
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
                </SettingGroup>
            </GroupGrid>
        </Section>
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
    if (dialog?.kind === 'msgpreview')
        return <MessagePreview k={dialog.key} data={data} />;
    return null;
}
