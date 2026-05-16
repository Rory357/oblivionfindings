import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CheckCircle2, CircleDashed } from 'lucide-react';
import { SELECT_TOOL, isEmergencyPlanKind, type PlanPin, type Taxonomy } from './_types';
import type { EditorAction } from './_use-plan-editor';
import type { Dispatch } from 'react';

type ChecklistRow = {
    kind: string;
    label: string;
    required?: boolean;
    passes: (pins: PlanPin[]) => boolean;
};

const rows: ChecklistRow[] = [
    { kind: 'assembly_point', label: 'Assembly point', required: true, passes: (pins) => pins.some((pin) => pin.kind === 'assembly_point') },
    { kind: 'emergency_exit', label: 'Emergency exit', required: true, passes: (pins) => pins.some((pin) => pin.kind === 'emergency_exit') },
    { kind: 'emergency_exit', label: 'Secondary exit', passes: (pins) => pins.filter((pin) => pin.kind === 'emergency_exit').length >= 2 },
    { kind: 'evacuation_route', label: 'Evacuation route', passes: (pins) => pins.some((pin) => pin.kind === 'evacuation_route') },
    { kind: 'fire_extinguisher', label: 'Fire extinguisher', passes: (pins) => pins.some((pin) => pin.kind === 'fire_extinguisher') },
    { kind: 'smoke_alarm', label: 'Smoke alarm', passes: (pins) => pins.some((pin) => pin.kind === 'smoke_alarm') },
    { kind: 'first_aid_kit', label: 'First-aid kit', passes: (pins) => pins.some((pin) => pin.kind === 'first_aid_kit') },
    { kind: 'you_are_here', label: 'You are here', passes: (pins) => pins.some((pin) => pin.kind === 'you_are_here') },
    { kind: 'defibrillator', label: 'Defibrillator', passes: (pins) => pins.some((pin) => pin.kind === 'defibrillator') },
];

type Props = {
    pins: PlanPin[];
    emergencyKinds: string[];
    taxonomy: Taxonomy | null;
    dispatch: Dispatch<EditorAction>;
};

export default function EmergencyChecklist({ pins, emergencyKinds, taxonomy, dispatch }: Props) {
    const emergencyPins = pins.filter((pin) => isEmergencyPlanKind(pin.kind, emergencyKinds));
    const visibleRows = rows.filter((row) => taxonomy?.kinds?.[row.kind] || emergencyKinds.includes(row.kind));
    const hardReady = visibleRows.filter((row) => row.required).every((row) => row.passes(emergencyPins));

    return (
        <section className="rounded-md border p-3" data-test="site-plan-emergency-checklist">
            <div className="mb-2 flex items-center justify-between gap-2">
                <h3 className="text-sm font-medium">Emergency checklist</h3>
                <Badge variant={hardReady ? 'default' : 'outline'}>{hardReady ? 'Ready to publish' : 'Needs essentials'}</Badge>
            </div>
            <div className="space-y-1.5">
                {visibleRows.map((row) => {
                    const done = row.passes(emergencyPins);
                    return (
                        <div key={`${row.kind}-${row.label}`} className="flex items-center gap-2 rounded-md border p-2 text-xs">
                            {done ? (
                                <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                            ) : (
                                <CircleDashed className="h-4 w-4 text-muted-foreground" />
                            )}
                            <div className="min-w-0 flex-1">
                                <div className="font-medium">{row.label}</div>
                                <div className="text-[10px] text-muted-foreground">{row.required ? 'Required' : 'Recommended'}</div>
                            </div>
                            {!done && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={row.required ? 'default' : 'outline'}
                                    className="h-7 px-2 text-xs"
                                    onClick={() => dispatch({ type: 'set_tool', kind: row.kind })}
                                >
                                    Add
                                </Button>
                            )}
                        </div>
                    );
                })}
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="mt-2 h-7 w-full text-xs"
                onClick={() => dispatch({ type: 'set_tool', kind: SELECT_TOOL })}
            >
                Return to select
            </Button>
        </section>
    );
}
