import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    PersonDisc,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { WORKER_TIMEZONE, formatDate, formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { ArrowUpRight, CalendarClock, CalendarDays, Check } from 'lucide-react';
import { ReferralStageBadge } from '../stage-badge';
import {
    label,
    referralHref,
    shownReference,
    type Page,
    type Permissions,
    type WorkspaceFollowUpRow,
} from '../types';

type Groups = { total: number; overdue: number; today: number };

const AUDIENCE: Record<string, string> = {
    intake: 'Intake follow-up',
    assessment: 'Assessment work',
    funding: 'Funding follow-up',
};

/** Same permission the profile uses before offering Edit / Finish on a follow-up. */
export function canWorkOn(row: WorkspaceFollowUpRow, permissions: Permissions) {
    return (
        !!permissions['tasks.manage'] &&
        (row.audience === 'intake' || !!permissions[`${row.audience}.manage`])
    );
}

/** New Zealand calendar day (the server groups "due today" the same way). */
const nzDay = (value: Date) =>
    value.toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE });
function groupOf(row: WorkspaceFollowUpRow): 'overdue' | 'today' | 'upcoming' {
    if (row.is_overdue) return 'overdue';
    return row.due_at && nzDay(new Date(row.due_at)) === nzDay(new Date())
        ? 'today'
        : 'upcoming';
}
const GROUP_TITLES = {
    overdue: 'Overdue',
    today: 'Due today',
    upcoming: 'Coming up',
} as const;

/**
 * Follow-ups view: the viewer's permitted open follow-ups and next actions
 * across referrals (ReferralWorkQueue scope), soonest first and grouped by
 * due state. Edit, reschedule and the outcome-required finish stay in the
 * referral's own follow-up workflow, which every row opens.
 */
export function FollowUpCollection({
    page,
    groups,
    layout,
    permissions,
    stages,
    onClear,
}: {
    page: Page<WorkspaceFollowUpRow>;
    groups: Groups | null;
    layout: 'cards' | 'table';
    permissions: Permissions;
    stages: { id: string; label: string }[];
    onClear: () => void;
}) {
    const menu = useEntityContextMenu<WorkspaceFollowUpRow>();
    const actions = (row: WorkspaceFollowUpRow): MenuItem[] => [
        {
            label: 'Open referral',
            icon: ArrowUpRight,
            onClick: () => router.visit(referralHref(row.referral.reference)),
        },
        {
            label: 'Work on follow-up',
            icon: Check,
            onClick: () => router.visit(row.href),
            disabled: canWorkOn(row, permissions)
                ? undefined
                : 'Requires follow-up permission',
        },
    ];
    if (page.data.length === 0)
        return (
            <EmptyState
                icon={CalendarClock}
                title="No outstanding follow-ups"
                description="Change the filters to see other permitted work."
                action={
                    <Button variant="outline" onClick={onClear}>
                        Clear filters
                    </Button>
                }
            />
        );
    const sections = (['overdue', 'today', 'upcoming'] as const)
        .map((key) => ({
            key,
            rows: page.data.filter((row) => groupOf(row) === key),
        }))
        .filter((section) => section.rows.length > 0);
    const totalFor = (key: 'overdue' | 'today' | 'upcoming') =>
        !groups
            ? null
            : key === 'overdue'
              ? groups.overdue
              : key === 'today'
                ? groups.today
                : Math.max(0, groups.total - groups.overdue - groups.today);
    const due = (row: WorkspaceFollowUpRow) => (
        <EntityStatusChip
            variant={row.is_overdue ? 'warning' : 'neutral'}
            icon={CalendarDays}
        >
            {row.is_overdue ? 'Overdue · ' : ''}
            {row.due_at ? formatDateTime(row.due_at) : 'No date'}
        </EntityStatusChip>
    );
    return (
        <div className="grid gap-5">
            {sections.map((section) => (
                <section key={section.key} className="grid gap-5">
                    <ListCaption
                        title={GROUP_TITLES[section.key]}
                        caption={`${section.rows.length} on this page${totalFor(section.key) !== null ? ` · ${totalFor(section.key)} in this view` : ''}`}
                    />
                    {layout === 'table' ? (
                        <EntityTable
                            rows={section.rows}
                            rowKey={(row) => row.key}
                            identityLabel="Person / follow-up"
                            identityWidth="2fr"
                            identity={(row) => ({
                                mark: (
                                    <PersonDisc
                                        name={row.referral.name}
                                        size={30}
                                    />
                                ),
                                name: row.referral.name,
                                subline: row.title,
                            })}
                            hrefFor={(row) => row.href}
                            onOpen={(row) => router.visit(row.href)}
                            actionsFor={actions}
                            onRowContextMenu={(event, row) =>
                                menu.open(event, row)
                            }
                            rowHeight="content"
                            minWidth={900}
                            columns={[
                                {
                                    key: 'owner',
                                    label: 'Owner',
                                    width: '1.2fr',
                                    cell: (row) => (
                                        <PersonCell name={row.owner_name} />
                                    ),
                                },
                                {
                                    key: 'kind',
                                    label: 'Type',
                                    width: '1fr',
                                    cell: (row) => (
                                        <EntityChip>
                                            {row.type === 'next'
                                                ? 'Next action'
                                                : (AUDIENCE[row.audience] ??
                                                  label(row.kind))}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'stage',
                                    label: 'Stage',
                                    width: '1fr',
                                    cell: (row) => (
                                        <ReferralStageBadge
                                            stage={row.referral.stage}
                                            stages={stages}
                                        />
                                    ),
                                },
                                {
                                    key: 'due',
                                    label: 'Due',
                                    width: '1.3fr',
                                    cell: due,
                                },
                            ]}
                        />
                    ) : (
                        <EntityCardGrid>
                            {section.rows.map((row) => (
                                <EntityCard
                                    key={row.key}
                                    meridian={
                                        row.is_overdue ? 'warning' : 'success'
                                    }
                                    mark={
                                        <PersonDisc
                                            name={row.referral.name}
                                            size={40}
                                        />
                                    }
                                    name={row.referral.name}
                                    href={row.href}
                                    linkLabel={`Open follow-up for ${row.referral.name}`}
                                    subline={row.title}
                                    actions={actions(row)}
                                    onContextMenu={(event) =>
                                        menu.open(event, row)
                                    }
                                    chips={
                                        <>
                                            <EntityChip>
                                                {row.type === 'next'
                                                    ? 'Next action'
                                                    : (AUDIENCE[row.audience] ??
                                                      label(row.kind))}
                                            </EntityChip>
                                            <EntityChip>
                                                {row.referral.site_name ??
                                                    'Central intake'}
                                            </EntityChip>
                                        </>
                                    }
                                    alerts={due(row)}
                                    footer={{
                                        personName: row.owner_name,
                                        primary:
                                            row.owner_name ?? 'No named owner',
                                        secondary: row.ends_at
                                            ? `Until ${formatDate(row.ends_at)}`
                                            : shownReference(row.referral),
                                    }}
                                />
                            ))}
                        </EntityCardGrid>
                    )}
                </section>
            ))}
            <LaravelPagination links={page.links} lastPage={page.last_page} />
            {menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={menu.ctx.record.referral.name}
                    items={actions(menu.ctx.record)}
                    onClose={menu.close}
                />
            )}
        </div>
    );
}
