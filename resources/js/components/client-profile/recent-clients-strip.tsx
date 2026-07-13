import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

type RecentClient = {
    id: number;
    name: string;
    photo?: string | null;
    house?: string | null;
};

type Props = {
    currentClient: RecentClient;
    currentTab: string;
};

const STORAGE_KEY = 'recentClients';
const MAX_RECENT = 8;

function loadRecent(): RecentClient[] {
    if (typeof window === 'undefined') return [];
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        return raw ? (JSON.parse(raw) as RecentClient[]) : [];
    } catch {
        return [];
    }
}

function persistRecent(list: RecentClient[]) {
    if (typeof window === 'undefined') return;
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
    } catch {
        // localStorage may be disabled; fail silently.
    }
}

export default function RecentClientsStrip({ currentClient, currentTab }: Props) {
    const [recents, setRecents] = useState<RecentClient[]>(() => loadRecent());

    useEffect(() => {
        const existing = loadRecent().filter((c) => c.id !== currentClient.id);
        const next = [
            { id: currentClient.id, name: currentClient.name },
            ...existing,
        ].slice(0, MAX_RECENT);
        setRecents(next);
        persistRecent(next);
    }, [currentClient.id, currentClient.name]);

    const others = useMemo(
        () => recents.filter((c) => c.id !== currentClient.id),
        [recents, currentClient.id],
    );

    if (others.length === 0) {
        return null;
    }

    return (
        <div className="flex items-center gap-2 overflow-x-auto pb-1">
            <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
                Recent
            </span>
            {others.map((c) => (
                <Link
                    key={c.id}
                    href={`/operations/clients/${c.id}?tab=${currentTab}`}
                    className="group flex shrink-0 items-center gap-1.5 rounded-full border bg-card px-2 py-1 text-xs hover:border-primary hover:bg-primary/5"
                    title={c.name}
                >
                    <img
                        src={c.photo ?? '/images/avatar-placeholder.svg'}
                        alt={c.name}
                        className="h-5 w-5 rounded-full border object-cover"
                    />
                    <span className="max-w-[140px] truncate font-medium text-foreground">
                        {c.name}
                    </span>
                </Link>
            ))}
        </div>
    );
}
