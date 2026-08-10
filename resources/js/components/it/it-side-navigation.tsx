import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    BookOpen,
    BookOpenCheck,
    CalendarClock,
    ChartNoAxesColumn,
    CircleUserRound,
    Inbox,
    LayoutDashboard,
    Library,
    LifeBuoy,
    PackageCheck,
    Plug,
    Settings2,
    Siren,
    Timer,
} from 'lucide-react';

export interface ItNavigationItem {
    label: string;
    href: string;
    icon: string;
}

export interface ItNavigationGroup {
    label: string;
    items: ItNavigationItem[];
}

interface Props {
    groups: ItNavigationGroup[];
    currentUrl: string;
}

const icons: Record<string, LucideIcon> = {
    activity: Activity,
    'book-open': BookOpen,
    'book-open-check': BookOpenCheck,
    'calendar-clock': CalendarClock,
    'chart-no-axes-column': ChartNoAxesColumn,
    'circle-user-round': CircleUserRound,
    inbox: Inbox,
    'layout-dashboard': LayoutDashboard,
    library: Library,
    'life-buoy': LifeBuoy,
    'package-check': PackageCheck,
    plug: Plug,
    'settings-2': Settings2,
    siren: Siren,
    timer: Timer,
};

function isActive(currentUrl: string, href: string): boolean {
    const current = new URL(currentUrl, 'https://oblivion.local');
    const target = new URL(href, 'https://oblivion.local');
    const currentPath = current.pathname.replace(/\/$/, '');
    const targetPath = target.pathname.replace(/\/$/, '');

    if (target.searchParams.size > 0) {
        if (currentPath !== targetPath) {
            return false;
        }

        return [...target.searchParams].every(
            ([key, value]) => current.searchParams.get(key) === value,
        );
    }
    if (targetPath === '/it') {
        return currentPath === '/it' && current.searchParams.size === 0;
    }

    return (
        currentPath === targetPath || currentPath.startsWith(`${targetPath}/`)
    );
}

export function ItSideNavigation({ groups, currentUrl }: Props) {
    return (
        <nav aria-label="IT & Support" className="space-y-5">
            {groups.map((group) => (
                <section
                    key={group.label}
                    aria-labelledby={`it-nav-${group.label.replace(/\s+/g, '-').toLowerCase()}`}
                >
                    <h2
                        id={`it-nav-${group.label.replace(/\s+/g, '-').toLowerCase()}`}
                        className="px-3 text-[10px] font-bold tracking-[0.13em] text-muted-foreground uppercase"
                    >
                        {group.label}
                    </h2>
                    <ul className="mt-1.5 space-y-1">
                        {group.items.map((item) => {
                            const Icon = icons[item.icon] ?? Settings2;
                            const active = isActive(currentUrl, item.href);

                            return (
                                <li key={`${group.label}-${item.label}`}>
                                    <Link
                                        href={item.href}
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        className={`frontline-focus flex min-h-11 items-center gap-3 rounded-xl px-3 text-[13px] font-medium transition-colors ${
                                            active
                                                ? 'bg-primary text-primary-foreground shadow-sm'
                                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                        }`}
                                    >
                                        <Icon
                                            className="h-4 w-4 flex-none"
                                            aria-hidden="true"
                                        />
                                        <span>{item.label}</span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            ))}
        </nav>
    );
}
