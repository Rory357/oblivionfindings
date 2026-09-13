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
    FileText,
    Hash,
    Layers,
    Pencil,
    Plus,
    StickyNote,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type NoteTemplate = {
    id: number;
    name: string;
    template_type: string;
    is_active: boolean;
    fields_count: number;
    created_at: string;
};

type Props = {
    templates: {
        data: NoteTemplate[];
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
    stats?: {
        total: number;
        active: number;
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

export default function NoteTemplatesIndex({
    templates = {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    },
    filters = {},
    stats,
    types = [],
}: Props) {
    const s = stats ?? { total: 0, active: 0 };
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
            router.get('/operations/note-templates', params, {
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
        { key: 'all', label: 'All templates', icon: Layers, count: s.total },
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
        railItems.find((v) => v.key === view)?.label ?? 'All templates';

    const hasNarrowing =
        (filters.q ?? '') !== '' || (filters.type ?? 'all') !== 'all';

    const typeOptions = [
        { value: 'all', label: 'All types' },
        ...types.map((t) => ({ value: t, label: typeLabel(t) })),
    ];

    const header = (
        <PageHeader
            icon={StickyNote}
            title="Note templates"
            titleChip={
                <PageHeaderStatusChip
                    variant={s.active > 0 ? 'success' : 'neutral'}
                >
                    {s.active} active
                </PageHeaderStatusChip>
            }
            subline={`Care note templates and custom fields · ${s.total} ${
                s.total === 1 ? 'template' : 'templates'
            } · ${types.length} ${types.length === 1 ? 'type' : 'types'}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder="Search templates…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() =>
                            router.visit('/operations/note-templates/create')
                        }
                    >
                        New template
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Templates"
                        ariaLabel="View all templates"
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
                            ariaLabel="View active templates"
                            onClick={() => applyFilters({ view: 'active' })}
                        >
                            <PageHeaderMeterDonut
                                percent={(s.active / s.total) * 100}
                                caption={
                                    <>
                                        {s.active} of {s.total}
                                        <br />
                                        in use
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Inactive"
                        ariaLabel="View inactive templates"
                        onClick={() => applyFilters({ view: 'inactive' })}
                    >
                        <PageHeaderMeterBig>{inactiveCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            retired from use
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={FileText}
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
                    ariaLabel="Template views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                {
                    title: 'Note templates',
                    href: '/operations/note-templates',
                },
            ]}
        >
            <Head title="Note templates" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${templates.data.length} of ${
                            view === 'active'
                                ? s.active
                                : view === 'inactive'
                                  ? inactiveCount
                                  : s.total
                        } shown`}
                    />

                    <div className="space-y-2">
                        {templates.data.length === 0 ? (
                            <EmptyState
                                icon={StickyNote}
                                title="No note templates"
                                description={
                                    hasNarrowing || view !== 'all'
                                        ? 'Try a different view or clear your filters.'
                                        : 'Create your first note template to standardise care notes.'
                                }
                                action={
                                    hasNarrowing ||
                                    view !== 'all' ? undefined : (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.visit(
                                                    '/operations/note-templates/create',
                                                )
                                            }
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            New template
                                        </Button>
                                    )
                                }
                            />
                        ) : (
                            templates.data.map((tpl) => (
                                <Card
                                    key={tpl.id}
                                    className="transition-all hover:border-border hover:shadow-sm"
                                >
                                    <CardContent className="flex items-center gap-4 p-4">
                                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                            <FileText className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link
                                                    href={`/operations/note-templates/${tpl.id}/edit`}
                                                    className="text-sm font-semibold hover:underline"
                                                >
                                                    {tpl.name}
                                                </Link>
                                                <StatusBadge
                                                    variant={
                                                        tpl.is_active
                                                            ? 'success'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {tpl.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </StatusBadge>
                                                <Badge
                                                    variant="outline"
                                                    className="h-4 px-1.5 text-[9px] capitalize"
                                                >
                                                    {tpl.template_type}
                                                </Badge>
                                            </div>
                                            <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                <span className="flex items-center gap-1">
                                                    <Hash className="h-3 w-3" />{' '}
                                                    {tpl.fields_count} fields
                                                </span>
                                                <span>
                                                    Created:{' '}
                                                    {formatDate(tpl.created_at)}
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
                                                    href={`/operations/note-templates/${tpl.id}/edit`}
                                                    aria-label={`Edit ${tpl.name}`}
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

                    {(templates.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(templates.links ?? []).map((link, i) => (
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
