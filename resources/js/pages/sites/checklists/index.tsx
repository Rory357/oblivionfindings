import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
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
    recommendedChecklists?: ChecklistSuggestion[];
};

type ChecklistSuggestion = {
    key: string;
    label: string;
    hint: string;
    frequency: string;
    matches: string[];
};

const frequencyLabels: Record<string, string> = {
    once: 'One-time',
    daily: 'Daily',
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
};

export default function SiteChecklists({
    site,
    assignments,
    templates,
    recommendedChecklists = [],
}: Props) {
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

    const openSuggestedAssignment = (
        suggestion: ChecklistSuggestion,
    ) => {
        const template = templates.find((candidate) => {
            const name = candidate.name.toLowerCase();
            return suggestion.matches.some((match) => name.includes(match));
        });

        form.setData('template_id', template ? String(template.id) : '');
        form.setData('frequency', suggestion.frequency);
        setAssignOpen(true);
    };

    // Templates not yet assigned
    const assignedTemplateIds = assignments.map(a => a.template.id);
    const availableTemplates = templates.filter(t => !assignedTemplateIds.includes(t.id));

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Checklists', href: `/sites/${site.id}/checklists` }]}>
            <Head title={`${site.name} - Checklists`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={ClipboardCheck}
                        title="Checklists & Walkthroughs"
                        description={site.name}
                        backHref={`/sites/${site.id}`}
                        stats={[
                            { label: 'Assigned', value: assignments.length },
                            {
                                label: 'Available',
                                value: availableTemplates.length,
                            },
                        ]}
                        actions={
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Link
                                        href={`/sites/${site.id}/checklists/runs`}
                                    >
                                        View runs
                                    </Link>
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() => setAssignOpen(true)}
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Assign checklist
                                </Button>
                            </div>
                        }
                    />
                }
            >
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
                        <Card className="border-dashed">
                            <CardContent className="space-y-4 p-6">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <ClipboardCheck className="h-5 w-5" />
                                    </span>
                                    <div>
                                        <h3 className="font-semibold">
                                            No checklists scheduled for this site
                                        </h3>
                                        <p className="text-sm text-muted-foreground">
                                            Recommended for supported-living sites. Schedule these to keep this site audit-ready.
                                        </p>
                                    </div>
                                </div>
                                <ul className="grid gap-2 sm:grid-cols-2">
                                    {recommendedChecklists.map((suggestion) => (
                                        <li
                                            key={suggestion.key}
                                            className="flex items-center justify-between gap-3 rounded-lg border bg-card/40 px-3 py-2"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {suggestion.label}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {suggestion.hint}
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    openSuggestedAssignment(suggestion)
                                                }
                                            >
                                                Schedule this
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-sm text-muted-foreground">
                                    Need a different routine?{' '}
                                    <Link
                                        href="/sites/checklists/templates"
                                        className="text-primary hover:underline"
                                    >
                                        Manage templates
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
            </PageLayout>
        </AppLayout>
    );
}
