import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Calendar, Clock } from 'lucide-react';

interface CalendarEvent {
    id: string;
    title: string;
    start: string;
    type: string;
    color: string;
    meta: any;
}

interface Props {
    events: CalendarEvent[];
    filters: {
        type?: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'Compliance', href: '/hr/compliance' },
    { title: 'Calendar', href: '/hr/compliance/calendar' },
];

const typeColors: Record<string, string> = {
    compliance: 'bg-status-info-bg text-status-info border-status-info/30',
    vetting: 'bg-primary/10 text-primary border-primary',
    driver: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    training:
        'bg-status-success-bg text-status-success border-status-success/30',
};

export default function ComplianceCalendar({ events, filters }: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get(
            '/hr/compliance/calendar',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    // Group events by month
    const grouped: Record<string, CalendarEvent[]> = {};
    events.forEach((evt) => {
        const d = new Date(evt.start);
        const monthKey = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
        (grouped[monthKey] = grouped[monthKey] || []).push(evt);
    });

    // Sort months
    const sortedMonths = Object.keys(grouped).sort();

    const formatMonthLabel = (key: string) => {
        const [year, month] = key.split('-');
        return new Date(Number(year), Number(month) - 1).toLocaleDateString(
            'en-NZ',
            { month: 'long', year: 'numeric' },
        );
    };

    const formatDate = (d: string) =>
        new Date(d).toLocaleDateString('en-NZ', {
            weekday: 'short',
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Compliance Calendar" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={Calendar}
                        title="Compliance Calendar"
                        description="Upcoming compliance events, vetting checks, and training expiry dates."
                        stats={[
                            { label: 'Total events', value: events.length },
                            { label: 'Months', value: sortedMonths.length },
                        ]}
                    />
                }
            >
                {/* Filter */}
                <div className="flex flex-wrap items-center gap-3">
                    <Select
                        value={filters.type || '__none__'}
                        onValueChange={(v) =>
                            applyFilter('type', v === '__none__' ? null : v)
                        }
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Event Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Types</SelectItem>
                            <SelectItem value="compliance">
                                Compliance
                            </SelectItem>
                            <SelectItem value="vetting">Vetting</SelectItem>
                            <SelectItem value="driver">Driver</SelectItem>
                            <SelectItem value="training">Training</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Calendar View - Grouped by Month */}
                {sortedMonths.length === 0 ? (
                    <Card>
                        <CardContent className="pt-6">
                            <div className="py-8 text-center text-sm text-muted-foreground">
                                <Calendar className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>
                                    No compliance events found for the selected
                                    filter.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    sortedMonths.map((monthKey) => (
                        <Card key={monthKey}>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Calendar className="h-5 w-5 text-muted-foreground" />
                                    {formatMonthLabel(monthKey)}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {grouped[monthKey]
                                        .sort(
                                            (a, b) =>
                                                new Date(a.start).getTime() -
                                                new Date(b.start).getTime(),
                                        )
                                        .map((evt) => (
                                            <div
                                                key={evt.id}
                                                className="flex items-center gap-4 rounded-lg border p-3 hover:bg-muted/30"
                                            >
                                                <div
                                                    className="h-10 w-1 shrink-0 rounded-full"
                                                    style={{
                                                        backgroundColor:
                                                            evt.color ||
                                                            '#6b7280',
                                                    }}
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium">
                                                            {evt.title}
                                                        </span>
                                                        <Badge
                                                            className={
                                                                typeColors[
                                                                    evt.type
                                                                ] ??
                                                                'bg-muted text-foreground'
                                                            }
                                                        >
                                                            {evt.type}
                                                        </Badge>
                                                    </div>
                                                    <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                                                        <span className="flex items-center gap-1">
                                                            <Clock className="h-3 w-3" />
                                                            {formatDate(
                                                                evt.start,
                                                            )}
                                                        </span>
                                                        {evt.meta
                                                            ?.employee_name && (
                                                            <span>
                                                                {
                                                                    evt.meta
                                                                        .employee_name
                                                                }
                                                            </span>
                                                        )}
                                                        {evt.meta
                                                            ?.requirement && (
                                                            <span>
                                                                {
                                                                    evt.meta
                                                                        .requirement
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                </div>
                            </CardContent>
                        </Card>
                    ))
                )}
            </PageLayout>
        </AppLayout>
    );
}
