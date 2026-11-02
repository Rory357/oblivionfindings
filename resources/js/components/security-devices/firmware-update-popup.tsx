/**
 * Pop-up 62, "Firmware update" (S&D Phase G): what a maker's source says is ready for a device, and
 * a way to the maker's app to install it. Oblivion shows updates and doesn't install them, so the
 * drawing's "Update and restart" confirm isn't built. Only what a source said is shown: UniFi names
 * the version but doesn't send the maker's notes or how long it takes.
 */
import { PopupSection, PopupViewer } from '@/components/popup';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import type { DeviceKind, DeviceState } from '@/lib/security-devices/device-words';
import { ExternalLink, FileText, HardDriveDownload, Info } from 'lucide-react';

export type QuietWindow = { starts_at: string | null; ends_at: string | null; by: string | null; now: boolean };

/** One device with newer software ready: a row on Devices › Upkeep › Updates, or the device page's update. */
export type FirmwareUpdate = {
    id: number;
    name: string;
    model: string;
    kind: DeviceKind;
    domain: string;
    where: string | null;
    site_id: number | null;
    state: DeviceState;
    badge: { label: string; tone: StatusVariant };
    href: string;
    source: string;
    source_label: string;
    maker: string;
    current: string | null;
    /** null: the source says an update is ready but not which version. */
    next: string | null;
    /** The maker's notes, when a source sends them (UniFi doesn't). */
    notes: null;
    current_since: string | null;
    install: { label: string; href: string };
    plan: {
        label: string;
        tone: StatusVariant;
        note: string;
        at: string | null;
        order: number;
        quiet: QuietWindow | null;
    };
};

/** "6.6.61 → 6.6.65", with words where a version isn't known. */
export function updateVersions(update: Pick<FirmwareUpdate, 'current' | 'next'>): string {
    return `${update.current ?? 'Not available'} → ${update.next ?? 'a newer version'}`;
}

/** The plan line's "{time}", filled in NZ time. */
export function planText(text: string, at: string | null, now: Date = new Date()): string {
    return at ? text.replace('{time}', seenTime(at, now)) : text.replace(' {time}', '').replace('{time}', '');
}

export function FirmwareUpdatePopup({
    open,
    update,
    onClose,
    now = new Date(),
}: {
    open: boolean;
    update: FirmwareUpdate | null;
    onClose: () => void;
    now?: Date;
}) {
    if (!update) return null;
    const quiet = update.plan.quiet;
    const description = update.next
        ? `Version ${update.next} is ready for ${update.name}${update.current ? `, which is on ${update.current}` : ''}`
        : `A newer version is ready for ${update.name}${update.current ? `, which is on ${update.current}` : ''}`;

    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="md"
            icon={HardDriveDownload}
            title="Firmware update"
            description={description}
            context={{
                title: update.name,
                tag: update.badge.label,
                note: [update.model, update.where].filter(Boolean).join(' · ') || undefined,
                icon: iconForKind(update.kind),
            }}
            callout={{
                tone: 'info',
                content: (
                    <>
                        <b className="font-semibold">Oblivion shows updates; you install them in {update.source_label}</b> (or on the
                        device’s own web page) until updating from Oblivion is built and tested on a real switch.
                    </>
                ),
            }}
            facts={[
                [
                    'Now',
                    update.current
                        ? `${update.current}${update.current_since ? ` · seen on it since ${formatDateOnly(toDateInput(update.current_since))}` : ''}`
                        : 'Not available',
                ],
                ['New', update.next ? `${update.next} · from ${update.maker}, as ${update.source_label} reports it` : `A newer version · ${update.source_label} didn’t say which`],
            ]}
            closeLabel="Not now"
            action={
                <Button asChild>
                    <a href={update.install.href} target="_blank" rel="noopener noreferrer">
                        <ExternalLink className="size-4" aria-hidden="true" />
                        {update.install.label}
                    </a>
                </Button>
            }
            testId="sd-firmware-update"
        >
            <PopupSection icon={FileText} title="Maker’s notes">
                <p className="text-subtle">
                    Not sent to Oblivion. {update.source_label} shows the maker’s notes, and how long the update takes, beside the
                    update.
                </p>
            </PopupSection>
            <Note icon={Info}>
                Best done when the house is quiet.{' '}
                {quiet
                    ? quiet.now
                        ? `Quiet time is on now${quiet.by ? `, set by ${quiet.by}` : ''}, so nobody is alerted while it restarts.`
                        : `Quiet time starts ${quiet.starts_at ? seenTime(quiet.starts_at, now) : 'soon'}${quiet.by ? `, set by ${quiet.by}` : ''}.`
                    : 'Set quiet time first, so nobody is alerted while it restarts.'}
            </Note>
        </PopupViewer>
    );
}
