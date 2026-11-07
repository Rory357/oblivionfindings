/**
 * The camera pop-ups (S&D rebuild Phase E), all on the pop-up kit:
 *  - 78 Watch live: give a reason (480). Decision D6: a reason before every live view, kept with
 *    the viewer's name. The reason travels with the live session (today's 10–500 character rule).
 *  - 16 The camera's full view (900): today's live session, the privacy line with the reason, and
 *    Open recorder / Save clip…. Closing it stops the live view.
 *  - 64 Privacy hours (720): days and times with the approved pickers. The server refuses every
 *    picture inside them; this pop-up only sets them.
 *  - Turn off privacy hours: a confirm, because it loosens a privacy safeguard.
 *
 * Not offered, on purpose: a privacy mask (no camera driver can send one yet), "Also on in Night
 * mode" (house modes are Phase F) and Snapshot in the full view (no audited way to keep a still).
 */
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { PopupCallout, PopupConfirm, PopupFacts, PopupField, PopupForm, PopupSection, PopupViewer } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDateTime, formatTime } from '@/lib/datetime';
import { endsNextDay, PRIVACY_DAYS, rangesWords, type PrivacyRangeData } from '@/lib/security-devices/camera-privacy';
import { cctvSessionReply } from '@/pages/security-devices/cctv/live-contract';
import { cctvJson, stopCctv } from '@/pages/security-devices/cctv/live-transport';
import { CctvViewer, type CctvViewerState } from '@/pages/security-devices/cctv/live-viewer';
import { Link, router } from '@inertiajs/react';
import { Cctv, Check, Clock3, Eye, EyeOff, LoaderCircle, Maximize2, Plus, Trash2, VideoOff } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type CameraContext = {
    id: number;
    name: string;
    site: string | null;
    model: string;
};

/* ------------------------------------------------------------------ */
/*  78 · Watch live: give a reason                                     */
/* ------------------------------------------------------------------ */

export const WATCH_REASONS = ['Checking someone at the door', 'Safety concern', 'Following up an alert', 'Other'] as const;
export type WatchReason = (typeof WATCH_REASONS)[number];

/** The reason kept with the live session: the chosen reason and the note, or just the note for "Other". */
export function watchPurpose(reason: WatchReason | '', note: string): string {
    const clean = note.trim().replace(/\s+/g, ' ');
    if (reason === 'Other') return clean;
    if (!reason) return '';
    return clean ? `${reason}: ${clean}` : reason;
}

/** Plain words for what's missing, or null when the reason can be sent (10–500 characters). */
export function watchReasonProblem(reason: WatchReason | '', note: string): string | null {
    if (!reason) return 'Choose why you’re watching.';
    const purpose = watchPurpose(reason, note);
    if (/[\p{Cc}\p{Cf}]/u.test(purpose)) return 'Take out the special characters from the note.';
    if ([...purpose].length < 10) return 'Say why in a few words (at least 10 letters).';
    if ([...purpose].length > 500) return 'Keep the reason under 500 letters.';
    return null;
}

/* The pop-up kit's tick chips: a tick as well as the tint, so colour is never the only sign. The
   group itself draws no box (the outline variant's shadow is turned off on the group). */
const CHIP_GROUP = 'flex flex-wrap justify-start gap-1.5 rounded-none shadow-none data-[variant=outline]:shadow-none';
const CHIP = 'rounded-full! border! px-3 data-[state=on]:border-primary';

