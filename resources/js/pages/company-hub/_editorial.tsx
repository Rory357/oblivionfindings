import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { EditorialInsights } from './_insights';
import { MediaLibrary } from './_media-library';
import { SharedNavigationDesigner } from './_navigation';
import { EditorialCalendar } from './_planning';
import { ResourceCare, type ResourceReport } from './_resource-care';
import type { HubEvent } from './_types';

export type EditorialPage = {
    reports?: ResourceReport[];
    id: number;
    title: string;
    kind: string;
    owner: string;
    version: number;
    status: string;
    show_in_navigation: boolean;
    navigation_order: number;
    planned_for: string | null;
    review_on: string | null;
    publish_at: string | null;
    expires_at: string | null;
    readers: number;
    comments: number;
    reactions: number;
    helpful: number;
};
const statuses: Record<string, string> = {
    draft: 'Draft',
    review: 'Awaiting review',
    changes_requested: 'Changes requested',
    scheduled: 'Scheduled',
    published: 'Published',
    changes: 'Unpublished changes',
    expired: 'Expired',
    archived: 'Archived',
};

export function EditorialWorkspace({
    pages,
    layout,
    canCreate = true,
    onCreate,
    onEvent,
}: {
    pages: EditorialPage[];
    layout: boolean;
    canCreate?: boolean;
    onCreate: () => void;
    onEvent: (event: HubEvent) => void;
}) {
    const [mode, setMode] = useState('pages');
    const [navigationDirty, setNavigationDirty] = useState(false);
    const [mediaDirty, setMediaDirty] = useState(false);
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('all');
    const filtered = pages.filter(
        (p) =>
            (status === 'all' || p.status === status) &&
            `${p.title} ${p.owner}`.toLowerCase().includes(query.toLowerCase()),
    );
    return (
        <section className="space-y-5">
            <div className="flex flex-wrap items-center gap-2">
                {[
                    ['pages', 'Pages & review'],
                    ['calendar', 'Editorial calendar'],
                    ['insights', 'Insights'],
                    ['resources', 'Resource care'],
                    ['media', 'Media'],
                    ...(layout ? [['navigation', 'Navigation']] : []),
                ].map(([key, label]) => (
                    <Button
                        key={key}
                        variant={mode === key ? 'secondary' : 'ghost'}
                        aria-pressed={mode === key}
                        disabled={
                            (navigationDirty && key !== 'navigation') ||
                            (mediaDirty && key !== 'media')
                        }
                        onClick={() => setMode(key)}
                    >
                        {label}
                    </Button>
                ))}
                {canCreate && (
                    <Button
                        className="ml-auto"
                        onClick={onCreate}
                        disabled={navigationDirty || mediaDirty}
                    >
                        <Plus className="size-4" />
                        Create page
                    </Button>
                )}
            </div>
            {mode === 'media' && <MediaLibrary onDirtyChange={setMediaDirty} />}
            {mode === 'pages' && (
                <>
                    <div className="hub-library-tools">
                        <label className="hub-library-search">
                            <Search className="size-4" />
                            <Input
                                aria-label="Search managed pages"
                                placeholder="Find a title or owner…"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </label>
                        <select
                            className="hub-native-select"
                            aria-label="Publishing status"
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                        >
                            <option value="all">All statuses</option>
                            {Object.entries(statuses).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="hub-editorial-list">
                        {filtered.map((p) => (
                            <Link
                                key={p.id}
                                href={`/company-hub/manage/${p.id}/edit`}
                                className="hub-update-row"
                            >
                                <div className="flex-1">
                                    <h3 className="font-medium">{p.title}</h3>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {p.owner} · {p.kind} · Version{' '}
                                        {p.version}
                                    </p>
                                </div>
                                <span className="rounded-full bg-muted px-3 py-1 text-xs">
                                    {statuses[p.status]}
                                </span>
                                <ArrowUpRight className="size-4" />
                            </Link>
                        ))}
                        {!filtered.length && (
                            <p className="hub-empty-note">
                                No pages match this view.
                            </p>
                        )}
                    </div>
                </>
            )}
            {mode === 'calendar' && <EditorialCalendar onEvent={onEvent} />}
            {mode === 'insights' && (
                <EditorialInsights
                    canCreate={canCreate}
                    onResources={() => setMode('resources')}
                    onCalendar={() => setMode('calendar')}
                />
            )}
            {mode === 'navigation' && layout && (
                <SharedNavigationDesigner onDirtyChange={setNavigationDirty} />
            )}
            {mode === 'resources' && <ResourceCare pages={pages} />}
        </section>
    );
}
