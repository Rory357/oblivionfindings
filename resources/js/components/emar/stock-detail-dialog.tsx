/* eslint-disable no-restricted-syntax -- the movement-history list is a custom-layout
   bordered surface (not a Card); all colours are semantic tokens. */
/* Read-only stock detail — opened from a stock row (click or the right-click
 * "View details" action). Built on the same WizardShell chrome (rail + sectioned
 * panes + footer Options bar) as prn-detail-dialog.tsx so it matches every other
 * eMAR popup; the primary actions open the relevant wizard in place rather than
 * navigating off-page. Colours are semantic tokens throughout. */
import { Button } from '@/components/ui/button';
import { InfoCard } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import type { StockMovement, StockRow } from '@/pages/emar/_stock-dialogs';
import { router } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    ClipboardCheck,
    FileText,
    History,
    Package,
    Pencil,
    Settings2,
    ShoppingCart,
    Snowflake,
    Truck,
    User,
} from 'lucide-react';
import { useState } from 'react';

/** Minimal open-order summary the detail modal shows (matched client-side). */
export type OpenOrderSummary = {
    status: string;
    pharmacy_name: string | null;
    order_type: string | null;
    quantity_ordered: number | null;
    ordered_at: string | null;
};

const SECTIONS: WizardStep[] = [
    {
        key: 'overview',
        label: 'Overview',
        blurb: 'Stock level, batch & flags',
        icon: Package,
    },
    {
        key: 'activity',
        label: 'Activity',
        blurb: 'Counts, orders & movements',
        icon: History,
    },
];

