import { TrendChart } from '@/components/control-room/reports/report-parts';
import {
    CounterPill,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    ProgressValue,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { QuietLine, Surface } from '@/components/signature/blocks';
import {
    OnTimeTrendChart,
    Pager,
    REPORTS_BASE,
    ReportBlock,
    ReportFilterRow,
    bucketLabel,
    formatRate,
    plural,
    rateRows,
    rateTone,
    reportHref,
    scopeWords,
    showBlock,
    usePaged,
    type ReportMetrics,
    type ReportScope,
    type TrendBucket,
} from '@/components/sites/reports/report-kit';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { ClipboardCheck, ClipboardX, TrendingUp } from 'lucide-react';
import { useMemo, useState } from 'react';

type FailedItem = {
    template_id: number;
    template_name: string;
    item_text: string;
    failure_count: number;
    site_count: number;
    last_failed_on: string | null;
};

type TemplateRow = {
    template_id: number;
    name: string;
    site_count: number;
    due: number;
    on_time: number;
    late: number;
    missed: number;
    on_time_rate: number | null;
};

type Props = ReportScope & {
    totals: ReportMetrics;
    trend: (TrendBucket & { failures: number })[];
    failedItems: FailedItem[];
    byTemplate: TemplateRow[];
};

const PATH = `${REPORTS_BASE}/checklist-trends`;

const openChecklists: MenuItem[] = [
    {
        label: 'Open checklists',
        icon: ClipboardCheck,
        onClick: () => router.visit('/checklists'),
    },
];

/**
 * Checklist trends — are checks done on time? Checklists done by their
 * scheduled day, which checklists slip, and the questions that fail most,
 * at the Sites the viewer can access.
 */
export default function ChecklistTrends(props: Props) {
    const { totals: t, trend, failedItems, byTemplate, filters } = props;
    const scope: ReportScope = props;
    const [query, setQuery] = useState('');
    const q = query.trim().toLowerCase();
    const templateMenu = useEntityContextMenu<TemplateRow>();
    const itemMenu = useEntityContextMenu<FailedItem>();

    const templates = useMemo(
        () =>
            byTemplate.filter(
                (row) => !q || row.name.toLowerCase().includes(q),
            ),
        [byTemplate, q],
    );
    const items = useMemo(
        () =>
            failedItems.filter(
                (item) =>
                    !q ||
                    `${item.item_text} ${item.template_name}`
                        .toLowerCase()
                        .includes(q),
            ),
        [failedItems, q],
    );
    const pagedTemplates = usePaged(templates, q);
    const pagedItems = usePaged(items, q);
    const rate = t.checklists_on_time_rate;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: reportHref(REPORTS_BASE, filters) },
                { title: 'Checklist trends', href: reportHref(PATH, filters) },
            ]}
        >
            <Head title="Checklist trends" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={TrendingUp}
                        title="Checklist trends"
                        subline={`Checklists done on time, the ones that slip and the items that fail · ${scopeWords(scope)}`}
                        actions={
                            <PageHeaderSearch
                                value={query}
                                onChange={setQuery}
                                placeholder="Search checklists, questions…"
                            />
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="On time"
                                    tone={
                                        rateTone(rate) === 'critical'
                                            ? 'critical'
                                            : rateTone(rate) === 'warning'
                                              ? 'warning'
                                              : 'brand'
                                    }
                                    ariaLabel="Show checklists done on time by month"
                                    onClick={() => showBlock('checklist-trend')}
                                >
                                    {rate !== null ? (
                                        <PageHeaderMeterDonut
                                            percent={rate}
                                            caption={
                                                <>
                                                    {t.checklists_on_time} of{' '}
                                                    {t.checklists_due}
                                                    <br />
                                                    checklists due
                                                </>
                                            }
                                        />
                                    ) : (
                                        <>
                                            <PageHeaderMeterBig>
                                                Nothing due
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                no checklists due in this period
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Done late"
                                    tone={
                                        t.checklists_late > 0
                                            ? 'warning'
                                            : 'success'
                                    }
                                    ariaLabel="Show each checklist's late and missed runs"
                                    onClick={() => showBlock('by-template')}
                                >
                                    <PageHeaderMeterBig>
                                        {t.checklists_late}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        finished after their day
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Missed"
                                    tone={
                                        t.checklists_missed > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                    ariaLabel="Show each checklist's missed runs"
                                    onClick={() => showBlock('by-template')}
                                >
                                    <PageHeaderMeterBig>
                                        {t.checklists_missed}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        still not done
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Failed items"
                                    tone={
                                        t.checklist_items_failed > 0
                                            ? 'warning'
                                            : 'success'
                                    }
                                    ariaLabel="Show the items that fail most"
                                    onClick={() => showBlock('failed-items')}
                                >
                                    <PageHeaderMeterBig>
                                        {t.checklist_items_failed}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {`across ${plural(failedItems.length, 'question')}`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={<ReportFilterRow scope={scope} path={PATH} />}
                    />
                }
            >
                {t.checklists_due === 0 && failedItems.length === 0 ? (
                    <Surface>
                        <EmptyState
                            icon={ClipboardCheck}
                            title="No checklists were due in this period"
                            description="Choose a longer period, or another region, in the header."
                            className="border-0"
                        />
                    </Surface>
                ) : (
                    <div className="grid gap-5">
                        <div className="grid gap-5 lg:grid-cols-2">
                            <ReportBlock
                                id="checklist-trend"
                                title="Checklists done on time"
                                sub={`Done by their scheduled day, by ${filters.unit}`}
                            >
                                <OnTimeTrendChart
                                    rows={rateRows(
                                        trend,
                                        filters.unit,
                                        'checklists',
                                    )}
                                    noun="checklists"
                                    emptyTitle="No checklists were due in this period"
                                />
                            </ReportBlock>
                            <ReportBlock
                                title="Failed items"
                                sub={`Questions answered as a fail, by ${filters.unit}`}
                            >
                                <TrendChart
                                    rows={trend.map((bucket) => ({
                                        key: bucket.key,
                                        label: bucketLabel(
                                            bucket,
                                            filters.unit,
                                        ),
                                        value: bucket.failures ?? 0,
                                    }))}
                                    unit="Failed items"
                                    labelHeading="Period"
                                    emptyTitle="Nothing failed in this period"
                                />
                            </ReportBlock>
                        </div>

                        <section
                            id="by-template"
                            tabIndex={-1}
                            aria-label="Each checklist"
                            className="grid scroll-mt-5 gap-3 outline-none"
                        >
                            <ListCaption
                                title="Each checklist"
                                caption="Least on time first"
                            />
                            {templates.length === 0 ? (
                                <QuietLine
                                    text={
                                        q
                                            ? `No checklist matches “${query.trim()}”.`
                                            : 'No checklists were due in this period.'
                                    }
                                />
                            ) : (
                                <>
                                    <EntityTable
                                        rows={pagedTemplates.rows}
                                        rowKey={(row) => row.template_id}
                                        identityLabel="Checklist"
                                        identity={(row) => ({
                                            icon: ClipboardCheck,
                                            name: row.name,
                                            subline: `${plural(row.site_count, 'site')} · ${plural(row.due, 'run')} due`,
                                        })}
                                        columns={[
                                            {
                                                key: 'rate',
                                                label: 'On time',
                                                width: '1.3fr',
                                                cell: (row) => {
                                                    const tone = rateTone(
                                                        row.on_time_rate,
                                                    );
                                                    return (
                                                        <ProgressValue
                                                            percent={
                                                                row.on_time_rate
                                                            }
                                                            tone={
                                                                tone ===
                                                                'neutral'
                                                                    ? 'brand'
                                                                    : tone
                                                            }
                                                        >
                                                            {formatRate(
                                                                row.on_time_rate,
                                                            ) ?? 'Nothing due'}
                                                        </ProgressValue>
                                                    );
                                                },
                                            },
                                            {
                                                key: 'late',
                                                label: 'Late',
                                                width: '0.7fr',
                                                align: 'center',
                                                cell: (row) => (
                                                    <CounterPill
                                                        tone={
                                                            row.late > 0
                                                                ? 'warning'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {row.late}
                                                    </CounterPill>
                                                ),
                                            },
                                            {
                                                key: 'missed',
                                                label: 'Missed',
                                                width: '0.7fr',
                                                align: 'center',
                                                cell: (row) => (
                                                    <CounterPill
                                                        tone={
                                                            row.missed > 0
                                                                ? 'critical'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {row.missed}
                                                    </CounterPill>
                                                ),
                                            },
                                        ]}
                                        onOpen={() =>
                                            router.visit('/checklists')
                                        }
                                        actionsFor={() => openChecklists}
                                        onRowContextMenu={templateMenu.open}
                                        minWidth={640}
                                    />
                                    <Pager {...pagedTemplates} />
                                </>
                            )}
                        </section>

                        <section
                            id="failed-items"
                            tabIndex={-1}
                            aria-label="Items that fail most"
                            className="grid scroll-mt-5 gap-3 outline-none"
                        >
                            <ListCaption
                                title="Items that fail most"
                                caption="The 50 questions answered as a fail most often"
                            />
                            {items.length === 0 ? (
                                <QuietLine
                                    text={
                                        q
                                            ? `No question matches “${query.trim()}”.`
                                            : 'No checklist items failed in this period.'
                                    }
                                />
                            ) : (
                                <>
                                    <EntityTable
                                        rows={pagedItems.rows}
                                        rowKey={(item) =>
                                            `${item.template_id}-${item.item_text}`
                                        }
                                        identityLabel="Question"
                                        identityWidth="2.6fr"
                                        wrapIdentity
                                        rowHeight="content"
                                        identity={(item) => ({
                                            icon: ClipboardX,
                                            name: item.item_text,
                                            subline: item.template_name,
                                        })}
                                        columns={[
                                            {
                                                key: 'failures',
                                                label: 'Failed',
                                                width: '0.7fr',
                                                align: 'center',
                                                cell: (item) => (
                                                    <CounterPill tone="warning">
                                                        {item.failure_count}
                                                    </CounterPill>
                                                ),
                                            },
                                            {
                                                key: 'sites',
                                                label: 'Sites',
                                                width: '0.7fr',
                                                align: 'center',
                                                cell: (item) => (
                                                    <span>
                                                        {item.site_count}
                                                    </span>
                                                ),
                                            },
                                            {
                                                key: 'last',
                                                label: 'Last failed',
                                                width: '1fr',
                                                cell: (item) =>
                                                    formatDateOnly(
                                                        item.last_failed_on,
                                                    ),
                                            },
                                        ]}
                                        onOpen={() =>
                                            router.visit('/checklists')
                                        }
                                        actionsFor={() => openChecklists}
                                        onRowContextMenu={itemMenu.open}
                                        minWidth={720}
                                    />
                                    <Pager {...pagedItems} />
                                </>
                            )}
                        </section>
                    </div>
                )}
                {templateMenu.ctx ? (
                    <EntityContextMenu
                        {...templateMenu.ctx}
                        onClose={templateMenu.close}
                        title={templateMenu.ctx.record.name}
                        items={openChecklists}
                    />
                ) : null}
                {itemMenu.ctx ? (
                    <EntityContextMenu
                        {...itemMenu.ctx}
                        onClose={itemMenu.close}
                        title={itemMenu.ctx.record.template_name}
                        items={openChecklists}
                    />
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
