import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateOnly } from '@/lib/datetime';
import { Plus, Trash2 } from 'lucide-react';

export type StockPackChoice = {
    id: number;
    batch_number: string | null;
    expiry_date: string | null;
    state: string;
    quantity_remaining: number | string;
    revision: number;
    usable?: boolean;
};
export type StockPackLine = {
    lot_id: number;
    revision: number;
    quantity: string;
    quantity_wasted?: string;
    return_of_id?: number;
    used_away?: string;
};
export type PackStockContext = {
    stock_id: number;
    unit: string;
    lots_started: boolean;
    lots: StockPackChoice[];
    physical_on_hand?: number | string;
    usable_on_hand?: number | string;
    paper_entries?: { id: number; label: string; occurred_at: string }[];
    outward?: {
        id: number;
        lot_id: number;
        quantity: string;
        recorded_at: string;
        reason: string;
    }[];
};

export function stockPackLabel(lot: StockPackChoice) {
    return `${lot.batch_number ? `Batch ${lot.batch_number}` : `Pack ${lot.id} · batch not recorded`} · ${lot.expiry_date ? `expires ${formatDateOnly(lot.expiry_date)}` : 'expiry not recorded'}`;
}

export function StockPackFields({
    stock,
    value,
    onChange,
    count = false,
    waste = false,
    returns = false,
    idPrefix = 'stock-pack',
    disabled = false,
}: {
    stock: PackStockContext;
    value: StockPackLine[];
    onChange: (value: StockPackLine[]) => void;
    count?: boolean;
    waste?: boolean;
    returns?: boolean;
    idPrefix?: string;
    disabled?: boolean;
}) {
    const rows = count
        ? stock.lots.map(
              (lot) =>
                  value.find((line) => line.lot_id === lot.id) ?? {
                      lot_id: lot.id,
                      revision: lot.revision,
                      quantity: '',
                  },
          )
        : value;
    const unused = stock.lots.filter(
        (lot) => !rows.some((line) => line.lot_id === lot.id),
    );
    return (
        <fieldset disabled={disabled} className="min-w-0 space-y-3">
            <legend className="text-section-title mb-2">
                {count
                    ? 'Count every physical pack'
                    : 'Select the actual packs'}
            </legend>
            <p className="text-subtle">
                {count
                    ? 'Include empty, expired and quarantined packs. Enter 0 where none remains; the controlled register tracks everything physically held.'
                    : returns
                      ? 'Choose each returned pack and its original outward record. Enter the amount returned and the amount used while away.'
                      : 'Choose the packs used and enter the amount from each. Pack quantities are never filled in for you.'}
            </p>
            {!stock.lots.length && (
                <p role="alert" className="text-status-warning-foreground">
                    No packs are available to record. Ask the stock lead to
                    check this medicine.
                </p>
            )}
            {rows.map((line) => {
                const lot = stock.lots.find(
                    (candidate) => candidate.id === line.lot_id,
                );
                return (
                    <div
                        key={line.lot_id}
                        className={`grid ${count ? 'grid-cols-[minmax(0,1fr)_160px]' : waste ? 'grid-cols-[minmax(0,1fr)_140px_140px_auto]' : 'grid-cols-[minmax(0,1fr)_160px_auto]'} items-end gap-3 rounded-lg border p-3`}
                    >
                        <div className="space-y-1">
                            <p className="font-medium">
                                {lot
                                    ? stockPackLabel(lot)
                                    : `Pack ${line.lot_id} · review required`}
                            </p>
                            <p className="text-subtle">
                                {lot
                                    ? `${lot.quantity_remaining} ${stock.unit} recorded · ${lot.state.replaceAll('_', ' ')}`
                                    : 'Pack no longer in the current list'}
                            </p>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor={`${idPrefix}-${line.lot_id}`}>
                                {count ? 'Counted' : 'Amount'} ({stock.unit})
                            </Label>
                            <Input
                                id={`${idPrefix}-${line.lot_id}`}
                                type="number"
                                min="0"
                                step="any"
                                value={line.quantity}
                                onChange={(event) =>
                                    onChange(
                                        rows.map((entry) =>
                                            entry.lot_id === line.lot_id
                                                ? {
                                                      ...entry,
                                                      quantity:
                                                          event.target.value,
                                                  }
                                                : entry,
                                        ),
                                    )
                                }
                            />
                        </div>
                        {waste && (
                            <div className="space-y-2">
                                <Label
                                    htmlFor={`${idPrefix}-waste-${line.lot_id}`}
                                >
                                    Wasted ({stock.unit})
                                </Label>
                                <Input
                                    id={`${idPrefix}-waste-${line.lot_id}`}
                                    type="number"
                                    min="0"
                                    step="any"
                                    placeholder="0 if none"
                                    value={line.quantity_wasted ?? ''}
                                    onChange={(event) =>
                                        onChange(
                                            rows.map((entry) =>
                                                entry.lot_id === line.lot_id
                                                    ? {
                                                          ...entry,
                                                          quantity_wasted:
                                                              event.target
                                                                  .value,
                                                      }
                                                    : entry,
                                            ),
                                        )
                                    }
                                />
                            </div>
                        )}
                        {!count && (
                            <Button
                                variant="ghost"
                                aria-label={`Remove pack ${line.lot_id}`}
                                onClick={() =>
                                    onChange(
                                        rows.filter(
                                            (entry) =>
                                                entry.lot_id !== line.lot_id,
                                        ),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        )}
                        {returns && (
                            <div className="col-span-full grid grid-cols-2 gap-3">
                                <div className="space-y-2">
                                    <Label
                                        htmlFor={`${idPrefix}-outward-${line.lot_id}`}
                                    >
                                        Original outward record
                                    </Label>
                                    <Select
                                        value={
                                            line.return_of_id
                                                ? String(line.return_of_id)
                                                : ''
                                        }
                                        onValueChange={(id) =>
                                            onChange(
                                                rows.map((entry) =>
                                                    entry.lot_id === line.lot_id
                                                        ? {
                                                              ...entry,
                                                              return_of_id:
                                                                  Number(id),
                                                          }
                                                        : entry,
                                                ),
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id={`${idPrefix}-outward-${line.lot_id}`}
                                        >
                                            <SelectValue placeholder="Choose the actual outward record" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {stock.outward
                                                ?.filter(
                                                    (entry) =>
                                                        entry.lot_id ===
                                                        line.lot_id,
                                                )
                                                .map((entry) => (
                                                    <SelectItem
                                                        key={entry.id}
                                                        value={String(entry.id)}
                                                    >
                                                        Record {entry.id} ·{' '}
                                                        {entry.quantity}{' '}
                                                        {stock.unit} ·{' '}
                                                        {entry.reason}
                                                    </SelectItem>
                                                ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label
                                        htmlFor={`${idPrefix}-used-${line.lot_id}`}
                                    >
                                        Used away ({stock.unit})
                                    </Label>
                                    <Input
                                        id={`${idPrefix}-used-${line.lot_id}`}
                                        type="number"
                                        min="0"
                                        step="any"
                                        placeholder="0 if none"
                                        value={line.used_away ?? ''}
                                        onChange={(event) =>
                                            onChange(
                                                rows.map((entry) =>
                                                    entry.lot_id === line.lot_id
                                                        ? {
                                                              ...entry,
                                                              used_away:
                                                                  event.target
                                                                      .value,
                                                          }
                                                        : entry,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        )}
                    </div>
                );
            })}
            {!count && unused.length > 0 && (
                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-add`}>Add a pack</Label>
                    <Select
                        value=""
                        onValueChange={(id) => {
                            const lot = unused.find(
                                (candidate) => candidate.id === Number(id),
                            );
                            if (lot)
                                onChange([
                                    ...rows,
                                    {
                                        lot_id: lot.id,
                                        revision: lot.revision,
                                        quantity: '',
                                    },
                                ]);
                        }}
                    >
                        <SelectTrigger
                            id={`${idPrefix}-add`}
                            className="w-full"
                        >
                            <Plus className="size-4" />
                            <SelectValue placeholder="Choose the actual pack" />
                        </SelectTrigger>
                        <SelectContent>
                            {unused.map((lot) => (
                                <SelectItem key={lot.id} value={String(lot.id)}>
                                    {stockPackLabel(lot)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}
        </fieldset>
    );
}

export function validateStockPackLines(
    stock: PackStockContext,
    lines: StockPackLine[],
    count = false,
    allowZero = false,
): string | null {
    if (!stock.lots_started) return null;
    if (!lines.length)
        return count
            ? 'Enter the physical count for every pack.'
            : 'Select the actual packs and amounts.';
    if (
        count &&
        (lines.length !== stock.lots.length ||
            stock.lots.some(
                (lot) => !lines.some((line) => line.lot_id === lot.id),
            ))
    )
        return 'Enter the physical count for every pack, including zero.';
    if (new Set(lines.map((line) => line.lot_id)).size !== lines.length)
        return 'Each pack must appear only once.';
    if (
        lines.some(
            (line) =>
                line.quantity.trim() === '' ||
                !Number.isFinite(Number(line.quantity)) ||
                Number(line.quantity) < 0 ||
                (!count && !allowZero && Number(line.quantity) === 0),
        )
    )
        return count || allowZero
            ? 'Enter an amount of 0 or more for every pack.'
            : 'Enter an amount greater than 0 for every selected pack.';
    if (
        lines.some(
            (line) =>
                !stock.lots.some(
                    (lot) =>
                        lot.id === line.lot_id &&
                        lot.revision === line.revision,
                ),
        )
    )
        return 'A selected pack has changed. Refresh the stock evidence and check it again.';
    return null;
}

export function StockPackReview({
    stock,
    lines,
}: {
    stock: PackStockContext;
    lines: StockPackLine[];
}) {
    return (
        <ul className="space-y-2 text-sm">
            {lines.map((line) => (
                <li key={line.lot_id}>
                    {stock.lots.find((lot) => lot.id === line.lot_id)
                        ? stockPackLabel(
                              stock.lots.find((lot) => lot.id === line.lot_id)!,
                          )
                        : `Pack ${line.lot_id}`}{' '}
                    · {line.quantity} {stock.unit}
                    {line.quantity_wasted !== undefined
                        ? ` · wasted ${line.quantity_wasted} ${stock.unit}`
                        : ''}
                    {line.return_of_id
                        ? ` · outward record ${line.return_of_id} · used away ${line.used_away || 'not recorded'}`
                        : ''}
                </li>
            ))}
        </ul>
    );
}
