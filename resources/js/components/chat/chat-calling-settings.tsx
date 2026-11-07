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
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { Check, KeyRound, PhoneCall, RadioTower } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type ReactNode,
    type RefObject,
} from 'react';
import { ChatError } from './api';
import { chatActorRequest } from './chat-actor-request';

export type ChatCallingPlainField =
    | 'relay_urls'
    | 'relay_credential_seconds'
    | 'relay_health_url'
    | 'relay_control_urls'
    | 'relay_control_key_id'
    | 'calling_url'
    | 'calling_api_url'
    | 'calling_api_key';
export type ChatCallingSecretField =
    | 'relay_secret'
    | 'relay_control_secret'
    | 'calling_api_secret';
type PlainValue = string | string[] | number | null;

export type ChatCallingSettingsData = {
    version: number;
    updated_at: string | null;
    updated_by: string | null;
    fields: Record<
        ChatCallingPlainField,
        { on_page: boolean; value: PlainValue; server: PlainValue }
    > &
        Record<
            ChatCallingSecretField,
            { on_page: boolean; on_server: boolean }
        >;
    status: {
        relay: boolean;
        control: boolean;
        calling: boolean;
        calling_verified: boolean;
    };
};

/** Field names, shared with the settings audit. */
export const chatCallingLabels: Record<
    ChatCallingPlainField | ChatCallingSecretField,
    string
> = {
    relay_urls: 'Relay addresses',
    relay_secret: 'Relay key',
    relay_credential_seconds: 'Call pass length',
    relay_health_url: 'Status check address',
    relay_control_urls: 'Control addresses',
    relay_control_secret: 'Control key',
    relay_control_key_id: 'Control key name',
    calling_url: 'Call server address',
    calling_api_url: 'Call server API address',
    calling_api_key: 'Call server API key',
    calling_api_secret: 'Call server API secret',
};

const plainFields: ChatCallingPlainField[] = [
    'relay_urls',
    'relay_credential_seconds',
    'relay_health_url',
    'relay_control_urls',
    'relay_control_key_id',
    'calling_url',
    'calling_api_url',
    'calling_api_key',
];
const secretFields: ChatCallingSecretField[] = [
    'relay_secret',
    'relay_control_secret',
    'calling_api_secret',
];
const listFields: ChatCallingPlainField[] = [
    'relay_urls',
    'relay_control_urls',
];

/** How a saved or server value reads in a field and in the audit. */
export function describeChatCallingValue(
    field: ChatCallingPlainField,
    value: PlainValue,
): string {
    if (value === null || value === '') return '';
    if (Array.isArray(value)) return value.join(', ');
    if (field === 'relay_credential_seconds') {
        const minutes = Math.round(Number(value) / 60);
        return minutes === 1 ? '1 minute' : minutes + ' minutes';
    }
    return String(value);
}

function toText(field: ChatCallingPlainField, value: PlainValue): string {
    if (value === null) return '';
    if (Array.isArray(value)) return value.join('\n');
    if (field === 'relay_credential_seconds')
        return String(Math.round(Number(value) / 60));
    return String(value);
}

function fromText(field: ChatCallingPlainField, text: string): PlainValue {
    const trimmed = text.trim();
    if (trimmed === '') return null;
    if (listFields.includes(field)) {
        const lines = trimmed
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter(Boolean);
        return lines.length ? lines : null;
    }
    if (field === 'relay_credential_seconds') {
        const minutes = Number(trimmed);
        // Anything that isn't a number goes as typed, so the server can say what's wrong.
        return Number.isFinite(minutes) ? Math.round(minutes * 60) : trimmed;
    }
    return trimmed;
}

/** A strong key made in this browser: 48 random bytes, as 64 base64 characters. */
function generateKey(): string {
    const bytes = new Uint8Array(48);
    crypto.getRandomValues(bytes);
    return btoa(String.fromCharCode(...bytes));
}

type SecretDraft = { mode: 'keep' | 'replace' | 'clear'; value: string };
type Draft = {
    plain: Record<ChatCallingPlainField, string>;
    secrets: Record<ChatCallingSecretField, SecretDraft>;
};

