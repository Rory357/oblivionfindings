import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateOnly } from '@/lib/datetime';
import { Radio, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { privateHeaders, readJson, type ZoneDraft } from './types';

export default function ZoneMonitoringDialog({
    zone,
    url,
    fingerprint,
    onClose,
    onSaved,
    onAccessEnded,
}: {
    zone: ZoneDraft;
    url: string;
    fingerprint: string;
    onClose: () => void;
    onSaved: (zone: ZoneDraft) => void;
    onAccessEnded: () => void;
}) {
    const pause = zone.monitoring?.status === 'active';
    const [reviewed, setReviewed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [key] = useState(() => crypto.randomUUID());
    const request = useRef<AbortController | null>(null);
    useEffect(() => () => request.current?.abort(), []);
    async function save() {
        setBusy(true);
        setError('');
        const abort = new AbortController();
        request.current = abort;
        try {
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const xsrf = document.cookie
                .split('; ')
                .find((item) => item.startsWith('XSRF-TOKEN='))
                ?.split('=')
                .slice(1)
                .join('=');
            const response = await fetch(`${url}/${zone.id}/monitoring`, {
                method: 'POST',
                credentials: 'same-origin',
                signal: abort.signal,
                headers: {
                    ...privateHeaders,
                    'Content-Type': 'application/json',
                    ...(csrf
                        ? { 'X-CSRF-TOKEN': csrf }
                        : xsrf
                          ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                          : {}),
                },
                body: JSON.stringify({
                    action: pause ? 'pause' : 'activate',
                    monitor_id: zone.monitoring?.id ?? null,
                    expected_revision: zone.revision,
                    access_fingerprint: fingerprint,
                    idempotency_key: key,
                    reviewed: true,
                }),
            });
            if (abort.signal.aborted) return;
            if (response.status === 403) {
                onAccessEnded();
                return;
            }
            const result = await readJson<{ zone: ZoneDraft }>(response);
            if (!abort.signal.aborted) onSaved(result.zone);
        } catch (failure) {
            if (!abort.signal.aborted)
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'Monitoring could not be changed. Try again.',
                );
        } finally {
            if (!abort.signal.aborted) setBusy(false);
        }
    }
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <DialogContent className="client-location-workspace zone-monitoring-dialog max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {pause
                            ? 'Pause zone monitoring'
                            : 'Send zone alerts to Control Room'}
                    </DialogTitle>
                    <DialogDescription>
                        {zone.name} · Revision {zone.revision}
                    </DialogDescription>
                </DialogHeader>
                <div className="location-message flex items-center gap-3">
                    <Radio className="size-5 shrink-0" />
                    <div>
                        <strong>Control Room · High priority</strong>
                        <p>
                            The Control Room team acknowledges, investigates and
                            resolves each alert.
                        </p>
                    </div>
                </div>
                {pause ? (
                    <p>
                        New location reports will stop raising alerts for this
                        zone. Existing Control Room alerts remain open for the
                        team to resolve. You can edit the zone after pausing.
                    </p>
                ) : (
                    <>
                        <div>
                            <strong className="flex items-center gap-2">
                                <ShieldCheck className="size-4" />
                                {zone.classification === 'agreed'
                                    ? 'Alert when reported outside this zone'
                                    : 'Alert when reported inside this zone'}
                            </strong>
                            <p className="location-subtle mt-2">
                                The first qualifying report can raise an alert.
                                Each zone is checked separately. A return report
                                ends the breach episode, but does not resolve
                                the Control Room alert.
                            </p>
                        </div>
                        <div className="location-zone">
                            <strong>Scheduled monitoring</strong>
                            <p>
                                {zone.schedule.weekdays
                                    .map(
                                        (day) =>
                                            [
                                                'Mon',
                                                'Tue',
                                                'Wed',
                                                'Thu',
                                                'Fri',
                                                'Sat',
                                                'Sun',
                                            ][day - 1],
                                    )
                                    .join(', ')}{' '}
                                · {zone.schedule.start}–{zone.schedule.end}
                                {zone.schedule.following_day ? ' next day' : ''}
                            </p>
                            <p>
                                {formatDateOnly(zone.schedule.first_date)} –{' '}
                                {formatDateOnly(zone.schedule.last_date)} ·
                                Pacific/Auckland
                            </p>
                            <p>
                                {zone.schedule.exception_dates.length
                                    ? `Excluded start dates: ${zone.schedule.exception_dates.map((date) => formatDateOnly(date)).join(', ')}`
                                    : 'No excluded dates'}
                            </p>
                        </div>
                        <div>
                            <strong>Control Room response instructions</strong>
                            <p className="mt-2 whitespace-pre-wrap">
                                {zone.response_proposal ||
                                    'Add response instructions by editing this draft first.'}
                            </p>
                        </div>
                        <p className="location-subtle">
                            Monitoring uses new tracker reports. It waits for a
                            usable GPS fix and does not poll the unit or detect
                            a breach while the unit is offline. Reports more
                            than five minutes old are ignored. The saved
                            boundary stays fixed until you pause, edit and
                            activate again.
                        </p>
                        <label className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border p-3">
                            <Checkbox
                                className="mt-1"
                                checked={reviewed}
                                onCheckedChange={(value) =>
                                    setReviewed(value === true)
                                }
                            />
                            <span>
                                I have reviewed the boundary, schedule and
                                response instructions.
                            </span>
                        </label>
                    </>
                )}
                {error && (
                    <p role="alert" className="location-message location-error">
                        {error}
                    </p>
                )}
                <DialogFooter>
                    <Button
                        variant="outline"
                        className="min-h-11"
                        disabled={busy}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        className="min-h-11"
                        disabled={
                            busy ||
                            (!pause &&
                                (!reviewed || !zone.response_proposal?.trim()))
                        }
                        onClick={() => void save()}
                    >
                        {busy
                            ? 'Saving…'
                            : pause
                              ? 'Pause monitoring'
                              : 'Activate monitoring'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
