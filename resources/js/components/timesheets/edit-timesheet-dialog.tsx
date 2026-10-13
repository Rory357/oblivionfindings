import {
    TimesheetWizard,
    type ClientOption,
    type EditTimesheetRow,
    type TimesheetWizardProps,
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
    writeCoordination,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    timesheet: EditTimesheetRow | null;
    clients: ClientOption[];
    canEdit?: boolean;
    canSubmit?: boolean;
    workerTimezone?: string;
    writeCoordination?: TimesheetWizardProps['writeCoordination'];
}) {
    return (
        <TimesheetWizard
            open={open}
            onOpenChange={onOpenChange}
            record={timesheet ?? undefined}
            draftKind="edit"
            clients={clients}
            canSave={
                canEdit &&
                timesheet != null &&
                timesheet.attendance_session_id == null &&
                ['draft', 'returned'].includes(timesheet.status)
            }
            canSubmit={canSubmit}
            workerTimezone={workerTimezone}
            writeCoordination={writeCoordination}
        />
    );
}
