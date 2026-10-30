import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTimeLong } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import type { IntakeDraft } from '../staff-intake-dialog';
import { label } from '../types';

export type IntakeDraftSummary = {
    reference: string;
    label: string;
    source: string | null;
    step: number;
    saved_at: string | null;
    expires_at: string;
};

/**
 * "N unfinished intake drafts · Choose a draft" (mockup drafts modal). Drafts
 * are the signed-in person's own; resuming loads the encrypted answers into the
 * same New referral wizard, and discarding asks first.
 */
export function IntakeDrafts({
    drafts,
    onResume,
}: {
    drafts: IntakeDraftSummary[];
    onResume: (draft: IntakeDraft) => void;
}) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [discarding, setDiscarding] = useState<IntakeDraftSummary | null>(
        null,
    );
    if (drafts.length === 0) return null;
    const resume = async (draft: IntakeDraftSummary) => {
        setBusy(draft.reference);
        setError('');
        try {
            const response = await axios.get<{ draft: IntakeDraft }>(
                `/operations/referrals/drafts/${draft.reference}`,
            );
            setOpen(false);
            onResume(response.data.draft);
        } catch {
            setError(
                'This draft could not be opened. It may have expired or been submitted. Reload the workspace to check.',
            );
        } finally {
            setBusy(null);
        }
    };
    const discard = async (draft: IntakeDraftSummary) => {
        setBusy(draft.reference);
        setError('');
        try {
            await axios.delete(
                `/operations/referrals/drafts/${draft.reference}`,
            );
            setDiscarding(null);
            router.reload({ only: ['drafts'] });
        } catch {
            setDiscarding(null);
            setError('The draft could not be discarded. Try again.');
        } finally {
            setBusy(null);
        }
    };
    return (
        <>
            <Alert>
                <AlertDescription className="flex flex-wrap items-center gap-x-2">
                    {drafts.length} unfinished intake{' '}
                    {drafts.length === 1 ? 'draft' : 'drafts'} saved by you.
                    <Button
                        variant="link"
                        className="h-auto p-0"
                        onClick={() => setOpen(true)}
                    >
                        Choose a draft
                    </Button>
                </AlertDescription>
            </Alert>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Your intake drafts</DialogTitle>
                        <DialogDescription>
                            Unfinished calls saved by you. Drafts are separate
                            from submitted referrals, visible only to you and
                            removed after 7 days.
                        </DialogDescription>
                    </DialogHeader>
                    {error && (
                        <p role="alert" className="text-subtle">
                            {error}
                        </p>
                    )}
                    <div className="grid gap-3">
                        {drafts.map((draft) => (
                            <div
                                key={draft.reference}
                                className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3 last:border-b-0"
                            >
                                <div className="min-w-0">
                                    <p className="font-semibold break-words">
                                        {draft.label}
                                    </p>
                                    <p className="text-caption text-muted-foreground">
                                        {draft.source
                                            ? `${label(draft.source)} · `
                                            : ''}
                                        Saved{' '}
                                        {formatDateTimeLong(draft.saved_at)} ·
                                        kept until{' '}
                                        {formatDateTimeLong(draft.expires_at)}
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        variant="ghost"
                                        disabled={busy !== null}
                                        onClick={() => setDiscarding(draft)}
                                    >
                                        Discard
                                        <span className="sr-only">
                                            {' '}
                                            {draft.label}
                                        </span>
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={busy !== null}
                                        onClick={() => void resume(draft)}
                                    >
                                        {busy === draft.reference
                                            ? 'Opening…'
                                            : 'Resume'}
                                        <span className="sr-only">
                                            {' '}
                                            {draft.label}
                                        </span>
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={discarding !== null}
                variant="destructive"
                onClose={() => setDiscarding(null)}
                onConfirm={() => discarding && void discard(discarding)}
                processing={busy !== null}
                title="Discard this intake draft?"
                description={`The saved answers for ${discarding?.label ?? 'this enquiry'} will be deleted. Nothing has been submitted as a referral.`}
                confirmText="Discard draft"
            />
        </>
    );
}
