import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, Loader2, Newspaper } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EventBody } from './_event-detail';
import { HubSelect } from './_select';
import { StarterDescription } from './_starter-choice';
import type { HubEvent, HubProps, HubStarterTemplate } from './_types';

export { BirthdaySettingsDialog } from './_birthday-settings';
export { HubNotificationDialog } from './_notification-preferences';

export { ShortcutDialog } from './_shortcuts';

export type CreateHubPageInitial = {
    title: string;
    kind: string;
    template: string;
    source_page_id?: number;
    source_revision_id?: number;
};

export function CreateHubPageDialog({
    open,
    close,
    initial,
    context,
}: {
    open: boolean;
    close: (restoreBrowse?: boolean) => void;
    initial?: CreateHubPageInitial;
    context?: string;
}) {
    const actor = usePage<{ auth?: { user?: { id?: number } } }>().props.auth
        ?.user?.id;
    return open ? (
        <CreateBody
            key={actor}
            close={close}
            initial={initial}
            context={context}
        />
    ) : null;
}
function CreateBody({
    close,
    initial,
    context,
}: {
    close: (restoreBrowse?: boolean) => void;
    initial?: CreateHubPageInitial;
    context?: string;
}) {
    const pending = useRef(false);
    const alive = useRef(false);
    const [busy, setBusy] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [baseline] = useState(
        initial ?? { title: '', kind: 'news', template: 'story' },
    );
    const form = useForm<CreateHubPageInitial>(
        initial ?? { title: '', kind: 'news', template: 'story' },
    );
    const templates =
        (usePage().props.starterTemplates as
            | HubStarterTemplate[]
            | undefined) ?? [];
    const dirty = JSON.stringify(form.data) !== JSON.stringify(baseline);
    const navigation = useSettingsLeaveConfirmation(
        dirty || busy,
        'Discard this page setup?',
        'Your unsaved title and starter choices will be discarded. If creation is already in progress, check your drafts before trying again.',
    );
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => router.on('navigate', () => close(false)), [close]);
    const leave = () => {
        if (pending.current) return;
        if (dirty) setDiscard(true);
        else close();
    };
    const locked = busy || form.processing || discard;
    return (
        <>
            <section
                aria-label="Create a Company Hub page"
                className="min-w-0 rounded-2xl border bg-card"
            >
                <form
                    className="flex min-h-0 min-w-0 flex-1 flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (
                            pending.current ||
                            form.processing ||
                            discard ||
                            !form.data.title.trim()
                        )
                            return;
                        pending.current = true;
                        setBusy(true);
                        form.post('/company-hub/pages', {
                            onSuccess: () => {
                                if (alive.current)
                                    navigation.discardAndRun(() =>
                                        close(false),
                                    );
                            },
                            onFinish: () => {
                                if (alive.current) {
                                    pending.current = false;
                                    setBusy(false);
                                }
                            },
                        });
                    }}
                >
                    <header className="shrink-0 space-y-3 border-b px-7 py-6">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="-ml-2"
                            disabled={locked}
                            onClick={leave}
                        >
                            <ArrowLeft className="size-4" />
                            Back to hub
                        </Button>
                        <h1
                            data-hub-page-creation-heading
                            tabIndex={-1}
                            className="flex items-center gap-2 text-2xl font-semibold tracking-tight"
                        >
                            <Newspaper className="size-5 text-primary" />
                            Start with a purpose
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {context ||
                                'Choose a starting point. Make it your own in the designer.'}
                        </p>
                    </header>
                    <div className="min-w-0 flex-1 space-y-6 px-7 py-6">
                        <div className="space-y-2">
                            <Label htmlFor="hub-new-title">
                                What is this page called?
                            </Label>
                            <Input
                                id="hub-new-title"
                                disabled={locked}
                                autoFocus
                                required
                                maxLength={160}
                                value={form.data.title}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                                placeholder="Give this page a clear, useful title"
                            />
                            {form.errors.title && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {form.errors.title}
                                </p>
                            )}
                            {Object.entries(form.errors)
                                .filter(([key]) => key !== 'title')
                                .map(([key, message]) => (
                                    <p
                                        key={key}
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {message}
                                    </p>
                                ))}
                        </div>
                        {!initial?.source_page_id && (
                            <Label className="grid gap-2">
                                Page type
                                <HubSelect
                                    label="Page type"
                                    disabled={locked}
                                    value={form.data.kind}
                                    onChange={(value) =>
                                        form.setData('kind', value)
                                    }
                                    options={[
                                        { value: 'news', label: 'News story' },
                                        {
                                            value: 'page',
                                            label: 'Company page',
                                        },
                                        {
                                            value: 'knowledge',
                                            label: 'Knowledge resource',
                                        },
                                        {
                                            value: 'department',
                                            label: 'Team space',
                                        },
                                        {
                                            value: 'campaign',
                                            label: 'Company campaign',
                                        },
                                    ]}
                                />
                                <span className="text-xs font-normal text-muted-foreground">
                                    Choose where this page belongs. The starting
                                    layout can be changed in the editor.
                                </span>
                            </Label>
                        )}
                        <fieldset className="min-w-0 space-y-3">
                            <legend className="text-sm font-medium">
                                Choose a starting point
                            </legend>
                            <div className="grid min-w-0 grid-cols-3 gap-3 p-1">
                                {templates
                                    .filter(
                                        ({ key }) =>
                                            !initial?.source_page_id ||
                                            ['story', 'leadership'].includes(
                                                key,
                                            ),
                                    )
                                    .map((starter) => (
                                        <label
                                            key={starter.key}
                                            className={`relative flex min-w-0 cursor-pointer items-start rounded-xl border p-3 has-focus-visible:ring-2 has-focus-visible:ring-ring ${form.data.template === starter.key ? 'border-primary bg-primary/5' : 'hover:bg-accent'}`}
                                        >
                                            <input
                                                type="radio"
                                                name="hub-new-starter"
                                                aria-label={starter.label}
                                                className="sr-only"
                                                disabled={locked}
                                                checked={
                                                    form.data.template ===
                                                    starter.key
                                                }
                                                onChange={() =>
                                                    form.setData((data) => ({
                                                        ...data,
                                                        template: starter.key,
                                                        kind: starter.kind,
                                                    }))
                                                }
                                            />
                                            <StarterDescription
                                                starter={starter}
                                                illustrated
                                            />
                                            {form.data.template ===
                                                starter.key && (
                                                <span className="absolute top-2 right-2 grid size-5 place-items-center rounded-full bg-primary-fill text-primary-fill-foreground">
                                                    <Check
                                                        className="size-3.5"
                                                        aria-hidden="true"
                                                    />
                                                </span>
                                            )}
                                        </label>
                                    ))}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Every section can be changed in the editor.
                            </p>
                        </fieldset>
                    </div>
                    {discard && (
                        <div
                            role="alert"
                            className="border-t bg-muted/50 px-7 py-5"
                        >
                            <h2 className="font-semibold">
                                Discard this page setup?
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Your title and starter choices have not been
                                saved.
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
                                    Discard changes
                                </Button>
                            </div>
                        </div>
                    )}
                    <footer className="sticky bottom-0 z-10 flex shrink-0 items-center justify-between gap-4 rounded-b-2xl border-t bg-card/95 px-7 py-5 backdrop-blur-sm">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={locked}
                            onClick={leave}
                        >
                            Cancel
                        </Button>
                        <div className="flex min-w-0 items-center gap-4">
                            <span className="text-sm text-muted-foreground">
                                {
                                    templates.find(
                                        (starter) =>
                                            starter.key === form.data.template,
                                    )?.label
                                }
                            </span>
                            <Button
                                type="submit"
                                disabled={locked || !form.data.title.trim()}
                            >
                                {(busy || form.processing) && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                Create draft &amp; open designer
                                <ArrowRight className="size-4" />
                            </Button>
                        </div>
                    </footer>
                </form>
            </section>
            {navigation.confirmation}
        </>
    );
}

