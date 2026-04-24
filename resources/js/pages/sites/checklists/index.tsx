import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { ClipboardCheck, Calendar, Plus, PlayCircle, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';

type Site = {
    id: number;
    name: string;
    type: string;
};

type Template = {
    id: number;
    name: string;
    description?: string;
    frequency: string;
    items_count: number;
};

type Assignment = {
    id: number;
    template: {
        id: number;
        name: string;
        description?: string;
    };
    frequency: string;
    assignedTo?: { id: number; name: string } | null;
    next_due?: string;
    is_active: boolean;
};

type Props = {
    site: Site;
    assignments: Assignment[];
    templates: Template[];
};

const frequencyLabels: Record<string, string> = {
    once: 'One-time',
    daily: 'Daily',
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
};

export default function SiteChecklists({ site, assignments, templates }: Props) {
    const [assignOpen, setAssignOpen] = useState(false);

    const form = useForm({
        template_id: '',
        frequency: 'monthly',
    });

    const handleAssign = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/sites/${site.id}/checklists/assign`, {
            onSuccess: () => {
                setAssignOpen(false);
                form.reset();
            },
        });
    };

    const removeAssignment = (id: number) => {
        router.delete(`/sites/${site.id}/checklists/assignments/${id}`);
    };

    const startRun = (assignmentId: number) => {
        router.post(`/sites/${site.id}/checklists/assignments/${assignmentId}/run`);
    };

    // Templates not yet assigned
    const assignedTemplateIds = assignments.map(a => a.template.id);
    const availableTemplates = templates.filter(t => !assignedTemplateIds.includes(t.id));

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Checklists', href: `/sites/${site.id}/checklists` }]}>
            <Head title={`${site.name} - Checklists`} />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <ClipboardCheck className="w-5 h-5" />
                            Checklists & Walkthroughs
                        </h1>
                        <p className="text-sm text-muted-foreground">{site.name}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="secondary" asChild>
                            <Link href={`/sites/${site.id}/checklists/runs`}>View Runs</Link>
                        </Button>
                        <Button onClick={() => setAssignOpen(true)}>
                            <Plus className="w-4 h-4 mr-1" />
                            Assign Checklist
                        </Button>
                    </div>
                </div>

                {/* Assign Dialog */}
                <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Assign Checklist Template</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleAssign} className="space-y-4">
                            <div>
                                <Label>Template *</Label>
                                {availableTemplates.length === 0 ? (
                                    <p className="text-sm text-muted-foreground mt-1">
                                        All available templates are already assigned.{' '}
                                        <Link href="/sites/checklists/templates/create" className="text-primary hover:underline">
                                            Create a new template
                                        </Link>
                                    </p>
                                ) : (
                                    <Select
                                        value={form.data.template_id || undefined}
                                        onValueChange={(v) => form.setData('template_id', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select a template..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {availableTemplates.map((t) => (
                                                <SelectItem key={t.id} value={String(t.id)}>
                                                    {t.name} ({t.items_count} items)
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                            </div>
                            <div>
                                <Label>Frequency *</Label>
                                <Select
                                    value={form.data.frequency}
                                    onValueChange={(v) => form.setData('frequency', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="once">One-time</SelectItem>
                                        <SelectItem value="daily">Daily</SelectItem>
                                        <SelectItem value="weekly">Weekly</SelectItem>
                                        <SelectItem value="fortnightly">Fortnightly</SelectItem>
                                        <SelectItem value="monthly">Monthly</SelectItem>
                                        <SelectItem value="quarterly">Quarterly</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex gap-2 pt-2">
                                <Button type="submit" disabled={form.processing || !form.data.template_id}>
                                    Assign
                                </Button>
                                <Button type="button" variant="outline" onClick={() => setAssignOpen(false)}>
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* Active Assignments */}
                <div className="space-y-3">
                    {assignments.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center text-muted-foreground">
                                <ClipboardCheck className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No checklists scheduled for this site</p>
                                <p className="text-sm mt-1">
                                    Click "Assign Checklist" to add one, or{' '}
                                    <Link href="/sites/checklists/templates" className="text-primary hover:underline">
                                        manage templates
                                    </Link>
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        assignments.map((assignment) => (
                            <Card key={assignment.id}>
                                <CardContent className="p-4">
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 mb-2">
                                                <h3 className="font-medium">{assignment.template.name}</h3>
                                                <Badge variant="outline">{frequencyLabels[assignment.frequency] || assignment.frequency}</Badge>
                                            </div>
                                            {assignment.template.description && (
                                                <p className="text-sm text-muted-foreground mb-2">{assignment.template.description}</p>
                                            )}
                                            <div className="flex items-center gap-4 text-xs text-muted-foreground">
                                                {assignment.assignedTo && (
                                                    <span>Assigned to: {assignment.assignedTo.name}</span>
                                                )}
                                                {assignment.next_due && (
                                                    <span className="flex items-center gap-1">
                                                        <Calendar className="w-3 h-3" />
                                                        Next due: {new Date(assignment.next_due).toLocaleDateString()}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => startRun(assignment.id)}
                                            >
                                                <PlayCircle className="w-4 h-4 mr-1" />
                                                Start Run
                                            </Button>
                                            <AlertDialog>
                                                <AlertDialogTrigger asChild>
                                                    <Button variant="ghost" size="sm" className="text-status-critical hover:text-status-critical">
                                                        <Trash2 className="w-4 h-4" />
                                                    </Button>
                                                </AlertDialogTrigger>
                                                <AlertDialogContent>
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>Remove Assignment</AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            Remove "{assignment.template.name}" from this site? Existing runs will be preserved.
                                                        </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <AlertDialogFooter>
                                                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                        <AlertDialogAction
                                                            className="bg-status-critical hover:bg-status-critical"
                                                            onClick={() => removeAssignment(assignment.id)}
                                                        >
                                                            Remove
                                                        </AlertDialogAction>
                                                    </AlertDialogFooter>
                                                </AlertDialogContent>
                                            </AlertDialog>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
