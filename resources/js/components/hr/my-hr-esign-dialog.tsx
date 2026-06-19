/* eslint-disable no-restricted-syntax -- The sign action + download link are
 * bespoke controls sized to the e-sign dialog from the design handoff. */
import { router } from '@inertiajs/react';
import { Check, Download, PenLine, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { fireConfetti } from '@/lib/confetti';

export type PendingSignature = {
    id: number;
    document_title: string;
    document_category: string | null;
    requested_by: string | null;
    requested_at: string | null;
    download_url: string;
};

export function MyHrEsignDialog({
    signature,
    onClose,
}: {
    signature: PendingSignature | null;
    onClose: () => void;
}) {
    const [name, setName] = useState('');
    const [processing, setProcessing] = useState(false);

    if (!signature) return null;

    const has = name.trim() !== '';
    const meta = [
        signature.requested_by ? `Sent by ${signature.requested_by}` : null,
        signature.requested_at
            ? new Date(signature.requested_at).toLocaleDateString('en-NZ', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              })
            : null,
    ]
        .filter(Boolean)
        .join(' · ');

    function close() {
        setName('');
        onClose();
    }

    function submit() {
        if (!has) {
            toast.warning('Add your name', {
                description: 'Type your full name to sign.',
            });
            return;
        }
        setProcessing(true);
        router.post(
            `/hr/my/documents/sign/${signature!.id}`,
            { signature_data: name.trim() },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const flash = (page.props as { flash?: { error?: string } }).flash;
                    if (flash?.error) {
                        toast.error('Could not sign', { description: flash.error });
                        return;
                    }
                    toast.success('Signed ✍️', {
                        description: `“${signature!.document_title}” signed & filed.`,
                    });
                    fireConfetti();
                    close();
                },
                onError: () => toast.error('Could not sign the document'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Dialog open={!!signature} onOpenChange={(next) => !next && close()}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{ maxWidth: 'min(94vw, 560px)', width: 'min(94vw, 560px)' }}
            >
                <DialogTitle className="sr-only">
                    Sign {signature.document_title}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Review and electronically sign this document.
                </DialogDescription>

                <header className="flex items-center gap-3 border-b border-border px-[18px] py-4">
                    <span className="grid h-[34px] w-[34px] place-items-center rounded-[9px] bg-status-warning-bg text-status-warning">
                        <PenLine className="h-[17px] w-[17px]" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-[14.5px] font-bold">
                            {signature.document_title}
                        </div>
                        <div className="text-[11.5px] text-muted-foreground">{meta}</div>
                    </div>
                    <button
                        type="button"
                        onClick={close}
                        aria-label="Close"
                        className="grid h-[30px] w-[30px] place-items-center rounded-md text-muted-foreground hover:bg-muted"
                    >
                        <X className="h-[17px] w-[17px]" />
                    </button>
                </header>

                <div className="p-[18px]">
                    <div className="h-[150px] overflow-y-auto rounded-[11px] border border-border bg-muted p-4 text-xs leading-relaxed text-muted-foreground">
                        <p className="mb-2 font-bold text-foreground">
                            {signature.document_title}
                        </p>
                        <p className="mb-2">
                            This document confirms your acknowledgement and agreement to the
                            terms set out by Kauri Care. Please read carefully before
                            signing. Your electronic signature has the same legal standing
                            as a handwritten one.
                        </p>
                        <p className="mb-3">
                            By signing you confirm you have read, understood and agree to
                            comply with this document in full.
                        </p>
                        <a
                            href={signature.download_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-primary hover:underline"
                        >
                            <Download className="h-3.5 w-3.5" />
                            Download the original to review
                        </a>
                    </div>

                    <div className="mt-3.5">
                        <label className="mb-1.5 block text-xs font-semibold">
                            Type your full name to sign
                        </label>
                        <input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder="Your full name"
                            className="w-full rounded-[10px] border border-border bg-card px-3.5 py-3 text-sm outline-none focus:border-primary"
                        />
                        <div
                            className="mt-2 flex h-[54px] items-center justify-center rounded-[10px] border border-dashed border-border text-[26px] italic"
                            style={{
                                fontFamily: "'Brush Script MT', cursive",
                                color: has ? 'var(--primary)' : 'var(--muted-foreground)',
                            }}
                        >
                            {has ? name : 'Your signature appears here'}
                        </div>
                    </div>
                </div>

                <footer className="flex items-center justify-end gap-2.5 border-t border-border bg-muted/40 px-[18px] py-3.5">
                    <button
                        type="button"
                        onClick={close}
                        className="rounded-[10px] border border-border bg-card px-4 py-2 text-[13px] font-semibold"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={!has || processing}
                        className="inline-flex items-center gap-1.5 rounded-[10px] bg-primary px-4 py-2 text-[13px] font-bold text-primary-foreground disabled:opacity-50"
                    >
                        <Check className="h-3.5 w-3.5" />
                        Sign &amp; submit
                    </button>
                </footer>
            </DialogContent>
        </Dialog>
    );
}

export default MyHrEsignDialog;
