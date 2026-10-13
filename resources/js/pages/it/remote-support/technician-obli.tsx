import { ObliMark } from '@/components/obli/obli-mark';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDateTime } from '@/lib/datetime';
import { Pause, Play, RotateCcw, Send, ShieldCheck } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import {
    chatResult,
    diagnosticGroups,
    diagnosticPreview,
    obliOptions,
    TechnicianObliError,
    technicianObliJSON,
    type ChatCommand,
    type ChatResult,
    type DiagnosticGroup,
    type DiagnosticPreview,
    type ObliOptions,
    type ObliScope,
    type TechnicianMessage,
} from './obli-transport';

const labels: Record<string, string> = {
    system: 'System health',
    software: 'Software versions',
    services: 'Windows services',
    windows_version: 'Windows version',
    architecture: 'Architecture',
    memory_total_bytes: 'Memory',
    memory_available_bytes: 'Available memory',
    uptime_seconds: 'Uptime',
    cpu_percent: 'CPU use',
    disks: 'Fixed drives',
    microsoft_edge: 'Microsoft Edge',
    google_chrome: 'Google Chrome',
    microsoft_teams: 'Microsoft Teams',
    teams_installer: 'Teams installer',
    print_spooler: 'Print spooler',
    windows_update: 'Windows Update',
    background_transfer: 'Background transfers',
    windows_defender: 'Windows Defender',
    windows_firewall: 'Windows Firewall',
};
const bytes = (value: number | null) =>
    value === null
        ? 'Not reported'
        : (value / 1073741824).toLocaleString(undefined, {
              maximumFractionDigits: 1,
          }) + ' GB';
const encoder = new TextEncoder();

export function TechnicianObli(props: {
    scope: ObliScope;
    computerName: string;
    available: boolean;
}) {
    return (
        <ObliWorkspace
            key={[
                props.scope.actorId,
                props.scope.deviceId,
                props.scope.ticketId,
                props.available,
            ].join(':')}
            {...props}
        />
    );
}

