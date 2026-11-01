/**
 * The camera's Live tab (drawing pg-sdr-4): the latest picture when someone asks for it, Watch
 * live (a reason first, pop-up 78, then the full view, pop-up 16), who watched last and why, and
 * the camera's facts. Inside privacy hours the frame says so and no picture is asked for; the
 * server refuses one anyway. Oblivion never keeps the picture.
 */
import { CameraCertificateNotice, type CameraCertificateTrust } from '@/components/security-devices/camera-certificate-notice';
import { FactRows, Log, Note, QuietLine, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { privacyUntilWords } from '@/lib/security-devices/camera-privacy';
import { seenTime } from '@/lib/security-devices/device-time';
import axios from 'axios';
import { Cctv, Eye, EyeOff, Info, LoaderCircle, RefreshCw, VideoOff } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { CameraLive, CameraPageProps } from './_camera-page-types';
import { logItems, MainSide } from './_device-page-parts';

type PictureReply = {
    ok: boolean;
    pending?: boolean;
    status_url?: string | null;
    reason_code?: string;
    message?: string;
    data_uri?: string | null;
    captured_at?: string | null;
    source?: string | null;
    privacy_until?: string | null;
    tls_trust?: CameraCertificateTrust | null;
};

export type Picture =
    | { status: 'idle' }
    | { status: 'loading'; message: string }
    | { status: 'shown'; dataUri: string; capturedAt: string | null; source: string | null }
    | { status: 'private'; until: string | null }
    | { status: 'failed'; message: string; trust: CameraCertificateTrust | null };

const HUB_POLL_MS = 2000;
const HUB_MAX_POLLS = 45;

function fromReply(reply: PictureReply): Picture {
    if (reply.reason_code === 'privacy_hours') return { status: 'private', until: reply.privacy_until ?? null };
    if (reply.ok && reply.data_uri) return { status: 'shown', dataUri: reply.data_uri, capturedAt: reply.captured_at ?? null, source: reply.source ?? null };
    return { status: 'failed', message: reply.message || 'The camera didn’t send a picture.', trust: reply.tls_trust ?? null };
}

function replyOf(error: unknown): PictureReply | null {
    return axios.isAxiosError(error) && error.response?.data && typeof error.response.data === 'object' ? (error.response.data as PictureReply) : null;
}

/**
 * The latest picture, asked for by hand: straight from the camera, or through the Site hub (it
 * checks back until the hub's picture arrives). Every request is in the audit log.
 */
export function useLatestPicture(deviceId: number) {
    const [picture, setPicture] = useState<Picture>({ status: 'idle' });
    const run = useRef(0);
    useEffect(() => () => void run.current++, []);

    const ask = async () => {
        const id = ++run.current;
        const current = () => id === run.current;
        setPicture({ status: 'loading', message: 'Getting the latest picture…' });
        try {
            const { data } = await axios.post<PictureReply>(`/security-devices/devices/${deviceId}/camera/snapshot`, {}, { headers: { Accept: 'application/json' } });
            if (!current()) return;
            if (data.pending && data.status_url) {
                setPicture({ status: 'loading', message: data.message || 'Checking through the Site hub…' });
                for (let poll = 0; poll < HUB_MAX_POLLS; poll += 1) {
                    await new Promise((resolve) => window.setTimeout(resolve, HUB_POLL_MS));
                    if (!current()) return;
                    try {
                        const { data: status } = await axios.get<PictureReply>(data.status_url, { headers: { Accept: 'application/json' } });
                        if (!current()) return;
                        if (!status.pending) {
                            setPicture(fromReply(status));
                            return;
                        }
                    } catch (error) {
                        if (current()) setPicture(fromReply(replyOf(error) ?? { ok: false, message: 'The Site hub didn’t send the picture.' }));
                        return;
                    }
                }
                setPicture({ status: 'failed', message: 'The Site hub didn’t send the picture in time. It may be offline; try again.', trust: null });
                return;
            }
            setPicture(fromReply(data));
        } catch (error) {
            if (current()) setPicture(fromReply(replyOf(error) ?? { ok: false, message: 'The picture couldn’t be fetched. Try again.' }));
        }
    };

    const clear = () => {
        run.current++;
        setPicture({ status: 'idle' });
    };

    return { picture, ask, clear };
}

export const LIVE_REASON_WORDS: Record<NonNullable<CameraLive['reason']>, string> = {
    privacy_hours: 'Privacy hours are on, so there’s no live picture until they end',
    not_set_up: 'Live viewing isn’t set up for this camera yet',
    no_access: 'Your account can’t watch cameras live',
    unavailable: 'Live viewing isn’t available right now; the camera or its hub isn’t ready',
};

export function CameraLiveTab({
    props,
    now,
    onWatch,
}: {
    props: CameraPageProps;
    now: Date;
    onWatch: () => void;
}) {
    const { device, can, privacy, live } = props;
    const overview = props.overview;
    const { picture, ask, clear } = useLatestPicture(device.id);

    // A picture already on screen goes the moment privacy hours begin.
    useEffect(() => {
        if (privacy.active) clear();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [privacy.active]);

    const until = privacy.active ? privacy.until : picture.status === 'private' ? picture.until : null;
    const isPrivate = privacy.active || picture.status === 'private';
    const sub = live?.via ? `Through ${live.via}` : live?.reason === 'not_set_up' || !live ? 'Live viewing isn’t set up for this camera' : 'Live picture';

    let frame;
    if (isPrivate) {
        frame = (
            <FrameMessage icon={EyeOff} title={privacyUntilWords(until, now)}>
                No picture is shown until then, on any screen.
            </FrameMessage>
        );
    } else if (!can.see_picture) {
        frame = <FrameMessage icon={VideoOff} title="No picture for you here">Your account can’t see this camera’s picture.</FrameMessage>;
    } else if (picture.status === 'shown') {
        frame = (
            <>
                <img src={picture.dataUri} alt={`Latest picture from ${device.name}`} className="h-full w-full object-contain" />
                <span className="absolute top-3 left-3 rounded-md bg-background/85 px-2 py-1 font-mono text-xs text-foreground">
                    {device.name}
                    {picture.capturedAt ? ` · ${formatDateTime(picture.capturedAt)}` : ''}
                </span>
            </>
        );
    } else if (picture.status === 'loading') {
        frame = (
            <FrameMessage icon={LoaderCircle} spin title={picture.message}>
                The picture is shown once and isn’t kept.
            </FrameMessage>
        );
    } else if (picture.status === 'failed') {
        frame = (
            <FrameMessage icon={VideoOff} title="No picture">
                {picture.message}
            </FrameMessage>
        );
    } else {
        frame = (
            <FrameMessage icon={Cctv} title="Latest picture">
                Oblivion doesn’t keep camera pictures. Ask for the latest one when you need it.
            </FrameMessage>
        );
    }

    const lastView = overview?.last_view ?? null;

    const main = (
        <>
            <Note icon={Info}>A reason is asked before every live view, and kept with your name.</Note>
            <Surface title="Live" sub={sub}>
                <div className="grid gap-3 px-[18px] pt-3 pb-4">
                    <div>
                        <div className="relative aspect-video w-full overflow-hidden rounded-[12px] border border-border bg-muted">{frame}</div>
                    </div>
                    {picture.status === 'failed' && picture.trust ? (
                        <CameraCertificateNotice trust={picture.trust} onTrusted={() => clear()} />
                    ) : null}
                    <div className="flex flex-wrap items-center gap-2">
                        {can.watch_live ? (
                            <Button type="button" onClick={onWatch}>
                                <Eye aria-hidden="true" />
                                Watch live
                            </Button>
                        ) : null}
                        {can.see_picture && !isPrivate ? (
                            <Button type="button" variant="outline" onClick={() => void ask()} disabled={picture.status === 'loading'}>
                                <RefreshCw aria-hidden="true" />
                                {picture.status === 'shown' ? 'Show a newer picture' : 'Show the latest picture'}
                            </Button>
                        ) : null}
                        {!can.watch_live && live?.reason ? <span className="text-subtle">{LIVE_REASON_WORDS[live.reason]}</span> : null}
                    </div>
                    {can.see_picture ? (
                        <p className="text-caption text-right">
                            {lastView ? (
                                <>
                                    Last watched live {lastView.at ? seenTime(lastView.at, now) : ''} · reason: <b className="font-semibold">{lastView.reason}</b> ·{' '}
                                    {lastView.who}
                                </>
                            ) : (
                                'Nobody has watched this camera live yet'
                            )}
                        </p>
                    ) : null}
                </div>
            </Surface>
        </>
    );

    const side = overview ? (
        <>
            <FactRows title="About this camera" rows={overview.facts} />
            {overview.events.length > 0 ? (
                <Log title="Recent" items={logItems(overview.events, now)} />
            ) : (
                <QuietLine title="Recent" text={can.see_events ? 'Nothing has happened at this camera lately.' : 'You can’t see this camera’s events.'} />
            )}
            <FactRows title="For IT" rows={overview.it} />
        </>
    ) : null;

    return <MainSide main={main} side={side} />;
}

function FrameMessage({ icon: Icon, title, spin, children }: { icon: typeof Cctv; title: string; spin?: boolean; children: ReactNode }) {
    return (
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 p-5 text-center">
            <Icon aria-hidden="true" className={spin ? 'size-7 animate-spin text-muted-foreground motion-reduce:animate-none' : 'size-7 text-muted-foreground'} />
            <p role="status" className="text-sm font-semibold">
                {title}
            </p>
            <p className="text-subtle max-w-md">{children}</p>
        </div>
    );
}