/** One choice from a few. */
function OneChoiceChips<T extends string>({
    label,
    options,
    value,
    onChange,
    invalid,
}: {
    label: string;
    options: readonly T[];
    value: T | '';
    onChange: (next: T) => void;
    invalid?: boolean;
}) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            aria-label={label}
            aria-invalid={invalid || undefined}
            value={value}
            onValueChange={(next) => {
                if (next) onChange(next as T);
            }}
            className={CHIP_GROUP}
        >
            {options.map((option) => (
                <ToggleGroupItem key={option} value={option} className={CHIP}>
                    {value === option ? <Check aria-hidden="true" className="size-3.5" /> : null}
                    {option}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

/** Any number of days, Monday first. */
function DayChips({ label, value, onChange, invalid }: { label: string; value: number[]; onChange: (next: number[]) => void; invalid?: boolean }) {
    return (
        <ToggleGroup
            type="multiple"
            variant="outline"
            aria-label={label}
            aria-invalid={invalid || undefined}
            value={value.map(String)}
            onValueChange={(next) => onChange(next.map(Number).sort((a, b) => a - b))}
            className={CHIP_GROUP}
        >
            {PRIVACY_DAYS.map((day) => (
                <ToggleGroupItem key={day.value} value={String(day.value)} className={CHIP}>
                    {value.includes(day.value) ? <Check aria-hidden="true" className="size-3.5" /> : null}
                    {day.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

export function WatchLiveReason({
    open,
    camera,
    onClose,
    onWatch,
}: {
    open: boolean;
    camera: CameraContext;
    onClose: () => void;
    /** Called with the reason to keep; the full view starts the live session with it. */
    onWatch: (purpose: string) => void;
}) {
    const [reason, setReason] = useState<WatchReason | ''>('');
    const [note, setNote] = useState('');
    const [problem, setProblem] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setReason('');
        setNote('');
        setProblem(null);
    }, [open]);

    const submit = () => {
        const found = watchReasonProblem(reason, note);
        setProblem(found);
        if (found) return;
        onWatch(watchPurpose(reason, note));
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="sm"
            icon={Eye}
            title="Watch live: give a reason"
            description="Say why before the picture opens"
            context={{ title: camera.name, tag: camera.site ?? undefined, note: 'Live picture', icon: Cctv }}
            submitLabel="Watch live"
            dirty={note.trim() !== ''}
            draftName={`Reason for watching ${camera.name}`}
            onSubmit={submit}
            testId="sd-watch-live-reason"
        >
            <PopupField label="Why are you watching?" required wide error={problem && !reason ? problem : undefined}>
                <OneChoiceChips<WatchReason>
                    label="Why are you watching?"
                    options={WATCH_REASONS}
                    value={reason}
                    invalid={!!problem && !reason}
                    onChange={(next) => {
                        setReason(next);
                        setProblem(null);
                    }}
                />
            </PopupField>
            <PopupField
                label="Note"
                htmlFor="sd-watch-note"
                hint={reason === 'Other' ? 'say why in a few words' : 'optional'}
                error={problem && reason ? problem : undefined}
                wide
            >
                <Input
                    id="sd-watch-note"
                    value={note}
                    maxLength={480}
                    placeholder="e.g. A delivery"
                    onChange={(event) => {
                        setNote(event.target.value);
                        setProblem(null);
                    }}
                />
            </PopupField>
            <PopupCallout tone="info">Viewing is recorded with your name and reason.</PopupCallout>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  16 · The camera's full view                                        */
/* ------------------------------------------------------------------ */

type ViewState =
    | CctvViewerState
    | { status: 'checking'; message: string; receivedAt: string | null };

export type FullViewLive = {
    actor_id: number;
    source_revision: number | null;
    start_href: string | null;
};

export function CameraFullView({
    open,
    onClose,
    camera,
    live,
    purpose,
    viewerName,
    recorder,
    canSaveClip,
    onSaveClip,
    onStartFailed,
}: {
    open: boolean;
    onClose: () => void;
    camera: CameraContext;
    live: FullViewLive | null;
    /** The reason from pop-up 78. Without one the live view never starts. */
    purpose: string | null;
    viewerName: string;
    recorder: { name: string; href: string } | null;
    canSaveClip: boolean;
    onSaveClip: () => void;
    /** A start the server refused or didn't confirm: the page reads its privacy state again. */
    onStartFailed?: () => void;
}) {
    const frame = useRef<HTMLDivElement>(null);
    const video = useRef<HTMLVideoElement | null>(null);
    const viewer = useRef<CctvViewer | null>(null);
    const [videoReady, setVideoReady] = useState(false);
    const videoRef = useCallback((node: HTMLVideoElement | null) => {
        video.current = node;
        setVideoReady(node !== null);
    }, []);
    const onFailed = useRef(onStartFailed);
    useEffect(() => {
        onFailed.current = onStartFailed;
    });
    const [startedAt, setStartedAt] = useState<Date | null>(null);
    const [state, setState] = useState<ViewState>({ status: 'checking', message: 'Checking viewing permission…', receivedAt: null });

    useEffect(() => {
        if (!open || !purpose || !live?.start_href || !live.source_revision || !videoReady || !video.current) return;
        let current = true;
        const controller = new AbortController();
        const started = performance.now();
        setStartedAt(new Date());
        setState({ status: 'checking', message: 'Checking viewing permission…', receivedAt: null });
        cctvJson(live.start_href, controller.signal, {
            expected_actor_id: live.actor_id,
            request_key: crypto.randomUUID(),
            source_revision: live.source_revision,
            purpose,
        })
            .then((reply) => {
                const session = cctvSessionReply(reply, live.actor_id, camera.id);
                if (!current || !video.current) {
                    stopCctv(session);
                    return;
                }
                const active = new CctvViewer(session, video.current, started, (next) => {
                    if (current) setState(next);
                });
                viewer.current = active;
                active.start();
            })
            .catch(() => {
                if (!current) return;
                setState({
                    status: 'unavailable',
                    message: 'The live view couldn’t start. Privacy hours may have begun, or the hub may be busy. Close this and try again.',
                    receivedAt: null,
                });
                onFailed.current?.();
            });

        return () => {
            current = false;
            controller.abort();
            viewer.current?.stop();
            viewer.current = null;
        };
    }, [open, purpose, live?.start_href, live?.source_revision, live?.actor_id, camera.id, videoReady]);

    const isLive = state.status === 'live';
    const busy = state.status === 'checking' || state.status === 'connecting';
    const shownAt = state.receivedAt ? new Date(state.receivedAt) : startedAt;

    const fullScreen = () => {
        const node = frame.current;
        if (node && typeof node.requestFullscreen === 'function') void node.requestFullscreen().catch(() => undefined);
    };

    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="lg"
            icon={Cctv}
            title={camera.name}
            description={`${camera.site ?? 'No Site'} · live picture`}
            callout={
                purpose
                    ? {
                          tone: 'info',
                          content: (
                              <>
                                  <b className="font-semibold">Viewing is recorded</b> · {viewerName}
                                  {startedAt ? `, ${formatTime(startedAt)}` : ''} · Why: {purpose}
                              </>
                          ),
                      }
                    : undefined
            }
            footerStart={
                recorder ? (
                    <Button type="button" variant="ghost" asChild>
                        <Link href={recorder.href}>Open recorder</Link>
                    </Button>
                ) : null
            }
            action={
                canSaveClip ? (
                    <Button type="button" onClick={onSaveClip}>
                        Save clip…
                    </Button>
                ) : null
            }
            testId="sd-camera-full-view"
        >
            {/* A plain block around the frame: an aspect-ratio box as a grid item overflows its row. */}
            <div>
                <div ref={frame} className="relative aspect-video w-full overflow-hidden rounded-[12px] border border-border bg-muted">
                    <video
                        ref={videoRef}
                        muted
                        playsInline
                        aria-label={`Live video from ${camera.name}, without sound`}
                        className="h-full w-full object-contain"
                    />
                    <span className="absolute top-3 left-3 rounded-md bg-background/85 px-2 py-1 font-mono text-xs text-foreground">
                        {camera.name}
                        {shownAt ? ` · ${formatDateTime(shownAt)}` : ''}
                    </span>
                    {isLive ? (
                        <StatusBadge variant="critical" className="absolute top-3 right-3">
                            Live
                        </StatusBadge>
                    ) : null}
                    {!isLive ? (
                        <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-muted p-5 text-center">
                            {busy ? (
                                <LoaderCircle aria-hidden="true" className="size-7 animate-spin text-muted-foreground motion-reduce:animate-none" />
                            ) : (
                                <VideoOff aria-hidden="true" className="size-7 text-muted-foreground" />
                            )}
                            <p role="status" className="max-w-md text-sm">
                                {purpose ? state.message : 'Give a reason first to watch live.'}
                            </p>
                        </div>
                    ) : null}
                    {isLive ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Full screen"
                            className="absolute right-3 bottom-3"
                            onClick={fullScreen}
                        >
                            <Maximize2 aria-hidden="true" />
                        </Button>
                    ) : null}
                </div>
            </div>
            <PopupFacts
                rows={[
                    ['Picture', isLive && shownAt ? `Live · ${formatDateTime(shownAt)}` : busy ? 'Connecting…' : 'Not live'],
                    ['Camera', camera.model || '—'],
                    ['Recorder', recorder?.name ?? 'Not linked'],
                ]}
            />
            <p className="text-caption">Video only, with no sound. Oblivion doesn’t record this view; closing it stops the live picture.</p>
        </PopupViewer>
    );
}

/* ------------------------------------------------------------------ */
/*  64 · Privacy hours                                                 */
/* ------------------------------------------------------------------ */

const NEW_RANGE: PrivacyRangeData = { days: [1, 2, 3, 4, 5, 6, 7], from: '22:00', to: '06:00' };

type RangeErrors = Record<number, { days?: string; from?: string; to?: string }>;

export function PrivacyHoursPopup({
    open,
    onClose,
    camera,
    spot,
    ranges: saved,
}: {
    open: boolean;
    onClose: () => void;
    camera: CameraContext;
    spot: string | null;
    ranges: PrivacyRangeData[];
}) {
    const [ranges, setRanges] = useState<PrivacyRangeData[]>([NEW_RANGE]);
    const [errors, setErrors] = useState<RangeErrors>({});
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const start = saved.length > 0 ? saved : [NEW_RANGE];

    useEffect(() => {
        if (!open) return;
        setRanges((saved.length > 0 ? saved : [NEW_RANGE]).map((range) => ({ ...range, days: [...range.days] })));
        setErrors({});
        setError(undefined);
        setProcessing(false);
        // Only when it opens: a saved prop change mustn't wipe what is being typed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const dirty = JSON.stringify(ranges) !== JSON.stringify(start);
    const change = (index: number, next: Partial<PrivacyRangeData>) => {
        setRanges((current) => current.map((range, at) => (at === index ? { ...range, ...next } : range)));
        setErrors((current) => ({ ...current, [index]: {} }));
    };

    const submit = () => {
        const found: RangeErrors = {};
        ranges.forEach((range, index) => {
            const problems: RangeErrors[number] = {};
            if (range.days.length === 0) problems.days = 'Choose at least one day.';
            if (!/^\d{2}:\d{2}$/.test(range.from)) problems.from = 'Choose a start time.';
            if (!/^\d{2}:\d{2}$/.test(range.to)) problems.to = 'Choose an end time.';
            if (Object.keys(problems).length > 0) found[index] = problems;
        });
        setErrors(found);
        if (Object.keys(found).length > 0) return;
        setProcessing(true);
        setError(undefined);
        router.put(
            `/security-devices/devices/${camera.id}/camera/privacy-hours`,
            { enabled: true, ranges: ranges.map((range) => ({ days: [...range.days].sort((a, b) => a - b), from: range.from, to: range.to })) },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (serverErrors) => {
                    const messages = Object.values(serverErrors).map(String);
                    setError(messages[0] ?? 'Privacy hours couldn’t be saved. Try again.');
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={EyeOff}
            title="Privacy hours"
            description="Turn the picture off at set times. Nothing is shown in Oblivion then"
            context={{ title: camera.name, tag: camera.site ?? undefined, note: [camera.model, spot].filter(Boolean).join(' · ') || undefined, icon: Cctv }}
            error={error}
            submitLabel="Save privacy hours"
            processingLabel="Saving…"
            processing={processing}
            dirty={dirty}
            draftName={`Privacy hours for ${camera.name}`}
            onSubmit={submit}
            testId="sd-privacy-hours"
        >
            {ranges.map((range, index) => (
                <PopupSection
                    key={index}
                    icon={ranges.length > 1 ? Clock3 : undefined}
                    title={ranges.length > 1 ? `Time ${index + 1}` : undefined}
                    columns={2}
                >
                    <PopupField label="Days" required wide error={errors[index]?.days}>
                        <DayChips
                            label={ranges.length > 1 ? `Days for time ${index + 1}` : 'Days'}
                            value={range.days}
                            onChange={(next) => change(index, { days: next })}
                            invalid={!!errors[index]?.days}
                        />
                    </PopupField>
                    {/* Top-aligned, so From doesn't stretch beside To's hint. */}
                    <div className="grid items-start gap-x-[18px] gap-y-3 sm:col-span-full sm:grid-cols-2">
                        <PopupField label="From" htmlFor={`sd-privacy-from-${index}`} required error={errors[index]?.from}>
                            <TimePicker
                                id={`sd-privacy-from-${index}`}
                                label={`From, time ${index + 1}`}
                                value={range.from}
                                onChange={(value) => change(index, { from: value })}
                                invalid={!!errors[index]?.from}
                            />
                        </PopupField>
                        <PopupField
                            label="To"
                            htmlFor={`sd-privacy-to-${index}`}
                            required
                            hint={range.from === range.to ? '24 hours' : endsNextDay(range) ? 'the next morning' : undefined}
                            error={errors[index]?.to}
                        >
                            <TimePicker
                                id={`sd-privacy-to-${index}`}
                                label={`To, time ${index + 1}`}
                                value={range.to}
                                onChange={(value) => change(index, { to: value })}
                                invalid={!!errors[index]?.to}
                            />
                        </PopupField>
                    </div>
                    {ranges.length > 1 ? (
                        <div className="sm:col-span-full">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setRanges((current) => current.filter((_, at) => at !== index))}
                            >
                                <Trash2 aria-hidden="true" />
                                Remove time {index + 1}
                            </Button>
                        </div>
                    ) : null}
                </PopupSection>
            ))}
            {ranges.length < 7 ? (
                <div>
                    <Button
                        type="button"
                        variant="link"
                        className="h-auto p-0"
                        onClick={() => setRanges((current) => [...current, { ...NEW_RANGE, days: [...NEW_RANGE.days] }])}
                    >
                        <Plus aria-hidden="true" />
                        Add another time
                    </Button>
                </div>
            ) : null}
            <p className="text-caption">
                No picture is shown in Oblivion during these hours: not on camera tiles, the camera page or live. The recorder keeps its own
                recording schedule.
            </p>
            <PopupCallout tone="info">
                Cameras are never put in bedrooms or bathrooms. Hallway and shared-space cameras can have privacy hours.
            </PopupCallout>
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  Turn privacy hours off                                             */
/* ------------------------------------------------------------------ */

/** Loosens a privacy safeguard, so it confirms and the main button is red. The times are kept. */
export function TurnOffPrivacyHours({
    open,
    onClose,
    camera,
    ranges,
}: {
    open: boolean;
    onClose: () => void;
    camera: CameraContext;
    ranges: PrivacyRangeData[];
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    useEffect(() => {
        if (open) setError(undefined);
    }, [open]);

    const confirm = () => {
        setProcessing(true);
        router.put(
            `/security-devices/devices/${camera.id}/camera/privacy-hours`,
            { enabled: false, ranges },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (serverErrors) => setError(Object.values(serverErrors).map(String)[0] ?? 'They couldn’t be turned off. Try again.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={confirm}
            variant="warning"
            icon={Eye}
            title="Turn off privacy hours?"
            description={`Pictures from ${camera.name} can then be shown at any time of day. The days and times are kept for next time.`}
            context={{ title: camera.name, tag: camera.site ?? undefined, note: ranges.length > 0 ? rangesWords(ranges) : 'Privacy hours', icon: Cctv }}
            confirmLabel="Turn off privacy hours"
            confirmDestructive
            processing={processing}
            processingLabel="Turning off…"
            error={error}
            testId="sd-privacy-off"
        />
    );
}
