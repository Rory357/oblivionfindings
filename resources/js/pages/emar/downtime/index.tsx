import {
    EntityCard,
    EntityCardGrid,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { FileText, FolderOpen, Plus, Printer } from 'lucide-react';
import { useState } from 'react';
import { DeclareDowntimeDialog, PackDialog } from './_dialogs';
import type { Downtime, Site } from './types';

type Props = {
    downtimes: {
        data: Downtime[];
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    sites: Site[];
    can_manage: boolean;
    can_make_pack: boolean;
    today: string;
    tomorrow: string;
};

export default function DowntimeIndex({
    downtimes,
    sites,
    can_manage,
    can_make_pack,
    today,
    tomorrow,
}: Props) {
    const [declare, setDeclare] = useState(false);
    const [pack, setPack] = useState(false);
    const [view, setView] = useState<'all' | 'open' | 'collected'>('all');
    const context = useEntityContextMenu<Downtime>();
    const open = (row: Downtime) => router.visit('/emar/downtime/' + row.id);
    const actions = (row: Downtime): MenuItem[] => [
        {
            label: 'Open paper records',
            icon: FolderOpen,
            onClick: () => open(row),
        },
    ];
    const rows = downtimes.data.filter(
        (row) =>
            view === 'all' ||
            (view === 'open' ? !row.finished_at : !!row.finished_at),
    );
    const openHere = downtimes.data.filter((row) => !row.finished_at).length;
    const header = (
        <PageHeader
            icon={FileText}
            title="Downtime & paper records"
            subline={
                can_manage
                    ? 'Check actual paper facts, confirmations and reconciliation'
                    : 'Confirm the medication records you gave or witnessed on paper'
            }
            actions={
                <>
                    {can_make_pack && (
                        <PageHeaderGlassButton onClick={() => setPack(true)}>
                            <Printer className="size-4" /> Make the pack
                        </PageHeaderGlassButton>
                    )}
                    {can_manage && (
                        <PageHeaderPrimaryButton
                            onClick={() => setDeclare(true)}
                        >
                            <Plus className="size-4" /> Record a downtime
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Downtimes in your scope"
                        onClick={() => setView('all')}
                    >
                        <PageHeaderMeterBig>
                            {downtimes.total}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            All permitted houses
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Open on this page"
                        tone="warning"
                        onClick={() => setView('open')}
                    >
                        <PageHeaderMeterBig>{openHere}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Paper still being collected
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Collected on this page"
                        onClick={() => setView('collected')}
                    >
                        <PageHeaderMeterBig>
                            {downtimes.data.length - openHere}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Paper collection finished
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {can_make_pack && (
                        <PageHeaderMeterBlock
                            label="Today's downtime pack"
                            onClick={() => setPack(true)}
                        >
                            <PageHeaderMeterBig>PDF</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Prepare the current paper pack
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                </>
            }
            rail={
                <PageHeaderRail
                    items={[
                        { key: 'all', label: 'All downtimes' },
                        { key: 'open', label: 'Open' },
                        { key: 'collected', label: 'Paper collected' },
                    ]}
                    value={view}
                    onSelect={setView}
                />
            }
        />
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Medication', href: '/emar' },
                { title: 'Downtime & paper records', href: '/emar/downtime' },
            ]}
        >
            <Head title="Downtime & paper records" />
            <PageLayout hero={header}>
                <div className="space-y-5">
                    <ListCaption
                        title={
                            can_manage
                                ? 'Recorded downtimes'
                                : 'Your paper records'
                        }
                        caption={`${rows.length} shown on this page · ${downtimes.total} in your scope`}
                    />
                    {!rows.length ? (
                        <Card className="p-8 text-center">
                            <FileText className="mx-auto mb-3 size-8 text-muted-foreground" />
                            <p className="text-section-title">
                                No paper records in this view
                            </p>
                            <p className="text-subtle">
                                Paper outcomes are never filled in for you.
                            </p>
                            {can_manage && (
                                <Button
                                    className="frontline-tap mt-4"
                                    onClick={() => setDeclare(true)}
                                >
                                    Record a downtime
                                </Button>
                            )}
                        </Card>
                    ) : (
                        <>
                            <div className="hidden md:block">
                                <EntityTable
                                    rows={rows}
                                    rowKey={(row) => row.id}
                                    identity={(row) => ({
                                        icon: FileText,
                                        name: `DT-${row.id} · ${row.site}`,
                                        subline: row.description,
                                    })}
                                    columns={[
                                        {
                                            key: 'period',
                                            label: 'Actual downtime (NZ)',
                                            width: '1.3fr',
                                            cell: (row) => (
                                                <div>
                                                    <p>
                                                        {formatDateTime(
                                                            row.started_at,
                                                        )}
                                                    </p>
                                                    <p className="text-subtle">
                                                        to{' '}
                                                        {formatDateTime(
                                                            row.ended_at,
                                                        )}
                                                    </p>
                                                </div>
                                            ),
                                        },
                                        {
                                            key: 'state',
                                            label: 'Paper collection',
                                            width: '1fr',
                                            cell: (row) => (
                                                <StatusBadge
                                                    variant={
                                                        row.finished_at
                                                            ? 'neutral'
                                                            : 'warning'
                                                    }
                                                >
                                                    {row.finished_at
                                                        ? 'Paper collected'
                                                        : 'To collect'}
                                                </StatusBadge>
                                            ),
                                        },
                                    ]}
                                    actionsFor={actions}
                                    onOpen={open}
                                    onRowContextMenu={context.open}
                                    rowHeight="content"
                                />
                            </div>
                            <div className="md:hidden">
                                <EntityCardGrid>
                                    {rows.map((row) => (
                                        <EntityCard
                                            key={row.id}
                                            icon={FileText}
                                            name={`DT-${row.id} · ${row.site}`}
                                            subline={`${formatDateTime(row.started_at)} to ${formatDateTime(row.ended_at)}`}
                                            meridian={
                                                row.finished_at
                                                    ? 'neutral'
                                                    : 'warning'
                                            }
                                            actions={actions(row)}
                                            onOpen={() => open(row)}
                                            onContextMenu={(event) =>
                                                context.open(event, row)
                                            }
                                            chips={
                                                <StatusBadge
                                                    variant={
                                                        row.finished_at
                                                            ? 'neutral'
                                                            : 'warning'
                                                    }
                                                >
                                                    {row.finished_at
                                                        ? 'Paper collected'
                                                        : 'To collect'}
                                                </StatusBadge>
                                            }
                                            footer={{
                                                primary: row.description,
                                            }}
                                        />
                                    ))}
                                </EntityCardGrid>
                            </div>
                        </>
                    )}
                    {downtimes.links.length > 3 && (
                        <nav
                            aria-label="Downtime pages"
                            className="flex flex-wrap gap-2"
                        >
                            {downtimes.links.map((link, index) =>
                                link.url ? (
                                    <Button
                                        key={index}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        className="frontline-tap"
                                        asChild
                                    >
                                        <Link href={link.url}>
                                            {link.label
                                                .replace(/&laquo;|&raquo;/g, '')
                                                .trim()}
                                        </Link>
                                    </Button>
                                ) : (
                                    <Button
                                        key={index}
                                        variant="outline"
                                        className="frontline-tap"
                                        disabled
                                    >
                                        {link.label
                                            .replace(/&laquo;|&raquo;/g, '')
                                            .trim()}
                                    </Button>
                                ),
                            )}
                        </nav>
                    )}
                    <p className="text-subtle">
                        Finishing paper collection does not mark a dose entered.
                        Required confirmations and clinical reconciliation carry
                        on separately.
                    </p>
                </div>
            </PageLayout>
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={`DT-${context.ctx.record.id}`}
                    icon={FileText}
                    items={actions(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            {declare && (
                <DeclareDowntimeDialog
                    sites={sites}
                    onClose={() => setDeclare(false)}
                />
            )}
            {pack && (
                <PackDialog
                    sites={sites}
                    today={today}
                    tomorrow={tomorrow}
                    onClose={() => setPack(false)}
                />
            )}
        </AppLayout>
    );
}
