import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import * as Lucide from 'lucide-react';
import { useMemo, type CSSProperties } from 'react';
import {
    DOOR_SUBKIND_LABELS,
    SELECT_TOOL,
    isEmergencyPlanKind,
    type BuilderMode,
    type DoorSubkind,
    type Taxonomy,
} from './_types';

export type ToolValue = string; // '__room' | '__wall' | ... | kind value
// Re-exported for legacy consumers (sites/show.tsx); the type is now permissive.
export type BuilderTool = string;

// Mirrors the keyboard shortcuts in _builder-dialog.tsx. Visible chips on the
// tool tiles make these discoverable without opening a help screen.
const SHORTCUTS: Record<string, string> = {
    [SELECT_TOOL]: 'Q',
    __room: 'R',
    __wall: 'W',
    __door: 'D',
    __window: 'N',
    __label: 'T',
    __scale: 'S',
    fire_extinguisher: 'F',
    assembly_point: 'A',
    emergency_exit: 'X',
    evacuation_route: 'E',
    medication_storage: 'M',
    device: 'V',
};

function ShortcutChip({ keyName }: { keyName: string | undefined }) {
    if (!keyName) return null;
    return (
        <kbd className="ml-auto hidden rounded border bg-muted px-1 font-mono text-[10px] leading-none text-muted-foreground sm:inline">
            {keyName}
        </kbd>
    );
}

type Props = {
    taxonomy: Taxonomy | null;
    activeKind: string | null;
    activeSubkind: string | null;
    mode?: BuilderMode;
    emergencyKinds?: string[];
    onPickTool: (kind: string | null, subkind?: string | null) => void;
    onRequestCalibration: () => void;
};

type IconProps = { className?: string; style?: CSSProperties };

function resolveIcon(name: string): React.ComponentType<IconProps> {
    const candidate = (
        Lucide as unknown as Record<string, React.ComponentType<IconProps>>
    )[name];
    return (
        candidate ??
        (Lucide.Circle as unknown as React.ComponentType<IconProps>)
    );
}