export function HubEventDialog({
    event,
    close,
    bookmarks = [],
    preview = false,
    events = [],
    onSelect,
}: {
    event: HubEvent | null;
    close: () => void;
    bookmarks?: HubProps['bookmarks'];
    preview?: boolean;
    events?: HubEvent[];
    onSelect?: (event: HubEvent) => void;
}) {
    const [commandBusy, setCommandBusy] = useState(false);
    const actorId = Number(
        (usePage().props.auth as { user?: { id: number } })?.user?.id,
    );
    return (
        <Dialog
            open={!!event}
            onOpenChange={(v) => !v && !commandBusy && close()}
        >
            <DialogContent
                className="max-h-[88vh] overflow-x-hidden overflow-y-auto"
                onEscapeKeyDown={(e) => {
                    if (
                        commandBusy ||
                        (e.target instanceof Element &&
                            e.target.closest('[data-event-cancellation-open]'))
                    )
                        e.preventDefault();
                }}
                onInteractOutside={(e) => {
                    if (commandBusy) e.preventDefault();
                }}
                style={{
                    width: 'min(94vw, 1100px)',
                    maxWidth: 'min(94vw, 1100px)',
                }}
            >
                {event && (
                    <EventBody
                        key={`${actorId}-${event.id}-${event.extendedProps.editVersion}`}
                        event={event}
                        bookmarks={bookmarks}
                        preview={preview}
                        events={events}
                        onSelect={onSelect}
                        onEdited={close}
                        actorId={actorId}
                        commandBusy={commandBusy}
                        onCommandBusy={setCommandBusy}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
