import {
    EntityContextMenu,
    EntityKebab,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import {
    qualificationMappingLabel,
    type QualificationOption,
} from '@/components/rostering/qualification-requirement-fields';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { WorkforcePageHeader } from '@/components/workforce/workforce-page-header';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Award,
    Layers,
    Pencil,
    Plus,
    ShieldAlert,
    ShieldCheck,
    Trash2,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import {
    QualificationRemoveDialog,
    QualificationRequirementEditor,
    type QualificationClient,
    type QualificationContext,
    type QualificationRequirement,
} from './requirement-editor';

type Props = {
    can?: { create: boolean; edit: boolean; delete: boolean };
    complianceRequirements?: QualificationOption[];
    editableClients?: QualificationClient[];
    serviceContexts?: QualificationContext[];
    requirements: {
        data: QualificationRequirement[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string | null;
        mandatory?: string | null;
        client_id?: number | string | null;
    };
    stats?: {
        total: number;
        mandatory: number;
        clients: number;
    };
    clients?: Array<{ id: number; first_name: string; last_name: string }>;
};

type ViewKey = 'all' | 'mandatory' | 'optional';

export default function QualificationsIndex({
    requirements = {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    },
    filters = {},
    stats,
    clients = [],
    can = { create: false, edit: false, delete: false },
    complianceRequirements = [],
    editableClients = [],
    serviceContexts = [],
}: Props) {
    const page = usePage().props as unknown as {
        labels?: Record<string, string>;
        auth?: { user?: { id: number } };
    };
    const { labels } = page;
    const actorId = page.auth?.user?.id ?? 0;
    const [editor, setEditor] = useState<
        QualificationRequirement | null | undefined
    >(undefined);
    const [removing, setRemoving] = useState<QualificationRequirement | null>(
        null,
    );
    const rowMenu = useEntityContextMenu<QualificationRequirement>();
    const [notice, setNotice] = useState('');
    const clientSingular: string = labels?.['client.singular'] ?? 'Client';
    const clientPlural: string = labels?.['client.plural'] ?? 'Clients';

    const s = stats ?? { total: 0, mandatory: 0, clients: 0 };
    const optionalCount = Math.max(0, s.total - s.mandatory);

    const view: ViewKey =
        filters.mandatory === 'mandatory'
            ? 'mandatory'
            : filters.mandatory === 'optional'
              ? 'optional'
              : 'all';

    const [q, setQ] = useState(filters.q ?? '');

    const applyFilters = useCallback(
        (overrides: { view?: ViewKey; q?: string; client_id?: string }) => {
            const nextView = overrides.view ?? view;
            const nextQ = overrides.q ?? filters.q ?? '';
            const nextClient =
                overrides.client_id ?? String(filters.client_id ?? 'all');
            const params: Record<string, string> = {};
            if (nextView !== 'all') params.mandatory = nextView;
            if (nextQ.trim() !== '') params.q = nextQ.trim();
            if (nextClient !== 'all') params.client_id = nextClient;
            router.get('/operations/qualifications', params, {
                preserveState: true,
                replace: true,
            });
        },
        [view, filters.q, filters.client_id],
    );

    useEffect(() => {
        if ((filters.q ?? '') === q.trim()) return;
        const t = setTimeout(() => applyFilters({ q }), 400);
        return () => clearTimeout(t);
    }, [q, filters.q, applyFilters]);

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'all',
            label: 'All requirements',
            icon: Layers,
            count: s.total,
        },
        {
            key: 'mandatory',
            label: 'Mandatory',
            icon: ShieldAlert,
            count: s.mandatory,
        },
        {
            key: 'optional',
            label: 'Optional',
            icon: ShieldCheck,
            count: optionalCount,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All requirements';
    const viewTotal =
        view === 'mandatory'
            ? s.mandatory
            : view === 'optional'
              ? optionalCount
              : s.total;

    const hasNarrowing =
        (filters.q ?? '') !== '' ||
        String(filters.client_id ?? 'all') !== 'all';

    const clientOptions = [
        { value: 'all', label: `All ${clientPlural.toLowerCase()}` },
        ...clients.map((c) => ({
            value: String(c.id),
            label: `${c.first_name} ${c.last_name}`,
        })),
    ];

    const headerSearch = (
        <PageHeaderSearch
            value={q}
            onChange={setQ}
            placeholder={`Search qualifications and ${clientPlural.toLowerCase()}…`}
        />
    );
    const headerPrimaryAction = can.create ? (
        <PageHeaderPrimaryButton onClick={() => setEditor(null)}>
            <Plus className="mr-2 size-4" />
            Add requirement
        </PageHeaderPrimaryButton>
    ) : null;

    const header = (
        <WorkforcePageHeader
            icon={Award}
            title="Qualifications"
            titleChip={
                <PageHeaderStatusChip
                    variant={s.mandatory > 0 ? 'info' : 'neutral'}
                >
                    {s.mandatory} mandatory
                </PageHeaderStatusChip>
            }
            subline={`Worker qualification requirements by ${clientSingular.toLowerCase()} · ${s.total} ${s.total === 1 ? 'requirement' : 'requirements'}`}
            actions={
                <>
                    {headerSearch}
                    {headerPrimaryAction}
                </>
            }
            mobileSummary="Requirements"
            mobilePrimaryAction={headerPrimaryAction}
            mobileSecondaryActions={headerSearch}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Requirements"
                        ariaLabel="View all requirements"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>{s.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            for {s.clients}{' '}
                            {s.clients === 1
                                ? clientSingular.toLowerCase()
                                : clientPlural.toLowerCase()}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {s.total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Mandatory"
                            ariaLabel="View mandatory requirements"
                            onClick={() => applyFilters({ view: 'mandatory' })}
                        >
                            <PageHeaderMeterDonut
                                percent={(s.mandatory / s.total) * 100}
                                caption={
                                    <>
                                        {s.mandatory} of {s.total}
                                        <br />
                                        must be met
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Optional"
                        ariaLabel="View optional requirements"
                        onClick={() => applyFilters({ view: 'optional' })}
                    >
                        <PageHeaderMeterBig>{optionalCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            preferred, not required
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Users}
                    label={`All ${clientPlural.toLowerCase()}`}
                    value={String(filters.client_id ?? 'all')}
                    options={clientOptions}
                    onChange={(v) => applyFilters({ client_id: v })}
                />
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={(key) => applyFilters({ view: key })}
                    ariaLabel="Requirement views"
                />
            }
        />
    );

    // One item set for each requirement's ⋯ menu and right-click menu.
    const requirementActions = (req: QualificationRequirement): MenuItem[] =>
        compactMenu([
            can.edit
                ? {
                      label: 'Edit requirement',
                      icon: Pencil,
                      onClick: () => setEditor(req),
                  }
                : null,
            can.delete ? { separator: true } : null,
            can.delete
                ? {
                      label: 'Remove requirement',
                      icon: Trash2,
                      danger: true,
                      onClick: () => setRemoving(req),
                  }
                : null,
        ]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Workforce', href: '/operations/rostering' },
                { title: 'Rostering', href: '/operations/rostering' },
                {
                    title: 'Qualifications',
                    href: '/operations/qualifications',
                },
            ]}
        >
            <Head title="Qualifications" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {notice ? (
                        <p
                            role="status"
                            className="rounded-lg border p-3 text-sm"
                        >
                            {notice}
                        </p>
                    ) : null}
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${requirements.data.length} of ${viewTotal} shown`}
                    />

                    <div className="space-y-2">
                        {requirements.data.length === 0 ? (
                            <EmptyState
                                icon={Award}
                                title="No qualification requirements"
                                description={
                                    hasNarrowing || view !== 'all'
                                        ? 'Nothing matches this view or your filters.'
                                        : 'Qualification requirements will appear here once configured.'
                                }
                            />
                        ) : (
                            requirements.data.map((req) => (
                                <Card
                                    key={req.id}
                                    role={can.edit ? 'button' : undefined}
                                    tabIndex={can.edit ? 0 : undefined}
                                    aria-label={
                                        can.edit
                                            ? `Edit ${req.qualification_name}`
                                            : undefined
                                    }
                                    onClick={
                                        can.edit
                                            ? () => setEditor(req)
                                            : undefined
                                    }
                                    onKeyDown={(e) => {
                                        if (
                                            can.edit &&
                                            e.target === e.currentTarget &&
                                            (e.key === 'Enter' || e.key === ' ')
                                        ) {
                                            e.preventDefault();
                                            setEditor(req);
                                        }
                                    }}
                                    onContextMenu={(e) =>
                                        requirementActions(req).length > 0 &&
                                        rowMenu.open(e, req)
                                    }
                                    className={cn(
                                        'transition-all hover:border-border hover:shadow-sm',
                                        can.edit &&
                                            'cursor-pointer focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    )}
                                >
                                    <CardContent className="flex flex-wrap items-start gap-4 p-4">
                                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                            <ShieldCheck className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0 flex-1 break-words">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold">
                                                    {req.qualification_name}
                                                </span>
                                                <Badge
                                                    variant="outline"
                                                    className="text-caption"
                                                >
                                                    {req.is_mandatory
                                                        ? 'Mandatory'
                                                        : 'Optional'}
                                                </Badge>
                                            </div>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {qualificationMappingLabel(
                                                    req.mapping,
                                                )}
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {req.service_context_id
                                                    ? (req.service_context
                                                          ?.name ??
                                                      'Recorded service context unavailable')
                                                    : 'All service contexts for this Client'}
                                            </p>
                                            <div className="text-caption mt-0.5 flex flex-wrap items-center gap-3 text-muted-foreground">
                                                {req.client && (
                                                    <span>
                                                        {req.client.first_name}{' '}
                                                        {req.client.last_name}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <EntityKebab
                                            label={`Actions for ${req.qualification_name}`}
                                            actions={requirementActions(req)}
                                        />
                                    </CardContent>
                                </Card>
                            ))
                        )}
                    </div>

                    {rowMenu.ctx ? (
                        <EntityContextMenu
                            x={rowMenu.ctx.x}
                            y={rowMenu.ctx.y}
                            icon={ShieldCheck}
                            title={rowMenu.ctx.record.qualification_name}
                            items={requirementActions(rowMenu.ctx.record)}
                            onClose={rowMenu.close}
                        />
                    ) : null}

                    <LaravelPagination
                        links={requirements.links ?? []}
                        lastPage={requirements.last_page}
                        className="flex-wrap gap-y-4 py-2"
                        preserveState
                    />
                </div>
            </PageLayout>
            {editor !== undefined ? (
                <QualificationRequirementEditor
                    key={editor?.id ?? 'new'}
                    requirement={editor}
                    actorId={actorId}
                    canEdit={editor ? can.edit : can.create}
                    clients={editableClients}
                    contexts={serviceContexts}
                    options={complianceRequirements}
                    onClose={() => setEditor(undefined)}
                    onSaved={() => {
                        setEditor(undefined);
                        setNotice('Qualification requirement saved.');
                        router.reload({
                            only: ['requirements', 'stats'],
                            preserveScroll: true,
                        });
                    }}
                />
            ) : null}
            {removing ? (
                <QualificationRemoveDialog
                    key={removing.id}
                    requirement={removing}
                    actorId={actorId}
                    allowed={can.delete}
                    onClose={() => setRemoving(null)}
                    onRemoved={() => {
                        setRemoving(null);
                        setNotice('Qualification requirement removed.');
                        router.reload({
                            only: ['requirements', 'stats'],
                            preserveScroll: true,
                        });
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
