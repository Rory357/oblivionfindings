/**
 * The Overdue list behind the Sites list's "Overdue" meter (and the cross-site
 * Inspections & Maintenance page's maintenance number): checklists,
 * inspections and maintenance past their due date at the viewer's Sites,
 * most overdue first, each with the action that clears it. Read from
 * GET /sites/overdue-work, scoped and counted exactly like the Sites list
 * header; each group shows at most 50 rows with its real total.
 */
import { PopupViewer } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    CircleCheck,
    ClipboardCheck,
    ClipboardList,
    TriangleAlert,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import {
    overdueLabel,
    overdueVariant,
    useSiteWorkRead,
    type SiteWorkAssetRef,
    type SiteWorkScheduleRef,
    type SiteWorkSite,
} from './shared';

type OverdueSite = { id: number; name: string | null; type?: string | null };

type OverdueChecklist = {
    id: number;
    site: OverdueSite;
    title: string;
    due_date: string | null;
    days_overdue: number | null;
    in_progress: boolean;
    href: string;
};

type OverdueInspection = {
    id: number;
    site: OverdueSite;
    title: string;
    inspection_type: string | null;
    frequency: string | null;
    due_date: string | null;
    days_overdue: number | null;
    can_record: boolean;
};

type OverdueAsset = {
    id: number;
    site: OverdueSite;
    name: string;
    asset_tag: string | null;
    due_date: string | null;
    days_overdue: number | null;
    can_log: boolean;
};

type OverduePayload = {
    limit: number;
    checklists: {
        total: number;
        items: OverdueChecklist[];
        can_complete: boolean;
    };
    inspections: { total: number; items: OverdueInspection[] };
    maintenance: { total: number; items: OverdueAsset[] };
};

export type OverdueGroup = 'checklists' | 'inspections' | 'maintenance';

const ALL_GROUPS: OverdueGroup[] = ['checklists', 'inspections', 'maintenance'];

function toSite(site: OverdueSite): SiteWorkSite {
    return { id: site.id, name: site.name ?? 'Site', type: site.type };
}

