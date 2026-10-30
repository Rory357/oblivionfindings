import { Button } from '@/components/ui/button';
import { Tabs } from '@/components/ui/tabs';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    BarChart3,
    CalendarDays,
    ClipboardCheck,
    UsersRound,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { hubRequest } from './_api';
import { HubBrowseSelect } from './_browse-select';
import { CreateHubPageDialog } from './_dialogs';
import { useHubPageCreation } from './_page-creation-context';
import { readerLocation } from './_reader-trail';
import { useHubListControls } from './_reading-navigation';
import { SearchInsights, type HubSearchInsightData } from './_search-insights';

type InsightData = {
    ideas?: Array<{
        id: number;
        title: string;
        status: string;
        support_count: number;
        supports_in_period: number;
        url?: string | null;
    }>;
    polls?: Array<{
        page_id: number;
        block_id: string;
        key: string;
        question: string;
        closed: boolean;
        results_visible: boolean;
        response_count: number | null;
        responses_in_period: number | null;
        url?: string | null;
    }>;
    searches?: HubSearchInsightData | null;
    days: number;
    since: string;
    until: string;
    readers: number;
    pages: {
        id: number;
        title: string;
        readers: number;
        saves: number;
        comments: number;
        reactions: number;
        helpful: number;
    }[];
    health: { reports: number; overdue: number; review: number };
    events: {
        id: string;
        title: string;
        start: string;
        going: number;
        waiting: number;
        capacity: number | null;
        url?: string | null;
    }[];
};
export function EditorialInsights({
    onResources,
    onCalendar,
    canCreate = false,
}: {
    onResources: () => void;
    onCalendar: () => void;
    canCreate?: boolean;
}) {
    const [answerTitle, setAnswerTitle] = useState<string | null>(null);
    const openCreator = useHubPageCreation();
    const [{ days, section }, setPeriod] = useHubListControls('insights', {
        days: 28,
        section: 'engagement',
    });
    const setDays = (days: number) =>
        setPeriod((previous) => ({ ...previous, days }));
    const [data, setData] = useState<InsightData | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [reload, setReload] = useState(0);
    useEffect(() => {
        let active = true;
        setLoading(true);
        setError('');
        hubRequest<InsightData>(
            `/company-hub/editor/insights?days=${days}`,
            'GET',
        )
            .then((result) => {
                if (active) setData(result);
            })
            .catch((err: Error) => {
                if (active) setError(err.message);
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [days, reload]);
    const top = Math.max(1, ...(data?.pages.map((p) => p.readers) ?? []));
    return (
        <div className="space-y-5">
            <div className="hub-planning-intro">
                <div>
                    <p className="text-xs font-semibold tracking-widest text-primary uppercase">
                        Insights
                    </p>
                    <h2>Understand what helps your people.</h2>
                    <p>
                        Recorded activity for pages you can edit. Individual
                        responses remain private.
                    </p>
                </div>
                <HubBrowseSelect
                    label="Insights period"
                    value={String(days)}
                    onChange={(value) => setDays(Number(value))}
                    options={[7, 28, 90].map((period) => ({
                        value: String(period),
                        label: `Last ${period} days`,
                    }))}
                />
            </div>
            {loading ? (
                <p role="status" className="hub-empty-note">
                    Loading activity…
                </p>
            ) : error ? (
                <div role="alert" className="hub-empty-note">
                    {error}{' '}
                    <Button
                        variant="outline"
                        onClick={() => setReload((n) => n + 1)}
                    >
                        Retry
                    </Button>
                </div>
            ) : (
                data && (
                    <>
                        <p className="text-xs text-muted-foreground">
                            {formatDate(data.since)} – {formatDate(data.until)}{' '}
                            · Updated {formatDateTime(data.until)}
                        </p>
                        <Tabs
                            value={section}
                            onValueChange={(section) =>
                                setPeriod((previous) => ({
                                    ...previous,
                                    section,
                                }))
                            }
                            listClassName="w-fit gap-1 border-border/70 bg-muted/40 p-1.5"
                            tabs={[
                                {
                                    key: 'engagement',
                                    label: 'Engagement',
                                    content: (
                                        <div className="space-y-5">
                                            <div className="hub-insights-grid">
                                                {[
                                                    {
                                                        label: 'Readers',
                                                        count: data.readers,
                                                        detail: 'Unique staff across editable pages',
                                                        icon: UsersRound,
                                                    },
                                                    {
                                                        label: 'Page reads',
                                                        count: data.pages.reduce(
                                                            (n, p) =>
                                                                n + p.readers,
                                                            0,
                                                        ),
                                                        detail: 'One reader counted once per page',
                                                        icon: BarChart3,
                                                    },
                                                    {
                                                        label: 'Saved for later',
                                                        count: data.pages.reduce(
                                                            (n, p) =>
                                                                n + p.saves,
                                                            0,
                                                        ),
                                                        detail: 'Current saves added in this period',
                                                        icon: ClipboardCheck,
                                                    },
                                                ].map(
                                                    ({
                                                        label,
                                                        count,
                                                        detail,
                                                        icon: Icon,
                                                    }) => (
                                                        <article
                                                            key={label}
                                                            className="hub-insight"
                                                        >
                                                            <Icon className="mb-2 size-5 text-primary" />
                                                            <span>{label}</span>
                                                            <strong>
                                                                {count}
                                                            </strong>
                                                            <small className="text-muted-foreground">
                                                                {detail}
                                                            </small>
                                                        </article>
                                                    ),
                                                )}
                                            </div>
                                            <section className="hub-insight-panel">
                                                <div className="mb-5 flex items-center justify-between">
                                                    <h3 className="text-lg font-semibold">
                                                        Pages people explore
                                                    </h3>
                                                    <span className="text-xs text-muted-foreground">
                                                        Last {days} days
                                                    </span>
                                                </div>
                                                {data.pages.some(
                                                    (p) => p.readers > 0,
                                                ) ? (
                                                    data.pages
                                                        .filter(
                                                            (p) =>
                                                                p.readers > 0,
                                                        )
                                                        .slice(0, 5)
                                                        .map((page) => (
                                                            <div
                                                                className="hub-read-bar"
                                                                key={page.id}
                                                            >
                                                                <div className="flex justify-between gap-3">
                                                                    <Link
                                                                        href={`/company-hub/manage/${page.id}/edit`}
                                                                        className="font-medium hover:text-primary"
                                                                    >
                                                                        {
                                                                            page.title
                                                                        }
                                                                    </Link>
                                                                    <strong>
                                                                        {
                                                                            page.readers
                                                                        }
                                                                    </strong>
                                                                </div>
                                                                <div className="hub-read-track">
                                                                    <span
                                                                        style={{
                                                                            width: `${(page.readers / top) * 100}%`,
                                                                        }}
                                                                    />
                                                                </div>
                                                                <p className="text-xs text-muted-foreground">
                                                                    {page.saves}{' '}
                                                                    saved ·{' '}
                                                                    {
                                                                        page.comments
                                                                    }{' '}
                                                                    comments ·{' '}
                                                                    {
                                                                        page.reactions
                                                                    }{' '}
                                                                    reactions
                                                                </p>
                                                            </div>
                                                        ))
                                                ) : (
                                                    <p className="hub-empty-note">
                                                        No recorded readers in
                                                        this period yet.
                                                    </p>
                                                )}
                                                <p className="mt-5 text-xs text-muted-foreground">
                                                    Reads count unique signed-in
                                                    staff per page whose most
                                                    recent visit falls within
                                                    this period, including
                                                    earlier published versions.
                                                    Repeated visits are counted
                                                    once.
                                                </p>
                                            </section>
                                            <details className="hub-insight-panel">
                                                <summary className="cursor-pointer font-medium">
                                                    Activity for all editable
                                                    pages
                                                </summary>
                                                <div className="mt-4 overflow-x-auto">
                                                    <table className="w-full text-left text-sm">
                                                        <thead>
                                                            <tr>
                                                                {[
                                                                    'Page',
                                                                    'Readers',
                                                                    'Saves',
                                                                    'Comments',
                                                                    'Reactions',
                                                                    'Helpful',
                                                                ].map(
                                                                    (label) => (
                                                                        <th
                                                                            className="border-b py-3 font-medium text-muted-foreground"
                                                                            key={
                                                                                label
                                                                            }
                                                                        >
                                                                            {
                                                                                label
                                                                            }
                                                                        </th>
                                                                    ),
                                                                )}
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {data.pages.map(
                                                                (page) => (
                                                                    <tr
                                                                        className="border-b last:border-0"
                                                                        key={
                                                                            page.id
                                                                        }
                                                                    >
                                                                        <td className="py-3">
                                                                            <Link
                                                                                className="text-primary"
                                                                                href={`/company-hub/manage/${page.id}/edit`}
                                                                            >
                                                                                {
                                                                                    page.title
                                                                                }
                                                                            </Link>
                                                                        </td>
                                                                        {[
                                                                            page.readers,
                                                                            page.saves,
                                                                            page.comments,
                                                                            page.reactions,
                                                                            page.helpful,
                                                                        ].map(
                                                                            (
                                                                                count,
                                                                                index,
                                                                            ) => (
                                                                                <td
                                                                                    key={
                                                                                        index
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        count
                                                                                    }
                                                                                </td>
                                                                            ),
                                                                        )}
                                                                    </tr>
                                                                ),
                                                            )}
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <p className="mt-3 text-xs text-muted-foreground">
                                                    Comments and feedback are
                                                    visible, currently retained
                                                    records created during the
                                                    selected period. No reader
                                                    identities or individual
                                                    poll answers are shown.
                                                </p>
                                            </details>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'participation',
                                    label: 'Participation',
                                    content: (
                                        <div className="space-y-5">
                                            <section className="hub-insight-panel space-y-4">
                                                <h3 className="text-lg font-semibold">
                                                    Ideas and shared voices
                                                </h3>
                                                <p className="text-xs text-muted-foreground">
                                                    Company totals only. Poll
                                                    results appear after your
                                                    own vote or when voting
                                                    closes.
                                                </p>
                                                <div className="grid grid-cols-2 gap-6">
                                                    <div className="space-y-3">
                                                        <h4 className="text-sm font-semibold">
                                                            Ideas gaining
                                                            support
                                                        </h4>
                                                        {(data.ideas ?? []).map(
                                                            (idea) => (
                                                                <div
                                                                    key={
                                                                        idea.id
                                                                    }
                                                                    className="border-b pb-3 last:border-0"
                                                                >
                                                                    <p className="text-sm font-medium">
                                                                        {
                                                                            idea.title
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                                        {
                                                                            idea.status
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {
                                                                            idea.support_count
                                                                        }{' '}
                                                                        supporting
                                                                        ·{' '}
                                                                        {
                                                                            idea.supports_in_period
                                                                        }{' '}
                                                                        added in
                                                                        this
                                                                        period
                                                                    </p>
                                                                    <InsightLink
                                                                        href={
                                                                            idea.url
                                                                        }
                                                                        label="View progress"
                                                                        title={
                                                                            idea.title
                                                                        }
                                                                    />
                                                                </div>
                                                            ),
                                                        )}
                                                        {!data.ideas
                                                            ?.length && (
                                                            <p className="text-sm text-muted-foreground">
                                                                No shared ideas
                                                                to show yet.
                                                            </p>
                                                        )}
                                                        <Link
                                                            href="/company-hub?view=ideas"
                                                            className="text-sm text-primary hover:underline"
                                                        >
                                                            Explore ideas
                                                        </Link>
                                                    </div>
                                                    <div className="space-y-3">
                                                        <h4 className="text-sm font-semibold">
                                                            Poll participation
                                                        </h4>
                                                        {(data.polls ?? []).map(
                                                            (poll) => (
                                                                <div
                                                                    key={`${poll.page_id}-${poll.block_id}-${poll.key}`}
                                                                    className="border-b pb-3 last:border-0"
                                                                >
                                                                    <p className="text-sm font-medium">
                                                                        {
                                                                            poll.question
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                                        {poll.closed
                                                                            ? 'Closed'
                                                                            : 'Open'}{' '}
                                                                        ·{' '}
                                                                        {poll.results_visible &&
                                                                        poll.response_count !==
                                                                            null &&
                                                                        poll.responses_in_period !==
                                                                            null
                                                                            ? `${poll.response_count} responses · ${poll.responses_in_period} in this period`
                                                                            : 'Results available after you vote or voting closes'}
                                                                    </p>
                                                                    <InsightLink
                                                                        href={
                                                                            poll.url
                                                                        }
                                                                        label="View poll"
                                                                        title={
                                                                            poll.question
                                                                        }
                                                                    />
                                                                </div>
                                                            ),
                                                        )}
                                                        {!data.polls
                                                            ?.length && (
                                                            <p className="text-sm text-muted-foreground">
                                                                No published
                                                                polls to show
                                                                yet.
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            </section>
                                            <section className="hub-insight-panel">
                                                <h3 className="font-semibold">
                                                    Event participation
                                                </h3>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    Current registrations for
                                                    events you manage in the
                                                    next 90 days, shown for each
                                                    occurrence.
                                                </p>
                                                {data.events.map((event) => (
                                                    <div
                                                        key={event.id}
                                                        className="hub-participation-row"
                                                    >
                                                        <CalendarDays className="size-4 text-primary" />
                                                        <div className="flex-1">
                                                            <p className="font-medium">
                                                                {event.title}
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {formatDateTime(
                                                                    event.start,
                                                                )}
                                                            </p>
                                                        </div>
                                                        <span className="text-sm">
                                                            {event.going}
                                                            {event.capacity !==
                                                            null
                                                                ? ` / ${event.capacity}`
                                                                : ''}{' '}
                                                            registered
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {event.waiting}{' '}
                                                            waiting
                                                        </span>
                                                        <InsightLink
                                                            href={event.url}
                                                            label="View event"
                                                            title={event.title}
                                                        />
                                                    </div>
                                                ))}
                                                {!data.events.length && (
                                                    <p className="hub-empty-note">
                                                        No upcoming events with
                                                        registration enabled in
                                                        your management view.
                                                    </p>
                                                )}
                                            </section>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'search',
                                    label: 'Search topics',
                                    content: (
                                        <div className="space-y-5">
                                            {data.searches ? (
                                                <SearchInsights
                                                    data={data.searches}
                                                    onDraftAnswer={
                                                        canCreate
                                                            ? (title) =>
                                                                  openCreator
                                                                      ? openCreator(
                                                                            {
                                                                                initial:
                                                                                    {
                                                                                        title,
                                                                                        kind: 'knowledge',
                                                                                        template:
                                                                                            'guide',
                                                                                    },
                                                                            },
                                                                        )
                                                                      : setAnswerTitle(
                                                                            title,
                                                                        )
                                                            : undefined
                                                    }
                                                />
                                            ) : (
                                                <p className="hub-empty-note">
                                                    No grouped search topics are
                                                    available for this period.
                                                    Individual searches are
                                                    never shown here.
                                                </p>
                                            )}
                                        </div>
                                    ),
                                },
                            ]}
                        />
                        <section className="hub-insight-panel">
                            <div className="flex items-center justify-between gap-4">
                                <div>
                                    <h3 className="font-semibold">
                                        Keep the hub useful
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {data.health.reports} open resource
                                        reports · {data.health.overdue} overdue
                                        reviews · {data.health.review} awaiting
                                        approval
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Current workload, independent of the
                                        activity period.
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={onResources}
                                    >
                                        Review resources
                                    </Button>
                                    <Button onClick={onCalendar}>
                                        Plan next update
                                    </Button>
                                </div>
                            </div>
                        </section>
                    </>
                )
            )}
            {canCreate && (
                <CreateHubPageDialog
                    open={answerTitle !== null}
                    close={() => setAnswerTitle(null)}
                    initial={{
                        title: answerTitle ?? '',
                        kind: 'knowledge',
                        template: 'guide',
                    }}
                />
            )}
        </div>
    );
}

function InsightLink({
    href,
    label,
    title,
}: {
    href?: string | null;
    label: string;
    title: string;
}) {
    const destination = readerLocation(href);
    if (!destination) return null;
    return (
        <Link
            href={destination}
            aria-label={`${label}: ${title}`}
            className="mt-2 inline-flex min-h-9 items-center gap-1 text-sm font-medium text-primary hover:underline"
        >
            {label}
            <ArrowUpRight className="size-3.5" aria-hidden="true" />
        </Link>
    );
}
