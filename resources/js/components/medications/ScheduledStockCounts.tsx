import MedicationScanVerificationPanel from '@/components/medications/MedicationScanVerificationPanel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { submitEmarMutation } from '@/lib/emar-offline';
import {
    emptyMedicationScanCapture,
    hasVerifiedMedicationScan,
    toMedicationScanPayload,
    type MedicationScanCapture,
    type MedicationScanVerification,
} from '@/lib/medication-scan';
import axios from 'axios';
import { AlertCircle, CheckCircle, Package, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface StockCount {
    id: number;
    scheduled_date: string;
    scheduled_time: string | null;
    status: 'pending' | 'completed' | 'overdue';
    expected_quantity: number | null;
    actual_quantity: number | null;
    discrepancy: number | null;
    notes: string | null;
    completed_by: string | null;
    witnessed_by: string | null;
    completed_at: string | null;
    is_overdue: boolean;
    scan_verified?: boolean;
}

interface ScheduledStockCountsProps {
    clientId: number;
    medicationId: number;
    medicationName: string;
    controlledDrug: boolean;
    scanVerification?: MedicationScanVerification | null;
    witnesses: Array<{ id: number; name: string }>;
    onUpdate?: () => void;
}

export default function ScheduledStockCounts({
    clientId,
    medicationId,
    medicationName,
    controlledDrug,
    scanVerification,
    witnesses,
    onUpdate,
}: ScheduledStockCountsProps) {
    const [counts, setCounts] = useState<StockCount[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [showAddForm, setShowAddForm] = useState(false);
    const [completingId, setCompletingId] = useState<number | null>(null);

    // Form states
    const [newDate, setNewDate] = useState('');
    const [newTime, setNewTime] = useState('');
    const [newExpectedQty, setNewExpectedQty] = useState('');
    const [newNotes, setNewNotes] = useState('');

    // Complete form states
    const [actualQty, setActualQty] = useState('');
    const [completeNotes, setCompleteNotes] = useState('');
    const [witnessId, setWitnessId] = useState('');
    const [scanCapture, setScanCapture] = useState<MedicationScanCapture>(
        emptyMedicationScanCapture(),
    );

    useEffect(() => {
        if (open) {
            loadCounts();
        }
    }, [open]);

    const loadCounts = async () => {
        setLoading(true);
        try {
            const response = await axios.get(
                `/api/medications/clients/${clientId}/medications/${medicationId}/scheduled-counts`,
            );
            setCounts(response.data.counts);
        } catch (error) {
            console.error('Failed to load stock counts:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleCreate = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            const result = await submitEmarMutation(
                `/api/medications/clients/${clientId}/medications/${medicationId}/scheduled-counts`,
                {
                    scheduled_date: newDate,
                    scheduled_time: newTime || null,
                    expected_quantity: newExpectedQty
                        ? parseInt(newExpectedQty, 10)
                        : null,
                    notes: newNotes || null,
                },
                {
                    allowQueueWhenOffline: false,
                    successMessage: 'Scheduled stock count created.',
                },
            );

            if (result.status === 'conflict') {
                return;
            }

            setShowAddForm(false);
            setNewDate('');
            setNewTime('');
            setNewExpectedQty('');
            setNewNotes('');
            loadCounts();
            onUpdate?.();
        } catch (error: unknown) {
            console.error('Failed to create stock count:', error);
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to create scheduled stock count',
            );
        }
    };

    const handleComplete = async (countId: number) => {
        try {
            const actualQuantity = parseInt(actualQty, 10);
            const witnessValue =
                controlledDrug && witnessId ? parseInt(witnessId, 10) : null;

            if (scanVerification && !hasVerifiedMedicationScan(scanCapture)) {
                toast.error(
                    'Verify the medication code before completing this stock count.',
                );
                return;
            }

            const result = await submitEmarMutation(
                `/api/medications/clients/${clientId}/scheduled-counts/${countId}/complete`,
                {
                    actual_quantity: actualQuantity,
                    notes: completeNotes || null,
                    witnessed_by: witnessValue,
                    ...toMedicationScanPayload(scanCapture),
                },
                {
                    successMessage: 'Stock count completed.',
                    queuedMessage:
                        'Stock count saved offline and will sync automatically when the device reconnects.',
                },
            );

            if (result.status === 'conflict') {
                return;
            }

            if (result.status === 'queued') {
                setCounts((current) =>
                    current.map((count) =>
                        count.id === countId
                            ? {
                                  ...count,
                                  status: 'completed',
                                  actual_quantity: actualQuantity,
                                  discrepancy:
                                      count.expected_quantity !== null
                                          ? actualQuantity -
                                            count.expected_quantity
                                          : null,
                                  notes: completeNotes || count.notes,
                                  witnessed_by:
                                      controlledDrug && witnessValue
                                          ? (witnesses.find(
                                                (w) => w.id === witnessValue,
                                            )?.name ?? null)
                                          : count.witnessed_by,
                                  completed_at: new Date().toISOString(),
                                  scan_verified: true,
                              }
                            : count,
                    ),
                );
            } else {
                loadCounts();
            }

            setCompletingId(null);
            setActualQty('');
            setCompleteNotes('');
            setWitnessId('');
            setScanCapture(emptyMedicationScanCapture());
            onUpdate?.();
        } catch (error: unknown) {
            console.error('Failed to complete stock count:', error);
            if (axios.isAxiosError(error)) {
                const validationMessage = Object.values(
                    (error.response?.data?.errors ?? {}) as Record<
                        string,
                        string[] | string
                    >,
                )
                    .flatMap((value) =>
                        Array.isArray(value) ? value : [value],
                    )
                    .find((value) => typeof value === 'string');

                toast.error(
                    typeof error.response?.data?.error === 'string'
                        ? error.response.data.error
                        : validationMessage
                          ? validationMessage
                        : 'Failed to complete stock count',
                );
                return;
            }

            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Failed to complete stock count',
            );
        }
    };

    const getStatusBadge = (status: string, isOverdue: boolean) => {
        if (isOverdue) {
            return <Badge className="bg-red-100 text-red-800">Overdue</Badge>;
        }
        const colors: Record<string, string> = {
            pending: 'bg-amber-100 text-amber-800',
            completed: 'bg-emerald-100 text-emerald-800',
        };
        return (
            <Badge className={colors[status] || 'bg-muted'}>{status}</Badge>
        );
    };

    const pendingCount = counts.filter(
        (c) => c.status === 'pending' || c.is_overdue,
    ).length;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" className="relative text-xs">
                    <Package className="mr-1 h-3 w-3" />
                    Stock Counts
                    {pendingCount > 0 && (
                        <span className="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] text-white">
                            {pendingCount}
                        </span>
                    )}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[80vh] max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center justify-between text-lg">
                        <span>Scheduled Stock Counts: {medicationName}</span>
                        <Button
                            size="sm"
                            onClick={() => setShowAddForm(!showAddForm)}
                        >
                            <Plus className="mr-1 h-3 w-3" />
                            Schedule
                        </Button>
                    </DialogTitle>
                </DialogHeader>

                {showAddForm && (
                    <form
                        onSubmit={handleCreate}
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <h4 className="text-sm font-medium">
                            Schedule New Stock Count
                        </h4>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label className="text-xs">Date *</Label>
                                <Input
                                    type="date"
                                    value={newDate}
                                    onChange={(e) => setNewDate(e.target.value)}
                                    required
                                />
                            </div>
                            <div>
                                <Label className="text-xs">Time</Label>
                                <Input
                                    type="time"
                                    value={newTime}
                                    onChange={(e) => setNewTime(e.target.value)}
                                />
                            </div>
                        </div>
                        <div>
                            <Label className="text-xs">Expected Quantity</Label>
                            <Input
                                type="number"
                                min="0"
                                value={newExpectedQty}
                                onChange={(e) =>
                                    setNewExpectedQty(e.target.value)
                                }
                                placeholder="Leave blank for current stock"
                            />
                        </div>
                        <div>
                            <Label className="text-xs">Notes</Label>
                            <Input
                                value={newNotes}
                                onChange={(e) => setNewNotes(e.target.value)}
                                placeholder="Optional notes..."
                            />
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit" size="sm">
                                Schedule
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setShowAddForm(false)}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}

                {loading ? (
                    <div className="py-8 text-center text-sm text-muted-foreground">
                        Loading...
                    </div>
                ) : counts.length === 0 ? (
                    <div className="py-8 text-center text-sm text-muted-foreground">
                        No scheduled stock counts.
                    </div>
                ) : (
                    <div className="max-h-[50vh] overflow-y-auto">
                        <div className="space-y-2 pr-4">
                            {counts.map((count) => (
                                <div
                                    key={count.id}
                                    className={`rounded-lg border p-3 ${
                                        count.is_overdue
                                            ? 'border-red-200 bg-red-50'
                                            : 'bg-muted'
                                    }`}
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            {getStatusBadge(
                                                count.status,
                                                count.is_overdue,
                                            )}
                                            <span className="text-sm font-medium">
                                                {new Date(
                                                    count.scheduled_date,
                                                ).toLocaleDateString()}
                                                {count.scheduled_time &&
                                                    ` at ${count.scheduled_time}`}
                                            </span>
                                        </div>
                                        {count.status === 'pending' && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setCompletingId(
                                                        completingId ===
                                                            count.id
                                                            ? null
                                                            : count.id,
                                                    )
                                                }
                                            >
                                                <CheckCircle className="mr-1 h-3 w-3" />
                                                Complete
                                            </Button>
                                        )}
                                    </div>

                                    {count.status === 'completed' && (
                                        <div className="mt-2 space-y-1 text-sm">
                                            <div className="flex gap-4">
                                                <span>
                                                    Expected:{' '}
                                                    <strong>
                                                        {count.expected_quantity ??
                                                            '—'}
                                                    </strong>
                                                </span>
                                                <span>
                                                    Actual:{' '}
                                                    <strong>
                                                        {count.actual_quantity}
                                                    </strong>
                                                </span>
                                                {count.discrepancy !== null &&
                                                    count.discrepancy !== 0 && (
                                                        <span className="text-red-600">
                                                            Discrepancy:{' '}
                                                            {(count.discrepancy ??
                                                                0) > 0
                                                                ? '+'
                                                                : ''}
                                                            {count.discrepancy}
                                                        </span>
                                                    )}
                                            </div>
                                            {count.completed_by && (
                                                <div className="text-xs text-muted-foreground">
                                                    Completed by{' '}
                                                    {count.completed_by}
                                                    {count.witnessed_by &&
                                                        ` • Witnessed by ${count.witnessed_by}`}
                                                    {count.scan_verified
                                                        ? ' • Scan verified'
                                                        : ''}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {completingId === count.id && (
                                        <div className="mt-3 space-y-3 border-t border-border pt-3">
                                            <div className="flex items-center gap-2 text-amber-700">
                                                <AlertCircle className="h-4 w-4" />
                                                <span className="text-sm">
                                                    Enter actual count details
                                                </span>
                                            </div>
                                            <div className="grid grid-cols-2 gap-3">
                                                <div>
                                                    <Label className="text-xs">
                                                        Actual Quantity *
                                                    </Label>
                                                    <Input
                                                        type="number"
                                                        min="0"
                                                        value={actualQty}
                                                        onChange={(e) =>
                                                            setActualQty(
                                                                e.target.value,
                                                            )
                                                        }
                                                        required
                                                    />
                                                </div>
                                                {controlledDrug && (
                                                    <div>
                                                        <Label className="text-xs">
                                                            Witness *
                                                        </Label>
                                                        <select
                                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                                            value={witnessId}
                                                            onChange={(e) =>
                                                                setWitnessId(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            required
                                                        >
                                                            <option value="">
                                                                Select
                                                                witness...
                                                            </option>
                                                            {witnesses.map(
                                                                (w) => (
                                                                    <option
                                                                        key={
                                                                            w.id
                                                                        }
                                                                        value={
                                                                            w.id
                                                                        }
                                                                    >
                                                                        {w.name}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                )}
                                            </div>
                                            <div>
                                                <Label className="text-xs">
                                                    Notes
                                                </Label>
                                                <Input
                                                    value={completeNotes}
                                                    onChange={(e) =>
                                                        setCompleteNotes(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Any notes about the count..."
                                                />
                                            </div>
                                            <MedicationScanVerificationPanel
                                                clientId={clientId}
                                                medicationId={medicationId}
                                                scanVerification={
                                                    scanVerification
                                                }
                                                resetKey={`${count.id}-${completingId}`}
                                                requirementText="Verification is required before completing this count."
                                                onChange={setScanCapture}
                                            />
                                            <div className="flex gap-2">
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        handleComplete(count.id)
                                                    }
                                                    disabled={
                                                        !actualQty ||
                                                        Boolean(
                                                            controlledDrug &&
                                                                !witnessId,
                                                        ) ||
                                                        Boolean(
                                                            scanVerification &&
                                                                !hasVerifiedMedicationScan(
                                                                    scanCapture,
                                                                ),
                                                        )
                                                    }
                                                >
                                                    Confirm Count
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setCompletingId(null)
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
