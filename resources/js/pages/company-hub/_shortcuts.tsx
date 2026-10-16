import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { useForm, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Link2,
    Pencil,
    Plus,
    Trash2,
    Undo2,
    UserRound,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { HubLink } from './_types';

const destinations = [
    { name: 'Web address', url: '' },
    { name: 'My day', url: '/my-day' },
    { name: 'My HR', url: '/hr/my' },
    { name: 'Company resources', url: '/company-hub?view=resources' },
];
function safeDestination(value: string) {
    if (/[\s\\]/.test(value) || value.length > 2000) return false;
    if (value.startsWith('/') && !value.startsWith('//')) return true;
    try {
        const url = new URL(value);
        return (
            value.startsWith('https://') &&
            url.protocol === 'https:' &&
            !!url.hostname &&
            !url.username &&
            !url.password
        );
    } catch {
        return false;
    }
}
type LinkDraft = { link: HubLink; destination: string; adding: boolean };

export function ShortcutDialog({
    open,
    close,
    shortcuts,
}: {
    open: boolean;
    close: () => void;
    shortcuts: HubLink[];
}) {
    const actor = usePage<{ auth?: { user?: { id?: number } } }>().props.auth
        ?.user?.id;
    return open ? (
        <ShortcutBody key={actor} close={close} shortcuts={shortcuts} />
    ) : null;
}

function ShortcutBody({
    close,
    shortcuts,
}: {
    close: () => void;
    shortcuts: HubLink[];
}) {
    const form = useForm<{ shortcuts: HubLink[] }>({ shortcuts });
    const [initial] = useState(() => JSON.stringify(shortcuts));
    const [editing, setEditing] = useState<LinkDraft | null>(null);
    const [editBaseline, setEditBaseline] = useState('');
    const [undo, setUndo] = useState<HubLink[] | null>(null);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const [busy, setBusy] = useState(false);
    const pending = useRef(false),
        alive = useRef(false);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const dirty =
        initial !== JSON.stringify(form.data.shortcuts) ||
        (!!editing && JSON.stringify(editing) !== editBaseline);
    const navigation = useSettingsLeaveConfirmation(
        dirty || busy,
        'Discard shortcut changes?',
        'Your unsaved shortcuts will be discarded. A save already sent may still finish; check your shortcuts before trying again.',
    );
    const leave = () => {
        if (pending.current) return;
        if (dirty) setDiscard(true);
        else close();
    };
    const change = (links: HubLink[], notice: string) => {
        if (pending.current) return;
        setUndo(form.data.shortcuts);
        form.setData('shortcuts', links);
        setMessage(notice);
        setError('');
    };
    const begin = (link?: HubLink) => {
        const draft = {
            link: link
                ? { ...link }
                : { id: crypto.randomUUID(), title: '', url: '' },
            destination:
                destinations.find((choice) => !!link && choice.url === link.url)
                    ?.name ?? 'Web address',
            adding: !link,
        };
        setEditBaseline(JSON.stringify(draft));
        setEditing(draft);
        setError('');
    };
    const saveLink = () => {
        if (!editing || pending.current) return;
        const link = {
            ...editing.link,
            title: editing.link.title.trim(),
            url: editing.link.url.trim(),
        };
        if (!link.title || link.title.length > 100) {
            setError('Give the shortcut a useful label, up to 100 characters.');
            return;
        }
        if (!safeDestination(link.url)) {
            setError(
                'Use a secure https:// address or an application path such as /my-day.',
            );
            return;
        }
        if (editing.adding && form.data.shortcuts.length >= 20) return;
        change(
            editing.adding
                ? [...form.data.shortcuts, link]
                : form.data.shortcuts.map((item) =>
                      item.id === link.id ? link : item,
                  ),
            editing.adding
                ? 'Shortcut added to your changes.'
                : 'Shortcut updated in your changes.',
        );
        setEditing(null);
    };
    const move = (index: number, direction: -1 | 1) => {
        const target = index + direction;
        if (target < 0 || target >= form.data.shortcuts.length) return;
        const links = [...form.data.shortcuts];
        [links[index], links[target]] = [links[target], links[index]];
        change(
            links,
            `${links[target].title} moved ${direction === -1 ? 'up' : 'down'}.`,
        );
    };
    const save = () => {
        if (pending.current || editing || discard) return;
        pending.current = true;
        setBusy(true);
        form.put('/company-hub/shortcuts', {
            preserveScroll: true,
            onSuccess: () => {
                if (alive.current) navigation.discardAndRun(close);
            },
            onFinish: () => {
                if (alive.current) {
                    pending.current = false;
                    setBusy(false);
                }
            },
        });
    };
    const locked = busy || !!editing || discard;
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
                >
                    <SheetHeader className="shrink-0 border-b px-7 pt-7 pr-14 pb-6">
                        <SheetTitle className="text-2xl">
                            Your useful shortcuts
                        </SheetTitle>
                        <SheetDescription>
                            Keep the places you use most within easy reach.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-7 py-6">
                        <p className="flex items-start gap-2 rounded-lg bg-muted/50 p-3 text-sm text-muted-foreground">
                            <UserRound
                                className="mt-0.5 size-4 shrink-0"
                                aria-hidden
                            />
                            These links are personal to you. They do not change
                            the shared Hub navigation.
                        </p>
                        <ol
                            className="divide-y"
                            aria-label="Your shortcut order"
                        >
                            {form.data.shortcuts.map((link, index) => (
                                <li
                                    key={link.id}
                                    className="flex items-center gap-3 py-4"
                                >
                                    <Link2
                                        className="size-5 shrink-0 text-primary"
                                        aria-hidden
                                    />
                                    <div className="min-w-0 flex-1">
                                        <h3 className="text-sm font-semibold break-words">
                                            {link.title}
                                        </h3>
                                        <p className="mt-1 text-xs break-all text-muted-foreground">
                                            {link.url}
                                        </p>
                                    </div>
                                    <div className="grid shrink-0 grid-cols-2 gap-1">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            disabled={locked || index === 0}
                                            aria-label={`Move ${link.title} up`}
                                            onClick={() => move(index, -1)}
                                        >
                                            <ArrowUp className="size-4" />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            disabled={
                                                locked ||
                                                index ===
                                                    form.data.shortcuts.length -
                                                        1
                                            }
                                            aria-label={`Move ${link.title} down`}
                                            onClick={() => move(index, 1)}
                                        >
                                            <ArrowDown className="size-4" />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            disabled={locked}
                                            aria-label={`Edit ${link.title}`}
                                            onClick={() => begin(link)}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            disabled={locked}
                                            aria-label={`Remove ${link.title}`}
                                            onClick={() =>
                                                change(
                                                    form.data.shortcuts.filter(
                                                        (item) =>
                                                            item.id !== link.id,
                                                    ),
                                                    `${link.title} removed from your changes.`,
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ol>
                        {form.data.shortcuts.length === 0 && !editing && (
                            <div className="py-6 text-center">
                                <Link2
                                    className="mx-auto mb-3 size-7 text-primary"
                                    aria-hidden
                                />
                                <h3 className="font-semibold">
                                    Your everyday places, one click away
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Add an application page or a useful website
                                    to get started.
                                </p>
                            </div>
                        )}
                        {editing ? (
                            <form
                                className="space-y-4 rounded-xl border bg-muted/30 p-5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    saveLink();
                                }}
                            >
                                <h3 className="font-semibold">
                                    {editing.adding
                                        ? 'Add a shortcut'
                                        : 'Edit shortcut'}
                                </h3>
                                <div className="space-y-2">
                                    <Label htmlFor="shortcut-label">
                                        Link label
                                    </Label>
                                    <Input
                                        id="shortcut-label"
                                        required
                                        maxLength={100}
                                        autoFocus
                                        disabled={busy || discard}
                                        value={editing.link.title}
                                        onChange={(event) =>
                                            setEditing({
                                                ...editing,
                                                link: {
                                                    ...editing.link,
                                                    title: event.target.value,
                                                },
                                            })
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="shortcut-destination">
                                        Destination
                                    </Label>
                                    <Select
                                        disabled={busy || discard}
                                        value={editing.destination}
                                        onValueChange={(destination) =>
                                            setEditing({
                                                ...editing,
                                                destination,
                                                link: {
                                                    ...editing.link,
                                                    url:
                                                        destinations.find(
                                                            (item) =>
                                                                item.name ===
                                                                destination,
                                                        )?.url ?? '',
                                                },
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id="shortcut-destination"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {destinations.map((item) => (
                                                <SelectItem
                                                    className="focus:text-primary"
                                                    key={item.name}
                                                    value={item.name}
                                                >
                                                    {item.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                {editing.destination === 'Web address' ? (
                                    <div className="space-y-2">
                                        <Label htmlFor="shortcut-url">
                                            Web address
                                        </Label>
                                        <Input
                                            id="shortcut-url"
                                            required
                                            maxLength={2000}
                                            disabled={busy || discard}
                                            value={editing.link.url}
                                            placeholder="https://… or /internal-page"
                                            onChange={(event) =>
                                                setEditing({
                                                    ...editing,
                                                    link: {
                                                        ...editing.link,
                                                        url: event.target.value,
                                                    },
                                                })
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Use https:// for websites, or an
                                            application path such as /my-day.
                                        </p>
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        Opens {editing.destination} within the
                                        application.
                                    </p>
                                )}
                                {error && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {error}
                                    </p>
                                )}
                                <div className="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        disabled={busy || discard}
                                        onClick={() => {
                                            setEditing(null);
                                            setError('');
                                        }}
                                    >
                                        Cancel edit
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={busy || discard}
                                    >
                                        Keep link
                                    </Button>
                                </div>
                            </form>
                        ) : (
                            <Button
                                variant="outline"
                                disabled={
                                    locked || form.data.shortcuts.length >= 20
                                }
                                onClick={() => begin()}
                            >
                                <Plus className="size-4" />
                                Add a shortcut
                            </Button>
                        )}
                        <p className="text-xs text-muted-foreground">
                            {form.data.shortcuts.length} of 20 shortcuts
                        </p>
                        {message && (
                            <div
                                role="status"
                                className="flex items-center justify-between gap-3 rounded-lg bg-muted/50 px-3 py-2 text-sm"
                            >
                                <span>{message}</span>
                                {undo && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={locked}
                                        onClick={() => {
                                            form.setData('shortcuts', undo);
                                            setUndo(null);
                                            setMessage('Last change undone.');
                                        }}
                                    >
                                        <Undo2 className="size-4" />
                                        Undo
                                    </Button>
                                )}
                            </div>
                        )}
                        {Object.values(form.errors).length > 0 && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {Object.values(form.errors).join(' ')}
                            </p>
                        )}
                    </div>
                    {discard && (
                        <div
                            role="alert"
                            className="border-t bg-muted/50 px-7 py-5"
                        >
                            <h3 className="font-semibold">
                                Discard shortcut changes?
                            </h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Your saved shortcuts will stay as they are.
                            </p>
                            <div className="mt-4 flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setDiscard(false)}
                                >
                                    Keep editing
                                </Button>
                                <Button
                                    onClick={() =>
                                        navigation.discardAndRun(close)
                                    }
                                >
                                    Discard changes
                                </Button>
                            </div>
                        </div>
                    )}
                    <SheetFooter className="border-t px-7 py-5">
                        {editing && (
                            <p className="mb-2 text-xs text-muted-foreground">
                                Keep this link or cancel editing before saving
                                your shortcuts.
                            </p>
                        )}
                        <div className="flex items-center justify-between gap-3">
                            <Button
                                variant="ghost"
                                disabled={busy || discard}
                                onClick={leave}
                            >
                                Cancel
                            </Button>
                            <Button disabled={locked} onClick={save}>
                                {busy ? 'Saving…' : 'Save shortcuts'}
                            </Button>
                        </div>
                    </SheetFooter>
                </SheetContent>
            </Sheet>
            {navigation.confirmation}
        </>
    );
}
