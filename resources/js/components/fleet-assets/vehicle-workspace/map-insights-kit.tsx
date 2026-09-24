import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateOnly, formatTime, toDateInput } from '@/lib/datetime';
import { type LucideIcon } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import './map-driving.css';
import { tripHistoryUrl } from './trip-model';
import type { TripDetail } from './trip-types';

/** "21 Sep 2026 · 8:07 am" in Pacific/Auckland. */
export function whenLabel(iso: string | null | undefined): string {
    if (!iso) return 'Time not recorded';
    return `${formatDateOnly(toDateInput(iso))} · ${formatTime(iso)}`;
}

/** The design's label / value row (evidence-row) inside the vehicle studio. */
export function StudioRow({
    title,
    sub,
    value,
    badge,
}: {
    title: string;
    sub?: string;
    value?: ReactNode;
    badge?: ReactNode;
}) {
    return (
        <div className="evidence-row">
            <div className="row-label">
                <strong>{title}</strong>
                {sub && <small>{sub}</small>}
            </div>
            <div className="row-value">
                {value}
                {badge}
            </div>
        </div>
    );
}

/**
 * The design's record modal (icon, title, description, scrolling body and a
 * footer). Rendered in a portal, so it carries its own unscoped classes.
 */
export function InsightModal({
    title,
    description,
    icon: Icon,
    size = 'standard',
    onClose,
    footer,
    className = '',
    children,
}: {
    title: string;
    description: string;
    icon: LucideIcon;
    size?: 'standard' | 'detail';
    onClose: () => void;
    footer?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    const width = size === 'standard' ? 'min(92vw, 720px)' : 'min(92vw, 480px)';
    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                className={`vehicle-record-dialog vehicle-insight-dialog flex max-h-[90vh] flex-col gap-0 overflow-hidden bg-card p-0 ${className}`}
                style={{ width, maxWidth: width }}
            >
                <DialogHeader className="vehicle-record-header">
                    <div className="vehicle-record-icon">
                        <Icon className="size-[21px]" aria-hidden />
                    </div>
                    <div className="min-w-0">
                        <DialogTitle className="text-section-title">
                            {title}
                        </DialogTitle>
                        <DialogDescription className="mt-1.5">
                            {description}
                        </DialogDescription>
                    </div>
                </DialogHeader>
                <div className="vehicle-record-body vehicle-insight-body">
                    {children}
                </div>
                <DialogFooter className="vehicle-record-footer">
                    {footer ?? (
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** A label / value row inside an InsightModal. */
export function ModalRow({
    label,
    value,
}: {
    label: string;
    value: ReactNode;
}) {
    return (
        <div className="vehicle-record-row">
            <strong>{label}</strong>
            <span>{value}</span>
        </div>
    );
}

/**
 * Loads a trip's history record (the same one Trip history shows) for the
 * wizards that act on it: confirming the driver and coaching.
 */
export function TripDetailGate({
    vehicleId,
    tripId,
    title,
    onClose,
    children,
}: {
    vehicleId: number;
    tripId: number;
    title: string;
    onClose: () => void;
    children: (detail: TripDetail) => ReactNode;
}) {
    const [detail, setDetail] = useState<TripDetail | null>(null);
    const [failed, setFailed] = useState<'error' | 'unavailable' | null>(null);
    useEffect(() => {
        const controller = new AbortController();
        fetch(tripHistoryUrl(vehicleId, `/${tripId}`), {
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if ([403, 404].includes(response.status)) {
                    setFailed('unavailable');
                    return;
                }
                if (!response.ok) throw new Error(String(response.status));
                setDetail((await response.json()) as TripDetail);
            })
            .catch((error: unknown) => {
                if ((error as Error)?.name !== 'AbortError') setFailed('error');
            });
        return () => controller.abort();
    }, [vehicleId, tripId]);

    if (detail) return <>{children(detail)}</>;
    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {failed === 'unavailable'
                            ? 'This trip is not available to you any more.'
                            : failed === 'error'
                              ? 'The trip could not be loaded. Close and try again.'
                              : 'Loading the trip record…'}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
