import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardCheck,
    DollarSign,
    FileSignature,
    ShieldCheck,
} from 'lucide-react';

export interface CompletedItem {
    kind:
        | 'risk_closed'
        | 'action_completed'
        | 'minutes_approved'
        | 'policy_signed'
        | 'spend_approved'
        | string;
    title: string;
    completed_at: string | null;
    completed_label: string | null;
    href: string;
    owner: string | null;
}

interface RecentlyCompletedRailProps {
    items: CompletedItem[];
}

const KIND_META: Record<
    string,
    { icon: typeof CheckCircle2; label: string; tone: string }
> = {
    risk_closed: {
        icon: ShieldCheck,
        label: 'Risk closed',
        tone: 'text-status-success bg-status-success-bg',
    },
    action_completed: {
        icon: ClipboardCheck,
        label: 'Action complete',
        tone: 'text-status-info bg-status-info-bg',
    },
    minutes_approved: {
        icon: FileSignature,
        label: 'Minutes signed',
        tone: 'text-primary bg-primary/10',
    },
    policy_signed: {
        icon: FileSignature,
        label: 'Policy approved',
        tone: 'text-status-info bg-status-info-bg',
    },
    spend_approved: {
        icon: DollarSign,
        label: 'Spend approved',
        tone: 'text-status-success bg-status-success-bg',
    },
};

/**
 * Reassurance rail — closed risks, completed actions, signed minutes, signed
 * policies, approved spend in the last 14 days. Renders nothing when empty so
 * we don't waste a row on "nothing happened" copy.
 */
export function RecentlyCompletedRail({ items }: RecentlyCompletedRailProps) {
    if (!items?.length) return null;

    return (
        <Card data-dusk="cockpit-recently-completed">
            <CardHeader className="pb-3">
                <CardTitle className="text-base">Recently Completed</CardTitle>
                <CardDescription>
                    Closed in the last 14 days — these no longer need board
                    action.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="-mx-1 flex snap-x snap-mandatory gap-3 overflow-x-auto px-1 pb-2">
                    {items.map((item, idx) => {
                        const meta =
                            KIND_META[item.kind] ?? KIND_META.action_completed;
                        const Icon = meta.icon;
                        return (
                            <Link
                                key={`${item.kind}-${idx}`}
                                href={item.href}
                                className="group flex w-64 shrink-0 snap-start flex-col gap-2 rounded-lg border border-border bg-card p-3 transition hover:border-status-success/40 hover:shadow-sm"
                            >
                                <div className="flex items-start gap-2">
                                    <div
                                        className={cn(
                                            'rounded-md p-1.5',
                                            meta.tone,
                                        )}
                                    >
                                        <Icon
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                            {meta.label}
                                        </p>
                                        <p className="line-clamp-2 text-sm leading-snug font-medium text-foreground">
                                            {item.title}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center justify-between text-[10px] tracking-wide text-muted-foreground uppercase">
                                    {item.owner ? (
                                        <span>{item.owner}</span>
                                    ) : (
                                        <span>—</span>
                                    )}
                                    {item.completed_label ? (
                                        <span>{item.completed_label}</span>
                                    ) : null}
                                </div>
                            </Link>
                        );
                    })}
                </div>
            </CardContent>
        </Card>
    );
}

export default RecentlyCompletedRail;