export function OverdueWorkDialog({
    open,
    onClose,
    siteId,
    groups = ALL_GROUPS,
    title = 'Overdue work',
    description = 'Past their due date at your sites, most overdue first',
    onRecord,
    onLog,
    footerLink,
}: {
    open: boolean;
    onClose: () => void;
    /** Only this Site's work (the page's site filter). */
    siteId?: number | null;
    groups?: OverdueGroup[];
    title?: string;
    description?: string;
    onRecord: (site: SiteWorkSite, schedule: SiteWorkScheduleRef) => void;
    onLog: (site: SiteWorkSite, asset: SiteWorkAssetRef) => void;
    footerLink?: { href: string; label: string };
}) {
    const [reload, setReload] = useState(0);
    const url = open
        ? `/sites/overdue-work${siteId ? `?site_id=${siteId}` : ''}`
        : null;
    const state = useSiteWorkRead<OverduePayload>(url, reload);

    const shown = (group: OverdueGroup) => groups.includes(group);

    let body: ReactNode;
    if (state.status === 'failed') {
        body = (
            <ErrorState
                title="The overdue list couldn't load"
                message="Nothing was changed. Check your connection and try again."
                onRetry={() => setReload((n) => n + 1)}
            />
        );
    } else if (state.status !== 'ready') {
        body = (
            <div className="grid gap-2" role="status" aria-label="Loading">
                {Array.from({ length: 4 }).map((_, i) => (
                    <Skeleton key={i} className="h-14 w-full rounded-[12px]" />
                ))}
            </div>
        );
    } else {
        const data = state.data;
        const totals =
            (shown('checklists') ? data.checklists.total : 0) +
            (shown('inspections') ? data.inspections.total : 0) +
            (shown('maintenance') ? data.maintenance.total : 0);

        body =
            totals === 0 ? (
                <EmptyState
                    icon={CircleCheck}
                    title="Nothing overdue"
                    description="Every checklist, inspection and service here is up to date."
                />
            ) : (
                <div className="grid gap-5">
                    {shown('checklists') && data.checklists.total > 0 ? (
                        <OverdueSection
                            icon={ClipboardList}
                            title="Checklists"
                            total={data.checklists.total}
                            shownCount={data.checklists.items.length}
                        >
                            {data.checklists.items.map((item) => (
                                <OverdueRow
                                    key={`run-${item.id}`}
                                    title={item.title}
                                    site={item.site.name}
                                    detail={
                                        item.in_progress
                                            ? 'Started, not finished'
                                            : undefined
                                    }
                                    dueDate={item.due_date}
                                    days={item.days_overdue}
                                    action={
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link href={item.href}>
                                                {data.checklists.can_complete
                                                    ? 'Complete'
                                                    : 'Open'}
                                            </Link>
                                        </Button>
                                    }
                                />
                            ))}
                        </OverdueSection>
                    ) : null}

                    {shown('inspections') && data.inspections.total > 0 ? (
                        <OverdueSection
                            icon={ClipboardCheck}
                            title="Inspections"
                            total={data.inspections.total}
                            shownCount={data.inspections.items.length}
                        >
                            {data.inspections.items.map((item) => (
                                <OverdueRow
                                    key={`inspection-${item.id}`}
                                    title={item.title}
                                    site={item.site.name}
                                    detail={item.inspection_type ?? undefined}
                                    dueDate={item.due_date}
                                    days={item.days_overdue}
                                    action={
                                        item.can_record ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    onRecord(
                                                        toSite(item.site),
                                                        {
                                                            id: item.id,
                                                            title: item.title,
                                                            inspection_type:
                                                                item.inspection_type,
                                                            frequency:
                                                                item.frequency,
                                                            due_date:
                                                                item.due_date,
                                                            days_overdue:
                                                                item.days_overdue,
                                                        },
                                                    )
                                                }
                                            >
                                                Record result
                                            </Button>
                                        ) : null
                                    }
                                />
                            ))}
                        </OverdueSection>
                    ) : null}

                    {shown('maintenance') && data.maintenance.total > 0 ? (
                        <OverdueSection
                            icon={Wrench}
                            title="Maintenance"
                            total={data.maintenance.total}
                            shownCount={data.maintenance.items.length}
                        >
                            {data.maintenance.items.map((item) => (
                                <OverdueRow
                                    key={`asset-${item.id}`}
                                    title={item.name}
                                    site={item.site.name}
                                    detail={item.asset_tag ?? undefined}
                                    dueDate={item.due_date}
                                    days={item.days_overdue}
                                    action={
                                        item.can_log ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    onLog(toSite(item.site), {
                                                        id: item.id,
                                                        name: item.name,
                                                        asset_tag:
                                                            item.asset_tag,
                                                        due_date: item.due_date,
                                                        days_overdue:
                                                            item.days_overdue,
                                                    })
                                                }
                                            >
                                                Log maintenance
                                            </Button>
                                        ) : null
                                    }
                                />
                            ))}
                        </OverdueSection>
                    ) : null}
                </div>
            );
    }

    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="md"
            icon={TriangleAlert}
            title={title}
            description={description}
            action={
                footerLink ? (
                    <Button asChild>
                        <Link href={footerLink.href}>{footerLink.label}</Link>
                    </Button>
                ) : undefined
            }
        >
            {body}
        </PopupViewer>
    );
}

function OverdueSection({
    icon: Icon,
    title,
    total,
    shownCount,
    children,
}: {
    icon: LucideIcon;
    title: string;
    total: number;
    shownCount: number;
    children: ReactNode;
}) {
    return (
        <section aria-label={title} className="grid gap-2">
            <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                <Icon
                    aria-hidden="true"
                    className="size-4 text-muted-foreground"
                />
                {title}
                <span className="rounded-[6px] bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground tabular-nums">
                    {total}
                </span>
            </h3>
            <ul className="divide-y divide-border overflow-hidden rounded-[12px] border border-border">
                {children}
            </ul>
            {total > shownCount ? (
                <p className="text-caption">
                    Showing the {shownCount} most overdue of {total}.
                </p>
            ) : null}
        </section>
    );
}

function OverdueRow({
    title,
    site,
    detail,
    dueDate,
    days,
    action,
}: {
    title: string;
    site: string | null;
    detail?: string;
    dueDate: string | null;
    days: number | null;
    action: ReactNode;
}) {
    const facts = [
        site,
        detail,
        dueDate ? `Due ${formatDateOnly(dueDate)}` : null,
    ].filter(Boolean);
    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-2 px-3.5 py-3">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-foreground">
                    {title}
                </p>
                <p className="text-caption truncate">{facts.join(' · ')}</p>
            </div>
            <StatusBadge
                variant={overdueVariant(days)}
                label={overdueLabel(days)}
            />
            {action}
        </li>
    );
}
