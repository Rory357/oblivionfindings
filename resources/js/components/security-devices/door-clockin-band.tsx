/**
 * Clock-ins from doors on My Day (S&D Phase H, decision D15): the band after a badge near a
 * rostered start (drawing pg-sdr-19g), the same choice as pop-up 88 (opened from the app notice),
 * and what the person was told when it was turned on (Privacy Act IPP3).
 *
 * Nothing is clocked in until they choose. "Clock in at 6:52 am" uses the badge's own time,
 * through attendance's own clock-in with every rule it keeps; "Later" just closes the pop-up.
 */
import { PopupCallout, PopupForm } from '@/components/popup';
import { Ring, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { formatTime, toDateInput } from '@/lib/datetime';
import { formatDay, shiftWords, type MyDayClockin } from '@/lib/security-devices/door-clockins';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Clock, DoorOpen, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

type Choice = 'badge' | 'now' | 'not_working';

const NOTICE_PARAM = 'door_clockin';

function failureWords(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { errors?: Record<string, string[]>; message?: string } | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        if (first) return first;
        if (error.response?.status === 404) return 'This suggestion has closed. Clock in the usual way if you’re working.';
        if (error.response?.status === 403) return 'You can’t clock in from here. Ask your manager.';
        if (!error.response) return 'Result not confirmed. Check your clock-in on My Day before trying again.';
    }
    return 'You weren’t clocked in. Try again, or clock in the usual way.';
}

function doneWords(choice: Choice, data: MyDayClockin): string {
    if (choice === 'badge') return `Clocked in at ${formatTime(data.badge_at)}`;
    if (choice === 'now') return 'Clocked in';
    return 'Thanks, nothing was clocked in';
}

/** What the person's own answer does, shared by the band and pop-up 88. */
function useAnswer(data: MyDayClockin, onDone?: () => void) {
    const [busy, setBusy] = useState<Choice | null>(null);
    const [error, setError] = useState<string | null>(null);
    const answer = async (choice: Choice) => {
        if (busy) return;
        setBusy(choice);
        setError(null);
        try {
            await axios.post(`/door-clockins/${data.id}/answer`, { choice });
            toast.success(doneWords(choice, data));
            onDone?.();
            router.reload();
        } catch (failure) {
            setError(failureWords(failure));
        } finally {
            setBusy(null);
        }
    };
    return { busy, error, answer };
}

/** "your card", "your PIN", "your phone". */
function usesWords(method: string): string {
    return `Uses the door, the time and your ${method}. Never your face or fingerprint.`;
}