export default function ToolPalette({
    taxonomy,
    activeKind,
    activeSubkind,
    mode = 'full',
    emergencyKinds = [],
    onPickTool,
    onRequestCalibration,
}: Props) {
    const groups = useMemo(
        () =>
            (taxonomy?.groups ?? [])
                .map((group) => ({
                    ...group,
                    kinds: group.kinds.filter((kind) => {
                        if (mode === 'full') return true;
                        if (kind.startsWith('__')) return false;
                        return isEmergencyPlanKind(kind, emergencyKinds);
                    }),
                }))
                .filter((group) => group.kinds.length > 0),
        [emergencyKinds, mode, taxonomy],
    );

    if (!taxonomy) {
        return (
            <div className="rounded-md border border-status-warning/30 bg-status-warning-bg p-3 text-xs text-status-warning">
                Taxonomy unavailable — the plan builder needs server-provided
                configuration.
            </div>
        );
    }

    return (
        <div className="space-y-2 rounded-md border bg-white p-2">
            <div>
                <div className="mb-1 px-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                    Selection
                </div>
                <Button
                    type="button"
                    size="sm"
                    variant={
                        activeKind === SELECT_TOOL || activeKind === null
                            ? 'default'
                            : 'outline'
                    }
                    className={cn(
                        'h-8 gap-1.5 px-2',
                        (activeKind === SELECT_TOOL || activeKind === null) &&
                            'ring-2 ring-primary/40',
                    )}
                    onClick={() => onPickTool(SELECT_TOOL)}
                    title="Select and marquee (Q)"
                    data-test="site-plan-select-tool"
                >
                    <Lucide.MousePointer2 className="h-3.5 w-3.5" />
                    <span className="text-xs">Select</span>
                    <ShortcutChip keyName={SHORTCUTS[SELECT_TOOL]} />
                </Button>
            </div>
            {groups.map((group) => (
                <div key={group.id}>
                    <div className="mb-1 px-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                        {group.label}
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        {group.kinds.map((kindKey) => {
                            if (kindKey.startsWith('__')) {
                                const shape = taxonomy.shapes[kindKey];
                                if (!shape) return null;
                                const Icon = resolveIcon(shape.icon);
                                const active = activeKind === kindKey;

                                if (kindKey === '__door') {
                                    const activeDoorSubkind =
                                        (activeSubkind as DoorSubkind | null) ??
                                        'single_swing';
                                    const tileLabel = active
                                        ? (DOOR_SUBKIND_LABELS[
                                              activeDoorSubkind
                                          ] ?? shape.label)
                                        : shape.label;
                                    return (
                                        <Popover key={kindKey}>
                                            <PopoverTrigger asChild>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant={
                                                        active
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    className={cn(
                                                        'h-8 gap-1.5 px-2',
                                                        active &&
                                                            'ring-2 ring-primary/40',
                                                    )}
                                                    title={`${shape.label}${SHORTCUTS[kindKey] ? ` (${SHORTCUTS[kindKey]})` : ''}`}
                                                    onClick={() =>
                                                        onPickTool(
                                                            kindKey,
                                                            activeDoorSubkind,
                                                        )
                                                    }
                                                    data-test="site-plan-door-tool"
                                                >
                                                    <Icon className="h-3.5 w-3.5" />
                                                    <span className="text-xs">
                                                        {tileLabel}
                                                    </span>
                                                    <ShortcutChip
                                                        keyName={
                                                            SHORTCUTS[kindKey]
                                                        }
                                                    />
                                                    <Lucide.ChevronDown className="h-3 w-3 opacity-60" />
                                                </Button>
                                            </PopoverTrigger>
                                            <PopoverContent
                                                className="w-56 p-1"
                                                align="start"
                                            >
                                                <div className="px-2 py-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                                    Door style
                                                </div>
                                                {(
                                                    Object.entries(
                                                        DOOR_SUBKIND_LABELS,
                                                    ) as [DoorSubkind, string][]
                                                ).map(([value, label]) => (
                                                    <button
                                                        key={value}
                                                        type="button"
                                                        className={cn(
                                                            'w-full rounded px-2 py-1.5 text-left text-xs hover:bg-muted',
                                                            active &&
                                                                activeDoorSubkind ===
                                                                    value &&
                                                                'bg-muted font-medium',
                                                        )}
                                                        onClick={() =>
                                                            onPickTool(
                                                                kindKey,
                                                                value,
                                                            )
                                                        }
                                                        data-test={`site-plan-door-subkind-${value}`}
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </PopoverContent>
                                        </Popover>
                                    );
                                }

                                return (
                                    <Button
                                        key={kindKey}
                                        type="button"
                                        size="sm"
                                        variant={active ? 'default' : 'outline'}
                                        className={cn(
                                            'h-8 gap-1.5 px-2',
                                            active && 'ring-2 ring-primary/40',
                                        )}
                                        onClick={() => onPickTool(kindKey)}
                                        title={`${shape.label}${SHORTCUTS[kindKey] ? ` (${SHORTCUTS[kindKey]})` : ''}`}
                                        data-test={
                                            kindKey === '__wall'
                                                ? 'site-plan-wall-tool'
                                                : undefined
                                        }
                                    >
                                        <Icon className="h-3.5 w-3.5" />
                                        <span className="text-xs">
                                            {shape.label}
                                        </span>
                                        <ShortcutChip
                                            keyName={SHORTCUTS[kindKey]}
                                        />
                                    </Button>
                                );
                            }

                            const kind = taxonomy.kinds[kindKey];
                            if (!kind) return null;
                            const Icon = resolveIcon(kind.icon);
                            const active = activeKind === kindKey;
                            const subkinds = kind.subkinds ?? [];

                            if (subkinds.length === 0) {
                                return (
                                    <Button
                                        key={kindKey}
                                        type="button"
                                        size="sm"
                                        variant={active ? 'default' : 'outline'}
                                        className={cn(
                                            'h-8 gap-1.5 px-2',
                                            active && 'ring-2 ring-primary/40',
                                        )}
                                        style={
                                            active
                                                ? undefined
                                                : { borderColor: kind.color }
                                        }
                                        onClick={() => onPickTool(kindKey)}
                                        title={`${kind.label}${SHORTCUTS[kindKey] ? ` (${SHORTCUTS[kindKey]})` : ''}`}
                                    >
                                        <Icon
                                            className="h-3.5 w-3.5"
                                            style={{
                                                color: active
                                                    ? undefined
                                                    : kind.color,
                                            }}
                                        />
                                        <span className="text-xs">
                                            {kind.label}
                                        </span>
                                        <ShortcutChip
                                            keyName={SHORTCUTS[kindKey]}
                                        />
                                    </Button>
                                );
                            }

                            const subLabel =
                                active && activeSubkind
                                    ? (subkinds.find(
                                          (s) => s.value === activeSubkind,
                                      )?.label ?? kind.label)
                                    : kind.label;

                            return (
                                <Popover key={kindKey}>
                                    <PopoverTrigger asChild>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={
                                                active ? 'default' : 'outline'
                                            }
                                            className={cn(
                                                'h-8 gap-1.5 px-2',
                                                active &&
                                                    'ring-2 ring-primary/40',
                                            )}
                                            style={
                                                active
                                                    ? undefined
                                                    : {
                                                          borderColor:
                                                              kind.color,
                                                      }
                                            }
                                            title={`${kind.label}${SHORTCUTS[kindKey] ? ` (${SHORTCUTS[kindKey]})` : ''}`}
                                        >
                                            <Icon
                                                className="h-3.5 w-3.5"
                                                style={{
                                                    color: active
                                                        ? undefined
                                                        : kind.color,
                                                }}
                                            />
                                            <span className="text-xs">
                                                {subLabel}
                                            </span>
                                            <ShortcutChip
                                                keyName={SHORTCUTS[kindKey]}
                                            />
                                            <Lucide.ChevronDown className="h-3 w-3 opacity-60" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        className="w-56 p-1"
                                        align="start"
                                    >
                                        <div className="px-2 py-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                            {kind.label} type
                                        </div>
                                        <button
                                            type="button"
                                            className={cn(
                                                'w-full rounded px-2 py-1.5 text-left text-xs hover:bg-muted',
                                                active &&
                                                    !activeSubkind &&
                                                    'bg-muted font-medium',
                                            )}
                                            onClick={() =>
                                                onPickTool(kindKey, null)
                                            }
                                        >
                                            Generic
                                        </button>
                                        {subkinds.map((sub) => (
                                            <button
                                                key={sub.value}
                                                type="button"
                                                className={cn(
                                                    'w-full rounded px-2 py-1.5 text-left text-xs hover:bg-muted',
                                                    active &&
                                                        activeSubkind ===
                                                            sub.value &&
                                                        'bg-muted font-medium',
                                                )}
                                                onClick={() =>
                                                    onPickTool(
                                                        kindKey,
                                                        sub.value,
                                                    )
                                                }
                                            >
                                                {sub.label}
                                            </button>
                                        ))}
                                    </PopoverContent>
                                </Popover>
                            );
                        })}
                    </div>
                </div>
            ))}

            {mode === 'full' && (
                <div className="border-t pt-2">
                    <div className="mb-1 px-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                        Measure
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        <Button
                            type="button"
                            size="sm"
                            variant={
                                activeKind === '__scale' ? 'default' : 'outline'
                            }
                            className="h-8 gap-1.5 px-2"
                            title="Set scale (S)"
                            onClick={() => {
                                onPickTool('__scale');
                                onRequestCalibration();
                            }}
                        >
                            <Lucide.Ruler className="h-3.5 w-3.5" />
                            <span className="text-xs">Set scale</span>
                            <ShortcutChip keyName={SHORTCUTS['__scale']} />
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
