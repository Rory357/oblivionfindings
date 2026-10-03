import { ConfirmDialog } from '@/components/confirm-dialog';
import { FilePreviewDialog, type PreviewFile } from '@/components/files/file-preview-dialog';
import { type MenuItem } from '@/components/lists/entity-menu';
import { PageHeader, PageHeaderFilterSelect, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderRail, PageHeaderSearch, PageHeaderStatusChip } from '@/components/page/page-header';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, Camera, ClipboardCheck, Eye, Package, Pill, ShieldCheck, Truck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CountReview, CountWizard, MovementDialog, NewSupplyOrder, SupplyOrderDialog } from './_dialogs';
import { ReceiveWizard } from './_receive';
import { PackPhotoDialog } from './_photo';
import { useStockCommand } from './_requests';
import { TableList } from './_table-list';
import type { Capabilities, ItemDetail, Movement, Pager, StockCount, StockItem, SupplyOrder } from './_types';

const states: Record<string, { label: string; variant: StatusVariant }> = {
    unknown: { label: 'Not counted yet', variant: 'neutral' }, out: { label: 'Out of stock', variant: 'critical' },
    expired: { label: 'Expired pack', variant: 'critical' }, low: { label: 'Running low', variant: 'warning' }, ok: { label: 'In stock', variant: 'success' },
    draft: { label: 'Draft', variant: 'neutral' }, submitted: { label: 'Contact recorded', variant: 'info' }, confirmed: { label: 'Confirmed', variant: 'info' },
    dispensed: { label: 'To receive', variant: 'warning' }, part_received: { label: 'Part received', variant: 'warning' }, delivered: { label: 'Received', variant: 'success' },
    received: { label: 'Received', variant: 'success' }, closed_short: { label: 'Closed short', variant: 'neutral' }, cancelled: { label: 'Cancelled', variant: 'neutral' },
    needs_review: { label: 'Count to sign off', variant: 'warning' }, counted: { label: 'Count matches', variant: 'success' }, reviewed: { label: 'Signed off', variant: 'success' },
};
function State({ value }: { value: string }) {
    const state = states[value] ?? { label: value.replaceAll('_', ' '), variant: 'neutral' as const };
    return <StatusBadge variant={state.variant}>{state.label}</StatusBadge>;
}
const moveLabels: Record<string, string> = { opening: 'Recorded balance', received: 'Received', given: 'Given', going_out: 'Went out', coming_back: 'Came back', returned_pharmacy: 'Returned to pharmacy', removed_expired: 'Expired pack removed', damaged: 'Damaged stock removed', quarantined: 'Pack taken out of use', count_correction: 'Count correction', controlled_receipt: 'Controlled receipt' };
type View = 'stock' | 'orders' | 'counts' | 'expiring' | 'removals' | 'movements';
type Props = {
    items: Pager<StockItem>; orders: Pager<SupplyOrder>; counts: Pager<StockCount>; movements: Pager<Movement>;
    sites: { id: number; name: string }[]; pharmacies: string[];
    filters: { view: View; search: string; site_id: number | null; show?: string };
    metrics: { tracked: number; out: number; expiring: number; orders: number; counts: number };
    can: Capabilities; lots_enabled: boolean; focused_count?: StockCount | null;
};
type Modal = { kind: 'item'; id: number; action?: 'receive' | 'count' | 'order' | 'move' | 'going_out' | 'coming_back'; order?: SupplyOrder } | { kind: 'order'; order: SupplyOrder } | { kind: 'count'; record: StockCount } | null;

