import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Search, Briefcase, MapPin, Clock, Wifi, DollarSign } from 'lucide-react';
import { useState } from 'react';
import { employmentTypeLabels } from '@/lib/job-posting-constants';
import type { PublicJobListing } from '@/types/job-postings';

type Props = {
    postings: PublicJobListing[];
    departments: string[];
    locations: string[];
    filters: {
        department: string | null;
        location: string | null;
        search: string | null;
        employment_type: string | null;
    };
};

export default function CareersIndex({ postings, departments, locations, filters }: Props) {
    const [searchValue, setSearchValue] = useState(filters.search || '');

    function submitFilters(next: Partial<typeof filters>) {
        const merged = { ...filters, ...next };
        router.get('/careers', {
            ...(merged.search ? { search: merged.search } : {}),
            ...(merged.department ? { department: merged.department } : {}),
            ...(merged.location ? { location: merged.location } : {}),
            ...(merged.employment_type ? { employment_type: merged.employment_type } : {}),
        }, { preserveState: true, replace: true });
    }

    return (
        <>
            <Head title="Careers" />
            <div className="mx-auto max-w-5xl px-4 py-10 space-y-6">
                <div className="space-y-2">
                    <h1 className="text-3xl font-bold">Careers</h1>
                    <p className="text-muted-foreground">Join our team and make a meaningful impact in the lives of the people we support.</p>
                </div>

                <div className="grid gap-3 md:grid-cols-4">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Search positions..."
                            value={searchValue}
                            onChange={e => setSearchValue(e.target.value)}
                            onKeyDown={e => { if (e.key === 'Enter') submitFilters({ search: searchValue || null }); }}
                            className="pl-9"
                        />
                    </div>
                    <select
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                        value={filters.department || ''}
                        onChange={e => submitFilters({ department: e.target.value || null })}
                    >
                        <option value="">All departments</option>
                        {departments.map(d => <option key={d} value={d}>{d}</option>)}
                    </select>
                    <select
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                        value={filters.location || ''}
                        onChange={e => submitFilters({ location: e.target.value || null })}
                    >
                        <option value="">All locations</option>
                        {locations.map(l => <option key={l} value={l}>{l}</option>)}
                    </select>
                    <select
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                        value={filters.employment_type || ''}
                        onChange={e => submitFilters({ employment_type: e.target.value || null })}
                    >
                        <option value="">All types</option>
                        <option value="full_time">Full Time</option>
                        <option value="part_time">Part Time</option>
                        <option value="casual">Casual</option>
                        <option value="fixed_term">Fixed Term</option>
                    </select>
                </div>

                <div className="grid gap-4">
                    {postings.map(posting => (
                        <Card key={posting.id} className="hover:bg-accent/30 transition-colors">
                            <CardContent className="p-6">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex-1 min-w-0">
                                        <Link href={`/careers/${posting.slug}`} className="text-lg font-semibold hover:underline">
                                            {posting.title}
                                        </Link>
                                        <div className="flex flex-wrap gap-2 mt-2">
                                            <Badge variant="secondary" className="text-xs">
                                                <Briefcase className="mr-1 h-3 w-3" />
                                                {employmentTypeLabels[posting.employment_type] || posting.employment_type}
                                            </Badge>
                                            {posting.department && (
                                                <span className="text-xs text-muted-foreground flex items-center gap-1">
                                                    {posting.department}
                                                </span>
                                            )}
                                            {posting.location && (
                                                <span className="text-xs text-muted-foreground flex items-center gap-1">
                                                    <MapPin className="h-3 w-3" /> {posting.location}
                                                </span>
                                            )}
                                            {posting.is_remote && (
                                                <Badge variant="outline" className="text-xs gap-1 border-blue-500/30 text-blue-400 bg-blue-500/10">
                                                    <Wifi className="h-3 w-3" /> Remote
                                                </Badge>
                                            )}
                                            {posting.salary_range && (
                                                <span className="text-xs text-emerald-400 flex items-center gap-1">
                                                    <DollarSign className="h-3 w-3" /> {posting.salary_range}
                                                </span>
                                            )}
                                        </div>
                                        {posting.summary && (
                                            <p className="mt-2 text-sm text-muted-foreground line-clamp-2">{posting.summary}</p>
                                        )}
                                    </div>
                                    <div className="flex flex-col items-end gap-2 shrink-0">
                                        <Button asChild>
                                            <Link href={`/careers/${posting.slug}/apply`}>Apply</Link>
                                        </Button>
                                        <p className="text-xs text-muted-foreground">
                                            {posting.closes_at ? (
                                                <span className="flex items-center gap-1"><Clock className="h-3 w-3" /> Closes {posting.closes_at}</span>
                                            ) : 'Open until filled'}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}

                    {postings.length === 0 && (
                        <Card>
                            <CardContent className="py-16 text-center">
                                <Briefcase className="mx-auto h-12 w-12 text-muted-foreground/40 mb-4" />
                                <p className="text-muted-foreground font-medium">No open positions at this time</p>
                                <p className="text-sm text-muted-foreground mt-1">Check back soon for new opportunities.</p>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}
