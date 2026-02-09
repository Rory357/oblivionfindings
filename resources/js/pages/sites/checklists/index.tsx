import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ClipboardCheck, Calendar } from 'lucide-react';

type Site = {
    id: number;
    name: string;
    type: string;
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
};

type Props = {
    site: Site;
    assignments: Assignment[];
};

const frequencyLabels: Record<string, string> = {
    once: 'One-time',
    daily: 'Daily',
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
};

export default function SiteChecklists({ site, assignments }: Props) {
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
                        <p className="text-sm text-slate-400">{site.name}</p>
                    </div>
                    <Button asChild>
                        <Link href={`/sites/${site.id}/checklists/runs`}>View Runs</Link>
                    </Button>
                </div>

                {/* Active Assignments */}
                <div className="space-y-3">
                    {assignments.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center text-slate-400">
                                <ClipboardCheck className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No checklists scheduled for this site</p>
                                <p className="text-sm mt-1">Complete onboarding to set up checklists</p>
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
                                                <p className="text-sm text-slate-400 mb-2">{assignment.template.description}</p>
                                            )}
                                            <div className="flex items-center gap-4 text-xs text-slate-500">
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
