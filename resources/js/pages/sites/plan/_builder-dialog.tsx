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
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { Save, Send, Undo2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import PlanCanvas from './_canvas';
import PlanInspector from './_inspector';
import ToolPalette, { type BuilderTool } from './_tool-palette';
import { normaliseLayout, type PlanLayout, type PlanPin } from './_thumbnail';

type TypePlanSummary = {
    tab_label: string;
    draft?: { layout: PlanLayout; notes?: string | null; pins: PlanPin[] } | null;
    published?: { layout: PlanLayout; notes?: string | null; pins: PlanPin[] } | null;
};

type Props = {
    site: { id: number; name: string; type: string };
    typePlan: TypePlanSummary;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    focusTool?: BuilderTool;
};

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
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
        throw new Error(text || `Request failed with ${response.status}`);
    }

    return response.json();
}

export default function SiteTypePlanBuilderDialog({ site, typePlan, open, onOpenChange, focusTool }: Props) {
    const source = typePlan.draft ?? typePlan.published;
    const [layout, setLayout] = useState<PlanLayout>(() => normaliseLayout(source?.layout));
    const [pins, setPins] = useState<PlanPin[]>(() => source?.pins ?? []);
    const [notes, setNotes] = useState(source?.notes ?? '');
    const [tool, setTool] = useState<BuilderTool>(focusTool ?? 'room');
    const [history, setHistory] = useState<Array<{ layout: PlanLayout; pins: PlanPin[] }>>([]);
    const [saving, setSaving] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    const [dirty, setDirty] = useState(false);

    const title = useMemo(() => `${source ? 'Edit' : 'Build'} ${typePlan.tab_label}`, [source, typePlan.tab_label]);

    useEffect(() => {
        if (open && focusTool) setTool(focusTool);
    }, [focusTool, open]);

    function pushHistory(nextLayout: PlanLayout, nextPins: PlanPin[]) {
        setHistory((current) => [...current.slice(-9), { layout, pins }]);
        setLayout(nextLayout);
        setPins(nextPins);
        setDirty(true);
    }

    function addAt(point: { x: number; y: number }) {
        const id = `${tool}-${Date.now()}`;
        if (tool === 'room') {
            pushHistory(
                {
                    ...layout,
                    rooms: [
                        ...(layout.rooms ?? []),
                        { id, label: `Room ${(layout.rooms ?? []).length + 1}`, shape: 'rect', x: point.x, y: point.y, width: 0.18, height: 0.14 },
                    ],
                },
                pins,
            );
            return;
        }
        if (tool === 'wall') {
            pushHistory(
                {
                    ...layout,
                    walls: [
                        ...(layout.walls ?? []),
                        { id, points: [{ x: Math.max(0, point.x - 0.08), y: point.y }, { x: Math.min(1, point.x + 0.08), y: point.y }], thickness: 4 },
                    ],
                },
                pins,
            );
            return;
        }
        if (tool === 'door') {
            pushHistory({ ...layout, doors: [...(layout.doors ?? []), { id, x: point.x, y: point.y, width: 0.08, swing: 'right' }] }, pins);
            return;
        }
        if (tool === 'window') {
            pushHistory({ ...layout, windows: [...(layout.windows ?? []), { id, x: point.x, y: point.y, width: 0.1 }] }, pins);
            return;
        }
        if (tool === 'label') {
            pushHistory({ ...layout, labels: [...(layout.labels ?? []), { id, x: point.x, y: point.y, text: 'Label', size: 16 }] }, pins);
            return;
        }

        pushHistory(layout, [
            ...pins,
            {
                kind: tool,
                label: tool.replaceAll('_', ' '),
                x: point.x,
                y: point.y,
                meta: tool === 'medication_storage' ? { is_locked: true } : {},
            },
        ]);
    }

    function undo() {
        const previous = history[history.length - 1];
        if (!previous) return;
        setLayout(previous.layout);
        setPins(previous.pins);
        setHistory((current) => current.slice(0, -1));
        setDirty(true);
    }

    async function saveDraft(): Promise<boolean> {
        setSaving(true);
        try {
            await jsonRequest(`/sites/${site.id}/plan/draft`, 'POST', { layout, notes });
            await jsonRequest(`/sites/${site.id}/plan/pins`, 'POST', { replace: true, pins });
            toast.success('Draft plan saved.');
            setDirty(false);
            router.reload({ only: ['typePlan', 'readiness'] });
            return true;
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Could not save draft plan.');
            return false;
        } finally {
            setSaving(false);
        }
    }

    async function publish() {
        const saved = await saveDraft();
        if (!saved) return;
        setSaving(true);
        try {
            await jsonRequest(`/sites/${site.id}/plan/publish`, 'POST');
            toast.success('Plan published.');
            setDirty(false);
            router.reload({ only: ['typePlan', 'readiness'] });
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Could not publish plan.');
        } finally {
            setSaving(false);
        }
    }

    function requestOpenChange(nextOpen: boolean) {
        if (!nextOpen && dirty) {
            setConfirmClose(true);
            return;
        }
        onOpenChange(nextOpen);
    }

    return (
        <>
            <Dialog open={open} onOpenChange={requestOpenChange}>
                <DialogContent className="grid h-[min(900px,90vh)] max-w-[min(1400px,95vw)] grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>Choose a tool, then click the plan to place it.</DialogDescription>
                    </DialogHeader>
                    <div className="grid min-h-0 gap-4 lg:grid-cols-[1fr_320px]">
                        <div className="min-h-0 space-y-3">
                            <ToolPalette value={tool} onChange={setTool} />
                            <PlanCanvas layout={layout} pins={pins} onCanvasClick={addAt} />
                        </div>
                        <aside className="min-h-0 space-y-3 overflow-y-auto rounded-md border p-3">
                            <Textarea value={notes} onChange={(event) => { setNotes(event.target.value); setDirty(true); }} placeholder="Plan notes and resident support notes" />
                            <PlanInspector layout={layout} pins={pins} onRemovePin={(index) => pushHistory(layout, pins.filter((_, i) => i !== index))} />
                        </aside>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={undo} disabled={history.length === 0 || saving}>
                            <Undo2 className="mr-2 h-4 w-4" />
                            Undo
                        </Button>
                        <Button type="button" variant="outline" onClick={saveDraft} disabled={saving}>
                            <Save className="mr-2 h-4 w-4" />
                            Save Draft
                        </Button>
                        <Button type="button" onClick={publish} disabled={saving}>
                            <Send className="mr-2 h-4 w-4" />
                            Publish
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <AlertDialog open={confirmClose} onOpenChange={setConfirmClose}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Close without saving?</AlertDialogTitle>
                        <AlertDialogDescription>Unsaved plan edits will be discarded.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep editing</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                setConfirmClose(false);
                                setDirty(false);
                                onOpenChange(false);
                            }}
                        >
                            Discard edits
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
