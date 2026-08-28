/* Read-only prescriber-order detail — opened from an order row (click or the
 * right-click "View details" action). Built on the Add-Client WizardShell chrome
 * (rail + sectioned panes + footer Options bar) so it matches every other popup
 * workflow (mirrors components/emar/prn-detail-dialog.tsx); the primary actions
 * open the relevant wizard in place rather than navigating off-page — only
 * "View client" / "Open on MAR" navigate. Colours are semantic tokens. */
import {
    countersignHoursLeft,
    needsCountersign,
    orderStatusTone,
    type CovertAuth,
    type PrescriptionOrder,
} from '@/components/emar/prescriptions/types';
import { Button } from '@/components/ui/button';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import {
    CalendarClock,
    FileText,
    Link2,
    Package,
    PenTool,
    Pill,
    ShieldCheck,
    Stethoscope,
    User,
} from 'lucide-react';
import { useState } from 'react';

const SECTIONS: WizardStep[] = [
    {
        key: 'order',
        label: 'Order',
        blurb: 'Prescriber, medication & dosing',
        icon: FileText,
    },
    {
        key: 'lifecycle',
        label: 'Countersign & dispensing',
        blurb: 'Sign-off, supply & audit',
        icon: PenTool,
    },
];

const ORDER_SOURCE: Record<string, string> = {
    new: 'Written — new',
    change: 'Written — change',
    cease: 'Written — cease',
    verbal: 'Verbal order',
    telephone: 'Telephone order',
};

/** Status pill shown in the rail subtitle + order card. */
function StatusPill({ status }: { status: string }) {
    return (
        <span
            className={`rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${orderStatusTone(status)}`}
        >
            {status}
        </span>
    );
}

