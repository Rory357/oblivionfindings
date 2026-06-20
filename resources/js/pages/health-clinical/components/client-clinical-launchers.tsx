import { Button } from '@/components/ui/button';
import { RecordAssessmentDialog } from '@/pages/health-clinical/components/record-assessment-dialog';
import { RecordEventDialog } from '@/pages/health-clinical/components/record-event-dialog';
import { RecordObservationDialog } from '@/pages/health-clinical/components/record-observation-dialog';
import type { ClientResult } from '@/pages/health-clinical/components/record-wizard-shared';
import { router, usePage } from '@inertiajs/react';
import { ClipboardList, HeartPulse, Stethoscope } from 'lucide-react';
import { useState } from 'react';

/**
 * Profile entry point (§8): the three premium clinical record wizards mounted on
 * the client profile's Health Monitoring tab, each locked to this client.
 *
 * These hit the canonical Health & Clinical domain (vitals→NEWS2, clinical
 * events with H&S auto-link, FRAT/Braden/MUST/IDDSI assessments) — distinct from
 * the profile's day-to-day care-capture "Record observation" flow. Self-contained:
 * permission-gated client-side via auth.can.clinical, no extra page props needed.
 */
type ProfileClient = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    nhi_number?: string | null;
    site?: { id: number; name: string } | null;
};

type ClinicalRecordCan = {
    observationsRecord?: boolean;
    observationsRecordClinical?: boolean;
    eventsRecord?: boolean;
    assessmentsRecord?: boolean;
};

export function ClientClinicalRecordLaunchers({ client }: { client: ProfileClient }) {
    const page = usePage<{ auth?: { can?: { clinical?: ClinicalRecordCan } } }>();
    const can = page.props.auth?.can?.clinical ?? {};

    const canRecordObs = !!(can.observationsRecord || can.observationsRecordClinical);
    const canRecordEvent = !!can.eventsRecord;
    const canRecordAssessment = !!can.assessmentsRecord;

    const [obsOpen, setObsOpen] = useState(false);
    const [eventOpen, setEventOpen] = useState(false);
    const [assessmentOpen, setAssessmentOpen] = useState(false);

    if (!canRecordObs && !canRecordEvent && !canRecordAssessment) {
        return null;
    }

    const lockedClient: ClientResult = {
        id: client.id,
        name: `${client.first_name} ${client.last_name}`.trim(),
        preferred_name: client.preferred_name ?? null,
        nhi: client.nhi_number ?? null,
        site: client.site?.name ?? null,
    };

    // Refresh the profile so newly recorded clinical data is reflected.
    const onSaved = () => router.reload({ preserveScroll: true });

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-dashed border-border/70 bg-muted/30 px-3 py-2">
            <span className="mr-1 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                <Stethoscope className="h-3.5 w-3.5" /> Clinical record
            </span>
            {canRecordObs ? (
                <Button variant="outline" size="sm" onClick={() => setObsOpen(true)} data-test="clinical-record-observation">
                    <HeartPulse className="mr-1.5 h-4 w-4" /> Observation (NEWS2)
                </Button>
            ) : null}
            {canRecordEvent ? (
                <Button variant="outline" size="sm" onClick={() => setEventOpen(true)} data-test="clinical-record-event">
                    <Stethoscope className="mr-1.5 h-4 w-4" /> Clinical event
                </Button>
            ) : null}
            {canRecordAssessment ? (
                <Button variant="outline" size="sm" onClick={() => setAssessmentOpen(true)} data-test="clinical-record-assessment">
                    <ClipboardList className="mr-1.5 h-4 w-4" /> Risk assessment
                </Button>
            ) : null}

            <RecordObservationDialog
                open={obsOpen}
                onClose={() => setObsOpen(false)}
                client={lockedClient}
                canRecordClinical={!!can.observationsRecordClinical}
                onSaved={onSaved}
            />
            <RecordEventDialog
                open={eventOpen}
                onClose={() => setEventOpen(false)}
                client={lockedClient}
                onSaved={onSaved}
            />
            <RecordAssessmentDialog
                open={assessmentOpen}
                onClose={() => setAssessmentOpen(false)}
                client={lockedClient}
                onSaved={onSaved}
            />
        </div>
    );
}
