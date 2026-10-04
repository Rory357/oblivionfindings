import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import InputError from '@/components/input-error';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    Check,
    ClipboardCheck,
    Package,
    ShieldCheck,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import { useStockCommand } from './_requests';
import type { ItemDetail, StockCount, StockItem, SupplyOrder } from './_types';

function Errors({ values }: { values: Record<string, string> }) {
    return Object.keys(values).length ? (
        <SettingsNotice>
            {Object.entries(values).map(([key, message]) => (
                <InputError key={key} message={message} />
            ))}
        </SettingsNotice>
    ) : null;
}
export function NewSupplyOrder({
    item,
    pharmacies,
    onClose,
    onSaved,
}: {
    item: StockItem;
    pharmacies: string[];
    onClose: () => void;
    onSaved: () => void;
}) {
    const [pharmacy, setPharmacy] = useState('');
    const [custom, setCustom] = useState(false);
    const [quantity, setQuantity] = useState('');
    const [needed, setNeeded] = useState('');
    const [notes, setNotes] = useState('');
    const [discard, setDiscard] = useState(false);
    const command = useStockCommand();
    const close = () => {
        if (!command.saving) {
            if (pharmacy || quantity || needed || notes) setDiscard(true);
            else onClose();
        }
    };
    const save = async () => {
        const result = await command.run({
            action: 'order',
            client_medication_id: item.id,
            pharmacy_name: pharmacy,
            quantity_ordered: quantity,
            needed_by: needed,
            order_notes: notes,
        });
        if (result) {
            onSaved();
            onClose();
        }
    };
    return (
        <>
            <SettingsModal
                frontline
                width={720}
                title="Order from the pharmacy"
                description={`${item.client_name} · ${item.name}`}
                onClose={close}
                footer={
                    <>
                        <Button
                            variant="outline"
                            disabled={command.saving}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                command.saving ||
                                !pharmacy ||
                                !quantity ||
                                !needed
                            }
                            onClick={() => void save()}
                        >
                            {command.saving ? 'Saving…' : 'Save supply record'}
                        </Button>
                    </>
                }
            >
                <SettingsNotice role="note">
                    This saves a supply record. Contact the pharmacy using your
                    usual channel, then record that communication here.
                </SettingsNotice>
                <Errors values={command.errors} />
                <Label>Pharmacy</Label>
                {custom ? (
                    <Input
                        aria-label="New pharmacy name"
                        id="pharmacy_name"
                        value={pharmacy}
                        onChange={(event) => setPharmacy(event.target.value)}
                    />
                ) : (
                    <RecordPicker
                        label="Pharmacy"
                        value={pharmacy}
                        options={pharmacies.map((name) => ({
                            value: name,
                            label: name,
                        }))}
                        onChange={setPharmacy}
                    />
                )}
                <Button
                    variant="outline"
                    onClick={() => {
                        setCustom(!custom);
                        setPharmacy('');
                    }}
                >
                    {custom
                        ? 'Choose a recorded pharmacy'
                        : 'Add a pharmacy name'}
                </Button>
                <Label htmlFor="quantity_ordered">
                    Quantity to order ({item.unit ?? 'supply units'})
                </Label>
                <Input
                    id="quantity_ordered"
                    type="number"
                    min="1"
                    step="1"
                    value={quantity}
                    onChange={(event) => setQuantity(event.target.value)}
                />
                <Label>Needed by</Label>
                <DatePicker
                    compact
                    id="needed_by"
                    label="Needed by"
                    value={needed}
                    onChange={setNeeded}
                />
                <Label htmlFor="order_notes">
                    Notes for this supply record
                </Label>
                <Textarea
                    id="order_notes"
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                />
            </SettingsModal>
            <ConfirmDialog
                frontline
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this supply record?"
                description="Your unsaved entries will be discarded."
                confirmText="Discard draft"
            />
        </>
    );
}
export function SupplyOrderDialog({
    order,
    canManage,
    onReceive,
    onClose,
    onSaved,
}: {
    order: SupplyOrder;
    canManage: boolean;
    onReceive: () => void;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [next, setNext] = useState<
        'contacted' | 'dispensed' | 'cancelled' | 'closed_short' | null
    >(null);
    const [method, setMethod] = useState('');
    const [reference, setReference] = useState('');
    const [dispensed, setDispensed] = useState('');
    const [due, setDue] = useState(order.expected_delivery ?? '');
    const [reason, setReason] = useState('');
    const [confirm, setConfirm] = useState(false);
    const command = useStockCommand();
    const closed = [
        'delivered',
        'received',
        'cancelled',
        'closed_short',
    ].includes(order.status);
    const save = async () => {
        if (!next) return;
        const result = await command.run({
            action: 'order_update',
            client_medication_id: order.client_medication_id,
            order_id: order.id,
            next,
            communication_method: method || null,
            communication_reference: reference || null,
            quantity_dispensed: dispensed || null,
            expected_delivery: due || null,
            reason: reason || null,
        });
        if (result) {
            setConfirm(false);
            onSaved();
            onClose();
        }
    };
    const dangerous = next === 'cancelled' || next === 'closed_short';
    return (
        <>
            <SettingsModal
                frontline
                width={720}
                title={`Pharmacy order #${order.id}`}
                description={`${order.client_name} · ${order.medication_name}`}
                onClose={() => !command.saving && onClose()}
                footer={
                    <>
                        <Button
                            variant="outline"
                            disabled={command.saving}
                            onClick={onClose}
                        >
                            Close
                        </Button>
                        {next && (
                            <Button
                                variant={dangerous ? 'destructive' : 'default'}
                                disabled={command.saving}
                                onClick={() =>
                                    dangerous ? setConfirm(true) : void save()
                                }
                            >
                                {command.saving
                                    ? 'Saving…'
                                    : dangerous
                                      ? 'Review closure'
                                      : 'Save evidence'}
                            </Button>
                        )}
                    </>
                }
            >
                <ReviewCard icon={Truck} title={order.pharmacy_name}>
                    <ReviewRow label="Ordered" value={order.quantity_ordered} />
                    <ReviewRow
                        label="Received"
                        value={Number(order.quantity_received ?? 0)}
                    />
                    <ReviewRow
                        label="Communication evidence"
                        value={order.communication_reference ?? 'Not recorded'}
                    />
                    <ReviewRow
                        label="Closure reason"
                        value={order.closure_reason}
                    />
                </ReviewCard>
                <Errors values={command.errors} />
                <SettingsNotice role="note">
                    {closed
                        ? 'This order is closed and read only. Its received supply and history are retained.'
                        : 'A saved supply record does not prove the pharmacy received it. Record the communication source and what the pharmacy actually dispensed.'}
                </SettingsNotice>
                {!closed && canManage && !order.controlled && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            {order.status === 'draft' && (
                                <Button
                                    variant="outline"
                                    onClick={() => setNext('contacted')}
                                >
                                    Record pharmacy contact
                                </Button>
                            )}
                            {['submitted', 'confirmed'].includes(
                                order.status,
                            ) && (
                                <Button
                                    variant="outline"
                                    onClick={() => setNext('dispensed')}
                                >
                                    What the pharmacy dispensed
                                </Button>
                            )}
                            {['dispensed', 'part_received'].includes(
                                order.status,
                            ) && (
                                <Button onClick={onReceive}>
                                    Receive this delivery
                                </Button>
                            )}
                            {order.status === 'part_received' ? (
                                <Button
                                    variant="outline"
                                    onClick={() => setNext('closed_short')}
                                >
                                    Close short
                                </Button>
                            ) : (
                                <Button
                                    variant="outline"
                                    onClick={() => setNext('cancelled')}
                                >
                                    Cancel order
                                </Button>
                            )}
                        </div>
                        {next === 'contacted' && (
                            <>
                                <Label>How was the pharmacy contacted?</Label>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {[
                                        ['phone', 'Phone'],
                                        ['email', 'Email'],
                                        ['in_person', 'In person'],
                                        ['secure_message', 'Secure message'],
                                    ].map(([value, label]) => (
                                        <Button
                                            key={value}
                                            variant="outline"
                                            aria-pressed={method === value}
                                            className="h-auto min-w-0 whitespace-normal"
                                            onClick={() => setMethod(value)}
                                        >
                                            {label}
                                            {method === value && (
                                                <Check className="size-4" />
                                            )}
                                        </Button>
                                    ))}
                                </div>
                                <Label htmlFor="communication_reference">
                                    Source or reference
                                </Label>
                                <Textarea
                                    id="communication_reference"
                                    value={reference}
                                    onChange={(event) =>
                                        setReference(event.target.value)
                                    }
                                />
                            </>
                        )}
                        {next === 'dispensed' && (
                            <>
                                <Label htmlFor="quantity_dispensed">
                                    Quantity the pharmacy dispensed
                                </Label>
                                <Input
                                    id="quantity_dispensed"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    value={dispensed}
                                    onChange={(event) =>
                                        setDispensed(event.target.value)
                                    }
                                />
                                <Label>Due to arrive</Label>
                                <DatePicker
                                    compact
                                    id="expected_delivery"
                                    label="Due to arrive"
                                    value={due}
                                    onChange={setDue}
                                />
                            </>
                        )}
                        {dangerous && (
                            <>
                                <Label htmlFor="reason">
                                    Why close this order?
                                </Label>
                                <Textarea
                                    id="reason"
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                />
                            </>
                        )}
                    </>
                )}
                {order.controlled && (
                    <SettingsNotice role="note">
                        The house lead receives controlled supplies through the
                        witnessed register.
                    </SettingsNotice>
                )}
            </SettingsModal>
            <ConfirmDialog
                frontline
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => void save()}
                processing={command.saving}
                title={
                    next === 'closed_short'
                        ? 'Close this order short?'
                        : 'Cancel this order?'
                }
                description={
                    next === 'closed_short'
                        ? 'The received supply stays in stock. The rest will no longer be expected. This reason stays in the order history.'
                        : 'This closes the supply record; contact the pharmacy separately if needed. The record and closure reason are kept.'
                }
                confirmText={
                    next === 'closed_short' ? 'Close short' : 'Cancel order'
                }
            />
        </>
    );
}
export function MovementDialog({
    item,
    mode,
    onClose,
    onSaved,
}: {
    item: ItemDetail;
    mode: 'move' | 'going_out' | 'coming_back';
    onClose: () => void;
    onSaved: () => void;
}) {
    const [lot, setLot] = useState('');
    const [out, setOut] = useState('');
    const [kind, setKind] = useState('returned_pharmacy');
    const [quantity, setQuantity] = useState('');
    const [used, setUsed] = useState('');
    const [reason, setReason] = useState('');
    const [confirm, setConfirm] = useState(false);
    const command = useStockCommand();
    const selected = item.packs.find((pack) => String(pack.id) === lot);
    const save = async () => {
        const result = await command.run({
            action: mode,
            client_medication_id: item.id,
            ...(mode === 'coming_back'
                ? { return_of_id: out, used_away: used }
                : { lot_id: lot }),
            ...(mode === 'move' ? { kind } : {}),
            quantity,
            reason,
        });
        if (result) {
            onSaved();
            onClose();
        }
    };
    const title =
        mode === 'coming_back'
            ? 'Record supply coming back'
            : mode === 'going_out'
              ? 'Supply going out with the person'
              : 'Adjust or remove stock';
    return (
        <>
            <SettingsModal
                frontline
                width={720}
                title={title}
                description={`${item.client_name} · ${item.name}`}
                onClose={() => !command.saving && onClose()}
                footer={
                    <>
                        <Button
                            variant="outline"
                            disabled={command.saving}
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant={
                                mode === 'move' ? 'destructive' : 'default'
                            }
                            disabled={
                                command.saving ||
                                !reason ||
                                !quantity ||
                                (mode === 'coming_back'
                                    ? !out || used === ''
                                    : !lot)
                            }
                            onClick={() =>
                                mode === 'move' ? setConfirm(true) : void save()
                            }
                        >
                            {command.saving ? 'Saving…' : 'Review and record'}
                        </Button>
                    </>
                }
            >
                <Errors values={command.errors} />
                {mode === 'coming_back' ? (
                    <>
                        <RecordPicker
                            label="Supply that went out"
                            value={out}
                            options={item.outward.map((movement) => ({
                                value: String(movement.id),
                                label: `${movement.quantity} ${item.unit} · ${movement.reason}`,
                            }))}
                            onChange={setOut}
                        />
                        <Label htmlFor="used_away">
                            Quantity used while away ({item.unit})
                        </Label>
                        <Input
                            id="used_away"
                            type="number"
                            min="0"
                            step="0.01"
                            value={used}
                            onChange={(event) => setUsed(event.target.value)}
                        />
                        <SettingsNotice role="note">
                            What came back plus what was used must match what
                            went out. The original pack and its expiry are kept.
                            Unexplained differences need the house lead.
                        </SettingsNotice>
                    </>
                ) : (
                    <>
                        <RecordPicker
                            label="Pack"
                            value={lot}
                            options={item.packs
                                .filter(
                                    (pack) =>
                                        Number(pack.quantity_remaining) > 0,
                                )
                                .map((pack) => ({
                                    value: String(pack.id),
                                    label: `${pack.batch_number ?? 'Batch unknown'} · ${pack.quantity_remaining} ${item.unit}`,
                                }))}
                            onChange={setLot}
                        />
                        {mode === 'move' && (
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {[
                                    ['returned_pharmacy', 'Return to pharmacy'],
                                    ['removed_expired', 'Expired pack'],
                                    ['damaged', 'Damaged'],
                                    ['quarantined', 'Take pack out of use'],
                                ].map(([value, label]) => (
                                    <Button
                                        key={value}
                                        variant="outline"
                                        aria-pressed={kind === value}
                                        className="h-auto min-w-0 whitespace-normal"
                                        onClick={() => setKind(value)}
                                    >
                                        {label}
                                        {kind === value && (
                                            <Check className="size-4" />
                                        )}
                                    </Button>
                                ))}
                            </div>
                        )}
                        {selected && (
                            <p className="text-caption">
                                This pack has {selected.quantity_remaining}{' '}
                                {item.unit}.{' '}
                                {kind === 'quarantined' && mode === 'move'
                                    ? 'Taking it out of use retains the whole pack quantity.'
                                    : 'This movement reduces the pack quantity.'}
                            </p>
                        )}
                    </>
                )}
                <Label htmlFor="quantity">
                    {mode === 'coming_back' ? 'Quantity returned' : 'Quantity'}{' '}
                    ({item.unit})
                </Label>
                <Input
                    id="quantity"
                    type="number"
                    min={mode === 'coming_back' ? '0' : '0.01'}
                    step="0.01"
                    value={quantity}
                    onChange={(event) => setQuantity(event.target.value)}
                />
                <Label htmlFor="reason">Reason or destination</Label>
                <Textarea
                    id="reason"
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                />
            </SettingsModal>
            <ConfirmDialog
                frontline
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => void save()}
                processing={command.saving}
                title="Record this removal?"
                description="This changes the pack's usable stock and creates a permanent movement record. The original receipt and history are retained."
                confirmText="Record movement"
            />
        </>
    );
}
export function CountWizard({
    item,
    onClose,
    onSaved,
}: {
    item: ItemDetail;
    onClose: () => void;
    onSaved: () => void;
}) {
    const packs = item.packs.filter(
        (pack) => Number(pack.quantity_remaining) > 0,
    );
    const [confirmedEmpty, setConfirmedEmpty] = useState(false);
    const [values, setValues] = useState<Record<number, string>>({});
    const [step, setStep] = useState(0);
    const [reason, setReason] = useState('');
    const [saved, setSaved] = useState(false);
    const [discard, setDiscard] = useState(false);
    const command = useStockCommand();
    const complete =
        packs.length === 0
            ? confirmedEmpty
            : packs.every(
                  (pack) =>
                      /^\d+(?:\.\d{1,2})?$/.test(values[pack.id] ?? '') &&
                      Number.isFinite(Number(values[pack.id])),
              );
    const navigate = (index: number) => {
        if (
            command.saving ||
            (index > 0 && !complete) ||
            (index === 2 && different && !reason.trim())
        )
            return;
        setStep(index);
    };
    const different = packs.some(
        (pack) => Number(values[pack.id]) !== Number(pack.quantity_remaining),
    );
    const close = () => {
        if (!command.saving) {
            if (!saved && (Object.keys(values).length || confirmedEmpty))
                setDiscard(true);
            else onClose();
        }
    };
    const save = async () => {
        const result = await command.run({
            action: 'count',
            client_medication_id: item.id,
            reason,
            confirm_empty: confirmedEmpty,
            lines: packs.map((pack) => ({
                lot_id: pack.id,
                revision: pack.revision,
                quantity: values[pack.id],
            })),
        });
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close}
                title="Count stock"
                description="Count each pack before comparing with the recorded balance."
                railIcon={ClipboardCheck}
                railTitle="Count stock"
                railSub={`${item.client_name} · ${item.name}`}
                steps={[
                    {
                        key: 'count',
                        label: 'Count the packs',
                        blurb: 'Recorded amounts hidden',
                        icon: Package,
                    },
                    {
                        key: 'differences',
                        label: 'Check differences',
                        blurb: 'Explain what changed',
                        icon: ClipboardCheck,
                        disabled: !complete || command.saving,
                    },
                    {
                        key: 'review',
                        label: 'Review and save',
                        blurb: 'Permanent count record',
                        icon: ShieldCheck,
                        disabled:
                            !complete ||
                            (different && !reason.trim()) ||
                            command.saving,
                    },
                ]}
                stepIndex={step}
                onStepClick={navigate}
                pct={((step + 1) / 3) * 100}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={command.saving}
                        onClick={close}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            disabled={step === 0 || command.saving}
                            onClick={() => setStep(step - 1)}
                        >
                            Back
                        </Button>
                        <Button
                            disabled={
                                command.saving ||
                                !complete ||
                                (step > 0 && different && !reason.trim())
                            }
                            onClick={() =>
                                step < 2 ? navigate(step + 1) : void save()
                            }
                        >
                            {command.saving
                                ? 'Saving…'
                                : step === 2
                                  ? 'Save count'
                                  : 'Continue'}
                        </Button>
                    </>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Count saved"
                            blurb={
                                different
                                    ? 'The difference is kept for the house lead to review. The stock balance has not been changed.'
                                    : 'The count matches. Last counted has been updated.'
                            }
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <Errors values={command.errors} />
                    <div className="space-y-4">
                        {step === 0 && (
                            <>
                                <SettingsNotice role="note">
                                    Count the packs physically. The recorded
                                    quantities stay hidden until you have
                                    entered every count.
                                </SettingsNotice>
                                {packs.length === 0 && (
                                    <label className="frontline-tap flex items-center gap-2">
                                        <Checkbox
                                            checked={confirmedEmpty}
                                            onCheckedChange={(value) =>
                                                setConfirmedEmpty(
                                                    value === true,
                                                )
                                            }
                                        />
                                        I physically checked and there are no
                                        packs to count.
                                    </label>
                                )}
                                {packs.map((pack) => (
                                    <div key={pack.id}>
                                        <Label htmlFor={`count-${pack.id}`}>
                                            {pack.batch_number ??
                                                (pack.batch_not_printed
                                                    ? 'Batch not printed'
                                                    : 'Batch unknown')}{' '}
                                            ·{' '}
                                            {pack.expiry_date?.slice(0, 10) ??
                                                'Expiry unknown'}
                                        </Label>
                                        <Input
                                            id={`count-${pack.id}`}
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            value={values[pack.id] ?? ''}
                                            onChange={(event) =>
                                                setValues({
                                                    ...values,
                                                    [pack.id]:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                ))}
                            </>
                        )}
                        {step > 0 && (
                            <>
                                {packs.length === 0 && (
                                    <ReviewCard
                                        icon={Package}
                                        title="Empty stock checked"
                                    >
                                        <ReviewRow
                                            label="Physically counted"
                                            value="No packs"
                                        />
                                        <ReviewRow
                                            label="Recorded"
                                            value="No remaining packs"
                                        />
                                    </ReviewCard>
                                )}
                                {packs.map((pack) => (
                                    <ReviewCard
                                        key={pack.id}
                                        icon={Package}
                                        title={pack.batch_number ?? 'Pack'}
                                    >
                                        <ReviewRow
                                            label="Counted"
                                            value={values[pack.id]}
                                        />
                                        <ReviewRow
                                            label="Recorded at start"
                                            value={pack.quantity_remaining}
                                        />
                                    </ReviewCard>
                                ))}
                                {different && (
                                    <>
                                        <Label htmlFor="reason">
                                            Explain the difference
                                        </Label>
                                        <Textarea
                                            id="reason"
                                            value={reason}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                        />
                                        <SettingsNotice role="note">
                                            A house lead reviews differences.
                                            Counts never silently overwrite
                                            stock. If stock moved while you
                                            counted, recount before saving.
                                        </SettingsNotice>
                                    </>
                                )}
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                frontline
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this count?"
                description="Your unsaved counts will be discarded."
                confirmText="Discard draft"
            />
        </>
    );
}
export function CountReview({
    record,
    canManage = false,
    onClose,
    onSaved,
}: {
    record: StockCount;
    canManage?: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [reason, setReason] = useState('');
    const [confirm, setConfirm] = useState(false);
    const command = useStockCommand();
    const save = async () => {
        const result = await command.run({
            action: 'count_review',
            client_medication_id: record.medication_id,
            count_id: record.id,
            reason,
        });
        if (result) {
            onSaved();
            onClose();
        }
    };
    return (
        <>
            <SettingsModal
                frontline
                title="Sign off this count"
                description={`${record.client_name} · ${record.medication_name}`}
                width={720}
                onClose={() => !command.saving && onClose()}
                footer={
                    <>
                        <Button
                            variant="outline"
                            disabled={command.saving}
                            onClick={onClose}
                        >
                            Close
                        </Button>
                        {record.state === 'needs_review' && canManage && (
                            <Button
                                disabled={command.saving || !reason.trim()}
                                onClick={() => setConfirm(true)}
                            >
                                Review adjustment
                            </Button>
                        )}
                    </>
                }
            >
                <Errors values={command.errors} />
                {record.lines.map((line) => (
                    <ReviewCard
                        key={line.lot_id}
                        icon={Package}
                        title={`Pack #${line.lot_id}`}
                    >
                        <ReviewRow label="Counted" value={line.counted} />
                        <ReviewRow label="Expected" value={line.expected} />
                    </ReviewCard>
                ))}
                <p>{record.reason ?? 'No difference recorded.'}</p>
                {record.state === 'needs_review' && canManage && (
                    <>
                        <Label htmlFor="reason">Your review and reason</Label>
                        <Textarea
                            id="reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                        />
                        <SettingsNotice role="note">
                            Signing off changes each pack to the counted
                            quantity. If any stock moved after this count, the
                            server requires a new count.
                        </SettingsNotice>
                    </>
                )}
            </SettingsModal>
            <ConfirmDialog
                frontline
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => void save()}
                processing={command.saving}
                title="Apply these counted quantities?"
                description="This changes the stock balance and records your reason. Historic receipts, movements and this count stay intact."
                confirmText="Apply count"
                variant="default"
            />
        </>
    );
}
