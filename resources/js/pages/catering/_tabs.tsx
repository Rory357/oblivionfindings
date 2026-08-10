import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { CalendarDays, Info } from 'lucide-react';
import { useEffect, useState, type ComponentType } from 'react';

export type CateringTabKey = 'meal-planner' | 'recipes' | 'products' | 'tags';

type Tab = {
    key: CateringTabKey;
    label: string;
    href: string;
    icon: ComponentType<{ className?: string }>;
    description?: string;
};

// Recipes / Products / Tags are now managed inside the Meal Planner — only the
// planner remains in the tab bar to kill the orphaned cross-links (P2-16).
const TABS: Tab[] = [
    {
        key: 'meal-planner',
        label: 'Meal Planner',
        href: '/catering',
        icon: CalendarDays,
        description: 'Plan meals, inventory & shopping',
    },
];

/** Deprecation banner shown on the legacy library index pages. */
export function LibraryDeprecationNotice({ thing }: { thing: string }) {
    return (
        <div className="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-3">
            <Info className="h-4 w-4 shrink-0 text-primary" />
            <span className="flex-1 text-sm text-foreground">
                {thing} are now managed inside the <strong>Meal Planner</strong>{' '}
                — open the planner to make changes.
            </span>
            <Button asChild size="sm" variant="outline">
                <Link href="/catering">Open Meal Planner</Link>
            </Button>
        </div>
    );
}

const CACHE_KEY = 'catering:library-counts';

function readCachedCounts(): Partial<Record<CateringTabKey, number>> | null {
    if (typeof window === 'undefined') return null;
    try {
        const raw = sessionStorage.getItem(CACHE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function writeCachedCounts(c: Partial<Record<CateringTabKey, number>>): void {
    if (typeof window === 'undefined') return;
    try {
        sessionStorage.setItem(CACHE_KEY, JSON.stringify(c));
    } catch {
        // sessionStorage may be unavailable in private mode — ignore
    }
}

export function CateringTabs({
    active,
    counts,
}: {
    active: CateringTabKey;
    counts?: Partial<Record<CateringTabKey, number>>;
}) {
    // Pages may pass their own counts; otherwise we read the cache
    // immediately (so the badges show instantly across navigations)
    // and refresh in the background.
    const [fetched, setFetched] = useState<Partial<
        Record<CateringTabKey, number>
    > | null>(() => readCachedCounts());

    useEffect(() => {
        if (counts) {
            writeCachedCounts(counts);
            return;
        }
        let cancelled = false;
        axios
            .get('/catering/library-counts')
            .then((res) => {
                if (cancelled || !res.data) return;
                setFetched(res.data);
                writeCachedCounts(res.data);
            })
            .catch(() => {
                // Keep showing the cached value if the refresh fails
            });
        return () => {
            cancelled = true;
        };
    }, [counts]);

    const effective = counts ?? fetched ?? {};

    return (
        <nav className="scrollbar-pretty mb-4 flex w-full gap-1 overflow-x-auto rounded-none border-b bg-transparent p-0 pb-2">
            {TABS.map((tab) => {
                const Icon = tab.icon;
                const isActive = active === tab.key;
                const count = effective[tab.key];
                return (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        className={`inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                            isActive
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-transparent text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                        }`}
                        title={tab.description}
                    >
                        <Icon className="h-4 w-4" />
                        {tab.label}
                        {count !== undefined && count > 0 && (
                            <Badge
                                variant="outline"
                                className="ml-1 px-1.5 py-0 text-xs"
                            >
                                {count}
                            </Badge>
                        )}
                    </Link>
                );
            })}
        </nav>
    );
}
