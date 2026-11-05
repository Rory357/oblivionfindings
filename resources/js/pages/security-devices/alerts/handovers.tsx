/**
 * Alerts › On call › Handover notes (pg-sdr-13d "All handovers"): read-only list of notes left
 * between the people covering IT on call, newest first. Same permission as reading handover notes
 * on the On call tab, scoped to the viewer's Sites.
 */
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { QuietLine, Story, Surface } from '@/components/signature/blocks';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, BellRing } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { OnCallHandoverNote, OnCallHandoversProps } from './_types';

const BASE = '/security-devices/alerts/on-call/handovers';
const ON_CALL_HREF = '/security-devices/alerts?tab=oncall';

function noteParas(note: OnCallHandoverNote) {
    return (
        [
            ['Still open', note.still_open],
            ['Coming up', note.coming_up],
            ['Good to know', note.good_to_know],
        ] as const
    )
        .filter(([, text]) => Boolean(text))
        .map(([h, t]) => ({ h, t: t as string }));
}

export default function OnCallHandoversPage({
    rows,
    meta,
    q,
}: OnCallHandoversProps) {
    const [search, setSearch] = useState(q ?? '');
    const searchTimer = useRef<number | null>(null);

    useEffect(() => () => {
        if (searchTimer.current) window.clearTimeout(searchTimer.current);
    }, []);

    const go = (changes: Record<string, string | number | undefined>) => {
        const next: Record<string, string | number | undefined> = {
            q: q || undefined,
            ...changes,
        };
        const params = Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) => value !== undefined && value !== '',
            ),
        );
        router.get(BASE, params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const updateSearch = (value: string) => {
        setSearch(value);
        if (searchTimer.current) window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(
            () => go({ q: value.trim() || undefined, page: undefined }),
            300,
        );
    };

    const searching = (q ?? '').trim() !== '';

    const header = (
        <PageHeader
            icon={BellRing}
            title="Handover notes"
            subline="Notes left between the people covering IT on call, newest first"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={updateSearch}
                        placeholder="Search handover notes"
                    />
                    <PageHeaderGlassButton icon={ArrowLeft} asChild>
                        <Link href={ON_CALL_HREF}>Back to On call</Link>
                    </PageHeaderGlassButton>
                </>
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: '/security-devices' },
                { title: 'Alerts', href: '/security-devices/alerts' },
                { title: 'On call', href: ON_CALL_HREF },
                { title: 'Handover notes', href: BASE },
            ]}
        >
            <Head title="Handover notes" />
            <PageLayout hero={header}>
                {rows.length === 0 ? (
                    <QuietLine
                        title="Handover notes"
                        text={
                            searching
                                ? 'No handover notes match. Try another search.'
                                : 'No handover notes yet.'
                        }
                        action={
                            searching
                                ? {
                                      label: 'Clear search',
                                      onClick: () => updateSearch(''),
                                  }
                                : {
                                      label: 'Back to On call',
                                      href: ON_CALL_HREF,
                                  }
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-4">
                        {rows.map((row) => (
                            <Story
                                key={row.id}
                                title={`From ${row.from}${row.to ? ` to ${row.to}` : ''}`}
                                sub={formatDateTime(row.at)}
                                paras={noteParas(row)}
                            />
                        ))}
                        <Surface>
                            <div className="flex flex-wrap items-center justify-between gap-3 px-[18px] py-3.5">
                                <span className="text-subtle">
                                    Showing {meta.from}–{meta.to} of{' '}
                                    {meta.total} handover{' '}
                                    {meta.total === 1 ? 'note' : 'notes'}
                                </span>
                                {meta.last_page > 1 ? (
                                    <LaravelPagination
                                        lastPage={meta.last_page}
                                        navigation={{
                                            currentPage: meta.page,
                                            previousPage:
                                                meta.page > 1
                                                    ? meta.page - 1
                                                    : null,
                                            nextPage:
                                                meta.page < meta.last_page
                                                    ? meta.page + 1
                                                    : null,
                                            onPageChange: (page) =>
                                                go({
                                                    page:
                                                        page <= 1
                                                            ? undefined
                                                            : page,
                                                }),
                                        }}
                                    />
                                ) : null}
                            </div>
                        </Surface>
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
