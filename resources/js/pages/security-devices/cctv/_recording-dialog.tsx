import {
    ActualEventTimeField,
    actualEventInstant,
} from '@/components/clinical/actual-event-time-field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { Bookmark, Clock3, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    matchesRecordingRequest,
    recordingDetail,
    recordingRecoveryBase,
    type RecordingDetail,
    type RecordingPage,
    type RecordingRequest,
} from './_recording-contract';
import { sourceText } from './_source-contract';
import { cctvRecordingJson } from './live-transport';

export function recordingWindow(
    start: string,
    startOffset: string,
    end: string,
    endOffset: string,
    serverNow: number,
    maxSeconds: number,
) {
    const a = actualEventInstant(start, startOffset),
        b = actualEventInstant(end, endOffset);
    if (!a || !b)
        throw Error(
            'Choose both dates and times, including the clock occurrence if a time repeats.',
        );
    const first = Date.parse(a),
        last = Date.parse(b);
    if (first > serverNow)
        throw Error('The window must start at or before the current time.');
    if (last <= first) throw Error('Choose an end time after the start time.');
    if (last - first > maxSeconds * 1000)
        throw Error('Choose a shorter time window within the limit shown.');
    return {
        requested_start_at: new Date(first).toISOString().replace('.000Z', 'Z'),
        requested_end_at: new Date(last).toISOString().replace('.000Z', 'Z'),
    };
}
export function RecordingReferenceDialog({
    page,
    open,
    onClose,
    onSaved,
}: {
    page: RecordingPage;
    open: boolean;
    onClose: () => void;
    onSaved: (detail: RecordingDetail) => void;
}) {
    const [step, setStep] = useState(0),
        [start, setStart] = useState(''),
        [end, setEnd] = useState(''),
        [startOffset, setStartOffset] = useState(''),
        [endOffset, setEndOffset] = useState(''),
        [purpose, setPurpose] = useState(''),
        [error, setError] = useState(''),
        [busy, setBusy] = useState(false),
        [uncertain, setUncertain] = useState(false),
        [saved, setSaved] = useState<RecordingDetail | null>(null);
    const request = useRef<RecordingRequest | null>(null),
        controller = useRef<AbortController | null>(null),
        mounted = useRef(true),
        loadedAt = useRef(performance.now());
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            controller.current?.abort();
        };
    }, []);
    // Closing the dialog retains its draft in this page; only navigation discards it.
    const leaveGuard = useSettingsLeaveConfirmation(
        !saved && !!(start || end || purpose),
        'Leave this recording reference?',
        'Your draft will be lost when you leave this page. A save already sent may still finish; check the camera references before creating another.',
    );
    const now = () =>
        Date.parse(page.server_now) +
        Math.max(0, performance.now() - loadedAt.current);
    const windowInput = () =>
        recordingWindow(
            start,
            startOffset,
            end,
            endOffset,
            now(),
            page.window_limits.max_seconds,
        );
    function review() {
        try {
            windowInput();
            setError('');
            setStep(1);
        } catch (e) {
            setError((e as Error).message);
        }
    }
    async function save(recovery = false) {
        if (busy || saved || !page.source) return;
        try {
            if (!request.current) {
                if (
                    !page.permissions.can_create ||
                    page.current_source.state !== 'ready' ||
                    !page.create_href
                )
                    throw Error(
                        'Reopen this camera to check your current access and connection.',
                    );
                const interval = windowInput();
                if (!sourceText(purpose.trim(), 10, 500))
                    throw Error(
                        'Explain why this time window is needed in 10 to 500 characters.',
                    );
                request.current = {
                    expected_actor_id: page.actor_id,
                    request_key: crypto.randomUUID(),
                    expected_source_revision: page.source.revision,
                    ...interval,
                    purpose: purpose.trim(),
                };
            }
        } catch (e) {
            setError((e as Error).message);
            return;
        }
        const body = request.current;
        controller.current?.abort();
        const call = new AbortController();
        controller.current = call;
        setBusy(true);
        setError('');
        try {
            const raw = await cctvRecordingJson(
                page.camera.id,
                page.actor_id,
                recovery
                    ? recordingRecoveryBase +
                          '/' +
                          body.request_key +
                          '?expected_actor_id=' +
                          page.actor_id
                    : page.create_href!,
                call.signal,
                recovery ? undefined : body,
            );
            if (!mounted.current || call.signal.aborted) return;
            const detail = recordingDetail(raw, page.actor_id, page.camera.id);
            if (
                !matchesRecordingRequest(
                    detail,
                    body,
                    page.source.recorder_device_id,
                )
            )
                throw Error(
                    'The saved reference does not match the reviewed window.',
                );
            setSaved(detail);
            setUncertain(false);
            onSaved(detail);
        } catch {
            if (mounted.current && !call.signal.aborted) {
                setUncertain(true);
                setError(
                    'The save could not be confirmed. Check its status, or retry the same reviewed request. Your camera, time window and reason stay unchanged.',
                );
            }
        } finally {
            if (mounted.current && !call.signal.aborted) setBusy(false);
        }
    }
    const locked = busy || uncertain || !!request.current;
    let interval: {
        requested_start_at: string;
        requested_end_at: string;
    } | null = null;
    try {
        interval = request.current ?? windowInput();
    } catch {
        /* The time step explains incomplete input. */
    }
    return (
        <>
            <WizardShell
                open={open}
                onClose={() => {
                    if (!busy) onClose();
                }}
                title="Add recording reference"
                description="Review the camera and requested time window. This saves a reference, not footage."
                railIcon={Bookmark}
                railTitle="Reference"
                railSub={page.camera.name}
                steps={[
                    {
                        key: 'time',
                        label: 'Time window',
                        blurb: 'Choose the period to check',
                        icon: Clock3,
                        disabled: locked,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check the camera and reason',
                        icon: ShieldCheck,
                        disabled: busy,
                    },
                ]}
                stepIndex={step}
                onStepClick={(i) => {
                    if (!locked) {
                        if (i === 1) review();
                        else setStep(i);
                    }
                }}
                pct={saved ? 100 : step === 1 ? 75 : 25}
                footerStart={
                    <Button variant="outline" disabled={busy} onClick={onClose}>
                        Close
                    </Button>
                }
                footerEnd={
                    !saved &&
                    (step === 0 ? (
                        <Button onClick={review}>Continue</Button>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {!locked && (
                                <Button
                                    variant="outline"
                                    onClick={() => setStep(0)}
                                >
                                    Back
                                </Button>
                            )}
                            {uncertain && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => void save(false)}
                                >
                                    Retry same save
                                </Button>
                            )}
                            <Button
                                disabled={busy}
                                onClick={() => void save(uncertain)}
                            >
                                {busy
                                    ? 'Checking…'
                                    : uncertain
                                      ? 'Check save status'
                                      : 'Save reference'}
                            </Button>
                        </div>
                    ))
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Reference saved"
                            blurb="The camera and time window have been recorded. Footage has not been verified or preserved."
                            actions={
                                <Button asChild>
                                    <Link href={saved.reference.reader_href}>
                                        Open reference
                                    </Link>
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div className="space-y-5">
                        {error && (
                            <Alert>
                                <AlertDescription role="alert">
                                    {error}
                                </AlertDescription>
                            </Alert>
                        )}
                        {step === 0 ? (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Choose up to{' '}
                                    {Math.round(
                                        page.window_limits.max_seconds / 60,
                                    )}{' '}
                                    minutes. The end may be in the future for an
                                    incident still in progress. This only saves
                                    the time to check; it does not collect
                                    video.
                                </p>
                                <ActualEventTimeField
                                    id="recording-start"
                                    label="Window start"
                                    value={start}
                                    offset={startOffset}
                                    onChange={(v, o) => {
                                        setStart(v);
                                        setStartOffset(o);
                                    }}
                                />
                                <ActualEventTimeField
                                    id="recording-end"
                                    label="Window end"
                                    value={end}
                                    offset={endOffset}
                                    onChange={(v, o) => {
                                        setEnd(v);
                                        setEndOffset(o);
                                    }}
                                />
                            </>
                        ) : (
                            <>
                                <ReviewCard
                                    icon={Bookmark}
                                    title={page.camera.name}
                                >
                                    <ReviewRow
                                        label="Site"
                                        value={page.camera.site.name}
                                    />
                                    <ReviewRow
                                        label="Recorder"
                                        value={
                                            page.source?.recorder_name ??
                                            'Unavailable'
                                        }
                                    />
                                    <ReviewRow
                                        label="Window starts"
                                        value={
                                            interval
                                                ? formatDateTimeLong(
                                                      interval.requested_start_at,
                                                  )
                                                : 'Not selected'
                                        }
                                    />
                                    <ReviewRow
                                        label="Window ends"
                                        value={
                                            interval
                                                ? formatDateTimeLong(
                                                      interval.requested_end_at,
                                                  )
                                                : 'Not selected'
                                        }
                                    />
                                    <ReviewRow
                                        label="Timezone"
                                        value="Pacific/Auckland"
                                    />
                                </ReviewCard>
                                <div className="space-y-2">
                                    <Label htmlFor="recording-purpose">
                                        Reason for this reference
                                    </Label>
                                    <Textarea
                                        id="recording-purpose"
                                        rows={3}
                                        maxLength={500}
                                        value={purpose}
                                        onChange={(e) =>
                                            setPurpose(e.target.value)
                                        }
                                        disabled={locked}
                                        placeholder="Explain which reported event or concern this window will help review."
                                    />
                                </div>
                                <p className="text-caption text-muted-foreground">
                                    This uses the reviewed camera connection.
                                    Historical placement, recorded coverage and
                                    vendor retention have not been verified.
                                    Linking this reference to an incident is a
                                    separate step.
                                </p>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            {leaveGuard.confirmation}
        </>
    );
}
