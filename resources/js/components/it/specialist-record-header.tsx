import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { StatusVariant } from '@/components/ui/status-badge';
import { Link } from '@inertiajs/react';
import { ExternalLink, MoreHorizontal, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export function SpecialistRecordHeader({
    icon,
    backHref,
    title,
    reference,
    status,
    statusVariant,
    subline,
    ticket,
    primary,
    actions = [],
}: {
    icon: LucideIcon;
    backHref: string;
    title: string;
    reference: string;
    status: string;
    statusVariant: StatusVariant;
    subline?: ReactNode;
    ticket: {
        href: string;
        comments_count: number;
        tasks_count: number;
        approvals_count: number;
        attachments_count: number;
    };
    primary?: { label: string; run: () => void };
    actions?: { label: string; run: () => void }[];
}) {
    const meters = [
        {
            label: 'Conversation',
            value: ticket.comments_count,
            tab: 'messages',
        },
        { label: 'Tasks & evidence', value: ticket.tasks_count, tab: 'tasks' },
        { label: 'Approvals', value: ticket.approvals_count, tab: 'approvals' },
        { label: 'Files', value: ticket.attachments_count, tab: 'files' },
    ];
    return (
        <PageHeader
            className="overflow-clip!"
            variant="profile"
            wrapTitle
            icon={icon}
            backHref={backHref}
            title={title}
            titleChip={
                <PageHeaderStatusChip variant={statusVariant}>
                    {status}
                </PageHeaderStatusChip>
            }
            subline={
                <>
                    {reference}
                    {subline ? <> · {subline}</> : null}
                </>
            }
            actions={
                <>
                    <Link
                        href={ticket.href}
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-3 text-sm font-semibold text-primary-foreground hover:bg-primary-foreground/20 focus-visible:ring-2 focus-visible:ring-primary-foreground"
                    >
                        <ExternalLink className="size-4" aria-hidden />
                        Open ticket
                    </Link>
                    {actions.length > 0 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <PageHeaderGlassButton
                                    className="min-h-11"
                                    icon={MoreHorizontal}
                                >
                                    More actions
                                </PageHeaderGlassButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {actions.map((action) => (
                                    <DropdownMenuItem
                                        key={action.label}
                                        onSelect={action.run}
                                    >
                                        {action.label}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                    {primary && (
                        <PageHeaderPrimaryButton
                            className="min-h-11"
                            onClick={primary.run}
                        >
                            {primary.label}
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    {meters.map((meter) => (
                        <PageHeaderMeterBlock
                            key={meter.tab}
                            label={meter.label}
                            href={`${ticket.href}?tab=${meter.tab}`}
                            ariaLabel={`Open ${meter.label.toLowerCase()}`}
                        >
                            <PageHeaderMeterBig>
                                {meter.value}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                On this ticket
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                </>
            }
        />
    );
}
