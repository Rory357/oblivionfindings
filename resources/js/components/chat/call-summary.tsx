import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { chatRequest } from './api';

export type CallSummaryTarget = {
    id: string;
    client_id: number | null;
    client_name: string | null;
    can_record_note: boolean;
};

export function CallSummary({
    call,
    actorId,
    onClose,
}: {
    call: CallSummaryTarget | null;
    actorId: number | null;
    onClose: () => void;
}) {
    const [step, setStep] = useState(1);
    const [summary, setSummary] = useState('');
    const [publish, setPublish] = useState(false);
    const [familySummary, setFamilySummary] = useState('');
    const [audience, setAudience] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const generation = useRef(0);
    const savePending = useRef(false);
    useEffect(() => {
        const current = ++generation.current;
        return () => {
            generation.current = current + 1;
        };
    }, [actorId, call?.id]);
    async function save() {
        if (!call || !actorId || savePending.current) return;
        const current = generation.current;
        savePending.current = true;
        setSaving(true);
        setError('');
        try {
            const result = await chatRequest<{ already_saved: boolean }>(
                `/chat/api/calls/${call.id}/record`,
                {
                    method: 'POST',
                    headers: { 'X-Chat-Actor': String(actorId) },
                    body: JSON.stringify({
                        summary,
                        publish_family_note: publish,
                        family_summary: publish ? familySummary : null,
                        audience_confirmed: publish && audience,
                    }),
                },
            );
            if (current !== generation.current) return;
            toast.success(
                result.already_saved
                    ? 'This call summary was already saved.'
                    : publish
                      ? 'Communication Record and Family Note saved.'
                      : 'Communication Record saved.',
            );
            onClose();
        } catch (failure) {
            if (current !== generation.current) return;
            setError((failure as Error).message);
        } finally {
            if (current === generation.current) {
                savePending.current = false;
                setSaving(false);
            }
        }
    }
    return (
        <Dialog
            open={!!call}
            onOpenChange={(open) => {
                if (!open && !saving) onClose();
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
                className="max-h-[85dvh] overflow-x-hidden overflow-y-auto [overflow-wrap:anywhere]"
            >
                <DialogHeader>
                    <p className="text-xs font-medium text-muted-foreground">
                        Step {step} of 2 · {call?.client_name}
                    </p>
                    <DialogTitle>
                        {step === 1
                            ? 'Add a call summary'
                            : 'Review where this will be saved'}
                    </DialogTitle>
                    <DialogDescription>
                        {step === 1
                            ? 'Write the summary you want in the client’s Communication Record. Nothing is recorded or summarised automatically.'
                            : 'The staff summary stays internal. A Family Note has a broader audience and needs its own wording.'}
                    </DialogDescription>
                </DialogHeader>
                {step === 1 ? (
                    <label className="block text-sm font-medium">
                        Staff summary
                        <textarea
                            autoFocus
                            rows={5}
                            maxLength={10000}
                            value={summary}
                            onChange={(event) => setSummary(event.target.value)}
                            className="mt-2 w-full rounded-lg border bg-background p-3 font-normal"
                            placeholder="What was discussed and what needs to happen next?"
                        />
                    </label>
                ) : (
                    <div className="space-y-4">
                        <div className="rounded-xl border bg-muted/30 p-3 text-sm">
                            <strong>
                                Client Profile → Communication Record
                            </strong>
                            <p className="mt-2 max-h-32 overflow-x-hidden overflow-y-auto whitespace-pre-wrap text-muted-foreground">
                                {summary}
                            </p>
                            <p className="mt-2 text-xs font-medium">
                                Authorised staff only
                            </p>
                        </div>
                        <label className="flex min-h-11 items-center gap-3 text-sm">
                            <Checkbox
                                checked={publish}
                                onCheckedChange={(checked) => {
                                    setPublish(checked === true);
                                    setAudience(false);
                                }}
                            />
                            Also publish a separate Family Note
                        </label>
                        {publish && (
                            <div className="space-y-3 rounded-xl border p-3">
                                <label className="block text-sm font-medium">
                                    Family-friendly update
                                    <textarea
                                        rows={3}
                                        maxLength={5000}
                                        value={familySummary}
                                        onChange={(event) =>
                                            setFamilySummary(event.target.value)
                                        }
                                        className="mt-2 w-full rounded-lg border bg-background p-3 font-normal"
                                    />
                                </label>
                                <p className="text-xs text-muted-foreground">
                                    Eligible relatives with access to this
                                    client’s Family Notes may see this update,
                                    including relatives who were not in the
                                    call.
                                </p>
                                <label className="flex min-h-11 items-start gap-3 text-sm">
                                    <Checkbox
                                        className="mt-1"
                                        checked={audience}
                                        onCheckedChange={(checked) =>
                                            setAudience(checked === true)
                                        }
                                    />
                                    I have reviewed this wording for the Family
                                    Notes audience.
                                </label>
                            </div>
                        )}
                    </div>
                )}
                {error && (
                    <p role="alert" className="text-sm text-status-critical">
                        {error}
                    </p>
                )}
                <DialogFooter>
                    <Button
                        variant="outline"
                        disabled={saving}
                        onClick={() => (step === 2 ? setStep(1) : onClose())}
                    >
                        {step === 2 ? 'Back' : 'Not now'}
                    </Button>
                    {step === 1 ? (
                        <Button
                            disabled={!summary.trim()}
                            onClick={() => setStep(2)}
                        >
                            Review
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                saving ||
                                (publish &&
                                    (!familySummary.trim() || !audience))
                            }
                            onClick={() => void save()}
                        >
                            {saving
                                ? 'Saving…'
                                : publish
                                  ? 'Save both records'
                                  : 'Save staff summary'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
