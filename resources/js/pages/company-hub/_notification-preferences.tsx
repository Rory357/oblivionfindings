import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Bell,
    CalendarDays,
    Check,
    HeartHandshake,
    Loader2,
    MessageCircle,
    Newspaper,
    RefreshCw,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { hubRequest, HubRequestError } from './_api';
import type { HubNotificationChoices, HubNotificationState } from './_types';

const choiceKeys = [
    'recognition',
    'replies',
    'events',
    'news',
    'reviews',
] as const;
const groups = [
    {
        title: 'Recognition & conversations',
        choices: [
            {
                key: 'recognition',
                label: 'A shout-out for me or my team',
                description: 'A little appreciation, delivered to you.',
                icon: HeartHandshake,
            },
            {
                key: 'replies',
                label: 'Replies to conversations I’m part of',
                description: 'Keep the conversation going.',
                icon: MessageCircle,
            },
        ],
    },
    {
        title: 'Events & news',
        choices: [
            {
                key: 'events',
                label: 'Changes and reminders for my events',
                description:
                    'Registration updates, event changes and useful reminders.',
                icon: CalendarDays,
            },
            {
                key: 'news',
                label: 'News from topics and teams I follow',
                description: 'Only the interests you have chosen.',
                icon: Newspaper,
            },
        ],
    },
] as const;

function validState(value: HubNotificationState, actor: number | null) {
    return (
        value?.actor_id === actor &&
        typeof value.ready === 'boolean' &&
        (value.ready
            ? Number.isSafeInteger(value.version) && value.version! > 0
            : value.version === null) &&
        choiceKeys.every((key) => typeof value.preferences?.[key] === 'boolean')
    );
}

type Props = {
    open: boolean;
    close: () => void;
    onInterests?: () => void;
    onSaved: () => void;
};

export function HubNotificationDialog(props: Props) {
    const page = usePage<{ auth: { user: { id: number } | null } }>();
    const actor = page.props.auth?.user?.id ?? null;
    return props.open ? (
        <PreferencesBody key={actor} {...props} actor={actor} />
    ) : null;
}

