import {
    IncidentReportDialog,
    type IncidentReportClient,
    type IncidentReportDefaults,
    type IncidentReportSite,
} from '@/components/incidents/incident-report-dialog';

type ReportIncidentDialogProps = {
    open: boolean;
    onClose: () => void;
    clients: IncidentReportClient[];
    sites: IncidentReportSite[];
    defaults?: IncidentReportDefaults;
};

/** Health & Safety uses the same incident form and payload as every other entry point. */
export function ReportIncidentDialog(props: ReportIncidentDialogProps) {
    return (
        <IncidentReportDialog
            {...props}
            mode="incident"
            entryContext="health_safety"
            staff={[]}
            canManageFollowups={false}
        />
    );
}

export default ReportIncidentDialog;
