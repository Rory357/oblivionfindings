import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { usePage } from '@inertiajs/react';
import { Check, Gift, Loader2, Search, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import type { HubProps } from './_types';

type BirthdaySettings = HubProps['birthdaySettings'];

export function BirthdaySettingsDialog({
    open,
    close,
    people,
}: {
    open: boolean;
    close: () => void;
    people: BirthdaySettings;
}) {
    const actor = usePage<{ auth?: { user?: { id?: number } } }>().props.auth
        ?.user?.id;
    return open ? (
        <BirthdaySettingsBody
            key={actor}
            actor={actor}
            close={close}
            people={people}
        />
    ) : null;
}

function BirthdaySettingsBody({
    actor,
    close,
    people,
}: {
    actor?: number;
    close: () => void;
    people: BirthdaySettings;
}) {
    type Person = BirthdaySettings[number] & { version?: number | null };
    const [currentPeople, setCurrentPeople] = useState<Person[]>([]);
    const [ready, setReady] = useState(false);
    const [loading, setLoading] = useState(true);
    const [readError, setReadError] = useState('');
    const [readVersion, setReadVersion] = useState(0);
    const [search, setSearch] = useState('');
    const [selectedId, setSelectedId] = useState(people[0]?.id);
    const [drafts, setDrafts] = useState<Record<number, boolean>>({});
    const [messages, setMessages] = useState<
        Record<number, { error?: string; saved?: string }>
    >({});
    const [checkRequired, setCheckRequired] = useState(false);
    const [busy, setBusy] = useState(false);
    const pending = useRef(false);
    const alive = useRef(false);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => {
        let active = true;
        setLoading(true);
        setReadError('');
        setReady(false);
        void hubRequest<{ ready: boolean; actor_id: number; people: Person[] }>(
            `/company-hub/celebrations?actor_id=${actor ?? ''}`,
            'GET',
        )
            .then((result) => {
                if (!active) return;
                if (
                    !actor ||
                    result.actor_id !== actor ||
                    typeof result.ready !== 'boolean' ||
                    !Array.isArray(result.people) ||
                    !result.people.every(
                        (person) =>
                            Number.isSafeInteger(person.id) &&
                            person.id > 0 &&
                            typeof person.name === 'string' &&
                            typeof person.enabled === 'boolean' &&
                            (!result.ready ||
                                (Number.isSafeInteger(person.version) &&
                                    person.version! > 0)),
                    )
                )
                    throw new Error(
                        'The current birthday preferences could not be confirmed.',
                    );
                setCurrentPeople(result.people);
                setReady(result.ready);
                setCheckRequired(false);
                setMessages({});
                setSelectedId((id) =>
                    result.people.some((person) => person.id === id)
                        ? id
                        : result.people[0]?.id,
                );
            })
            .catch((err: Error) => {
                if (active) {
                    setReadError(err.message);
                    setCurrentPeople([]);
                }
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [actor, readVersion]);
    const savedValue = (person: Person) => person.enabled;
    const isDirty = (person: Person) =>
        drafts[person.id] !== undefined && drafts[person.id] !== person.enabled;
    const dirty = Object.entries(drafts).some(([id, enabled]) => {
        const person = currentPeople.find((entry) => entry.id === Number(id));
        return !person || person.enabled !== enabled;
    });
    const navigation = useSettingsLeaveConfirmation(
        dirty || busy,
        'Discard birthday-sharing changes?',
        'Your unsaved individual choices will be discarded. A save already sent may still finish; check the saved preference before trying again.',
        close,
        busy,
    );
    const selected = currentPeople.find((person) => person.id === selectedId);
    const shown = currentPeople.filter((person) =>
        person.name.toLowerCase().includes(search.trim().toLowerCase()),
    );
    const leave = () => {
        if (!pending.current)
            navigation.request(() => {
                if (!dirty) close();
            });
    };
    const save = async () => {
        if (
            !actor ||
            !selected ||
            !ready ||
            loading ||
            checkRequired ||
            !selected.version ||
            !isDirty(selected) ||
            pending.current
        )
            return;
        const person = selected;
        const enabled = drafts[person.id];
        pending.current = true;
        setBusy(true);
        setMessages((current) => ({ ...current, [person.id]: {} }));
        try {
            const result = await hubRequest<{
                saved: boolean;
                ready: boolean;
                actor_id: number;
                person: Person;
            }>(`/company-hub/celebrations/${person.id}`, 'PUT', {
                actor_id: actor,
                version: person.version,
                birthday_enabled: enabled,
            });
            if (!alive.current) return;
            if (
                result.saved !== true ||
                result.ready !== true ||
                result.actor_id !== actor ||
                result.person?.id !== person.id ||
                typeof result.person.name !== 'string' ||
                result.person.enabled !== enabled ||
                result.person.version !== person.version! + 1
            )
                throw new Error(
                    'The saved preference could not be confirmed. Check the current setting before trying again.',
                );
            setCurrentPeople((current) =>
                current.map((entry) =>
                    entry.id === person.id ? result.person : entry,
                ),
            );
            setDrafts((current) => {
                const next = { ...current };
                delete next[person.id];
                return next;
            });
            setMessages((current) => ({
                ...current,
                [person.id]: {
                    saved: enabled
                        ? 'Birthday sharing enabled for this person.'
                        : 'Birthday sharing turned off for this person.',
                },
            }));
        } catch (err) {
            if (!alive.current) return;
            setCheckRequired(true);
            setReady(false);
            setCurrentPeople([]);
            setReadError((err as Error).message);
            setMessages((current) => ({
                ...current,
                [person.id]: { error: (err as Error).message },
            }));
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) leave();
                }}
            >
                <DialogContent
                    className="gap-0 overflow-hidden p-0"
                    style={{
                        width: 'min(92vw, 800px)',
                        maxWidth: 'min(92vw, 800px)',
                    }}
                >
                    <DialogHeader className="border-b px-7 py-6">
                        <div className="mb-2 flex items-center gap-2 text-xs font-semibold tracking-widest text-primary uppercase">
                            <Gift className="size-4" /> Celebrating our people
                        </div>
                        <DialogTitle>Birthday sharing</DialogTitle>
                        <DialogDescription>
                            Choose whose birthday can appear in the Company Hub.
                            Each preference is saved individually.
                        </DialogDescription>
                    </DialogHeader>
                    {loading && (
                        <p
                            role="status"
                            className="border-b px-7 py-4 text-sm text-muted-foreground"
                        >
                            Checking current birthday preferences…
                        </p>
                    )}
                    {(readError || (!loading && !ready) || checkRequired) && (
                        <div className="space-y-2 border-b px-7 py-4 text-sm">
                            {readError && (
                                <p role="alert" className="text-destructive">
                                    {readError}
                                </p>
                            )}
                            {!loading && !ready && !readError && (
                                <p>
                                    Birthday settings are awaiting an
                                    application update. Existing sharing choices
                                    remain unchanged.
                                </p>
                            )}
                            {checkRequired && (
                                <p>
                                    Check the current saved preferences before
                                    trying again. Your unsaved choices stay
                                    here.
                                </p>
                            )}
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={busy || loading}
                                onClick={() =>
                                    setReadVersion((value) => value + 1)
                                }
                            >
                                Check saved preferences
                            </Button>
                        </div>
                    )}
                    <div className="grid min-h-72 grid-cols-[260px_minmax(0,1fr)]">
                        <div className="border-r bg-muted/20 p-4">
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground" />
                                <Input
                                    className="pl-9"
                                    aria-label="Find a staff member"
                                    placeholder="Find a colleague…"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </div>
                            <div
                                className="mt-3 max-h-72 space-y-1 overflow-y-auto"
                                aria-label="Birthday preferences"
                            >
                                {shown.map((person) => (
                                    <Button
                                        key={person.id}
                                        variant={
                                            selectedId === person.id
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        className="h-auto w-full justify-start px-3 py-3 text-left whitespace-normal"
                                        disabled={busy}
                                        aria-label={`${person.name}: ${isDirty(person) ? 'Unsaved choice' : savedValue(person) ? 'Shared with staff' : 'Birthday hidden'}`}
                                        aria-pressed={selectedId === person.id}
                                        onClick={() => setSelectedId(person.id)}
                                    >
                                        <span className="min-w-0">
                                            <span className="block font-medium break-words">
                                                {person.name}
                                            </span>
                                            <span className="mt-1 block text-xs font-normal text-muted-foreground">
                                                {isDirty(person)
                                                    ? 'Unsaved choice'
                                                    : savedValue(person)
                                                      ? 'Shared with staff'
                                                      : 'Birthday hidden'}
                                            </span>
                                        </span>
                                    </Button>
                                ))}
                                {!shown.length && (
                                    <p className="px-3 py-5 text-sm text-muted-foreground">
                                        {currentPeople.length
                                            ? 'No colleagues match this search.'
                                            : 'No birthday preferences are available.'}
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="px-7 py-6">
                            {selected ? (
                                <>
                                    <h3 className="text-lg font-semibold break-words">
                                        {selected.name}
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        A personal choice, managed with care.
                                    </p>
                                    <div className="my-6 flex items-start gap-3 rounded-xl bg-primary/5 p-4 text-sm">
                                        <ShieldCheck className="mt-0.5 size-5 shrink-0 text-primary" />
                                        <p>
                                            Only the day and month are shared.
                                            Their age and birth year stay
                                            private.
                                        </p>
                                    </div>
                                    <div className="flex items-start justify-between gap-5">
                                        <div>
                                            <Label
                                                htmlFor="hub-birthday-sharing"
                                                className="font-semibold"
                                            >
                                                Share birthday with staff
                                            </Label>
                                            <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                                Confirm this person’s sharing
                                                preference before enabling it.
                                            </p>
                                        </div>
                                        <Switch
                                            id="hub-birthday-sharing"
                                            checked={
                                                drafts[selected.id] ??
                                                savedValue(selected)
                                            }
                                            disabled={
                                                busy ||
                                                loading ||
                                                !ready ||
                                                !actor ||
                                                checkRequired
                                            }
                                            onCheckedChange={(enabled) => {
                                                setDrafts((current) => ({
                                                    ...current,
                                                    [selected.id]: enabled,
                                                }));
                                                setMessages((current) => ({
                                                    ...current,
                                                    [selected.id]: {},
                                                }));
                                            }}
                                        />
                                    </div>
                                    {isDirty(selected) && (
                                        <p className="mt-5 text-xs text-muted-foreground">
                                            This choice has not been saved yet.
                                        </p>
                                    )}
                                    {messages[selected.id]?.error && (
                                        <p
                                            role="alert"
                                            className="mt-5 text-sm text-destructive"
                                        >
                                            {messages[selected.id].error}
                                        </p>
                                    )}
                                    {messages[selected.id]?.saved && (
                                        <p
                                            role="status"
                                            className="mt-5 flex items-start gap-2 text-sm"
                                        >
                                            <Check className="size-4 shrink-0 text-primary" />
                                            {messages[selected.id].saved}
                                        </p>
                                    )}
                                </>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Choose a colleague to review their
                                    individual preference.
                                </p>
                            )}
                        </div>
                    </div>
                    <DialogFooter className="items-center border-t px-7 py-4 sm:justify-between">
                        <p className="text-xs text-muted-foreground">
                            Birthday sharing starts off for everyone.
                        </p>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={leave}
                            >
                                Done
                            </Button>
                            <Button
                                disabled={
                                    busy ||
                                    loading ||
                                    !ready ||
                                    checkRequired ||
                                    !actor ||
                                    !selected ||
                                    !isDirty(selected)
                                }
                                onClick={save}
                            >
                                {busy ? (
                                    <>
                                        <Loader2 className="size-4 animate-spin" />
                                        Saving…
                                    </>
                                ) : (
                                    'Save preference'
                                )}
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {navigation.confirmation}
        </>
    );
}
