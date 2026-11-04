/**
 * Inspections & maintenance actions for list pages (the Sites list and the
 * cross-site Inspections & Maintenance page):
 *
 *   const work = useSiteWork();
 *   <SiteWorkMenu can={can} onSelect={(kind) => work.open({ kind })} />
 *   …siteWorkMenuItems(can, site, work.open)   // a Site's kebab + right-click
 *   <SiteWorkDialogs work={work} sites={sites} overdue={{ … }} />
 *
 * Acting on a row of the Overdue list hides the list while the form is open
 * and brings it back (re-read) when the form closes, so a manager can work
 * down the list without leaving the page. Saving reloads the page's props,
 * which keeps its header counts current.
 */
import type { MenuItem } from '@/components/lists';
import { PageHeaderGlassButton } from '@/components/page';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    CalendarPlus,
    ChevronDown,
    ClipboardCheck,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { LogMaintenanceDialog } from './log-maintenance-dialog';
import { OverdueWorkDialog, type OverdueGroup } from './overdue-work-dialog';
import { RecordInspectionDialog } from './record-inspection-dialog';
import { ScheduleInspectionDialog } from './schedule-inspection-dialog';
import type { SiteWorkCan, SiteWorkRequest, SiteWorkSite } from './shared';

export type SiteWorkKind = SiteWorkRequest['kind'];

const ACTIONS: {
    kind: SiteWorkKind;
    label: string;
    icon: LucideIcon;
    allowed: (can: SiteWorkCan) => boolean;
}[] = [
    {
        kind: 'schedule',
        label: 'Schedule inspection',
        icon: CalendarPlus,
        allowed: (can) => can.schedule,
    },
    {
        kind: 'record',
        label: 'Record inspection result',
        icon: ClipboardCheck,
        allowed: (can) => can.record,
    },
    {
        kind: 'maintenance',
        label: 'Log maintenance',
        icon: Wrench,
        allowed: (can) => can.maintenance,
    },
];

export function useSiteWork() {
    const [request, setRequest] = useState<SiteWorkRequest | null>(null);
    // Remounts a form each time it opens, so no earlier typing carries over.
    const [session, setSession] = useState(0);
    const [overdueOpen, setOverdueOpen] = useState(false);
    const [returnToOverdue, setReturnToOverdue] = useState(false);

    const open = useCallback((next: SiteWorkRequest) => {
        setReturnToOverdue(false);
        setSession((n) => n + 1);
        setRequest(next);
    }, []);

    const openFromOverdue = useCallback((next: SiteWorkRequest) => {
        setOverdueOpen(false);
        setReturnToOverdue(true);
        setSession((n) => n + 1);
        setRequest(next);
    }, []);

    const closeRequest = useCallback(() => {
        setRequest(null);
        if (returnToOverdue) {
            setReturnToOverdue(false);
            setOverdueOpen(true);
        }
    }, [returnToOverdue]);

    return {
        request,
        session,
        open,
        openFromOverdue,
        closeRequest,
        overdueOpen,
        openOverdue: useCallback(() => setOverdueOpen(true), []),
        closeOverdue: useCallback(() => setOverdueOpen(false), []),
    };
}

export type SiteWorkController = ReturnType<typeof useSiteWork>;

/** The actions a viewer may take, for a header menu or a Site's menu. */
export function allowedSiteWork(can: SiteWorkCan) {
    return ACTIONS.filter((action) => action.allowed(can));
}

/** A Site's kebab / right-click items, with that Site filled in. */
export function siteWorkMenuItems(
    can: SiteWorkCan,
    site: SiteWorkSite,
    open: (request: SiteWorkRequest) => void,
): MenuItem[] {
    return allowedSiteWork(can).map((action) => ({
        label: action.label,
        icon: action.icon,
        onClick: () => open({ kind: action.kind, site }),
    }));
}

/** Header menu (glass): each action asks which Site. */
export function SiteWorkMenu({
    can,
    onSelect,
    label = 'Inspections & maintenance',
}: {
    can: SiteWorkCan;
    onSelect: (kind: SiteWorkKind) => void;
    label?: string;
}) {
    const actions = allowedSiteWork(can);
    if (actions.length === 0) return null;
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <PageHeaderGlassButton icon={ClipboardCheck}>
                    {label} <ChevronDown className="size-3.5" />
                </PageHeaderGlassButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuLabel>For any of your sites</DropdownMenuLabel>
                {actions.map((action) => (
                    <DropdownMenuItem
                        key={action.kind}
                        onSelect={() => onSelect(action.kind)}
                    >
                        <action.icon className="size-4" />
                        {action.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function SiteWorkDialogs({
    work,
    sites,
    redirectBack = true,
    overdue,
}: {
    work: SiteWorkController;
    /** Sites to choose from when a form opens without one. */
    sites: SiteWorkSite[];
    /** Inspection saves return to this page (the default). */
    redirectBack?: boolean;
    /** Mount the Overdue list (opened with work.openOverdue()). */
    overdue?: {
        siteId?: number | null;
        groups?: OverdueGroup[];
        title?: string;
        description?: string;
        footerLink?: { href: string; label: string };
    };
}) {
    const request = work.request;
    return (
        <>
            {request?.kind === 'schedule' ? (
                <ScheduleInspectionDialog
                    key={work.session}
                    open
                    onClose={work.closeRequest}
                    site={request.site}
                    sites={sites}
                    redirectBack={redirectBack}
                />
            ) : null}
            {request?.kind === 'record' ? (
                <RecordInspectionDialog
                    key={work.session}
                    open
                    onClose={work.closeRequest}
                    site={request.site}
                    sites={sites}
                    schedule={request.schedule}
                    redirectBack={redirectBack}
                />
            ) : null}
            {request?.kind === 'maintenance' ? (
                <LogMaintenanceDialog
                    key={work.session}
                    open
                    onClose={work.closeRequest}
                    site={request.site}
                    sites={sites}
                    asset={request.asset}
                />
            ) : null}
            {overdue ? (
                <OverdueWorkDialog
                    open={work.overdueOpen}
                    onClose={work.closeOverdue}
                    siteId={overdue.siteId}
                    groups={overdue.groups}
                    title={overdue.title}
                    description={overdue.description}
                    footerLink={overdue.footerLink}
                    onRecord={(site, schedule) =>
                        work.openFromOverdue({ kind: 'record', site, schedule })
                    }
                    onLog={(site, asset) =>
                        work.openFromOverdue({
                            kind: 'maintenance',
                            site,
                            asset,
                        })
                    }
                />
            ) : null}
        </>
    );
}
