import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import { ClipboardCheck, Loader2, Play, Sparkles } from 'lucide-react';
import { useState } from 'react';

// ── Start Run confirmation dialog ─────────────────────────────────────────

export function StartRunDialog({
    siteId,
    isOpen,
    onClose,
    assignmentId,
    templateName,
    frequencyLabel,
}: {
    siteId: number;
    isOpen: boolean;
    onClose: () => void;
    assignmentId: number | null;
    templateName: string | null;
    frequencyLabel?: string | null;
}) {
    const [submitting, setSubmitting] = useState(false);

    const handleStart = () => {
        if (!assignmentId) return;
        setSubmitting(true);
        router.post(
            `/sites/${siteId}/checklists/assignments/${assignmentId}/run`,
            {},
            {
                preserveScroll: false,
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <span className="rounded-xl border bg-background/60 p-2">
                            <Play className="h-5 w-5 text-primary" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <DialogTitle className="truncate">
                                Start a checklist run
                            </DialogTitle>
                            <DialogDescription>
                                {templateName ? (
                                    <>
                                        <span className="font-medium text-foreground">
                                            {templateName}
                                        </span>
                                        {frequencyLabel ? (
                                            <> · {frequencyLabel}</>
                                        ) : null}
                                    </>
                                ) : (
                                    'Begin a new run for this assignment.'
                                )}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <p className="text-sm text-muted-foreground">
                    This opens the run sheet so you can record results. Any
                    in-progress run for this assignment will be resumed instead
                    of duplicated.
                </p>

                <DialogFooter className="mt-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={handleStart}
                        disabled={submitting || !assignmentId}
                    >
                        {submitting ? (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        ) : (
                            <Play className="mr-2 h-4 w-4" />
                        )}
                        Start run
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ── Create Template dialog ────────────────────────────────────────────────

type CreateTemplateValues = {
    key: string;
    name: string;
    description: string;
    applicable_to_type: 'all' | 'house' | 'head_office' | 'facility';
    frequency: 'once' | 'daily' | 'weekly' | 'fortnightly' | 'monthly' | 'quarterly';
    is_active: boolean;
};

export function CreateTemplateDialog({
    isOpen,
    onClose,
    onCreated,
}: {
    isOpen: boolean;
    onClose: () => void;
    onCreated?: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {isOpen && (
                    <CreateTemplateBody onClose={onClose} onCreated={onCreated} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function CreateTemplateBody({
    onClose,
    onCreated,
}: {
    onClose: () => void;
    onCreated?: () => void;
}) {
    const form = useForm<CreateTemplateValues>({
        key: '',
        name: '',
        description: '',
        applicable_to_type: 'all',
        frequency: 'weekly',
        is_active: true,
    });

    // Auto-fill key from name (snake_case) until the user edits the key field
    const [keyTouched, setKeyTouched] = useState(false);

    const handleNameChange = (value: string) => {
        form.setData('name', value);
        if (!keyTouched) {
            const auto = value
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '');
            form.setData('key', auto);
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/sites/checklists/templates', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onCreated?.();
                onClose();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <div className="flex items-center gap-3">
                    <span className="rounded-xl border bg-background/60 p-2">
                        <Sparkles className="h-5 w-5 text-primary" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <DialogTitle>New checklist template</DialogTitle>
                        <DialogDescription>
                            Templates can be assigned to sites of a chosen type.
                            Add items to the template after creating it.
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <div className="mt-4 space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="tpl-name">
                            Template name{' '}
                            <span className="text-status-critical">*</span>
                        </Label>
                        <Input
                            id="tpl-name"
                            value={form.data.name}
                            onChange={(e) => handleNameChange(e.target.value)}
                            placeholder="e.g. Daily safety check"
                            required
                        />
                        {form.errors.name && (
                            <p className="mt-1 text-xs text-status-critical">
                                {form.errors.name}
                            </p>
                        )}
                    </div>
                    <div>
                        <Label htmlFor="tpl-key">
                            Key <span className="text-status-critical">*</span>
                        </Label>
                        <Input
                            id="tpl-key"
                            value={form.data.key}
                            onChange={(e) => {
                                setKeyTouched(true);
                                form.setData('key', e.target.value);
                            }}
                            placeholder="daily_safety_check"
                            required
                        />
                        <p className="mt-1 text-[11px] text-muted-foreground">
                            Lowercase, no spaces — used for matching across
                            sites.
                        </p>
                        {form.errors.key && (
                            <p className="mt-1 text-xs text-status-critical">
                                {form.errors.key}
                            </p>
                        )}
                    </div>
                </div>

                <div>
                    <Label htmlFor="tpl-desc">Description</Label>
                    <Textarea
                        id="tpl-desc"
                        rows={2}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        placeholder="What this checklist is for and when to use it"
                    />
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="tpl-applies">Applies to</Label>
                        <Select
                            value={form.data.applicable_to_type}
                            onValueChange={(v) =>
                                form.setData(
                                    'applicable_to_type',
                                    v as CreateTemplateValues['applicable_to_type'],
                                )
                            }
                        >
                            <SelectTrigger id="tpl-applies">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All site types
                                </SelectItem>
                                <SelectItem value="house">
                                    Houses only
                                </SelectItem>
                                <SelectItem value="head_office">
                                    Head offices only
                                </SelectItem>
                                <SelectItem value="facility">
                                    Facilities only
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label htmlFor="tpl-freq">Default frequency</Label>
                        <Select
                            value={form.data.frequency}
                            onValueChange={(v) =>
                                form.setData(
                                    'frequency',
                                    v as CreateTemplateValues['frequency'],
                                )
                            }
                        >
                            <SelectTrigger id="tpl-freq">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="once">One-time</SelectItem>
                                <SelectItem value="daily">Daily</SelectItem>
                                <SelectItem value="weekly">Weekly</SelectItem>
                                <SelectItem value="fortnightly">
                                    Fortnightly
                                </SelectItem>
                                <SelectItem value="monthly">Monthly</SelectItem>
                                <SelectItem value="quarterly">
                                    Quarterly
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        id="tpl-active"
                        checked={form.data.is_active}
                        onCheckedChange={(checked) =>
                            form.setData('is_active', !!checked)
                        }
                    />
                    <Label
                        htmlFor="tpl-active"
                        className="text-sm font-normal"
                    >
                        Active and available for assignment
                    </Label>
                </div>

                <p className="rounded-md border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                    <ClipboardCheck className="-mt-0.5 mr-1 inline h-3.5 w-3.5" />
                    After creating, edit the template to add checklist items.
                </p>
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Create template
                </Button>
            </DialogFooter>
        </form>
    );
}
