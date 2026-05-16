import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { PlanLayout, PlanPin } from './_thumbnail';
import { Trash2 } from 'lucide-react';

type Props = {
    layout: PlanLayout;
    pins: PlanPin[];
    onRemovePin: (index: number) => void;
};

export default function PlanInspector({ layout, pins, onRemovePin }: Props) {
    return (
        <div className="space-y-4 text-sm">
            <section>
                <h3 className="font-medium">Rooms</h3>
                <div className="mt-2 space-y-2">
                    {(layout.rooms ?? []).length === 0 ? (
                        <p className="text-muted-foreground">No rooms placed yet.</p>
                    ) : (
                        layout.rooms?.map((room) => (
                            <div key={room.id} className="rounded-md border p-2">
                                {room.label}
                            </div>
                        ))
                    )}
                </div>
            </section>
            <section>
                <h3 className="font-medium">Pins</h3>
                <div className="mt-2 space-y-2">
                    {pins.length === 0 ? (
                        <p className="text-muted-foreground">No pins placed yet.</p>
                    ) : (
                        pins.map((pin, index) => (
                            <div key={`${pin.kind}-${index}`} className="flex items-center justify-between rounded-md border p-2">
                                <div>
                                    <div className="font-medium">{pin.label || pin.kind.replaceAll('_', ' ')}</div>
                                    <Badge variant="outline" className="mt-1">
                                        {pin.kind.replaceAll('_', ' ')}
                                    </Badge>
                                </div>
                                <Button type="button" size="icon" variant="ghost" onClick={() => onRemovePin(index)}>
                                    <Trash2 className="h-4 w-4" />
                                    <span className="sr-only">Remove pin</span>
                                </Button>
                            </div>
                        ))
                    )}
                </div>
            </section>
        </div>
    );
}

