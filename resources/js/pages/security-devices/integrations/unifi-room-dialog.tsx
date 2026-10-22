import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { Building2, Check, Cpu } from 'lucide-react';
import { useState } from 'react';
import type { UnifiProps } from './unifi';
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
    const [step, setStep] = useState(0);
    const available = rooms.filter((room) => room.site_id === device.site_id);
    const valid =
        device.site_id !== null &&
        (value === 'unassigned' ||
            available.some((room) => String(room.id) === value));
    const roomName =
        value === 'unassigned'
            ? 'No room assigned'
            : available.find((room) => String(room.id) === value)?.name ||
              'Room unavailable';
    return (
        <WizardShell
            open
            onClose={() => {
                if (!pending) onClose();
            }}
            title={'Assign room · ' + device.name}
            description="Review the room assignment within the device’s current Site."
            railIcon={Cpu}
            railTitle="Assign room"
            railSub={device.name}
            steps={[
                {
                    key: 'room',
                    label: 'Room',
                    blurb: 'Current Site placement',
                    icon: Building2,
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm assignment',
                    icon: Check,
                },
            ]}
            stepIndex={step}
            onStepClick={(index) => {
                if (!pending && (index === 0 || valid)) setStep(index);
            }}
            pct={valid ? 100 : 0}
            footerStart={
                <Button variant="outline" disabled={pending} onClick={onClose}>
                    Cancel
                </Button>
            }
            footerEnd={
                <>
                    {step === 1 && (
                        <Button
                            variant="outline"
                            disabled={pending}
                            onClick={() => setStep(0)}
                        >
                            Back
                        </Button>
                    )}
                    <Button
                        disabled={pending || !valid || uncertain}
                        onClick={() => (step === 0 ? setStep(1) : onSave())}
                    >
                        {pending
                            ? 'Saving…'
                            : step === 0
                              ? 'Review assignment'
                              : 'Save Room'}
                    </Button>
                </>
            }
        >
            {error && (
                <p role="alert" className="text-status-critical">
                    {error}
                </p>
            )}
            {step === 0 ? (
                <WizardStepPane>
                    <StepHead
                        icon={Building2}
                        title="Choose room"
                        blurb="Choose a room at the device’s current Site. Clearing the room keeps its Site placement."
                    />
                    <ReviewRow label="Device" value={device.name} />
                    <ReviewRow label="Site" value={device.site_name} />
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
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    <StepHead
                        icon={Building2}
                        title="Review assignment"
                        blurb="Save this room assignment for the selected device."
                    />
                    <ReviewCard icon={Cpu} title="Assignment">
                        <ReviewRow label="Device" value={device.name} />
                        <ReviewRow label="Site" value={device.site_name} />
                        <ReviewRow label="Room" value={roomName} />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
