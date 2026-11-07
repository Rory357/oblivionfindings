/**
 * The care tracker's pop-up (S&D rebuild drawing sdr-19, Send a command to the tracker), on the
 * pop-up kit at the medium width. Four commands in plain words, the exact message the unit gets
 * (password hidden), and a warning only where it matters (Restart). Commands go through today's
 * console path; the server checks who may send which command, wants a reason for the two that
 * change the unit, and refuses "Ask for position now" while the location can't be shown. Only the
 * commands this person may send are offered.
 *
 * Pop-up 76, Add a tracker by its IMEI, isn't built: its setup message carries the unit's password,
 * which Oblivion deliberately doesn't hand out, and a tracker is claimed only after it reports in.
 */
import { PopupCallout, PopupField, PopupForm } from '@/components/popup';
import { ChoiceTiles, type ChoiceTile } from '@/components/security-devices/device-popups';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { TrackerCommandKey, TrackerCommands } from '@/pages/security-devices/devices/_sensor-tracker-types';
import axios from 'axios';
import { ListChecks, LocateFixed, MapPin, RotateCcw, Send, Timer, type LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

const ICONS: Record<TrackerCommandKey, LucideIcon> = {
    request_location: LocateFixed,
    update_reporting_interval: Timer,
    reboot: RotateCcw,
    read_config: ListChecks,
};

/** "1 minute", "5 minutes", "1 hour". */
export function minutesWords(minutes: number): string {
    if (minutes % 60 === 0 && minutes >= 60) {
        const hours = minutes / 60;
        return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    }
    return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
}

/** "every 5 minutes", from seconds. */
export function intervalWords(seconds: number): string {
    return seconds < 60 ? `${seconds} seconds` : minutesWords(Math.round(seconds / 60));
}

/** Why a command can't go now, in words for the person who asked. */
export function commandProblem(error: unknown): { field: 'command' | 'interval_minutes' | 'reason' | null; message: string } {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { errors?: Record<string, string[]> } | undefined;
        if (data?.errors) {
            for (const field of ['reason', 'interval_minutes', 'command'] as const) {
                const first = data.errors[field]?.[0];
                if (first) return { field, message: first };
            }
            const other = Object.values(data.errors)[0]?.[0];
            if (other) return { field: null, message: other };
        }
        if (error.response?.status === 403) return { field: null, message: 'You can’t send commands to this tracker.' };
        if (error.response?.status === 429) return { field: null, message: 'Too many commands in a minute. Wait a moment, then try again.' };
        if (!error.response) return { field: null, message: 'Not sent: Oblivion couldn’t be reached. Check your connection and try again.' };
    }
    return { field: null, message: 'Not sent. Try again in a moment.' };
}

export type TrackerPopupContext = {
    id: number;
    name: string;
    /** "Queclink GL300MG · Hemi Walker · IMEI 8679•••••••6998" */
    note: string;
    /** "Consent active", "Consent not active", or nothing for a tracker not worn by a client. */
    tag: string | null;
    /** The wearer's first name, when the viewer may see it. */
    person: string | null;
};

