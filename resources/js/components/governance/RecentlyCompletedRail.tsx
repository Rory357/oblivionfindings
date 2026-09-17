import { Link } from '@inertiajs/react';
import {
    BookOpenCheck,
    CheckCircle2,
    ClipboardCheck,
    DollarSign,
    FileSignature,
    ShieldCheck,
    ShieldOff,
} from 'lucide-react';

import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { refSuffix } from '@/lib/governance-labels';

export interface CompletedItem {
    kind:
        | 'risk_closed'
        | 'risk_removed'
        | 'action_completed'
        | 'minutes_approved'
        | 'minutes_signed'
        | 'policy_approved'
        | 'spend_approved'
        | string;
    title: string;
    reference?: string | null;
    completed_at: string | null;
    completed_label: string | null;
    href: string;
    owner: string | null;
}

interface RecentlyCompletedRailProps {
    items: CompletedItem[];
}

const KIND_META: Record<string, { icon: typeof CheckCircle2; label: string }> = {
    risk_closed: { icon: ShieldCheck, label: 'Risk closed' },
    risk_removed: { icon: ShieldOff, label: 'Removed from the register' },
    action_completed: { icon: ClipboardCheck, label: 'Action done' },
    minutes_approved: { icon: FileSignature, label: 'Minutes approved' },
    minutes_signed: { icon: FileSignature, label: 'Minutes signed' },
    policy_approved: { icon: BookOpenCheck, label: 'Policy approved' },
    spend_approved: { icon: DollarSign, label: 'Spend request approved' },
};

const FALLBACK_META = { icon: CheckCircle2, label: 'Done' };

/**
 * Work finished in the last 14 days, already filtered on the server to the
 * registers the viewer can open. Renders nothing when empty.
 */
export function RecentlyCompletedRail({ items }: RecentlyCompletedRailProps) {
    if (!items?.length) return null;

    return (
        <Card data-dusk="cockpit-recently-completed">
            <CardHeader className="pb-3">
                <CardTitle className="text-section-title">
                    Recently completed
                </CardTitle>
                <CardDescription>
                    Finished in the last 14 days — nothing more is needed on
                    these.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="scrollbar-pretty -mx-1 flex snap-x snap-mandatory gap-3 overflow-x-auto px-1 pb-2">
                    {items.map((item, idx) => {
                        const meta = KIND_META[item.kind] ?? FALLBACK_META;
                        const Icon = meta.icon;
                        const reference = refSuffix(item.reference);
                        return (
                            <li
                                key={`${item.kind}-${item.href}-${idx}`}
                                className="w-64 shrink-0 snap-start"
                            >
                                <Link
                                    href={item.href}
                                    className="flex h-full flex-col gap-2 rounded-lg border border-border bg-card p-3 transition-colors hover:border-status-success/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="flex items-start gap-2">
                                        <span className="rounded-md bg-status-success-bg p-1.5 text-status-success">
                                            <Icon
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-caption">
                                                {meta.label}
                                            </span>
                                            <span className="line-clamp-2 text-sm leading-snug font-medium text-foreground">
                                                {item.title}
                                            </span>
                                        </span>
                                    </span>
                                    <span className="flex flex-wrap items-center justify-between gap-2 text-caption">
                                        <span>
                                            {[item.owner, reference]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                        {item.completed_label ? (
                                            <span>{item.completed_label}</span>
                                        ) : null}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </CardContent>
        </Card>
    );
}

export default RecentlyCompletedRail;
