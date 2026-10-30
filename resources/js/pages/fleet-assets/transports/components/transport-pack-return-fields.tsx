import { stockPackLabel } from '@/components/medications/stock-pack-fields';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    witnessIsSelectable,
    witnessOptionLabel,
    type WitnessPickerOption,
} from '@/lib/witness-pin';
import type { TransportMedicationLog } from './transport-medication-dialogs';

export type TransitReturnLine = {
    lot_id: number;
    quantity: string;
    missing: string;
};
export function validateTransitReturn(
    log: TransportMedicationLog | null,
    lines: TransitReturnLine[],
    reason: string,
): string | null {
    if (!log?.pack_stock?.lots_started) return null;
    const allocations = log.pack_stock.allocations;
    if (
        allocations.length !== lines.length ||
        new Set(lines.map((line) => line.lot_id)).size !== lines.length ||
        allocations.some(
            (allocation) =>
                !lines.some((line) => line.lot_id === allocation.lot_id),
        )
    )
        return 'Count the amount returned and missing for every retained pack, including 0.';
    for (const line of lines) {
        if (
            [line.quantity, line.missing].some(
                (value) =>
                    value.trim() === '' ||
                    !Number.isFinite(Number(value)) ||
                    Number(value) < 0,
            )
        )
            return 'Enter 0 or more in both columns for every pack.';
        const allocation = allocations.find(
            (item) => item.lot_id === line.lot_id,
        )!;
        if (
            Math.round((Number(line.quantity) + Number(line.missing)) * 100) !==
            Math.round(Number(allocation.quantity_remaining_away) * 100)
        )
            return 'Returned and missing amounts must account for all stock still away.';
    }
    return lines.some((line) => Number(line.missing) > 0) && !reason.trim()
        ? 'Explain the missing stock and what you did next.'
        : null;
}
export function TransitReturnFields({
    log,
    lines,
    onChange,
    reason,
    onReasonChange,
    immediateAction,
    onImmediateActionChange,
    witnessId,
    onWitnessChange,
    credential,
    onCredentialChange,
    witnesses,
}: {
    log: TransportMedicationLog;
    lines: TransitReturnLine[];
    onChange: (value: TransitReturnLine[]) => void;
    reason: string;
    onReasonChange: (value: string) => void;
    immediateAction: string;
    onImmediateActionChange: (value: string) => void;
    witnessId: string;
    onWitnessChange: (value: string) => void;
    credential: string;
    onCredentialChange: (value: string) => void;
    witnesses: Array<WitnessPickerOption & { id: number }>;
}) {
    const stock = log.pack_stock;
    if (!stock?.lots_started) return null;
    const rows = stock.allocations.map(
        (allocation) =>
            lines.find((line) => line.lot_id === allocation.lot_id) ?? {
                lot_id: allocation.lot_id,
                quantity: '',
                missing: '',
            },
    );
    return (
        <div className="space-y-4">
            <h3 className="text-section-title">Reconcile every pack</h3>
            <p className="text-subtle">
                The house stock was reduced when packed. Only the counted return
                goes back; stock already given is not subtracted again.
            </p>
            {rows.map((line) => {
                const allocation = stock.allocations.find(
                    (item) => item.lot_id === line.lot_id,
                )!;
                const lot = stock.lots.find((item) => item.id === line.lot_id);
                return (
                    <div
                        key={line.lot_id}
                        className="grid grid-cols-[minmax(0,1fr)_140px_140px] items-end gap-3 rounded-lg border p-3"
                    >
                        <div>
                            <p className="font-medium">
                                {lot
                                    ? stockPackLabel(lot)
                                    : `Pack ${line.lot_id}`}
                            </p>
                            <p className="text-subtle">
                                {allocation.quantity_remaining_away}{' '}
                                {stock.unit} still away ·{' '}
                                {allocation.quantity_used} given
                            </p>
                        </div>
                        {(['quantity', 'missing'] as const).map((key) => (
                            <div key={key} className="space-y-2">
                                <Label htmlFor={`return-${line.lot_id}-${key}`}>
                                    {key === 'quantity'
                                        ? 'Returned'
                                        : 'Missing'}{' '}
                                    ({stock.unit})
                                </Label>
                                <Input
                                    id={`return-${line.lot_id}-${key}`}
                                    type="number"
                                    min="0"
                                    step="any"
                                    placeholder="0 if none"
                                    value={line[key]}
                                    onChange={(event) =>
                                        onChange(
                                            rows.map((row) =>
                                                row.lot_id === line.lot_id
                                                    ? {
                                                          ...row,
                                                          [key]: event.target
                                                              .value,
                                                      }
                                                    : row,
                                            ),
                                        )
                                    }
                                />
                            </div>
                        ))}
                    </div>
                );
            })}
            <div className="space-y-2">
                <Label htmlFor="return-reconciliation-reason">
                    Missing stock: what happened?
                </Label>
                <Textarea
                    id="return-reconciliation-reason"
                    value={reason}
                    onChange={(event) => onReasonChange(event.target.value)}
                />
            </div>
            {log.is_controlled_drug && (
                <div className="space-y-3">
                    <Label htmlFor="return-immediate-action">
                        Missing controlled medicine: what you did straight away
                    </Label>
                    <Textarea
                        id="return-immediate-action"
                        value={immediateAction}
                        onChange={(event) =>
                            onImmediateActionChange(event.target.value)
                        }
                    />
                    <Label htmlFor="return-witness">
                        Witness at the cupboard
                    </Label>
                    <Select value={witnessId} onValueChange={onWitnessChange}>
                        <SelectTrigger id="return-witness">
                            <SelectValue placeholder="Choose the eligible colleague" />
                        </SelectTrigger>
                        <SelectContent>
                            {witnesses.map((witness) => (
                                <SelectItem
                                    key={witness.id}
                                    value={String(witness.id)}
                                    disabled={!witnessIsSelectable(witness)}
                                >
                                    {witnessOptionLabel(witness)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <WitnessPinInput
                        id="return-witness-pin"
                        value={credential}
                        onChange={onCredentialChange}
                        atCupboard
                        disabled={!witnessId}
                    />
                </div>
            )}
        </div>
    );
}
