import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { LoadingState } from '@/components/ui/loading-state';
import type { ChecksLoad } from './checks-data';
import { vehicleLine } from './checks-model';
import './checks.css';
import './studio.css';
import type { VehicleProfile } from './types';

/** The design's locked record line at the top of every check dialog. */
export function LockedVehicle({ vehicle }: { vehicle: VehicleProfile }) {
    return (
        <div className="locked">
            <strong>{vehicle.name}</strong>
            <span>{vehicleLine(vehicle)}</span>
        </div>
    );
}

/** The design's close guard for an unsent draft. */
export function DraftGuard({
    open,
    onKeep,
    onDiscard,
}: {
    open: boolean;
    onKeep: () => void;
    onDiscard: () => void;
}) {
    return (
        <ConfirmDialog
            open={open}
            onClose={onKeep}
            onConfirm={onDiscard}
            title="Discard this draft?"
            description={
                <>
                    Your unsent answers and locally selected files will be
                    removed. No operational record has been changed.
                </>
            }
            confirmText="Discard draft"
            cancelText="Keep editing"
        />
    );
}

/** Shown while a check dialog loads the vehicle's checklists, or if that fails. */
export function ChecksLoadingDialog({
    title,
    load,
    onRetry,
    onClose,
}: {
    title: string;
    load: ChecksLoad;
    onRetry: () => void;
    onClose: () => void;
}) {
    const failed = load === 'error' || load === 'forbidden';
    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {load === 'forbidden'
                            ? 'You no longer have access to this vehicle’s checks. Reload the vehicle to see what is available.'
                            : failed
                              ? 'The vehicle’s checklists could not be loaded. Try again.'
                              : 'Loading the vehicle’s checklists…'}
                    </DialogDescription>
                </DialogHeader>
                {!failed && <LoadingState message="" />}
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {load === 'error' && (
                        <Button onClick={onRetry}>Try again</Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
