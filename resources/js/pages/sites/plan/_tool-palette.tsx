import { Button } from '@/components/ui/button';
import { DoorOpen, Flame, MapPin, PanelTop, Pencil, Pill, Square, Type, Video } from 'lucide-react';

export type BuilderTool =
    | 'room'
    | 'wall'
    | 'door'
    | 'window'
    | 'label'
    | 'medication_storage'
    | 'assembly_point'
    | 'emergency_exit'
    | 'fire_extinguisher'
    | 'device';

const tools: Array<{ value: BuilderTool; label: string; icon: typeof Square }> = [
    { value: 'room', label: 'Room', icon: Square },
    { value: 'wall', label: 'Wall', icon: Pencil },
    { value: 'door', label: 'Door', icon: DoorOpen },
    { value: 'window', label: 'Window', icon: PanelTop },
    { value: 'label', label: 'Label', icon: Type },
    { value: 'medication_storage', label: 'Medication', icon: Pill },
    { value: 'assembly_point', label: 'Assembly', icon: MapPin },
    { value: 'emergency_exit', label: 'Exit', icon: DoorOpen },
    { value: 'fire_extinguisher', label: 'Fire', icon: Flame },
    { value: 'device', label: 'Device', icon: Video },
];

type Props = {
    value: BuilderTool;
    onChange: (tool: BuilderTool) => void;
};

export default function ToolPalette({ value, onChange }: Props) {
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
            {tools.map((tool) => {
                const Icon = tool.icon;
                return (
                    <Button
                        key={tool.value}
                        type="button"
                        size="sm"
                        variant={value === tool.value ? 'default' : 'outline'}
                        className="justify-start gap-2"
                        onClick={() => onChange(tool.value)}
                    >
                        <Icon className="h-4 w-4" />
                        {tool.label}
                    </Button>
                );
            })}
        </div>
    );
}

