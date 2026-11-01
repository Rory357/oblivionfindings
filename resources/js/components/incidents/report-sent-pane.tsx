/**
 * After a report (board G4-3, approved by Stephan 1 Nov 2026): one plain pane on the pop-up kit's 480 frame that says
 * where the report went, in the person's own status word ("Sent"), and who follows it up at that Site — never one
 * named person: nothing routes a new incident or injury to one person, and a new injury has no coordinator until
 * someone chooses one. The main button goes back to where the person started ("Back to My Day"), or "Done".
 *
 * A draft has no reference yet (one is given when it's sent), so the draft pane shows none. It says plainly that
 * nobody follows it up until it's sent, and where to finish it. "Open incident" stays only for people who can open
 * the incident (the caller passes it only then). All buttons are 44 px.
 *
 * `inline` lays the same words out inside a reporting page (the standalone report page has no dialog).
 */
import { POPUP_WIDTH, PopupFooter } from '@/components/popup';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Check, FileText, X } from 'lucide-react';
import type { ReactNode } from 'react';

export type ReportSentKind = 'incident' | 'near_miss' | 'injury';

const KIND_NAME: Record<ReportSentKind, string> = {
    incident: 'Incident',
    near_miss: 'Near miss',
    injury: 'Workplace injury',
};

/** Where a sent report went, in plain words (exported for tests). */
export function reportSentWhere(
    kind: ReportSentKind,
    site: string | null | undefined,
): ReactNode {
    const place = site ? <b className="font-semibold">{site}</b> : null;
    if (kind === 'injury') {
        return (
            <>
                Your injury report has gone to the people who manage health and
                safety
                {place ? <> at {place}</> : null}. One of them will coordinate
                your recovery.
            </>
        );
    }
    return (
        <>
            Your {kind === 'near_miss' ? 'near miss' : 'incident'} has gone to
            the managers
            {place ? <> at {place}</> : <> who look after it</>}. One of them
            will take it on and follow it up.
        </>
    );
}

export function ReportSentPane({
    open = true,
    inline = false,
    outcome,
    kind,
    siteName,
    reference,
    doneLabel,
    onDone,
    onReportAnother,
    onSendNow,
    sending = false,
    onOpenRecord,
}: {
    open?: boolean;
    inline?: boolean;
    outcome: 'sent' | 'draft';
    kind: ReportSentKind;
    siteName?: string | null;
    reference?: string | null;
    /** "Back to My Day" when the page came with a return address, otherwise "Done". */
    doneLabel: string;
    onDone: () => void;
    onReportAnother?: () => void;
    /** Drafts only: send the saved draft now. */
    onSendNow?: () => void;
    sending?: boolean;
    /** Only for people who can open the record. */
    onOpenRecord?: () => void;
}) {
    const draft = outcome === 'draft';
    const title = draft ? 'Draft saved — not sent yet' : 'Sent — thanks';
    const lens = draft ? (
        <span aria-hidden="true" className="popup-lens">
            <FileText className="size-5" />
        </span>
    ) : (
        <span
            aria-hidden="true"
            className="grid size-12 place-items-center rounded-full bg-status-success-bg text-status-success"
        >
            <Check className="size-6" strokeWidth={2.5} />
        </span>
    );
    const words = draft ? (
        <>
            <p>Nobody follows it up until you send it.</p>
            <p>Finish it any time from My Day, under “What you reported”.</p>
        </>
    ) : (
        <>
            <p>{reportSentWhere(kind, siteName)}</p>
            <p>
                You can see where it’s up to on My Day, under “What you
                reported”.
            </p>
        </>
    );
    const ref =
        !draft && reference ? (
            <p className="text-caption rounded-md bg-muted px-2.5 py-1 font-medium text-foreground">
                {KIND_NAME[kind]} {reference}
            </p>
        ) : null;
    const actions = (
        <>
            {onOpenRecord && !draft ? (
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap"
                    onClick={onOpenRecord}
                >
                    Open {kind === 'injury' ? 'injury' : 'incident'}
                </Button>
            ) : null}
            {draft && onSendNow ? (
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap"
                    onClick={onSendNow}
                    disabled={sending}
                >
                    Send it now
                </Button>
            ) : null}
            {!draft && onReportAnother ? (
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap"
                    onClick={onReportAnother}
                >
                    Report another
                </Button>
            ) : null}
            <Button
                type="button"
                className="frontline-tap"
                onClick={onDone}
                disabled={sending}
            >
                {doneLabel}
            </Button>
        </>
    );

    if (inline) {
        return (
            <div className="grid justify-items-center gap-3 py-6 text-center">
                {lens}
                <h2 className="text-section-title">{title}</h2>
                <div className="grid max-w-[400px] gap-2 text-sm text-foreground">
                    {words}
                </div>
                {ref}
                <div className="mt-2 flex flex-wrap justify-center gap-2.5">
                    {actions}
                </div>
            </div>
        );
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !sending) onDone();
            }}
        >
            {/* The kit's 480 frame (POPUP_WIDTH.sm, PopupFrame's classes): a centred success pane the kit has no type for yet. */}
            <DialogContent
                showCloseButton={false}
                data-popup-size="sm"
                className="flex max-h-[88vh] flex-col gap-0 overflow-hidden rounded-[18px] border border-border bg-card p-0 text-card-foreground shadow-2xl sm:max-w-none"
                style={{
                    width: `min(92vw, ${POPUP_WIDTH.sm}px)`,
                    maxWidth: `min(92vw, ${POPUP_WIDTH.sm}px)`,
                }}
            >
                {/* eslint-disable-next-line no-restricted-syntax -- the kit header's ✕ (PopupHeader), placed over a centred pane */}
                <button
                    type="button"
                    onClick={onDone}
                    disabled={sending}
                    aria-label="Close"
                    className="frontline-hit absolute top-3.5 right-4 inline-flex size-8 items-center justify-center rounded-[9px] text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50"
                >
                    <X className="size-[18px]" />
                </button>
                <div className="scrollbar-pretty grid min-h-0 flex-1 justify-items-center gap-2.5 overflow-y-auto px-6 pt-[26px] pb-[18px] text-center">
                    {lens}
                    <DialogTitle className="text-section-title">
                        {title}
                    </DialogTitle>
                    <DialogDescription asChild>
                        <div className="grid max-w-[400px] gap-2 text-sm text-foreground">
                            {words}
                        </div>
                    </DialogDescription>
                    {ref}
                </div>
                <PopupFooter>{actions}</PopupFooter>
            </DialogContent>
        </Dialog>
    );
}

export default ReportSentPane;
