import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { ReviewRow } from '@/components/wizard/shell';
import { Building2 } from 'lucide-react';
import type { FormEvent } from 'react';
import type { UnifiProps } from './unifi';

/** One choice (a room at the device's current Site): a simple dialog, not a wizard. */
export function UnifiRoomDialog({
    device,
    rooms,
    value,
    onValue,
    onSave,
    onClose,
    pending,
    error,
    uncertain,
}: {
    device: UnifiProps['syncedDevices'][number];
    rooms: UnifiProps['rooms'];
    value: string;
    onValue: (value: string) => void;
    onSave: () => void;
    onClose: () => void;
    pending: boolean;
    error: string;
    uncertain: boolean;
}) {
    const available = rooms.filter((room) => room.site_id === device.site_id);
    const valid =
        device.site_id !== null &&
        (value === 'unassigned' ||
            available.some((room) => String(room.id) === value));
    const close = () => {
        if (!pending) onClose();
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!pending && valid && !uncertain) onSave();
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) close();
            }}
        >
            <DialogContent
                style={{
                    width: 'min(92vw, 480px)',
                    maxWidth: 'min(92vw, 480px)',
                }}
            >
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Building2 className="size-4 text-primary" />
                            Assign room · {device.name}
                        </DialogTitle>
                        <DialogDescription>
                            Choose a room at the device’s current Site. Clearing
                            the room keeps its Site placement.
                        </DialogDescription>
                    </DialogHeader>
                    <div>
                        <ReviewRow label="Device" value={device.name} />
                        <ReviewRow label="Site" value={device.site_name} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="unifi-room">Room</Label>
                        <Select
                            value={valid ? value : undefined}
                            onValueChange={onValue}
                            disabled={pending}
                        >
                            <SelectTrigger id="unifi-room">
                                <SelectValue placeholder="Choose a current room" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="unassigned">
                                    No room assigned
                                </SelectItem>
                                {available.map((room) => (
                                    <SelectItem
                                        key={room.id}
                                        value={String(room.id)}
                                    >
                                        {room.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    {error && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={pending}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={pending || !valid || uncertain}
                        >
                            {pending && <Spinner aria-hidden="true" />}
                            {pending ? 'Saving…' : 'Save room'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