function draftFrom(data: ChatCallingSettingsData): Draft {
    return {
        plain: Object.fromEntries(
            plainFields.map((field) => [
                field,
                data.fields[field].on_page
                    ? toText(field, data.fields[field].value)
                    : '',
            ]),
        ) as Draft['plain'],
        secrets: Object.fromEntries(
            secretFields.map((field) => [field, { mode: 'keep', value: '' }]),
        ) as Draft['secrets'],
    };
}

type Change = { field: string; label: string; before: string; after: string };

function changesBetween(data: ChatCallingSettingsData, draft: Draft): Change[] {
    const saved = draftFrom(data);
    const reading = (field: ChatCallingPlainField, text: string) => {
        if (text.trim() !== '')
            return describeChatCallingValue(field, fromText(field, text));
        const server = describeChatCallingValue(
            field,
            data.fields[field].server,
        );
        return server ? `The server’s setting (${server})` : 'Not set';
    };
    const keyReading = (field: ChatCallingSecretField) =>
        data.fields[field].on_page
            ? 'Saved on this page'
            : data.fields[field].on_server
              ? 'The server’s key'
              : 'Not set';
    return [
        ...plainFields
            .filter(
                (field) =>
                    draft.plain[field].trim() !== saved.plain[field].trim(),
            )
            .map((field) => ({
                field,
                label: chatCallingLabels[field],
                before: reading(field, saved.plain[field]),
                after: reading(field, draft.plain[field]),
            })),
        ...secretFields
            .filter((field) => draft.secrets[field].mode !== 'keep')
            .map((field) => ({
                field,
                label: chatCallingLabels[field],
                before: keyReading(field),
                after:
                    draft.secrets[field].mode === 'replace'
                        ? 'A new key'
                        : data.fields[field].on_server
                          ? 'The server’s key'
                          : 'Not set',
            })),
    ];
}

const hints: Record<ChatCallingPlainField | ChatCallingSecretField, string> = {
    relay_urls:
        'One address per line, up to four. For example turn:relay.example.com:3479?transport=udp, then turns:relay.example.com:443?transport=tcp.',
    relay_secret: 'Must match the chat key on the relay server.',
    relay_credential_seconds:
        'How long the relay accepts a person’s call pass, from 1 to 60 minutes. The app gives out a new one before it runs out.',
    relay_health_url:
        'Only when the relay runs on this server, for example http://127.0.0.1:9478. Leave empty when it has a server of its own.',
    relay_control_urls:
        'One address per line, up to four. A private or loopback address with a port, for example http://127.0.0.1:9479.',
    relay_control_secret:
        'Must match the control key on the relay server, and be different from the relay key.',
    relay_control_key_id:
        'The same name as the control key name on the relay server, for example k2026a.',
    calling_url:
        'Where browsers join calls. Starts with wss:// and is on your own server.',
    calling_api_url:
        'Where the app manages calls. Starts with https:// and is on your own server.',
    calling_api_key: 'From the call server’s settings.',
    calling_api_secret:
        'From the call server’s settings. At least 32 characters.',
};