const fmtDate = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
const fmtDateTime = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString('en-NZ', {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

export function stockStatusPill(s: StockRow): { label: string; cls: string } {
    if (s.is_expired)
        return {
            label: 'Expired',
            cls: 'bg-status-critical-bg text-status-critical',
        };
    if (s.is_low)
        return {
            label: 'Reorder now',
            cls: 'bg-status-warning-bg text-status-warning',
        };
    if (s.is_expiring_soon)
        return {
            label: 'Expiring',
            cls: 'bg-status-warning-bg text-status-warning',
        };
    return {
        label: 'In stock',
        cls: 'bg-status-success-bg text-status-success',
    };
}

const MOVE_ICON: Record<StockMovement['type'], typeof Package> = {
    received: ArrowDownToLine,
    removed: ArrowUpFromLine,
    adjusted: Pencil,
    counted: ClipboardCheck,
    updated: Settings2,
    created: Package,
};

function MovementRow({ m }: { m: StockMovement }) {
    const Icon = MOVE_ICON[m.type] ?? Package;
    const deltaTone =
        m.delta == null
            ? ''
            : m.delta > 0
              ? 'text-status-success'
              : m.delta < 0
                ? 'text-status-critical'
                : 'text-muted-foreground';
    return (
        <li className="flex items-start gap-3 px-4 py-2.5">
            <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Icon className="h-3.5 w-3.5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex items-center justify-between gap-2">
                    <span className="truncate text-[13px] font-medium capitalize">
                        {m.type}
                    </span>
                    {m.delta != null && m.delta !== 0 ? (
                        <span
                            className={`shrink-0 font-mono text-xs font-semibold tabular-nums ${deltaTone}`}
                        >
                            {m.delta > 0 ? '+' : ''}
                            {m.delta} {m.unit ?? ''}
                        </span>
                    ) : null}
                </div>
                <div className="truncate text-xs text-muted-foreground">
                    {m.summary}
                </div>
                <div className="text-[10.5px] text-muted-foreground">
                    {[fmtDateTime(m.at), m.actor].filter(Boolean).join(' · ') ||
                        '—'}
                </div>
            </div>
        </li>
    );
}

export function StockDetailDialog({
    item,
    openOrder,
    onClose,
    onAdjust,
    onCount,
    onOrder,
}: {
    item: StockRow;
    /** Open pharmacy order for this medication, if one is in flight. */
    openOrder?: OpenOrderSummary | null;
    onClose: () => void;
    onAdjust?: () => void;
    onCount?: () => void;
    onOrder: () => void;
}) {
    const [section, setSection] = useState(0);
    const pill = stockStatusPill(item);
    const reorder = item.reorder_level ?? 0;
    const ratio =
        reorder > 0 ? Math.min(100, (item.on_hand / (reorder * 2)) * 100) : 100;
    const barTone = item.is_low
        ? 'bg-status-critical'
        : item.on_hand <= reorder * 1.4
          ? 'bg-status-warning'
          : 'bg-status-success';
    const movements = item.movements ?? [];

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Stock item detail"
            description="Read-only view of a medication stock position."
            railIcon={Package}
            railTitle={item.medication_name ?? 'Stock item'}
            railSub={
                [item.client_name, item.client_room, item.site_name]
                    .filter(Boolean)
                    .join(' · ') || 'Stock item'
            }
            steps={SECTIONS}
            stepIndex={section}
            onStepClick={setSection}
            pct={null}
            footerStart={
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                <>
                    {onAdjust ? (
                        <Button type="button" onClick={onAdjust}>
                            <Pencil className="h-4 w-4" /> Adjust stock
                        </Button>
                    ) : null}
                    {onCount ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCount}
                        >
                            <ClipboardCheck className="h-4 w-4" /> Run count
                        </Button>
                    ) : null}
                    <Button type="button" variant="outline" onClick={onOrder}>
                        <ShoppingCart className="h-4 w-4" /> Order more
                    </Button>
                    {item.client_id ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                router.visit(
                                    `/operations/clients/${item.client_id}?tab=mar`,
                                )
                            }
                        >
                            <User className="h-4 w-4" /> Client
                        </Button>
                    ) : null}
                    {item.mar_url ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => router.visit(item.mar_url!)}
                        >
                            <FileText className="h-4 w-4" /> MAR
                        </Button>
                    ) : null}
                </>
            }
        >
            {section === 0 ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <ReviewCard icon={User} title="Resident" span>
                        <ReviewRow label="Name" value={item.client_name} />
                        <ReviewRow label="Room" value={item.client_room} />
                        <ReviewRow label="Site" value={item.site_name} />
                    </ReviewCard>
                    <ReviewCard icon={Package} title="Medication">
                        <ReviewRow
                            label="Name"
                            value={
                                <span className="inline-flex items-center gap-1.5">
                                    {item.medication_name ?? '—'}
                                    {item.controlled ? (
                                        <span className="rounded bg-status-critical-bg px-1 py-0.5 text-[9px] font-bold text-status-critical">
                                            CD
                                        </span>
                                    ) : null}
                                    {item.requires_cold_chain ? (
                                        <Snowflake
                                            className="h-3.5 w-3.5 text-status-info"
                                            aria-label="Cold chain"
                                        />
                                    ) : null}
                                </span>
                            }
                        />
                        <ReviewRow label="Dose" value={item.medication_dose} />
                        <ReviewRow
                            label="Storage"
                            value={
                                STORAGE_LABEL[item.storage_condition] ??
                                item.storage_condition
                            }
                        />
                    </ReviewCard>
                    <ReviewCard icon={Package} title="Stock level">
                        <ReviewRow
                            label="On hand"
                            value={
                                <span
                                    className={`font-mono font-semibold tabular-nums ${item.is_low ? 'text-status-critical' : ''}`}
                                >
                                    {item.on_hand} {item.unit}
                                </span>
                            }
                        />
                        <ReviewRow
                            label="Reorder level"
                            value={item.reorder_level ?? '—'}
                        />
                        <ReviewRow
                            label="Reorder quantity"
                            value={item.reorder_quantity ?? '—'}
                        />
                        <ReviewRow
                            label="Status"
                            value={
                                <span
                                    className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${pill.cls}`}
                                >
                                    {pill.label}
                                </span>
                            }
                        />
                        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className={`h-full rounded-full ${barTone}`}
                                style={{ width: `${ratio}%` }}
                            />
                        </div>
                    </ReviewCard>
                    <ReviewCard icon={Truck} title="Batch & supply">
                        <ReviewRow
                            label="Batch number"
                            value={item.batch_number}
                        />
                        <ReviewRow
                            label="Expiry"
                            value={
                                item.expiry_date ? (
                                    <span
                                        className={
                                            item.is_expired
                                                ? 'text-status-critical'
                                                : item.is_expiring_soon
                                                  ? 'text-status-warning'
                                                  : ''
                                        }
                                    >
                                        {fmtDate(item.expiry_date)}
                                    </span>
                                ) : null
                            }
                        />
                        <ReviewRow
                            label="Supplier"
                            value={item.supplier_name}
                        />
                    </ReviewCard>
                </div>
            ) : (
                <div className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={ClipboardCheck} title="Last count">
                            <ReviewRow
                                label="Last counted"
                                value={
                                    item.last_counted_at
                                        ? fmtDateTime(item.last_counted_at)
                                        : 'Never'
                                }
                            />
                            <ReviewRow
                                label="On hand now"
                                value={`${item.on_hand} ${item.unit}`}
                            />
                        </ReviewCard>
                        {openOrder ? (
                            <ReviewCard
                                icon={ShoppingCart}
                                title="Open pharmacy order"
                            >
                                <ReviewRow
                                    label="Pharmacy"
                                    value={openOrder.pharmacy_name}
                                />
                                <ReviewRow
                                    label="Status"
                                    value={
                                        <span className="capitalize">
                                            {openOrder.status}
                                        </span>
                                    }
                                />
                                <ReviewRow
                                    label="Quantity"
                                    value={
                                        openOrder.quantity_ordered != null
                                            ? `${openOrder.quantity_ordered} units`
                                            : null
                                    }
                                />
                                <ReviewRow
                                    label="Ordered"
                                    value={fmtDate(openOrder.ordered_at)}
                                />
                            </ReviewCard>
                        ) : (
                            <InfoCard icon={ShoppingCart} tone="info">
                                No open pharmacy order for this item. Use “Order
                                more” to resupply.
                            </InfoCard>
                        )}
                    </div>
                    <div className="overflow-hidden rounded-xl border bg-card">
                        <div className="border-b bg-muted/40 px-4 py-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Recent movements
                        </div>
                        {movements.length === 0 ? (
                            <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                                No recorded movements yet.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border/60">
                                {movements.map((m) => (
                                    <MovementRow key={m.id} m={m} />
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            )}
        </WizardShell>
    );
}

const STORAGE_LABEL: Record<string, string> = {
    ambient: 'Ambient',
    fridge: 'Fridge (2–8°C)',
    controlled_room: 'Controlled room',
};

export default StockDetailDialog;
