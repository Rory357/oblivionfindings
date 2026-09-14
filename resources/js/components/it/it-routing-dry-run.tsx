import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import { FlaskConical } from 'lucide-react';
import { useState } from 'react';

type DryRunRow = {
    id: number;
    reference: string;
    title: string;
    current: { queue: string | null; owner: string | null; assignee: string | null };
    proposed: { queue: string | null; owner: string | null; assignee: string | null };
    strategy: string;
    gaps: string[];
    differs: boolean;
};

const GAP_LABELS: Record<string, string> = {
    no_eligible_queue: 'No eligible queue',
    no_accountable_team: 'No accountable team',
    no_available_owner: 'No available owner',
    no_accountable_owner: 'No accountable owner',
    no_available_cover: 'No available cover',
    manual_override_suspended: 'Manual override suspended',
};

function cell(value: string | null): string {
    return value ?? '—';
}

/**
 * W18 explainable routing: preview what the current rules would decide for
 * every open ticket, next to where each one sits now. Nothing mutates.
 */
export function ItRoutingDryRun() {
    const [open, setOpen] = useState(false);
    const [rows, setRows] = useState<DryRunRow[] | null>(null);
    const [generatedAt, setGeneratedAt] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const run = async () => {
        setBusy(true);
        setError('');
        try {
            const response = await axios.get('/it/setup/routing-dry-run', {
                headers: { Accept: 'application/json' },
            });
            const loaded = (response.data?.rows ?? []) as DryRunRow[];
            setRows(
                [...loaded].sort(
                    (a, b) => Number(b.differs) - Number(a.differs),
                ),
            );
            setGeneratedAt(String(response.data?.generated_at ?? ''));
        } catch {
            setError('The dry run could not be loaded. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Button
                variant="outline"
                onClick={() => {
                    setOpen(true);
                    void run();
                }}
            >
                <FlaskConical className="h-4 w-4" /> Dry-run routing
            </Button>
            <Dialog open={open} onOpenChange={(next) => setOpen(next)}>
                <DialogContent className="sm:max-w-4xl">
                    <DialogTitle>Routing dry run</DialogTitle>
                    <DialogDescription>
                        What the current rules would decide for each open
                        ticket, next to where it sits now. Nothing changes
                        until a ticket is actually re-routed.
                        {generatedAt
                            ? ` Generated ${formatDateTime(generatedAt)}.`
                            : ''}
                    </DialogDescription>
                    {error && (
                        <p role="alert" className="text-sm text-status-critical">
                            {error}
                        </p>
                    )}
                    {busy && <p role="status">Evaluating open tickets…</p>}
                    {rows && !busy && (
                        <div className="max-h-[55vh] overflow-y-auto">
                            {rows.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    There are no open tickets to evaluate.
                                </p>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead className="text-left text-xs text-muted-foreground uppercase">
                                        <tr>
                                            <th className="py-1.5 pr-3">
                                                Ticket
                                            </th>
                                            <th className="py-1.5 pr-3">
                                                Now
                                            </th>
                                            <th className="py-1.5 pr-3">
                                                Rules would decide
                                            </th>
                                            <th className="py-1.5">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="border-t border-border align-top"
                                            >
                                                <td className="max-w-48 py-2 pr-3">
                                                    <span className="font-medium">
                                                        {row.reference}
                                                    </span>
                                                    <span className="block truncate text-muted-foreground">
                                                        {row.title}
                                                    </span>
                                                </td>
                                                <td className="py-2 pr-3 text-muted-foreground">
                                                    {cell(row.current.queue)} ·{' '}
                                                    {cell(row.current.assignee)}
                                                </td>
                                                <td className="py-2 pr-3">
                                                    {cell(row.proposed.queue)} ·{' '}
                                                    {cell(
                                                        row.proposed.assignee,
                                                    )}
                                                </td>
                                                <td className="space-x-1 space-y-1 py-2">
                                                    {row.differs ? (
                                                        <StatusBadge
                                                            variant="warning"
                                                            label="Would change"
                                                        />
                                                    ) : (
                                                        <StatusBadge
                                                            variant="success"
                                                            label="Unchanged"
                                                        />
                                                    )}
                                                    {row.gaps.map((gap) => (
                                                        <StatusBadge
                                                            key={gap}
                                                            variant="neutral"
                                                            label={
                                                                GAP_LABELS[
                                                                    gap
                                                                ] ?? gap
                                                            }
                                                        />
                                                    ))}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
