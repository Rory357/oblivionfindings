import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    Lightbulb,
    UsersRound,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubRequestError, hubRequest } from './_api';
import type { IdeaProgressContext } from './_idea-progress';
import { HubSelect } from './_select';
import type { HubIdea } from './_types';

const categories = [
    'Better ways of working',
    'Our people',
    'Community',
    'Sustainability',
    'Something else',
];

export function IdeaComposer({
    actorId,
    teams,
    progressReady,
    ideas,
    close,
    onView,
    readOnly = false,
}: Omit<IdeaProgressContext, 'owners'> & {
    ideas: HubIdea[];
    close: () => void;
    onView: (id: number) => void;
    readOnly?: boolean;
}) {
    const [draft, setDraft] = useState({
        title: '',
        body: '',
        category: categories[0],
        department_id: null as number | null,
    });
    const [step, setStep] = useState<'write' | 'review' | 'shared'>('write');
    const [discard, setDiscard] = useState(false);
    const [busy, setBusy] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [error, setError] = useState('');
    const [createdId, setCreatedId] = useState<number | null>(null);
    const pending = useRef(false);
    const refreshingRef = useRef(false);
    const alive = useRef(false);
    const heading = useRef<HTMLHeadingElement>(null);
    const dirty =
        step !== 'shared' &&
        (!!draft.title ||
            !!draft.body ||
            draft.category !== categories[0] ||
            draft.department_id !== null);
    const navigation = useSettingsLeaveConfirmation(
        dirty || busy,
        'Leave this idea?',
        'Your writing has not been saved as a draft. A share already sent may still finish; check the board before trying again.',
    );
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => {
        heading.current?.focus({ preventScroll: true });
    }, [step]);
    const leave = () => {
        if (pending.current) return;
        if (dirty) setDiscard(true);
        else close();
    };
    const locked = busy || discard || uncertain;
    const ready =
        draft.title.trim().length >= 5 && draft.body.trim().length >= 15;
    const refresh = () => {
        if (!alive.current || refreshingRef.current) return;
        refreshingRef.current = true;
        setRefreshing(true);
        navigation.discardAndRun(() =>
            router.reload({
                only: [
                    'ideas',
                    'ideaBrowse',
                    'ideaActorId',
                    'selectedIdea',
                    'ideaId',
                    'ideaUnavailable',
                ],
                onFinish: () => {
                    if (alive.current) {
                        refreshingRef.current = false;
                        setRefreshing(false);
                    }
                },
            }),
        );
    };
    const share = async () => {
        if (readOnly || pending.current || locked || !actorId || !ready) return;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<{
                saved?: boolean;
                actor_id?: number;
                idea_id?: number;
            }>('/company-hub/ideas', 'POST', { ...draft, actor_id: actorId });
            if (!alive.current) return;
            if (
                result.saved !== true ||
                result.actor_id !== actorId ||
                !Number.isSafeInteger(result.idea_id) ||
                (result.idea_id ?? 0) < 1
            ) {
                setUncertain(true);
                setError(
                    'We couldn’t confirm the save. Check the idea board before sharing again.',
                );
                return;
            }
            setCreatedId(result.idea_id!);
            setStep('shared');
            refresh();
        } catch (reason) {
            if (!alive.current) return;
            if (
                reason instanceof HubRequestError &&
                [422, 429].includes(reason.status)
            ) {
                setError(reason.message);
                if (reason.status === 422) setStep('write');
            } else {
                setUncertain(true);
                setError(
                    'We couldn’t confirm the save. Check the idea board before sharing again.',
                );
            }
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    const savedIdea = ideas.find((idea) => idea.id === createdId);
    const team = teams.find((team) => team.id === draft.department_id);
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) leave();
                }}
            >
                <DialogContent
                    showCloseButton={false}
                    className="flex max-h-[88vh] flex-col gap-0 overflow-hidden rounded-2xl p-0"
                    style={{ maxWidth: 720 }}
                >
                    <DialogHeader className="shrink-0 border-b bg-primary/5 px-7 py-6">
                        <div className="mb-3 flex items-center justify-between gap-3">
                            <span className="flex items-center gap-2 text-xs font-semibold text-primary">
                                <Lightbulb className="size-4" /> COMPANY IDEAS
                            </span>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                aria-label="Close idea composer"
                                disabled={busy}
                                onClick={leave}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>
                        <DialogTitle
                            ref={heading}
                            tabIndex={-1}
                            className="text-2xl tracking-tight"
                        >
                            {step === 'write'
                                ? 'Share an idea'
                                : step === 'review'
                                  ? 'Review your idea'
                                  : 'Thank you for sharing it'}
                        </DialogTitle>
                        <DialogDescription>
                            {step === 'write'
                                ? 'Tell us what could work better and who it would help.'
                                : step === 'review'
                                  ? 'Check what colleagues will see before sharing.'
                                  : 'Small ideas can make a real difference.'}
                        </DialogDescription>
                        {step !== 'shared' && (
                            <ol
                                aria-label="Sharing steps"
                                className="mt-4 flex gap-5 text-xs"
                            >
                                {(['write', 'review'] as const).map(
                                    (item, index) => (
                                        <li
                                            key={item}
                                            aria-current={
                                                step === item
                                                    ? 'step'
                                                    : undefined
                                            }
                                            className={`flex items-center gap-2 ${step === item ? 'font-semibold text-foreground' : 'text-muted-foreground'}`}
                                        >
                                            <span
                                                className={`grid size-5 place-items-center rounded-full ${step === item ? 'bg-primary-fill text-primary-fill-foreground' : 'border'}`}
                                            >
                                                {step === 'review' &&
                                                index === 0 ? (
                                                    <Check className="size-3" />
                                                ) : (
                                                    index + 1
                                                )}
                                            </span>
                                            {item === 'write'
                                                ? 'Write'
                                                : 'Review & share'}
                                        </li>
                                    ),
                                )}
                            </ol>
                        )}
                    </DialogHeader>
                    <form
                        className="flex min-h-0 flex-1 flex-col"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (locked || (!actorId && !readOnly)) return;
                            if (!ready) {
                                setError(
                                    'Add a clear title of at least 5 characters and a little detail of at least 15 characters about who this would help.',
                                );
                                return;
                            }
                            if (step === 'write') {
                                setError('');
                                setStep('review');
                            } else if (step === 'review') void share();
                        }}
                    >
                        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-7 py-6">
                            {step === 'write' && (
                                <>
                                    <Label className="grid gap-2">
                                        Your idea in a sentence
                                        <Input
                                            required
                                            maxLength={160}
                                            disabled={locked}
                                            value={draft.title}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    title: event.target.value,
                                                })
                                            }
                                            placeholder="A small change worth exploring…"
                                        />
                                    </Label>
                                    <Label className="grid gap-2">
                                        What would change, and who would it
                                        help?
                                        <Textarea
                                            required
                                            rows={5}
                                            maxLength={4000}
                                            disabled={locked}
                                            value={draft.body}
                                            onChange={(event) =>
                                                setDraft({
                                                    ...draft,
                                                    body: event.target.value,
                                                })
                                            }
                                            placeholder="A useful starting point is enough. You don’t need a complete solution."
                                        />
                                    </Label>
                                    <div className="grid grid-cols-2 gap-4">
                                        <Label className="grid gap-2">
                                            Category
                                            <HubSelect
                                                label="Category"
                                                disabled={locked}
                                                value={draft.category}
                                                onChange={(value) =>
                                                    setDraft({
                                                        ...draft,
                                                        category: value,
                                                    })
                                                }
                                                options={categories.map(
                                                    (category) => ({
                                                        value: category,
                                                        label: category,
                                                    }),
                                                )}
                                            />
                                        </Label>
                                        {progressReady && (
                                            <Label className="grid gap-2">
                                                Best team to help
                                                <HubSelect
                                                    label="Best team to help"
                                                    disabled={locked}
                                                    value={String(
                                                        draft.department_id ??
                                                            '',
                                                    )}
                                                    onChange={(value) =>
                                                        setDraft({
                                                            ...draft,
                                                            department_id: value
                                                                ? Number(value)
                                                                : null,
                                                        })
                                                    }
                                                    options={[
                                                        {
                                                            value: '',
                                                            label: 'Not sure yet / company-wide',
                                                        },
                                                        ...teams.map(
                                                            (team) => ({
                                                                value: String(
                                                                    team.id,
                                                                ),
                                                                label: team.name,
                                                            }),
                                                        ),
                                                    ]}
                                                />
                                            </Label>
                                        )}
                                    </div>
                                    <p className="text-xs leading-relaxed text-muted-foreground">
                                        Ideas are visible to staff across the
                                        company. Leave out client details and
                                        private staff information.
                                    </p>
                                </>
                            )}
                            {step === 'review' && (
                                <article
                                    aria-label="Idea preview"
                                    className="rounded-xl border bg-card p-6"
                                >
                                    <span className="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                                        {draft.category}
                                    </span>
                                    <h2 className="mt-5 text-xl font-semibold tracking-tight">
                                        {draft.title}
                                    </h2>
                                    <p className="mt-3 text-sm leading-relaxed whitespace-pre-wrap">
                                        {draft.body}
                                    </p>
                                    <div className="mt-6 flex items-center gap-2 border-t pt-4 text-sm">
                                        <UsersRound className="size-4 text-muted-foreground" />
                                        <span>
                                            Best team to help:{' '}
                                            <strong>
                                                {team?.name ??
                                                    'Company-wide / not assigned'}
                                            </strong>
                                        </span>
                                    </div>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Shared with all staff. Choosing a team
                                        helps route your suggestion.
                                    </p>
                                </article>
                            )}
                            {step === 'shared' && (
                                <div
                                    className="space-y-5 py-4 text-center"
                                    role="status"
                                >
                                    <span className="mx-auto grid size-14 place-items-center rounded-full bg-primary/10 text-primary">
                                        <Check className="size-7" />
                                    </span>
                                    <div>
                                        <h2 className="text-xl font-semibold">
                                            Your idea is on the board.
                                        </h2>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            Follow its progress and add more
                                            context in the discussion.
                                        </p>
                                    </div>
                                    <Card className="gap-0 p-4 text-left shadow-none">
                                        <p className="text-xs text-muted-foreground">
                                            {savedIdea?.category ??
                                                draft.category}
                                        </p>
                                        <h3 className="mt-1 font-semibold">
                                            {savedIdea?.title ?? draft.title}
                                        </h3>
                                    </Card>
                                    {!savedIdea && (
                                        <p className="text-sm text-muted-foreground">
                                            {refreshing
                                                ? 'Loading your shared idea…'
                                                : 'Your idea was saved. Refresh the board to check its current availability.'}
                                        </p>
                                    )}
                                </div>
                            )}
                            {error && (
                                <p
                                    role="alert"
                                    className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                                >
                                    {error}
                                </p>
                            )}
                            {uncertain && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setDiscard(true)}
                                >
                                    Check idea board
                                </Button>
                            )}
                            {discard && (
                                <div
                                    role="alert"
                                    className="rounded-xl border bg-muted/50 p-4"
                                >
                                    <h2 className="font-semibold">
                                        Leave this idea?
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Your writing has not been saved as a
                                        draft.
                                        {uncertain
                                            ? ' A share already sent may have finished; check the board before trying again.'
                                            : ''}
                                    </p>
                                    <div className="mt-4 flex gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setDiscard(false)}
                                        >
                                            {uncertain
                                                ? 'Keep this open'
                                                : 'Keep editing'}
                                        </Button>
                                        <Button
                                            type="button"
                                            onClick={() =>
                                                navigation.discardAndRun(() => {
                                                    close();
                                                    if (uncertain)
                                                        router.reload({
                                                            only: [
                                                                'ideas',
                                                                'ideaBrowse',
                                                                'ideaActorId',
                                                                'selectedIdea',
                                                                'ideaId',
                                                                'ideaUnavailable',
                                                            ],
                                                        });
                                                })
                                            }
                                        >
                                            {uncertain
                                                ? 'Discard writing and check board'
                                                : 'Discard writing'}
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </div>
                        <footer className="flex shrink-0 items-center justify-between gap-3 border-t bg-card px-7 py-5">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy || discard}
                                onClick={
                                    step === 'review' && !uncertain
                                        ? () => setStep('write')
                                        : leave
                                }
                            >
                                {step === 'review' && !uncertain ? (
                                    <>
                                        <ArrowLeft className="size-4" />
                                        Back to editing
                                    </>
                                ) : step === 'shared' ? (
                                    'Done'
                                ) : (
                                    'Cancel'
                                )}
                            </Button>
                            {step === 'shared' ? (
                                savedIdea ? (
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            if (createdId !== null)
                                                onView(createdId);
                                        }}
                                    >
                                        View idea
                                        <ArrowRight className="size-4" />
                                    </Button>
                                ) : (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={refreshing}
                                        onClick={refresh}
                                    >
                                        Refresh board
                                    </Button>
                                )
                            ) : (
                                <Button
                                    type="submit"
                                    disabled={
                                        locked ||
                                        (!actorId && !readOnly) ||
                                        (readOnly && step === 'review') ||
                                        !draft.title.trim() ||
                                        !draft.body.trim()
                                    }
                                >
                                    {busy
                                        ? 'Sharing…'
                                        : step === 'write'
                                          ? 'Preview idea'
                                          : 'Share idea'}
                                    <ArrowRight className="size-4" />
                                </Button>
                            )}
                        </footer>
                    </form>
                </DialogContent>
            </Dialog>
            {navigation.confirmation}
        </>
    );
}
