import MarketingLayout from '@/layouts/marketing-layout';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { MapPin, Briefcase, Clock, DollarSign } from 'lucide-react';

type Posting = {
    id: number;
    title: string;
    department: string | null;
    location: string | null;
    employment_type: string;
    salary_range: string | null;
    published_at: string | null;
    closes_at: string | null;
};

type Props = {
    postings: Posting[];
    departments: string[];
    locations: string[];
    filters: { department: string | null; location: string | null };
};

const typeLabels: Record<string, string> = {
    full_time: 'Full Time',
    part_time: 'Part Time',
    casual: 'Casual',
    fixed_term: 'Fixed Term',
};

export default function CareersIndex({ postings, departments, locations, filters }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/careers', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <MarketingLayout title="Careers" description="Join our team and make a difference in supported living.">
            <div className="mx-auto max-w-5xl px-4 py-16 sm:px-6 lg:px-8">
                <div className="text-center mb-12">
                    <h1 className="text-4xl font-bold tracking-tight sm:text-5xl">
                        Join Our Team
                    </h1>
                    <p className="mt-4 text-lg text-muted-foreground max-w-2xl mx-auto">
                        We are looking for talented and passionate people to help us deliver outstanding supported living services.
                    </p>
                </div>

                {/* Filters */}
                {(departments.length > 0 || locations.length > 0) && (
                    <div className="flex flex-wrap gap-4 mb-8 justify-center">
                        {departments.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant={!filters.department ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => onFilter({ department: null })}
                                >
                                    All Departments
                                </Button>
                                {departments.map((dept) => (
                                    <Button
                                        key={dept}
                                        variant={filters.department === dept ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => onFilter({ department: dept })}
                                    >
                                        {dept}
                                    </Button>
                                ))}
                            </div>
                        )}
                        {locations.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant={!filters.location ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => onFilter({ location: null })}
                                >
                                    All Locations
                                </Button>
                                {locations.map((loc) => (
                                    <Button
                                        key={loc}
                                        variant={filters.location === loc ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => onFilter({ location: loc })}
                                    >
                                        {loc}
                                    </Button>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {/* Job Listings */}
                <div className="grid gap-4 md:grid-cols-2">
                    {postings.map((posting) => (
                        <Link key={posting.id} href={`/careers/${posting.id}`} className="block group">
                            <Card className="h-full transition-shadow hover:shadow-lg">
                                <CardHeader>
                                    <CardTitle className="text-lg group-hover:text-primary transition-colors">
                                        {posting.title}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="pt-0">
                                    <div className="flex flex-wrap gap-2 mb-3">
                                        <Badge variant="secondary">
                                            <Briefcase className="mr-1 h-3 w-3" />
                                            {typeLabels[posting.employment_type] || posting.employment_type}
                                        </Badge>
                                        {posting.department && (
                                            <Badge variant="outline">{posting.department}</Badge>
                                        )}
                                    </div>
                                    <div className="flex flex-col gap-1 text-sm text-muted-foreground">
                                        {posting.location && (
                                            <span className="flex items-center gap-1.5">
                                                <MapPin className="h-3.5 w-3.5" />
                                                {posting.location}
                                            </span>
                                        )}
                                        {posting.salary_range && (
                                            <span className="flex items-center gap-1.5">
                                                <DollarSign className="h-3.5 w-3.5" />
                                                {posting.salary_range}
                                            </span>
                                        )}
                                        {posting.closes_at && (
                                            <span className="flex items-center gap-1.5">
                                                <Clock className="h-3.5 w-3.5" />
                                                Closes: {posting.closes_at}
                                            </span>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </Link>
                    ))}
                </div>

                {postings.length === 0 && (
                    <div className="text-center py-16">
                        <p className="text-lg text-muted-foreground">
                            No open positions at the moment. Please check back soon.
                        </p>
                    </div>
                )}
            </div>
        </MarketingLayout>
    );
}
