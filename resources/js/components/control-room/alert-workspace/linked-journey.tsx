import {
    nextAlertAction,
    type LinkedHealthSafety,
    type LinkedIncident,
} from '@/components/control-room/alert-workspace/next-action';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    CircleDot,
    FileWarning,
    HeartPulse,
    RadioTower,
} from 'lucide-react';

type JourneyCan = {
    manage: boolean;
    createIncident: boolean;
    viewIncident: boolean;
    viewHealthSafety: boolean;
};

export function LinkedJourney({
    alertReference,
    alertStatus,
    sensorConfirmationRequired,
    incident,
    healthSafety,
    can,
    onConfirmSensor,
    onCreateIncident,
    showAction = true,
}: {
    alertReference: string;
    alertStatus: string;
    sensorConfirmationRequired: boolean;
    incident: LinkedIncident | null;
    healthSafety: LinkedHealthSafety | null;
    can: JourneyCan;
    onConfirmSensor?: () => void;
    onCreateIncident?: () => void;
    showAction?: boolean;
}) {
    const action = nextAlertAction({
        alertStatus,
        sensorConfirmationRequired,
        incident,
        healthSafety,
        can,
    });

    const actionControl = (() => {
        if (!showAction) return null;
        if (action.key === 'confirm_sensor') {
            return (
                <Button size="sm" onClick={onConfirmSensor}>
                    {action.label}
                    <ArrowRight className="ml-1.5 h-4 w-4" aria-hidden />
                </Button>
            );
        }
        if (action.key === 'create_incident') {
            return (
                <Button size="sm" onClick={onCreateIncident}>
                    {action.label}
                    <ArrowRight className="ml-1.5 h-4 w-4" aria-hidden />
                </Button>
            );
        }
        if (action.href) {
            return (
                <Button size="sm" asChild>
                    <Link href={action.href}>
                        {action.label}
                        <ArrowRight className="ml-1.5 h-4 w-4" aria-hidden />
                    </Link>
                </Button>
            );
        }
        return null;
    })();

    return (
        <section className="rounded-xl border border-primary/25 bg-primary/5 p-4">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                        Linked safety journey
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        One operational alert, one official incident, then
                        governed H&S ownership.
                    </p>
                </div>
                {actionControl}
            </div>

            <div className="mt-4 grid grid-cols-3 gap-2">
                <JourneyStage
                    icon={RadioTower}
                    label="Control Room"
                    reference={alertReference}
                    state="In progress"
                    complete={Boolean(incident)}
                />
                <JourneyStage
                    icon={FileWarning}
                    label="Incident"
                    reference={incident?.referenceNumber ?? null}
                    state={incident ? 'Official record created' : 'Not created'}
                    complete={Boolean(incident)}
                />
                <JourneyStage
                    icon={HeartPulse}
                    label="Health & Safety"
                    reference={healthSafety?.referenceNumber ?? null}
                    state={
                        healthSafety
                            ? healthSafety.handoverStatus === 'accepted'
                                ? 'Handover accepted'
                                : 'Waiting for acceptance'
                            : 'Not started'
                    }
                    complete={healthSafety?.handoverStatus === 'accepted'}
                />
            </div>

            {showAction && action.statusText ? (
                <p className="mt-3 text-xs text-muted-foreground">
                    {action.statusText}
                </p>
            ) : null}
        </section>
    );
}

function JourneyStage({
    icon: Icon,
    label,
    reference,
    state,
    complete,
}: {
    icon: typeof RadioTower;
    label: string;
    reference: string | null;
    state: string;
    complete: boolean;
}) {
    return (
        <Card
            unstyled
            className="rounded-lg border border-border bg-background px-3 py-3"
        >
            <div className="flex items-center gap-2">
                <Icon className="h-4 w-4 text-primary" aria-hidden />
                <span className="text-xs font-semibold text-foreground">
                    {label}
                </span>
                {complete ? (
                    <Check
                        className="ml-auto h-3.5 w-3.5 text-status-success"
                        aria-hidden
                    />
                ) : (
                    <CircleDot
                        className="ml-auto h-3.5 w-3.5 text-muted-foreground"
                        aria-hidden
                    />
                )}
            </div>
            <p className="mt-2 font-mono text-xs font-semibold text-foreground">
                {reference ?? 'Not yet assigned'}
            </p>
            <p className="mt-1 text-[11px] text-muted-foreground">{state}</p>
        </Card>
    );
}