export function ChatCallingSettings({
    actorId,
    headingRef,
    onDirtyChange,
    reviewRequest = 0,
}: {
    actorId: number;
    headingRef?: RefObject<HTMLHeadingElement | null>;
    onDirtyChange?: (dirty: boolean) => void;
    /** Each increase opens the review, when there are changes (the page's "Review changes"). */
    reviewRequest?: number;
}) {
    const [data, setData] = useState<ChatCallingSettingsData | null>(null);
    const [draft, setDraft] = useState<Draft | null>(null);
    const [loadError, setLoadError] = useState('');
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [review, setReview] = useState(false);
    const [password, setPassword] = useState('');
    const [saving, setSaving] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [generated, setGenerated] = useState<ChatCallingSecretField[]>([]);
    const [copied, setCopied] = useState<ChatCallingSecretField | null>(null);
    const request = useRef<AbortController | null>(null);
    const reviewButton = useRef<HTMLButtonElement>(null);

    const load = useCallback(async () => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setLoadError('');
        try {
            const result = await chatActorRequest<ChatCallingSettingsData>(
                actorId,
                '/settings/chat/calling',
                {
                    signal: AbortSignal.any([
                        controller.signal,
                        AbortSignal.timeout(10000),
                    ]),
                },
            );
            if (request.current !== controller) return;
            setData(result);
            setDraft(draftFrom(result));
            setGenerated([]);
            setConflict(false);
        } catch (failure) {
            if (request.current !== controller || controller.signal.aborted)
                return;
            setLoadError((failure as Error).message);
        } finally {
            if (request.current === controller) request.current = null;
        }
    }, [actorId]);

    useEffect(() => {
        void load();
        return () => {
            request.current?.abort();
            request.current = null;
        };
    }, [load]);

    const changes = data && draft ? changesBetween(data, draft) : [];
    const dirty = changes.length > 0;
    useEffect(() => {
        onDirtyChange?.(dirty);
    }, [dirty, onDirtyChange]);
    useEffect(() => () => onDirtyChange?.(false), [onDirtyChange]);
    const lastRequest = useRef(reviewRequest);
    useEffect(() => {
        if (reviewRequest !== lastRequest.current && dirty) setReview(true);
        lastRequest.current = reviewRequest;
    }, [reviewRequest, dirty]);

    function editPlain(field: ChatCallingPlainField, text: string) {
        setDraft((current) =>
            current
                ? { ...current, plain: { ...current.plain, [field]: text } }
                : current,
        );
        setNotice('');
    }
    function editSecret(field: ChatCallingSecretField, next: SecretDraft) {
        setDraft((current) =>
            current
                ? {
                      ...current,
                      secrets: { ...current.secrets, [field]: next },
                  }
                : current,
        );
        if (next.mode !== 'replace')
            setGenerated((current) => current.filter((key) => key !== field));
        setNotice('');
    }

    async function save() {
        if (!data || !draft || !dirty || saving || conflict) return;
        const controller = new AbortController();
        request.current?.abort();
        request.current = controller;
        setSaving(true);
        setError('');
        const changed = new Set(changes.map((change) => change.field));
        try {
            const result = await chatActorRequest<ChatCallingSettingsData>(
                actorId,
                '/settings/chat/calling',
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        version: data.version,
                        password,
                        values: Object.fromEntries(
                            plainFields
                                .filter((field) => changed.has(field))
                                .map((field) => [
                                    field,
                                    fromText(field, draft.plain[field]),
                                ]),
                        ),
                        secrets: Object.fromEntries(
                            secretFields
                                .filter(
                                    (field) =>
                                        draft.secrets[field].mode === 'replace',
                                )
                                .map((field) => [
                                    field,
                                    draft.secrets[field].value.trim(),
                                ]),
                        ),
                        clear_secrets: secretFields.filter(
                            (field) => draft.secrets[field].mode === 'clear',
                        ),
                    }),
                    signal: AbortSignal.any([
                        controller.signal,
                        AbortSignal.timeout(15000),
                    ]),
                },
            );
            if (request.current !== controller) return;
            setData(result);
            setDraft(draftFrom(result));
            setGenerated([]);
            setPassword('');
            setReview(false);
            setNotice('Calling settings saved.');
        } catch (failure) {
            if (request.current !== controller || controller.signal.aborted)
                return;
            setError((failure as Error).message);
            if (failure instanceof ChatError && failure.status === 409)
                setConflict(true);
        } finally {
            if (request.current === controller) {
                request.current = null;
                setSaving(false);
            }
        }
    }

    const header = (
        <header className="space-y-2 border-b p-5">
            <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                Global Settings / Chat
            </p>
            <h1
                id="chat-calling-heading"
                ref={headingRef}
                tabIndex={-1}
                className="text-page-title"
            >
                Chat calling settings
            </h1>
            <p className="text-sm text-muted-foreground">
                Where Chat calls connect, for the whole organisation
            </p>
        </header>
    );

    if (!data || !draft)
        return (
            <section
                className="min-w-0 rounded-2xl border bg-background"
                aria-labelledby="chat-calling-heading"
            >
                {header}
                <div className="space-y-3 p-5">
                    {loadError ? (
                        <>
                            <p
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {loadError}
                            </p>
                            <Button
                                variant="outline"
                                className="min-h-[44px]"
                                onClick={() => void load()}
                            >
                                Reload calling settings
                            </Button>
                        </>
                    ) : (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Loading the calling settings…
                        </p>
                    )}
                </div>
            </section>
        );

    const plainRow = (field: ChatCallingPlainField) => {
        const id = 'chat-calling-' + field;
        const server = describeChatCallingValue(
            field,
            data.fields[field].server,
        );
        const text = draft.plain[field];
        const source =
            text.trim() !== ''
                ? 'Set on this page'
                : server
                  ? 'Uses the server’s setting: ' + server
                  : 'Not set';
        const control = listFields.includes(field) ? (
            <Textarea
                id={id}
                aria-describedby={id + '-hint ' + id + '-source'}
                rows={2}
                spellCheck={false}
                autoComplete="off"
                className="min-w-0 font-mono"
                value={text}
                disabled={saving}
                onChange={(event) => editPlain(field, event.target.value)}
            />
        ) : (
            <Input
                id={id}
                aria-describedby={id + '-hint ' + id + '-source'}
                type={field === 'relay_credential_seconds' ? 'number' : 'text'}
                min={field === 'relay_credential_seconds' ? 1 : undefined}
                max={field === 'relay_credential_seconds' ? 60 : undefined}
                spellCheck={false}
                autoComplete="off"
                className="min-h-[44px] min-w-0 font-mono"
                value={text}
                disabled={saving}
                onChange={(event) => editPlain(field, event.target.value)}
            />
        );
        return (
            <div
                key={field}
                className="grid items-start gap-3 border-b py-4 last:border-0 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]"
            >
                <div className="min-w-0">
                    <Label htmlFor={id}>
                        {chatCallingLabels[field]}
                        {field === 'relay_credential_seconds' && ' (minutes)'}
                    </Label>
                    <p
                        id={id + '-hint'}
                        className="mt-1 text-sm text-muted-foreground"
                    >
                        {hints[field]}
                    </p>
                    <p
                        id={id + '-source'}
                        className="mt-1 text-xs break-words text-muted-foreground"
                    >
                        {source}
                    </p>
                </div>
                {control}
            </div>
        );
    };

    const secretRow = (field: ChatCallingSecretField) => {
        const id = 'chat-calling-' + field;
        const state = data.fields[field];
        const secret = draft.secrets[field];
        const source =
            secret.mode === 'replace'
                ? 'Replaced when you save'
                : secret.mode === 'clear'
                  ? state.on_server
                      ? 'Uses the server’s key when you save'
                      : 'Not set when you save'
                  : state.on_page
                    ? 'Saved on this page'
                    : state.on_server
                      ? 'Uses the server’s key'
                      : 'Not set';
        return (
            <div
                key={field}
                className="grid items-start gap-3 border-b py-4 last:border-0 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]"
            >
                <div className="min-w-0">
                    {secret.mode === 'replace' ? (
                        <Label htmlFor={id}>{chatCallingLabels[field]}</Label>
                    ) : (
                        <p className="text-sm font-medium">
                            {chatCallingLabels[field]}
                        </p>
                    )}
                    <p
                        id={id + '-hint'}
                        className="mt-1 text-sm text-muted-foreground"
                    >
                        {hints[field]}
                    </p>
                    <p
                        id={id + '-source'}
                        className="mt-1 text-xs text-muted-foreground"
                    >
                        {source}
                    </p>
                </div>
                {secret.mode === 'replace' ? (
                    <div className="grid min-w-0 gap-2">
                        <Input
                            id={id}
                            aria-describedby={id + '-hint ' + id + '-source'}
                            spellCheck={false}
                            autoComplete="off"
                            placeholder="Paste a key or generate one"
                            className="min-h-[44px] min-w-0 font-mono"
                            value={secret.value}
                            disabled={saving}
                            onChange={(event) => {
                                editSecret(field, {
                                    mode: 'replace',
                                    value: event.target.value,
                                });
                                setGenerated((current) =>
                                    current.filter((key) => key !== field),
                                );
                            }}
                        />
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                className="min-h-[44px]"
                                disabled={saving}
                                onClick={() => {
                                    editSecret(field, {
                                        mode: 'replace',
                                        value: generateKey(),
                                    });
                                    setGenerated((current) => [
                                        ...current.filter(
                                            (key) => key !== field,
                                        ),
                                        field,
                                    ]);
                                    setCopied(null);
                                }}
                            >
                                Generate key
                            </Button>
                            <Button
                                variant="outline"
                                className="min-h-[44px]"
                                disabled={saving || secret.value.trim() === ''}
                                onClick={() =>
                                    void navigator.clipboard
                                        ?.writeText(secret.value.trim())
                                        .then(() => setCopied(field))
                                }
                            >
                                {copied === field ? 'Copied' : 'Copy'}
                            </Button>
                            <Button
                                variant="ghost"
                                className="min-h-[44px]"
                                disabled={saving}
                                onClick={() =>
                                    editSecret(field, {
                                        mode: 'keep',
                                        value: '',
                                    })
                                }
                            >
                                Cancel
                            </Button>
                        </div>
                        {generated.includes(field) && (
                            <p className="text-sm text-muted-foreground">
                                Copy this key to the server now. It won’t be
                                shown again after you save.
                            </p>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            className="min-h-[44px]"
                            disabled={saving}
                            aria-label={
                                (state.on_page || state.on_server
                                    ? 'Replace '
                                    : 'Set ') +
                                // "call server API secret": lower-case the first letter only.
                                chatCallingLabels[field]
                                    .charAt(0)
                                    .toLowerCase() +
                                chatCallingLabels[field].slice(1)
                            }
                            onClick={() =>
                                editSecret(field, {
                                    mode: 'replace',
                                    value: '',
                                })
                            }
                        >
                            {state.on_page || state.on_server
                                ? 'Replace key'
                                : 'Set key'}
                        </Button>
                        {state.on_page && secret.mode === 'keep' && (
                            <Button
                                variant="ghost"
                                className="min-h-[44px]"
                                disabled={saving}
                                onClick={() =>
                                    editSecret(field, {
                                        mode: 'clear',
                                        value: '',
                                    })
                                }
                            >
                                {state.on_server
                                    ? 'Use the server’s key'
                                    : 'Remove key'}
                            </Button>
                        )}
                        {secret.mode === 'clear' && (
                            <Button
                                variant="ghost"
                                className="min-h-[44px]"
                                disabled={saving}
                                onClick={() =>
                                    editSecret(field, {
                                        mode: 'keep',
                                        value: '',
                                    })
                                }
                            >
                                Keep the saved key
                            </Button>
                        )}
                    </div>
                )}
            </div>
        );
    };

    const group = (
        icon: ReactNode,
        title: string,
        caption: string,
        ready: { label: string; variant: 'success' | 'warning' | 'neutral' },
        rows: ReactNode,
    ) => (
        <section className="min-w-0 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-section-title flex items-center gap-2">
                        {icon}
                        {title}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {caption}
                    </p>
                </div>
                <StatusBadge variant={ready.variant}>{ready.label}</StatusBadge>
            </div>
            <div className="mt-2">{rows}</div>
        </section>
    );
    const setUp = (ready: boolean) =>
        ready
            ? { label: 'Set up', variant: 'success' as const }
            : { label: 'Not set up', variant: 'neutral' as const };

    return (
        <section
            className="min-w-0 rounded-2xl border bg-background"
            aria-labelledby="chat-calling-heading"
        >
            {header}
            <div className="min-w-0 space-y-5 p-5">
                {notice && (
                    <p
                        role="status"
                        className="flex items-center gap-2 rounded-lg bg-status-success-bg p-3 text-sm text-status-success"
                    >
                        <Check className="size-4" />
                        {notice}
                    </p>
                )}
                <p className="rounded-lg bg-muted/40 p-3 text-sm">
                    A value saved here replaces the server’s setting. Clear a
                    field to use the server’s setting again. Saved keys stay
                    hidden: you can replace them, but not read them back.
                </p>
                {group(
                    <RadioTower className="size-4" aria-hidden />,
                    'Call relay',
                    'Carries a call when two browsers can’t connect directly, for example on a hospital or guest network. The call stays encrypted.',
                    setUp(data.status.relay),
                    <>
                        {plainRow('relay_urls')}
                        {secretRow('relay_secret')}
                        {plainRow('relay_credential_seconds')}
                    </>,
                )}
                {group(
                    <KeyRound className="size-4" aria-hidden />,
                    'Relay control',
                    'Lets the app end someone’s relay use straight away, for example when they leave a call or their access ends.',
                    setUp(data.status.control),
                    <>
                        {plainRow('relay_control_urls')}
                        {secretRow('relay_control_secret')}
                        {plainRow('relay_control_key_id')}
                        {plainRow('relay_health_url')}
                    </>,
                )}
                {group(
                    <PhoneCall className="size-4" aria-hidden />,
                    'Voice-call server',
                    'Your own server that runs Chat voice calls.',
                    data.status.calling
                        ? data.status.calling_verified
                            ? { label: 'Set up', variant: 'success' }
                            : {
                                  label: 'Not marked as checked',
                                  variant: 'warning',
                              }
                        : setUp(false),
                    <>
                        {plainRow('calling_url')}
                        {plainRow('calling_api_url')}
                        {plainRow('calling_api_key')}
                        {secretRow('calling_api_secret')}
                        <div className="grid items-start gap-3 py-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                            <div className="min-w-0">
                                <p className="text-sm font-medium">
                                    Marked as checked
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Set on the server by whoever installed the
                                    call server, once they’ve checked it works.
                                    Calls can be turned on only after that.
                                </p>
                            </div>
                            <p className="text-sm">
                                {data.status.calling_verified ? 'Yes' : 'No'}
                            </p>
                        </div>
                    </>,
                )}
                {data.updated_at && (
                    <p className="text-caption">
                        Last saved by {data.updated_by ?? 'Former staff member'}{' '}
                        · {formatDateTime(data.updated_at)}
                    </p>
                )}
            </div>
            <footer className="sticky bottom-0 flex flex-wrap items-center justify-between gap-3 border-t bg-background p-4">
                <p className="text-sm text-muted-foreground">
                    {dirty
                        ? changes.length +
                          (changes.length === 1
                              ? ' unsaved change'
                              : ' unsaved changes')
                        : 'No unsaved changes'}
                </p>
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        className="min-h-[44px]"
                        disabled={!dirty || saving}
                        onClick={() => {
                            setDraft(draftFrom(data));
                            setGenerated([]);
                            setError('');
                            setNotice('');
                        }}
                    >
                        Discard
                    </Button>
                    <Button
                        ref={reviewButton}
                        className="min-h-[44px]"
                        disabled={!dirty || saving}
                        onClick={() => setReview(true)}
                    >
                        Review changes
                    </Button>
                </div>
            </footer>
            <Dialog
                open={review}
                onOpenChange={(open) => {
                    if (saving) return;
                    setReview(open);
                    if (!open) setPassword('');
                }}
            >
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    className="flex max-h-[85dvh] flex-col overflow-hidden"
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        (dirty
                            ? reviewButton.current
                            : headingRef?.current
                        )?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Review calling settings</DialogTitle>
                        <DialogDescription>
                            For the whole organisation: new calls use these
                            settings, and calls already running can finish
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="min-h-0 space-y-3 overflow-y-auto text-sm">
                        {changes.map((change) => (
                            <li
                                key={change.field}
                                className="rounded-lg bg-muted/40 p-3"
                            >
                                <strong>{change.label}</strong>
                                <p className="mt-1 break-words text-muted-foreground">
                                    Before: {change.before}
                                </p>
                                <p className="mt-1 break-words">
                                    After: {change.after}
                                </p>
                            </li>
                        ))}
                    </ul>
                    <form
                        className="grid gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <Label htmlFor="chat-calling-password">
                            Your password
                        </Label>
                        <Input
                            id="chat-calling-password"
                            type="password"
                            autoComplete="current-password"
                            className="min-h-[44px]"
                            aria-describedby="chat-calling-password-hint"
                            value={password}
                            disabled={saving}
                            onChange={(event) =>
                                setPassword(event.target.value)
                            }
                        />
                        <p
                            id="chat-calling-password-hint"
                            className="text-sm text-muted-foreground"
                        >
                            The password you sign in with. It’s asked for each
                            time calling settings are saved.
                        </p>
                        {error && (
                            <p
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {error}
                            </p>
                        )}
                        {conflict && (
                            <Button
                                type="button"
                                variant="outline"
                                className="h-auto min-h-[44px] whitespace-normal"
                                disabled={saving}
                                onClick={() => {
                                    setReview(false);
                                    setPassword('');
                                    setError('');
                                    void load();
                                }}
                            >
                                Reload calling settings
                            </Button>
                        )}
                        <DialogFooter className="mt-2">
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-[44px]"
                                disabled={saving}
                                onClick={() => {
                                    setReview(false);
                                    setPassword('');
                                }}
                            >
                                Keep editing
                            </Button>
                            <Button
                                type="submit"
                                className="min-h-[44px]"
                                disabled={
                                    saving ||
                                    conflict ||
                                    !dirty ||
                                    password === ''
                                }
                            >
                                {saving ? 'Saving…' : 'Save reviewed changes'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
