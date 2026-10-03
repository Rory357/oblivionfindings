import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime, toDateInput } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { ClipboardList, Eye, Search } from 'lucide-react';
import { useState } from 'react';
import { PlanBadge } from './support/support-panel';
import { SUPPORT, type SupportPlan } from './support/types';

type Props = {
    register: SupportPlan[];
    total: number;
    page: number;
    counts: {
        people: number;
        reassess: number;
        none: number;
        self_managed: number;
    };
    sites: { id: number; name: string }[];
    filters: { site_id: number | null; show: string; search: string };
    can_assess: boolean;
    now: string;
};
export default function SelfAdmin({
    register,
    total,
    page,
    counts,
    sites,
    filters,
    now,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const ctx = useEntityContextMenu<SupportPlan>();
    const visit = (patch: Partial<typeof filters>) =>
        router.get(
            '/emar/self-admin',
            { ...filters, ...patch, page: 1 },
            { preserveState: true, preserveScroll: true },
        );
    const open = (p: SupportPlan, section = 'support') =>
        router.get(p.url + '?section=' + section);
    const actions = (p: SupportPlan): MenuItem[] => [
        { label: 'Open the support plan', icon: Eye, onClick: () => open(p) },
        ...(p.assessment
            ? [
                  {
                      label: 'View the assessment',
                      icon: ClipboardList,
                      onClick: () => open(p, 'assessment'),
                  },
              ]
            : []),
        ...(p.can_assess
            ? [
                  {
                      label: p.assessment ? 'Reassess' : 'Assess support',
                      icon: ClipboardList,
                      onClick: () => router.get(p.url + '?action=assess'),
                  },
              ]
            : []),
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Medication', href: '/emar' },
                { title: 'MAR & medicines', href: '/emar/mar' },
                {
                    title: 'Support & self-administration',
                    href: '/emar/self-admin',
                },
            ]}
        >
            <Head title="Support & self-administration" />
            <div className="flex flex-col gap-5">
                <PageHeader
                    icon={ClipboardList}
                    title="Support & self-administration"
                    subline="Wishes, assessment and support for each medicine"
                    actions={
                        <form
                            className="flex items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                visit({ search });
                            }}
                        >
                            <Input
                                aria-label="Find a person"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Find a person"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                aria-label="Search"
                            >
                                <Search className="size-4" />
                            </Button>
                        </form>
                    }
                    meters={
                        <>
                            {(
                                [
                                    {
                                        key: 'people',
                                        label: 'People',
                                        show: '',
                                        caption: 'with active records',
                                    },
                                    {
                                        key: 'reassess',
                                        label: 'Reassess',
                                        show: 'reassess',
                                        caption: 'due or triggered',
                                    },
                                    {
                                        key: 'none',
                                        label: 'No assessment',
                                        show: 'none',
                                        caption: 'staff give every medicine',
                                    },
                                    {
                                        key: 'self_managed',
                                        label: 'Self-managed',
                                        show: 'self_managed',
                                        caption: 'at least one medicine',
                                    },
                                ] as const
                            ).map((m) => (
                                <PageHeaderMeterBlock
                                    key={m.key}
                                    label={m.label}
                                    onClick={() => visit({ show: m.show })}
                                >
                                    <PageHeaderMeterBig>
                                        {counts[m.key]}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {m.caption}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </>
                    }
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="House"
                                value={
                                    filters.site_id
                                        ? String(filters.site_id)
                                        : 'all'
                                }
                                options={[
                                    {
                                        value: 'all',
                                        label: 'All approved houses',
                                    },
                                    ...sites.map((s) => ({
                                        value: String(s.id),
                                        label: s.name,
                                    })),
                                ]}
                                onChange={(v) =>
                                    visit({
                                        site_id: v === 'all' ? null : Number(v),
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Show"
                                value={filters.show || 'all'}
                                options={[
                                    { value: 'all', label: 'Everyone' },
                                    {
                                        value: 'reassess',
                                        label: 'Reassess now or date passed',
                                    },
                                    { value: 'none', label: 'No assessment' },
                                    {
                                        value: 'self_managed',
                                        label: 'Self-managed',
                                    },
                                ]}
                                onChange={(v) =>
                                    visit({ show: v === 'all' ? '' : v })
                                }
                            />
                            <span className="text-caption">
                                As at {formatDateTime(now)}
                            </span>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={[
                                {
                                    key: 'support',
                                    label: 'Support & self-administration',
                                },
                            ]}
                            value="support"
                            onSelect={() => {}}
                            showFind={false}
                        />
                    }
                />
                <ListCaption
                    title="Support register"
                    caption={register.length + ' of ' + total + ' shown'}
                />
                {!register.length ? (
                    <EmptyState
                        icon={ClipboardList}
                        title="No matching support records"
                        description="Try another house or clear the filters."
                        action={
                            <Button
                                variant="outline"
                                onClick={() =>
                                    visit({
                                        site_id: null,
                                        show: '',
                                        search: '',
                                    })
                                }
                            >
                                Clear filters
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <div className="hidden md:block">
                            <EntityTable
                                rows={register}
                                rowKey={(p) => p.client_id}
                                identityLabel="Person"
                                identityWidth="1.6fr"
                                identity={(p) => ({
                                    name: p.client_name,
                                    subline:
                                        p.site_name ?? 'House not recorded',
                                })}
                                columns={[
                                    {
                                        key: 'status',
                                        label: 'Support plan',
                                        width: '1.3fr',
                                        cell: (p) => (
                                            <div className="space-y-1 whitespace-normal">
                                                <PlanBadge plan={p} />
                                                {p.state === 'none' && (
                                                    <p className="text-caption">
                                                        Staff give every
                                                        medicine
                                                    </p>
                                                )}
                                                {p.reviews[0] && (
                                                    <p className="text-caption">
                                                        {p.reviews[0].reason}
                                                    </p>
                                                )}
                                                {p.state === 'overdue' && (
                                                    <p className="text-caption">
                                                        Support stays as it is
                                                    </p>
                                                )}
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'mix',
                                        label: 'Support now',
                                        width: '1.8fr',
                                        cell: (p) => (
                                            <div className="whitespace-normal">
                                                {Array.from(
                                                    new Set(
                                                        p.medicines.map(
                                                            (m) =>
                                                                SUPPORT[m.mode]
                                                                    .label,
                                                        ),
                                                    ),
                                                ).join(' · ') ||
                                                    'No visible active medicines'}
                                                {p.concealed_count > 0 && (
                                                    <p className="text-caption">
                                                        {p.concealed_count}{' '}
                                                        controlled concealed
                                                    </p>
                                                )}
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'review',
                                        label: 'Next review',
                                        width: '1fr',
                                        cell: (p) =>
                                            formatDateOnly(
                                                toDateInput(
                                                    p.assessment
                                                        ?.reassessment_date,
                                                ),
                                                'Not recorded',
                                            ),
                                    },
                                ]}
                                actionsFor={actions}
                                onOpen={(p) => open(p)}
                                onRowContextMenu={(e, p) => ctx.open(e, p)}
                                rowHeight="content"
                                minWidth={760}
                            />
                        </div>
                        <ul className="space-y-3 md:hidden">
                            {register.map((p) => (
                                <li key={p.client_id}>
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>
                                                {p.client_name}
                                            </CardTitle>
                                            <p className="text-caption">
                                                {p.site_name}
                                            </p>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            <PlanBadge plan={p} />
                                            <p>
                                                Review:{' '}
                                                {formatDateOnly(
                                                    toDateInput(
                                                        p.assessment
                                                            ?.reassessment_date,
                                                    ),
                                                    'Not recorded',
                                                )}
                                            </p>
                                            <Button
                                                variant="outline"
                                                className="frontline-tap"
                                                onClick={() => open(p)}
                                            >
                                                Open support plan
                                            </Button>
                                        </CardContent>
                                    </Card>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
                {total > 50 && (
                    <nav
                        aria-label="Support register pages"
                        className="flex items-center justify-end gap-3"
                    >
                        <Button
                            variant="outline"
                            disabled={page <= 1}
                            onClick={() =>
                                router.get('/emar/self-admin', {
                                    ...filters,
                                    page: page - 1,
                                })
                            }
                        >
                            Previous
                        </Button>
                        <span>
                            Page {page} of {Math.ceil(total / 50)}
                        </span>
                        <Button
                            variant="outline"
                            disabled={page * 50 >= total}
                            onClick={() =>
                                router.get('/emar/self-admin', {
                                    ...filters,
                                    page: page + 1,
                                })
                            }
                        >
                            Next
                        </Button>
                    </nav>
                )}
                {ctx.ctx && (
                    <EntityContextMenu
                        x={ctx.ctx.x}
                        y={ctx.ctx.y}
                        title={ctx.ctx.record.client_name}
                        icon={ClipboardList}
                        items={actions(ctx.ctx.record)}
                        onClose={ctx.close}
                    />
                )}
            </div>
        </AppLayout>
    );
}
