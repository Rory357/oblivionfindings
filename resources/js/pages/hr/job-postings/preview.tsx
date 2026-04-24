import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft, Globe, Pencil, Eye, MapPin, Briefcase, Clock, DollarSign, Wifi, Lock } from 'lucide-react';
import { employmentTypeLabels } from '@/lib/job-posting-constants';
import type { ScreeningQuestion } from '@/types/job-postings';

type Props = {
    posting: {
        id: number;
        title: string;
        slug: string | null;
        summary: string | null;
        department: string | null;
        location: string | null;
        employment_type: string;
        is_remote: boolean;
        is_internal: boolean;
        description: string;
        requirements: string | null;
        responsibilities: string | null;
        salary_range: string | null;
        show_salary: boolean;
        status: string;
        closes_at: string | null;
        screening_questions: ScreeningQuestion[];
    };
};

export default function PreviewJobPosting({ posting }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Job Postings', href: '/hr/job-postings' },
        { title: 'Preview', href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Preview: ${posting.title}`} />
            <div className="flex flex-col gap-6 p-6 max-w-3xl mx-auto">
                {/* Preview Banner */}
                <div className="flex items-center justify-between gap-4 p-4 bg-status-warning border border-status-warning/30 rounded-lg">
                    <div className="flex items-center gap-2">
                        <Eye className="h-5 w-5 text-status-warning" />
                        <div>
                            <p className="font-medium text-status-warning">Preview Mode</p>
                            <p className="text-xs text-muted-foreground">This is how the posting will appear to candidates on the career portal</p>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/hr/job-postings/${posting.id}/edit`}><Pencil className="mr-1.5 h-3.5 w-3.5" /> Edit</Link>
                        </Button>
                        {posting.status === 'draft' && (
                            <Button size="sm" onClick={() => router.post(`/hr/job-postings/${posting.id}/publish`)}>
                                <Globe className="mr-1.5 h-3.5 w-3.5" /> Publish
                            </Button>
                        )}
                        <Button variant="ghost" size="sm" onClick={() => router.visit(`/hr/job-postings/${posting.id}`)}>
                            <ArrowLeft className="mr-1.5 h-3.5 w-3.5" /> Back
                        </Button>
                    </div>
                </div>

                {/* Simulated Public Page */}
                <div className="border rounded-xl p-8 bg-card">
                    <div className="max-w-2xl mx-auto space-y-8">
                        <div>
                            <h1 className="text-3xl font-bold">{posting.title}</h1>
                            <div className="flex flex-wrap items-center gap-3 mt-3 text-sm text-muted-foreground">
                                {posting.department && (
                                    <span className="flex items-center gap-1"><Briefcase className="h-4 w-4" /> {posting.department}</span>
                                )}
                                {posting.location && (
                                    <span className="flex items-center gap-1"><MapPin className="h-4 w-4" /> {posting.location}</span>
                                )}
                                <span className="flex items-center gap-1"><Clock className="h-4 w-4" /> {employmentTypeLabels[posting.employment_type]}</span>
                                {posting.is_remote && (
                                    <Badge variant="outline" className="gap-1 border-status-info/30 text-status-info bg-status-info"><Wifi className="h-3 w-3" /> Remote</Badge>
                                )}
                                {posting.is_internal && (
                                    <Badge variant="outline" className="gap-1 border-primary/30 text-primary bg-primary/10"><Lock className="h-3 w-3" /> Internal</Badge>
                                )}
                            </div>
                            {posting.salary_range && (
                                <p className="mt-2 text-sm flex items-center gap-1 text-status-success">
                                    <DollarSign className="h-4 w-4" /> {posting.salary_range}
                                </p>
                            )}
                            {posting.closes_at && (
                                <p className="mt-1 text-xs text-muted-foreground">Closing: {posting.closes_at}</p>
                            )}
                        </div>

                        {posting.summary && (
                            <p className="text-muted-foreground italic">{posting.summary}</p>
                        )}

                        <div>
                            <h2 className="text-lg font-semibold mb-2">About the Role</h2>
                            <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">{posting.description}</div>
                        </div>

                        {posting.responsibilities && (
                            <div>
                                <h2 className="text-lg font-semibold mb-2">Responsibilities</h2>
                                <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">{posting.responsibilities}</div>
                            </div>
                        )}

                        {posting.requirements && (
                            <div>
                                <h2 className="text-lg font-semibold mb-2">Requirements</h2>
                                <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">{posting.requirements}</div>
                            </div>
                        )}

                        {posting.screening_questions.length > 0 && (
                            <div>
                                <h2 className="text-lg font-semibold mb-2">Screening Questions</h2>
                                <p className="text-xs text-muted-foreground mb-3">Candidates will be asked to answer these questions when applying:</p>
                                <ol className="space-y-2 list-decimal list-inside text-sm">
                                    {posting.screening_questions.map(q => (
                                        <li key={q.id}>
                                            {q.question} {q.required && <span className="text-destructive">*</span>}
                                            <span className="text-xs text-muted-foreground ml-1">({q.type.replace('_', '/')})</span>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        )}

                        <div className="pt-4 border-t">
                            <Button size="lg" disabled className="w-full sm:w-auto">
                                Apply for this Position
                            </Button>
                            <p className="text-xs text-muted-foreground mt-2">(Apply button is disabled in preview mode)</p>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
