/* Safety & oversight › Staff eligibility (eMAR P11 v5 `eligibility.tsx`).
 * It replaces Medication › Competency. The rail is the Safety & oversight
 * views — only Staff eligibility exists so far (P07a, P08a, P08b, P09 and P10
 * add theirs) — and Register · Renewals · Exemptions are header filter chips
 * (Rory: nothing below the header band).
 *
 * Every number comes from the server's competency decision. There is no
 * witness view or "Can witness" meter: that rule is enforced with P07b; the
 * register keeps the real witness PIN status. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EntityChip, PersonDisc } from '@/components/lists/entity-cells';
import {
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Download,
    Eye,
    Home,
    Pencil,
    Plus,
    RefreshCw,
    Shield,
    ShieldCheck,
    Trash2,
    User,
    UserCheck,
    Users,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    AssessmentView,
    AssessmentWizard,
    type AssessMode,
    type EligClient,
} from './eligibility/_assessment';
import {
    EndExemption,
    ExemptionWizard,
    type ExemptionPerson,
} from './eligibility/_exemptions';
import {
    areaRes,
    assessMode,
    day,
    eligLine,
    firstName,
    givenAbility,
    nextStep,
    PIN_LABEL,
    PIN_VARIANT,
    STATUS_META,
    type AreaMeta,
    type EligExemption,
    type EligPerson,
    type EligPolicy,
} from './eligibility/_model';
import { StatusChip } from './eligibility/_parts';
import { Note, RowMenu, Section } from './settings/_ui';

type Props = {
    people: EligPerson[];
    exemptions: EligExemption[];
    houses: { id: number; name: string }[];
    policy: EligPolicy;
    areas: AreaMeta[];
    can: { assess: boolean; exempt: boolean; reset_pins: boolean };
    clients: EligClient[];
    me: { id: number; name: string };
    loaded_at: string;
};

type View = 'register' | 'renewals' | 'exemptions';
const VIEWS: [View, string][] = [
    ['register', 'Register'],
    ['renewals', 'Renewals'],
    ['exemptions', 'Exemptions'],
];
const STATUS_FILTERS = [
    { value: 'all', label: 'Any status' },
    { value: 'alone', label: 'Can record given doses alone' },
    { value: 'due', label: 'Due for renewal' },
    { value: 'cant', label: 'Can’t record given doses' },
    { value: 'restricted', label: 'Restricted' },
    { value: 'areas', label: 'Areas not passed' },
];
const RULE_WORD = { block: 'Block', cosigner: 'co-signer', off: 'Off' };

type Dialog =
    | { kind: 'view'; id: number }
    | { kind: 'assess'; id?: number; mode: AssessMode }
    | { kind: 'exempt'; id?: number }
    | { kind: 'end'; exemption: EligExemption }
    | { kind: 'delete'; person: EligPerson }
    | { kind: 'reset'; person: EligPerson };

/** A CSV cell that a spreadsheet can't run as a formula. */
const csvCell = (v: unknown) => {
    const s = v == null ? '' : String(v);
    const safe = /^[=+\-@\t\r]/.test(s) ? `'${s}` : s;
    return /[",\n]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
};

const readQuery = () => new URLSearchParams(window.location.search);

export default function StaffEligibility(props: Props) {
    const { people, exemptions, houses, policy, areas, can } = props;
    const [view, setView] = useState<View>(() => {
        const v = readQuery().get('view');
        return v === 'renewals' || v === 'exemptions' ? v : 'register';
    });
    const [house, setHouse] = useState(() => readQuery().get('house') ?? 'all');
    const [status, setStatus] = useState(
        () => readQuery().get('status') ?? 'all',
    );
    const [role, setRole] = useState('all');
    const [q, setQ] = useState('');
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const [busy, setBusy] = useState(false);

    // The address keeps the sub-view and filters, so a link opens the same list.
    useEffect(() => {
        const p = new URLSearchParams();
        if (view !== 'register') p.set('view', view);
        if (house !== 'all') p.set('house', house);
        if (view === 'register' && status !== 'all') p.set('status', status);
        const qs = p.toString();
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}${qs ? `?${qs}` : ''}`,
        );
    }, [view, house, status]);

    const inHouse = (id: number | null) =>
        house === 'all' || String(id) === house;
    // Name, role, house or assessor, as the old register searched.
    const matches = (x: EligPerson) =>
        !q ||
        [x.name, x.role, x.house, x.assessment?.assessor].some((s) =>
            (s ?? '').toLowerCase().includes(q.toLowerCase()),
        );
    const scoped = people.filter((x) => inHouse(x.house_id));
    const alone = scoped.filter(
        (x) => givenAbility(x, policy).v === 'yes' && x.st !== 'restricted',
    );
    const due = scoped
        .filter((x) => x.status === 'due')
        .sort((a, b) => (a.days ?? 0) - (b.days ?? 0));
    const cant = scoped.filter(
        (x) => givenAbility(x, policy).v === 'no' && x.st !== 'restricted',
    );
    const restricted = scoped.filter((x) => x.st === 'restricted');
    const activeExemptions = exemptions.filter(
        (e) => e.status === 'active' && inHouse(e.house_id),
    );
    const roles = useMemo(
        () =>
            [...new Set(people.map((x) => x.role).filter(Boolean) as string[])]
                .sort()
                .map((r) => ({ value: r, label: r })),
        [people],
    );
    const go = (next: View, nextStatus?: string) => {
        setView(next);
        if (nextStatus !== undefined) setStatus(nextStatus);
    };
    const person = (id: number) => people.find((x) => x.id === id) ?? null;

    const actions = (x: EligPerson): MenuItem[] =>
        compactMenu([
            x.assessment && {
                label: 'View assessment',
                icon: Eye,
                onClick: () => setDialog({ kind: 'view', id: x.id }),
            },
            x.can?.assess &&
                x.st !== 'ack' && {
                    label:
                        x.st === 'none'
                            ? 'Start first assessment'
                            : x.st === 'failed'
                              ? 'Start remedial assessment'
                              : 'Renew or reassess',
                    icon: ClipboardCheck,
                    onClick: () =>
                        setDialog({
                            kind: 'assess',
                            id: x.id,
                            mode: assessMode(x),
                        }),
                },
            x.can?.assess &&
                x.assessment && {
                    label: 'Edit this assessment',
                    icon: Pencil,
                    onClick: () =>
                        setDialog({ kind: 'assess', id: x.id, mode: 'edit' }),
                },
            x.can?.exempt && {
                label: 'Grant an exemption',
                icon: ShieldCheck,
                onClick: () => setDialog({ kind: 'exempt', id: x.id }),
            },
            { separator: true },
            {
                label: 'View staff member',
                icon: User,
                onClick: () => router.visit(`/staff/${x.id}`),
            },
            x.can?.reset_pin && {
                label: 'Reset witness PIN',
                icon: RefreshCw,
                danger: true,
                onClick: () => setDialog({ kind: 'reset', person: x }),
            },
            x.can?.assess &&
                x.assessment && {
                    label: 'Delete this assessment',
                    icon: Trash2,
                    danger: true,
                    onClick: () => setDialog({ kind: 'delete', person: x }),
                },
        ]);

    const exportRegister = () => {
        const head = [
            'Person',
            'Role',
            'House',
            'Status',
            'Until',
            'Given doses',
            'Areas not passed',
            'Witness PIN',
            'Assessed',
            'Assessor',
            'Type',
            'Score',
        ];
        const lines = scoped.map((x) =>
            [
                x.name,
                x.role,
                x.house,
                STATUS_META[x.status][1],
                x.until,
                givenAbility(x, policy).t,
                areas
                    .filter((a) => areaRes(x, a.key) === 'no')
                    .map((a) => a.label)
                    .join('; '),
                PIN_LABEL[x.pin],
                x.assessment?.assessed,
                x.assessment?.assessor,
                x.assessment?.type_label,
                x.assessment ? `${x.assessment.passed}/12` : '',
            ]
                .map(csvCell)
                .join(','),
        );
        const blob = new Blob([[head.join(','), ...lines].join('\n')], {
            type: 'text/csv;charset=utf-8;',
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `staff-eligibility-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    };

    const houseNames = houses.map((h) => h.name);
    const header = (
        <PageHeader
            icon={Shield}
            title="Safety & oversight"
            titleChip={
                <PageHeaderStatusChip variant="neutral">
                    {houses.length} {houses.length === 1 ? 'house' : 'houses'}
                </PageHeaderStatusChip>
            }
            subline={`${houseNames.length > 3 ? `${houseNames.slice(0, 3).join(', ')} and ${houseNames.length - 3} more` : houseNames.join(' and ') || 'No houses'} · your approved houses · times in NZDT (Pacific/Auckland)`}
            actions={
                <>
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder="Search staff, role or assessor"
                    />
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={exportRegister}
                        disabled={!scoped.length}
                    >
                        Export register
                    </PageHeaderGlassButton>
                    {can.assess ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() =>
                                setDialog({ kind: 'assess', mode: 'new' })
                            }
                        >
                            New assessment
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Can record alone"
                        ariaLabel={`View ${alone.length} staff who can record given doses alone`}
                        pressed={view === 'register' && status === 'alone'}
                        onClick={() => go('register', 'alone')}
                    >
                        {scoped.length ? (
                            <PageHeaderMeterDonut
                                percent={(alone.length / scoped.length) * 100}
                                caption={
                                    <>
                                        {alone.length} of {scoped.length}
                                        <br />
                                        current, not restricted
                                    </>
                                }
                            />
                        ) : (
                            <>
                                <PageHeaderMeterBig>n/a</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Nobody records doses here
                                </PageHeaderMeterCaption>
                            </>
                        )}
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Due for renewal"
                        tone={due.length ? 'warning' : 'brand'}
                        ariaLabel={`View ${due.length} renewals due`}
                        pressed={view === 'renewals'}
                        onClick={() => go('renewals')}
                    >
                        <PageHeaderMeterBig>{due.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {due.length
                                ? `First: ${firstName(due[0])}, ${day(due[0].until)}`
                                : `Nothing within ${policy.renewal_days} days`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Can’t record given"
                        tone={cant.length ? 'critical' : 'brand'}
                        ariaLabel={`View ${cant.length} staff who can’t record given doses`}
                        pressed={view === 'register' && status === 'cant'}
                        onClick={() => go('register', 'cant')}
                    >
                        <PageHeaderMeterBig>{cant.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {cant.length
                                ? 'Refused and withheld only'
                                : 'Everyone can'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Restricted"
                        tone={restricted.length ? 'warning' : 'brand'}
                        ariaLabel={`View ${restricted.length} restricted staff`}
                        pressed={view === 'register' && status === 'restricted'}
                        onClick={() => go('register', 'restricted')}
                    >
                        <PageHeaderMeterBig>
                            {restricted.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Rule: {RULE_WORD[policy.restricted_mode]}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Exemptions"
                        ariaLabel={`View exemptions, ${activeExemptions.length} active`}
                        pressed={view === 'exemptions'}
                        onClick={() => go('exemptions')}
                    >
                        <PageHeaderMeterBig>
                            {activeExemptions.length} active
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Longest {policy.longest_exemption_days} days
                            {policy.longest_exemption_reviewed
                                ? ''
                                : ' · not reviewed'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    {VIEWS.map(([key, label]) => {
                        const n =
                            key === 'renewals'
                                ? cant.length + due.length
                                : key === 'exemptions'
                                  ? activeExemptions.length
                                  : 0;
                        return (
                            <PageHeaderFilterButton
                                key={key}
                                icon={
                                    key === 'register'
                                        ? ClipboardList
                                        : key === 'renewals'
                                          ? Clock
                                          : ShieldCheck
                                }
                                active={view === key}
                                aria-pressed={view === key}
                                onClick={() => go(key)}
                            >
                                {label}
                                {n ? ` · ${n}` : ''}
                            </PageHeaderFilterButton>
                        );
                    })}
                    <PageHeaderFilterSelect
                        icon={Home}
                        label="All houses"
                        value={house}
                        onChange={setHouse}
                        options={[
                            { value: 'all', label: 'All houses' },
                            ...houses.map((h) => ({
                                value: String(h.id),
                                label: h.name,
                            })),
                        ]}
                    />
                    {view === 'register' ? (
                        <>
                            <PageHeaderFilterSelect
                                label="Any status"
                                value={status}
                                onChange={setStatus}
                                options={STATUS_FILTERS}
                            />
                            <PageHeaderFilterSelect
                                icon={Users}
                                label="Any role"
                                value={role}
                                onChange={setRole}
                                options={[
                                    { value: 'all', label: 'Any role' },
                                    ...roles,
                                ]}
                            />
                        </>
                    ) : null}
                    <PageHeaderFilterButton
                        icon={RefreshCw}
                        onClick={() =>
                            router.reload({
                                only: ['people', 'exemptions', 'loaded_at'],
                            })
                        }
                    >
                        Updated {formatTime(props.loaded_at)}
                    </PageHeaderFilterButton>
                </>
            }
            rail={
                <PageHeaderRail
                    items={[
                        {
                            key: 'eligibility',
                            label: 'Staff eligibility',
                            icon: UserCheck,
                            ...(cant.length
                                ? { count: cant.length, alert: true }
                                : {}),
                        },
                    ]}
                    value="eligibility"
                    onSelect={() => go('register')}
                    showFind={false}
                    ariaLabel="Safety & oversight views"
                />
            }
        />
    );

    const open = dialog?.kind === 'view' ? person(dialog.id) : null;
    const exemptPeople: ExemptionPerson[] = people.map((x) => ({
        id: x.id,
        name: x.name,
        role: x.role,
        house_id: x.house_id,
        house: x.house,
        status: STATUS_META[x.status][1],
        ok: !!x.can?.exempt,
        why: x.exemption
            ? 'already exempt'
            : x.id === props.me.id
              ? 'you can’t exempt yourself'
              : 'has a current assessment',
    }));

    let body;
    if (!people.length) {
        body = (
            <EmptyState
                icon={UserCheck}
                title="Nobody has been assessed yet"
                description="Until someone is assessed, nobody at your houses can record doses as given. Refused, withheld and away can always be recorded."
                action={
                    can.assess ? (
                        <Button
                            size="sm"
                            onClick={() =>
                                setDialog({ kind: 'assess', mode: 'new' })
                            }
                        >
                            <Plus />
                            New assessment
                        </Button>
                    ) : undefined
                }
            />
        );
    } else if (view === 'renewals') {
        body = (
            <Renewals
                people={scoped.filter(matches)}
                policy={policy}
                areas={areas}
                actions={actions}
                onOpen={(x) =>
                    setDialog(
                        x.can?.assess && x.st !== 'ack'
                            ? { kind: 'assess', id: x.id, mode: assessMode(x) }
                            : { kind: 'view', id: x.id },
                    )
                }
            />
        );
    } else if (view === 'exemptions') {
        body = (
            <Exemptions
                list={exemptions.filter(
                    (e) =>
                        inHouse(e.house_id) &&
                        (!q ||
                            e.person.toLowerCase().includes(q.toLowerCase())),
                )}
                policy={policy}
                canGrant={can.exempt}
                onGrant={() => setDialog({ kind: 'exempt' })}
                onView={(e) => setDialog({ kind: 'view', id: e.user_id })}
                onEnd={(e) => setDialog({ kind: 'end', exemption: e })}
                hasPerson={(id) => !!person(id)}
            />
        );
    } else {
        body = (
            <Register
                all={scoped}
                rows={scoped.filter((x) => {
                    if (!matches(x)) return false;
                    if (role !== 'all' && x.role !== role) return false;
                    const g = givenAbility(x, policy).v;
                    return status === 'alone'
                        ? g === 'yes' && x.st !== 'restricted'
                        : status === 'due'
                          ? x.status === 'due'
                          : status === 'cant'
                            ? g === 'no' && x.st !== 'restricted'
                            : status === 'restricted'
                              ? x.st === 'restricted'
                              : status === 'areas'
                                ? !!x.assessment &&
                                  areas.some((a) => areaRes(x, a.key) === 'no')
                                : true;
                })}
                policy={policy}
                areas={areas}
                actions={actions}
                onOpen={(x) => setDialog({ kind: 'view', id: x.id })}
                clear={() => {
                    setQ('');
                    setHouse('all');
                    setStatus('all');
                    setRole('all');
                }}
            />
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Medication', href: '/emar' },
                {
                    title: 'Safety & oversight',
                    href: '/emar/safety/eligibility',
                },
                {
                    title: 'Staff eligibility',
                    href: '/emar/safety/eligibility',
                },
            ]}
        >
            <Head title="Staff eligibility" />
            <div className="flex flex-col gap-5 p-6">
                {header}
                {body}
            </div>
            {open ? (
                <AssessmentView
                    x={open}
                    areas={areas}
                    policy={policy}
                    onClose={() => setDialog(null)}
                    onAssess={
                        open.can?.assess
                            ? () =>
                                  setDialog({
                                      kind: 'assess',
                                      id: open.id,
                                      mode: assessMode(open),
                                  })
                            : undefined
                    }
                />
            ) : null}
            {dialog?.kind === 'assess' ? (
                <AssessmentWizard
                    people={people}
                    clients={props.clients}
                    areas={areas}
                    policy={policy}
                    assessor={props.me.name}
                    who={dialog.id}
                    mode={dialog.mode}
                    onClose={() => setDialog(null)}
                    onView={(id) => setDialog({ kind: 'view', id })}
                />
            ) : null}
            {dialog?.kind === 'exempt' ? (
                <ExemptionWizard
                    people={exemptPeople}
                    longestDays={policy.longest_exemption_days}
                    limitReviewed={policy.longest_exemption_reviewed}
                    approver={props.me.name}
                    who={dialog.id}
                    onClose={() => setDialog(null)}
                />
            ) : null}
            {dialog?.kind === 'end' ? (
                <EndExemption
                    exemption={{
                        id: dialog.exemption.id,
                        person: dialog.exemption.person,
                        house: dialog.exemption.house ?? '',
                        until: dialog.exemption.until ?? '',
                    }}
                    onClose={() => setDialog(null)}
                />
            ) : null}
            <ConfirmDialog
                open={dialog?.kind === 'delete'}
                onClose={() => setDialog(null)}
                processing={busy}
                onConfirm={() => {
                    if (dialog?.kind !== 'delete' || !dialog.person.assessment)
                        return;
                    router.delete(
                        `/emar/competency/${dialog.person.assessment.id}`,
                        {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => {
                                setBusy(false);
                                setDialog(null);
                            },
                        },
                    );
                }}
                title={`Delete ${dialog?.kind === 'delete' ? firstName(dialog.person) : ''}’s assessment?`}
                description="The assessment is removed from the register and their eligibility is worked out again from what’s left. This can’t be undone. Recorded in the audit log."
                confirmText="Delete assessment"
                variant="destructive"
            />
            <ConfirmDialog
                open={dialog?.kind === 'reset'}
                onClose={() => setDialog(null)}
                processing={busy}
                onConfirm={() => {
                    if (dialog?.kind !== 'reset') return;
                    router.post(
                        `/emar/settings/witness-pins/${dialog.person.id}/reset`,
                        {},
                        {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => {
                                setBusy(false);
                                setDialog(null);
                            },
                        },
                    );
                }}
                title={`Reset ${dialog?.kind === 'reset' ? dialog.person.name : ''}’s witness PIN?`}
                description="They won’t be able to co-sign or witness until they choose a new PIN in Settings › Witness PIN. Nobody sees the old or the new PIN. The reset is recorded in the audit log."
                confirmText="Reset PIN"
                variant="destructive"
            />
        </AppLayout>
    );
}

const identity = (x: EligPerson) => ({
    mark: <PersonDisc name={x.name} size={30} />,
    name: x.name,
    subline: [x.role, x.house].filter(Boolean).join(' · '),
});

function Register({
    all,
    rows,
    policy,
    areas,
    actions,
    onOpen,
    clear,
}: {
    all: EligPerson[];
    rows: EligPerson[];
    policy: EligPolicy;
    areas: AreaMeta[];
    actions: (x: EligPerson) => MenuItem[];
    onOpen: (x: EligPerson) => void;
    clear: () => void;
}) {
    const menu = useEntityContextMenu<EligPerson>();
    return (
        <Section
            id="reg"
            title="Competency register"
            caption={`${rows.length} of ${all.length} people who record doses at your houses`}
        >
            {rows.length ? (
                <EntityTable<EligPerson>
                    rows={rows}
                    rowKey={(x) => x.id}
                    identityLabel="Person"
                    identityWidth="1.35fr"
                    minWidth={1080}
                    rowHeight="content"
                    identity={identity}
                    columns={[
                        {
                            key: 'comp',
                            label: 'Competency',
                            width: '1.35fr',
                            cell: (x) => (
                                <div className="space-y-1 py-2">
                                    <StatusChip x={x} />
                                    <p className="text-caption">
                                        {eligLine(x, areas)}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            key: 'can',
                            label: 'What they can do',
                            width: '1.3fr',
                            cell: (x) => {
                                const g = givenAbility(x, policy);
                                const cd = areaRes(x, 'controlled_drugs');
                                const cdBlocked =
                                    g.v !== 'no' &&
                                    (x.st === 'current' ||
                                        x.st === 'restricted') &&
                                    policy.area_mode !== 'off' &&
                                    (cd === 'no' ||
                                        (cd === 'unseen' &&
                                            policy.area_mode ===
                                                'failed_or_not_seen'));
                                return (
                                    <div className="space-y-1 py-2 text-[12.5px]">
                                        <p>
                                            {g.v === 'no'
                                                ? x.st === 'restricted'
                                                    ? 'Can’t sign given doses — restricted'
                                                    : 'Refused, withheld and away only'
                                                : g.v === 'part'
                                                  ? x.st === 'restricted'
                                                      ? 'Given doses with a co-signer'
                                                      : 'Given doses under an exemption'
                                                  : 'Given doses'}
                                        </p>
                                        {cdBlocked ? (
                                            <p className="text-muted-foreground">
                                                Not controlled doses
                                            </p>
                                        ) : null}
                                    </div>
                                );
                            },
                        },
                        {
                            key: 'areas',
                            label: 'Areas not passed',
                            width: '1.4fr',
                            cell: (x) => {
                                if (!x.assessment)
                                    return (
                                        <span className="text-caption">
                                            No assessment
                                        </span>
                                    );
                                const fail = areas.filter(
                                    (a) => areaRes(x, a.key) === 'no',
                                );
                                const unseen = areas.filter(
                                    (a) => areaRes(x, a.key) === 'unseen',
                                );
                                return !fail.length && !unseen.length ? (
                                    <span className="text-caption">
                                        All 12 passed
                                    </span>
                                ) : (
                                    <div className="flex flex-wrap gap-1 py-2">
                                        {fail.map((a) => (
                                            <StatusBadge
                                                key={a.key}
                                                variant="critical"
                                                size="sm"
                                            >
                                                {a.label}
                                                {a.core ? ' (core)' : ''}
                                            </StatusBadge>
                                        ))}
                                        {unseen.map((a) => (
                                            <StatusBadge
                                                key={a.key}
                                                variant="neutral"
                                                size="sm"
                                            >
                                                {a.label} — not assessed
                                            </StatusBadge>
                                        ))}
                                    </div>
                                );
                            },
                        },
                        {
                            key: 'pin',
                            label: 'Witness PIN',
                            width: '1fr',
                            cell: (x) => (
                                <StatusBadge
                                    variant={PIN_VARIANT[x.pin]}
                                    size="sm"
                                >
                                    {PIN_LABEL[x.pin]}
                                </StatusBadge>
                            ),
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={onOpen}
                    onRowContextMenu={menu.open}
                />
            ) : (
                <EmptyState
                    icon={Users}
                    title="Nobody matches"
                    description="Clear the filters or the search."
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
                icon={UserCheck}
                title={(x) => x.name}
                items={actions}
            />
            <Note>
                “Due for renewal” starts {policy.renewal_days} days before the
                end date. Refused, withheld and away can always be recorded,
                whatever the status.
            </Note>
        </Section>
    );
}

function Renewals({
    people,
    policy,
    areas,
    actions,
    onOpen,
}: {
    people: EligPerson[];
    policy: EligPolicy;
    areas: AreaMeta[];
    actions: (x: EligPerson) => MenuItem[];
    onOpen: (x: EligPerson) => void;
}) {
    const menu = useEntityContextMenu<EligPerson>();
    const now = people.filter(
        (x) => givenAbility(x, policy).v === 'no' && x.st !== 'restricted',
    );
    const due = people
        .filter((x) => x.status === 'due')
        .sort((a, b) => (a.days ?? 0) - (b.days ?? 0));
    const later = people
        .filter((x) => x.status === 'current')
        .sort((a, b) => (a.days ?? 0) - (b.days ?? 0));
    const table = (list: EligPerson[]) => (
        <EntityTable<EligPerson>
            rows={list}
            rowKey={(x) => x.id}
            identityLabel="Person"
            identityWidth="1.3fr"
            minWidth={900}
            rowHeight="content"
            identity={identity}
            columns={[
                {
                    key: 'st',
                    label: 'Status',
                    width: '1.4fr',
                    cell: (x) => (
                        <div className="space-y-1 py-2">
                            <StatusChip x={x} />
                            <p className="text-caption">{eligLine(x, areas)}</p>
                        </div>
                    ),
                },
                {
                    key: 'roster',
                    label: 'Rostering',
                    width: '1.6fr',
                    cell: (x) => (
                        <span className="py-2 text-[12.5px]">
                            {x.status === 'due'
                                ? 'Rostering shows a warning the rosterer can override'
                                : 'Rostering blocks shifts that need medication cover until this is fixed'}
                        </span>
                    ),
                },
                {
                    key: 'next',
                    label: 'Next step',
                    width: '1fr',
                    cell: (x) => (
                        <span className="text-[12.5px]">{nextStep(x)}</span>
                    ),
                },
            ]}
            actionsFor={actions}
            onOpen={onOpen}
            onRowContextMenu={menu.open}
        />
    );
    return (
        <div className="space-y-5">
            <Section
                id="rn-now"
                title="Can’t record given doses now"
                caption={`${now.length} people · refused, withheld and away still recordable`}
            >
                {now.length ? (
                    table(now)
                ) : (
                    <EmptyState
                        variant="inline"
                        icon={UserCheck}
                        title="Everyone at your houses can record given doses."
                    />
                )}
            </Section>
            <Section
                id="rn-due"
                title={`Due within ${policy.renewal_days} days`}
                caption={`${due.length} people · still current until the end date`}
            >
                {due.length ? (
                    table(due)
                ) : (
                    <EmptyState
                        variant="inline"
                        icon={Clock}
                        title={`No renewals due within ${policy.renewal_days} days.`}
                    />
                )}
            </Section>
            <Section
                id="rn-later"
                title="Later"
                caption={`${later.length} people current beyond ${policy.renewal_days} days${later[0] ? ` · next: ${later[0].name}, ${day(later[0].until)}` : ''}`}
            >
                <span />
            </Section>
            <RowMenu
                ctx={menu.ctx}
                close={menu.close}
                icon={UserCheck}
                title={(x) => x.name}
                items={actions}
            />
        </div>
    );
}

function Exemptions({
    list,
    policy,
    canGrant,
    onGrant,
    onView,
    onEnd,
    hasPerson,
}: {
    list: EligExemption[];
    policy: EligPolicy;
    canGrant: boolean;
    onGrant: () => void;
    onView: (e: EligExemption) => void;
    onEnd: (e: EligExemption) => void;
    hasPerson: (id: number) => boolean;
}) {
    const menu = useEntityContextMenu<EligExemption>();
    const actions = (e: EligExemption): MenuItem[] =>
        compactMenu([
            hasPerson(e.user_id) && {
                label: 'View the person’s assessment',
                icon: Eye,
                onClick: () => onView(e),
            },
            e.can_end && {
                label: 'End early',
                icon: XCircle,
                danger: true,
                onClick: () => onEnd(e),
            },
        ]);
    const badge: Record<EligExemption['status'], ['info' | 'neutral', string]> =
        {
            active: ['info', 'Active'],
            ended: ['neutral', 'Ended'],
            revoked: ['neutral', 'Ended early'],
        };
    return (
        <Section
            id="xl"
            title="Exemptions"
            caption={`${list.length} shown · longest ${policy.longest_exemption_days} days`}
            right={
                canGrant ? (
                    <Button size="sm" onClick={onGrant}>
                        <Plus />
                        Grant an exemption
                    </Button>
                ) : null
            }
        >
            <p className="text-subtle">
                One house, a reason and an end date within{' '}
                {policy.longest_exemption_days} days (
                {policy.longest_exemption_reviewed
                    ? 'set'
                    : 'default — not yet reviewed'}
                ).
                <Button variant="link" className="ml-2" asChild>
                    <Link href="/emar/settings#staff/exemptions">
                        Longest exemption setting
                        <ArrowUpRight className="size-4" />
                    </Link>
                </Button>
            </p>
            {list.length ? (
                <EntityTable<EligExemption>
                    rows={list}
                    rowKey={(e) => e.id}
                    identityLabel="Person"
                    identityWidth="1.2fr"
                    minWidth={1000}
                    rowHeight="content"
                    identity={(e) => ({
                        mark: <PersonDisc name={e.person} size={30} />,
                        name: e.person,
                        subline: e.role ?? undefined,
                    })}
                    columns={[
                        {
                            key: 'where',
                            label: 'Where',
                            width: '0.9fr',
                            cell: (e) => (
                                <EntityChip icon={Home}>{e.house}</EntityChip>
                            ),
                        },
                        {
                            key: 'why',
                            label: 'Why',
                            width: '1.6fr',
                            cell: (e) => (
                                <span className="py-2 text-[12.5px]">
                                    {e.reason}
                                </span>
                            ),
                        },
                        {
                            key: 'dates',
                            label: 'From – until',
                            width: '1.1fr',
                            cell: (e) => (
                                <span className="text-[13px]">
                                    {day(e.from)} – {day(e.until)}
                                </span>
                            ),
                        },
                        {
                            key: 'by',
                            label: 'Approved by',
                            width: '1fr',
                            cell: (e) => (
                                <div>
                                    <div className="text-[13px]">
                                        {e.by ?? '—'}
                                    </div>
                                    <div className="text-caption">
                                        {e.at ? formatDateTime(e.at) : ''}
                                    </div>
                                </div>
                            ),
                        },
                        {
                            key: 'st',
                            label: 'Status',
                            width: '1fr',
                            cell: (e) => (
                                <div className="space-y-1 py-2">
                                    <StatusBadge
                                        variant={badge[e.status][0]}
                                        size="sm"
                                    >
                                        {badge[e.status][1]}
                                    </StatusBadge>
                                    {e.status === 'revoked' && e.ended_at ? (
                                        <p className="text-caption">
                                            Ended {formatDateTime(e.ended_at)}
                                            {e.ended_by
                                                ? ` by ${e.ended_by}`
                                                : ''}
                                            {e.end_reason
                                                ? `: “${e.end_reason}”`
                                                : ''}
                                        </p>
                                    ) : null}
                                </div>
                            ),
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={(e) =>
                        hasPerson(e.user_id) ? onView(e) : undefined
                    }
                    onRowContextMenu={menu.open}
                    mutedFor={(e) => e.status !== 'active'}
                />
            ) : (
                <EmptyState
                    icon={ShieldCheck}
                    title="No exemptions"
                    description="Nobody at your houses is exempt. Exemptions show here with their end date, and end by themselves."
                    action={
                        canGrant ? (
                            <Button size="sm" onClick={onGrant}>
                                <Plus />
                                Grant an exemption
                            </Button>
                        ) : undefined
                    }
                />
            )}
            <RowMenu
                ctx={menu.ctx}
                close={menu.close}
                icon={ShieldCheck}
                title={(e) => `${e.person} — exemption`}
                items={actions}
            />
        </Section>
    );
}