export default function StockHub({ items, orders, counts, movements, sites, pharmacies = [], filters, metrics, can, lots_enabled, focused_count = null }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [modal, setModal] = useState<Modal>(null);
    useEffect(() => { if (focused_count) setModal({ kind: 'count', record: focused_count }); }, [focused_count]);
    const refresh = () => router.reload({ only: ['items', 'orders', 'counts', 'movements', 'metrics', 'pharmacies'], preserveScroll: true });
    const visit = (changes: Record<string, string | number | null>) => router.get('/emar/stock/packs', { ...filters, search, ...changes }, { preserveState: true, preserveScroll: true });
    const openItem = (item: StockItem, action?: Exclude<Extract<Modal, { kind: 'item' }>['action'], undefined>) => setModal({ kind: 'item', id: item.id, action });
    const itemActions = (item: StockItem): MenuItem[] => [
        { label: 'Open the stock item', icon: Eye, onClick: () => openItem(item) },
        ...(can.receive && !item.controlled ? [
            { label: 'Receive a delivery', icon: Truck, onClick: () => openItem(item, 'receive'), disabled: !item.lots_started && item.stock_id !== null ? 'Pack tracking has not been set up.' : !item.active ? 'This medicine is no longer active.' : undefined },
            { label: 'Count it', icon: ClipboardCheck, onClick: () => openItem(item, 'count'), disabled: !item.lots_started ? 'Pack tracking has not been set up.' : undefined },
            { label: 'Going out or coming back', icon: ArrowLeftRight, onClick: () => openItem(item, 'going_out'), disabled: !item.lots_started ? 'Pack tracking has not been set up.' : undefined },
        ] : []),
        ...(can.manage && !item.controlled ? [
            { label: 'Order from the pharmacy', icon: Truck, onClick: () => openItem(item, 'order'), disabled: !item.active ? 'This medicine is no longer active.' : undefined },
            { label: 'Adjust or remove', icon: Package, onClick: () => openItem(item, 'move'), disabled: !item.lots_started ? 'Pack tracking has not been set up.' : undefined },
        ] : []),
        ...(item.controlled ? [{ label: 'Open the controlled register', icon: ShieldCheck, onClick: () => router.visit('/emar/controlled') }] : []),
    ];
    const orderOpen = (order: SupplyOrder) => setModal({ kind: 'order', order });
    const orderActions = (order: SupplyOrder): MenuItem[] => [
        { label: 'Open the supply record', icon: Eye, onClick: () => orderOpen(order) },
        ...(can.receive && !order.controlled && ['dispensed', 'part_received'].includes(order.status) ? [{ label: 'Receive this delivery', icon: Truck, onClick: () => setModal({ kind: 'item', id: order.client_medication_id, action: 'receive', order }) }] : []),
    ];
    const countOpen = (record: StockCount) => setModal({ kind: 'count', record });
    const rail = [
        { key: 'stock' as const, label: 'Stock', icon: Package }, { key: 'orders' as const, label: 'Deliveries & orders', icon: Truck, count: metrics.orders },
        { key: 'counts' as const, label: 'Counts', icon: ClipboardCheck, count: metrics.counts, alert: metrics.counts > 0 },
        { key: 'expiring' as const, label: 'Expiring', icon: Package }, { key: 'removals' as const, label: 'Removals', icon: Package }, { key: 'movements' as const, label: 'Movements', icon: ArrowLeftRight },
        ...(can.controlled ? [{ key: 'controlled' as const, label: 'Controlled', icon: ShieldCheck }] : []),
    ];
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar' }, { title: 'Stock & controlled drugs', href: '/emar/stock/packs' }]}>
        <Head title="Stock & controlled drugs" />
        <div className="grid min-w-0 grid-cols-1 gap-5">
            <PageHeader frontline title="Stock & controlled drugs" icon={Package} titleChip={<PageHeaderStatusChip variant="neutral">Supply and pack records</PageHeaderStatusChip>} subline="Person-owned medicines, deliveries and counts"
                actions={<PageHeaderSearch value={search} onChange={setSearch} placeholder="Search medicines or batches" onKeyDown={(event) => event.key === 'Enter' && visit({})} />}
                meters={<>
                    <PageHeaderMeterBlock label="Tracked" onClick={() => visit({ view: 'stock', show: 'all' })}><PageHeaderMeterBig>{metrics.tracked}</PageHeaderMeterBig><PageHeaderMeterCaption>Stock records</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Out of stock" tone={metrics.out ? 'critical' : 'brand'} onClick={() => visit({ view: 'stock', show: 'out' })}><PageHeaderMeterBig>{metrics.out}</PageHeaderMeterBig><PageHeaderMeterCaption>Check supply</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="To receive" onClick={() => visit({ view: 'orders' })}><PageHeaderMeterBig>{metrics.orders}</PageHeaderMeterBig><PageHeaderMeterCaption>Open supply records</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Expiring" onClick={() => visit({ view: 'expiring' })}><PageHeaderMeterBig>{metrics.expiring}</PageHeaderMeterBig><PageHeaderMeterCaption>Packs within 30 days</PageHeaderMeterCaption></PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Counts to sign off" tone={metrics.counts ? 'warning' : 'brand'} onClick={() => visit({ view: 'counts' })}><PageHeaderMeterBig>{metrics.counts}</PageHeaderMeterBig><PageHeaderMeterCaption>Lead review needed</PageHeaderMeterCaption></PageHeaderMeterBlock>
                </>}
                filters={<><PageHeaderFilterSelect label="House" value={filters.site_id ? String(filters.site_id) : 'all'} options={[{ value: 'all', label: 'My approved houses' }, ...sites.map((site) => ({ value: String(site.id), label: site.name }))]} onChange={(value) => visit({ site_id: value === 'all' ? null : Number(value) })} />{filters.view === 'stock' && <PageHeaderFilterSelect label="Show" value={filters.show ?? 'all'} options={[{ value: 'all', label: 'All stock' }, { value: 'out', label: 'Out of stock' }, { value: 'low', label: 'Running low' }]} onChange={(value) => visit({ show: value })} />}</>}
                rail={<PageHeaderRail items={rail} value={filters.view} onSelect={(view) => view === 'controlled' ? router.visit('/emar/controlled') : visit({ view })} />} />
            {['stock', 'expiring'].includes(filters.view) && <TableList title={filters.view === 'expiring' ? 'Packs needing an expiry check' : 'Stock'} pager={items} name={(item) => item.name} subline={(item) => `${item.client_name} · ${item.site_name ?? 'House not available'}`} open={openItem} actions={itemActions} columns={[
                { key: 'quantity', label: 'On hand', width: '1fr', cell: (item) => <span>{item.on_hand === null ? 'Unknown' : `${item.on_hand} ${item.unit ?? ''}`}</span> },
                { key: 'next', label: 'Use first', width: '1.5fr', cell: (item) => <div><span>{item.next_batch ?? (item.lots_started ? 'Batch unknown' : 'Packs not set up')}</span><p className="text-caption">{item.next_expiry ? formatDateOnly(item.next_expiry) : 'Expiry unknown'}</p></div> },
                { key: 'state', label: 'Supply status', width: '1.2fr', cell: (item) => <div><State value={item.state} />{item.expired_packs > 0 && <p className="text-caption">{item.expired_packs} expired {item.expired_packs === 1 ? 'pack' : 'packs'} — take out of use</p>}</div> },
            ]} />}
            {filters.view === 'orders' && <TableList title="Deliveries and pharmacy orders" pager={orders} name={(order) => order.medication_name} subline={(order) => `${order.client_name} · Order #${order.id}`} open={orderOpen} actions={orderActions} columns={[
                { key: 'pharmacy', label: 'Pharmacy', width: '1.3fr', cell: (order) => order.pharmacy_name },
                { key: 'qty', label: 'Received / ordered', width: '1fr', cell: (order) => `${Number(order.quantity_received ?? 0)} / ${order.quantity_ordered}` },
                { key: 'due', label: 'Due to arrive', width: '1fr', cell: (order) => order.expected_delivery ? formatDateOnly(order.expected_delivery) : 'Not recorded' },
                { key: 'status', label: 'Status', width: '1.1fr', cell: (order) => <State value={order.status} /> },
            ]} />}
            {filters.view === 'counts' && <TableList title="Recent counts" pager={counts} name={(record) => record.medication_name} subline={(record) => record.client_name} open={countOpen} actions={(record) => [{ label: record.state === 'needs_review' && can.manage ? 'Review this count' : 'Open the count', icon: ClipboardCheck, onClick: () => countOpen(record) }]} columns={[
                { key: 'counted', label: 'Counted', width: '1.2fr', cell: (record) => formatDateTime(record.counted_at) },
                { key: 'person', label: 'Counted by', width: '1fr', cell: (record) => record.counted_by_name ?? 'Unknown' },
                { key: 'status', label: 'Result', width: '1fr', cell: (record) => <State value={record.state} /> },
            ]} />}
            {['movements', 'removals'].includes(filters.view) && <TableList title={filters.view === 'removals' ? 'Removed or taken out of use' : 'Recent movements'} pager={movements} name={(move) => move.medication_name} subline={(move) => move.client_name} open={(move) => setModal({ kind: 'item', id: move.medication_id })} actions={(move) => [{ label: 'Open the stock item', icon: Eye, onClick: () => setModal({ kind: 'item', id: move.medication_id }) }]} columns={[
                { key: 'kind', label: 'Movement', width: '1.1fr', cell: (move) => moveLabels[move.kind] ?? move.kind.replaceAll('_', ' ') },
                { key: 'qty', label: 'Quantity', width: '0.7fr', cell: (move) => move.quantity },
                { key: 'why', label: 'Reason', width: '1.8fr', cell: (move) => move.reason },
                { key: 'when', label: 'Recorded', width: '1.2fr', cell: (move) => formatDateTime(move.recorded_at) },
            ]} />}
        </div>
        {modal?.kind === 'item' && <ItemWorkspace id={modal.id} initialAction={modal.action} order={modal.order} pharmacies={pharmacies} can={can} lotsEnabled={lots_enabled} onClose={() => setModal(null)} onSaved={refresh} />}
        {modal?.kind === 'order' && <SupplyOrderDialog order={modal.order} canManage={can.manage} onClose={() => setModal(null)} onSaved={refresh} onReceive={() => setModal({ kind: 'item', id: modal.order.client_medication_id, action: 'receive', order: modal.order })} />}
        {modal?.kind === 'count' && <CountReview record={modal.record} canManage={can.manage} onClose={() => setModal(null)} onSaved={refresh} />}
    </AppLayout>;
}

