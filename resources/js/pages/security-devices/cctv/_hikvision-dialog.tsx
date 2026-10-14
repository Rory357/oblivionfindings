import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDateTime } from '@/lib/datetime';
import {
    Camera,
    Check,
    ChevronsUpDown,
    Network,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    hikActionReply,
    hikBase,
    hikDiscoveryReply,
    hikMappingReply,
    hikProfile,
    hikReviewReply,
    type HikDiscovery,
    type HikOption,
    type HikReview,
    type HikSetup,
} from './_hikvision-contract';
import { HikvisionPicker } from './_hikvision-picker';
import { sourceAddress, sourceText } from './_source-contract';
import { cctvTime } from './live-contract';
import { hikvisionJson } from './live-transport';

type Mode = 'configure' | 'enable' | 'disable';
type Pending = {
    kind: 'discovery' | 'mapping' | 'action';
    path: string;
    body: Record<string, unknown>;
};
const channelReasons: Record<string, string> = {
    model_unsupported:
        'This model and firmware still need an approved equipment test.',
    input_unsupported: 'This camera input has not been approved for viewing.',
    channel_disabled: 'This channel is turned off in the camera.',
    codec_unsupported: 'This channel needs a supported H.264 stream.',
    identity_unmatched:
        'The observed camera does not match the registered camera.',
    observation_expired: 'The camera check has expired. Run a new check.',
    context_changed: 'The connection or your access changed. Refresh setup.',
    source_unavailable: 'This camera could not be checked.',
};
export function HikvisionSetupDialog({
    setup,
    mode,
    onClose,
    onCommitted,
}: {
    setup: HikSetup;
    mode: Mode;
    onClose: () => void;
    onCommitted: () => void;
}) {
    const configuring = mode === 'configure';
    const target = mode === 'disable' ? setup.active_source : setup.source;
    const [collector, setCollector] = useState<HikOption | null>(null),
        [scope, setScope] = useState<HikOption | null>(null),
        [credential, setCredential] = useState<HikOption | null>(null);
    const [port, setPort] = useState('443'),
        [tlsName, setTlsName] = useState(''),
        [pin, setPin] = useState(''),
        [purpose, setPurpose] = useState('');
    const [step, setStep] = useState(0),
        [busy, setBusy] = useState(false),
        [uncertain, setUncertain] = useState(false),
        [notice, setNotice] = useState(''),
        [discovery, setDiscovery] = useState<HikDiscovery | null>(null),
        [review, setReview] = useState<HikReview | null>(null),
        [channel, setChannel] = useState<string | null>(null),
        [channelOpen, setChannelOpen] = useState(false),
        [channelQuery, setChannelQuery] = useState(''),
        [channelLimit, setChannelLimit] = useState(20),
        [saved, setSaved] = useState<string | null>(null),
        [now, setNow] = useState(Date.now());
    const call = useRef<AbortController | null>(null),
        pending = useRef<Pending | null>(null),
        discoveryRequest = useRef<Pending | null>(null),
        pollUntil = useRef(0);
    useEffect(() => () => call.current?.abort(), []);
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, []);
    const frozen = !!discoveryRequest.current;
    const locked =
        busy ||
        uncertain ||
        !!saved ||
        pending.current?.kind === 'mapping' ||
        pending.current?.kind === 'action';
    const selected = review?.channels.find((c) => c.id === channel) ?? null;
    const fresh =
        !!review &&
        cctvTime(review.observed_at) <= now &&
        cctvTime(review.observed_at) + 300000 > now;
    const selectable =
        !!selected?.can_select &&
        !!review?.current_context &&
        !!review?.mapping_enabled &&
        fresh;
    const leave = useSettingsLeaveConfirmation(
        !saved && (!!collector || !!purpose || !!pending.current),
        'Close camera setup?',
        'Unsaved entries will be discarded. A camera check or change already sent may still finish. Refresh setup before making another change.',
    );
    const steps = configuring
        ? [
              {
                  key: 'equipment',
                  label: 'Equipment',
                  blurb: 'Collector, network and access',
                  icon: Network,
              },
              {
                  key: 'connection',
                  label: 'Connection',
                  blurb: 'Camera certificate and reason',
                  icon: ShieldCheck,
              },
              {
                  key: 'channels',
                  label: 'Channels',
                  blurb: 'Review the camera check',
                  icon: Camera,
              },
              {
                  key: 'review',
                  label: 'Review',
                  blurb: 'Save the selected connection',
                  icon: Check,
              },
          ]
        : [
              {
                  key: 'effect',
                  label: 'Review change',
                  blurb: 'Camera and effect',
                  icon: Camera,
              },
              {
                  key: 'confirm',
                  label: 'Confirm',
                  blurb: 'Record your reason',
                  icon: Check,
              },
          ];
    const effect =
        mode === 'disable'
            ? 'This stops current viewing sessions using this connection and turns off viewing through it in Oblivion. Camera recordings are unchanged.'
            : mode === 'enable'
              ? 'This allows viewing attempts through the selected connection. Each attempt still checks access, credentials and the camera. Enabling does not verify video.'
              : 'A camera check only reads the approved camera identity and channels. Saving creates a separate connection with viewing off. The existing connection stays as it is until you turn it off.';
    function equipmentValid() {
        return (
            !!collector &&
            !!scope &&
            scope.collector_id === collector.id &&
            !!credential
        );
    }
    function connectionValid() {
        return (
            sourceText(purpose.trim(), 10, 500) &&
            !!setup.camera.ip_address &&
            sourceAddress(setup.camera.ip_address) &&
            /^[1-9]\d{0,4}$/.test(port) &&
            Number(port) <= 65535 &&
            tlsName.length <= 253 &&
            tlsName
                .split('.')
                .every((l) =>
                    /^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/.test(l),
                ) &&
            /^[a-f0-9]{64}$/.test(pin)
        );
    }
    async function acceptDiscovery(
        next: HikDiscovery,
        controller: AbortController,
    ) {
        if (controller.signal.aborted) return;
        setDiscovery(next);
        setUncertain(false);
        if (!next.current_context || !next.execution_enabled) {
            setNotice(
                'The connection or your access changed. Close setup and refresh before running another check.',
            );
            return;
        }
        if (next.status === 'observed') {
            const result = hikReviewReply(
                await hikvisionJson(
                    setup.camera_id,
                    setup.actor_id,
                    hikBase(setup.camera_id) +
                        '/source/discoveries/' +
                        next.request_uuid,
                    controller.signal,
                ),
                setup,
                next.request_uuid,
            );
            if (!controller.signal.aborted) {
                setReview(result);
                setStep(2);
                setNotice('');
                pending.current = null;
            }
        } else if (['failed', 'expired', 'revoked'].includes(next.status)) {
            setNotice(
                'The camera check did not finish successfully. Review the connection and run a new check.',
            );
            pending.current = null;
        } else {
            setStep(2);
            setNotice(
                'The Site collector is checking the camera. You can close setup safely; no connection is saved automatically.',
            );
        }
    }
    async function checkStatus() {
        if (!discovery || busy || saved) return;
        if (Date.now() > pollUntil.current) {
            setUncertain(true);
            setNotice(
                'The camera check has not been confirmed. Check this same request before making another change.',
            );
            return;
        }
        call.current?.abort();
        const controller = new AbortController();
        call.current = controller;
        setBusy(true);
        try {
            await acceptDiscovery(
                hikDiscoveryReply(
                    await hikvisionJson(
                        setup.camera_id,
                        setup.actor_id,
                        discovery.reader_href,
                        controller.signal,
                    ),
                    setup,
                    discovery.request_uuid,
                ),
                controller,
            );
        } catch {
            if (!controller.signal.aborted) {
                setUncertain(true);
                setNotice(
                    'The camera check could not be confirmed. Check this same request; your entries have been kept.',
                );
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    useEffect(() => {
        if (
            !discovery ||
            !discovery.current_context ||
            !discovery.execution_enabled ||
            !['queued', 'preparing', 'dispatched'].includes(discovery.status) ||
            busy ||
            uncertain ||
            saved
        )
            return;
        const timer = setTimeout(() => void checkStatus(), 1000);
        return () => clearTimeout(timer);
        // The current discovery and busy state own one bounded poll at a time.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [discovery, busy, uncertain, saved]);
    async function send(kind: Pending['kind']) {
        if (busy || saved) return;
        if (!pending.current) {
            if (kind === 'discovery') {
                if (!equipmentValid() || !connectionValid()) {
                    setNotice(
                        'Choose the approved equipment and enter the camera certificate details and reason.',
                    );
                    setStep(!equipmentValid() ? 0 : 1);
                    return;
                }
                pending.current = {
                    kind,
                    path:
                        '/security-devices/devices/' +
                        setup.camera_id +
                        '/hikvision-discoveries',
                    body: {
                        expected_actor_id: setup.actor_id,
                        request_key: crypto.randomUUID(),
                        expected_source_revision: setup.revision,
                        collector_id: collector!.id,
                        discovery_scope_id: scope!.id,
                        credential_reference_id: credential!.id,
                        allowed_ip: setup.camera.ip_address,
                        allowed_port: Number(port),
                        tls_server_name: tlsName,
                        tls_pin: pin,
                        purpose: purpose.trim(),
                    },
                };
                discoveryRequest.current = pending.current;
                pollUntil.current = Date.now() + 300000;
            } else if (kind === 'mapping') {
                if (!selectable || !review || !selected) {
                    setNotice(
                        'Choose an approved channel from a fresh camera check.',
                    );
                    setStep(2);
                    return;
                }
                pending.current = {
                    kind,
                    path: hikBase(setup.camera_id) + '/source',
                    body: {
                        expected_actor_id: setup.actor_id,
                        request_key: crypto.randomUUID(),
                        expected_revision: setup.revision,
                        discovery_uuid: review.discovery_uuid,
                        expected_result_sha256: review.result_sha256,
                        channel_id: selected.id,
                        transport_profile: hikProfile,
                        purpose: purpose.trim(),
                    },
                };
            } else {
                if (!target || !sourceText(purpose.trim(), 10, 500)) {
                    setNotice('Enter a reason between 10 and 500 characters.');
                    setStep(1);
                    return;
                }
                pending.current = {
                    kind,
                    path: hikBase(setup.camera_id) + '/source/' + mode,
                    body: {
                        expected_actor_id: setup.actor_id,
                        expected_revision: target.revision,
                        request_key: crypto.randomUUID(),
                        purpose: purpose.trim(),
                    },
                };
            }
        }
        const request = pending.current;
        call.current?.abort();
        const controller = new AbortController();
        call.current = controller;
        setBusy(true);
        setNotice('');
        try {
            const raw = await hikvisionJson(
                setup.camera_id,
                setup.actor_id,
                request.path,
                controller.signal,
                request.body,
            );
            if (request.kind === 'discovery') {
                await acceptDiscovery(
                    hikDiscoveryReply(raw, setup, discovery?.request_uuid),
                    controller,
                );
            } else if (request.kind === 'mapping') {
                hikMappingReply(raw, setup, review!, selected!);
                if (!controller.signal.aborted) {
                    setUncertain(false);
                    setSaved(
                        'Connection saved. Refresh setup before turning on viewing.',
                    );
                }
            } else {
                const result = hikActionReply(raw, setup, target!);
                if (!controller.signal.aborted) {
                    setUncertain(false);
                    setSaved(
                        result.enabled
                            ? 'Viewing is enabled. Video still needs a successful viewing attempt.'
                            : 'Viewing is off for this connection.',
                    );
                }
            }
        } catch {
            if (!controller.signal.aborted) {
                setUncertain(true);
                setNotice(
                    'The outcome could not be confirmed. Check this same request before making another change. Your reviewed details stay locked.',
                );
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    function resetCheck() {
        if (busy || uncertain || locked) return;
        pending.current = null;
        discoveryRequest.current = null;
        setDiscovery(null);
        setReview(null);
        setChannel(null);
        setStep(0);
        setNotice('');
    }
    const matching =
        review?.channels.filter((c) =>
            (c.name + ' ' + c.id + ' ' + c.codec)
                .toLowerCase()
                .includes(channelQuery.toLowerCase()),
        ) ?? [];
    function field(
        label: string,
        value: string,
        change: (s: string) => void,
        max: number,
        help: string,
        numeric = false,
    ) {
        const fieldId = 'hik-' + label.toLowerCase().replaceAll(' ', '-');
        return (
            <div className="space-y-2">
                <Label htmlFor={fieldId}>{label}</Label>
                <Input
                    id={fieldId}
                    value={value}
                    disabled={locked || frozen}
                    onChange={(e) => change(e.target.value)}
                    maxLength={max}
                    autoComplete="off"
                    spellCheck={false}
                    inputMode={numeric ? 'numeric' : undefined}
                />
                <p className="text-caption text-muted-foreground">{help}</p>
            </div>
        );
    }
    const summary = (
        <ReviewCard
            icon={Camera}
            title={setup.camera.name}
            onEdit={!locked ? () => setStep(0) : undefined}
        >
            <ReviewRow label="Site" value={setup.camera.site.name} />
            <ReviewRow
                label="Site collector"
                value={collector?.name ?? 'Choose a collector'}
            />
            <ReviewRow
                label="Approved network"
                value={scope?.name ?? 'Choose a network'}
            />
            <ReviewRow
                label="Credential reference"
                value={credential?.name ?? 'Choose a reference'}
            />
            <ReviewRow
                label="Camera address"
                value={setup.camera.ip_address ?? 'Not recorded'}
            />
            <ReviewRow
                label="Selected channel"
                value={selected?.name ?? 'Choose a channel'}
            />
            <ReviewRow label="Reason" value={purpose || 'Enter your reason'} />
        </ReviewCard>
    );
    const waiting =
        !!discovery &&
        ['queued', 'preparing', 'dispatched'].includes(discovery.status);
    return (
        <>
            <WizardShell
                open
                frontline
                title={
                    configuring
                        ? 'Set up Hikvision connection'
                        : mode === 'enable'
                          ? 'Turn on camera viewing'
                          : 'Turn off camera viewing'
                }
                description={setup.camera.name + ' · ' + setup.camera.site.name}
                onClose={() => {
                    if (!busy) leave.request(onClose);
                }}
                railIcon={Camera}
                railTitle="Camera connection"
                railSub={setup.camera.name}
                steps={steps.map((item) => ({ ...item, disabled: locked }))}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!locked) setStep(index);
                }}
                pct={
                    saved
                        ? 100
                        : configuring
                          ? Math.round(
                                [
                                    equipmentValid(),
                                    connectionValid(),
                                    !!review,
                                    selectable,
                                ].filter(Boolean).length * 25,
                            )
                          : purpose.trim().length >= 10
                            ? 100
                            : 50
                }
                footerStart={
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={() => leave.request(onClose)}
                    >
                        Close
                    </Button>
                }
                footerEnd={
                    saved ? null : uncertain ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() =>
                                void send(pending.current?.kind ?? 'discovery')
                            }
                        >
                            {busy ? 'Checking…' : 'Check this request'}
                        </Button>
                    ) : configuring && step === 1 ? (
                        <Button
                            type="button"
                            disabled={busy || frozen}
                            onClick={() => void send('discovery')}
                        >
                            {busy ? 'Checking…' : 'Check camera'}
                        </Button>
                    ) : configuring && step === 2 ? (
                        <Button
                            type="button"
                            disabled={busy || !selectable}
                            onClick={() => setStep(3)}
                        >
                            Review connection
                        </Button>
                    ) : step < steps.length - 1 ? (
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={() => {
                                if (configuring && !equipmentValid()) {
                                    setNotice(
                                        'Choose a Site collector, approved network and credential reference.',
                                    );
                                    setStep(0);
                                } else {
                                    setNotice('');
                                    setStep(step + 1);
                                }
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant={
                                mode === 'disable' ? 'destructive' : 'default'
                            }
                            disabled={busy || (configuring && !selectable)}
                            onClick={() =>
                                void send(configuring ? 'mapping' : 'action')
                            }
                        >
                            {busy
                                ? 'Confirming…'
                                : configuring
                                  ? 'Save connection'
                                  : mode === 'enable'
                                    ? 'Turn on viewing'
                                    : 'Turn off viewing'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Change confirmed"
                            blurb={saved}
                            actions={
                                <Button onClick={onCommitted}>
                                    Refresh camera setup
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane key={step}>
                    <div className="space-y-5">
                        {notice && (
                            <Alert>
                                <AlertDescription
                                    role={uncertain ? 'alert' : 'status'}
                                >
                                    {notice}
                                </AlertDescription>
                            </Alert>
                        )}
                        {!configuring ? (
                            <>
                                <ReviewCard
                                    icon={Camera}
                                    title={setup.camera.name}
                                >
                                    <ReviewRow
                                        label="Site"
                                        value={setup.camera.site.name}
                                    />
                                    <ReviewRow
                                        label="Connection"
                                        value={
                                            'Revision ' +
                                            (target?.revision ?? 'unavailable')
                                        }
                                    />
                                    <ReviewRow label="Effect" value={effect} />
                                </ReviewCard>
                                {step === 1 && (
                                    <div className="space-y-2">
                                        <Label htmlFor="hik-purpose">
                                            Reason for this change
                                        </Label>
                                        <Textarea
                                            id="hik-purpose"
                                            value={purpose}
                                            disabled={locked}
                                            maxLength={500}
                                            onChange={(e) =>
                                                setPurpose(e.target.value)
                                            }
                                        />
                                        <p className="text-caption text-muted-foreground">
                                            Use 10–500 characters. The reason is
                                            recorded with this change.
                                        </p>
                                    </div>
                                )}
                            </>
                        ) : step === 0 ? (
                            <>
                                <ReviewCard
                                    icon={Camera}
                                    title={setup.camera.name}
                                >
                                    <ReviewRow
                                        label="Site"
                                        value={setup.camera.site.name}
                                    />
                                    <ReviewRow
                                        label="Camera model"
                                        value={
                                            setup.camera.model ?? 'Not recorded'
                                        }
                                    />
                                    <ReviewRow
                                        label="Camera address"
                                        value={
                                            setup.camera.ip_address ??
                                            'Not recorded'
                                        }
                                    />
                                </ReviewCard>
                                <HikvisionPicker
                                    setup={setup}
                                    kind="collectors"
                                    label="Site collector"
                                    value={collector}
                                    disabled={locked || frozen}
                                    onChange={(v) => {
                                        setCollector(v);
                                        setScope(null);
                                    }}
                                />
                                <HikvisionPicker
                                    setup={setup}
                                    kind="scopes"
                                    label="Approved network"
                                    collector={collector?.id}
                                    value={scope}
                                    disabled={locked || frozen}
                                    onChange={setScope}
                                />
                                <HikvisionPicker
                                    setup={setup}
                                    kind="credentials"
                                    label="Credential reference"
                                    value={credential}
                                    disabled={locked || frozen}
                                    onChange={setCredential}
                                />
                                <p className="text-subtle">
                                    An administrator manages protected
                                    credentials. Camera passwords are never
                                    entered here.
                                </p>
                            </>
                        ) : step === 1 ? (
                            <>
                                {field(
                                    'HTTPS port',
                                    port,
                                    setPort,
                                    5,
                                    'Use the approved HTTPS port for this camera.',
                                    true,
                                )}
                                {field(
                                    'Certificate server name',
                                    tlsName,
                                    setTlsName,
                                    253,
                                    'Use the name on the approved camera certificate.',
                                )}
                                {field(
                                    'Certificate SHA-256',
                                    pin,
                                    setPin,
                                    64,
                                    'Use the approved 64-character certificate value, in lower case.',
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="hik-purpose">
                                        Reason for this change
                                    </Label>
                                    <Textarea
                                        id="hik-purpose"
                                        value={purpose}
                                        disabled={locked || frozen}
                                        maxLength={500}
                                        onChange={(e) =>
                                            setPurpose(e.target.value)
                                        }
                                    />
                                    <p className="text-caption text-muted-foreground">
                                        Use 10–500 characters. The reason is
                                        recorded with the camera check and
                                        selection.
                                    </p>
                                </div>
                                <Alert>
                                    <AlertDescription>
                                        {effect}
                                    </AlertDescription>
                                </Alert>
                                {frozen && !uncertain && !waiting && (
                                    <Button
                                        variant="outline"
                                        onClick={resetCheck}
                                    >
                                        Start a new check
                                    </Button>
                                )}
                            </>
                        ) : step === 2 ? (
                            <>
                                {review ? (
                                    <>
                                        <ReviewCard
                                            icon={Camera}
                                            title="Observed camera"
                                        >
                                            <ReviewRow
                                                label="Model"
                                                value={
                                                    review.identity?.model ??
                                                    'Not available'
                                                }
                                            />
                                            <ReviewRow
                                                label="Serial number"
                                                value={
                                                    review.identity?.serial ??
                                                    'Not available'
                                                }
                                            />
                                            <ReviewRow
                                                label="Firmware"
                                                value={
                                                    review.identity
                                                        ? review.identity
                                                              .firmware +
                                                          ' ' +
                                                          review.identity
                                                              .firmware_build
                                                        : 'Not available'
                                                }
                                            />
                                            <ReviewRow
                                                label="Checked at"
                                                value={formatDateTime(
                                                    review.observed_at,
                                                )}
                                            />
                                        </ReviewCard>
                                        {(!fresh ||
                                            !review.current_context ||
                                            !review.mapping_enabled) && (
                                            <Alert>
                                                <AlertDescription>
                                                    This check cannot be used to
                                                    save a connection. Refresh
                                                    setup or run a new check. An
                                                    administrator must enable
                                                    reviewed camera mapping.
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                        <Popover
                                            open={channelOpen}
                                            onOpenChange={setChannelOpen}
                                        >
                                            <PopoverTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    role="combobox"
                                                    aria-label="Camera channel"
                                                    aria-expanded={channelOpen}
                                                    disabled={locked || !fresh}
                                                    className="frontline-tap w-full justify-between"
                                                >
                                                    {selected?.name ||
                                                        'Choose a camera channel'}
                                                    <ChevronsUpDown
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                </Button>
                                            </PopoverTrigger>
                                            <PopoverContent className="w-[var(--radix-popover-trigger-width)] max-w-[calc(100vw-2rem)] p-0">
                                                <Command shouldFilter={false}>
                                                    <CommandInput
                                                        aria-label="Search camera channels"
                                                        placeholder="Search name, channel or codec…"
                                                        value={channelQuery}
                                                        onValueChange={(v) => {
                                                            setChannelQuery(v);
                                                            setChannelLimit(20);
                                                        }}
                                                    />
                                                    <CommandList>
                                                        <CommandEmpty>
                                                            No matching
                                                            channels.
                                                        </CommandEmpty>
                                                        {matching
                                                            .slice(
                                                                0,
                                                                channelLimit,
                                                            )
                                                            .map((c) => (
                                                                <CommandItem
                                                                    key={c.id}
                                                                    value={c.id}
                                                                    disabled={
                                                                        !c.can_select
                                                                    }
                                                                    className="frontline-tap"
                                                                    onSelect={() => {
                                                                        setChannel(
                                                                            c.id,
                                                                        );
                                                                        setChannelOpen(
                                                                            false,
                                                                        );
                                                                    }}
                                                                >
                                                                    <span className="min-w-0 [overflow-wrap:anywhere] whitespace-normal">
                                                                        {c.name ||
                                                                            'Channel ' +
                                                                                c.id}{' '}
                                                                        · {c.id}{' '}
                                                                        ·{' '}
                                                                        {
                                                                            c.codec
                                                                        }
                                                                        {!c.can_select && (
                                                                            <span className="text-caption block">
                                                                                {channelReasons[
                                                                                    c.reason_code ??
                                                                                        ''
                                                                                ] ??
                                                                                    'This channel is unavailable for viewing.'}
                                                                            </span>
                                                                        )}
                                                                    </span>
                                                                </CommandItem>
                                                            ))}
                                                        {matching.length >
                                                            channelLimit && (
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setChannelLimit(
                                                                        (v) =>
                                                                            v +
                                                                            20,
                                                                    )
                                                                }
                                                            >
                                                                More channels
                                                            </Button>
                                                        )}
                                                    </CommandList>
                                                </Command>
                                            </PopoverContent>
                                        </Popover>
                                        <p className="text-caption text-muted-foreground">
                                            Only an approved H.264 channel can
                                            be selected. Audio remains off.
                                        </p>
                                    </>
                                ) : (
                                    <p role="status" className="text-subtle">
                                        {waiting
                                            ? 'Waiting for the Site collector’s camera check.'
                                            : 'No camera check is available yet.'}
                                    </p>
                                )}
                                {!waiting && !locked && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={resetCheck}
                                    >
                                        Start a new check
                                    </Button>
                                )}
                            </>
                        ) : (
                            <>
                                {summary}
                                <Alert>
                                    <AlertDescription>
                                        {effect}
                                    </AlertDescription>
                                </Alert>
                                <p className="text-subtle">
                                    Video will remain unverified until a viewing
                                    attempt succeeds.
                                </p>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            {leave.confirmation}
        </>
    );
}
