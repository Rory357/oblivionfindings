/**
 * Worker participation (approved H&S drawings pg-hsn-104 … 104d): one header
 * with four number boxes, the filters and the People and participation hub
 * on the rail, whose three views are this page's tabs (drawing D4); then
 * representatives as people cards, committee meetings as two logs and
 * consultations as a table. The detail dialogs and create wizards are
 * unchanged. Every record's kebab and right-click come from one actionsFor
 * list, so eligibility stays in one place. The viewer boundary masks the page
 * if the signed-in account changes.
 */
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import {
    EntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import {
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageLayout,
} from '@/components/page';
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { AddRepresentativeWizard } from '@/components/worker-participation/add-representative-wizard';
import { ConsultationDetailDialog } from '@/components/worker-participation/consultation-detail-dialog';
import { MeetingDetailDialog } from '@/components/worker-participation/meeting-detail-dialog';
import { NewConsultationWizard } from '@/components/worker-participation/new-consultation-wizard';
import { RepresentativeDetailDialog } from '@/components/worker-participation/representative-detail-dialog';
import { ScheduleMeetingWizard } from '@/components/worker-participation/schedule-meeting-wizard';
import {
    type ConsultationRow,
    type MeetingRow,
    type RepRow,
    type WpDetailAction,
} from '@/components/worker-participation/shared';
import { ConfirmDialog } from '@/components/confirm-dialog';
import AppLayout from '@/layouts/app-layout';
import { titleCase } from '@/pages/health-safety/components/register-row-kit';
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
    GraduationCap,
    MessageSquare,
    MoreHorizontal,
    Pencil,
    Plus,
    Slash,
    Upload,
    UserPlus,
    XCircle,
} from 'lucide-react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';
import { participationMenu } from './participation-cards';
import {
    ParticipationHeader,
    ParticipationTally,
    type ParticipationFilters,
    type ParticipationHero,
} from './participation-header';
import { ParticipationViewerBoundary } from './participation-viewer-boundary';
import {
    ConsultationTable,
    MeetingLogs,
    RepresentativeCards,
} from './participation-views';

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
    // Tracks which sub-action a detail dialog should open straight onto (right-click → action).
    const [pendingAction, setPendingAction] = useState<WpDetailAction | null>(
        null,
    );
    // Standing a representative down and cancelling a meeting ask first (they change the record people rely on).
    const [confirming, setConfirming] = useState<
        | { action: 'stand_down'; id: number; name: string | null }
        | { action: 'cancel_meeting'; id: number; name: string | null }
        | null
    >(null);
    const [confirmBusy, setConfirmBusy] = useState(false);
    const runConfirmed = () => {
        if (!confirming) return;
        setConfirmBusy(true);
        const finish = { preserveScroll: true, onFinish: () => { setConfirmBusy(false); setConfirming(null); } };
        if (confirming.action === 'stand_down') {
            router.put(`${BASE}/representatives/${confirming.id}`, { status: 'inactive' }, finish);
        } else {
            router.put(`${BASE}/meetings/${confirming.id}/cancel`, {}, finish);
        }
    };

    const go = (next: Partial<Filters>) =>
        router.get(
            BASE,
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
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

    // The three views are the People and participation hub's tabs on the header rail.
    const TAB_LABELS: Record<Props['tab'], string> = {
        representatives: 'Representatives',
        meetings: 'Committee meetings',
        consultations: 'Consultations',
    };

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
                              label: 'No longer a representative',
                              tone: 'critical',
                              onClick: () =>
                                  setConfirming({ action: 'stand_down', id: row.id, name: row.user?.name ?? null }),
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
                            setConfirming({ action: 'cancel_meeting', id: row.id, name: row.committee?.name ?? null }),
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

    const pager =
        rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null;
    const exportHref = `${BASE}/export${filters.site_id ? `?site_id=${filters.site_id}` : ''}`;
    const pageActions = can.manage ? (
        <>
            <PageHeaderGlassButton onClick={() => setWizard('meeting')}>
                Schedule meeting
            </PageHeaderGlassButton>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <PageHeaderGlassButton
                        icon={MoreHorizontal}
                        aria-label="More participation actions"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => setWizard('consultation')}>
                        <MessageSquare className="size-4" />
                        New consultation
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => window.open(exportHref)}>
                        <Download className="size-4" />
                        Export board report
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <PageHeaderPrimaryButton icon={Plus} onClick={() => setWizard('rep')}>
                Add representative
            </PageHeaderPrimaryButton>
        </>
    ) : null;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'People and participation', href: '/health-safety/registers?view=participation' },
                { title: 'Worker participation', href: BASE },
            ]}
        >
            <Head title="Worker participation" />
            <PageLayout
                hero={
                    <div onContextMenu={openHeroCtx}>
                        <ParticipationHeader
                            filters={filters}
                            hero={hero}
                            sites={sites}
                            periods={PERIOD}
                            statuses={STATUS_OPTS[tab]}
                            onFilters={go}
                            onClear={clearFilters}
                            actions={pageActions}
                            rail={
                                <SafetyWorkspaceRail
                                    hub="participation"
                                    current={tab}
                                    counts={tabCounts}
                                    // The three views share this page: switching keeps the Site, period and search.
                                    onSelect={(id) => {
                                        router.get(
                                            BASE,
                                            { ...filters, tab: id, status: null, focus: null },
                                            { preserveState: true, preserveScroll: true },
                                        );
                                        return true;
                                    }}
                                />
                            }
                        />
                    </div>
                }
            >
                <div
                    role="tabpanel"
                    aria-label={TAB_LABELS[tab] ?? 'Participation records'}
                    className="grid min-w-0 gap-5"
                >
                    {tab === 'representatives' ? (
                        <>
                            <RepresentativeCards
                                rows={rows.data as RepRow[]}
                                actionsFor={actionsFor}
                                onOpen={(id) => openDetail('representative', id)}
                                onContext={openRowCtx}
                                footer={pager}
                            />
                            <ParticipationTally
                                filters={filters}
                                hero={hero}
                                meetingCount={tabCounts.meetings}
                            />
                        </>
                    ) : tab === 'meetings' ? (
                        <MeetingLogs
                            rows={rows.data as MeetingRow[]}
                            actionsFor={actionsFor}
                            onOpen={(id) => openDetail('meeting', id)}
                            onContext={openRowCtx}
                            footer={pager}
                        />
                    ) : (
                        <ConsultationTable
                            rows={rows.data as ConsultationRow[]}
                            actionsFor={actionsFor}
                            onOpen={(id) => openDetail('consultation', id)}
                            footer={pager}
                        />
                    )}
                </div>
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
            <ConfirmDialog
                open={confirming !== null}
                onClose={() => { if (!confirmBusy) setConfirming(null); }}
                onConfirm={runConfirmed}
                processing={confirmBusy}
                variant="destructive"
                title={confirming?.action === 'stand_down' ? 'No longer a representative?' : 'Cancel this meeting?'}
                description={confirming?.action === 'stand_down'
                    ? `${confirming.name ?? 'This person'} will show as no longer a health and safety representative, and their site may show as having no representative until someone else is added. You can make them active again later.`
                    : `This ${confirming?.name ? confirming.name + ' ' : ''}meeting stays on the record as cancelled and can’t be held or edited. This can’t be undone.`}
                confirmText={confirming?.action === 'stand_down' ? 'No longer a representative' : 'Cancel meeting'}
                cancelText={confirming?.action === 'stand_down' ? 'Keep as representative' : 'Keep meeting'}
            />
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
