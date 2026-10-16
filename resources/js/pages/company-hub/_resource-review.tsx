import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { CheckCircle2, ExternalLink, RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubRequestError, hubRequest } from './_api';
import { HubDateField } from './_date-fields';
import type { EditorialPage } from './_editorial';
import type { ResourceReport } from './_resource-care';

export type ReviewableReport = ResourceReport & { page: EditorialPage };

export function ResourceReview({
    report,
    actorId,
    close,
    onSaved,
}: {
    report: ReviewableReport;
    actorId: number | null;
    close: () => void;
    onSaved: () => void;
}) {
    const [original] = useState(report);
    const [response, setResponse] = useState(original.response);
    const [reviewOn, setReviewOn] = useState(original.page.review_on ?? '');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [needsReload, setNeedsReload] = useState(false);
    const pending = useRef(false),
        alive = useRef(false);
    const dateChanged = reviewOn !== (original.page.review_on ?? '');
    const dateLocked = ['review', 'scheduled', 'archived'].includes(
        original.page.status,
    );
    const dirty = response !== original.response || dateChanged;
    const navigation = useSettingsLeaveConfirmation(
        dirty || busy,
        'Discard this response?',
        'Your unsaved response and review date will be discarded. A response already sent may still finish; check the correction before saving again.',
    );
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const leave = () => {
        if (pending.current) return;
        if (dirty) setDiscard(true);
        else close();
    };
    const save = async () => {
        if (
            pending.current ||
            discard ||
            needsReload ||
            !actorId ||
            !response.trim() ||
            (dateChanged && dateLocked)
        )
            return;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<{
                saved?: boolean;
                actor_id?: number;
                page_id?: number;
                report_id?: number;
                report_version?: number;
                page_version?: number;
                review_on?: string | null;
            }>(
                `/company-hub/manage/${original.page.id}/reports/${original.id}`,
                'PUT',
                {
                    resolved: !original.resolved,
                    response,
                    version: original.version,
                    actor_id: actorId,
                    ...(dateChanged
                        ? {
                              review_on: reviewOn || null,
                              page_version: original.page.version,
                          }
                        : {}),
                },
            );
            if (!alive.current) return;
            if (
                result?.saved !== true ||
                result.actor_id !== actorId ||
                result.page_id !== original.page.id ||
                result.report_id !== original.id ||
                result.report_version !== original.version + 1 ||
                !Number.isSafeInteger(result.page_version) ||
                (result.page_version ?? 0) < 1 ||
                (dateChanged &&
                    (result.page_version !== original.page.version + 1 ||
                        result.review_on !== (reviewOn || null)))
            ) {
                setNeedsReload(true);
                setError(
                    'We could not confirm your response. Reload the current report before saving again.',
                );
                return;
            }
            navigation.discardAndRun(onSaved);
        } catch (reason) {
            if (!alive.current) return;
            if (
                !(reason instanceof HubRequestError) ||
                ![422, 429].includes(reason.status)
            )
                setNeedsReload(true);
            setError(
                reason instanceof Error
                    ? reason.message
                    : 'Your response could not be saved. Please try again.',
            );
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    const reload = () =>
        navigation.request(() =>
            navigation.discardAndRun(() => {
                close();
                router.reload({ only: ['editorial'], preserveScroll: true });
            }),
        );
    return (
        <>
            <Sheet
                open
                onOpenChange={(open) => {
                    if (!open) leave();
                }}
            >
                <SheetContent
                    side="right"
                    className="gap-0 overflow-hidden p-0 sm:max-w-[600px]"
                    overlayClassName="bg-black/30 backdrop-blur-[2px]"
                    closeButtonClassName={
                        busy ? 'pointer-events-none opacity-30' : ''
                    }
                    closeLabel="Close correction review"
                >
                    <SheetHeader className="shrink-0 border-b px-7 pt-7 pr-14 pb-6">
                        <p className="mb-1 text-xs font-semibold tracking-wide text-primary uppercase">
                            Resource care
                        </p>
                        <SheetTitle className="text-2xl">
                            {original.resolved
                                ? 'Reopen a correction'
                                : 'Review a correction'}
                        </SheetTitle>
                        <SheetDescription>
                            Record what changed or why this needs another look.
                            Your response is visible to the person who reported
                            it.
                        </SheetDescription>
                    </SheetHeader>
                    <form
                        className="flex min-h-0 flex-1 flex-col"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-7 py-6">
                            <Card
                                className="gap-3 p-5"
                                aria-label="Original correction"
                            >
                                <span className="text-xs font-medium text-primary">
                                    {original.resolved ? 'Resolved' : 'Open'} ·{' '}
                                    {original.issue}
                                </span>
                                <h2 className="text-lg font-semibold">
                                    {original.page.title}
                                </h2>
                                <p className="text-sm leading-6 whitespace-pre-wrap">
                                    {original.note}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Reported{' '}
                                    {formatDateTime(original.created_at)}
                                </p>
                            </Card>
                            <Label className="grid gap-2">
                                Response to the reporter
                                <Textarea
                                    rows={6}
                                    required
                                    maxLength={2000}
                                    disabled={busy || discard}
                                    value={response}
                                    onChange={(event) =>
                                        setResponse(event.target.value)
                                    }
                                    placeholder="Explain what you checked, what changed and any next step…"
                                />
                            </Label>
                            <div className="space-y-3 rounded-xl bg-muted/40 p-4 text-sm">
                                <Label className="grid gap-2">
                                    Next review date
                                    <HubDateField
                                        label="Next review date"
                                        value={reviewOn}
                                        disabled={
                                            busy ||
                                            discard ||
                                            dateLocked ||
                                            needsReload
                                        }
                                        onChange={(value) => setReviewOn(value)}
                                        describedBy="resource-review-date-help"
                                    />
                                </Label>
                                <p
                                    id="resource-review-date-help"
                                    className="text-xs text-muted-foreground"
                                >
                                    {dateLocked
                                        ? 'The date is locked while this page is awaiting review, scheduled or archived. You can still respond to the correction.'
                                        : 'Optional. Save a new date with your response, or clear it to remove the draft’s next review date.'}
                                </p>
                                <p>
                                    The review date is saved to the resource’s
                                    draft. Publish the updated page through its
                                    usual approval process to show it to staff.
                                </p>
                                <Link
                                    href={`/company-hub/manage/${original.page.id}/edit`}
                                    className="inline-flex items-center gap-1.5 font-medium text-primary"
                                >
                                    Open resource editor
                                    <ExternalLink className="size-3.5" />
                                </Link>
                            </div>
                            {error && (
                                <div
                                    role="alert"
                                    className="space-y-3 rounded-lg border border-destructive/30 p-4"
                                >
                                    <p className="text-sm text-destructive">
                                        {error}
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={reload}
                                    >
                                        Reload current report
                                    </Button>
                                </div>
                            )}
                            {discard && (
                                <div
                                    role="alert"
                                    className="rounded-xl border bg-muted/40 p-4"
                                >
                                    <h2 className="font-semibold">
                                        Discard this response?
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Your unsaved response and review date
                                        will be discarded. The saved correction
                                        stays available.
                                    </p>
                                    <div className="mt-4 flex gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setDiscard(false)}
                                        >
                                            Keep editing
                                        </Button>
                                        <Button
                                            type="button"
                                            onClick={() =>
                                                navigation.discardAndRun(close)
                                            }
                                        >
                                            Discard response
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </div>
                        <footer className="flex shrink-0 items-center justify-between gap-3 border-t px-7 py-5">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy || discard}
                                onClick={leave}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    busy ||
                                    discard ||
                                    needsReload ||
                                    !actorId ||
                                    !response.trim()
                                }
                            >
                                {original.resolved ? (
                                    <RotateCcw className="size-4" />
                                ) : (
                                    <CheckCircle2 className="size-4" />
                                )}
                                {busy
                                    ? 'Saving response…'
                                    : original.resolved
                                      ? 'Reopen report'
                                      : 'Respond & resolve'}
                            </Button>
                        </footer>
                    </form>
                </SheetContent>
            </Sheet>
            {navigation.confirmation}
        </>
    );
}