function PreferencesBody({
    close,
    onInterests,
    onSaved,
    actor,
}: Props & { actor: number | null }) {
    const [saved, setSaved] = useState<HubNotificationState | null>(null);
    const [draft, setDraft] = useState<HubNotificationChoices | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [needsReload, setNeedsReload] = useState(false);
    const [refresh, setRefresh] = useState(0);
    const [intent, setIntent] = useState<
        'close' | 'interests' | 'reload' | null
    >(null);
    const pending = useRef(false);
    const alive = useRef(false);
    const dirty =
        !!draft &&
        !!saved &&
        choiceKeys.some((key) => draft[key] !== saved.preferences[key]);
    const navigation = useSettingsLeaveConfirmation(
        dirty || saving,
        'Leave notification preferences?',
        'Your unsaved choices will be discarded. A save already sent may still finish; check your saved preferences before trying again.',
        close,
        saving,
    );

    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => {
        let current = true;
        setLoading(true);
        setError('');
        hubRequest<HubNotificationState>('/company-hub/notifications', 'GET')
            .then((result) => {
                if (!current) return;
                if (!actor || !validState(result, actor))
                    throw new Error(
                        'Your saved choices could not be confirmed. Reopen notification preferences.',
                    );
                setSaved(result);
                setDraft({ ...result.preferences });
                setNeedsReload(false);
            })
            .catch((failure) => {
                if (current) {
                    setError((failure as Error).message);
                    setNeedsReload(true);
                }
            })
            .finally(() => {
                if (current) setLoading(false);
            });
        return () => {
            current = false;
        };
    }, [actor, refresh]);
    const proceed = (next: 'close' | 'interests' | 'reload') => {
        setIntent(null);
        navigation.preserveAndRun(() => {
            if (next === 'reload') setRefresh((value) => value + 1);
            else if (next === 'interests') onInterests?.();
            else close();
        });
    };
    const leave = (next: 'close' | 'interests' | 'reload') => {
        if (pending.current) return;
        if (dirty) setIntent(next);
        else proceed(next);
    };
    const save = async () => {
        if (
            pending.current ||
            loading ||
            needsReload ||
            !actor ||
            !saved?.ready ||
            !draft
        )
            return;
        pending.current = true;
        setSaving(true);
        setError('');
        const submitted = { ...draft };
        let unconfirmed = false;
        try {
            const result = await hubRequest<
                HubNotificationState & { saved: boolean }
            >('/company-hub/notifications', 'PUT', {
                actor_id: actor,
                version: saved.version,
                ...submitted,
            });
            if (!alive.current) return;
            if (
                !validState(result, actor) ||
                !result.ready ||
                result.saved !== true ||
                result.version !== saved.version! + 1 ||
                choiceKeys.some(
                    (key) => result.preferences[key] !== submitted[key],
                )
            ) {
                unconfirmed = true;
                throw new Error(
                    'The saved choices could not be confirmed. Reload them before making another change.',
                );
            }
            setSaved(result);
            setDraft({ ...result.preferences });
            toast.success('Your notification preferences are saved.');
            navigation.preserveAndRun(() => {
                onSaved();
                close();
            });
        } catch (failure) {
            if (alive.current) {
                setError((failure as Error).message);
                setNeedsReload(
                    unconfirmed ||
                        (failure instanceof HubRequestError &&
                            [401, 403, 409, 419, 503].includes(failure.status)),
                );
                setIntent(null);
            }
        } finally {
            if (alive.current) {
                pending.current = false;
                setSaving(false);
            }
        }
    };
    const disabled =
        loading || saving || !saved?.ready || needsReload || !!intent;

    return (
        <>
            <Sheet
                open
                onOpenChange={(open) => {
                    if (!open) leave('close');
                }}
            >
                <SheetContent
                    side="right"
                    className="gap-0 overflow-hidden p-0 sm:max-w-[600px]"
                    overlayClassName="bg-black/30 backdrop-blur-[2px]"
                    closeButtonClassName={
                        saving ? 'pointer-events-none opacity-30' : ''
                    }
                >
                    <SheetHeader className="shrink-0 border-b px-7 pt-7 pr-14 pb-6">
                        <span className="mb-4 flex size-11 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                            <Bell className="size-5" aria-hidden />
                        </span>
                        <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                            Your Company Hub
                        </p>
                        <SheetTitle className="mt-1 text-2xl leading-tight">
                            A useful amount of staying in touch
                        </SheetTitle>
                        <SheetDescription className="mt-2">
                            Choose the Hub updates you want to hear about.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="min-h-0 flex-1 space-y-7 overflow-y-auto px-7 py-6">
                        <div className="flex gap-3 rounded-xl border border-primary/15 bg-primary/5 p-4 text-sm leading-relaxed">
                            <Bell
                                className="mt-0.5 size-4 shrink-0 text-primary"
                                aria-hidden
                            />
                            <p>
                                These are your preferences. Required-read
                                announcements still appear in Needs attention.
                            </p>
                        </div>
                        {loading ? (
                            <p
                                role="status"
                                className="flex items-center gap-2 py-6 text-sm text-muted-foreground"
                            >
                                <Loader2
                                    className="size-4 animate-spin"
                                    aria-hidden
                                />
                                Loading your saved choices…
                            </p>
                        ) : (
                            draft &&
                            groups.map((group) => (
                                <fieldset
                                    key={group.title}
                                    disabled={disabled}
                                    className="space-y-3"
                                >
                                    <legend className="mb-3 text-sm font-semibold">
                                        {group.title}
                                    </legend>
                                    {group.choices.map(
                                        ({
                                            key,
                                            label,
                                            description,
                                            icon: Icon,
                                        }) => (
                                            <label
                                                key={key}
                                                className="flex min-h-[90px] cursor-pointer items-center gap-4 rounded-xl border border-border/70 bg-card p-4 transition-colors hover:border-primary/40 has-[:disabled]:cursor-default has-[:disabled]:opacity-65 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary"
                                            >
                                                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted text-primary">
                                                    <Icon
                                                        className="size-5"
                                                        aria-hidden
                                                    />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-sm leading-snug font-medium">
                                                        {label}
                                                    </span>
                                                    <span className="mt-1 block text-xs leading-relaxed text-muted-foreground">
                                                        {description}
                                                    </span>
                                                </span>
                                                <Switch
                                                    aria-label={label}
                                                    checked={draft[key]}
                                                    disabled={disabled}
                                                    onCheckedChange={(value) =>
                                                        setDraft({
                                                            ...draft,
                                                            [key]: value,
                                                        })
                                                    }
                                                />
                                            </label>
                                        ),
                                    )}
                                </fieldset>
                            ))
                        )}
                        {saved?.ready === false && (
                            <p
                                role="status"
                                className="rounded-xl border bg-muted/40 p-4 text-sm"
                            >
                                Notification editing is being updated. Your
                                current choices are kept. Try again shortly.
                            </p>
                        )}
                        {onInterests && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="h-auto px-0 text-primary"
                                disabled={saving || loading || !!intent}
                                onClick={() => leave('interests')}
                            >
                                <HeartHandshake className="size-4" />
                                Manage followed topics and teams
                                <ArrowRight className="size-4" />
                            </Button>
                        )}
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            Event invitations and required company updates keep
                            their existing delivery settings.
                        </p>
                        {draft && (
                            <details className="border-t pt-4">
                                <summary className="cursor-pointer text-xs text-muted-foreground">
                                    Page owner reminders
                                </summary>
                                <label className="mt-4 flex items-center justify-between gap-4 text-sm">
                                    <span>
                                        Review reminders for pages I own
                                    </span>
                                    <Switch
                                        aria-label="Review reminders for pages I own"
                                        checked={draft.reviews}
                                        disabled={disabled}
                                        onCheckedChange={(reviews) =>
                                            setDraft({ ...draft, reviews })
                                        }
                                    />
                                </label>
                            </details>
                        )}
                        {error && (
                            <div
                                role="alert"
                                className="space-y-3 rounded-xl border border-destructive/25 bg-destructive/5 p-4 text-sm"
                            >
                                <p>{error}</p>
                                <p className="text-muted-foreground">
                                    Your choices are kept here until you reload
                                    or close.
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={loading || saving || !!intent}
                                    onClick={() => leave('reload')}
                                >
                                    <RefreshCw className="size-4" />
                                    Reload saved choices
                                </Button>
                            </div>
                        )}
                        {saved?.ready === false && !error && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={loading}
                                onClick={() => leave('reload')}
                            >
                                <RefreshCw className="size-4" />
                                Try again
                            </Button>
                        )}
                    </div>
                    <SheetFooter className="shrink-0 gap-3 border-t bg-card px-7 py-5">
                        {intent ? (
                            <div role="alert" className="space-y-3">
                                <p className="text-sm font-medium">
                                    You have unsaved choices
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Keep editing, or discard these changes{' '}
                                    {intent === 'reload'
                                        ? 'and load your saved choices.'
                                        : intent === 'interests'
                                          ? 'to manage your interests.'
                                          : 'and close preferences.'}
                                </p>
                                <div className="flex justify-end gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={() => setIntent(null)}
                                    >
                                        Keep editing
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        onClick={() => proceed(intent)}
                                    >
                                        Discard changes
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <div className="flex items-center justify-between gap-4">
                                <span
                                    className="text-xs text-muted-foreground"
                                    role="status"
                                >
                                    {saving
                                        ? 'Saving your choices…'
                                        : dirty
                                          ? 'Unsaved changes'
                                          : 'Just for you'}
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        variant="ghost"
                                        disabled={saving}
                                        onClick={() => leave('close')}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        disabled={disabled || !dirty}
                                        onClick={() => void save()}
                                    >
                                        {saving ? (
                                            <Loader2 className="size-4 animate-spin" />
                                        ) : (
                                            <Check className="size-4" />
                                        )}
                                        Save preferences
                                    </Button>
                                </div>
                            </div>
                        )}
                    </SheetFooter>
                </SheetContent>
            </Sheet>
            {navigation.confirmation}
        </>
    );
}
