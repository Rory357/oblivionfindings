import { EntityStatusChip } from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { PageLayout } from '@/components/page';
import { PageHeaderPrimaryButton } from '@/components/page/page-header';
import {
    ShiftContextMenu,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { AddRepresentativeWizard } from '@/components/worker-participation/add-representative-wizard';
import { ConsultationDetailDialog } from '@/components/worker-participation/consultation-detail-dialog';
import { MeetingDetailDialog } from '@/components/worker-participation/meeting-detail-dialog';
import { NewConsultationWizard } from '@/components/worker-participation/new-consultation-wizard';
import { ParticipationOwnershipChip } from '@/components/worker-participation/ownership-notice';
import { RepresentativeDetailDialog } from '@/components/worker-participation/representative-detail-dialog';
import { ScheduleMeetingWizard } from '@/components/worker-participation/schedule-meeting-wizard';
import {
    CONSULT_ORDER,
    CONSULT_STATUS,
    MEETING_STATUS,
    REP_STATUS,
    type ConsultationRow,
    type MeetingRow,
    type RepRow,
    type WpDetailAction,
} from '@/components/worker-participation/shared';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import {
    ParticipationCards,
    ParticipationRowMenu,
    participationMenu,
    type ParticipationActions,
} from './participation-cards';
import {
    ParticipationHeader,
    type ParticipationFilters,
    type ParticipationHero,
} from './participation-header';

import {
    FlagBadge,
    RegisterTableHeader,
    TONE_BG,
    TONE_DOT,
    entityTone,
    initials,
    titleCase,
    type Tone,
} from '@/pages/health-safety/components/register-row-kit';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Calendar,
    CheckCircle2,
    ClipboardCheck,
    Copy,
    Download,
    Eye,
    FileCheck2,
    FileText,
    GraduationCap,
    Info,
    MapPin,
    MessageSquare,
    MousePointer2,
    Pencil,
    Plus,
    Slash,
    Upload,
    UserCog,
    UserPlus,
    Users,
    Wrench,
    XCircle,
} from 'lucide-react';
import {
    useState,
    type ComponentType,
    type MouseEvent as ReactMouseEvent,
    type ReactNode,
} from 'react';
import { ParticipationViewerBoundary } from './participation-viewer-boundary';

/* ------------------------------------------------------------------ */
/*  Types (match WorkerParticipationController@index)                  */
/* ------------------------------------------------------------------ */

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
};
type Filters = ParticipationFilters;

type Props = {
    participation_viewer_id: number;
    filters: Filters;
    tab: 'representatives' | 'meetings' | 'consultations';
    tabCounts: Record<string, number>;
    rows: Paginated<RepRow | MeetingRow | ConsultationRow>;
    hero: ParticipationHero;
    detail: {
        kind: 'representative' | 'meeting' | 'consultation';
        data: any;
    } | null;
    can: { manage: boolean };
    sites: { id: number; name: string }[];
    staff: { id: number; name: string }[];
    committees: {
        id: number;
        name: string;
        site_id: number | null;
        meeting_frequency: string;
        meetings_count: number;
    }[];
};

const BASE = '/health-safety/worker-participation';

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function WorkerParticipationIndex(props: Props) {
    const { auth } = usePage<SharedData>().props;
    return (
        <ParticipationViewerBoundary
            currentActorId={auth?.user?.id}
            sourceActorId={props.participation_viewer_id}
            manage={props.can?.manage === true}
        >
            <ParticipationWorkspace {...props} />
        </ParticipationViewerBoundary>
    );
}

