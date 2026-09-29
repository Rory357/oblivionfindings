import {
    ActionDialog,
    DemandDialog,
    intentTitles,
} from '@/components/fleet-assets/transport/dialogs';
import {
    exportPath,
    transportDay,
} from '@/components/fleet-assets/transport/model';
import {
    TransportRecordContent,
    availableIntents,
} from '@/components/fleet-assets/transport/record-detail';
import '@/components/fleet-assets/transport/transport.css';
import type {
    Intent,
    TransportRecord,
} from '@/components/fleet-assets/transport/types';
import { EntityContextMenu, EntityKebab } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page/page-header';
import { PageLayout } from '@/components/page/page-layout';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, FileText, Route } from 'lucide-react';
import { useState } from 'react';
export default function TransportRecordPage({
    record: row,
}: {
    record: TransportRecord;
}) {
    const [intent, setIntent] = useState<Intent | null>(null),
        [menu, setMenu] = useState<{ x: number; y: number } | null>(null);
    const actions = availableIntents(row).map((action) => ({
        label: intentTitles[action],
        icon: Route,
        onClick: () => setIntent(action),
    }));
    const plan = () =>
        router.visit(
            `/fleet-assets/transports/planner?from=${transportDay(row.booking?.start || row.start)}&to=${transportDay(row.booking?.start || row.start)}&selected=${row.id}&queue=${row.booking ? 'planned' : 'all'}`,
        );
    const exportUrl = exportPath(
        {
            from: transportDay(row.start),
            to: transportDay(row.start),
            site: 'all',
            search: '',
        },
        row.id,
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: 'Transport',
                    href: '/fleet-assets/transports/overview',
                },
                { title: row.reference, href: row.links.request },
            ]}
        >
            <Head title={`${row.person} · Transport`} />
            <PageLayout
                className="transport-workspace"
                hero={
                    <div
                        onContextMenu={(e) => {
                            e.preventDefault();
                            setMenu({ x: e.clientX, y: e.clientY });
                        }}
                    >
                        <PageHeader
                            title={`${row.person} · transport`}
                            icon={Route}
                            subline={`${row.reference} · ${row.site.name} · ${row.stage_label}`}
                            variant="index"
                            actions={
                                <>
                                    <PageHeaderGlassButton
                                        onClick={() =>
                                            router.visit(
                                                '/fleet-assets/transports/journeys',
                                            )
                                        }
                                    >
                                        <ArrowLeft className="size-4" />
                                        Back to Transport
                                    </PageHeaderGlassButton>
                                    <PageHeaderGlassButton
                                        onClick={() =>
                                            window.location.assign(exportUrl)
                                        }
                                    >
                                        <FileText className="size-4" />
                                        Export PDF
                                    </PageHeaderGlassButton>
                                    <EntityKebab actions={actions} />
                                </>
                            }
                            meters={
                                <div className="tr-meters">
                                    {[
                                        [
                                            'Current stage',
                                            row.stage_label,
                                            row.reference,
                                        ],
                                        [
                                            'Transport window',
                                            formatDateTime(
                                                row.booking?.start || row.start,
                                            ),
                                            row.end
                                                ? `Return ${formatDateTime(row.booking?.end || row.end)}`
                                                : 'Expected return needed',
                                        ],
                                        [
                                            'Vehicle & driver',
                                            row.booking?.vehicle.name ||
                                                'Unallocated',
                                            row.booking?.driver.name ||
                                                'Choose in Planner',
                                        ],
                                        [
                                            'Next person',
                                            row.next_owner,
                                            row.next_action,
                                        ],
                                    ].map(([label, value, caption]) => (
                                        <PageHeaderMeterBlock
                                            key={label}
                                            label={label}
                                        >
                                            <PageHeaderMeterBig>
                                                {value}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {caption}
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                    ))}
                                </div>
                            }
                        />
                    </div>
                }
            >
                <TransportRecordContent
                    row={row}
                    onIntent={setIntent}
                    onPlan={plan}
                    onRefresh={() => router.reload()}
                />
            </PageLayout>
            {menu && (
                <EntityContextMenu
                    {...menu}
                    title="Transport actions"
                    icon={Route}
                    items={actions}
                    onClose={() => setMenu(null)}
                />
            )}
            {intent &&
                (intent === 'assess' || intent === 'respond' ? (
                    <DemandDialog
                        row={row}
                        mode={intent}
                        onClose={() => setIntent(null)}
                        onSaved={() => router.reload()}
                    />
                ) : (
                    <ActionDialog
                        row={row}
                        intent={intent}
                        onClose={() => setIntent(null)}
                        onSaved={() => router.reload()}
                    />
                ))}
        </AppLayout>
    );
}
