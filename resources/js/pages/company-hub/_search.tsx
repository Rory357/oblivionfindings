import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    BookOpen,
    CalendarDays,
    FileText,
    HeartHandshake,
    Lightbulb,
    Megaphone,
    Search,
    UsersRound,
    Wrench,
} from 'lucide-react';
import { useHubListControls } from './_reading-navigation';
import type { HubEvent, HubProps } from './_types';

type Result = {
    id: string;
    title: string;
    summary: string;
    meta: string;
    url?: string;
    event?: HubEvent;
    external?: boolean;
};
const plain = (value: string | null | undefined) =>
    (value ?? '').replace(/<[^>]*>/g, '').trim();
const destination = (view: string, query: string) =>
    `/company-hub?view=${view}&search=${encodeURIComponent(query.slice(0, 150))}`;

export function HubSearchResults({
    data,
    onEvent,
}: {
    data: HubProps;
    onEvent: (event: HubEvent) => void;
}) {
    const [controls, setControls] = useHubListControls('search', {
        query: data.search,
        filter: 'all',
        limit: 20,
        preview: '',
    });
    const { filter, limit, preview } =
        controls.query === data.search
            ? controls
            : { filter: 'all', limit: 20, preview: '' };
    const setFilter = (filter: string) =>
        setControls({ query: data.search, filter, limit: 20, preview: '' });
    const setLimit = (limit: number) =>
        setControls({ query: data.search, filter, limit, preview });
    const groups: Array<{
        id: string;
        label: string;
        icon: typeof Search;
        items: Result[];
    }> = [
        {
            id: 'pages',
            label: 'Stories & pages',
            icon: FileText,
            items: data.pages.map((p) => ({
                id: `page:${p.id}`,
                title: p.title,
                summary: p.summary,
                meta: [p.category, p.owner].filter(Boolean).join(' · '),
                url: data.demo
                    ? `/company-hub/showcase?story=${p.slug}`
                    : `/company-hub/read/${p.slug}`,
            })),
        },
        {
            id: 'resources',
            label: 'Files & links',
            icon: BookOpen,
            items: (data.resourceItems ?? []).map((p) => ({
                id: p.resource_key!,
                title: p.title,
                summary: p.summary,
                meta: [p.resource_type, p.collection]
                    .filter(Boolean)
                    .join(' · '),
                url: p.reader_url ?? p.resource_url,
                external: !p.reader_url,
            })),
        },
        {
            id: 'people',
            label: 'People',
            icon: UsersRound,
            items: (data.people ?? []).map((p) => ({
                id: `person:${p.id}`,
                title: p.name,
                summary: [p.position, p.department, p.site]
                    .filter(Boolean)
                    .join(' · '),
                meta: p.shared_profile?.help_with.join(' · ') || 'Work contact',
                url: data.demo
                    ? '/company-hub/showcase?story=sample-team'
                    : `/company-hub/people/${p.id}`,
            })),
        },
        {
            id: 'services',
            label: 'Services',
            icon: Wrench,
            items: (data.searchServices ?? []).map((s) => ({
                id: `service:${s.id}`,
                title: s.name,
                summary: plain(s.description),
                meta: s.category.replace(/_/g, ' '),
                url: s.url ?? destination('services', s.name),
            })),
        },
        {
            id: 'events',
            label: 'Upcoming events',
            icon: CalendarDays,
            items: data.events.map((e) => ({
                id: e.id,
                title: e.title,
                summary: plain(e.extendedProps.description),
                meta: [
                    e.extendedProps.cancellation?.cancelled
                        ? 'Cancelled'
                        : null,
                    formatDateTime(e.start),
                    e.extendedProps.location,
                ]
                    .filter(Boolean)
                    .join(' · '),
                event: e,
            })),
        },
        {
            id: 'shoutouts',
            label: 'Shout-outs',
            icon: HeartHandshake,
            items: data.kudos.map((k) => ({
                id: `kudos:${k.id}`,
                title: [k.to || 'A colleague', k.category]
                    .filter(Boolean)
                    .join(' · '),
                summary: k.message,
                meta: `From ${k.from}`,
                url: data.demo
                    ? '/company-hub/showcase?view=shoutouts'
                    : `/company-hub?view=shoutouts&recognition=${k.id}#kudos-${k.id}`,
            })),
        },
        {
            id: 'ideas',
            label: 'Ideas',
            icon: Lightbulb,
            items: (data.ideas ?? []).map((i) => ({
                id: `idea:${i.id}`,
                title: i.title,
                summary: i.body,
                meta: `${i.category} · ${i.status.replace(/_/g, ' ')}`,
                url: data.demo
                    ? '/company-hub/showcase?view=ideas'
                    : `/company-hub?view=ideas&idea=${i.id}`,
            })),
        },
        {
            id: 'announcements',
            label: 'Announcements',
            icon: Megaphone,
            items: data.announcements.map((a) => ({
                id: `notice:${a.id}`,
                title: a.title,
                summary: plain(a.content),
                meta: a.requires_acknowledgement
                    ? 'Read confirmation requested'
                    : 'Company announcement',
                url: `/company-hub?view=updates&announcement=${a.id}`,
            })),
        },
    ];
    const total = groups.reduce((n, group) => n + group.items.length, 0);
    const choose = setFilter;
    return (
        <section
            className="hub-search-results space-y-6"
            aria-label="Company Hub search results"
        >
            <div
                className="flex flex-wrap items-center gap-2"
                aria-label="Filter search results"
            >
                <Button
                    variant={filter === 'all' ? 'default' : 'outline'}
                    aria-pressed={filter === 'all'}
                    onClick={() => choose('all')}
                >
                    All results <span>{total}</span>
                </Button>
                {groups
                    .filter((g) => g.items.length > 0)
                    .map((g) => (
                        <Button
                            key={g.id}
                            variant={filter === g.id ? 'default' : 'outline'}
                            aria-pressed={filter === g.id}
                            onClick={() => choose(g.id)}
                        >
                            <g.icon className="size-4" />
                            {g.label} <span>{g.items.length}</span>
                        </Button>
                    ))}
            </div>
            {data.document_text_incomplete && (
                <p
                    role="status"
                    className="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
                >
                    Some PDF text could not be searched. Try the document’s
                    title or browse Resources.
                </p>
            )}
            {!total ? (
                <Card className="items-center gap-0 px-8 py-12 text-center">
                    <Search className="mx-auto mb-4 size-7 text-muted-foreground" />
                    <h2 className="text-lg font-semibold">
                        No matching results yet
                    </h2>
                    <p className="mx-auto mt-2 max-w-lg text-sm text-muted-foreground">
                        Try a shorter phrase, a colleague’s name or the service
                        you need. Search includes content available to you and
                        events in the next 90 days.
                    </p>
                    <Button className="mt-5" variant="outline" asChild>
                        <Link
                            href={
                                data.demo
                                    ? '/company-hub/showcase'
                                    : '/company-hub?view=home'
                            }
                        >
                            Back to the hub
                        </Link>
                    </Button>
                </Card>
            ) : (
                groups
                    .filter(
                        (g) =>
                            g.items.length > 0 &&
                            (filter === 'all' || filter === g.id),
                    )
                    .map((group) => (
                        <section
                            key={group.id}
                            aria-label={`${group.label} results`}
                            className="overflow-hidden rounded-xl border bg-card"
                        >
                            <div className="flex items-center justify-between border-b px-5 py-4">
                                <h2 className="flex items-center gap-2 font-semibold">
                                    <group.icon className="size-4 text-primary" />
                                    {group.label}
                                    <span className="ml-1 text-sm font-normal text-muted-foreground">
                                        {group.items.length}
                                    </span>
                                </h2>
                                {filter === 'all' && group.items.length > 4 && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => choose(group.id)}
                                    >
                                        See all {group.items.length}
                                        <ArrowUpRight className="size-4" />
                                    </Button>
                                )}
                            </div>
                            <div className="divide-y">
                                {group.items
                                    .slice(0, filter === 'all' ? 4 : limit)
                                    .map((item) => {
                                        const content = (
                                            <>
                                                <div className="min-w-0 flex-1">
                                                    <h3 className="font-medium text-foreground">
                                                        {item.title}
                                                    </h3>
                                                    <span className="mt-2 block text-xs font-normal text-muted-foreground">
                                                        {item.meta}
                                                    </span>
                                                </div>
                                                <ArrowUpRight className="size-4 shrink-0 text-primary" />
                                            </>
                                        );
                                        const style =
                                            'flex w-full items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-accent/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring';
                                        const opener = item.event ? (
                                            <Button
                                                variant="ghost"
                                                type="button"
                                                key={item.id}
                                                data-hub-event-key={
                                                    item.event.id
                                                }
                                                className={`${style} h-auto rounded-none whitespace-normal`}
                                                onClick={() =>
                                                    onEvent(item.event!)
                                                }
                                            >
                                                {content}
                                            </Button>
                                        ) : item.external ? (
                                            <a
                                                key={item.id}
                                                className={style}
                                                href={item.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                {content}
                                            </a>
                                        ) : (
                                            <Link
                                                key={item.id}
                                                className={style}
                                                href={item.url!}
                                            >
                                                {content}
                                            </Link>
                                        );
                                        const expanded = preview === item.id;
                                        const previewId = `hub-search-preview-${group.id}-${item.id}`;
                                        return (
                                            <div key={item.id}>
                                                <div className="flex items-center gap-3 pr-5">
                                                    <div className="min-w-0 flex-1">
                                                        {opener}
                                                    </div>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-expanded={expanded}
                                                        aria-controls={
                                                            previewId
                                                        }
                                                        aria-label={`${expanded ? 'Hide preview' : 'Preview'}: ${item.title}`}
                                                        onClick={() =>
                                                            setControls({
                                                                query: data.search,
                                                                filter,
                                                                limit,
                                                                preview:
                                                                    expanded
                                                                        ? ''
                                                                        : item.id,
                                                            })
                                                        }
                                                    >
                                                        {expanded
                                                            ? 'Hide preview'
                                                            : 'Preview'}
                                                    </Button>
                                                </div>
                                                {expanded && (
                                                    <div
                                                        id={previewId}
                                                        role="region"
                                                        aria-label={`Preview of ${item.title}`}
                                                        className="mx-5 mb-5 border-l-2 border-primary/25 pl-4 text-sm leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap text-muted-foreground"
                                                    >
                                                        {item.summary ||
                                                            'Open this result to view the available details.'}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                            </div>
                            {filter !== 'all' && group.items.length > limit && (
                                <div className="border-t p-3 text-center">
                                    <Button
                                        variant="ghost"
                                        onClick={() => setLimit(limit + 20)}
                                    >
                                        Show more results
                                    </Button>
                                </div>
                            )}
                        </section>
                    ))
            )}
        </section>
    );
}
