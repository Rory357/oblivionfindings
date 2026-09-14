import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Wand2 } from 'lucide-react';
import { useState } from 'react';

type MacroPreview = {
    id: number;
    name: string;
    description: string | null;
    changes: string[];
    blockers: string[];
};

/**
 * Governed macro application: the preview names every change the macro
 * would make on THIS ticket before anything is posted or mutated.
 */
export function TicketMacros({
    ticketId,
    disabled,
}: {
    ticketId: number;
    disabled?: boolean;
}) {
    const [macros, setMacros] = useState<MacroPreview[] | null>(null);
    const [ticketVersion, setTicketVersion] = useState<number | null>(null);
    const [confirming, setConfirming] = useState<MacroPreview | null>(null);
    const [busy, setBusy] = useState(false);

    const load = async () => {
        if (macros !== null || busy) return;
        setBusy(true);
        try {
            const response = await axios.get(`/it/tickets/${ticketId}/macros`, {
                headers: { Accept: 'application/json' },
            });
            setMacros((response.data?.macros ?? []) as MacroPreview[]);
            setTicketVersion(Number(response.data?.ticket_version ?? 0));
        } catch {
            setMacros([]);
        } finally {
            setBusy(false);
        }
    };

    const apply = (macro: MacroPreview) => {
        if (ticketVersion === null) return;
        router.post(
            `/it/tickets/${ticketId}/macros/${macro.id}/apply`,
            {
                expected_version: ticketVersion,
                request_uuid: crypto.randomUUID(),
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    // The ticket changed (or was stale); re-fetch previews next open.
                    setMacros(null);
                    setTicketVersion(null);
                },
            },
        );
    };

    return (
        <div
            className="flex items-center gap-2"
            aria-label="Ticket macros"
            onPointerEnter={() => void load()}
            onFocusCapture={() => void load()}
        >
            <Select
                value=""
                disabled={disabled || busy || macros?.length === 0}
                onOpenChange={(open) => {
                    if (open) void load();
                }}
                onValueChange={(value) => {
                    const macro = macros?.find(
                        (candidate) => String(candidate.id) === value,
                    );
                    if (macro) setConfirming(macro);
                }}
            >
                <SelectTrigger
                    aria-label="Apply macro"
                    className="h-8 w-auto gap-1.5 text-xs"
                >
                    <Wand2 className="h-3.5 w-3.5" />
                    <SelectValue
                        placeholder={
                            macros?.length === 0 ? 'No macros' : 'Apply macro…'
                        }
                    />
                </SelectTrigger>
                <SelectContent>
                    {(macros ?? []).map((macro) => (
                        <SelectItem key={macro.id} value={String(macro.id)}>
                            {macro.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <AlertDialog
                open={confirming !== null}
                onOpenChange={(open) => {
                    if (!open) setConfirming(null);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Apply “{confirming?.name}”?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {confirming?.description ??
                                'This macro will make the following changes to this ticket.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {confirming && (
                        <div className="space-y-2 text-sm">
                            <ul className="list-disc space-y-1 pl-5">
                                {confirming.changes.map((change) => (
                                    <li key={change}>{change}</li>
                                ))}
                            </ul>
                            {confirming.blockers.length > 0 && (
                                <div role="alert" className="space-y-1">
                                    {confirming.blockers.map((blocker) => (
                                        <p
                                            key={blocker}
                                            className="text-status-critical"
                                        >
                                            {blocker}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                    <AlertDialogFooter>
                        <AlertDialogCancel onClick={() => setConfirming(null)}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={
                                !confirming || confirming.blockers.length > 0
                            }
                            onClick={() => {
                                if (
                                    confirming &&
                                    confirming.blockers.length === 0
                                ) {
                                    apply(confirming);
                                }
                                setConfirming(null);
                            }}
                        >
                            Apply macro
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
