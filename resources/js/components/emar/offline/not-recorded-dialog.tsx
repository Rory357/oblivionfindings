/* eMAR audit Lane A3 (EA-046, EA-048, EA-049, EA-050): what is saved on
 * this device but not on the chart. Each refused dose names the person,
 * medicine, outcome and time with the server's reason, says whether a lead
 * has been told, and is removed one at a time only after the worker says it
 * is recorded on paper or their lead knows. Another worker's saved doses are
 * listed so nobody gives them again. */
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import {
    dismissRejectedOfflineSubmission,
    type OfflineSubmission,
    type OfflineSubmissionDisplay,
    type StrandedSubmission,
} from '@/lib/offline-queue';
import { useState } from 'react';

function doseLine(display: OfflineSubmissionDisplay | null | undefined) {
    if (!display) return 'A saved medication record';
    return `${display.person} · ${display.medicine}`;
}

function whenLine(
    display: OfflineSubmissionDisplay | null | undefined,
    savedAt: string,
) {
    const parts = [
        display?.outcome,
        display?.at ? formatDateTime(display.at) : null,
        `saved on this device ${formatDateTime(savedAt)}`,
    ].filter(Boolean);
    return parts.join(' · ');
}

function RefusedRow({ item }: { item: OfflineSubmission }) {
    const [acknowledged, setAcknowledged] = useState(false);
    const [removing, setRemoving] = useState(false);
    const checkboxId = `offline-ack-${item.id}`;

    return (
        <li className="space-y-2 rounded-lg border p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium">{doseLine(item.display)}</p>
                    <p className="text-caption">
                        {whenLine(item.display, item.createdAt)}
                    </p>
                </div>
                <StatusBadge variant="critical" size="sm">
                    Not recorded
                </StatusBadge>
            </div>
            <p className="text-sm">
                {item.lastError ??
                    'The server refused this saved action, so it was not recorded.'}
            </p>
            <p className="text-caption">
                {item.reportedAt ? (
                    <StatusBadge variant="success" size="sm">
                        Lead follow-up created
                    </StatusBadge>
                ) : (
                    <StatusBadge variant="warning" size="sm">
                        Lead not told yet — tell your lead
                    </StatusBadge>
                )}
            </p>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id={checkboxId}
                        checked={acknowledged}
                        onCheckedChange={(value) =>
                            setAcknowledged(value === true)
                        }
                    />
                    <Label htmlFor={checkboxId} className="text-sm font-normal">
                        I’ve recorded this on paper or told my lead
                    </Label>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap"
                    disabled={!acknowledged || removing}
                    onClick={() => {
                        setRemoving(true);
                        void dismissRejectedOfflineSubmission(
                            item.id,
                            'told_lead',
                        ).finally(() => setRemoving(false));
                    }}
                >
                    Remove from this device
                </Button>
            </div>
        </li>
    );
}

export function NotRecordedDialog({
    refused,
    stranded,
    onClose,
}: {
    refused: OfflineSubmission[];
    stranded: StrandedSubmission[];
    onClose: () => void;
}) {
    return (
        <SettingsModal
            title="Medication records not on the chart"
            description="These were saved on this device but are not on anyone’s chart. Nothing here was recorded."
            width={720}
            onClose={onClose}
        >
            {refused.length > 0 ? (
                <section className="space-y-2">
                    <h3 className="text-section-title">
                        Refused by the server ({refused.length})
                    </h3>
                    <p className="text-subtle">
                        If the dose was given, record it on paper and tell your
                        lead. Each one stays here until you say it’s dealt with.
                    </p>
                    <ul className="space-y-2">
                        {refused.map((item) => (
                            <RefusedRow key={item.id} item={item} />
                        ))}
                    </ul>
                </section>
            ) : null}
            {stranded.length > 0 ? (
                <section className="space-y-2">
                    <h3 className="text-section-title">
                        Saved by another worker ({stranded.length})
                    </h3>
                    <p className="text-subtle">
                        They send when that worker signs in on this device.
                        Don’t give or record these again. If they can’t sign in
                        here, tell your lead.
                    </p>
                    <ul className="space-y-2">
                        {stranded.map((item) => (
                            <li key={item.id} className="rounded-lg border p-3">
                                <p className="font-medium">
                                    {doseLine(item.display)}
                                </p>
                                <p className="text-caption">
                                    {whenLine(item.display, item.createdAt)}
                                </p>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </SettingsModal>
    );
}
