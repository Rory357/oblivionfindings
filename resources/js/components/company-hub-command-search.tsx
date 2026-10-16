import { Button } from '@/components/ui/button';
import { CommandGroup, CommandItem } from '@/components/ui/command';
import {
    BookOpen,
    Building2,
    FileText,
    Loader2,
    Newspaper,
    Search,
    UsersRound,
    Wrench,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type HubMatch = {
    id: string;
    kind: string;
    title: string;
    description: string;
    url: string;
};
type Lookup = {
    incomplete?: boolean;
    actorId: number;
    query: string;
    state: 'loading' | 'ready' | 'error';
    results: HubMatch[];
};
const destinations = [
    {
        label: 'Company news',
        description: 'Stories from across the company',
        view: 'news',
        icon: Newspaper,
    },
    {
        label: 'Find a colleague',
        description: 'People, shared skills and work contacts',
        view: 'people',
        icon: UsersRound,
    },
    {
        label: 'Get help',
        description: 'Staff services and your requests',
        view: 'services',
        icon: Wrench,
    },
    {
        label: 'Knowledge library',
        description: 'Guides, files and useful links',
        view: 'resources',
        icon: BookOpen,
    },
    {
        label: 'Department spaces',
        description: 'Meet your teams',
        view: 'teams',
        icon: Building2,
    },
];

export function HubCommandResults({
    query,
    actorId,
    open,
    onSelect,
}: {
    query: string;
    actorId: number;
    open: boolean;
    onSelect: (url: string) => void;
}) {
    const q = query.trim().slice(0, 150);
    const [lookup, setLookup] = useState<Lookup | null>(null);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        if (!open || q.length < 2) return;
        const controller = new AbortController();
        setLookup({ actorId, query: q, state: 'loading', results: [] });
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(
                    `/company-hub/search?query=${encodeURIComponent(q)}`,
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    },
                );
                if (!response.ok) throw new Error('Search unavailable');
                const result = (await response.json()) as {
                    document_text_incomplete?: boolean;
                    actor_id: number;
                    results: HubMatch[];
                };
                if (Number(result.actor_id) !== actorId)
                    throw new Error('Session changed');
                if (!controller.signal.aborted)
                    setLookup({
                        actorId,
                        query: q,
                        state: 'ready',
                        results: result.results,
                        incomplete: result.document_text_incomplete === true,
                    });
            } catch {
                if (!controller.signal.aborted)
                    setLookup({
                        actorId,
                        query: q,
                        state: 'error',
                        results: [],
                    });
            }
        }, 350);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [q, actorId, open, retry]);
    const current =
        lookup?.actorId === actorId && lookup.query === q ? lookup : null;
    if (!open) return null;
    if (!q)
        return (
            <CommandGroup heading="Explore Company Hub">
                {destinations.map((item) => (
                    <CommandItem
                        key={item.view}
                        value={`hub-${item.view}`}
                        onSelect={() =>
                            onSelect(`/company-hub?view=${item.view}`)
                        }
                    >
                        <item.icon className="size-4 opacity-70" />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium">{item.label}</p>
                            <p className="truncate text-xs text-muted-foreground">
                                {item.description}
                            </p>
                        </div>
                    </CommandItem>
                ))}
            </CommandGroup>
        );
    if (q.length < 2)
        return (
            <p className="px-4 py-3 text-xs text-muted-foreground">
                Type at least two characters to find Hub content, colleagues and
                services.
            </p>
        );
    if (!current || current.state === 'loading')
        return (
            <p
                role="status"
                className="flex items-center gap-2 px-4 py-3 text-sm text-muted-foreground"
            >
                <Loader2 className="size-4 animate-spin" />
                Searching Company Hub…
            </p>
        );
    if (current.state === 'error')
        return (
            <div
                role="alert"
                className="flex items-center justify-between gap-3 px-4 py-3"
            >
                <p className="text-sm text-muted-foreground">
                    Company Hub search is unavailable. Please try again.
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setRetry((value) => value + 1)}
                >
                    Retry Hub search
                </Button>
            </div>
        );
    return (
        <CommandGroup heading="Company Hub results" forceMount>
            {current.incomplete && (
                <p
                    role="status"
                    className="px-3 py-3 text-xs text-muted-foreground"
                >
                    Some PDF text could not be searched. Try its title or browse
                    Resources.
                </p>
            )}
            {current.results.map((item) => {
                const Icon =
                    item.kind === 'Person'
                        ? UsersRound
                        : item.kind === 'Service'
                          ? Wrench
                          : item.kind === 'Story'
                            ? Newspaper
                            : FileText;
                return (
                    <CommandItem
                        key={item.id}
                        value={`hub:${item.id}`}
                        forceMount
                        onSelect={() => onSelect(item.url)}
                    >
                        <Icon className="size-4 opacity-70" />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">
                                {item.title}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {item.kind}
                                {item.description
                                    ? ` · ${item.description}`
                                    : ''}
                            </p>
                        </div>
                    </CommandItem>
                );
            })}
            {current.results.length === 0 && (
                <p
                    role="status"
                    className="px-3 py-3 text-sm text-muted-foreground"
                >
                    No matching Hub content, colleagues or services.
                </p>
            )}
            <CommandItem
                value="hub:all-results"
                forceMount
                onSelect={() =>
                    onSelect(
                        `/company-hub?view=home&search=${encodeURIComponent(q)}`,
                    )
                }
            >
                <Search className="size-4 opacity-70" />
                <span>See all Hub results for “{q}”</span>
            </CommandItem>
        </CommandGroup>
    );
}
