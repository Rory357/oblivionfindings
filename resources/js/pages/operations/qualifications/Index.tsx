import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
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
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
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

    const header = (
        <PageHeader
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
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder={`Search qualifications and ${clientPlural.toLowerCase()}…`}
                    />
                    {can.create ? (
                        <Button
                            onClick={() => setEditor(null)}
                            className="min-h-11"
                        >
                            <Plus className="mr-2 size-4" />
                            Add requirement
                        </Button>
                    ) : null}
                </>
            }
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

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
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
                                    className="transition-all hover:border-border hover:shadow-sm"
                                >
                                    <CardContent className="flex flex-wrap items-start gap-4 p-4">
                                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                            <ShieldCheck className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold">
                                                    {req.qualification_name}
                                                </span>
                                                <Badge
                                                    variant="outline"
                                                    className="h-4 px-1.5 text-[9px]"
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
                                            <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                {req.client && (
                                                    <span>
                                                        {req.client.first_name}{' '}
                                                        {req.client.last_name}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {can.edit ? (
                                                <Button
                                                    variant="outline"
                                                    className="min-h-11"
                                                    onClick={() =>
                                                        setEditor(req)
                                                    }
                                                >
                                                    <Pencil className="mr-2 size-4" />
                                                    Edit
                                                    <span className="sr-only">
                                                        {' '}
                                                        {req.qualification_name}
                                                    </span>
                                                </Button>
                                            ) : null}
                                            {can.delete ? (
                                                <Button
                                                    variant="ghost"
                                                    className="min-h-11 text-status-critical"
                                                    onClick={() =>
                                                        setRemoving(req)
                                                    }
                                                >
                                                    <Trash2 className="mr-2 size-4" />
                                                    Remove
                                                    <span className="sr-only">
                                                        {' '}
                                                        {req.qualification_name}
                                                    </span>
                                                </Button>
                                            ) : null}
                                        </div>
                                    </CardContent>
                                </Card>
                            ))
                        )}
                    </div>

                    {(requirements.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(requirements.links ?? []).map((link, i) => (
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
