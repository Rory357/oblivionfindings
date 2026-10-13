import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    BarChart3,
    CalendarDays,
    ClipboardCheck,
    UsersRound,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { hubRequest } from './_api';
import { CreateHubPageDialog } from './_dialogs';
import { SearchInsights, type HubSearchInsightData } from './_search-insights';

type InsightData = {
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
    const [days, setDays] = useState(28);
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
                <select
                    aria-label="Insights period"
                    className="hub-native-select"
                    value={days}
                    onChange={(e) => setDays(Number(e.target.value))}
                >
                    {[7, 28, 90].map((period) => (
                        <option value={period} key={period}>
                            Last {period} days
                        </option>
                    ))}
                </select>
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
                                        (n, p) => n + p.readers,
                                        0,
                                    ),
                                    detail: 'One reader counted once per page',
                                    icon: BarChart3,
                                },
                                {
                                    label: 'Saved for later',
                                    count: data.pages.reduce(
                                        (n, p) => n + p.saves,
                                        0,
                                    ),
                                    detail: 'Current saves added in this period',
                                    icon: ClipboardCheck,
                                },
                            ].map(({ label, count, detail, icon: Icon }) => (
                                <article key={label} className="hub-insight">
                                    <Icon className="mb-2 size-5 text-primary" />
                                    <span>{label}</span>
                                    <strong>{count}</strong>
                                    <small className="text-muted-foreground">
                                        {detail}
                                    </small>
                                </article>
                            ))}
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
                            {data.pages.some((p) => p.readers > 0) ? (
                                data.pages
                                    .filter((p) => p.readers > 0)
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
                                                    {page.title}
                                                </Link>
                                                <strong>{page.readers}</strong>
                                            </div>
                                            <div className="hub-read-track">
                                                <span
                                                    style={{
                                                        width: `${(page.readers / top) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {page.saves} saved ·{' '}
                                                {page.comments} comments ·{' '}
                                                {page.reactions} reactions
                                            </p>
                                        </div>
                                    ))
                            ) : (
                                <p className="hub-empty-note">
                                    No recorded readers in this period yet.
                                </p>
                            )}
                            <p className="mt-5 text-xs text-muted-foreground">
                                Reads count unique signed-in staff per page
                                whose most recent visit falls within this
                                period, including earlier published versions.
                                Repeated visits are counted once.
                            </p>
                        </section>
                        {data.searches && (
                            <SearchInsights
                                data={data.searches}
                                onDraftAnswer={
                                    canCreate ? setAnswerTitle : undefined
                                }
                            />
                        )}
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
                        <section className="hub-insight-panel">
                            <h3 className="font-semibold">
                                Event participation
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Current registrations for events you manage in
                                the next 90 days, shown for each occurrence.
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
                                            {formatDateTime(event.start)}
                                        </p>
                                    </div>
                                    <span className="text-sm">
                                        {event.going}
                                        {event.capacity !== null
                                            ? ` / ${event.capacity}`
                                            : ''}{' '}
                                        registered
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {event.waiting} waiting
                                    </span>
                                </div>
                            ))}
                            {!data.events.length && (
                                <p className="hub-empty-note">
                                    No upcoming events with registration enabled
                                    in your management view.
                                </p>
                            )}
                        </section>
                        <details className="hub-insight-panel">
                            <summary className="cursor-pointer font-medium">
                                Activity for all editable pages
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
                                            ].map((label) => (
                                                <th
                                                    className="border-b py-3 font-medium text-muted-foreground"
                                                    key={label}
                                                >
                                                    {label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.pages.map((page) => (
                                            <tr
                                                className="border-b last:border-0"
                                                key={page.id}
                                            >
                                                <td className="py-3">
                                                    <Link
                                                        className="text-primary"
                                                        href={`/company-hub/manage/${page.id}/edit`}
                                                    >
                                                        {page.title}
                                                    </Link>
                                                </td>
                                                {[
                                                    page.readers,
                                                    page.saves,
                                                    page.comments,
                                                    page.reactions,
                                                    page.helpful,
                                                ].map((count, index) => (
                                                    <td key={index}>{count}</td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <p className="mt-3 text-xs text-muted-foreground">
                                Comments and feedback are visible, currently
                                retained records created during the selected
                                period. No reader identities or individual poll
                                answers are shown.
                            </p>
                        </details>
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
