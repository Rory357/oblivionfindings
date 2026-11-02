import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { FileDown, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { exportInvestigation, type InvestigationExportScope } from './types';

/**
 * The controlled export (approved 22 October 2026): a watermarked PDF of
 * the granted conversation, or of every conversation still active in the
 * case. Offered only while the grant is active; the server refuses after
 * expiry or ending and logs every export with the file's hash.
 */
export function InvestigationExportDialog({
    actorId,
    grantId,
    caseReference,
    activeInCase,
    open,
    onOpenChange,
}: {
    actorId: number;
    grantId: number;
    caseReference: string;
    /** Conversations in this case whose access is still active, including this one. */
    activeInCase: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [scope, setScope] =
        useState<InvestigationExportScope>('conversation');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [done, setDone] = useState<{
        filename: string;
        sha256: string | null;
    } | null>(null);

    async function run() {
        if (busy) return;
        setBusy(true);
        setError('');
        setDone(null);
        try {
            setDone(
                await exportInvestigation(
                    actorId,
                    grantId,
                    activeInCase > 1 ? scope : 'conversation',
                ),
            );
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (busy) return;
                if (!next) {
                    setError('');
                    setDone(null);
                }
                onOpenChange(next);
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <FileDown className="size-4 text-primary" />
                        Export a watermarked PDF
                    </DialogTitle>
                    <DialogDescription>
                        Case {caseReference}. Only conversations granted to you
                        for this case are included.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    {activeInCase > 1 && (
                        <RadioGroup
                            value={scope}
                            onValueChange={(value) =>
                                setScope(value as InvestigationExportScope)
                            }
                            disabled={busy}
                            aria-label="What to export"
                            className="grid gap-2"
                        >
                            <div className="flex items-center gap-3">
                                <RadioGroupItem
                                    id="export-scope-conversation"
                                    value="conversation"
                                />
                                <Label htmlFor="export-scope-conversation">
                                    This conversation
                                </Label>
                            </div>
                            <div className="flex items-center gap-3">
                                <RadioGroupItem
                                    id="export-scope-case"
                                    value="case"
                                />
                                <Label htmlFor="export-scope-case">
                                    All {activeInCase} conversations still
                                    active in this case
                                </Label>
                            </div>
                        </RadioGroup>
                    )}
                    <Alert variant="info" role="note">
                        <ShieldCheck />
                        <AlertDescription>
                            Every page is watermarked with the case reference,
                            your name, the export time and “Confidential —
                            investigation record”. The record includes removed
                            messages and earlier versions; attachments are
                            listed by name only. The export, its conversations
                            and the file’s fingerprint are logged in the
                            investigation audit.
                        </AlertDescription>
                    </Alert>
                    {error && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {error}
                        </p>
                    )}
                    {done && (
                        <p
                            role="status"
                            className="text-sm break-all text-status-success"
                        >
                            Exported and logged as {done.filename}.
                            {done.sha256 && ` SHA-256 ${done.sha256}.`}
                        </p>
                    )}
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => onOpenChange(false)}
                    >
                        {done ? 'Close' : 'Cancel'}
                    </Button>
                    <Button disabled={busy} onClick={() => void run()}>
                        {busy ? 'Exporting…' : 'Export and log'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
