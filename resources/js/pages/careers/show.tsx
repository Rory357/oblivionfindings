import MarketingLayout from '@/layouts/marketing-layout';
import { Link, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, MapPin, Briefcase, Clock, DollarSign, Wifi } from 'lucide-react';
import { employmentTypeLabels } from '@/lib/job-posting-constants';
import type { PublicJobDetail } from '@/types/job-postings';

type Props = {
    posting: PublicJobDetail;
};

export default function CareersShow({ posting }: Props) {
    const { flash } = usePage().props as any;

    return (
        <MarketingLayout title={posting.title} description={`Apply for ${posting.title} position.`}>
            <div className="mx-auto max-w-3xl px-4 py-16 sm:px-6 lg:px-8">
                <Link href="/careers" className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-6">
                    <ArrowLeft className="h-4 w-4" /> Back to all positions
                </Link>

                {flash?.success && (
                    <div className="mb-6 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-400">
                        {flash.success}
                    </div>
                )}

                <div className="mb-8">
                    <h1 className="text-3xl font-bold tracking-tight">{posting.title}</h1>
                    <div className="mt-4 flex flex-wrap gap-3">
                        <Badge variant="secondary" className="text-sm">
                            <Briefcase className="mr-1 h-3.5 w-3.5" />
                            {employmentTypeLabels[posting.employment_type] || posting.employment_type}
                        </Badge>
                        {posting.department && <Badge variant="outline" className="text-sm">{posting.department}</Badge>}
                        {posting.location && (
                            <Badge variant="outline" className="text-sm"><MapPin className="mr-1 h-3.5 w-3.5" />{posting.location}</Badge>
                        )}
                        {posting.is_remote && (
                            <Badge variant="outline" className="text-sm gap-1 border-blue-500/30 text-blue-400 bg-blue-500/10">
                                <Wifi className="h-3 w-3" /> Remote
                            </Badge>
                        )}
                        {posting.salary_range && (
                            <Badge variant="outline" className="text-sm"><DollarSign className="mr-1 h-3.5 w-3.5" />{posting.salary_range}</Badge>
                        )}
                    </div>
                    {posting.closes_at && (
                        <p className="mt-3 text-sm text-muted-foreground flex items-center gap-1.5">
                            <Clock className="h-3.5 w-3.5" /> Applications close: {posting.closes_at}
                        </p>
                    )}
                </div>

                {posting.summary && (
                    <p className="text-muted-foreground italic border-l-2 border-primary/30 pl-4 mb-6">{posting.summary}</p>
                )}

                <Card className="mb-6">
                    <CardHeader><CardTitle>About This Role</CardTitle></CardHeader>
                    <CardContent>
                        <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">{posting.description}</div>
                    </CardContent>
                </Card>

                {posting.responsibilities && (
                    <Card className="mb-6">
                        <CardHeader><CardTitle>Key Responsibilities</CardTitle></CardHeader>
                        <CardContent>
                            <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">{posting.responsibilities}</div>
                        </CardContent>
                    </Card>
                )}

                {posting.requirements && (
                    <Card className="mb-8">
                        <CardHeader><CardTitle>Requirements</CardTitle></CardHeader>
                        <CardContent>
                            <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">{posting.requirements}</div>
                        </CardContent>
                    </Card>
                )}

                <div className="text-center">
                    <Button asChild size="lg">
                        <Link href={`/careers/${posting.slug}/apply`}>Apply Now</Link>
                    </Button>
                </div>
            </div>
        </MarketingLayout>
    );
}
