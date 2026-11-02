/**
 * The camera's Recordings tab (drawing pg-sdr-4b): clip references, newest first. A clip reference
 * says where a clip is on the recorder, why and who saved it; the video stays on the recorder and
 * Oblivion never copies it. "Open" shows the reference.
 *
 * Not built, on purpose: playing a clip and "Who viewed recordings" (Oblivion can't play recorder
 * video yet, so there's no viewing to list), and "Find a moment" (no recorder search).
 */
import { FactRows, Log, Note, QuietLine } from '@/components/signature/blocks';
import { formatDate, formatTime, WORKER_TIMEZONE } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import { Info } from 'lucide-react';
import type { CameraClip, CameraPageProps } from './_camera-page-types';
import { MainSide } from './_device-page-parts';

const nzDay = (iso: string) => new Date(iso).toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE });

/** "9:24 am – 9:26 am", or "11:48 pm – Tue 27 Oct, 12:05 am" when it runs past midnight. */
export function clipWindow(clip: Pick<CameraClip, 'start' | 'end'>, now: Date): string {
    const end = nzDay(clip.start) === nzDay(clip.end) ? formatTime(clip.end) : seenTime(clip.end, now);
    return `${formatTime(clip.start)} – ${end}`;
}

/** The log's time column: "Today" or "Mon 26 Oct". */
export function clipDay(start: string, now: Date): string {
    return nzDay(start) === now.toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE }) ? 'Today' : formatDate(start);
}

export function CameraRecordingsTab({ props, now, onSaveClip }: { props: CameraPageProps; now: Date; onSaveClip: () => void }) {
    const recordings = props.recordings;
    if (!recordings) return null;
    const save = props.can.save_clip ? { label: 'Save a clip', onClick: onSaveClip } : null;

    const main = (
        <>
            <Note icon={Info}>
                <b className="font-semibold">Recordings stay on the recorder.</b> Oblivion keeps where to find a clip, why it was saved and who
                saved it.
            </Note>
            {recordings.unavailable ? (
                <QuietLine title="Clip references" text="Clip references couldn’t be read just now. Try again in a minute." />
            ) : recordings.clips.length === 0 ? (
                <QuietLine title="Clip references" text="No clips have been saved for this camera yet." action={save} />
            ) : (
                <Log
                    title="Clip references"
                    sub="Where to find a clip on the recorder · the video stays on the recorder"
                    action={save}
                    items={recordings.clips.map((clip) => ({
                        key: clip.key,
                        time: clipDay(clip.start, now),
                        tone: 'info' as const,
                        title: `${clipWindow(clip, now)} · ${clip.why}`,
                        sub: clip.by ? `Saved by ${clip.by}` : undefined,
                        action: { label: 'Open', href: clip.href, ariaLabel: `Open the clip reference for ${clipWindow(clip, now)}` },
                    }))}
                    limit={10}
                />
            )}
        </>
    );

    return <MainSide main={main} side={<FactRows title="Recording" rows={recordings.facts} />} />;
}