function ParticipationWorkspace({
    filters,
    tab,
    tabCounts,
    rows,
    hero,
    detail,
    can,
    sites,
    staff,
    committees,
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [recordCtx, setRecordCtx] = useState<{
        x: number;
        y: number;
        title: string;
        items: MenuItem[];
    } | null>(null);
    const [wizard, setWizard] = useState<
        'rep' | 'meeting' | 'consultation' | null
    >(null);
    const [newOpen, setNewOpen] = useState(false);
    // Tracks which sub-action a detail dialog should open straight onto (right-click → action).
    const [pendingAction, setPendingAction] = useState<WpDetailAction | null>(
        null,
    );

    const go = (next: Partial<Filters>) =>
        router.get(
            BASE,
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const setTab = (id: string) =>
        router.get(
            BASE,
            { ...filters, tab: id, status: null, focus: null },
            { preserveState: true, preserveScroll: true },
        );
    const openDetail = (
        param: 'representative' | 'meeting' | 'consultation',
        id: number,
        action: WpDetailAction | null = null,
    ) => {
        setPendingAction(action);
        router.get(
            BASE,
            { ...filters, [param]: id },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    };
    const closeDetail = () => {
        setPendingAction(null);
        router.get(
            BASE,
            { ...filters },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    };
    const clearFilters = () =>
        router.get(
            BASE,
            { tab },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const TABS: RosterTabItem[] = [
        {
            id: 'representatives',
            label: 'Representatives',
            icon: Users,
            tone: 'info',
            badge: tabCounts.representatives || undefined,
        },
        {
            id: 'meetings',
            label: 'Committee meetings',
            icon: Calendar,
            tone: 'info',
            badge: tabCounts.meetings || undefined,
        },
        {
            id: 'consultations',
            label: 'Consultations',
            icon: MessageSquare,
            tone: 'warning',
            badge: tabCounts.consultations || undefined,
        },
    ];

    const PERIOD = [
        { key: 'week', label: 'This week' },
        { key: '30d', label: '30 days' },
        { key: 'quarter', label: 'Quarter' },
        { key: 'year', label: 'Year' },
    ];
    const STATUS_OPTS: Record<string, [string, string][]> = {
        representatives: [
            ['active', 'Active'],
            ['inactive', 'Inactive'],
            ['resigned', 'Resigned'],
        ],
        meetings: [
            ['scheduled', 'Scheduled'],
            ['completed', 'Completed'],
            ['cancelled', 'Cancelled'],
        ],
        consultations: [
            ['open', 'Open'],
            ['feedback_received', 'Feedback received'],
            ['actioned', 'Actioned'],
            ['closed', 'Closed'],
        ],
    };

    /* ---- shared actionsFor (powers right-click AND the row menu) ---- */
    const actionsFor = (kind: string, row: any): ShiftCtxItem[] => {
        if (kind === 'representative') {
            const active = row.status === 'active';
            const items: ShiftCtxItem[] = [
                {
                    icon: <Eye className="h-3.5 w-3.5" />,
                    label: 'View representative',
                    sub: row.user?.name,
                    tone: 'primary',
                    onClick: () => openDetail('representative', row.id),
                },
            ];
            if (can.manage) {
                items.push(
                    {
                        icon: <Pencil className="h-3.5 w-3.5" />,
                        label: 'Edit details',
                        onClick: () =>
                            openDetail('representative', row.id, 'edit'),
                    },
                    {
                        icon: <GraduationCap className="h-3.5 w-3.5" />,
                        label: 'Record training days',
                        onClick: () =>
                            openDetail('representative', row.id, 'training'),
                    },
                    { sep: true },
                    active
                        ? {
                              icon: <Slash className="h-3.5 w-3.5" />,
                              label: 'Mark stood-down',
                              tone: 'critical',
                              onClick: () =>
                                  router.put(
                                      `${BASE}/representatives/${row.id}`,
                                      { status: 'inactive' },
                                      { preserveScroll: true },
                                  ),
                          }
                        : {
                              icon: <CheckCircle2 className="h-3.5 w-3.5" />,
                              label: 'Mark active',
                              tone: 'primary',
                              onClick: () =>
                                  router.put(
                                      `${BASE}/representatives/${row.id}`,
                                      { status: 'active' },
                                      { preserveScroll: true },
                                  ),
                          },
                );
            }
            items.push(
                { sep: true },
                {
                    icon: <Copy className="h-3.5 w-3.5" />,
                    label: 'Copy link',
                    onClick: () =>
                        navigator.clipboard?.writeText(
                            `${location.origin}${BASE}?tab=representatives&representative=${row.id}`,
                        ),
                },
            );
            return items;
        }
        if (kind === 'meeting') {
            const sched = row.status === 'scheduled';
            const items: ShiftCtxItem[] = [
                {
                    icon: <Eye className="h-3.5 w-3.5" />,
                    label: 'View meeting',
                    sub: row.committee?.name,
                    tone: 'primary',
                    onClick: () => openDetail('meeting', row.id),
                },
            ];
            if (can.manage && row.status !== 'cancelled') {
                items.push({
                    icon: <Pencil className="h-3.5 w-3.5" />,
                    label: 'Edit meeting',
                    onClick: () => openDetail('meeting', row.id, 'edit'),
                });
            }
            if (can.manage && sched) {
                items.push(
                    {
                        icon: <UserPlus className="h-3.5 w-3.5" />,
                        label: 'Add attendees',
                        onClick: () =>
                            openDetail('meeting', row.id, 'attendees'),
                    },
                    {
                        icon: <ClipboardCheck className="h-3.5 w-3.5" />,
                        label: 'Complete meeting',
                        sub: 'Minutes + action items',
                        onClick: () =>
                            openDetail('meeting', row.id, 'complete'),
                    },
                    {
                        icon: <XCircle className="h-3.5 w-3.5" />,
                        label: 'Cancel meeting',
                        tone: 'critical',
                        onClick: () =>
                            router.put(
                                `${BASE}/meetings/${row.id}/cancel`,
                                {},
                                { preserveScroll: true },
                            ),
                    },
                );
            }
            items.push({ sep: true });
            if (row.minutes_document_path) {
                items.push({
                    icon: <Download className="h-3.5 w-3.5" />,
                    label: 'Download minutes',
                    onClick: () =>
                        window.open(
                            `${BASE}/meetings/${row.id}/minutes/download`,
                        ),
                });
            } else if (can.manage) {
                items.push({
                    icon: <Upload className="h-3.5 w-3.5" />,
                    label: 'Upload minutes',
                    onClick: () => openDetail('meeting', row.id, 'minutes'),
                });
            }
            return items;
        }
        // consultation
        const closed = row.status === 'closed';
        const items: ShiftCtxItem[] = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View consultation',
                sub: row.title,
                tone: 'primary',
                onClick: () => openDetail('consultation', row.id),
            },
        ];
        if (can.manage && !closed) {
            items.push(
                {
                    icon: <Pencil className="h-3.5 w-3.5" />,
                    label: 'Edit details',
                    onClick: () => openDetail('consultation', row.id, 'edit'),
                },
                {
                    icon: <MessageSquare className="h-3.5 w-3.5" />,
                    label: 'Record feedback',
                    onClick: () =>
                        openDetail('consultation', row.id, 'feedback'),
                },
                {
                    icon: <FileCheck2 className="h-3.5 w-3.5" />,
                    label: 'Record outcome',
                    onClick: () =>
                        openDetail('consultation', row.id, 'outcome'),
                },
            );
        }
        if (can.manage) {
            items.push(
                { sep: true },
                {
                    icon: <Upload className="h-3.5 w-3.5" />,
                    label: 'Upload document',
                    onClick: () => openDetail('consultation', row.id, 'upload'),
                },
            );
            if (!closed) {
                items.push({
                    icon: <XCircle className="h-3.5 w-3.5" />,
                    label: 'Close consultation',
                    tone: 'critical',
                    onClick: () => openDetail('consultation', row.id, 'close'),
                });
            }
        }
        return items;
    };

    const openRowCtx = (
        e: ReactMouseEvent,
        kind: string,
        row: any,
        tag: string,
        meta: string,
    ) => {
        e.preventDefault();
        setCtx(null);
        const bounds = e.currentTarget.getBoundingClientRect();
        const keyboard = e.clientX === 0 && e.clientY === 0;
        setRecordCtx({
            x: keyboard ? bounds.left + 12 : e.clientX,
            y: keyboard ? bounds.top + 12 : e.clientY,
            title: `${meta || titleCase(kind)} · ${tag}`,
            items: participationMenu(actionsFor(kind, row)),
        });
    };

    const openHeroCtx = (e: ReactMouseEvent) => {
        if (!can.manage) return;
        e.preventDefault();
        setRecordCtx(null);
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'NEW',
            meta: 'Quick actions',
            items: [
                {
                    icon: <UserPlus className="h-3.5 w-3.5" />,
                    label: 'Add representative',
                    tone: 'primary',
                    onClick: () => setWizard('rep'),
                },
                {
                    icon: <Calendar className="h-3.5 w-3.5" />,
                    label: 'Schedule meeting',
                    onClick: () => setWizard('meeting'),
                },
                {
                    icon: <MessageSquare className="h-3.5 w-3.5" />,
                    label: 'New consultation',
                    onClick: () => setWizard('consultation'),
                },
                { sep: true },
                {
                    icon: <Download className="h-3.5 w-3.5" />,
                    label: 'Export board report',
                    onClick: () =>
                        window.open(
                            `${BASE}/export${filters.site_id ? `?site_id=${filters.site_id}` : ''}`,
                        ),
                },
            ],
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Worker Participation', href: BASE },
            ]}
        >
            <Head title="Worker Participation" />
            <PageLayout
                hero={
                    <div onContextMenu={openHeroCtx}>
                        <ParticipationHeader
                            meetingCount={tabCounts.meetings}
                            filters={filters}
                            hero={hero}
                            sites={sites}
                            tabs={TABS}
                            periods={PERIOD}
                            statuses={STATUS_OPTS[tab]}
                            onFilters={go}
                            onTab={setTab}
                            onClear={clearFilters}
                            actions={
                                can.manage ? (
                                    <Popover
                                        open={newOpen}
                                        onOpenChange={setNewOpen}
                                    >
                                        <PopoverTrigger asChild>
                                            <PageHeaderPrimaryButton
                                                icon={Plus}
                                            >
                                                New
                                            </PageHeaderPrimaryButton>
                                        </PopoverTrigger>
                                        <PopoverContent
                                            align="end"
                                            className="w-64 p-1.5"
                                        >
                                            {(
                                                [
                                                    [
                                                        'rep',
                                                        UserPlus,
                                                        'Add representative',
                                                        'Elect or appoint a rep',
                                                    ],
                                                    [
                                                        'meeting',
                                                        Calendar,
                                                        'Schedule meeting',
                                                        'Committee meeting',
                                                    ],
                                                    [
                                                        'consultation',
                                                        MessageSquare,
                                                        'New consultation',
                                                        'Consult kaimahi on a change',
                                                    ],
                                                ] as const
                                            ).map(([k, Icon, t, s]) => (
                                                // eslint-disable-next-line no-restricted-syntax -- two-line menu item (icon + title + subtitle) in the New popover
                                                <button
                                                    key={k}
                                                    type="button"
                                                    onClick={() => {
                                                        setNewOpen(false);
                                                        setWizard(
                                                            k as
                                                                | 'rep'
                                                                | 'meeting'
                                                                | 'consultation',
                                                        );
                                                    }}
                                                    className="frontline-tap frontline-focus flex w-full items-start gap-2.5 rounded-md p-2.5 text-left transition-colors hover:bg-muted"
                                                >
                                                    <Icon className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                                    <span>
                                                        <span className="block text-sm font-medium">
                                                            {t}
                                                        </span>
                                                        <span className="block text-xs text-muted-foreground">
                                                            {s}
                                                        </span>
                                                    </span>
                                                </button>
                                            ))}
                                        </PopoverContent>
                                    </Popover>
                                ) : null
                            }
                        />
                    </div>
                }
            >
                <Card>
                    <CardContent className="p-0">
                        <RegisterTableHeader
                            icon={
                                tab === 'representatives'
                                    ? Users
                                    : tab === 'meetings'
                                      ? Calendar
                                      : MessageSquare
                            }
                            title={
                                tab === 'representatives'
                                    ? 'H&S Representatives'
                                    : tab === 'meetings'
                                      ? 'Committee Meetings'
                                      : 'Worker Consultations'
                            }
                            subtitle={
                                tab === 'representatives'
                                    ? 'elected & appointed reps'
                                    : tab === 'meetings'
                                      ? 'scheduled & completed'
                                      : 'feedback → outcome → close'
                            }
                            hint="Use the actions menu, or right-click a record"
                            hintIcon={MousePointer2}
                        />
                        {tab === 'representatives' && (
                            <RepTable
                                rows={rows.data as RepRow[]}
                                onOpen={(id) =>
                                    openDetail('representative', id)
                                }
                                onCtx={openRowCtx}
                                actionsFor={actionsFor}
                            />
                        )}
                        {tab === 'meetings' && (
                            <MeetingTable
                                rows={rows.data as MeetingRow[]}
                                onOpen={(id) => openDetail('meeting', id)}
                                onCtx={openRowCtx}
                                actionsFor={actionsFor}
                            />
                        )}
                        {tab === 'consultations' && (
                            <ConsultationTable
                                rows={rows.data as ConsultationRow[]}
                                onOpen={(id) => openDetail('consultation', id)}
                                onCtx={openRowCtx}
                                actionsFor={actionsFor}
                            />
                        )}
                    </CardContent>
                </Card>

                {rows.last_page > 1 ? (
                    <LaravelPagination links={rows.links} />
                ) : null}
            </PageLayout>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}
            {recordCtx ? (
                <EntityContextMenu
                    {...recordCtx}
                    onClose={() => setRecordCtx(null)}
                />
            ) : null}

            {detail?.kind === 'representative' ? (
                <RepresentativeDetailDialog
                    key={detail.data.id}
                    detail={detail.data}
                    open
                    onClose={closeDetail}
                    staff={staff}
                    sites={sites}
                    can={can}
                    initialAction={pendingAction}
                />
            ) : null}
            {detail?.kind === 'meeting' ? (
                <MeetingDetailDialog
                    key={detail.data.id}
                    detail={detail.data}
                    open
                    onClose={closeDetail}
                    staff={staff}
                    can={can}
                    initialAction={pendingAction}
                />
            ) : null}
            {detail?.kind === 'consultation' ? (
                <ConsultationDetailDialog
                    key={detail.data.id}
                    detail={detail.data}
                    open
                    onClose={closeDetail}
                    sites={sites}
                    staff={staff}
                    can={can}
                    initialAction={pendingAction}
                />
            ) : null}

            {wizard === 'rep' ? (
                <AddRepresentativeWizard
                    open
                    sites={sites}
                    staff={staff}
                    onClose={() => setWizard(null)}
                />
            ) : null}
            {wizard === 'meeting' ? (
                <ScheduleMeetingWizard
                    open
                    committees={committees}
                    sites={sites}
                    staff={staff}
                    onClose={() => setWizard(null)}
                />
            ) : null}
            {wizard === 'consultation' ? (
                <NewConsultationWizard
                    open
                    sites={sites}
                    staff={staff}
                    onClose={() => setWizard(null)}
                />
            ) : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Tables                                                             */
/* ------------------------------------------------------------------ */

function Row({
    children,
    onClick,
    onContextMenu,
}: {
    children: ReactNode;
    onClick: () => void;
    onContextMenu: (e: ReactMouseEvent) => void;
}) {
    return (
        <tr
            tabIndex={0}
            onClick={onClick}
            onContextMenu={onContextMenu}
            onKeyDown={(e) => {
                if (e.target !== e.currentTarget) return;
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onClick();
                }
            }}
            className="cursor-pointer transition-colors hover:bg-muted/45 focus:bg-muted/45 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
        >
            {children}
        </tr>
    );
}

const TH = ({ children }: { children: ReactNode }) => (
    <th className="px-4 py-2.5">{children}</th>
);
const HeadRow = ({ cols }: { cols: string[] }) => (
    <thead>
        <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
            {cols.map((c) => (
                <TH key={c}>{c}</TH>
            ))}
        </tr>
    </thead>
);
const Pill = ({ tone, children }: { tone: Tone; children: ReactNode }) => (
    <EntityStatusChip variant={tone} icon={Info}>
        {children}
    </EntityStatusChip>
);

function RepTable({
    rows,
    onOpen,
    onCtx,
    actionsFor,
}: {
    rows: RepRow[];
    actionsFor: ParticipationActions;
    onOpen: (id: number) => void;
    onCtx: (
        e: ReactMouseEvent,
        kind: string,
        row: RepRow,
        tag: string,
        meta: string,
    ) => void;
}) {
    if (!rows.length) return <Empty icon={Users} label="No representatives" />;
    return (
        <>
            <ParticipationCards
                kind="representative"
                rows={rows}
                actionsFor={actionsFor}
                onOpen={onOpen}
                onContextMenu={onCtx}
            />
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full text-sm">
                    <HeadRow
                        cols={[
                            'Representative',
                            'Site',
                            'Work group',
                            'Election',
                            'Training',
                            'Status',
                            'Actions',
                        ]}
                    />
                    <tbody className="divide-y">
                        {rows.map((r) => {
                            const name = r.user?.name ?? '—';
                            const recorded = Number.isFinite(
                                r.training_days_completed,
                            );
                            const below =
                                recorded && r.training_days_completed < 2;
                            return (
                                <Row
                                    key={r.id}
                                    onClick={() => onOpen(r.id)}
                                    onContextMenu={(e) =>
                                        onCtx(
                                            e,
                                            'representative',
                                            r,
                                            r.status.toUpperCase(),
                                            `${name} · ${r.site?.name ?? ''}`,
                                        )
                                    }
                                >
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2.5">
                                            <span
                                                className={`flex h-8 w-8 items-center justify-center rounded-full text-[11px] font-semibold ${entityTone(r.id)}`}
                                            >
                                                {initials(name)}
                                            </span>
                                            <span className="font-medium">
                                                {name}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                            <MapPin className="h-3.5 w-3.5" />
                                            {r.site_ownership ===
                                            'unconfirmed' ? (
                                                <ParticipationOwnershipChip
                                                    state={r.site_ownership}
                                                />
                                            ) : (
                                                (r.site?.name ?? '—')
                                            )}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {r.work_group ?? '—'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className="font-medium">
                                            {titleCase(
                                                r.election_method ?? '—',
                                            )}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDateOnly(r.elected_at)}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3">
                                        <FlagBadge
                                            icon={GraduationCap}
                                            tone={below ? 'warning' : 'neutral'}
                                            title="Recorded representative training"
                                        >
                                            {recorded
                                                ? `${r.training_days_completed} ${r.training_days_completed === 1 ? 'day' : 'days'}`
                                                : 'Not recorded'}
                                        </FlagBadge>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Pill
                                            tone={
                                                REP_STATUS[r.status] ??
                                                'neutral'
                                            }
                                        >
                                            {titleCase(r.status)}
                                        </Pill>
                                    </td>
                                    <td className="px-4 py-3">
                                        <ParticipationRowMenu
                                            kind="representative"
                                            row={r}
                                            name={name}
                                            actionsFor={actionsFor}
                                        />
                                    </td>
                                </Row>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </>
    );
}

function MeetingTable({
    rows,
    onOpen,
    onCtx,
    actionsFor,
}: {
    rows: MeetingRow[];
    actionsFor: ParticipationActions;
    onOpen: (id: number) => void;
    onCtx: (
        e: ReactMouseEvent,
        kind: string,
        row: MeetingRow,
        tag: string,
        meta: string,
    ) => void;
}) {
    if (!rows.length) return <Empty icon={Calendar} label="No meetings" />;
    return (
        <>
            <ParticipationCards
                kind="meeting"
                rows={rows}
                actionsFor={actionsFor}
                onOpen={onOpen}
                onContextMenu={onCtx}
            />
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full text-sm">
                    <HeadRow
                        cols={[
                            'Committee',
                            'Date',
                            'Status',
                            'Attendees',
                            'Flags',
                            'Actions',
                        ]}
                    />
                    <tbody className="divide-y">
                        {rows.map((m) => (
                            <Row
                                key={m.id}
                                onClick={() => onOpen(m.id)}
                                onContextMenu={(e) =>
                                    onCtx(
                                        e,
                                        'meeting',
                                        m,
                                        m.status.toUpperCase(),
                                        m.committee?.name ?? '',
                                    )
                                }
                            >
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-2.5">
                                        <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary">
                                            <Users className="h-4 w-4" />
                                        </span>
                                        <span className="font-medium">
                                            {m.committee?.name ?? '—'}
                                        </span>
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                        <Calendar className="h-3.5 w-3.5" />
                                        {formatDateTimeLong(m.scheduled_at)}
                                    </span>
                                </td>
                                <td className="px-4 py-3">
                                    <Pill
                                        tone={
                                            MEETING_STATUS[m.status] ??
                                            'neutral'
                                        }
                                    >
                                        {titleCase(m.status)}
                                    </Pill>
                                </td>
                                <td className="px-4 py-3">
                                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                        <UserCog className="h-3.5 w-3.5" />
                                        {Number.isFinite(m.attendees_count)
                                            ? `${m.attendees_count} listed`
                                            : 'Not recorded'}
                                    </span>
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex flex-wrap gap-1.5">
                                        <ParticipationOwnershipChip
                                            state={m.site_ownership}
                                        />
                                        {m.actions_due_count > 0 ? (
                                            <FlagBadge
                                                icon={Wrench}
                                                tone="warning"
                                                title="Unfinished action items"
                                            >
                                                {m.actions_due_count}{' '}
                                                outstanding
                                            </FlagBadge>
                                        ) : null}
                                        {m.status === 'completed' &&
                                        !m.minutes_document_path ? (
                                            <FlagBadge
                                                icon={FileText}
                                                tone="warning"
                                                title="Minutes outstanding"
                                            >
                                                No minutes
                                            </FlagBadge>
                                        ) : null}
                                        {m.minutes_document_path ? (
                                            <FlagBadge
                                                icon={FileCheck2}
                                                tone="success"
                                                title="Minutes filed"
                                            >
                                                Minutes
                                            </FlagBadge>
                                        ) : null}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    <ParticipationRowMenu
                                        kind="meeting"
                                        row={m}
                                        name={
                                            m.committee?.name ??
                                            'Committee not recorded'
                                        }
                                        actionsFor={actionsFor}
                                    />
                                </td>
                            </Row>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}

function ConsultationTable({
    rows,
    onOpen,
    onCtx,
    actionsFor,
}: {
    rows: ConsultationRow[];
    actionsFor: ParticipationActions;
    onOpen: (id: number) => void;
    onCtx: (
        e: ReactMouseEvent,
        kind: string,
        row: ConsultationRow,
        tag: string,
        meta: string,
    ) => void;
}) {
    if (!rows.length)
        return <Empty icon={MessageSquare} label="No consultations" />;
    return (
        <>
            <ParticipationCards
                kind="consultation"
                rows={rows}
                actionsFor={actionsFor}
                onOpen={onOpen}
                onContextMenu={onCtx}
            />
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full text-sm">
                    <HeadRow
                        cols={[
                            'Topic',
                            'Type',
                            'Status',
                            'Progress',
                            'Docs',
                            'Actions',
                        ]}
                    />
                    <tbody className="divide-y">
                        {rows.map((c) => {
                            const st = CONSULT_STATUS[c.status] ?? {
                                tone: 'neutral' as const,
                                label:
                                    titleCase(c.status) ||
                                    'Status not recorded',
                            };
                            const ord = CONSULT_ORDER[c.status];
                            return (
                                <Row
                                    key={c.id}
                                    onClick={() => onOpen(c.id)}
                                    onContextMenu={(e) =>
                                        onCtx(
                                            e,
                                            'consultation',
                                            c,
                                            c.status.toUpperCase(),
                                            c.title,
                                        )
                                    }
                                >
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2.5">
                                            <span
                                                className={`h-1.5 w-1.5 rounded-full ${TONE_DOT[st.tone]}`}
                                            />
                                            <span>
                                                <span className="block max-w-sm truncate font-medium">
                                                    {c.title}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {c.site?.name} ·{' '}
                                                    {formatDateOnly(
                                                        c.consultation_date,
                                                    )}
                                                </span>
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <span
                                            className={`inline-flex rounded-md px-2 py-0.5 text-[11px] font-medium ${TONE_BG.neutral}`}
                                        >
                                            {titleCase(c.consultation_type)}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Pill tone={st.tone}>{st.label}</Pill>
                                        <div className="mt-1">
                                            <ParticipationOwnershipChip
                                                state={c.site_ownership}
                                            />
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div
                                            className="flex items-center gap-1"
                                            aria-label={
                                                ord
                                                    ? `Stage ${ord} of 4`
                                                    : 'Stage not confirmed'
                                            }
                                        >
                                            {[1, 2, 3, 4].map((i) => (
                                                <span
                                                    key={i}
                                                    className={`h-1.5 w-6 rounded ${ord && i <= ord ? TONE_DOT[st.tone] : 'bg-muted'}`}
                                                />
                                            ))}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex gap-2 text-muted-foreground">
                                            <FileText
                                                aria-label={
                                                    c.document_path
                                                        ? 'Consultation document recorded'
                                                        : 'Consultation document not recorded'
                                                }
                                                className={`h-4 w-4 ${c.document_path ? 'text-primary' : 'opacity-30'}`}
                                            />
                                            <FileCheck2
                                                aria-label={
                                                    c.outcome_document_path
                                                        ? 'Outcome document recorded'
                                                        : 'Outcome document not recorded'
                                                }
                                                className={`h-4 w-4 ${c.outcome_document_path ? 'text-status-success' : 'opacity-30'}`}
                                            />
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <ParticipationRowMenu
                                            kind="consultation"
                                            row={c}
                                            name={c.title}
                                            actionsFor={actionsFor}
                                        />
                                    </td>
                                </Row>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </>
    );
}

function Empty({
    icon: Icon,
    label,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
}) {
    return (
        <div className="px-4 py-16 text-center">
            <Icon className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
            <p className="font-medium text-muted-foreground">{label}</p>
            <p className="mt-1 text-sm text-muted-foreground/70">
                Nothing matches this tab and filters.
            </p>
        </div>
    );
}