/** The band on My Day (19g). */
export function DoorClockinBand({ data }: { data: MyDayClockin }) {
    const [open, setOpen] = useState(false);
    const { busy, error, answer } = useAnswer(data);
    const badge = formatTime(data.badge_at);
    const start = data.shift.starts_at ? formatTime(data.shift.starts_at) : null;

    // The app notice links to My Day with ?door_clockin=ID: open pop-up 88 for it.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get(NOTICE_PARAM) === String(data.id)) {
            setOpen(true);
            params.delete(NOTICE_PARAM);
            const query = params.toString();
            window.history.replaceState(window.history.state, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
        }
    }, [data.id]);

    return (
        <>
            <Surface aria-label="Clock-in suggestion" className="mb-5">
                <div className="flex flex-wrap items-center gap-4 px-[18px] py-4">
                    <span className="popup-lens" aria-hidden="true">
                        <DoorOpen className="size-5" />
                    </span>
                    <div className="grid min-w-0 flex-1 basis-80 gap-1">
                        <p className="text-caption flex items-center gap-2 font-semibold">
                            <Ring tone="info" />
                            From your badge · {badge}
                        </p>
                        <h2 className="text-section-title">
                            You badged in at {data.place} at {badge}. Clock in for your {start ? `${start} ` : ''}shift?
                        </h2>
                        <p className="text-subtle">Nothing is clocked in until you choose. Your manager approves your timesheet as usual.</p>
                        {error ? (
                            <p role="alert" className="text-sm text-status-critical">
                                {error}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" disabled={busy !== null} onClick={() => answer('not_working')}>
                            I’m not working
                        </Button>
                        {data.badge_time ? (
                            <>
                                <Button type="button" variant="outline" disabled={busy !== null} onClick={() => answer('now')}>
                                    {busy === 'now' ? 'Clocking in…' : 'Clock in now instead'}
                                </Button>
                                <Button type="button" disabled={busy !== null} onClick={() => answer('badge')}>
                                    {busy === 'badge' ? 'Clocking in…' : `Clock in at ${badge}`}
                                </Button>
                            </>
                        ) : (
                            <Button type="button" disabled={busy !== null} onClick={() => answer('now')}>
                                {busy === 'now' ? 'Clocking in…' : 'Clock in now'}
                            </Button>
                        )}
                    </div>
                </div>
                <p className="flex items-center gap-2.5 border-t border-border bg-muted/40 px-[18px] py-2.5 text-sm text-muted-foreground">
                    <Ring tone="none" />
                    {usesWords(data.method)}
                </p>
            </Surface>
            <DoorClockinPopup data={data} open={open} onClose={() => setOpen(false)} />
        </>
    );
}

/** Pop-up 88: the same choice, opened from the app notice. */
export function DoorClockinPopup({ data, open, onClose }: { data: MyDayClockin; open: boolean; onClose: () => void }) {
    const page = usePage<{ auth?: { user?: { name?: string } | null } }>();
    const { busy, error, answer } = useAnswer(data, onClose);
    const badge = formatTime(data.badge_at);
    const start = data.shift.starts_at ? formatTime(data.shift.starts_at) : null;
    const shift = shiftWords(data.shift);
    const name = page.props.auth?.user?.name ?? 'You';

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Clock}
            title={`Clock in for your ${start ? `${start} ` : ''}shift?`}
            description={`You badged in at ${data.place} at ${badge}.`}
            context={{ title: name, tag: data.site || undefined, note: `Rostered today ${shift}`, icon: UserRound }}
            error={error ?? undefined}
            cancelLabel="Later"
            submitLabel={data.badge_time ? `Clock in at ${badge}` : 'Clock in now'}
            processing={busy !== null}
            processingLabel="Clocking in…"
            onSubmit={() => answer(data.badge_time ? 'badge' : 'now')}
            footerStart={
                <Button type="button" variant="ghost" disabled={busy !== null} onClick={() => answer('not_working')}>
                    I’m not working
                </Button>
            }
            secondary={
                data.badge_time ? (
                    <Button type="button" variant="outline" disabled={busy !== null} onClick={() => answer('now')}>
                        Clock in now instead
                    </Button>
                ) : undefined
            }
            testId="door-clockin-88"
        >
            <dl className="grid gap-x-[18px] gap-y-3 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <dt className="text-sm font-semibold">Your badge</dt>
                    <dd className="text-sm">
                        {data.door}, {badge} · {data.method}
                    </dd>
                </div>
                <div className="grid gap-1.5">
                    <dt className="text-sm font-semibold">Your shift</dt>
                    <dd className="text-sm">
                        {shift}
                        {data.site ? ` · ${data.site}` : ''}
                    </dd>
                </div>
            </dl>
            <PopupCallout tone="info">
                Nothing is clocked in until you choose. Your manager approves your timesheet as usual. Faces and fingerprints are never used.
            </PopupCallout>
        </PopupForm>
    );
}

/** "What you were told": the notice exactly as it was sent, beside the band (19g side). */
export function DoorClockinNotice({ data }: { data: MyDayClockin }) {
    if (!data.notice) return null;
    const sent = toDateInput(data.notice.sent_at);
    const how = data.notice.by_email ? 'in the app and by email' : 'in the app';

    // Word for word, as it was sent: it's the privacy notice (IPP3), so the page never rewrites it.
    return (
        <Surface title="What you were told" sub={`Sent ${formatDay(sent, true)} by ${data.notice.by}, ${how}`}>
            <p className="px-[18px] pt-1 pb-4 text-sm leading-relaxed whitespace-pre-line">{data.notice.body}</p>
        </Surface>
    );
}
