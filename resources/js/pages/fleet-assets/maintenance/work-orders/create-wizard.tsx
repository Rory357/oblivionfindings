/* Work-order create wizard — Add-Client-modal pattern (WizardShell) replacing the
 * old full-page create form. Three steps: What & where → Priority & schedule →
 * Costs & review. POSTs to the existing store route; the controller redirects to
 * the new work order's detail page on success. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import { formatDate } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    CalendarClock,
    Car,
    ClipboardCheck,
    DollarSign,
    Loader2,
    Search,
    Wrench,
    Zap,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

export type WizardAsset = {
    id: number;
    name: string;
    asset_tag: string | null;
    category: string | null;
};

export type WizardChecklistRun = {
    id: number;
    asset_name: string;
    template_name: string;
    run_at: string | null;
};

const STEPS: readonly WizardStep[] = [
    { key: 'what', label: 'What & where', blurb: 'Asset and the job', icon: Car },
    { key: 'schedule', label: 'Priority & schedule', blurb: 'Urgency, due date, assignee', icon: CalendarClock },
    { key: 'review', label: 'Costs & review', blurb: 'Estimates and confirm', icon: DollarSign },
];

const PRIORITY_OPTIONS = [
    {
        value: 'critical',
        label: 'Critical',
        icon: Zap,
        color: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
    },
    {
        value: 'high',
        label: 'High',
        icon: ArrowUp,
        color: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    },
    {
        value: 'medium',
        label: 'Medium',
        icon: AlertTriangle,
        color: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    },
    {
        value: 'low',
        label: 'Low',
        icon: ArrowDown,
        color: 'border-status-info/30 bg-status-info-bg text-status-info',
    },
] as const;

const NONE = '__none__'; // Radix Select crashes on value="" — sentinel for "no selection".

export function WorkOrderCreateWizard({
    open,
    onClose,
    assets,
    users,
    checklistRuns,
    prefillAssetId,
    prefillChecklistRunId,
}: {
    open: boolean;
    onClose: () => void;
    assets: WizardAsset[];
    users: Array<{ id: number; name: string }>;
    checklistRuns: WizardChecklistRun[];
    prefillAssetId?: string | null;
    prefillChecklistRunId?: string | null;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [assetSearch, setAssetSearch] = useState('');
    const [userSearch, setUserSearch] = useState('');
    const [assetOptions, setAssetOptions] = useState(assets);
    const [userOptions, setUserOptions] = useState(users);

    const form = useForm({
        asset_id: prefillAssetId ?? '',
        title: '',
        description: '',
        priority: 'medium',
        assigned_to_user_id: '',
        due_at: '',
        estimated_cost: '',
        estimated_hours: '',
        checklist_run_id: prefillChecklistRunId ?? '',
        notes: '',
    });

    useEffect(() => {
        const query = assetSearch.trim();
        if (query.length < 2) {
            setAssetOptions(assets);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(`/fleet-assets/maintenance/work-orders/options/search?type=assets&q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (response.ok) setAssetOptions((await response.json()).results ?? []);
            } catch (error) {
                if ((error as Error).name !== 'AbortError') setAssetOptions([]);
            }
        }, 300);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [assetSearch, assets]);

    useEffect(() => {
        const query = userSearch.trim();
        if (query.length < 2) {
            setUserOptions(users);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(`/fleet-assets/maintenance/work-orders/options/search?type=users&q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (response.ok) setUserOptions((await response.json()).results ?? []);
            } catch (error) {
                if ((error as Error).name !== 'AbortError') setUserOptions([]);
            }
        }, 300);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [userSearch, users]);

    const selectedAsset = [...assetOptions, ...assets].find((a) => String(a.id) === form.data.asset_id) ?? null;
    const selectedUser = [...userOptions, ...users].find((u) => String(u.id) === form.data.assigned_to_user_id) ?? null;
    const visibleAssetOptions = selectedAsset && !assetOptions.some((asset) => asset.id === selectedAsset.id)
        ? [selectedAsset, ...assetOptions]
        : assetOptions;
    const visibleUserOptions = selectedUser && !userOptions.some((user) => user.id === selectedUser.id)
        ? [selectedUser, ...userOptions]
        : userOptions;
    const filteredAssets = visibleAssetOptions;
    const selectedRun = checklistRuns.find((r) => String(r.id) === form.data.checklist_run_id) ?? null;
    const priority = PRIORITY_OPTIONS.find((p) => p.value === form.data.priority);

    const stepOneValid = form.data.asset_id !== '' && form.data.title.trim() !== '';

    const close = () => {
        setStepIndex(0);
        onClose();
    };

    const submit = () => {
        form.post('/fleet-assets/maintenance/work-orders', {
            // Store redirects to the new work order's show page on success; on
            // validation failure, jump back to the step that owns the first error.
            onError: (errors) => {
                if (errors.asset_id || errors.title || errors.description) setStepIndex(0);
                else if (errors.priority || errors.due_at || errors.assigned_to_user_id || errors.checklist_run_id) setStepIndex(1);
                else setStepIndex(2);
            },
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New work order"
            description="Create a maintenance work order for a vehicle or asset."
            railIcon={Wrench}
            railTitle="New work order"
            railSub="Maintenance"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                if (i < stepIndex || (i === stepIndex + 1 && stepOneValid) || (i > 0 && stepOneValid)) setStepIndex(i);
            }}
            footerStart={
                stepIndex > 0 ? (
                    <Button variant="ghost" onClick={() => setStepIndex(stepIndex - 1)}>
                        <ArrowLeft className="mr-1.5 h-4 w-4" /> Back
                    </Button>
                ) : (
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                )
            }
            footerEnd={
                stepIndex < STEPS.length - 1 ? (
                    <Button onClick={() => setStepIndex(stepIndex + 1)} disabled={!stepOneValid}>
                        Continue <ArrowRight className="ml-1.5 h-4 w-4" />
                    </Button>
                ) : (
                    <Button onClick={submit} disabled={form.processing || !stepOneValid}>
                        {form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        Create work order
                    </Button>
                )
            }
        >
            {stepIndex === 0 && (
                <WizardStepPane>
                    <div className="space-y-5">
                        <div>
                            <Label className="mb-1.5 block">Asset *</Label>
                            <div className="relative mb-2">
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    value={assetSearch}
                                    onChange={(e) => setAssetSearch(e.target.value)}
                                    placeholder="Search assets..."
                                    className="pl-8"
                                />
                            </div>
                            <div className="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-border p-1.5">
                                {filteredAssets.map((a) => {
                                    const active = form.data.asset_id === String(a.id);
                                    return (
                                        // eslint-disable-next-line no-restricted-syntax -- selectable tile row (Send-Kudos-style picker), not a shadcn Button.
                                        <button
                                            key={a.id}
                                            type="button"
                                            onClick={() => form.setData('asset_id', String(a.id))}
                                            className={cn(
                                                'flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm transition-colors',
                                                active ? 'bg-primary/10 font-semibold text-primary' : 'hover:bg-accent',
                                            )}
                                        >
                                            <Car className={cn('h-4 w-4 shrink-0', active ? 'text-primary' : 'text-muted-foreground')} />
                                            <span className="min-w-0 flex-1 truncate">
                                                {a.name}
                                                {a.asset_tag ? ` (${a.asset_tag})` : ''}
                                            </span>
                                            {a.category ? (
                                                <span className="shrink-0 text-[11px] text-muted-foreground capitalize">{a.category}</span>
                                            ) : null}
                                        </button>
                                    );
                                })}
                                {filteredAssets.length === 0 && (
                                    <p className="px-2 py-4 text-center text-xs text-muted-foreground">No assets match your search.</p>
                                )}
                            </div>
                            {form.errors.asset_id && <p className="mt-1 text-xs text-destructive">{form.errors.asset_id}</p>}
                        </div>

                        <div>
                            <Label className="mb-1.5 block">Title *</Label>
                            <Input
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder="Work order title"
                            />
                            {form.errors.title && <p className="mt-1 text-xs text-destructive">{form.errors.title}</p>}
                        </div>

                        <div>
                            <Label className="mb-1.5 block">Description</Label>
                            <textarea
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                rows={3}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Describe the work needed..."
                            />
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {stepIndex === 1 && (
                <WizardStepPane>
                    <div className="space-y-5">
                        <div>
                            <Label className="mb-1.5 block">Priority *</Label>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {PRIORITY_OPTIONS.map((opt) => {
                                    const IconComp = opt.icon;
                                    return (
                                        <Button
                                            key={opt.value}
                                            type="button"
                                            variant="outline"
                                            onClick={() => form.setData('priority', opt.value)}
                                            className={cn(
                                                'h-auto flex-col gap-2 rounded-xl border-2 px-4 py-4 whitespace-normal transition-all',
                                                form.data.priority === opt.value
                                                    ? `${opt.color} shadow-md`
                                                    : 'border-transparent bg-muted text-muted-foreground hover:bg-muted/80',
                                            )}
                                        >
                                            <IconComp className="h-5 w-5" />
                                            {opt.label}
                                        </Button>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label className="mb-1.5 block">Due date</Label>
                                <Input
                                    type="date"
                                    value={form.data.due_at}
                                    onChange={(e) => form.setData('due_at', e.target.value)}
                                />
                                {form.errors.due_at && <p className="mt-1 text-xs text-destructive">{form.errors.due_at}</p>}
                            </div>
                            <div>
                                <Label className="mb-1.5 block">Assigned to</Label>
                                <Input
                                    value={userSearch}
                                    onChange={(event) => setUserSearch(event.target.value)}
                                    placeholder="Search people..."
                                    className="mb-2"
                                />
                                <Select
                                    value={form.data.assigned_to_user_id === '' ? NONE : form.data.assigned_to_user_id}
                                    onValueChange={(v) => form.setData('assigned_to_user_id', v === NONE ? '' : v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Unassigned" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Unassigned</SelectItem>
                                        {visibleUserOptions.map((u) => (
                                            <SelectItem key={u.id} value={String(u.id)}>
                                                {u.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        {checklistRuns.length > 0 && (
                            <div>
                                <Label className="mb-1.5 flex items-center gap-1.5">
                                    <ClipboardCheck className="h-3.5 w-3.5" />
                                    Related failed inspection
                                </Label>
                                <p className="mb-2 text-xs text-muted-foreground">
                                    Optionally link this work order to a failed checklist run.
                                </p>
                                <Select
                                    value={form.data.checklist_run_id === '' ? NONE : form.data.checklist_run_id}
                                    onValueChange={(v) => form.setData('checklist_run_id', v === NONE ? '' : v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="None" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>None</SelectItem>
                                        {checklistRuns.map((run) => (
                                            <SelectItem key={run.id} value={String(run.id)}>
                                                {run.template_name} - {run.asset_name}
                                                {run.run_at ? ` (${formatDate(run.run_at)})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.checklist_run_id && (
                                    <p className="mt-1 text-xs text-destructive">{form.errors.checklist_run_id}</p>
                                )}
                            </div>
                        )}
                    </div>
                </WizardStepPane>
            )}

            {stepIndex === 2 && (
                <WizardStepPane>
                    <div className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label className="mb-1.5 block">Estimated cost ($)</Label>
                                <Input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={form.data.estimated_cost}
                                    onChange={(e) => form.setData('estimated_cost', e.target.value)}
                                    placeholder="0.00"
                                />
                                {form.errors.estimated_cost && (
                                    <p className="mt-1 text-xs text-destructive">{form.errors.estimated_cost}</p>
                                )}
                            </div>
                            <div>
                                <Label className="mb-1.5 block">Estimated hours</Label>
                                <Input
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    value={form.data.estimated_hours}
                                    onChange={(e) => form.setData('estimated_hours', e.target.value)}
                                    placeholder="0"
                                />
                                {form.errors.estimated_hours && (
                                    <p className="mt-1 text-xs text-destructive">{form.errors.estimated_hours}</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <Label className="mb-1.5 block">Notes</Label>
                            <textarea
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Additional notes..."
                            />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard icon={Car} title="What & where" onEdit={() => setStepIndex(0)}>
                                <ReviewRow
                                    label="Asset"
                                    value={
                                        selectedAsset
                                            ? `${selectedAsset.name}${selectedAsset.asset_tag ? ` (${selectedAsset.asset_tag})` : ''}`
                                            : undefined
                                    }
                                />
                                <ReviewRow label="Title" value={form.data.title || undefined} />
                                <ReviewRow label="Description" value={form.data.description || undefined} />
                            </ReviewCard>
                            <ReviewCard icon={CalendarClock} title="Priority & schedule" onEdit={() => setStepIndex(1)}>
                                <ReviewRow label="Priority" value={priority?.label} />
                                <ReviewRow label="Due" value={form.data.due_at || undefined} />
                                <ReviewRow label="Assignee" value={selectedUser?.name} />
                                <ReviewRow
                                    label="Linked run"
                                    value={selectedRun ? `${selectedRun.template_name} - ${selectedRun.asset_name}` : undefined}
                                />
                            </ReviewCard>
                        </div>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
