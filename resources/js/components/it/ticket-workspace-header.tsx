import {
    GroupPillRail,
    TabSearchPalette,
    TierTwoTabs,
    useGroupedProfileSearchShortcut,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { StatusVariant } from '@/components/ui/status-badge';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    CheckCircle2,
    ClipboardCheck,
    Copy,
    Eye,
    EyeOff,
    FileText,
    GitMerge,
    Info,
    Link2,
    ListChecks,
    MessageSquare,
    MoreHorizontal,
    Paperclip,
    RotateCcw,
    ShieldCheck,
    Ticket,
    Timer,
    UserPlus,
    XCircle,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { SLA_LABELS, type SlaVerdict } from './sla-evidence';

export const TICKET_WORKSPACE_GROUPS: GroupedProfileNavGroup[] = [
    {
        key: 'conversation',
        label: 'Conversation',
        icon: MessageSquare,
        tabs: [
            { key: 'messages', label: 'Messages', icon: MessageSquare },
            { key: 'files', label: 'Files', icon: Paperclip },
        ],
    },
    {
        key: 'work',
        label: 'Work',
        icon: ListChecks,
        tabs: [
            { key: 'tasks', label: 'Tasks & evidence', icon: ClipboardCheck },
            { key: 'approvals', label: 'Approvals', icon: ShieldCheck },
        ],
    },
    {
        key: 'details',
        label: 'Details & links',
        icon: Info,
        tabs: [
            {
                key: 'properties',
                label: 'Classification & ownership',
                icon: FileText,
            },
            { key: 'links', label: 'Linked records', icon: Link2 },
            { key: 'sla', label: 'Service levels', icon: Timer },
        ],
    },
    {
        key: 'activity',
        label: 'Activity',
        icon: Activity,
        tabs: [{ key: 'history', label: 'History', icon: Activity }],
    },
];

export function ticketWorkspaceTab(
    value: string | null | undefined,
    canViewWork = true,
): string {
    return TICKET_WORKSPACE_GROUPS.some(
        (group) =>
            (canViewWork || group.key !== 'work') &&
            group.tabs.some((tab) => tab.key === value),
    )
        ? value!
        : 'messages';
}

type HeaderAction = { label: string; run: () => void; icon: LucideIcon };

export function TicketWorkspaceHeader({
    id,
    reference,
    title,
    status,
    statusVariant,
    subline,
    activeTab,
    onTab,
    original = false,
    sla,
    replies,
    files,
    tasks,
    related,
    primary,
    actions,
}: {
    id: number;
    reference: string | null;
    title: string;
    status: string;
    statusVariant: StatusVariant;
    subline: ReactNode;
    activeTab: string;
    onTab: (tab: string) => void;
    original?: boolean;
    sla?: SlaVerdict;
    replies: number;
    files: number;
    tasks: {
        completed: number;
        total: number;
        needsReview: number;
        unverified: number;
    } | null;
    related: number;
    primary?: HeaderAction;
    actions: {
        resolve?: () => void;
        reopen?: () => void;
        close?: () => void;
        merge?: () => void;
        assign?: () => void;
        watch?: () => void;
        watching: boolean;
        busy: boolean;
        copyReference: () => void;
        copyLink: () => void;
    };
}) {
    const [searchOpen, setSearchOpen] = useState(false);
    useGroupedProfileSearchShortcut(() => setSearchOpen(true));
    const groups = TICKET_WORKSPACE_GROUPS.filter(
        (item) => tasks !== null || item.key !== 'work',
    );
    const group =
        groups.find((item) => item.tabs.some((tab) => tab.key === activeTab)) ??
        groups[0];
    const href = (tab: string) =>
        `/it/tickets/${id}${original ? '/original' : ''}?tab=${tab}`;
    const go = (tab: string) => {
        setSearchOpen(false);
        onTab(tab);
    };
    const state = sla?.state ?? 'unmeasured';
    const tone =
        state === 'breached'
            ? 'critical'
            : state === 'at_risk' || state === 'unmeasured'
              ? 'warning'
              : state === 'met'
                ? 'success'
                : 'brand';
    return (
        <>
            <PageHeader
                className="overflow-clip!"
                variant="profile"
                icon={Ticket}
                backHref="/it"
                title={title}
                wrapTitle
                titleChip={
                    <PageHeaderStatusChip variant={statusVariant}>
                        {status}
                    </PageHeaderStatusChip>
                }
                subline={
                    <>
                        {reference ?? `Ticket ${id}`} · {subline}
                    </>
                }
                actions={
                    <>
                        <PageHeaderSearchTrigger
                            placeholder="Find a ticket section…"
                            onOpen={() => setSearchOpen(true)}
                        />
                        {primary && (
                            <PageHeaderPrimaryButton
                                icon={primary.icon}
                                onClick={primary.run}
                            >
                                {primary.label}
                            </PageHeaderPrimaryButton>
                        )}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <PageHeaderGlassButton
                                    icon={MoreHorizontal}
                                    aria-label="Ticket actions"
                                    disabled={actions.busy}
                                />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {actions.resolve && (
                                    <DropdownMenuItem onClick={actions.resolve}>
                                        <CheckCircle2 />
                                        Resolve ticket
                                    </DropdownMenuItem>
                                )}
                                {actions.reopen && (
                                    <DropdownMenuItem onClick={actions.reopen}>
                                        <RotateCcw />
                                        Reopen ticket
                                    </DropdownMenuItem>
                                )}
                                {actions.close && (
                                    <DropdownMenuItem onClick={actions.close}>
                                        <XCircle />
                                        Close ticket
                                    </DropdownMenuItem>
                                )}
                                {actions.assign && (
                                    <DropdownMenuItem onClick={actions.assign}>
                                        <UserPlus />
                                        Assign to me
                                    </DropdownMenuItem>
                                )}
                                {actions.watch && (
                                    <DropdownMenuItem onClick={actions.watch}>
                                        {actions.watching ? (
                                            <EyeOff />
                                        ) : (
                                            <Eye />
                                        )}
                                        {actions.watching
                                            ? 'Stop watching'
                                            : 'Watch ticket'}
                                    </DropdownMenuItem>
                                )}
                                {actions.merge && (
                                    <DropdownMenuItem onClick={actions.merge}>
                                        <GitMerge />
                                        Merge ticket
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuSeparator />
                                {reference && (
                                    <DropdownMenuItem
                                        onClick={actions.copyReference}
                                    >
                                        <Copy />
                                        Copy reference
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuItem onClick={actions.copyLink}>
                                    <Link2 />
                                    Copy ticket link
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </>
                }
                meters={
                    <>
                        <PageHeaderMeterBlock
                            label="Conversation"
                            href={href('messages')}
                            preserveState
                            preserveScroll
                            ariaLabel="View ticket conversation"
                        >
                            <PageHeaderMeterBig>{replies}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {replies === 1 ? 'Reply' : 'Replies'} · {files}{' '}
                                {files === 1 ? 'file' : 'files'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {tasks !== null && (
                            <PageHeaderMeterBlock
                                label="Required work"
                                value={
                                    tasks.total > 0 ? tasks.total : undefined
                                }
                                href={href('tasks')}
                                preserveState
                                preserveScroll
                                ariaLabel="View required ticket work"
                            >
                                {tasks.total > 0 ? (
                                    <PageHeaderMeterDonut
                                        percent={Math.round(
                                            (tasks.completed / tasks.total) *
                                                100,
                                        )}
                                    />
                                ) : (
                                    <PageHeaderMeterBig>0</PageHeaderMeterBig>
                                )}
                                <PageHeaderMeterCaption>
                                    {tasks.total
                                        ? `${tasks.completed} of ${tasks.total} verified complete`
                                        : 'No required tasks recorded'}
                                    {tasks.needsReview > 0 &&
                                        ` · ${tasks.needsReview} ${tasks.needsReview === 1 ? 'completion needs' : 'completions need'} review`}
                                    {tasks.unverified > 0 &&
                                        ` · ${tasks.unverified} ${tasks.unverified === 1 ? 'completion' : 'completions'} unverified`}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        )}
                        <PageHeaderMeterBlock
                            label="Service levels"
                            tone={tone}
                            href={href('sla')}
                            preserveState
                            preserveScroll
                            ariaLabel="View response and resolution clocks"
                        >
                            <PageHeaderMeterBig>
                                {SLA_LABELS[state]}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {sla?.coverage === 'full'
                                    ? 'Both clocks measured'
                                    : 'Measurement details available'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Linked records"
                            href={href('links')}
                            preserveState
                            preserveScroll
                            ariaLabel="View permitted linked records"
                        >
                            <PageHeaderMeterBig>{related}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Related systems and work
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                }
                rail={
                    <GroupPillRail
                        groups={groups}
                        openGroup={group.key}
                        activeTab={activeTab}
                        onOpenGroup={(_group, tab) => go(tab)}
                        onSearch={() => setSearchOpen(true)}
                        testIdPrefix="it-ticket"
                        ariaLabel="Ticket workspace"
                    />
                }
            />
            <TierTwoTabs
                tabs={group.tabs.map((tab) => ({
                    ...tab,
                    href: href(tab.key),
                }))}
                activeTab={activeTab}
                onTab={go}
                testIdPrefix="it-ticket"
                ariaLabel={`${group.label} sections`}
                panelId="it-ticket-panel"
                renderLink={(tab, className, inner, accessibility) => (
                    <Link
                        key={tab.key}
                        href={href(tab.key)}
                        className={className}
                        {...accessibility}
                        onClick={(event) => {
                            if (
                                !event.ctrlKey &&
                                !event.metaKey &&
                                !event.shiftKey &&
                                !event.altKey
                            ) {
                                event.preventDefault();
                                go(tab.key);
                            }
                        }}
                    >
                        {inner}
                    </Link>
                )}
            />
            <TabSearchPalette
                open={searchOpen}
                onClose={() => setSearchOpen(false)}
                groups={groups}
                onTab={go}
                testIdPrefix="it-ticket"
                searchLabel="Find a section in this ticket"
            />
        </>
    );
}
