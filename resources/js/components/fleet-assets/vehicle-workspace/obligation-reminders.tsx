import { Textarea } from '@/components/ui/textarea';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { useState } from 'react';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { SourceRecordDialog } from './studio-kit';
import type { ObligationReminder, VehicleProfile } from './types';
import {
    fieldProps,
    RecordDialog,
    StudioNotice,
    WizardField,
} from './wizard-kit';
import { formatKm } from './workspace-model';

/** When an obligation is due, as the design words it. */
export function obligationDue(reminder: ObligationReminder): string {
    if (reminder.due_on && reminder.due_km !== null)
        return `${formatDateOnly(reminder.due_on)} or ${formatKm(reminder.due_km)}`;
    if (reminder.due_on) return formatDateOnly(reminder.due_on);
    if (reminder.due_km !== null) return formatKm(reminder.due_km);
    return 'By distance / evidence';
}

const EVENT_LABELS: Record<
    ObligationReminder['events'][number]['action'],
    string
> = {
    sent: 'Delivered in the app',
    failed: 'Delivery failed',
    acknowledged: 'Acknowledged',
};

/** The reminder's delivery and acknowledgement history. */
export function ObligationHistoryDialog({
    vehicle,
    reminder,
    onClose,
}: {
    vehicle: VehicleProfile;
    reminder: ObligationReminder;
    onClose: () => void;
}) {
    const rows: Array<[string, string]> = [
        ['Due', obligationDue(reminder)],
        ['Owner', reminder.owner?.name ?? 'No owner recorded'],
        ['Reminder lead time', `${reminder.lead} before`],
        ['Delivery status', reminder.status_label],
        ...(reminder.last_error
            ? ([['Last problem', reminder.last_error]] as Array<
                  [string, string]
              >)
            : []),
        [
            'History',
            reminder.events.length
                ? reminder.events
                      .map(
                          (event) =>
                              `${event.at ? formatDateTime(event.at) : ''} · ${EVENT_LABELS[event.action]}${event.recipient ? ` to ${event.recipient}` : ''}${event.actor ? ` by ${event.actor}` : ''}${event.note ? `: ${event.note}` : ''}`,
                      )
                      .join('\n')
                : reminder.due_now
                  ? 'Due for delivery at the next daily run (7 am).'
                  : 'Not delivered yet. The owner is told when it comes within its lead time.',
        ],
    ];
    return (
        <SourceRecordDialog
            title={`${reminder.name} reminder`}
            description={`${[vehicle.asset_tag, vehicle.name].filter(Boolean).join(' · ')} · Delivery history`}
            rows={rows}
            onClose={onClose}
        />
    );
}

/** Acknowledge an obligation reminder, or try a failed delivery again. */
export function ObligationActionDialog({
    vehicleId,
    reminder,
    action,
    onClose,
    onSaved,
}: {
    vehicleId: number;
    reminder: ObligationReminder;
    action: 'acknowledge' | 'retry';
    onClose: () => void;
    onSaved: () => void;
}) {
    const [note, setNote] = useState('');
    const [error, setError] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const save = async () => {
        if (action === 'acknowledge' && !note.trim()) {
            setError('Record what was done or who is following up.');
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicleId}/obligation-reminders/${reminder.source_type}/${reminder.source_id}/${action}`,
            action === 'acknowledge' ? { note: note.trim() } : {},
        );
        if (!result) return;
        // The row shows the new delivery status and its history keeps the detail.
        onSaved();
        onClose();
    };

    return (
        <RecordDialog
            title={
                action === 'acknowledge'
                    ? 'Acknowledge reminder'
                    : 'Retry delivery'
            }
            description={`${reminder.name} · due ${obligationDue(reminder)} · ${reminder.owner?.name ?? 'No owner recorded'}`}
            command={command}
            submitLabel={
                action === 'acknowledge' ? 'Acknowledge' : 'Retry delivery'
            }
            onSubmit={save}
            onClose={onClose}
        >
            {action === 'acknowledge' ? (
                <>
                    <WizardField
                        id="obligation-note"
                        label="What was done or who is following up"
                        error={error || command.errors.note}
                    >
                        <Textarea
                            {...fieldProps(
                                'obligation-note',
                                error || command.errors.note,
                            )}
                            rows={3}
                            maxLength={2000}
                            value={note}
                            onChange={(event) => {
                                setNote(event.target.value);
                                setError('');
                            }}
                        />
                    </WizardField>
                    <StudioNotice title="What this changes">
                        The reminder is marked acknowledged for this due point.
                        The{' '}
                        {reminder.source_type === 'service_schedule'
                            ? 'service'
                            : 'compliance evidence'}{' '}
                        stays due until its own record is updated.
                    </StudioNotice>
                </>
            ) : (
                <StudioNotice
                    title={
                        reminder.last_error
                            ? 'Last delivery failed'
                            : 'Deliver again'
                    }
                    tone="warning"
                >
                    {reminder.last_error ??
                        'The owner is sent the in-app reminder again now.'}
                </StudioNotice>
            )}
        </RecordDialog>
    );
}
