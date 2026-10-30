import { Alert, AlertDescription } from '@/components/ui/alert';
import { ErrorState } from '@/components/ui/error-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import type { CurrentRecordingSource } from './_recording-contract';

export const recordingTime = (value: string) => formatDateTimeLong(value);
export function ReferenceUnavailable() {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'All devices', href: '/security-devices/devices' },
            ]}
        >
            <ErrorState
                title="Recording reference unavailable"
                message="Your camera access or the reference details could not be confirmed. Reopen the camera from Security & Devices."
            />
        </AppLayout>
    );
}
export function RecordingSourceNote({
    source,
}: {
    source: CurrentRecordingSource;
}) {
    const text = {
        ready: 'The current camera connection matches the reviewed source.',
        withdrawn:
            'Viewing has been turned off for this source. Saved references do not enable it.',
        changed:
            'The camera connection has changed. Saved references retain their original camera and time window.',
        unavailable:
            'The current camera connection cannot be verified. Saved references do not confirm that footage is available.',
    };
    return (
        <Alert>
            <AlertDescription>{text[source.state]}</AlertDescription>
        </Alert>
    );
}
export function ReferenceLabel() {
    return <StatusBadge variant="info">Reference only</StatusBadge>;
}
export function RequestedWindow({
    start,
    end,
    serverNow,
}: {
    start: string;
    end: string;
    serverNow: string;
}) {
    return (
        <div className="space-y-1">
            <p>{recordingTime(start)}</p>
            <p className="text-muted-foreground">to {recordingTime(end)}</p>
            <p className="text-caption text-muted-foreground">
                Pacific/Auckland
                {Date.parse(end) > Date.parse(serverNow)
                    ? ' · Window still in progress'
                    : ''}
            </p>
        </div>
    );
}