function ItemWorkspace({ id, initialAction, order, pharmacies, can, lotsEnabled, onClose, onSaved }: {
    id: number; initialAction?: 'receive' | 'count' | 'order' | 'move' | 'going_out' | 'coming_back'; order?: SupplyOrder; pharmacies: string[]; can: Capabilities; lotsEnabled: boolean; onClose: () => void; onSaved: () => void;
}) {
    const [item, setItem] = useState<ItemDetail | null>(null);
    const [error, setError] = useState(false);
    const [retry, setRetry] = useState(0);
    const [action, setAction] = useState<typeof initialAction | 'photo' | 'order_detail'>(initialAction);
    const [selectedOrder, setSelectedOrder] = useState<SupplyOrder | null>(null);
    const [setupConfirm, setSetupConfirm] = useState(false);
    const [section, setSection] = useState(0);
    const [file, setFile] = useState<PreviewFile | null>(null);
    const command = useStockCommand();
    useEffect(() => {
        const controller = new AbortController();
        fetch(`/emar/stock/packs/medicine/${id}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
            .then(async (response) => { if (!response.ok) throw new Error('unavailable'); return response.json(); })
            .then((data: ItemDetail) => { setItem(data); setError(false); })
            .catch((failure) => { if (failure.name !== 'AbortError') setError(true); });
        return () => controller.abort();
    }, [id, retry]);
    if (!item || error) return <SettingsModal title="Stock item" description="Person-owned pack and movement records" onClose={onClose}>{error ? <ErrorState title="We cannot show this record" message="It may be unavailable or outside your medication access." onRetry={() => { setError(false); setRetry(retry + 1); }} /> : <SkeletonTable rows={3} />}</SettingsModal>;
    const back = () => { setAction(undefined); setSelectedOrder(null); setRetry(retry + 1); };
    if (action === 'photo') return <PackPhotoDialog item={item} onClose={back} onSaved={onSaved} />;
    if (action === 'order_detail' && selectedOrder) return <SupplyOrderDialog order={selectedOrder} canManage={can.manage} onClose={back} onSaved={onSaved} onReceive={() => setAction('receive')} />;
    if (action === 'receive') return <ReceiveWizard item={item} order={selectedOrder ?? order} onClose={back} onSaved={onSaved} />;
    if (action === 'count') return <CountWizard item={item} onClose={back} onSaved={onSaved} />;
    if (action === 'order') return <NewSupplyOrder item={item} pharmacies={pharmacies} onClose={back} onSaved={onSaved} />;
    if (action === 'move' || action === 'going_out' || action === 'coming_back') return <MovementDialog item={item} mode={action} onClose={back} onSaved={onSaved} />;
    const photos = item.packs.flatMap((pack) => pack.photos.map((photo) => ({ pack, photo })));
    const setup = async () => {
        const result = await command.run({ action: 'initialise', client_medication_id: item.id, confirm_balance: true });
        if (result) { setSetupConfirm(false); setRetry(retry + 1); onSaved(); }
    };
    return <>
        <WizardShell open onClose={onClose} title={item.name} description={item.client_name} railIcon={Pill} railTitle={item.name} railSub={item.client_name} sequential={false}
            steps={[{ key: 'medicine', label: 'This medicine', blurb: item.client_name, icon: Pill }, { key: 'packs', label: 'Packs', blurb: `${item.packs.length} kept`, icon: Package }, { key: 'movements', label: 'Movements', blurb: 'Permanent history', icon: ArrowLeftRight }, { key: 'orders', label: 'Pharmacy orders', blurb: 'Supply and deliveries', icon: Truck }, { key: 'photos', label: 'Pack photos', blurb: `${photos.length} kept`, icon: Camera }]} stepIndex={section} onStepClick={setSection} headerLabel={['This medicine', 'Packs', 'Movements', 'Pharmacy orders', 'Pack photos'][section]}
            footerEnd={<Button onClick={onClose}>Close</Button>}>
            <div className="grid gap-5">
                {section === 0 && <>
                    <State value={item.state} /><ReviewCard icon={Pill} title={item.name}><ReviewRow label="Person" value={item.client_name} /><ReviewRow label="House" value={item.site_name} /><ReviewRow label={item.controlled ? 'Register balance' : 'Usable on hand'} value={item.on_hand === null ? 'Unknown' : `${item.on_hand} ${item.unit}`} /><ReviewRow label="Last counted" value={item.last_counted_at ? formatDateTime(item.last_counted_at) : 'Not counted yet'} /><ReviewRow label="Days of supply" value={item.days_supply === null ? 'Not available — stock-use quantity is not configured' : item.days_supply} /></ReviewCard>
                    {!item.lots_started && <SettingsNotice role="note">{lotsEnabled ? 'The recorded stock balance has not been set up as packs yet. Check the recorded balance before carrying it forward.' : 'Pack tracking is awaiting integration review. Existing balances and history are retained.'}</SettingsNotice>}
                    {Object.values(command.errors).map((message) => <SettingsNotice key={message}>{message}</SettingsNotice>)}
                    {!item.lots_started && lotsEnabled && can.manage && !item.controlled && <Button disabled={command.saving || item.on_hand === null} onClick={() => setSetupConfirm(true)}>Use the checked recorded balance</Button>}
                    <div className="flex flex-wrap gap-2">
                        {can.receive && !item.controlled && <><Button disabled={!item.lots_started && item.stock_id !== null} onClick={() => setAction('receive')}>Receive a delivery</Button><Button variant="outline" disabled={!item.lots_started} onClick={() => setAction('count')}>Count it</Button><Button variant="outline" disabled={!item.lots_started || !item.pack_count} onClick={() => setAction('going_out')}>Going out</Button>{item.outward.length > 0 && <Button variant="outline" onClick={() => setAction('coming_back')}>Coming back</Button>}</>}
                        {can.manage && !item.controlled && <><Button variant="outline" onClick={() => setAction('order')}>Order from pharmacy</Button>{item.lots_started && <Button variant="outline" onClick={() => setAction('move')}>Adjust or remove</Button>}</>}
                        {item.controlled && <Button variant="outline" onClick={() => router.visit('/emar/controlled')}>Open the controlled register</Button>}
                    </div>
                </>}
                {section === 1 && (item.packs.length ? item.packs.map((pack) => <ReviewCard key={pack.id} icon={Package} title={pack.batch_number ?? (pack.batch_not_printed ? 'Batch not printed on the pack' : 'Batch unknown')}><ReviewRow label="Remaining" value={`${pack.quantity_remaining} ${item.unit}`} /><ReviewRow label="Expiry" value={pack.expiry_date ? formatDateOnly(pack.expiry_date.slice(0, 10)) : pack.expiry_not_printed ? 'Not printed on the pack' : 'Unknown'} /><ReviewRow label="Source" value={pack.source_reference ?? pack.source.replaceAll('_', ' ')} /><ReviewRow label="Recorded" value={formatDateTime(pack.received_at)} /><ReviewRow label="Pack state" value={pack.state === 'quarantined' ? 'Out of use' : 'Open'} />{pack.source === 'recorded_balance' && <p className="text-caption">Carried forward from the recorded balance. Original receipt date and label checks are unknown.</p>}</ReviewCard>) : <EmptyState icon={Package} title="No packs yet" description="No pack receipts have been recorded." />)}
                {section === 2 && ((item.movements ?? []).length ? <>{item.movements?.map((move) => <ReviewCard key={move.id} icon={ArrowLeftRight} title={moveLabels[move.kind] ?? move.kind.replaceAll('_', ' ')}><ReviewRow label="Quantity" value={`${move.quantity} ${item.unit}`} /><ReviewRow label="Pack balance before / after" value={`${move.balance_before} / ${move.balance_after}`} /><ReviewRow label="Reason" value={move.reason} /><ReviewRow label="Recorded" value={`${formatDateTime(move.recorded_at)} · ${move.recorded_by_name ?? 'Unknown'}`} />{move.notes && <p>{move.notes}</p>}</ReviewCard>)}{item.history_truncated && <Button variant="outline" onClick={() => router.visit(`/emar/stock/packs?view=movements&medication_id=${item.id}`)}>Open all movements</Button>}</> : <EmptyState icon={ArrowLeftRight} title="No movements yet" description="Recorded receipts, doses, returns and adjustments appear here." />)}
                {section === 3 && ((item.orders ?? []).length ? item.orders?.map((supply) => <ReviewCard key={supply.id} icon={Truck} title={`${supply.pharmacy_name} · #${supply.id}`}><State value={supply.status} /><ReviewRow label="Received / ordered" value={`${Number(supply.quantity_received ?? 0)} / ${supply.quantity_ordered}`} /><ReviewRow label="Due to arrive" value={supply.expected_delivery ? formatDateOnly(supply.expected_delivery) : 'Not recorded'} /><Button variant="outline" onClick={() => { setSelectedOrder(supply); setAction('order_detail'); }}>Open supply record</Button></ReviewCard>) : <EmptyState icon={Truck} title="No pharmacy orders yet" description="Supply records for this person and medicine appear here." />)}
                {section === 4 && <>{can.manage && item.packs.length > 0 && <Button onClick={() => setAction('photo')}>Add or replace a pack photo</Button>}<SettingsNotice role="note">Photos are supplemental identification. Check the current order and pack label. A changed pack or brand needs a new photo.</SettingsNotice>{photos.length ? photos.map(({ pack, photo }) => <ReviewCard key={photo.id} icon={Camera} title={photo.original_name}><ReviewRow label="Pack" value={pack.batch_number ?? 'Batch unknown'} /><ReviewRow label="Added" value={`${formatDateTime(photo.taken_at)} · ${photo.taken_by_name ?? 'Unknown'}`} /><Button variant="outline" onClick={() => setFile({ id: photo.id, name: 'Medicine pack photo', filename: photo.original_name, mime: photo.mime, bytes: photo.bytes, source: pack.batch_number ?? 'Batch unknown', previewUrl: photo.preview_url, downloadUrl: photo.download_url })}>View and download</Button></ReviewCard>) : <EmptyState icon={Camera} title="No pack photos yet" description="A receipt can be saved without a photo." />}</>}
            </div>
        </WizardShell>
        <ConfirmDialog open={setupConfirm} onClose={() => setSetupConfirm(false)} onConfirm={() => void setup()} processing={command.saving} title="Carry forward this checked balance?" description={`This creates pack records from ${item.on_hand ?? 'unknown'} ${item.unit ?? ''}. Original receipt dates and label checks remain unknown. Existing stock history is retained.`} confirmText="Use checked balance" variant="default" />
        <FilePreviewDialog file={file} onClose={() => setFile(null)} />
    </>;
}

