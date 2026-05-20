import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { Copy, Redo2, Save, Send, Undo2 } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useState,
} from 'react';
import { toast } from 'sonner';
import PlanCanvas from './_canvas';
import PlanInspector from './_inspector';
import ToolPalette from './_tool-palette';
import {
    SELECT_TOOL,
    distanceCanvasUnits,
    formatMeters,
    isEmergencyPlanKind,
    metersPerUnit,
    type BuilderMode,
    type Inventory,
    type PlanLayout,
    type PlanPin,
    type Taxonomy,
} from './_types';
import { usePlanEditor } from './_use-plan-editor';

type TypePlanSummary = {
    tab_label: string;
    inventory_label: string;
    inventory_href: string;
    status?: 'empty' | 'draft' | 'published' | 'draft_over_published';
    draft?: {
        layout: PlanLayout;
        notes?: string | null;
        pins: PlanPin[];
    } | null;
    published?: {
        layout: PlanLayout;
        notes?: string | null;
        pins: PlanPin[];
    } | null;
    inventory?: Inventory | null;
    taxonomy?: Taxonomy | null;
    emergency_pin_kinds?: string[];
};

type Props = {
    site: { id: number; name: string; type: string };
    typePlan: TypePlanSummary;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    focusTool?: string;
    mode?: BuilderMode;
};

const PLAN_RELOAD_PROPS = [
    'typePlan',
    'readiness',
    'plan',
    'emergencyPins',
    'ready',
    'legend',
    'hasDraftOverPublished',
];

class JsonRequestError extends Error {
    constructor(
        message: string,
        readonly status: number,
        readonly payload: unknown,
    ) {
        super(message);
    }
}

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function jsonRequest(url: string, method: string, body?: unknown) {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
        const text = await response.text();
        let payload: unknown = text;
        try {
            payload = text ? JSON.parse(text) : null;
        } catch {
            // keep raw text
        }
        const message =
            typeof payload === 'object' && payload && 'message' in payload
                ? String((payload as { message?: unknown }).message)
                : text || `Request failed with ${response.status}`;
        throw new JsonRequestError(message, response.status, payload);
    }

    return response.json();
}

function sanitisePinsForSave(pins: PlanPin[]) {
    return pins.map((pin) => ({
        ...pin,
        id: typeof pin.id === 'number' ? pin.id : undefined,
    }));
}

function pinIdOf(pin: PlanPin, index: number): string {
    return pin.id != null ? String(pin.id) : `__idx-${index}`;
}

function validationErrorsForPins(
    errors: unknown,
    pins: PlanPin[],
): Record<string, string> {
    if (!errors || typeof errors !== 'object') return {};
    const result: Record<string, string> = {};
    for (const [key, value] of Object.entries(
        errors as Record<string, unknown>,
    )) {
        const match = key.match(/^pins\.(\d+)\./);
        if (!match) continue;
        const pin = pins[Number(match[1])];
        if (!pin) continue;
        const message = Array.isArray(value)
            ? String(value[0] ?? '')
            : String(value ?? '');
        if (message) result[`pin:${pinIdOf(pin, Number(match[1]))}`] = message;
    }
    return result;
}

