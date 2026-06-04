import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import { freqLabel, useChecklistConfig } from './context';
import type { ChecklistTemplate } from './types';

/**
 * Site-scoped "Assign checklist" button + dialog. Renders nothing when not in a
 * site or without the `checklists.schedule` permission. Posts to the existing
 * sites.checklists.assign endpoint.
 */
export function AssignChecklistButton({
    templates,
    templateId,
    label = 'Assign checklist',
    variant = 'default',
    size = 'sm',
    className,
}: {
    templates: ChecklistTemplate[];
    templateId?: number;
    label?: string;
    variant?: 'default' | 'outline' | 'ghost';
    size?: 'sm' | 'default';
    className?: string;
}) {
    const cfg = useChecklistConfig();
    const [open, setOpen] = useState(false);

    const form = useForm({
        template_id: templateId ? String(templateId) : '',
        frequency: 'monthly',
    });

    if (cfg.scope.mode !== 'site' || !cfg.can.schedule) {
        return null;
    }
    const siteId = cfg.scope.site.id;
    const siteType = cfg.scope.site.type;

    const available = templates.filter(
        (t) => t.is_active && (t.applicable_to_type === 'all' || t.applicable_to_type === siteType),
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/sites/${siteId}/checklists/assign`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    const frequencies = ['once', 'daily', 'weekly', 'fortnightly', 'monthly', 'quarterly'];

    return (
        <>
            <Button
                variant={variant}
                size={size}
                className={className}
                onClick={() => {
                    if (templateId) form.setData('template_id', String(templateId));
                    setOpen(true);
                }}
            >
                <Plus className="h-3.5 w-3.5" />
                {label}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Assign checklist to {cfg.scope.mode === 'site' ? cfg.scope.site.name : ''}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Template</Label>
                            <Select
                                value={form.data.template_id || undefined}
                                onValueChange={(v) => form.setData('template_id', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a template…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {available.map((t) => (
                                        <SelectItem key={t.id} value={String(t.id)}>
                                            {t.name} ({t.items_count} items)
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Frequency</Label>
                            <Select value={form.data.frequency} onValueChange={(v) => form.setData('frequency', v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {frequencies.map((f) => (
                                        <SelectItem key={f} value={f}>
                                            {freqLabel(cfg, f)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        {form.errors.template_id ? (
                            <p className="text-sm text-status-critical">{form.errors.template_id}</p>
                        ) : null}
                        <div className="flex gap-2 pt-1">
                            <Button type="submit" disabled={form.processing || !form.data.template_id}>
                                Assign
                            </Button>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
