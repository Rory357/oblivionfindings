import { PageHeader } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import { Camera, Loader2, Play, Square, VideoOff } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type FormEvent,
} from 'react';
import { cctvSessionReply } from './live-contract';
import { cctvJson, stopCctv } from './live-transport';
import { CctvViewer, type CctvViewerState } from './live-viewer';

export type CctvLiveProps = {
    actor_id: number;
    reference_href?: string | null;
    camera: { id: number; name: string; site: { id: number; name: string } };
    live: {
        available: boolean;
        reason_code: string | null;
        can_start: boolean;
        source_revision: number | null;
        transport: 'webrtc';
        video_codec: 'H264';
        audio_enabled: false;
        max_session_seconds: number;
        lease_seconds: 15;
        start_href: string | null;
        setup_href: string | null;
        setup_ready: boolean;
        streaming: false;
    };
    recording_references: { state: 'not_requested'; reference: null };
};
type StartRequest = {
    expected_actor_id: number;
    request_key: string;
    source_revision: number;
    purpose: string;
};
type ViewState =
    | CctvViewerState
    | {
          status: 'idle' | 'checking';
          message: string;
          receivedAt: string | null;
      };
export default function CctvLive(props: CctvLiveProps) {
    return (
        <CameraLiveWorkspace
            key={
                props.actor_id +
                ':' +
                props.camera.id +
                ':' +
                props.live.source_revision
            }
            {...props}
        />
    );
}
export function CameraLiveWorkspace({
    actor_id,
    camera,
    live,
    reference_href,
}: CctvLiveProps) {
    const video = useRef<HTMLVideoElement>(null),
        viewer = useRef<CctvViewer | null>(null),
        call = useRef<AbortController | null>(null);
    const generation = useRef(0),
        mounted = useRef(true),
        draft = useRef<StartRequest | null>(null);
    const [purpose, setPurpose] = useState(''),
        [busy, setBusy] = useState(false),
        [uncertain, setUncertain] = useState(false);
    const [state, setState] = useState<ViewState>({
        status: 'idle',
        message: 'Start a live view when you need to check this camera.',
        receivedAt: null,
    });
    const destroy = useCallback(() => {
        mounted.current = false;
        generation.current++;
        call.current?.abort();
        viewer.current?.stop();
    }, []);
    useEffect(() => {
        mounted.current = true;
        return destroy;
    }, [destroy]);
    function stop() {
        generation.current++;
        call.current?.abort();
        call.current = null;
        viewer.current?.stop();
        viewer.current = null;
        draft.current = null;
        setBusy(false);
        setUncertain(false);
        setState((previous) => ({
            status: 'stopped',
            message: 'Live view stopped.',
            receivedAt: previous.receivedAt,
        }));
    }
    async function start(event: FormEvent) {
        event.preventDefault();
        if (
            busy ||
            !video.current ||
            !live.can_start ||
            !live.available ||
            !live.source_revision ||
            live.start_href !==
                '/security-devices/devices/' + camera.id + '/live-sessions'
        )
            return;
        const clean = purpose.trim();
        if (
            !draft.current &&
            ([...clean].length < 10 ||
                [...clean].length > 500 ||
                /[\p{Cc}\p{Cf}]/u.test(clean))
        ) {
            setState({
                status: 'idle',
                message:
                    'Enter a short reason for viewing this camera (10–500 characters).',
                receivedAt: null,
            });
            return;
        }
        if (!draft.current)
            draft.current = {
                expected_actor_id: actor_id,
                request_key: crypto.randomUUID(),
                source_revision: live.source_revision,
                purpose: clean,
            };
        const request = draft.current,
            id = ++generation.current,
            controller = new AbortController();
        call.current = controller;
        setBusy(true);
        setUncertain(false);
        setState({
            status: 'checking',
            message: 'Checking viewing permission…',
            receivedAt: null,
        });
        const started = performance.now();
        try {
            const response = await cctvJson(
                live.start_href,
                controller.signal,
                request,
            );
            const session = cctvSessionReply(response, actor_id, camera.id);
            if (
                !mounted.current ||
                id !== generation.current ||
                !video.current
            ) {
                stopCctv(session);
                return;
            }
            const active = new CctvViewer(
                session,
                video.current,
                started,
                (next) => {
                    if (!mounted.current || id !== generation.current) return;
                    setState(next);
                    if (
                        ['stopped', 'expired', 'unavailable'].includes(
                            next.status,
                        )
                    ) {
                        draft.current = null;
                        setUncertain(false);
                    }
                },
            );
            viewer.current = active;
            active.start();
        } catch {
            if (mounted.current && id === generation.current) {
                setUncertain(true);
                setState({
                    status: 'unavailable',
                    message:
                        'The request could not be confirmed. Check the same request before starting another connection.',
                    receivedAt: null,
                });
            }
        } finally {
            if (mounted.current && id === generation.current) {
                call.current = null;
                setBusy(false);
            }
        }
    }
    const active = ['checking', 'connecting', 'live'].includes(state.status);
    const label =
        state.status === 'live'
            ? 'Live'
            : state.status === 'connecting' || state.status === 'checking'
              ? 'Connecting'
              : state.status === 'expired'
                ? 'Expired'
                : state.status === 'unavailable'
                  ? 'Unavailable'
                  : state.status === 'stopped'
                    ? 'Stopped'
                    : 'Not viewing';
    const ready = live.available && live.can_start && live.setup_ready;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: 'Security & Devices',
                    href: '/security-devices/security?tab=cctv',
                },
                {
                    title: camera.name,
                    href: '/security-devices/devices/' + camera.id,
                },
                {
                    title: 'Live view',
                    href: '/security-devices/devices/' + camera.id + '/live',
                },
            ]}
        >
            <Head title={camera.name + ' · Live view'} />
            <div className="space-y-5">
                <PageHeader
                    variant="profile"
                    icon={Camera}
                    title={camera.name}
                    wrapTitle
                    backHref={'/security-devices/devices/' + camera.id}
                    subline={camera.site.name + ' · Live camera view'}
                    actions={
                        <Button
                            asChild
                            variant="secondary"
                            className="frontline-focus frontline-tap"
                        >
                            <Link
                                href={'/security-devices/devices/' + camera.id}
                            >
                                Camera details
                            </Link>
                        </Button>
                    }
                />
                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
                    <Card className="min-w-0 gap-0 overflow-hidden p-0">
                        <CardHeader className="flex-row items-center justify-between gap-3 px-5 py-4">
                            <CardTitle className="text-section-title">
                                Live view
                            </CardTitle>
                            <StatusBadge
                                variant={
                                    state.status === 'live'
                                        ? 'success'
                                        : state.status === 'unavailable' ||
                                            state.status === 'expired'
                                          ? 'warning'
                                          : 'neutral'
                                }
                            >
                                {label}
                            </StatusBadge>
                        </CardHeader>
                        <div className="relative aspect-video overflow-hidden bg-muted">
                            <video
                                ref={video}
                                muted
                                playsInline
                                aria-label={
                                    'Live video from ' +
                                    camera.name +
                                    ', without audio'
                                }
                                className="h-full w-full object-contain"
                            />
                            {state.status !== 'live' && (
                                <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-muted p-5 text-center">
                                    {active ? (
                                        <Loader2
                                            className="h-7 w-7 animate-spin motion-reduce:animate-none"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <VideoOff
                                            className="h-7 w-7 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <p
                                        className="max-w-md text-sm"
                                        role="status"
                                    >
                                        {ready
                                            ? state.message
                                            : 'Live viewing is not available for this camera.'}
                                    </p>
                                </div>
                            )}
                        </div>
                        <CardContent className="space-y-3 px-5 py-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-caption text-muted-foreground">
                                    {state.receivedAt
                                        ? 'Last frame received ' +
                                          formatDateTime(state.receivedAt)
                                        : 'No video received in this view.'}
                                </p>
                                {(active || uncertain) && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="frontline-focus frontline-tap"
                                        onClick={stop}
                                    >
                                        <Square aria-hidden="true" />
                                        Stop viewing
                                    </Button>
                                )}
                            </div>
                            <p className="text-caption text-muted-foreground">
                                Video only. This view does not make a recording.
                                Leaving this page stops the connection.
                            </p>
                        </CardContent>
                    </Card>
                    <div className="space-y-5">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    Viewing access
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {ready ? (
                                    <form
                                        onSubmit={start}
                                        className="space-y-3"
                                    >
                                        <Label htmlFor="cctv-purpose">
                                            Reason for viewing
                                        </Label>
                                        <Textarea
                                            id="cctv-purpose"
                                            value={purpose}
                                            onChange={(event) =>
                                                setPurpose(event.target.value)
                                            }
                                            disabled={active || uncertain}
                                            maxLength={1000}
                                            rows={3}
                                            placeholder="For example, checking a reported concern at the entrance."
                                        />
                                        <p className="text-caption text-muted-foreground">
                                            Your access and reason are recorded.
                                            View only what you need for your
                                            work.
                                        </p>
                                        {!active && (
                                            <Button
                                                type="submit"
                                                className="frontline-focus frontline-tap w-full"
                                                disabled={busy}
                                            >
                                                <Play aria-hidden="true" />
                                                {uncertain
                                                    ? 'Check connection'
                                                    : 'Start live view'}
                                            </Button>
                                        )}
                                    </form>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {live.reason_code === 'restricted'
                                            ? 'You do not have permission to view this camera. Contact your administrator if you need access.'
                                            : 'An authorised administrator needs to check this camera’s source and Site collector before live viewing is available.'}
                                    </p>
                                )}
                                {live.setup_href ===
                                    '/security-devices/devices/' +
                                        camera.id +
                                        '/live/source' && (
                                    <Button
                                        variant="outline"
                                        asChild
                                        className="frontline-focus frontline-tap"
                                    >
                                        <Link href={live.setup_href}>
                                            Camera setup
                                        </Link>
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    Incident recordings
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm text-muted-foreground">
                                {reference_href ===
                                    '/security-devices/devices/' +
                                        camera.id +
                                        '/recording-references' && (
                                    <Button
                                        variant="outline"
                                        asChild
                                        className="frontline-focus frontline-tap"
                                    >
                                        <Link href={reference_href}>
                                            Recording references
                                        </Link>
                                    </Button>
                                )}
                                <p>
                                    Live view shows what is happening now.
                                    Recordings remain with the camera provider.
                                </p>
                                <p>
                                    No recording reference has been requested
                                    for an incident time in this view.
                                </p>
                                <p>
                                    A recording reference does not confirm that
                                    footage exists or has been preserved.
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
