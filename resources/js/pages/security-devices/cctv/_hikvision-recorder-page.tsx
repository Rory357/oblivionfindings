import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import { ClipboardCheck, HardDrive, Network, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { type HikOption } from './_hikvision-contract';
import { HikvisionPicker } from './_hikvision-picker';
import {
    recorderDiscoveryReply,
    recorderSetupHref,
    recorderSetupReply,
    type RecorderDiscovery,
    type RecorderSetup,
} from './_hikvision-recorder-contract';
import { sourceAddress, sourceText } from './_source-contract';
import { hikvisionJson } from './live-transport';
const waiting = (v: string) =>
    ['queued', 'preparing', 'dispatched'].includes(v);
const statusText = (v: string) =>
    ({
        queued: 'Waiting for collector',
        preparing: 'Preparing check',
        dispatched: 'Checking recorder',
        observed: 'Information received',
        failed: 'Check unsuccessful',
        revoked: 'Permission changed',
        expired: 'Check expired',
    })[v as 'queued'] ?? 'Check unavailable';
export default function RecorderPage(props: RecorderSetup) {
    let initial: RecorderSetup | null = null;
    try {
        initial = recorderSetupReply(
            {
                provider: props.provider,
                actor_id: props.actor_id,
                recorder_id: props.recorder_id,
                recorder: props.recorder,
                revision: props.revision,
                readiness: props.readiness,
                choices: props.choices,
                choice: props.choice,
                q: props.q,
                collector_id: props.collector_id,
                recent_requests: props.recent_requests,
            },
            props.actor_id,
            props.recorder_id,
        );
    } catch {
        /* Closed reader; no unverified data is shown. */
    }
    return initial ? (
        <Workspace
            key={JSON.stringify([
                initial.actor_id,
                initial.recorder_id,
                initial.recorder.site.id,
                initial.recorder.model,
                initial.recorder.serial,
                initial.recorder.ip_address,
            ])}
            initial={initial}
        />
    ) : (
        <Unavailable />
    );
}
function Unavailable() {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'All devices', href: '/security-devices/devices' },
            ]}
        >
            <Alert>
                <AlertDescription>
                    Recorder information could not be verified. Return to
                    Security &amp; Devices and reopen this recorder.
                </AlertDescription>
            </Alert>
        </AppLayout>
    );
}
function Workspace({ initial }: { initial: RecorderSetup }) {
    const [setup, setSetup] = useState<RecorderSetup | null>(initial),
        [open, setOpen] = useState(false),
        [observation, setObservation] = useState<RecorderDiscovery | null>(
            null,
        ),
        [notice, setNotice] = useState(''),
        [busy, setBusy] = useState(false);
    const call = useRef<AbortController | null>(null);
    useEffect(() => () => call.current?.abort(), []);
    async function refresh() {
        setObservation(null);
        call.current?.abort();
        const controller = new AbortController();
        call.current = controller;
        setBusy(true);
        try {
            const next = recorderSetupReply(
                await hikvisionJson(
                    initial.recorder_id,
                    initial.actor_id,
                    recorderSetupHref(initial.recorder_id),
                    controller.signal,
                ),
                initial.actor_id,
                initial.recorder_id,
            );
            if (!controller.signal.aborted) {
                setSetup(next);
                setNotice('');
            }
        } catch {
            if (!controller.signal.aborted) {
                setSetup(null);
                setObservation(null);
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    async function read(uuid: string) {
        if (!setup) return;
        call.current?.abort();
        const controller = new AbortController();
        call.current = controller;
        setBusy(true);
        setObservation(null);
        try {
            const next = recorderDiscoveryReply(
                await hikvisionJson(
                    setup.recorder_id,
                    setup.actor_id,
                    '/security-devices/cctv/hikvision-discoveries/' + uuid,
                    controller.signal,
                ),
                setup,
                uuid,
            );
            if (!controller.signal.aborted) {
                setObservation(next);
                setNotice('');
            }
        } catch {
            if (!controller.signal.aborted)
                setNotice(
                    'This recorder check could not be read. Refresh information and check your access before trying again.',
                );
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    if (!setup) return <Unavailable />;
    const d = setup.recorder,
        base = '/security-devices/devices/' + d.id;
    const pending = setup.recent_requests.some((r) => waiting(r.status));
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'All devices', href: '/security-devices/devices' },
                { title: d.name, href: base },
                { title: 'Recorder information' },
            ]}
        >
            <Head title={d.name + ' · Recorder information'} />
            <div className="flex flex-col gap-5">
                <PageHeader
                    variant="profile"
                    frontline
                    title={d.name}
                    icon={HardDrive}
                    wrapTitle
                    backHref={base}
                    subline={d.site.name + ' · Hikvision recorder'}
                    actions={
                        <>
                            <PageHeaderGlassButton
                                asChild
                                className="frontline-tap frontline-focus"
                            >
                                <Link href={base}>Recorder details</Link>
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton
                                className="frontline-tap frontline-focus"
                                disabled={
                                    busy ||
                                    !!notice ||
                                    pending ||
                                    !setup.readiness.can_discover
                                }
                                onClick={() => setOpen(true)}
                            >
                                Check recorder
                            </PageHeaderPrimaryButton>
                        </>
                    }
                />
                <Alert>
                    <AlertDescription>
                        This check reads recorder information and reported
                        inputs. Camera identities and links still need
                        verification. Viewing and recording preservation are
                        separate steps.
                    </AlertDescription>
                </Alert>
                {!setup.readiness.can_discover && (
                    <Alert>
                        <AlertDescription>
                            Recorder checks have not been enabled. An
                            administrator needs to approve the Site collector
                            and protected settings.
                        </AlertDescription>
                    </Alert>
                )}
                {pending && (
                    <Alert>
                        <AlertDescription>
                            A recorder check is still pending. Open that request
                            below before starting another check.
                        </AlertDescription>
                    </Alert>
                )}
                {notice && (
                    <Alert>
                        <AlertDescription role="alert">
                            {notice}
                        </AlertDescription>
                    </Alert>
                )}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-section-title">
                            Recent recorder checks
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <Button
                            variant="outline"
                            className="frontline-tap frontline-focus"
                            disabled={busy}
                            onClick={() => void refresh()}
                        >
                            Refresh information
                        </Button>
                        {setup.recent_requests.length === 0 ? (
                            <Alert>
                                <AlertDescription>
                                    No recorder check has been requested yet.
                                    Choose Check recorder to review the approved
                                    connection.
                                </AlertDescription>
                            </Alert>
                        ) : (
                            <ul className="divide-y">
                                {setup.recent_requests.map((r) => (
                                    <li
                                        key={r.request_uuid}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3"
                                    >
                                        <div className="space-y-1">
                                            <StatusBadge
                                                status={
                                                    r.status === 'observed'
                                                        ? 'info'
                                                        : waiting(r.status)
                                                          ? 'pending'
                                                          : 'warning'
                                                }
                                            >
                                                {statusText(r.status)}
                                            </StatusBadge>
                                            <p className="text-caption text-muted-foreground">
                                                Requested{' '}
                                                {formatDateTime(r.created_at)}
                                            </p>
                                        </div>
                                        <Button
                                            variant="outline"
                                            className="frontline-tap frontline-focus"
                                            disabled={busy}
                                            onClick={() =>
                                                void read(r.request_uuid)
                                            }
                                        >
                                            Open check
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
                {observation && <Observation value={observation} />}
                {open && (
                    <RecorderCheck
                        setup={setup}
                        onClose={() => {
                            setOpen(false);
                            void refresh();
                        }}
                        onObserved={(v) => {
                            setObservation(v);
                        }}
                    />
                )}
            </div>
        </AppLayout>
    );
}
function Observation({ value }: { value: RecorderDiscovery }) {
    const inventory = value.inventory;
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-section-title">
                    Reported recorder information
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <StatusBadge
                    status={
                        value.status === 'observed'
                            ? 'info'
                            : waiting(value.status)
                              ? 'pending'
                              : 'warning'
                    }
                >
                    {statusText(value.status)}
                </StatusBadge>
                {!value.current_context && (
                    <Alert>
                        <AlertDescription>
                            The connection or permission changed. This is an
                            earlier observation; run a new authorised check
                            before using it.
                        </AlertDescription>
                    </Alert>
                )}
                {value.observed_at && (
                    <p className="text-caption text-muted-foreground">
                        Observed {formatDateTime(value.observed_at)}
                    </p>
                )}
                {inventory && (
                    <>
                        <ReviewCard icon={HardDrive} title="Reported identity">
                            <ReviewRow
                                label="Model"
                                value={inventory.identity.model}
                            />
                            <ReviewRow
                                label="Serial number"
                                value={inventory.identity.serial}
                            />
                            <ReviewRow
                                label="Firmware"
                                value={inventory.identity.firmware}
                            />
                        </ReviewCard>
                        <div>
                            <h3 className="text-section-title">
                                Reported inputs
                            </h3>
                            <p className="text-subtle">
                                An online input does not verify which camera is
                                connected.
                            </p>
                            {inventory.inputs.length === 0 ? (
                                <Alert>
                                    <AlertDescription>
                                        No digital input status was reported.
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <ul className="grid gap-3 md:grid-cols-2">
                                    {inventory.inputs.map((i) => (
                                        <li
                                            key={i.id}
                                            className="rounded-lg border p-4"
                                        >
                                            <p className="font-medium">
                                                Input {i.id}
                                            </p>
                                            <StatusBadge
                                                status={
                                                    i.online === true
                                                        ? 'info'
                                                        : i.online === false
                                                          ? 'warning'
                                                          : 'neutral'
                                                }
                                            >
                                                {i.online === true
                                                    ? 'Online reported'
                                                    : i.online === false
                                                      ? 'Offline reported'
                                                      : 'Status not reported'}
                                            </StatusBadge>
                                            <p className="text-subtle mt-2">
                                                Camera identity unverified
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div>
                            <h3 className="text-section-title">
                                Reported channels
                            </h3>
                            {inventory.channels.length === 0 ? (
                                <Alert>
                                    <AlertDescription>
                                        No channel details were reported.
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <ul className="grid gap-3 md:grid-cols-2">
                                    {inventory.channels.map((c) => (
                                        <li
                                            key={c.id}
                                            className="rounded-lg border p-4 [overflow-wrap:anywhere]"
                                        >
                                            <p className="font-medium">
                                                {c.name || 'Channel ' + c.id}
                                            </p>
                                            <p className="text-subtle">
                                                {c.input_kind ===
                                                'digital_proxy'
                                                    ? 'Digital input '
                                                    : 'Local video input '}
                                                {c.input_id} · {c.codec}
                                            </p>
                                            <p className="text-caption text-muted-foreground">
                                                {c.width} × {c.height} ·{' '}
                                                {c.fps100 === 0
                                                    ? 'Frame rate not reported'
                                                    : c.fps100 / 100 +
                                                      ' frames per second'}
                                            </p>
                                            <div className="mt-2">
                                                <StatusBadge
                                                    status={
                                                        c.enabled &&
                                                        c.video_enabled
                                                            ? 'info'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {c.enabled &&
                                                    c.video_enabled
                                                        ? 'Channel enabled as reported'
                                                        : 'Channel disabled as reported'}
                                                </StatusBadge>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
function RecorderCheck({
    setup,
    onClose,
    onObserved,
}: {
    setup: RecorderSetup;
    onClose: () => void;
    onObserved: (v: RecorderDiscovery | null) => void;
}) {
    const [collector, setCollector] = useState<HikOption | null>(null),
        [scope, setScope] = useState<HikOption | null>(null),
        [credential, setCredential] = useState<HikOption | null>(null),
        [port, setPort] = useState('443'),
        [name, setName] = useState(''),
        [pin, setPin] = useState(''),
        [purpose, setPurpose] = useState(''),
        [step, setStep] = useState(0),
        [busy, setBusy] = useState(false),
        [notice, setNotice] = useState(''),
        [result, setResult] = useState<RecorderDiscovery | null>(null),
        [uncertain, setUncertain] = useState(false);
    const pending = useRef<Record<string, unknown> | null>(null),
        call = useRef<AbortController | null>(null),
        until = useRef(0);
    const leave = useSettingsLeaveConfirmation(
        !result && (!!purpose || !!collector || !!pending.current),
        'Close recorder check?',
        'Unsaved entries will be discarded. A request already sent may still finish. Refresh recorder information and open that same request before starting another check.',
    );
    useEffect(() => () => call.current?.abort(), []);
    const equipment =
        !!collector &&
        !!scope &&
        scope.collector_id === collector.id &&
        !!credential;
    const connection =
        !!setup.recorder.ip_address &&
        sourceAddress(setup.recorder.ip_address) &&
        /^[1-9]\d{0,4}$/.test(port) &&
        Number(port) <= 65535 &&
        name.length > 0 &&
        name.length <= 253 &&
        name
            .split('.')
            .every((l) =>
                /^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/.test(l),
            ) &&
        /^[a-f0-9]{64}$/.test(pin) &&
        sourceText(purpose.trim(), 10, 500);
    const locked = !!pending.current;
    function accept(raw: unknown, uuid?: string) {
        const value = recorderDiscoveryReply(raw, setup, uuid);
        setResult(value);
        onObserved(value);
        setUncertain(false);
        setNotice(
            !value.current_context
                ? 'The connection or permission changed. Close and refresh information.'
                : waiting(value.status)
                  ? 'The Site collector is checking the recorder. This request is saved in Recent recorder checks.'
                  : value.status === 'observed'
                    ? 'Recorder information received. Camera identities remain unverified.'
                    : 'The check did not finish successfully. Close and review the connection before requesting a new check.',
        );
    }
    async function run(recover = false) {
        if (busy) return;
        if (!pending.current) {
            if (!equipment || !connection) return;
            pending.current = {
                expected_actor_id: setup.actor_id,
                request_key: crypto.randomUUID(),
                expected_source_revision: 0,
                collector_id: collector!.id,
                discovery_scope_id: scope!.id,
                credential_reference_id: credential!.id,
                allowed_ip: setup.recorder.ip_address,
                allowed_port: Number(port),
                tls_server_name: name,
                tls_pin: pin,
                purpose: purpose.trim(),
            };
            until.current = Date.now() + 300000;
        }
        call.current?.abort();
        const controller = new AbortController();
        call.current = controller;
        setBusy(true);
        try {
            const raw = await hikvisionJson(
                setup.recorder_id,
                setup.actor_id,
                recover
                    ? '/security-devices/cctv/hikvision-discoveries/requests/' +
                          pending.current.request_key +
                          '?expected_actor_id=' +
                          setup.actor_id
                    : '/security-devices/devices/' +
                          setup.recorder_id +
                          '/hikvision-discoveries',
                controller.signal,
                recover ? undefined : pending.current,
            );
            if (!controller.signal.aborted) accept(raw, result?.request_uuid);
        } catch {
            if (!controller.signal.aborted) {
                setUncertain(true);
                setNotice(
                    'The request could not be confirmed. Check this same request or retry it with the unchanged details.',
                );
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    async function check() {
        if (!result || busy) return;
        call.current?.abort();
        const controller = new AbortController();
        call.current = controller;
        setBusy(true);
        try {
            const raw = await hikvisionJson(
                setup.recorder_id,
                setup.actor_id,
                result.reader_href,
                controller.signal,
            );
            if (!controller.signal.aborted) accept(raw, result.request_uuid);
        } catch {
            if (!controller.signal.aborted) {
                setResult(null);
                onObserved(null);
                setUncertain(true);
                setNotice(
                    'This request could not be read. Check the same request before starting another.',
                );
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    useEffect(() => {
        if (
            !result ||
            !waiting(result.status) ||
            !result.current_context ||
            !result.execution_enabled ||
            busy ||
            uncertain
        )
            return;
        if (Date.now() >= until.current) {
            setUncertain(true);
            setNotice(
                'The recorder check has not been confirmed. Check this same request before making another change.',
            );
            return;
        }
        const timer = setTimeout(() => void check(), 1000);
        return () =>
            clearTimeout(
                timer,
            ); /* One bounded reader request owns each poll. */ // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [result, busy, uncertain]);
    const steps = [
        {
            key: 'equipment',
            label: 'Equipment',
            blurb: 'Approved collector and access',
            icon: Network,
        },
        {
            key: 'connection',
            label: 'Connection',
            blurb: 'Certificate and reason',
            icon: ShieldCheck,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Request a read-only check',
            icon: ClipboardCheck,
        },
    ];
    const field = (
        id: string,
        label: string,
        value: string,
        set: (v: string) => void,
        help?: string,
    ) => (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                value={value}
                onChange={(e) => set(e.target.value)}
                disabled={locked || busy}
            />
            {help && (
                <p className="text-caption text-muted-foreground">{help}</p>
            )}
        </div>
    );
    return (
        <>
            <WizardShell
                open
                frontline
                title="Check Hikvision recorder"
                description={
                    setup.recorder.name + ' · ' + setup.recorder.site.name
                }
                onClose={() => {
                    if (!busy) leave.request(onClose);
                }}
                railIcon={HardDrive}
                railTitle="Recorder check"
                railSub={setup.recorder.name}
                steps={steps.map((s) => ({ ...s, disabled: locked || busy }))}
                stepIndex={step}
                onStepClick={(i) => {
                    if (!locked && !busy) setStep(i);
                }}
                pct={
                    result
                        ? 100
                        : equipment && connection
                          ? 75
                          : equipment
                            ? 40
                            : 0
                }
                footerStart={
                    <Button
                        variant="outline"
                        className="frontline-tap frontline-focus"
                        disabled={busy}
                        onClick={() => leave.request(onClose)}
                    >
                        Close
                    </Button>
                }
                footerEnd={
                    step < 2 ? (
                        <Button
                            className="frontline-tap frontline-focus"
                            disabled={
                                busy || (step === 0 ? !equipment : !connection)
                            }
                            onClick={() => setStep(step + 1)}
                        >
                            Continue
                        </Button>
                    ) : locked ? (
                        <>
                            <Button
                                variant="outline"
                                className="frontline-tap frontline-focus"
                                disabled={busy}
                                onClick={() =>
                                    void (result ? check() : run(true))
                                }
                            >
                                Check same request
                            </Button>
                            {uncertain && !result && (
                                <Button
                                    className="frontline-tap frontline-focus"
                                    disabled={busy}
                                    onClick={() => void run()}
                                >
                                    Retry same request
                                </Button>
                            )}
                        </>
                    ) : (
                        <Button
                            className="frontline-tap frontline-focus"
                            disabled={
                                busy ||
                                !equipment ||
                                !connection ||
                                !setup.readiness.can_discover
                            }
                            onClick={() => void run()}
                        >
                            Request recorder check
                        </Button>
                    )
                }
            >
                {step === 0 && (
                    <WizardStepPane>
                        <h2 className="text-section-title">
                            Choose approved equipment
                        </h2>
                        <p className="mb-4 text-sm text-muted-foreground">
                            Only choices at this recorder's current Site are
                            available.
                        </p>
                        <div className="space-y-4">
                            <HikvisionPicker
                                setup={setup}
                                kind="collectors"
                                label="Site collector"
                                value={collector}
                                disabled={busy || locked}
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
                                disabled={busy || locked}
                                onChange={setScope}
                            />
                            <HikvisionPicker
                                setup={setup}
                                kind="credentials"
                                label="Credential reference"
                                value={credential}
                                disabled={busy || locked}
                                onChange={setCredential}
                            />
                        </div>
                    </WizardStepPane>
                )}
                {step === 1 && (
                    <WizardStepPane>
                        <h2 className="text-section-title">
                            Review the recorder connection
                        </h2>
                        <p className="mb-4 text-sm text-muted-foreground">
                            The registered recorder address is fixed. A
                            technical administrator can provide its certificate
                            details.
                        </p>
                        <div className="space-y-4">
                            <p className="text-subtle">
                                Recorder address:{' '}
                                {setup.recorder.ip_address ?? 'Not recorded'}
                            </p>
                            {field(
                                'recorder-port',
                                'HTTPS port',
                                port,
                                setPort,
                            )}
                            {field(
                                'recorder-name',
                                'Certificate name',
                                name,
                                setName,
                            )}
                            {field(
                                'recorder-pin',
                                'Certificate SHA256 fingerprint',
                                pin,
                                setPin,
                                'Enter the approved certificate fingerprint.',
                            )}
                            <div className="space-y-2">
                                <Label htmlFor="recorder-purpose">
                                    Reason for this check
                                </Label>
                                <Textarea
                                    id="recorder-purpose"
                                    value={purpose}
                                    onChange={(e) => setPurpose(e.target.value)}
                                    maxLength={500}
                                    disabled={busy || locked}
                                />
                            </div>
                        </div>
                    </WizardStepPane>
                )}
                {step === 2 && (
                    <WizardStepPane>
                        <h2 className="text-section-title">
                            Review and request
                        </h2>
                        <p className="mb-4 text-sm text-muted-foreground">
                            Reads recorder information. It does not change
                            equipment settings or activate viewing.
                        </p>
                        <ReviewCard
                            icon={HardDrive}
                            title={setup.recorder.name}
                        >
                            <ReviewRow
                                label="Site collector"
                                value={collector?.name ?? 'Not selected'}
                            />
                            <ReviewRow
                                label="Approved network"
                                value={scope?.name ?? 'Not selected'}
                            />
                            <ReviewRow
                                label="Credential reference"
                                value={credential?.name ?? 'Not selected'}
                            />
                            <ReviewRow
                                label="Recorder address"
                                value={
                                    setup.recorder.ip_address ?? 'Not recorded'
                                }
                            />
                            <ReviewRow label="Reason" value={purpose} />
                        </ReviewCard>
                    </WizardStepPane>
                )}
                {notice && (
                    <Alert>
                        <AlertDescription role="status">
                            {notice}
                        </AlertDescription>
                    </Alert>
                )}
                {result && <Observation value={result} />}
            </WizardShell>
            {leave.confirmation}
        </>
    );
}
