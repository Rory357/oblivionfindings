import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { Camera, Check, Network, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    sameSourceInput,
    sourceAddress,
    sourceBase,
    sourceText,
    sourceWriteReply,
    type CctvSource,
    type CctvSourceInput,
    type CctvSourceSetup,
    type SourceOption,
} from './_source-contract';
import { CctvSourcePicker } from './_source-picker';
import { cctvSourceJson } from './live-transport';

type Mode = 'configure' | 'enable-stream' | 'disable';
type Equipment = {
    recorder: SourceOption | null;
    collector: SourceOption | null;
    scope: SourceOption | null;
};
type Connection = {
    console_id: string;
    provider_camera_id: string;
    stream_address: string;
    stream_port: string;
    tls_server_name: string;
    tls_pin: string;
};
export function CctvSourceDialog({
    setup,
    mode,
    onClose,
    onSaved,
}: {
    setup: CctvSourceSetup;
    mode: Mode;
    onClose: () => void;
    onSaved: (source: CctvSource) => void;
}) {
    const source = setup.source,
        configuring = mode === 'configure';
    const initialEquipment: Equipment = {
        recorder: source
            ? { id: source.recorder_device_id, name: source.recorder_name }
            : null,
        collector: source
            ? { id: source.collector_id, name: source.collector_name }
            : null,
        scope: source
            ? {
                  id: source.discovery_scope_id,
                  name: source.discovery_scope_name,
                  collector_id: source.collector_id,
              }
            : null,
    };
    const initialConnection: Connection = {
        console_id: source?.console_id ?? '',
        provider_camera_id: source?.provider_camera_id ?? '',
        stream_address: source?.stream_address ?? '',
        stream_port: String(source?.stream_port ?? ''),
        tls_server_name: source?.tls_server_name ?? '',
        tls_pin: source?.tls_pin ?? '',
    };
    const [equipment, setEquipment] = useState(initialEquipment),
        [connection, setConnection] = useState(initialConnection),
        [reason, setReason] = useState(''),
        [step, setStep] = useState(0),
        [notice, setNotice] = useState(''),
        [busy, setBusy] = useState(false),
        [uncertain, setUncertain] = useState(false),
        [saved, setSaved] = useState<CctvSource | null>(null);
    const original = useRef(
        JSON.stringify({
            equipment: initialEquipment,
            connection: initialConnection,
        }),
    );
    const controller = useRef<AbortController | null>(null),
        request = useRef<Record<string, unknown> | null>(null);
    useEffect(() => () => controller.current?.abort(), []);
    const dirty =
        !saved &&
        (reason !== '' ||
            original.current !== JSON.stringify({ equipment, connection }) ||
            uncertain);
    const leave = useSettingsLeaveConfirmation(
        dirty,
        'Close camera setup?',
        'Unsaved entries will be discarded. A request already sent may still finish. Refresh the camera setup before making another change.',
    );
    const locked = busy || uncertain;
    const title = configuring
        ? 'Set up camera connection'
        : mode === 'enable-stream'
          ? 'Enable camera stream'
          : 'Turn off camera viewing';
    const verb = configuring
        ? 'Verify and save connection'
        : mode === 'enable-stream'
          ? 'Enable and verify stream'
          : 'Turn off viewing';
    const steps = configuring
        ? [
              {
                  key: 'equipment',
                  label: 'Equipment',
                  blurb: 'Recorder and Site network',
                  icon: Camera,
              },
              {
                  key: 'connection',
                  label: 'Connection',
                  blurb: 'UniFi identity and certificate',
                  icon: Network,
              },
              {
                  key: 'review',
                  label: 'Review',
                  blurb: 'Confirm connection settings',
                  icon: ShieldCheck,
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
                  key: 'reason',
                  label: 'Confirm',
                  blurb: 'Record your reason',
                  icon: Check,
              },
          ];
    function validEquipment() {
        return (
            !!equipment.recorder &&
            !!equipment.collector &&
            !!equipment.scope &&
            equipment.scope.collector_id === equipment.collector.id
        );
    }
    function validConnection() {
        const c = connection;
        return (
            /^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/.test(c.console_id) &&
            /^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/.test(c.provider_camera_id) &&
            sourceAddress(c.stream_address) &&
            /^[1-9]\d{0,4}$/.test(c.stream_port) &&
            Number(c.stream_port) <= 65535 &&
            c.tls_server_name.length <= 253 &&
            c.tls_server_name
                .split('.')
                .every((label) =>
                    /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(label),
                ) &&
            /^[0-9a-f]{64}$/.test(c.tls_pin)
        );
    }
    function validate(next: boolean) {
        if (configuring && !validEquipment()) {
            setStep(0);
            setNotice(
                'Choose a recorder, a Site collector and an approved network for that collector.',
            );
            return false;
        }
        if (configuring && (step > 0 || !next) && !validConnection()) {
            setStep(1);
            setNotice(
                'Complete the UniFi identities, recorder address and port, and its verified certificate details.',
            );
            return false;
        }
        if (!next && !sourceText(reason.trim(), 10, 500)) {
            setStep(steps.length - 1);
            setNotice('Enter a reason between 10 and 500 characters.');
            return false;
        }
        setNotice('');
        return true;
    }
    async function submit() {
        if (controller.current || saved) return;
        if (!request.current) {
            if (!validate(false)) return;
            if (
                !configuring &&
                (!source?.enabled || source.revision !== setup.revision)
            ) {
                setNotice('Refresh camera setup before changing this source.');
                return;
            }
            request.current = {
                expected_actor_id: setup.actor_id,
                expected_revision: setup.revision,
                request_key: crypto.randomUUID(),
                purpose: reason.trim(),
                ...(configuring
                    ? {
                          recorder_device_id: equipment.recorder!.id,
                          collector_id: equipment.collector!.id,
                          discovery_scope_id: equipment.scope!.id,
                          ...connection,
                          stream_port: Number(connection.stream_port),
                      }
                    : {}),
            };
        }
        const body = request.current,
            call = new AbortController();
        controller.current = call;
        setBusy(true);
        setNotice('');
        try {
            const next = sourceWriteReply(
                await cctvSourceJson(
                    setup.camera_id,
                    sourceBase(setup.camera_id) +
                        (configuring ? '' : '/' + mode),
                    call.signal,
                    body,
                ),
                setup.actor_id,
                setup.camera_id,
            );
            if (
                configuring
                    ? !sameSourceInput(next, body as CctvSourceInput)
                    : next.revision !== setup.revision ||
                      (mode === 'disable'
                          ? next.enabled
                          : !next.enabled || next.stream_state !== 'verified')
            )
                throw Error('Source outcome changed');
            if (!call.signal.aborted) {
                setSaved(next);
                setUncertain(false);
                onSaved(next);
            }
        } catch {
            if (!call.signal.aborted) {
                setUncertain(true);
                setNotice(
                    'The outcome could not be confirmed. Check this same request before making another change. Your reviewed details stay locked.',
                );
            }
        } finally {
            if (!call.signal.aborted) setBusy(false);
            if (controller.current === call) controller.current = null;
        }
    }
    const effect =
        mode === 'disable'
            ? 'Current live sessions will stop and this camera will become unavailable for viewing here. Vendor recordings remain managed in UniFi Protect.'
            : mode === 'enable-stream'
              ? 'This enables the low-quality camera stream in UniFi Protect and checks the stream after the change. Site collector readiness and viewing permission are still required.'
              : 'Camera and recorder identities are checked before saving. Changing the source ends any current live sessions.';
    function field(key: keyof Connection, label: string, help?: string) {
        return (
            <div className="space-y-2" key={key}>
                <Label htmlFor={'source-' + key}>{label}</Label>
                <Input
                    id={'source-' + key}
                    value={connection[key]}
                    disabled={locked}
                    maxLength={key === 'tls_pin' ? 64 : 253}
                    autoComplete="off"
                    spellCheck={false}
                    inputMode={key === 'stream_port' ? 'numeric' : undefined}
                    onChange={(event) =>
                        setConnection({
                            ...connection,
                            [key]: event.target.value,
                        })
                    }
                />
                {help && (
                    <p className="text-caption text-muted-foreground">{help}</p>
                )}
            </div>
        );
    }
    const review = (
        <ReviewCard
            icon={Camera}
            title={setup.camera.name}
            onEdit={configuring && !locked ? () => setStep(0) : undefined}
        >
            <ReviewRow label="Site" value={setup.camera.site.name} />
            <ReviewRow
                label="Recorder"
                value={equipment.recorder?.name ?? 'Choose a recorder'}
            />
            <ReviewRow
                label="Site collector"
                value={equipment.collector?.name ?? 'Choose a collector'}
            />
            <ReviewRow
                label="Approved network"
                value={equipment.scope?.name ?? 'Choose a network'}
            />
        </ReviewCard>
    );
    return (
        <>
            <WizardShell
                open
                title={title}
                description={setup.camera.name + ' · ' + setup.camera.site.name}
                onClose={() => {
                    if (!busy) leave.request(onClose);
                }}
                railIcon={Camera}
                railTitle="Camera connection"
                railSub={setup.camera.name}
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!locked) setStep(index);
                }}
                pct={
                    configuring
                        ? Math.round(
                              ([
                                  validEquipment(),
                                  validConnection(),
                                  sourceText(reason.trim(), 10, 500),
                              ].filter(Boolean).length /
                                  3) *
                                  100,
                          )
                        : reason.trim().length >= 10
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
                    uncertain ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => void submit()}
                        >
                            {busy ? 'Checking…' : 'Check this request'}
                        </Button>
                    ) : step < steps.length - 1 ? (
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={() => {
                                if (validate(true)) setStep(step + 1);
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
                            disabled={busy}
                            onClick={() => void submit()}
                        >
                            {busy ? 'Confirming…' : verb}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={
                                configuring
                                    ? 'Connection saved'
                                    : mode === 'disable'
                                      ? 'Viewing turned off'
                                      : 'Camera stream verified'
                            }
                            blurb={
                                mode === 'disable'
                                    ? 'This source is no longer available for live viewing here.'
                                    : saved.stream_state === 'verified'
                                      ? 'The vendor stream is verified. Open live view to check the picture with your current viewing permission.'
                                      : 'The connection is saved. The vendor stream still needs to be enabled and verified.'
                            }
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div className="space-y-5">
                        {notice && (
                            <Alert>
                                <AlertDescription role="status">
                                    {notice}
                                </AlertDescription>
                            </Alert>
                        )}
                        {configuring && step === 0 && (
                            <>
                                <p className="text-subtle">
                                    Choose equipment at {setup.camera.site.name}
                                    . The approved network must belong to the
                                    selected Site collector.
                                </p>
                                {(
                                    ['recorder', 'collector', 'scope'] as const
                                ).map((key) => (
                                    <div className="space-y-2" key={key}>
                                        <p className="text-sm font-medium">
                                            {key === 'recorder'
                                                ? 'Recorder'
                                                : key === 'collector'
                                                  ? 'Site collector'
                                                  : 'Approved network'}
                                        </p>
                                        <CctvSourcePicker
                                            actor={setup.actor_id}
                                            camera={setup.camera_id}
                                            revision={setup.revision}
                                            kind={
                                                key === 'recorder'
                                                    ? 'recorders'
                                                    : key === 'collector'
                                                      ? 'collectors'
                                                      : 'scopes'
                                            }
                                            label={
                                                key === 'recorder'
                                                    ? 'Recorder'
                                                    : key === 'collector'
                                                      ? 'Site collector'
                                                      : 'Approved network'
                                            }
                                            value={equipment[key]}
                                            collectorId={
                                                key === 'scope'
                                                    ? equipment.collector?.id
                                                    : undefined
                                            }
                                            disabled={locked}
                                            onChange={(option) =>
                                                setEquipment((current) => ({
                                                    ...current,
                                                    [key]: option,
                                                    ...(key === 'collector' &&
                                                    option.id !==
                                                        current.collector?.id
                                                        ? { scope: null }
                                                        : {}),
                                                }))
                                            }
                                        />
                                    </div>
                                ))}
                            </>
                        )}
                        {configuring && step === 1 && (
                            <>
                                <p className="text-subtle">
                                    Use the identities shown by your UniFi
                                    integration and the recorder’s approved
                                    network and certificate details.
                                </p>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {field('console_id', 'UniFi console ID')}
                                    {field(
                                        'provider_camera_id',
                                        'Protect camera ID',
                                    )}
                                    {field(
                                        'stream_address',
                                        'Recorder IP address',
                                        'A literal address inside the selected approved network.',
                                    )}
                                    {field('stream_port', 'RTSPS port')}
                                    {field(
                                        'tls_server_name',
                                        'Certificate server name',
                                        'The name covered by the recorder’s trusted TLS certificate.',
                                    )}
                                    {field(
                                        'tls_pin',
                                        'Certificate SHA-256',
                                        'The verified certificate fingerprint: 64 lowercase hexadecimal characters.',
                                    )}
                                </div>
                            </>
                        )}
                        {((configuring && step === 2) ||
                            (!configuring && step === 0)) && (
                            <>
                                {review}
                                {configuring && (
                                    <ReviewCard
                                        icon={Network}
                                        title="Connection to verify"
                                        onEdit={
                                            !locked
                                                ? () => setStep(1)
                                                : undefined
                                        }
                                    >
                                        <ReviewRow
                                            label="UniFi console ID"
                                            value={connection.console_id}
                                        />
                                        <ReviewRow
                                            label="Protect camera ID"
                                            value={
                                                connection.provider_camera_id
                                            }
                                        />
                                        <ReviewRow
                                            label="Recorder address"
                                            value={
                                                connection.stream_address +
                                                ':' +
                                                connection.stream_port
                                            }
                                        />
                                        <ReviewRow
                                            label="Certificate name"
                                            value={connection.tls_server_name}
                                        />
                                        <ReviewRow
                                            label="Certificate SHA-256"
                                            value={connection.tls_pin}
                                        />
                                    </ReviewCard>
                                )}
                                <p className="text-subtle">{effect}</p>
                            </>
                        )}
                        {step === steps.length - 1 && (
                            <div className="space-y-2">
                                <Label htmlFor="source-purpose">
                                    Reason for this change
                                </Label>
                                <Textarea
                                    id="source-purpose"
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    disabled={locked}
                                    maxLength={500}
                                    rows={3}
                                />
                                <p className="text-caption text-muted-foreground">
                                    Recorded with the camera’s audit history.
                                </p>
                            </div>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            {leave.confirmation}
        </>
    );
}
