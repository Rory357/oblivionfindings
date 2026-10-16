import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/datetime';
import {
    ArrowLeft,
    Check,
    Copy,
    FileClock,
    LayoutTemplate,
    Loader2,
    RotateCcw,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import { StarterDescription } from './_starter-choice';
import type { HubDraftRecovery, HubSection, HubStarterLayout } from './_types';

const reasons: Record<string, string> = {
    review: 'Withdraw the current review in Versions before discarding this draft.',
    schedule:
        'Cancel the scheduled publication in Versions before discarding this draft.',
    no_saved_draft: 'There is no saved unpublished draft to discard.',
    unavailable: 'Saved-draft recovery is temporarily unavailable.',
    published_audience:
        'Your current permissions do not allow you to restore the published audience.',
    layout: 'Restoring this design needs permission to change the shared navigation.',
};

export function DraftTools({
    pageId,
    version,
    actorId,
    dirty,
    demo,
    starters,
    onReset,
    onChanged,
    onClose,
    onDuplicate,
    editable = true,
}: {
    pageId: number;
    version: number;
    actorId: number | null;
    dirty: boolean;
    demo: boolean;
    starters: HubStarterLayout[];
    onReset: (sections: HubSection[]) => void;
    onChanged: () => void;
    onClose: () => void;
    onDuplicate?: () => void;
    editable?: boolean;
}) {
    const [step, setStep] = useState<
        'options' | 'reset' | 'discard' | 'recover'
    >('options');
    const [context, setContext] = useState<HubDraftRecovery | null>(null);
    const [loading, setLoading] = useState(!demo);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState(starters[0]?.key ?? '');
    const lock = useRef(false);
    useEffect(() => {
        if (demo) return;
        let current = true;
        hubRequest<HubDraftRecovery>(
            `/company-hub/manage/${pageId}/draft`,
            'GET',
        )
            .then((result) => {
                if (current) setContext(result);
            })
            .catch((failure) => {
                if (current)
                    setError(
                        failure instanceof Error
                            ? failure.message
                            : 'Draft options could not be loaded.',
                    );
            })
            .finally(() => {
                if (current) setLoading(false);
            });
        return () => {
            current = false;
        };
    }, [pageId, demo]);
    const stale =
        context &&
        (context.page_id !== pageId ||
            context.version !== version ||
            context.actor_id !== actorId);
    const canMutate =
        !!context && !stale && !dirty && !loading && !busy && !demo;
    const changeDraft = async (restore: boolean) => {
        if (
            !canMutate ||
            lock.current ||
            (restore ? !context.recovery?.can_restore : !context.can_discard)
        )
            return;
        lock.current = true;
        setBusy(true);
        setError('');
        try {
            await hubRequest(
                `/company-hub/manage/${pageId}/draft${restore ? '/restore' : ''}`,
                restore ? 'POST' : 'DELETE',
                {
                    actor_id: context.actor_id,
                    version,
                    ...(restore ? { discard_id: context.recovery!.id } : {}),
                },
            );
            onChanged();
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'The draft could not be changed.',
            );
            lock.current = false;
            setBusy(false);
        }
    };
    const starter = starters.find((item) => item.key === selected);
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {step === 'reset'
                            ? 'Start from a page layout'
                            : step === 'discard'
                              ? 'Discard the saved draft?'
                              : step === 'recover'
                                ? 'Recover the discarded draft?'
                                : 'Draft and design options'}
                    </DialogTitle>
                    <DialogDescription>
                        {step === 'reset'
                            ? 'Replace the sections and blocks with a starting layout. Your title, hero, audience and page settings stay in place. You can undo this change.'
                            : step === 'discard'
                              ? 'The last published version stays as it is. The unpublished draft is kept privately for recovery until you save a new draft.'
                              : step === 'recover'
                                ? 'Bring the saved content back into the editor as a draft. This does not publish the page.'
                                : 'Choose a starting layout or manage the saved draft. Publishing and archiving are separate actions.'}
                    </DialogDescription>
                </DialogHeader>
                {loading && (
                    <p
                        role="status"
                        className="flex items-center gap-2 text-sm"
                    >
                        <Loader2 className="size-4 animate-spin" />
                        Checking current draft…
                    </p>
                )}
                {error && (
                    <p
                        role="alert"
                        className="rounded-lg bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        {error}
                    </p>
                )}
                {stale && (
                    <p
                        role="alert"
                        className="text-sm text-status-warning-foreground"
                    >
                        This page or your signed-in account has changed since
                        you opened it. Close these options and reload the editor
                        before changing its saved draft.
                    </p>
                )}
                {step === 'options' && (
                    <div className="space-y-3">
                        {onDuplicate && !demo && (
                            <div className="rounded-xl border border-border p-4">
                                <div className="mb-2 flex items-center gap-2 font-semibold">
                                    <Copy className="size-4 text-primary" />
                                    Make a copy
                                </div>
                                <p className="mb-3 text-sm text-muted-foreground">
                                    Reuse this page’s design and content in a
                                    new private draft.
                                </p>
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={onDuplicate}
                                >
                                    Duplicate page
                                </Button>
                            </div>
                        )}
                        <div className="rounded-xl border border-border p-4">
                            <div className="mb-2 flex items-center gap-2 font-semibold">
                                <LayoutTemplate className="size-4 text-primary" />
                                Starting layout
                            </div>
                            <p className="mb-3 text-sm text-muted-foreground">
                                Choose from your company’s page starters and
                                rebuild the content area.
                            </p>
                            <Button
                                variant="outline"
                                disabled={!starters.length || busy || !editable}
                                onClick={() => setStep('reset')}
                            >
                                Choose a starting layout
                            </Button>
                        </div>
                        {!demo && (
                            <div className="rounded-xl border border-border p-4">
                                <div className="mb-2 flex items-center gap-2 font-semibold">
                                    <FileClock className="size-4 text-primary" />
                                    Saved draft
                                </div>
                                {dirty ? (
                                    <p className="mb-3 text-sm text-muted-foreground">
                                        Save your latest changes before
                                        discarding or recovering a saved draft.
                                    </p>
                                ) : context?.recovery ? (
                                    <p className="mb-3 text-sm text-muted-foreground">
                                        “{context.recovery.title}” was discarded{' '}
                                        {formatDateTime(
                                            context.recovery.discarded_at,
                                        )}
                                        .
                                    </p>
                                ) : (
                                    <p className="mb-3 text-sm text-muted-foreground">
                                        {context?.can_discard
                                            ? 'Remove unpublished changes from the editor while keeping the published page available.'
                                            : (reasons[
                                                  context?.blocked_reason ?? ''
                                              ] ??
                                              'Current draft options will appear here.')}
                                    </p>
                                )}
                                {context?.recovery ? (
                                    <>
                                        {!context.recovery.can_restore && (
                                            <p className="mb-3 text-sm text-muted-foreground">
                                                Recovery is unavailable during
                                                review or scheduled publication,
                                                or without the required layout
                                                permission.
                                            </p>
                                        )}
                                        <Button
                                            variant="outline"
                                            disabled={
                                                !canMutate ||
                                                !context.recovery.can_restore
                                            }
                                            onClick={() => setStep('recover')}
                                        >
                                            <RotateCcw className="size-4" />
                                            Recover draft
                                        </Button>
                                    </>
                                ) : (
                                    <Button
                                        variant="outline"
                                        disabled={
                                            !canMutate || !context?.can_discard
                                        }
                                        onClick={() => setStep('discard')}
                                    >
                                        <Trash2 className="size-4" />
                                        Discard saved draft
                                    </Button>
                                )}
                            </div>
                        )}
                        {demo && (
                            <p className="text-sm text-muted-foreground">
                                Showcase changes stay in this preview.
                                Saved-draft recovery is available on real pages.
                            </p>
                        )}
                    </div>
                )}
                {step === 'reset' && (
                    <fieldset className="grid max-h-[45vh] grid-cols-2 gap-3 overflow-y-auto p-1">
                        <legend className="sr-only">Starting layout</legend>
                        {starters.map((item) => (
                            <label
                                key={item.key}
                                className={`flex cursor-pointer gap-3 rounded-xl border p-4 ${selected === item.key ? 'border-primary bg-primary/5' : 'border-border hover:bg-accent'}`}
                            >
                                <input
                                    type="radio"
                                    name="hub-starter"
                                    value={item.key}
                                    checked={selected === item.key}
                                    onChange={() => setSelected(item.key)}
                                    className="mt-1 accent-primary"
                                />
                                <StarterDescription starter={item} />
                            </label>
                        ))}
                    </fieldset>
                )}
                {(step === 'discard' || step === 'recover') && (
                    <div className="flex gap-3 rounded-xl bg-primary/5 p-4 text-sm">
                        <Check className="mt-0.5 size-5 shrink-0 text-primary" />
                        <p>
                            {step === 'discard'
                                ? 'Published content, version history and uploaded files remain available. If a review or publication is scheduled, finish or cancel it first.'
                                : `Recover “${context?.recovery?.title ?? ''}” into this page. Review it before saving further changes or publishing.`}
                        </p>
                    </div>
                )}
                <DialogFooter className="gap-2">
                    {step !== 'options' && (
                        <Button
                            variant="ghost"
                            disabled={busy}
                            onClick={() => setStep('options')}
                        >
                            <ArrowLeft className="size-4" />
                            Back
                        </Button>
                    )}
                    <Button variant="outline" disabled={busy} onClick={onClose}>
                        {step === 'options' ? 'Done' : 'Cancel'}
                    </Button>
                    {step === 'reset' && (
                        <Button
                            disabled={!starter || busy || !editable}
                            onClick={() => {
                                if (!starter) return;
                                if (!editable) return;
                                onReset(structuredClone(starter.sections));
                                onClose();
                            }}
                        >
                            <LayoutTemplate className="size-4" />
                            Use this layout
                        </Button>
                    )}
                    {step === 'discard' && (
                        <Button
                            variant="destructive"
                            disabled={!canMutate || !context?.can_discard}
                            onClick={() => void changeDraft(false)}
                        >
                            {busy ? 'Discarding…' : 'Discard saved draft'}
                        </Button>
                    )}
                    {step === 'recover' && (
                        <Button
                            disabled={
                                !canMutate || !context?.recovery?.can_restore
                            }
                            onClick={() => void changeDraft(true)}
                        >
                            {busy ? 'Recovering…' : 'Recover draft'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
