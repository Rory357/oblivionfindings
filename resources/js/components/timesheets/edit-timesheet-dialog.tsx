import {
    TimesheetWizard,
    type ClientOption,
    type EditTimesheetRow,
} from './timesheet-wizard';
export type { EditTimesheetRow } from './timesheet-wizard';
export default function EditTimesheetDialog({
    open,
    onOpenChange,
    timesheet,
    clients,
    canEdit = false,
    canSubmit = false,
    workerTimezone,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    timesheet: EditTimesheetRow | null;
    clients: ClientOption[];
    canEdit?: boolean;
    canSubmit?: boolean;
    workerTimezone?: string;
}) {
    if (!timesheet) return null;
    return (
        <TimesheetWizard
            open={open}
            onOpenChange={onOpenChange}
            record={timesheet}
            clients={clients}
            canSave={
                canEdit &&
                timesheet.attendance_session_id == null &&
                ['draft', 'returned'].includes(timesheet.status)
            }
            canSubmit={canSubmit}
            workerTimezone={workerTimezone}
        />
    );
}