export function OrderDetailDialog({
    order,
    covert,
    linkedMedName,
    canCountersign = false,
    canDispense = false,
    canLink = false,
    onClose,
    onCountersign,
    onDispense,
    onLink,
}: {
    order: PrescriptionOrder;
    /** Matching active covert authorisation for this client + medication, if any. */
    covert: CovertAuth | null;
    /** Resolved charted-medication name for the linked MAR entry, if linked. */
    linkedMedName: string | null;
    /** Exact server-authorised transition capabilities for this order. */
    canCountersign?: boolean;
    canDispense?: boolean;
    canLink?: boolean;
    onClose: () => void;
    /** Open the countersign wizard for this order (in place). */
    onCountersign: () => void;
    /** Open the dispensing wizard for this order (in place). */
    onDispense: () => void;
    /** Open the link-to-MAR wizard for this order (in place). */
    onLink: () => void;
}) {
    const [section, setSection] = useState(0);
    const awaiting = order.status === 'pending' && needsCountersign(order);
    const hrs = countersignHoursLeft(order);
    const overdue = hrs !== null && hrs < 0;
    const source = ORDER_SOURCE[order.order_type] ?? order.order_type;

    const countersignValue = !order.requires_countersign ? (
        'Not required'
    ) : order.countersigned_at ? (
        <span className="text-status-success">✓ Signed</span>
    ) : !awaiting ? (
        <span className="text-muted-foreground">Not signed</span>
    ) : overdue ? (
        <span className="font-semibold text-status-critical">
            Overdue by {Math.abs(hrs!)}h
        </span>
    ) : (
        <span className="font-semibold text-status-warning">
            {hrs}h remaining
        </span>
    );

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Prescriber order detail"
            description="Read-only detail of a prescriber order, its countersignature and dispensing."
            railIcon={FileText}
            railTitle={order.client_name}
            railSub={
                [order.client_room, order.client_site]
                    .filter(Boolean)
                    .join(' · ') || 'Prescriber order'
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
                    {canCountersign && awaiting ? (
                        <Button type="button" onClick={onCountersign}>
                            <PenTool className="h-4 w-4" /> Countersign
                        </Button>
                    ) : null}
                    {canDispense && order.status === 'confirmed' ? (
                        <Button
                            type="button"
                            variant={awaiting ? 'outline' : 'default'}
                            onClick={onDispense}
                        >
                            <Package className="h-4 w-4" /> Dispense
                        </Button>
                    ) : null}
                    {canLink && order.status === 'pending' ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onLink}
                        >
                            <Link2 className="h-4 w-4" /> Link to MAR
                        </Button>
                    ) : null}
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() =>
                            router.visit(
                                `/operations/clients/${order.client_id}?tab=mar`,
                            )
                        }
                    >
                        <User className="h-4 w-4" /> Client
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() =>
                            router.visit(`/clients/${order.client_id}/mar`)
                        }
                    >
                        <FileText className="h-4 w-4" /> MAR
                    </Button>
                </>
            }
        >
            {section === 0 ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <ReviewCard icon={User} title="Resident">
                        <ReviewRow label="Name" value={order.client_name} />
                        <ReviewRow label="Room" value={order.client_room} />
                        <ReviewRow label="Site" value={order.client_site} />
                    </ReviewCard>
                    <ReviewCard icon={FileText} title="Order">
                        <ReviewRow
                            label="Status"
                            value={<StatusPill status={order.status} />}
                        />
                        <ReviewRow label="Source" value={source} />
                        <ReviewRow
                            label="Order date"
                            value={order.order_date}
                        />
                        <ReviewRow
                            label="Effective"
                            value={order.effective_date}
                        />
                        <ReviewRow label="Expiry" value={order.expiry_date} />
                    </ReviewCard>
                    <ReviewCard icon={Pill} title="Medication & dosing" span>
                        <ReviewRow
                            label="Medication"
                            value={order.medication_name}
                        />
                        <ReviewRow label="Dose" value={order.dose} />
                        <ReviewRow label="Route" value={order.route} />
                        <ReviewRow label="Frequency" value={order.frequency} />
                        <ReviewRow
                            label="Indication"
                            value={order.indication}
                        />
                        <ReviewRow
                            label="Instructions"
                            value={order.instructions}
                        />
                    </ReviewCard>
                    <ReviewCard icon={Stethoscope} title="Prescriber" span>
                        <ReviewRow label="Name" value={order.prescriber_name} />
                        <ReviewRow
                            label="Registration"
                            value={order.prescriber_registration}
                        />
                        <ReviewRow label="Type" value={order.prescriber_type} />
                    </ReviewCard>
                </div>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    <ReviewCard icon={PenTool} title="Countersignature" span>
                        <ReviewRow label="Status" value={countersignValue} />
                        <ReviewRow
                            label="Method"
                            value={order.countersign_method}
                        />
                        <ReviewRow
                            label="Signed by"
                            value={order.countersigned_by_name}
                        />
                        <ReviewRow
                            label="Read-back"
                            value={
                                order.read_back_confirmed ? 'Confirmed' : null
                            }
                        />
                        <ReviewRow
                            label="Received by"
                            value={order.received_by_name}
                        />
                    </ReviewCard>
                    <ReviewCard icon={Package} title="Dispensing">
                        {order.dispensed_at ? (
                            <>
                                <ReviewRow
                                    label="Pharmacy"
                                    value={order.pharmacy_name}
                                />
                                <ReviewRow
                                    label="Batch"
                                    value={order.batch_number}
                                />
                                <ReviewRow
                                    label="Batch expiry"
                                    value={order.batch_expiry}
                                />
                                <ReviewRow
                                    label="Dispensed by"
                                    value={order.dispensed_by_name}
                                />
                            </>
                        ) : (
                            <ReviewRow
                                label="Status"
                                value={
                                    <span className="text-muted-foreground">
                                        Not yet dispensed
                                    </span>
                                }
                            />
                        )}
                    </ReviewCard>
                    <ReviewCard icon={Link2} title="Linked MAR entry">
                        <ReviewRow
                            label="Charted medication"
                            value={linkedMedName}
                        />
                        {!linkedMedName ? (
                            <ReviewRow
                                label="Status"
                                value={
                                    <span className="text-muted-foreground">
                                        Not linked to a chart
                                    </span>
                                }
                            />
                        ) : null}
                    </ReviewCard>
                    {covert ? (
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Covert authorisation"
                            span
                        >
                            <ReviewRow
                                label="Status"
                                value={
                                    <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-semibold text-status-critical">
                                        Active
                                    </span>
                                }
                            />
                            <ReviewRow
                                label="Method"
                                value={covert.administration_method}
                            />
                            <ReviewRow
                                label="Authorised by"
                                value={covert.authorised_by_name}
                            />
                            <ReviewRow
                                label="Next review"
                                value={
                                    covert.review_date ? (
                                        <span
                                            className={
                                                covert.review_overdue
                                                    ? 'inline-flex items-center gap-1 text-status-critical'
                                                    : undefined
                                            }
                                        >
                                            {covert.review_overdue ? (
                                                <CalendarClock className="h-3 w-3" />
                                            ) : null}
                                            {covert.review_date}
                                            {covert.review_overdue
                                                ? ' · overdue'
                                                : ''}
                                        </span>
                                    ) : null
                                }
                            />
                        </ReviewCard>
                    ) : null}
                </div>
            )}
        </WizardShell>
    );
}

export default OrderDetailDialog;
