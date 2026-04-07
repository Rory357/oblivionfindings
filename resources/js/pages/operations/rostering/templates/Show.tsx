import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';

type TemplateShift = {
    id: number;
    day_of_week: number;
    start_time: string;
    end_time: string;
    shift_type?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    expected_break_minutes?: number | null;
    location?: string | null;
    notes?: string | null;
    required_skills?: string[] | null;
    client?: { id: number; first_name: string; last_name: string } | null;
    user?: { id: number; name: string } | null;
    service_context?: { id: number; name: string } | null;
};

type Template = {
    id: number;
    name: string;
    description?: string | null;
    template_type: string;
    is_active: boolean;
    creator?: { id: number; name: string } | null;
    updated_at?: string | null;
    template_shifts?: TemplateShift[];
};

type Props = {
    template: Template;
};

const DAY_LABELS = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

function nextMonday(): string {
    const now = new Date();
    const result = new Date(now);
    const day = result.getDay();
    const daysUntilMonday = day === 1 ? 7 : (8 - day) % 7 || 7;
    result.setDate(result.getDate() + daysUntilMonday);
    return result.toISOString().slice(0, 10);
}

export default function ShowTemplate({ template }: Props) {
    const applyForm = useForm({
        week_start: nextMonday(),
    });

    const deleteTemplate = () => {
        router.delete(`/operations/rostering/templates/${template.id}`);
    };

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: 'Roster templates',
                    href: '/operations/rostering/templates',
                },
                {
                    title: template.name,
                    href: `/operations/rostering/templates/${template.id}`,
                },
            ]}
        >
            <Head title={template.name} />
            <PageShell>
                <PageHeader
                    title={template.name}
                    description={
                        template.description ??
                        'Reusable roster template with operational shift metadata.'
                    }
                    backHref="/operations/rostering/templates"
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/operations/rostering/templates/${template.id}/edit`}
                                >
                                    Edit
                                </Link>
                            </Button>
                            <Button variant="outline" onClick={deleteTemplate}>
                                Delete
                            </Button>
                        </div>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Template rows
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {template.template_shifts?.length ? (
                                template.template_shifts.map((shift) => {
                                    const overnight =
                                        shift.end_time <= shift.start_time;

                                    return (
                                        <div
                                            key={shift.id}
                                            className="rounded-md border p-4"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div className="font-medium">
                                                    {DAY_LABELS[
                                                        shift.day_of_week
                                                    ] ??
                                                        `Day ${shift.day_of_week}`}
                                                    {' · '}
                                                    {shift.start_time} -{' '}
                                                    {shift.end_time}
                                                    {overnight
                                                        ? ' (+1 day)'
                                                        : ''}
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    <Badge variant="outline">
                                                        {shift.shift_type ??
                                                            'standard'}
                                                    </Badge>
                                                    {shift.is_sleepover && (
                                                        <Badge variant="secondary">
                                                            Sleepover
                                                        </Badge>
                                                    )}
                                                    {shift.is_on_call && (
                                                        <Badge variant="secondary">
                                                            On-call
                                                        </Badge>
                                                    )}
                                                </div>
                                            </div>

                                            <div className="mt-2 grid gap-2 text-sm text-muted-foreground md:grid-cols-2">
                                                <div>
                                                    Client:{' '}
                                                    <span className="font-medium text-foreground">
                                                        {shift.client
                                                            ? `${shift.client.first_name} ${shift.client.last_name}`
                                                            : 'No client'}
                                                    </span>
                                                </div>
                                                <div>
                                                    Staff:{' '}
                                                    <span className="font-medium text-foreground">
                                                        {shift.user?.name ??
                                                            'Unassigned'}
                                                    </span>
                                                </div>
                                                <div>
                                                    Service context:{' '}
                                                    <span className="font-medium text-foreground">
                                                        {shift.service_context
                                                            ?.name ?? 'None'}
                                                    </span>
                                                </div>
                                                <div>
                                                    Break:{' '}
                                                    <span className="font-medium text-foreground">
                                                        {shift.expected_break_minutes ??
                                                            0}{' '}
                                                        min
                                                    </span>
                                                </div>
                                                <div>
                                                    Location:{' '}
                                                    <span className="font-medium text-foreground">
                                                        {shift.location ??
                                                            'Not set'}
                                                    </span>
                                                </div>
                                                <div>
                                                    Skills:{' '}
                                                    <span className="font-medium text-foreground">
                                                        {shift.required_skills
                                                            ?.length
                                                            ? shift.required_skills.join(
                                                                  ', ',
                                                              )
                                                            : 'None'}
                                                    </span>
                                                </div>
                                            </div>

                                            {shift.notes && (
                                                <p className="mt-2 text-sm text-muted-foreground">
                                                    {shift.notes}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })
                            ) : (
                                <div className="text-sm text-muted-foreground">
                                    This template has no shift rows yet.
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Template details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">
                                        Cadence
                                    </span>
                                    <Badge variant="outline">
                                        {template.template_type}
                                    </Badge>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">
                                        Status
                                    </span>
                                    <Badge
                                        variant={
                                            template.is_active
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                    >
                                        {template.is_active
                                            ? 'Active'
                                            : 'Inactive'}
                                    </Badge>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">
                                        Rows
                                    </span>
                                    <span className="font-medium">
                                        {template.template_shifts?.length ?? 0}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">
                                        Created by
                                    </span>
                                    <span className="font-medium">
                                        {template.creator?.name ?? 'Unknown'}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">
                                        Updated
                                    </span>
                                    <span className="font-medium">
                                        {template.updated_at
                                            ? new Date(
                                                  template.updated_at,
                                              ).toLocaleDateString('en-NZ')
                                            : '—'}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Apply template
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form
                                    className="space-y-3"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        applyForm.post(
                                            `/operations/rostering/templates/${template.id}/apply`,
                                        );
                                    }}
                                >
                                    <div className="space-y-2">
                                        <Label htmlFor="week-start">
                                            Week start
                                        </Label>
                                        <Input
                                            id="week-start"
                                            type="date"
                                            value={applyForm.data.week_start}
                                            onChange={(event) =>
                                                applyForm.setData(
                                                    'week_start',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        The selected date is treated as the
                                        Monday anchor for the template rows.
                                    </p>
                                    <Button
                                        type="submit"
                                        disabled={applyForm.processing}
                                        className="w-full"
                                    >
                                        Apply to roster
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
