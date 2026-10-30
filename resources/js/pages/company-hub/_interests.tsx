import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { usePage } from '@inertiajs/react';
import {
    Building2,
    Check,
    GraduationCap,
    Heart,
    HeartHandshake,
    Monitor,
    Newspaper,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest, HubRequestError } from './_api';
import type { HubContent, HubInterests } from './_types';

export const hubTopics = [
    'People & culture',
    'Wellbeing',
    'Learning',
    'Digital tools',
    'Company news',
];

function TopicIcon({ topic }: { topic: string }) {
    const Icon =
        topic === 'Learning'
            ? GraduationCap
            : topic === 'Digital tools'
              ? Monitor
              : topic === 'Company news'
                ? Newspaper
                : topic === 'Wellbeing'
                  ? Heart
                  : HeartHandshake;
    return <Icon className="size-4 shrink-0 text-primary" aria-hidden />;
}

function Choices<T extends string | number>({
    legend,
    options,
    value,
    onChange,
    disabled,
}: {
    legend: string;
    options: Array<{ id: T; name: string }>;
    value: T[];
    onChange: (value: T[]) => void;
    disabled?: boolean;
}) {
    return (
        <fieldset disabled={disabled} className="space-y-3">
            <legend className="mb-2 text-sm font-semibold">{legend}</legend>
            <div className="hub-interest-grid">
                {options.map((option) => (
                    <label
                        key={option.id}
                        className="hub-interest-choice"
                        data-selected={value.includes(option.id)}
                    >
                        <input
                            type="checkbox"
                            checked={value.includes(option.id)}
                            onChange={(e) =>
                                onChange(
                                    e.target.checked
                                        ? [...value, option.id]
                                        : value.filter(
                                              (id) => id !== option.id,
                                          ),
                                )
                            }
                        />
                        {legend === 'Topics' ? (
                            <TopicIcon topic={option.name} />
                        ) : (
                            <Building2 className="size-4" aria-hidden />
                        )}
                        <span>{option.name}</span>
                    </label>
                ))}
            </div>
            {options.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No teams are currently available.
                </p>
            )}
        </fieldset>
    );
}

export function ContentInterests({
    content,
    teams,
    onChange,
}: {
    content: HubContent;
    teams: HubInterests['available_teams'];
    onChange: (changes: Partial<HubContent>) => void;
}) {
    return (
        <div className="hub-content-interests space-y-4 border-t pt-4">
            <h3 className="font-semibold">Help people discover this page</h3>
            <p className="text-xs text-muted-foreground">
                Topics and related teams connect this page to Following. The
                page audience still controls who can read it.
            </p>
            <Choices
                legend="Topics"
                options={hubTopics.map((name) => ({ id: name, name }))}
                value={content.topics ?? []}
                onChange={(topics) => onChange({ topics })}
            />
            <Choices
                legend="Related teams"
                options={[
                    ...teams,
                    ...(content.team_ids ?? [])
                        .filter((id) => !teams.some((team) => team.id === id))
                        .map((id) => ({
                            id,
                            name: 'Unavailable team — remove before saving',
                        })),
                ]}
                value={content.team_ids ?? []}
                onChange={(team_ids) => onChange({ team_ids })}
            />
        </div>
    );
}

type InterestsProps = {
    initial: HubInterests;
    onClose: () => void;
    onSaved: (result: HubInterests) => void;
    onReloaded?: (result: HubInterests) => void;
};
const sameChoices = <T extends string | number>(a: T[], b: T[]) =>
    a.length === b.length && a.every((value) => b.includes(value));
function validInterests(value: HubInterests, actor: number | null) {
    return (
        !!actor &&
        value?.actor_id === actor &&
        Number.isSafeInteger(value.version) &&
        value.version > 0 &&
        Array.isArray(value.available_topics) &&
        value.available_topics.every((topic) => typeof topic === 'string') &&
        Array.isArray(value.available_teams) &&
        value.available_teams.every(
            (team) =>
                !!team &&
                Number.isSafeInteger(team.id) &&
                team.id > 0 &&
                typeof team.name === 'string',
        ) &&
        Array.isArray(value.topics) &&
        new Set(value.topics).size === value.topics.length &&
        value.topics.every((topic) => value.available_topics.includes(topic)) &&
        Array.isArray(value.teams) &&
        new Set(value.teams).size === value.teams.length &&
        value.teams.every((id) =>
            value.available_teams.some((team) => team.id === id),
        )
    );
}

export function InterestsDialog(props: InterestsProps) {
    const actor =
        usePage<{ auth?: { user?: { id?: number } } }>().props.auth?.user?.id ??
        null;
    return <InterestsBody key={actor} {...props} actor={actor} />;
}

