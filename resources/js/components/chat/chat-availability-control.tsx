import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useEffect, useId, useRef, useState } from 'react';
import { useChatCalling, type Availability } from './chat-calling';

export function ChatAvailabilityControl(props: {
    actorId: number;
    family: boolean;
    showRingtone?: boolean;
}) {
    return <AvailabilityControlSession key={props.actorId} {...props} />;
}

function AvailabilityControlSession({
    family,
    showRingtone = true,
}: {
    family: boolean;
    showRingtone?: boolean;
}) {
    const calling = useChatCalling();
    const id = useId();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const live = useRef(true);
    const busy = useRef(false);
    useEffect(() => {
        live.current = true;
        return () => {
            live.current = false;
        };
    }, []);
    if (!calling) return null;
    async function save(action: () => Promise<void>) {
        if (busy.current) return;
        busy.current = true;
        setSaving(true);
        setError('');
        try {
            await action();
        } catch (failure) {
            if (live.current)
                setError(
                    (failure as Error).message ||
                        'Your preference could not be confirmed. Check your connection and try again.',
                );
        } finally {
            busy.current = false;
            if (live.current) setSaving(false);
        }
    }
    return (
        <div className="max-w-sm space-y-1 text-xs text-muted-foreground">
            <div className="space-y-1">
                <Label htmlFor={`${id}-availability`}>Availability</Label>
                <Select
                    value={calling.availability}
                    disabled={saving || !calling.enabled}
                    onValueChange={(value) => {
                        void save(() =>
                            calling.setAvailability(value as Availability),
                        );
                    }}
                >
                    <SelectTrigger
                        id={`${id}-availability`}
                        aria-label="Chat availability"
                        className="frontline-tap text-foreground"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="unavailable" disabled>
                            Unavailable
                        </SelectItem>
                        <SelectItem value="available">Available</SelectItem>
                        <SelectItem value="busy">Busy</SelectItem>
                        <SelectItem value="dnd">Do not disturb</SelectItem>
                        {!family && calling.canSetOffDuty && (
                            <SelectItem value="off_duty">Off duty</SelectItem>
                        )}
                    </SelectContent>
                </Select>
            </div>
            {showRingtone && (
                <div className="frontline-tap flex items-center gap-2">
                    <Switch
                        id={`${id}-ringtone`}
                        checked={calling.soundEnabled}
                        disabled={saving || !calling.enabled}
                        onCheckedChange={(enabled) => {
                            void save(() => calling.setSoundEnabled(enabled));
                        }}
                    />
                    <Label htmlFor={`${id}-ringtone`}>Call ringtone</Label>
                </div>
            )}
            {saving && <p role="status">Saving your preference…</p>}
            {error && (
                <p role="alert" className="text-status-critical">
                    {error}
                </p>
            )}
            {calling.availability === 'off_duty' && (
                <p>You are off duty. No response is expected.</p>
            )}
            {['dnd', 'off_duty'].includes(calling.availability) && (
                <p>Chat notifications and new calls are paused.</p>
            )}
            {calling.availability === 'busy' && (
                <p>You can keep chatting. New calls will not ring.</p>
            )}
        </div>
    );
}
