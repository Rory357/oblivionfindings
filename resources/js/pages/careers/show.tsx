import MarketingLayout from '@/layouts/marketing-layout';
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, MapPin, Briefcase, Clock, DollarSign } from 'lucide-react';

type Props = {
    posting: {
        id: number;
        title: string;
        department: string | null;
        location: string | null;
        employment_type: string;
        description: string;
        requirements: string | null;
        salary_range: string | null;
        published_at: string | null;
        closes_at: string | null;
    };
};

const typeLabels: Record<string, string> = {
    full_time: 'Full Time',
    part_time: 'Part Time',
    casual: 'Casual',
    fixed_term: 'Fixed Term',
};

export default function CareersShow({ posting }: Props) {
    return (
        <MarketingLayout title={posting.title} description={`Apply for ${posting.title} position.`}>
            <div className="mx-auto max-w-3xl px-4 py-16 sm:px-6 lg:px-8">
                <Link href="/careers" className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-6">
                    <ArrowLeft className="h-4 w-4" />
                    Back to all positions
                </Link>

                <div className="mb-8">
                    <h1 className="text-3xl font-bold tracking-tight">{posting.title}</h1>
                    <div className="mt-4 flex flex-wrap gap-3">
                        <Badge variant="secondary" className="text-sm">
                            <Briefcase className="mr-1 h-3.5 w-3.5" />
                            {typeLabels[posting.employment_type] || posting.employment_type}
                        </Badge>
                        {posting.department && (
                            <Badge variant="outline" className="text-sm">{posting.department}</Badge>
                        )}
                        {posting.location && (
                            <Badge variant="outline" className="text-sm">
                                <MapPin className="mr-1 h-3.5 w-3.5" />
                                {posting.location}
                            </Badge>
                        )}
                        {posting.salary_range && (
                            <Badge variant="outline" className="text-sm">
                                <DollarSign className="mr-1 h-3.5 w-3.5" />
                                {posting.salary_range}
                            </Badge>
                        )}
                    </div>
                    {posting.closes_at && (
                        <p className="mt-3 text-sm text-muted-foreground flex items-center gap-1.5">
                            <Clock className="h-3.5 w-3.5" />
                            Applications close: {posting.closes_at}
                        </p>
                    )}
                </div>

                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>About This Role</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">
                            {posting.description}
                        </div>
                    </CardContent>
                </Card>

                {posting.requirements && (
                    <Card className="mb-8">
                        <CardHeader>
                            <CardTitle>Requirements</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap">
                                {posting.requirements}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <div className="text-center">
                    <Button asChild size="lg">
                        <Link href={`/careers/${posting.id}/apply`}>
                            Apply Now
                        </Link>
                    </Button>
                </div>
            </div>
        </MarketingLayout>
    );
}
