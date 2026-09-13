import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardList,
    Eye,
    FileText,
    Layers,
    Pencil,
    Plus,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type Form = {
    id: number;
    name: string;
    form_type: string;
    is_active: boolean;
    submissions_count: number;
    created_at: string;
    created_by: { id: number; name: string } | null;
};

type Props = {
    forms: {
        data: Form[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string | null;
        status?: string | null;
        type?: string | null;
    };
    stats: {
        total: number;
        active: number;
        submissions_this_week: number;
    };
    types?: string[];
};

type ViewKey = 'all' | 'active' | 'inactive';

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function typeLabel(type: string): string {
    return type.replace(/_/g, ' ').replace(/^\w/, (m) => m.toUpperCase());
}

export default function FormsIndex({
    forms,
    filters,
    stats,
    types = [],
}: Props) {
    const s = stats ?? { total: 0, active: 0, submissions_this_week: 0 };
    const inactiveCount = Math.max(0, s.total - s.active);

    const view: ViewKey =
        filters.status === 'active'
            ? 'active'
            : filters.status === 'inactive'
              ? 'inactive'
              : 'all';

    const [q, setQ] = useState(filters.q ?? '');

    const applyFilters = useCallback(
        (overrides: { view?: ViewKey; q?: string; type?: string }) => {
            const nextView = overrides.view ?? view;
            const nextQ = overrides.q ?? filters.q ?? '';
            const nextType = overrides.type ?? filters.type ?? 'all';
            const params: Record<string, string> = {};
            if (nextView !== 'all') params.status = nextView;
            if (nextQ.trim() !== '') params.q = nextQ.trim();
            if (nextType !== 'all') params.type = nextType;
            router.get('/operations/forms', params, {
                preserveState: true,
                replace: true,
            });
        },
        [view, filters.q, filters.type],
    );

    useEffect(() => {
        if ((filters.q ?? '') === q.trim()) return;
        const t = setTimeout(() => applyFilters({ q }), 400);
        return () => clearTimeout(t);
    }, [q, filters.q, applyFilters]);

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        { key: 'all', label: 'All forms', icon: Layers, count: s.total },
        {
            key: 'active',
            label: 'Active',
            icon: CheckCircle2,
            count: s.active,
        },
        {
            key: 'inactive',
            label: 'Inactive',
            icon: XCircle,
            count: inactiveCount,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All forms';

    const hasNarrowing =
        (filters.q ?? '') !== '' || (filters.type ?? 'all') !== 'all';

    const typeOptions = [
        { value: 'all', label: 'All types' },
        ...types.map((t) => ({ value: t, label: typeLabel(t) })),
    ];

    const header = (
        <PageHeader
            icon={FileText}
            title="Forms"
            titleChip={
                <PageHeaderStatusChip
                    variant={s.active > 0 ? 'success' : 'neutral'}
                >
                    {s.active} active
                </PageHeaderStatusChip>
            }
            subline={`Custom data-collection forms · ${s.total} ${
                s.total === 1 ? 'form' : 'forms'
            } · ${s.submissions_this_week} ${
                s.submissions_this_week === 1 ? 'submission' : 'submissions'
            } this week`}
            actions={
                <>
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder="Search forms and types…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => router.visit('/operations/forms/create')}
                    >
                        New form
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total forms"
                        ariaLabel="View all forms"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>{s.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {types.length}{' '}
                            {types.length === 1 ? 'type' : 'types'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {s.total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Active"
                            ariaLabel="View active forms"
                            onClick={() => applyFilters({ view: 'active' })}
                        >
                            <PageHeaderMeterDonut
                                percent={(s.active / s.total) * 100}
                                caption={
                                    <>
                                        {s.active} of {s.total}
                                        <br />
                                        accepting submissions
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="This week"
                        ariaLabel="View forms and their submission counts"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>
                            {s.submissions_this_week}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            submissions across all forms
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={ClipboardList}
                    label="All types"
                    value={filters.type ?? 'all'}
                    options={typeOptions}
                    onChange={(v) => applyFilters({ type: v })}
                />
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={(key) => applyFilters({ view: key })}
                    ariaLabel="Form views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Forms', href: '/operations/forms' },
            ]}
        >
            <Head title="Forms" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${forms.data.length} of ${
                            view === 'active'
                                ? s.active
                                : view === 'inactive'
                                  ? inactiveCount
                                  : s.total
                        } shown`}
                    />

                    <div className="space-y-2">
                        {forms.data.length === 0 ? (
                            <EmptyState
                                icon={FileText}
                                title="No forms found"
                                description={
                                    hasNarrowing || view !== 'all'
                                        ? 'Try a different view or clear your filters.'
                                        : 'Create your first form to start collecting data.'
                                }
                                action={
                                    hasNarrowing ||
                                    view !== 'all' ? undefined : (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.visit(
                                                    '/operations/forms/create',
                                                )
                                            }
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            New form
                                        </Button>
                                    )
                                }
                            />
                        ) : (
                            forms.data.map((form) => (
                                <Card
                                    key={form.id}
                                    className="transition-all hover:border-border hover:shadow-sm"
                                >
                                    <CardContent className="flex items-center gap-4 p-4">
                                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                            <ClipboardList className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link
                                                    href={`/operations/forms/${form.id}`}
                                                    className="text-sm font-semibold hover:underline"
                                                >
                                                    {form.name}
                                                </Link>
                                                <StatusBadge
                                                    variant={
                                                        form.is_active
                                                            ? 'success'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {form.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </StatusBadge>
                                                <Badge
                                                    variant="outline"
                                                    className="h-4 px-1.5 text-[9px] capitalize"
                                                >
                                                    {form.form_type}
                                                </Badge>
                                            </div>
                                            <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                <span>
                                                    {form.submissions_count}{' '}
                                                    submissions
                                                </span>
                                                {form.created_by && (
                                                    <span>
                                                        Created by:{' '}
                                                        {form.created_by.name}
                                                    </span>
                                                )}
                                                <span>
                                                    Created:{' '}
                                                    {formatDate(
                                                        form.created_at,
                                                    )}
                                                </span>
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 gap-1">
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="ghost"
                                                className="h-7 w-7 p-0"
                                            >
                                                <Link
                                                    href={`/operations/forms/${form.id}`}
                                                    aria-label={`View ${form.name}`}
                                                >
                                                    <Eye className="h-3.5 w-3.5" />
                                                </Link>
                                            </Button>
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="ghost"
                                                className="h-7 w-7 p-0"
                                            >
                                                <Link
                                                    href={`/operations/forms/${form.id}/edit`}
                                                    aria-label={`Edit ${form.name}`}
                                                >
                                                    <Pencil className="h-3.5 w-3.5" />
                                                </Link>
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))
                        )}
                    </div>

                    {forms.last_page > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {forms.links.map((link, i) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    className="h-7 min-w-[28px] px-2 text-xs"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
