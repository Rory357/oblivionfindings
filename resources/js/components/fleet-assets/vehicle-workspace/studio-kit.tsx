import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { FileText, Loader2 } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { AppointmentWizard } from './appointment-wizard';
import type { VehicleCalendarSummary } from './calendar-types';
import './studio.css';
import type { VehicleProfile } from './types';
import { locationUrl, type WorkspaceLocation } from './workspace-model';

/** The design's section heading: an optional eyebrow, a title and actions on the right. */
export function SectionHeading({
    eyebrow,
    title,
    children,
}: {
    eyebrow?: string;
    title: string;
    children?: ReactNode;
}) {
    return (
        <div className="studio-section-heading">
            <div>
                {eyebrow && <span className="studio-eyebrow">{eyebrow}</span>}
                <h2 className="text-section-title">{title}</h2>
            </div>
            {children}
        </div>
    );
}

/** The design's footer row under a collection: a note on the left, a link on the right. */
export function StudioFooterAction({
    icon,
    children,
    action,
}: {
    icon: ReactNode;
    children: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="studio-footer-action">
            <span>
                {icon}
                {children}
            </span>
            {action}
        </div>
    );
}

/** The calendar summary (open work, bookings, permissions) for wizards opened outside the calendar. */
export function useCalendarSummary(vehicleId: number, enabled: boolean) {
    const [summary, setSummary] = useState<VehicleCalendarSummary | null>(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        if (!enabled) return;
        const controller = new AbortController();
        setFailed(false);
        fetch(`/fleet-assets/vehicles/${vehicleId}/calendar/summary`, {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) throw new Error(String(response.status));
                setSummary((await response.json()) as VehicleCalendarSummary);
            })
            .catch((error: unknown) => {
                if ((error as Error)?.name !== 'AbortError') setFailed(true);
            });
        return () => controller.abort();
    }, [vehicleId, enabled]);
    return { summary, failed };
}

/**
 * "Plan service" / "Plan appointment" from a schedule, a due date or open
 * work: loads what the appointment wizard needs, then opens it.
 */
export function PlanAppointmentDialog({
    vehicle,
    presetType,
    workOrderId,
    startLocal,
    onClose,
    onSaved,
}: {
    vehicle: VehicleProfile;
    presetType?: string;
    workOrderId?: number;
    startLocal?: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const { summary, failed } = useCalendarSummary(vehicle.id, true);
    if (summary) {
        return (
            <AppointmentWizard
                vehicle={vehicle}
                summary={summary}
                presetType={presetType}
                workOrderId={workOrderId}
                startLocal={startLocal}
                onClose={onClose}
                onSaved={onSaved}
            />
        );
    }
    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Plan appointment</DialogTitle>
                    <DialogDescription>
                        {failed
                            ? 'The vehicle’s bookings and open work could not be loaded, so the appointment can’t be checked for conflicts. Try again.'
                            : 'Loading the vehicle’s bookings and open work…'}
                    </DialogDescription>
                </DialogHeader>
                {!failed && (
                    <Loader2
                        className="mx-auto size-6 animate-spin text-muted-foreground"
                        aria-hidden
                    />
                )}
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Open a Maintenance work order, with a way back to this vehicle view. */
export function openWorkOrder(
    workOrderId: number,
    vehicleId: number,
    back: WorkspaceLocation,
) {
    router.visit(
        `/fleet-assets/maintenance/work-orders/${workOrderId}?return=${encodeURIComponent(locationUrl(vehicleId, back))}`,
    );
}

/** A read-only source record: label and value rows, like the design's record modal. */
export function SourceRecordDialog({
    title,
    description = 'Source record',
    rows,
    onClose,
}: {
    title: string;
    description?: string;
    rows: Array<[string, string]>;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="vehicle-record-dialog flex max-h-[90vh] w-[min(92vw,480px)] max-w-[min(92vw,480px)] flex-col gap-0 overflow-hidden bg-card p-0">
                <DialogHeader className="vehicle-record-header">
                    <div className="vehicle-record-icon">
                        <FileText className="size-[21px]" aria-hidden />
                    </div>
                    <div>
                        <DialogTitle className="text-section-title">
                            {title}
                        </DialogTitle>
                        <DialogDescription className="mt-1.5">
                            {description}
                        </DialogDescription>
                    </div>
                </DialogHeader>
                <div className="vehicle-record-body">
                    {rows.map(([label, value], index) => (
                        <div
                            className="vehicle-record-row"
                            key={`${label}-${index}`}
                        >
                            <strong>{label}</strong>
                            <span>{value}</span>
                        </div>
                    ))}
                </div>
                <DialogFooter className="vehicle-record-footer">
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