export function SendTrackerCommand({
    open,
    tracker,
    commands,
    initial,
    onClose,
    onSent,
}: {
    open: boolean;
    tracker: TrackerPopupContext;
    commands: TrackerCommands;
    initial?: TrackerCommandKey | null;
    onClose: () => void;
    onSent: (message: string) => void;
}) {
    const options = commands.options;
    const current = commands.interval !== null ? Math.round(commands.interval / 60) : null;
    const [command, setCommand] = useState<TrackerCommandKey | ''>('');
    const [minutes, setMinutes] = useState('5');
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [problem, setProblem] = useState<ReturnType<typeof commandProblem> | null>(null);

    useEffect(() => {
        if (!open) return;
        const wanted = initial && options.some((option) => option.key === initial) ? initial : (options[0]?.key ?? '');
        setCommand(wanted);
        setMinutes(current !== null && commands.intervals.includes(current) ? String(current) : '5');
        setReason('');
        setProblem(null);
        setProcessing(false);
        // Only when it opens: the choices are the person's after that.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const chosen = options.find((option) => option.key === command) ?? null;
    const message = chosen ? (chosen.key === 'update_reporting_interval' ? (chosen.messages?.[minutes] ?? null) : chosen.message) : null;
    const needsReason = chosen?.risky ?? false;
    const tiles: ChoiceTile<TrackerCommandKey>[] = options.map((option) => ({ value: option.key, label: option.label, description: option.sub, icon: ICONS[option.key] }));
    const whose = tracker.person ? `${tracker.person}’s` : 'Its';

    const send = async () => {
        if (!chosen) {
            setProblem({ field: 'command', message: 'Choose a command.' });
            return;
        }
        if (needsReason && reason.trim().length < 4) {
            setProblem({ field: 'reason', message: 'Say why in a few words. It’s kept in the console history.' });
            return;
        }
        setProcessing(true);
        setProblem(null);
        try {
            const { data } = await axios.post<{ message: string }>(
                `/security-devices/devices/${tracker.id}/tracker/commands`,
                {
                    command: chosen.key,
                    interval_minutes: chosen.key === 'update_reporting_interval' ? Number(minutes) : null,
                    reason: reason.trim() || null,
                },
                { headers: { Accept: 'application/json' } },
            );
            setProcessing(false);
            onSent(data.message);
        } catch (error) {
            setProcessing(false);
            setProblem(commandProblem(error));
        }
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Send}
            title="Send a command to the tracker"
            description="The unit picks it up next time it checks in."
            context={{ title: tracker.name, tag: tracker.tag ?? undefined, note: tracker.note, icon: MapPin }}
            error={problem && problem.field === null ? problem.message : undefined}
            submitLabel={chosen?.key === 'reboot' ? 'Restart the unit' : 'Send command'}
            processingLabel="Sending…"
            processing={processing}
            submitDisabled={!chosen}
            onSubmit={() => void send()}
            dirty={reason.trim() !== ''}
            testId="sd-tracker-command"
        >
            <PopupField label="Command" required error={problem?.field === 'command' ? problem.message : undefined}>
                <ChoiceTiles<TrackerCommandKey> label="Command" columns={tiles.length >= 4 ? 4 : 2} options={tiles} value={command} onChange={setCommand} />
            </PopupField>
            {commands.locate_off ? (
                <p className="text-caption -mt-1">Ask for position now is off while the location can’t be shown.</p>
            ) : null}
            {chosen?.key === 'update_reporting_interval' ? (
                <PopupField label="Report every" htmlFor="tracker-interval" hint="Only for Change report interval" error={problem?.field === 'interval_minutes' ? problem.message : undefined}>
                    <Select value={minutes} onValueChange={setMinutes}>
                        <SelectTrigger id="tracker-interval" className="sm:max-w-[240px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {commands.intervals.map((option) => (
                                <SelectItem key={option} value={String(option)}>
                                    {minutesWords(option)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </PopupField>
            ) : null}
            {chosen ? (
                <PopupField label="Message sent to the unit" hint="Password hidden · its number is added when it’s sent">
                    <code data-testid="sd-tracker-message" className="block rounded-lg bg-muted px-3 py-2 font-mono text-[13px] break-all text-muted-foreground">
                        {message ?? 'Not available for this unit'}
                    </code>
                </PopupField>
            ) : null}
            {chosen?.key === 'reboot' ? (
                <PopupCallout tone="warning">
                    The tracker is out of touch for about 2 minutes while it restarts. {whose} location won’t update in that time.
                </PopupCallout>
            ) : null}
            {chosen ? (
                <PopupField
                    label="Why"
                    htmlFor="tracker-why"
                    required={needsReason}
                    hint={needsReason ? 'Kept in the console history' : 'Optional · kept in the console history'}
                    error={problem?.field === 'reason' ? problem.message : undefined}
                >
                    <Textarea
                        id="tracker-why"
                        value={reason}
                        maxLength={500}
                        rows={3}
                        placeholder={chosen.key === 'reboot' ? 'e.g. It stopped sending positions after 10:05 am' : undefined}
                        onChange={(event) => setReason(event.target.value)}
                    />
                </PopupField>
            ) : null}
            {chosen?.key === 'update_reporting_interval' ? (
                <PopupCallout tone="info">
                    {current !== null
                        ? `It reports every ${intervalWords(commands.interval as number)} now, so it picks this up within ${intervalWords(commands.interval as number)}. `
                        : 'It picks this up next time it checks in. '}
                    Reporting more often runs the battery down faster.
                </PopupCallout>
            ) : null}
        </PopupForm>
    );
}
