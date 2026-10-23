import { ProcedureWizardDialog } from '@/components/health-safety/procedure-wizard-dialog';
import {
    ProcedureReviewDate,
    ProcedureStatus,
    procedureCategory,
} from '@/components/health-safety/procedures/procedure-kit';
import type { ProcedurePickers } from '@/components/health-safety/procedures/types';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import {
    PROCEDURE_REGISTER,
    procedureHref,
    procedureReturn,
} from '@/lib/health-safety/procedure-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    CheckCircle2,
    Clock,
    FileDown,
    FileEdit,
    FileText,
    LayoutGrid,
    Plus,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type Row = {
    provenance?: string;
    id: number;
    reference_number: string | null;
    title: string;
    purpose: string | null;
    category: string;
    status: string;
    version: number;
    review_date: string | null;
    working_status?: string | null;
    owner: { id: number; name: string } | null;
    approved_by: { id: number; name: string } | null;
    sites_count: number;
    roles_count: number;
    documents_count: number;
};
type Filters = {
    q: string | null;
    tab: string;
    category: string | null;
    status: string | null;
    site_id: number | null;
    review_state: string | null;
};
type Props = ProcedurePickers & {
    procedures: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        last_page: number;
        total?: number;
        from?: number | null;
        to?: number | null;
    };
    filters: Filters;
    tabCounts: Record<string, number>;
    can: { view: boolean; create: boolean; manage: boolean; approve: boolean };
};
export default function ProceduresIndex({
    procedures,
    filters,
    tabCounts,
    can,
    sites,
    roles,
    trainingCourses,
    owners,
    categories,
    can_apply_organisation_wide,
}: Props) {
    const { url } = usePage();
    const returnTo = procedureReturn(url);
    const drafts = useSafetyWorkspaceDrafts();
    const [search, setSearch] = useState(filters.q ?? '');
    const [creating, setCreating] = useState(false);
    useEffect(() => setSearch(filters.q ?? ''), [filters.q]);
    useEffect(() => {
        if (
            can.create &&
            new URLSearchParams(url.split('?')[1]).get('new') === '1'
        )
            setCreating(true);
    }, [can.create, url]);
    const params = (next: Partial<Filters> = {}) =>
        Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, value]) => value != null && value !== '',
            ),
        ) as Record<string, string | number>;
    const query = (next: Partial<Filters> = {}) =>
        new URLSearchParams(
            Object.entries(params(next)).map(([key, value]) => [
                key,
                String(value),
            ]),
        );
    const go = (next: Partial<Filters>) =>
        router.get(PROCEDURE_REGISTER, params(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    const tabs = [
        { key: 'all', label: 'All', icon: LayoutGrid },
        { key: 'approved', label: 'Published', icon: CheckCircle2 },
        { key: 'under_review', label: 'Under review', icon: Clock },
        { key: 'draft', label: 'Drafts', icon: FileEdit },
        { key: 'review_due', label: 'Review due', icon: Clock },
        { key: 'archived', label: 'Archived', icon: Archive },
    ].map((item) => ({ ...item, count: tabCounts[item.key] ?? 0 }));
    const href = (id: number) => procedureHref(id, { returnTo });
    const pickers = {
        sites,
        roles,
        trainingCourses,
        owners,
        categories,
        can_apply_organisation_wide,
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Safe work procedures', href: PROCEDURE_REGISTER },
            ]}
        >
            <Head title="Safe work procedures" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            frontline
                            icon={FileText}
                            title="Safe work procedures"
                            subline="Current instructions, controlled revisions and staff acknowledgement"
                            actions={
                                <>
                                    <PageHeaderSearch
                                        value={search}
                                        onChange={setSearch}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter')
                                                go({
                                                    q: search.trim() || null,
                                                });
                                        }}
                                        placeholder="Search procedures"
                                        ariaLabel="Search procedures"
                                    />
                                    <PageHeaderGlassButton asChild>
                                        <a
                                            href={`${PROCEDURE_REGISTER}/export?${query()}`}
                                        >
                                            <FileDown className="size-4" />
                                            Export
                                        </a>
                                    </PageHeaderGlassButton>
                                    {can.create && (
                                        <PageHeaderPrimaryButton
                                            onClick={() => setCreating(true)}
                                        >
                                            <Plus className="size-4" />
                                            New procedure
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            meters={
                                <>
                                    {[
                                        {
                                            key: 'approved',
                                            label: 'Published',
                                            caption:
                                                'Current staff instructions',
                                        },
                                        {
                                            key: 'under_review',
                                            label: 'Under review',
                                            caption:
                                                'Working revisions awaiting review',
                                        },
                                        {
                                            key: 'review_due',
                                            label: 'Review due',
                                            caption:
                                                'Published review deadlines',
                                        },
                                        {
                                            key: 'draft',
                                            label: 'Drafts',
                                            caption: 'Working drafts',
                                        },
                                    ].map((item) => (
                                        <PageHeaderMeterBlock
                                            key={item.key}
                                            label={item.label}
                                            href={`${PROCEDURE_REGISTER}?${query({ tab: item.key })}`}
                                        >
                                            <PageHeaderMeterBig>
                                                {tabCounts[item.key] ?? 0}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {item.caption}
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                    ))}
                                </>
                            }
                            filters={
                                <>
                                    <PageHeaderFilterSelect
                                        label="Category"
                                        value={filters.category ?? ''}
                                        onChange={(value) =>
                                            go({ category: value || null })
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All categories',
                                            },
                                            ...categories,
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="Site"
                                        value={
                                            filters.site_id
                                                ? String(filters.site_id)
                                                : ''
                                        }
                                        onChange={(value) =>
                                            go({
                                                site_id: value
                                                    ? Number(value)
                                                    : null,
                                            })
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All accessible sites',
                                            },
                                            ...sites.map((site) => ({
                                                value: String(site.id),
                                                label: site.name,
                                            })),
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="State"
                                        value={filters.status ?? ''}
                                        onChange={(value) =>
                                            go({ status: value || null })
                                        }
                                        options={[
                                            { value: '', label: 'All states' },
                                            { value: 'draft', label: 'Draft' },
                                            {
                                                value: 'under_review',
                                                label: 'Under review',
                                            },
                                            {
                                                value: 'approved',
                                                label: 'Published',
                                            },
                                            {
                                                value: 'archived',
                                                label: 'Archived',
                                            },
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="Review"
                                        value={filters.review_state ?? ''}
                                        onChange={(value) =>
                                            go({ review_state: value || null })
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All review dates',
                                            },
                                            {
                                                value: 'overdue',
                                                label: 'Overdue',
                                            },
                                            {
                                                value: 'today',
                                                label: 'Due today',
                                            },
                                            {
                                                value: 'due_soon',
                                                label: 'Due within 30 days',
                                            },
                                            {
                                                value: 'current',
                                                label: 'Current',
                                            },
                                            {
                                                value: 'none',
                                                label: 'Not scheduled',
                                            },
                                        ]}
                                    />
                                    {Object.entries(filters).some(
                                        ([key, value]) =>
                                            key !== 'tab' && value,
                                    ) && (
                                        <PageHeaderGlassButton
                                            onClick={() =>
                                                go({
                                                    q: null,
                                                    category: null,
                                                    status: null,
                                                    site_id: null,
                                                    review_state: null,
                                                })
                                            }
                                        >
                                            Clear filters
                                        </PageHeaderGlassButton>
                                    )}
                                </>
                            }
                            rail={
                                <PageHeaderRail
                                    items={tabs}
                                    value={filters.tab}
                                    onSelect={(tab) => go({ tab })}
                                    ariaLabel="Procedure views"
                                />
                            }
                        />
                    }
                >
                    {procedures.data.length ? (
                        <>
                            <ul className="space-y-3 md:hidden">
                                {procedures.data.map((row) => (
                                    <li
                                        key={row.id}
                                        className="space-y-3 rounded-xl border border-border bg-card p-4"
                                    >
                                        <Link
                                            className="frontline-tap frontline-focus block font-semibold break-words text-primary underline"
                                            href={href(row.id)}
                                        >
                                            {row.reference_number
                                                ? `${row.reference_number} · `
                                                : ''}
                                            {row.title}
                                        </Link>
                                        <div className="flex flex-wrap gap-2">
                                            <ProcedureStatus
                                                status={row.status}
                                                legacy={
                                                    row.provenance ===
                                                    'legacy_current_capture'
                                                }
                                            />
                                            <ProcedureReviewDate
                                                date={row.review_date}
                                            />
                                        </div>
                                        <p className="text-sm">
                                            Version {row.version} ·{' '}
                                            {procedureCategory(row.category)}
                                        </p>
                                        <p className="text-subtle">
                                            Document owner:{' '}
                                            {row.owner?.name ?? 'Unassigned'}
                                        </p>
                                        {row.working_status &&
                                            row.working_status !==
                                                row.status && (
                                                <p className="text-subtle">
                                                    A working revision is{' '}
                                                    {row.working_status.replaceAll(
                                                        '_',
                                                        ' ',
                                                    )}
                                                    .
                                                </p>
                                            )}
                                    </li>
                                ))}
                            </ul>
                            <Card className="hidden gap-0 overflow-hidden py-0 md:block">
                                <div
                                    role="region"
                                    aria-label="Procedure register table"
                                    tabIndex={0}
                                    className="frontline-focus overflow-x-auto"
                                >
                                    <table className="w-full text-left text-sm">
                                        <thead className="border-b border-border bg-muted/40">
                                            <tr>
                                                {[
                                                    'Procedure',
                                                    'State & review',
                                                    'Owner',
                                                    'Version',
                                                    'Documents',
                                                ].map((label) => (
                                                    <th
                                                        key={label}
                                                        scope="col"
                                                        className="px-4 py-3 font-semibold"
                                                    >
                                                        {label}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-border">
                                            {procedures.data.map((row) => (
                                                <tr
                                                    key={row.id}
                                                    className="align-top hover:bg-muted/20"
                                                >
                                                    <td className="min-w-64 px-4 py-3">
                                                        <Link
                                                            className="frontline-tap frontline-focus inline-flex items-center font-semibold text-primary underline"
                                                            href={href(row.id)}
                                                        >
                                                            {row.reference_number ??
                                                                row.title}
                                                        </Link>
                                                        <p className="max-w-md break-words">
                                                            {row.title}
                                                        </p>
                                                        <p className="text-subtle mt-1">
                                                            {procedureCategory(
                                                                row.category,
                                                            )}
                                                        </p>
                                                    </td>
                                                    <td className="min-w-48 space-y-2 px-4 py-4">
                                                        <ProcedureStatus
                                                            status={row.status}
                                                            legacy={
                                                                row.provenance ===
                                                                'legacy_current_capture'
                                                            }
                                                        />
                                                        <div>
                                                            <ProcedureReviewDate
                                                                date={
                                                                    row.review_date
                                                                }
                                                            />
                                                        </div>
                                                        {row.working_status &&
                                                            row.working_status !==
                                                                row.status && (
                                                                <p className="text-subtle">
                                                                    Working
                                                                    revision:{' '}
                                                                    {row.working_status.replaceAll(
                                                                        '_',
                                                                        ' ',
                                                                    )}
                                                                </p>
                                                            )}
                                                    </td>
                                                    <td className="px-4 py-4">
                                                        {row.owner?.name ??
                                                            'Unassigned'}
                                                    </td>
                                                    <td className="px-4 py-4">
                                                        {row.version}
                                                    </td>
                                                    <td className="px-4 py-4">
                                                        {row.documents_count}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </Card>
                            {procedures.total != null && (
                                <p className="text-subtle mt-3">
                                    {procedures.from ?? 0}–{procedures.to ?? 0}{' '}
                                    of {procedures.total} procedures
                                </p>
                            )}
                        </>
                    ) : (
                        <Card className="space-y-4 p-8 text-center">
                            <h2 className="text-section-title">
                                No procedures match this view
                            </h2>
                            <p className="text-subtle">
                                Try another view or clear the filters.
                            </p>
                            {can.create && (
                                <Button
                                    className="frontline-tap"
                                    onClick={() => setCreating(true)}
                                >
                                    New procedure
                                </Button>
                            )}
                        </Card>
                    )}
                    {procedures.last_page > 1 && (
                        <LaravelPagination links={procedures.links} />
                    )}
                </PageLayout>
                {creating && (
                    <ProcedureWizardDialog
                        open
                        options={pickers}
                        onClose={() => drafts.request(() => setCreating(false))}
                        onOpenProcedure={(id) => {
                            setCreating(false);
                            router.visit(
                                procedureHref(id, {
                                    revision: 'working',
                                    returnTo,
                                }),
                            );
                        }}
                    />
                )}
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