function InterestsBody({
    initial,
    onClose,
    onSaved,
    onReloaded,
    actor,
}: InterestsProps & { actor: number | null }) {
    const [saved, setSaved] = useState<HubInterests | null>(() =>
        validInterests(initial, actor) ? initial : null,
    );
    const [draft, setDraft] = useState<HubInterests | null>(saved);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(
        saved
            ? ''
            : 'Your saved choices could not be confirmed. Reload them to continue.',
    );
    const [needsReload, setNeedsReload] = useState(!saved);
    const [intent, setIntent] = useState<'close' | 'reload' | null>(null);
    const alive = useRef(false),
        pending = useRef(false);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const dirty =
        !!saved &&
        !!draft &&
        (!sameChoices(saved.topics, draft.topics) ||
            !sameChoices(saved.teams, draft.teams));
    const navigation = useSettingsLeaveConfirmation(
        dirty || busy,
        busy
            ? 'Leave while your interests are saving?'
            : 'Discard interest changes?',
        busy
            ? 'Your request may still finish. Check your saved choices before trying again.'
            : 'Your unsaved topic and team choices will be discarded. Cancel to keep editing.',
    );
    const save = async () => {
        if (pending.current || !draft || !saved || !actor || needsReload)
            return;
        pending.current = true;
        setBusy(true);
        setError('');
        let unconfirmed = false;
        try {
            const result = await hubRequest<HubInterests>(
                '/company-hub/interests',
                'PUT',
                {
                    actor_id: actor,
                    version: saved.version,
                    topics: draft.topics,
                    teams: draft.teams,
                },
            );
            if (!alive.current) return;
            if (
                !validInterests(result, actor) ||
                result.version !== saved.version + 1 ||
                !sameChoices(result.topics, draft.topics) ||
                !sameChoices(result.teams, draft.teams)
            ) {
                unconfirmed = true;
                throw new Error(
                    'Your saved interests could not be confirmed. Reload them before making another change.',
                );
            }
            setSaved(result);
            setDraft(result);
            navigation.discardAndRun(() => {
                onSaved(result);
                onClose();
            });
        } catch (err) {
            if (alive.current) {
                setError((err as Error).message);
                setNeedsReload(
                    unconfirmed ||
                        (err instanceof HubRequestError &&
                            [401, 403, 409, 419, 503].includes(err.status)),
                );
            }
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    const reload = async () => {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<HubInterests>(
                '/company-hub/interests',
                'GET',
            );
            if (!alive.current) return;
            if (!validInterests(result, actor))
                throw new Error(
                    'These choices belong to a different sign-in or could not be confirmed. Reopen your Company Hub.',
                );
            setSaved(result);
            setDraft(result);
            setNeedsReload(false);
            navigation.discardAndRun(() => onReloaded?.(result));
        } catch (err) {
            if (alive.current) {
                setError((err as Error).message);
                setNeedsReload(true);
            }
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    const proceed = (next: 'close' | 'reload') => {
        setIntent(null);
        if (next === 'reload') void reload();
        else onClose();
    };
    const leave = (next: 'close' | 'reload') => {
        if (pending.current) return;
        if (dirty) setIntent(next);
        else proceed(next);
    };
    const disabled = busy || needsReload || !!intent;
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
                        busy ? 'pointer-events-none opacity-30' : ''
                    }
                >
                    <SheetHeader className="shrink-0 border-b px-7 pt-7 pr-14 pb-6">
                        <SheetTitle className="text-2xl">
                            Follow your interests
                        </SheetTitle>
                        <SheetDescription>
                            Choose the topics and teams you want to keep up
                            with.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="min-h-0 flex-1 space-y-7 overflow-y-auto px-7 py-6">
                        {draft && (
                            <>
                                <Choices
                                    legend="Topics"
                                    options={draft.available_topics.map(
                                        (name) => ({
                                            id: name,
                                            name,
                                        }),
                                    )}
                                    value={draft.topics}
                                    disabled={disabled}
                                    onChange={(topics) =>
                                        setDraft({ ...draft, topics })
                                    }
                                />
                                <Choices
                                    legend="Teams"
                                    options={draft.available_teams}
                                    value={draft.teams}
                                    disabled={disabled}
                                    onChange={(teams) =>
                                        setDraft({ ...draft, teams })
                                    }
                                />
                            </>
                        )}
                        <p className="rounded-lg bg-muted/50 p-3 text-sm text-muted-foreground">
                            Your choices personalise Following. Everyone keeps
                            the same shared Company Hub design.
                        </p>
                        {error && (
                            <div
                                role="alert"
                                className="space-y-2 text-sm text-destructive"
                            >
                                <p>{error}</p>
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => leave('reload')}
                                >
                                    Reload saved choices
                                </Button>
                            </div>
                        )}
                    </div>
                    {intent && (
                        <div
                            role="alert"
                            className="border-t bg-muted/50 px-7 py-5"
                        >
                            <h3 className="font-semibold">
                                Discard unsaved choices?
                            </h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {intent === 'reload'
                                    ? 'Reloading replaces your changes with the current saved interests.'
                                    : 'Your saved interests stay as they are.'}
                            </p>
                            <div className="mt-4 flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setIntent(null)}
                                >
                                    Keep editing
                                </Button>
                                <Button onClick={() => proceed(intent)}>
                                    {intent === 'reload'
                                        ? 'Discard and reload'
                                        : 'Discard changes'}
                                </Button>
                            </div>
                        </div>
                    )}
                    <SheetFooter className="flex-row items-center justify-between border-t px-7 py-5">
                        <Button
                            variant="ghost"
                            disabled={busy || !!intent}
                            onClick={() => leave('close')}
                        >
                            Cancel
                        </Button>
                        <Button disabled={disabled || !draft} onClick={save}>
                            <Check className="size-4" />
                            {busy ? 'Saving…' : 'Save interests'}
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>
            {navigation.confirmation}
        </>
    );
}
