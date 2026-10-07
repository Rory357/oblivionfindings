import { TimesheetWizard, type TimesheetWizardProps } from './timesheet-wizard';
export type { ClientOption, ShiftOption, SiteOption } from './timesheet-wizard';
export type CreateTimesheetDialogProps = Omit<
    TimesheetWizardProps,
    'record' | 'canSave'
> & { canCreate?: boolean };
export default function CreateTimesheetDialog({
    canCreate = false,
    ...props
}: CreateTimesheetDialogProps) {
    return <TimesheetWizard {...props} canSave={canCreate} />;
}