export default function SiteTypePlanBuilderDialog({
    site,
    typePlan,
    open,
    onOpenChange,
    focusTool,
    mode = 'full',
}: Props) {
    const source = typePlan.draft ?? typePlan.published;

    const { state, dispatch, canUndo, canRedo, reset, markClean } =
        usePlanEditor(source?.layout ?? null, source?.pins ?? []);

    const [notes, setNotes] = useState(source?.notes ?? '');
    const [notesDirty, setNotesDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    const [calibrationDialog, setCalibrationDialog] = useState<{
        firstPoint: { x: number; y: number };
        secondPoint: { x: number; y: number };
        realMeters: string;
    } | null>(null);

    const title = useMemo(
        () =>
            mode === 'emergency'
                ? 'Edit emergency plan'
                : `${source ? 'Edit' : 'Build'} ${typePlan.tab_label}`,
        [mode, source, typePlan.tab_label],
    );
    const sourceLabel =
        mode === 'emergency'
            ? 'Emergency pins'
            : typePlan.status === 'draft_over_published'
              ? 'Draft over published'
              : typePlan.status === 'published'
                ? 'Published'
                : typePlan.status === 'draft'
                  ? 'Draft'
                  : 'New plan';
    const emergencyKinds = typePlan.emergency_pin_kinds ?? [];

    // When the dialog opens we re-seed the editor from the current source.
    useLayoutEffect(() => {
        if (!open) return;
        reset(source?.layout ?? null, source?.pins ?? []);
        setNotes(source?.notes ?? '');
        setNotesDirty(false);
        if (focusTool) {
            dispatch({ type: 'set_tool', kind: mapFocusTool(focusTool) });
        } else {
            dispatch({ type: 'set_tool', kind: SELECT_TOOL });
        }
        // We intentionally depend only on `open` here — otherwise rendering
        // the parent would clobber in-progress edits.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const layoutForServer = useMemo<PlanLayout>(
        () => ({
            ...state.layout,
            // strip any transient interaction artefacts (none today)
        }),
        [state.layout],
    );

    const dirty = state.dirty || notesDirty;

    const saveDraft = useCallback(async (): Promise<boolean> => {
        setSaving(true);
        try {
            if (mode === 'full') {
                await jsonRequest(`/sites/${site.id}/plan/draft`, 'POST', {
                    layout: layoutForServer,
                    notes,
                });
            }
            const pinsForSave =
                mode === 'emergency'
                    ? state.pins.filter((pin) =>
                          isEmergencyPlanKind(pin.kind, emergencyKinds),
                      )
                    : state.pins;
            await jsonRequest(`/sites/${site.id}/plan/pins`, 'POST', {
                mode,
                replace: true,
                pins: sanitisePinsForSave(pinsForSave),
            });
            toast.success('Draft plan saved.');
            dispatch({ type: 'set_validation_errors', errors: {} });
            markClean();
            setNotesDirty(false);
            router.reload({ only: PLAN_RELOAD_PROPS });
            return true;
        } catch (error) {
            if (error instanceof JsonRequestError && error.status === 422) {
                const errors = validationErrorsForPins(
                    (error.payload as { errors?: unknown })?.errors,
                    state.pins,
                );
                dispatch({ type: 'set_validation_errors', errors });
            }
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Could not save draft plan.',
            );
            return false;
        } finally {
            setSaving(false);
        }
    }, [
        dispatch,
        emergencyKinds,
        layoutForServer,
        markClean,
        mode,
        notes,
        site.id,
        state.pins,
    ]);

    const publish = useCallback(async () => {
        const saved = await saveDraft();
        if (!saved) return;
        setSaving(true);
        try {
            await jsonRequest(`/sites/${site.id}/plan/publish`, 'POST');
            toast.success('Plan published.');
            router.reload({ only: PLAN_RELOAD_PROPS });
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Could not publish plan.',
            );
        } finally {
            setSaving(false);
        }
    }, [saveDraft, site.id]);

    const requestOpenChange = useCallback(
        (nextOpen: boolean) => {
            if (!nextOpen && dirty) {
                setConfirmClose(true);
                return;
            }
            onOpenChange(nextOpen);
        },
        [dirty, onOpenChange],
    );

    // ── Keyboard shortcuts ──────────────────────────────────────────
    useEffect(() => {
        if (!open) return;
        function onKey(event: KeyboardEvent) {
            const target = event.target as HTMLElement | null;
            const tag = target?.tagName?.toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                if (!(event.metaKey || event.ctrlKey)) return;
            }

            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'z'
            ) {
                event.preventDefault();
                if (event.shiftKey) dispatch({ type: 'redo' });
                else dispatch({ type: 'undo' });
                return;
            }
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'y'
            ) {
                event.preventDefault();
                dispatch({ type: 'redo' });
                return;
            }
            if (event.key === 'Escape') {
                if (state.editing) {
                    dispatch({ type: 'end_edit' });
                } else if (state.interaction.mode !== 'idle') {
                    dispatch({ type: 'cancel_interaction' });
                } else {
                    dispatch({ type: 'set_tool', kind: SELECT_TOOL });
                    dispatch({ type: 'select', ref: null });
                }
                return;
            }
            if (
                (event.key === 'Delete' || event.key === 'Backspace') &&
                state.selection.length > 0 &&
                !state.editing
            ) {
                event.preventDefault();
                dispatch({ type: 'commit' });
                dispatch({ type: 'delete_selected' });
                return;
            }
            const shortcuts: Record<string, string> = {
                r: '__room',
                w: '__wall',
                d: '__door',
                n: '__window',
                t: '__label',
                f: 'fire_extinguisher',
                a: 'assembly_point',
                x: 'emergency_exit',
                e: 'evacuation_route',
                m: 'medication_storage',
                v: 'device',
                s: '__scale',
                q: SELECT_TOOL,
            };
            const tool = shortcuts[event.key.toLowerCase()];
            if (tool) {
                event.preventDefault();
                dispatch({ type: 'set_tool', kind: tool });
            }
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [
        dispatch,
        open,
        state.interaction.mode,
        state.editing,
        state.selection.length,
    ]);

    const taxonomy = typePlan.taxonomy ?? null;
    const inventory = typePlan.inventory ?? null;
    const mpu = metersPerUnit(state.layout);

    return (
        <>
            <Dialog open={open} onOpenChange={requestOpenChange}>
                <DialogContent
                    className="grid h-[min(900px,92vh)] grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden bg-muted/30 p-0 sm:max-w-[min(1500px,calc(100vw-2rem))]"
                    data-test="site-plan-builder-dialog"
                >
                    <DialogHeader className="border-b bg-background px-5 py-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <DialogTitle className="flex items-center gap-2">
                                    {title}
                                    <span className="rounded-full border bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                        {sourceLabel}
                                    </span>
                                    {mode === 'emergency' && (
                                        <span
                                            className="rounded-full border border-red-200 bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700"
                                            data-test="site-plan-emergency-mode-badge"
                                        >
                                            Emergency mode
                                        </span>
                                    )}
                                </DialogTitle>
                                <DialogDescription>
                                    Pick a tool, click the canvas to place
                                    items. Drag to move, drag handles to resize,
                                    double-click rooms to link to the registry.
                                </DialogDescription>
                            </div>
                            <div className="rounded-md border bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                                {dirty
                                    ? 'Draft has unsaved edits'
                                    : 'Draft is up to date'}{' '}
                                ·{' '}
                                {state.selection.length > 0
                                    ? `${state.selection.length} selected`
                                    : 'No selection'}
                            </div>
                        </div>
                    </DialogHeader>
                    <div className="grid min-h-0 gap-3 p-3 lg:grid-cols-[270px_minmax(0,1fr)_350px]">
                        <div className="min-h-0 overflow-y-auto rounded-lg border bg-background p-2 shadow-sm">
                            <ToolPalette
                                taxonomy={taxonomy}
                                activeKind={state.activeKind}
                                activeSubkind={state.activeSubkind}
                                mode={mode}
                                emergencyKinds={emergencyKinds}
                                onPickTool={(kind, subkind) =>
                                    dispatch({
                                        type: 'set_tool',
                                        kind: kind ?? SELECT_TOOL,
                                        subkind: subkind ?? null,
                                    })
                                }
                                onRequestCalibration={() =>
                                    dispatch({
                                        type: 'set_tool',
                                        kind: '__scale',
                                    })
                                }
                            />
                        </div>
                        <div className="min-h-0 rounded-lg border bg-slate-100 p-2 shadow-sm">
                            <PlanCanvas
                                layout={state.layout}
                                pins={state.pins}
                                selection={state.selection}
                                editing={state.editing}
                                activeKind={state.activeKind}
                                activeSubkind={state.activeSubkind}
                                interaction={state.interaction}
                                layers={state.layers}
                                taxonomy={taxonomy}
                                mode={mode}
                                emergencyKinds={emergencyKinds}
                                validationErrors={state.validationErrors}
                                dispatch={dispatch}
                                onRequestCalibration={(
                                    firstPoint,
                                    secondPoint,
                                ) =>
                                    setCalibrationDialog({
                                        firstPoint,
                                        secondPoint,
                                        realMeters: '',
                                    })
                                }
                            />
                        </div>
                        <aside className="min-h-0 space-y-3 overflow-y-auto rounded-lg border bg-background p-3 shadow-sm">
                            <Textarea
                                value={notes}
                                onChange={(event) => {
                                    setNotes(event.target.value);
                                    setNotesDirty(true);
                                }}
                                placeholder="Plan notes and resident support notes"
                            />
                            <PlanInspector
                                layout={state.layout}
                                pins={state.pins}
                                selection={state.selection}
                                inventory={inventory}
                                taxonomy={taxonomy}
                                layers={state.layers}
                                inventoryHref={typePlan.inventory_href}
                                inventoryLabel={typePlan.inventory_label}
                                mode={mode}
                                emergencyKinds={emergencyKinds}
                                validationErrors={state.validationErrors}
                                dispatch={dispatch}
                            />
                        </aside>
                    </div>
                    <DialogFooter className="flex flex-wrap items-center justify-end gap-2 border-t bg-background px-5 py-3">
                        <span className="mr-auto text-xs text-muted-foreground">
                            {dirty ? 'Unsaved changes' : 'All changes saved'} ·
                            scale 1 m ≈ {(1 / mpu).toFixed(0)} units
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => dispatch({ type: 'undo' })}
                            disabled={!canUndo || saving}
                        >
                            <Undo2 className="mr-1.5 h-4 w-4" />
                            Undo
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => dispatch({ type: 'redo' })}
                            disabled={!canRedo || saving}
                        >
                            <Redo2 className="mr-1.5 h-4 w-4" />
                            Redo
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                dispatch({ type: 'commit' });
                                dispatch({ type: 'duplicate_selected' });
                            }}
                            disabled={state.selection.length === 0 || saving}
                        >
                            <Copy className="mr-1.5 h-4 w-4" />
                            Duplicate
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={saveDraft}
                            disabled={saving}
                        >
                            <Save className="mr-1.5 h-4 w-4" />
                            Save Draft
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={publish}
                            disabled={saving}
                        >
                            <Send className="mr-1.5 h-4 w-4" />
                            Publish
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog open={confirmClose} onOpenChange={setConfirmClose}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Close without saving?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Unsaved plan edits will be discarded.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep editing</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                setConfirmClose(false);
                                onOpenChange(false);
                            }}
                        >
                            Discard edits
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Scale calibration dialog */}
            <Dialog
                open={!!calibrationDialog}
                onOpenChange={(o) => !o && setCalibrationDialog(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Set scale</DialogTitle>
                        <DialogDescription>
                            Enter the real-world distance between the two points
                            you clicked. The plan's metres-per-unit value will
                            be recalibrated to match.
                        </DialogDescription>
                    </DialogHeader>
                    {calibrationDialog && (
                        <div className="space-y-3">
                            <div className="rounded-md border bg-slate-50 p-3 text-xs">
                                <div className="flex justify-between">
                                    <span>Measured distance</span>
                                    <strong>
                                        {formatMeters(
                                            distanceCanvasUnits(
                                                calibrationDialog.firstPoint,
                                                calibrationDialog.secondPoint,
                                                state.layout.canvas?.width ??
                                                    1000,
                                                state.layout.canvas?.height ??
                                                    700,
                                            ),
                                            mpu,
                                        )}
                                    </strong>
                                </div>
                                <div className="mt-1 text-[10px] text-muted-foreground">
                                    at current scale
                                </div>
                            </div>
                            <div>
                                <Label
                                    htmlFor="real-meters"
                                    className="text-xs"
                                >
                                    Real-world distance (metres)
                                </Label>
                                <Input
                                    id="real-meters"
                                    type="number"
                                    step="0.01"
                                    min={0.01}
                                    autoFocus
                                    value={calibrationDialog.realMeters}
                                    onChange={(event) =>
                                        setCalibrationDialog((current) =>
                                            current
                                                ? {
                                                      ...current,
                                                      realMeters:
                                                          event.target.value,
                                                  }
                                                : current,
                                        )
                                    }
                                    placeholder="e.g. 5"
                                />
                            </div>
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setCalibrationDialog(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            disabled={
                                !calibrationDialog?.realMeters ||
                                Number.parseFloat(
                                    calibrationDialog?.realMeters ?? '',
                                ) <= 0
                            }
                            onClick={() => {
                                if (!calibrationDialog) return;
                                const real = Number.parseFloat(
                                    calibrationDialog.realMeters,
                                );
                                if (!Number.isFinite(real) || real <= 0) return;
                                const units = distanceCanvasUnits(
                                    calibrationDialog.firstPoint,
                                    calibrationDialog.secondPoint,
                                    state.layout.canvas?.width ?? 1000,
                                    state.layout.canvas?.height ?? 700,
                                );
                                if (units <= 0) return;
                                dispatch({ type: 'commit' });
                                dispatch({
                                    type: 'apply_calibration',
                                    metersPerUnit: real / units,
                                });
                                dispatch({
                                    type: 'set_tool',
                                    kind: SELECT_TOOL,
                                });
                                setCalibrationDialog(null);
                                toast.success(
                                    `Scale calibrated: ${formatMeters(units, real / units)} per ${units.toFixed(0)} units.`,
                                );
                            }}
                        >
                            Apply
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function mapFocusTool(focusTool: string): string {
    const map: Record<string, string> = {
        room: '__room',
        wall: '__wall',
        door: '__door',
        window: '__window',
        label: '__label',
    };
    return map[focusTool] ?? focusTool;
}