function ObliWorkspace({
    scope,
    computerName,
    available,
}: {
    scope: ObliScope;
    computerName: string;
    available: boolean;
}) {
    const id = useId();
    const [options, setOptions] = useState<ObliOptions | null>(null);
    const [preview, setPreview] = useState<DiagnosticPreview | null>(null);
    const [selected, setSelected] = useState<DiagnosticGroup[]>([]);
    const [messages, setMessages] = useState<TechnicianMessage[]>([]);
    const [question, setQuestion] = useState('');
    const [consent, setConsent] = useState(false);
    const [pending, setPending] = useState<{
        uuid: string;
        command: ChatCommand | null;
    } | null>(null);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [recoveredOnly, setRecoveredOnly] = useState(false);
    const [motion, setMotion] = useState(true);
    const [now, setNow] = useState(Date.now());
    const request = useRef<AbortController | null>(null);
    const generation = useRef({ serial: 0 });
    const inFlight = useRef(false);
    const leave = useSettingsLeaveConfirmation(
        Boolean(question || pending || messages.length),
        'Leave this Obli conversation?',
        'This conversation is held in this browser tab. Leaving clears it here. A question already sent may still finish and use the organisation’s AI budget.',
        () => {
            generation.current.serial++;
            request.current?.abort();
        },
    );
    useEffect(() => {
        const lifetime = generation.current;
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => {
            clearInterval(timer);
            lifetime.serial++;
            request.current?.abort();
        };
    }, []);

    function clearPrivate() {
        setMessages([]);
        setQuestion('');
        setPreview(null);
        setSelected([]);
        setConsent(false);
        setOptions(null);
        setPending((current) =>
            current ? { uuid: current.uuid, command: null } : null,
        );
    }
    async function run(
        task: (signal: AbortSignal, current: () => boolean) => Promise<void>,
    ) {
        if (inFlight.current) return;
        inFlight.current = true;
        setBusy(true);
        setNotice('');
        const serial = ++generation.current.serial,
            controller = new AbortController();
        request.current = controller;
        const current = () => generation.current.serial === serial;
        try {
            await task(controller.signal, current);
        } catch (error) {
            if (!current()) return;
            if (
                error instanceof TechnicianObliError &&
                (error.status === 401 ||
                    error.status === 403 ||
                    error.status === 419 ||
                    error.code === 'source_unavailable')
            ) {
                clearPrivate();
                setNotice(
                    'Your access to this support context could not be confirmed. Private content has been cleared. Reopen Obli after your access is restored.',
                );
            } else
                setNotice(
                    'The result could not be confirmed. If a question was sent, use Check reply before retrying; it may still finish and use the AI budget.',
                );
        } finally {
            if (current()) {
                inFlight.current = false;
                setBusy(false);
                request.current = null;
            }
        }
    }
    function open() {
        void run(async (signal, current) => {
            const result = obliOptions(
                await technicianObliJSON(scope, 'options', signal),
                scope,
            );
            if (current()) {
                setOptions(result);
                setConsent(false);
                setPreview(null);
                setSelected([]);
            }
        });
    }
    function loadPreview() {
        if (!options?.can_preview || !options.diagnostic_categories.length)
            return;
        void run(async (signal, current) => {
            const result = diagnosticPreview(
                await technicianObliJSON(
                    scope,
                    'diagnostics',
                    signal,
                    undefined,
                    options.diagnostic_categories,
                ),
                scope,
            );
            if (current()) {
                setPreview(result);
                setSelected([]);
                setConsent(false);
                setNow(Date.now());
            }
        });
    }
    function finish(result: ChatResult, command: ChatCommand | null) {
        if (result.status !== 'completed') {
            setNotice(
                result.status === 'pending'
                    ? 'Obli is still preparing a reply. Check again shortly.'
                    : 'The provider outcome is unconfirmed. This request will not be sent to the provider again. Contact an administrator before starting another question.',
            );
            return;
        }
        if (command) {
            const historical = command.messages.map((message) =>
                message.computer_diagnostics &&
                !message.computer_diagnostics.source_request_uuid
                    ? {
                          ...message,
                          computer_diagnostics: {
                              ...message.computer_diagnostics,
                              source_request_uuid: result.request_uuid,
                          },
                      }
                    : message,
            );
            setMessages([
                ...historical,
                { role: 'assistant', content: result.text! },
            ]);
        } else {
            setMessages([{ role: 'assistant', content: result.text! }]);
            setRecoveredOnly(true);
        }
        setPending(null);
        setQuestion('');
        setConsent(false);
        setSelected([]);
        setPreview(null);
        setNotice(
            command
                ? 'Reply received. Review any suggested repair before taking action.'
                : 'Reply recovered. Its earlier conversation was cleared; start a new conversation for another question.',
        );
    }
    function submit(command: ChatCommand) {
        if (inFlight.current) return;
        setPending({ uuid: command.request_uuid, command });
        void run(async (signal, current) => {
            try {
                const result = chatResult(
                    await technicianObliJSON(scope, 'chat', signal, command),
                    command.request_uuid,
                );
                if (current()) finish(result, command);
            } catch (error) {
                if (
                    current() &&
                    error instanceof TechnicianObliError &&
                    [
                        'source_changed',
                        'invalid_request',
                        'budget_exceeded',
                    ].includes(error.code)
                ) {
                    setPending(null);
                    setPreview(null);
                    setSelected([]);
                    setConsent(false);
                    setNotice(
                        error.code === 'source_changed'
                            ? 'The computer checks changed before this question was sent. Preview them again and review your selection.'
                            : error.code === 'budget_exceeded'
                              ? 'The organisation’s AI spending limit prevented this question from being sent. Ask an administrator to review the limit.'
                              : 'This question could not be sent. Shorten it, include fewer checks or start a new conversation, then review it again.',
                    );
                    return;
                }
                throw error;
            }
        });
    }
    const stale = preview !== null && Date.parse(preview.fresh_until) <= now;
    const previousBytes = messages.reduce(
        (total, message) => total + encoder.encode(message.content).length,
        0,
    );
    const tooLong =
        previousBytes + encoder.encode(question).length > 12000 ||
        messages.length >= 15;
    function send() {
        if (
            recoveredOnly ||
            busy ||
            pending ||
            !options?.enabled ||
            !options.can_assist ||
            !consent ||
            !question.trim() ||
            tooLong ||
            (selected.length > 0 && (!preview || stale))
        )
            return;
        const message: TechnicianMessage = {
            role: 'user',
            content: question.trim(),
        };
        if (selected.length && preview)
            message.computer_diagnostics = {
                snapshot_version: preview.snapshot_version,
                groups: selected,
            };
        submit({
            request_uuid: crypto.randomUUID(),
            expected_actor_id: scope.actorId,
            configuration_version: options.version,
            ticket_id: scope.ticketId,
            messages: [...messages, message],
        });
    }
    function checkReply() {
        if (!pending) return;
        const receipt = pending;
        void run(async (signal, current) => {
            const result = chatResult(
                await technicianObliJSON(scope, `chat/${receipt.uuid}`, signal),
                receipt.uuid,
            );
            if (current()) finish(result, receipt.command);
        });
    }
    function reset() {
        leave.request(() => {
            generation.current.serial++;
            request.current?.abort();
            inFlight.current = false;
            setBusy(false);
            setRecoveredOnly(false);
            setPending(null);
            setMessages([]);
            setQuestion('');
            setConsent(false);
            setPreview(null);
            setSelected([]);
            setNotice('');
        });
    }

    return (
        <section
            className="min-w-0 rounded-xl border bg-card"
            aria-labelledby={id + '-title'}
        >
            <div className="flex flex-wrap items-center justify-between gap-3 border-b p-5">
                <div className="flex items-center gap-2">
                    <ObliMark active={busy && motion} />
                    <div>
                        <h3 id={id + '-title'} className="text-section-title">
                            Obli
                        </h3>
                        <p className="text-caption text-muted-foreground">
                            Investigate {computerName}
                        </p>
                    </div>
                </div>
                {options && (
                    <div className="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={
                                motion
                                    ? 'Pause Obli animation'
                                    : 'Enable Obli animation'
                            }
                            onClick={() => setMotion(!motion)}
                        >
                            {motion ? (
                                <Pause aria-hidden />
                            ) : (
                                <Play aria-hidden />
                            )}
                        </Button>
                        <Button variant="ghost" disabled={busy} onClick={reset}>
                            <RotateCcw aria-hidden />
                            New conversation
                        </Button>
                    </div>
                )}
            </div>
            <div className="space-y-5 p-5">
                <p className="text-subtle flex items-start gap-2">
                    <ShieldCheck className="h-4 w-4 shrink-0" aria-hidden />
                    Obli reads the checks you choose and suggests next steps.
                    Suggestions do not run commands or start remote control.
                </p>
                {notice && (
                    <Alert>
                        <AlertDescription role="status">
                            {notice}
                        </AlertDescription>
                    </Alert>
                )}
                {!options ? (
                    <div className="space-y-2">
                        <Button
                            variant="outline"
                            disabled={!available || busy}
                            onClick={open}
                        >
                            {busy ? 'Opening Obli…' : 'Open Obli'}
                        </Button>
                        {!available && (
                            <p className="text-caption text-muted-foreground">
                                Technician assistance is not enabled for this
                                workspace.
                            </p>
                        )}
                    </div>
                ) : (
                    <>
                        <p className="text-caption text-muted-foreground">
                            {options.provider === 'openai'
                                ? 'OpenAI'
                                : 'Anthropic (Claude)'}{' '}
                            · {options.model || 'No model configured'} · Replies
                            retained for {options.chat_retention_days} days
                            under the organisation’s policy.
                        </p>
                        {options.can_preview && (
                            <div className="space-y-3 border-y py-4">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h4 className="font-medium">
                                            Computer checks
                                        </h4>
                                        <p className="text-caption text-muted-foreground">
                                            Preview the latest approved agent
                                            observations.
                                        </p>
                                    </div>
                                    <Button
                                        variant="outline"
                                        disabled={
                                            busy ||
                                            !!pending ||
                                            !options.diagnostic_categories
                                                .length
                                        }
                                        onClick={loadPreview}
                                    >
                                        {preview
                                            ? 'Refresh checks'
                                            : 'Preview computer checks'}
                                    </Button>
                                </div>
                                {!options.diagnostic_categories.length && (
                                    <p className="text-subtle">
                                        No diagnostic categories are enabled in
                                        Obli settings.
                                    </p>
                                )}
                                {preview && (
                                    <>
                                        <p className="text-caption text-muted-foreground">
                                            Observed{' '}
                                            {formatDateTime(
                                                preview.observed_at,
                                            )}{' '}
                                            · Received{' '}
                                            {formatDateTime(
                                                preview.received_at,
                                            )}
                                        </p>
                                        {stale && (
                                            <p
                                                role="status"
                                                className="text-subtle"
                                            >
                                                These checks have expired.
                                                Refresh before attaching them to
                                                a new question.
                                            </p>
                                        )}
                                        {preview.groups.map((group) => (
                                            <div
                                                key={group}
                                                className="space-y-2"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <Checkbox
                                                        id={id + '-' + group}
                                                        checked={selected.includes(
                                                            group,
                                                        )}
                                                        disabled={
                                                            busy ||
                                                            !!pending ||
                                                            stale ||
                                                            !options.can_assist
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) => {
                                                            setSelected(
                                                                (current) =>
                                                                    diagnosticGroups.filter(
                                                                        (
                                                                            item,
                                                                        ) =>
                                                                            item ===
                                                                            group
                                                                                ? checked ===
                                                                                  true
                                                                                : current.includes(
                                                                                      item,
                                                                                  ),
                                                                    ),
                                                            );
                                                            setConsent(false);
                                                        }}
                                                    />
                                                    <Label
                                                        htmlFor={
                                                            id + '-' + group
                                                        }
                                                    >
                                                        Include{' '}
                                                        {labels[
                                                            group
                                                        ].toLowerCase()}
                                                    </Label>
                                                </div>
                                                <details className="rounded-lg border px-3 py-2">
                                                    <summary className="cursor-pointer text-sm focus-visible:ring-2 focus-visible:ring-ring">
                                                        Review{' '}
                                                        {labels[
                                                            group
                                                        ].toLowerCase()}
                                                        {!preview.completeness[
                                                            group
                                                        ]?.complete
                                                            ? ' · Some checks unavailable'
                                                            : ''}
                                                    </summary>
                                                    <DiagnosticDetails
                                                        preview={preview}
                                                        group={group}
                                                    />
                                                </details>
                                            </div>
                                        ))}
                                        <p className="text-caption text-muted-foreground">
                                            Only checked groups are shared.
                                            Missing observations do not prove
                                            that software is absent or a service
                                            has stopped.
                                        </p>
                                    </>
                                )}
                            </div>
                        )}
                        {!!messages.length && (
                            <ol
                                aria-label="Obli conversation"
                                className="space-y-5"
                            >
                                {messages.map((message, index) => (
                                    <li key={index} className="space-y-1">
                                        <p className="text-caption font-medium">
                                            {message.role === 'user'
                                                ? 'You'
                                                : 'Obli'}
                                        </p>
                                        <p className="text-sm break-words whitespace-pre-wrap">
                                            {message.content}
                                        </p>
                                        {message.computer_diagnostics && (
                                            <p className="text-caption text-muted-foreground">
                                                Shared checks:{' '}
                                                {message.computer_diagnostics.groups
                                                    .map(
                                                        (group) =>
                                                            labels[group],
                                                    )
                                                    .join(', ')}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        )}
                        {options.enabled &&
                        options.can_assist &&
                        !recoveredOnly ? (
                            <div className="space-y-3">
                                <Label htmlFor={id + '-question'}>
                                    Ask about this computer
                                </Label>
                                <Textarea
                                    id={id + '-question'}
                                    value={question}
                                    onChange={(event) => {
                                        setQuestion(event.target.value);
                                        setConsent(false);
                                    }}
                                    placeholder="Describe the problem and what you have tried…"
                                    rows={4}
                                    maxLength={8000}
                                    disabled={busy || !!pending}
                                />
                                <p className="text-caption text-muted-foreground">
                                    Keep passwords and personal information out
                                    of your question. No ticket text, staff
                                    chats, files or screenshots are attached
                                    automatically.
                                </p>
                                {!!messages.length && (
                                    <p className="text-caption text-muted-foreground">
                                        This question also includes the
                                        conversation above and any previously
                                        shared checks.
                                    </p>
                                )}
                                {tooLong && (
                                    <p role="status" className="text-subtle">
                                        This conversation has reached its size
                                        limit. Shorten your question or start a
                                        new conversation.
                                    </p>
                                )}
                                {!pending && (
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id={id + '-consent'}
                                            checked={consent}
                                            disabled={busy}
                                            onCheckedChange={(value) =>
                                                setConsent(value === true)
                                            }
                                        />
                                        <Label
                                            htmlFor={id + '-consent'}
                                            className="text-sm leading-relaxed"
                                        >
                                            Send this question, the conversation
                                            above and{' '}
                                            {selected.length
                                                ? selected
                                                      .map((group) =>
                                                          labels[
                                                              group
                                                          ].toLowerCase(),
                                                      )
                                                      .join(', ')
                                                : 'no new computer checks'}{' '}
                                            to{' '}
                                            {options.provider === 'openai'
                                                ? 'OpenAI'
                                                : 'Anthropic'}
                                            . The organisation’s AI budget
                                            applies.
                                        </Label>
                                    </div>
                                )}
                                {!pending && (
                                    <Button
                                        disabled={
                                            busy ||
                                            !consent ||
                                            !question.trim() ||
                                            tooLong ||
                                            (selected.length > 0 && stale)
                                        }
                                        onClick={send}
                                    >
                                        <Send aria-hidden />
                                        Ask Obli
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <p className="text-subtle">
                                {recoveredOnly
                                    ? 'Start a new conversation to ask another question.'
                                    : 'AI chat is unavailable under the current settings or your permissions. Approved computer checks remain available where permitted.'}
                            </p>
                        )}
                    </>
                )}
                {pending && (
                    <div className="space-y-3 rounded-lg border bg-muted p-4">
                        <p role="status" className="text-sm">
                            {busy
                                ? 'Waiting for Obli’s reply…'
                                : 'This question has an unresolved reply. Check its result before starting another.'}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {busy ? (
                                <Button
                                    variant="outline"
                                    onClick={() => request.current?.abort()}
                                >
                                    Stop waiting
                                </Button>
                            ) : (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={checkReply}
                                    >
                                        Check reply
                                    </Button>
                                    {pending.command && (
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                submit(pending.command!)
                                            }
                                        >
                                            Retry same request
                                        </Button>
                                    )}
                                </>
                            )}
                        </div>
                        <p className="text-caption text-muted-foreground">
                            Checking retrieves the existing request. A retry
                            uses the same request reference; it does not create
                            another question.
                        </p>
                    </div>
                )}
            </div>
            {leave.confirmation}
        </section>
    );
}

function DiagnosticDetails({
    preview,
    group,
}: {
    preview: DiagnosticPreview;
    group: DiagnosticGroup;
}) {
    const system = preview.snapshot.system;
    return (
        <div className="mt-3 space-y-2 text-sm">
            {group === 'system' && system ? (
                <dl className="space-y-1">
                    {[
                        ['Windows', system.windows_version],
                        ['Architecture', system.architecture],
                        ['Memory', bytes(system.memory_total_bytes)],
                        [
                            'Available memory',
                            bytes(system.memory_available_bytes),
                        ],
                        [
                            'Uptime',
                            system.uptime_seconds === null
                                ? 'Not reported'
                                : (system.uptime_seconds / 3600).toLocaleString(
                                      undefined,
                                      { maximumFractionDigits: 1 },
                                  ) + ' hours',
                        ],
                        [
                            'CPU use',
                            system.cpu_percent === null
                                ? 'Not reported'
                                : system.cpu_percent + '%',
                        ],
                        ...system.disks.map((disk) => [
                            disk.name,
                            bytes(disk.free_bytes) +
                                ' free of ' +
                                bytes(disk.total_bytes),
                        ]),
                    ].map(([name, value]) => (
                        <div
                            key={name}
                            className="flex flex-wrap justify-between gap-x-4"
                        >
                            <dt className="text-muted-foreground">{name}</dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
                </dl>
            ) : group === 'software' ? (
                <ul className="space-y-1">
                    {preview.snapshot.software?.map((row) => (
                        <li
                            key={row.name}
                            className="flex flex-wrap justify-between gap-x-4"
                        >
                            <span>{labels[row.name]}</span>
                            <span>{row.version}</span>
                        </li>
                    ))}
                </ul>
            ) : (
                <ul className="space-y-1">
                    {preview.snapshot.services?.map((row) => (
                        <li
                            key={row.name}
                            className="flex flex-wrap justify-between gap-x-4"
                        >
                            <span>{labels[row.name]}</span>
                            <span>{row.state}</span>
                        </li>
                    ))}
                </ul>
            )}
            {group !== 'system' && !preview.snapshot[group]?.length && (
                <p>No approved entries were reported.</p>
            )}
            {!!preview.completeness[group]?.missing_fields?.length && (
                <p className="text-caption text-muted-foreground">
                    Not reported:{' '}
                    {preview.completeness[group]?.missing_fields
                        ?.map((field) => labels[field])
                        .join(', ')}
                    .
                </p>
            )}
        </div>
    );
}
