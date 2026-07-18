import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    ArrowUpCircle,
    Bell,
    HeartPulse,
    LayoutDashboard,
    ListTodo,
    UsersRound,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

export type WorkspaceRoute =
    | '/control-room'
    | '/control-room/alerts'
    | '/control-room/escalations'
    | '/control-room/incidents'
    | '/control-room/my-tasks'
    | '/control-room/shifts';

type WorkspaceItem = {
    href: WorkspaceRoute;
    label: string;
    icon: LucideIcon;
};

const WORKSPACE_ITEMS: readonly WorkspaceItem[] = [
    { href: '/control-room', label: 'Desk', icon: LayoutDashboard },
    { href: '/control-room/alerts', label: 'Active alerts', icon: Bell },
    {
        href: '/control-room/escalations',
        label: 'Escalations',
        icon: ArrowUpCircle,
    },
    {
        href: '/control-room/incidents',
        label: 'Safety handovers',
        icon: HeartPulse,
    },
    { href: '/control-room/my-tasks', label: 'My queue', icon: ListTodo },
    { href: '/control-room/shifts', label: 'Shifts', icon: UsersRound },
];

function isActive(current: string, href: WorkspaceRoute): boolean {
    if (href === '/control-room') return current === href;

    return current === href || current.startsWith(`${href}/`);
}

export function WorkspaceStrip({
    current,
    badges,
    className,
}: {
    current: string;
    badges?: Partial<Record<WorkspaceRoute, ReactNode>>;
    className?: string;
}) {
    return (
        <nav
            aria-label="Control Room workspace"
            className={cn(
                'overflow-hidden rounded-xl border bg-card p-1.5 shadow-sm',
                className,
            )}
        >
            <div className="flex gap-1 overflow-x-auto overscroll-x-contain scroll-smooth [scrollbar-width:thin]">
                {WORKSPACE_ITEMS.map((item) => {
                    const active = isActive(current, item.href);
                    const Icon = item.icon;
                    const badge = badges?.[item.href];

                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex min-h-11 min-w-max flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:outline-none',
                                active
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                            )}
                        >
                            <Icon className="h-4 w-4 shrink-0" aria-hidden />
                            <span>{item.label}</span>
                            {badge !== undefined && badge !== null ? (
                                <Badge
                                    variant={active ? 'secondary' : 'outline'}
                                    className="h-5 min-w-5 justify-center px-1.5 text-[10px] tabular-nums"
                                >
                                    {badge}
                                </Badge>
                            ) : null}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
