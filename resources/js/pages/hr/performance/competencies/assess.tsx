import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

interface Competency {
    id: number;
    name: string;
    category: string;
    proficiency_levels: string[];
}

interface StaffMember {
    id: number;
    name: string;
    email: string;
}

interface AssessmentRow {
    competency_id: number;
    proficiency_level: string;
    target_level: string;
    notes: string;
}

interface Props {
    competencies: Competency[];
    staff: StaffMember[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance', href: '/hr/performance' },
    { title: 'Competencies', href: '/hr/performance/competencies' },
    { title: 'Assess', href: '/hr/performance/competencies' },
];

export default function CompetencyAssess({ competencies, staff }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        employee_user_id: '',
        assessments: competencies.map((c) => ({
            competency_id: c.id,
            proficiency_level: '',
            target_level: '',
            notes: '',
        })) as AssessmentRow[],
    });

    const updateAssessment = (
        index: number,
        field: keyof AssessmentRow,
        value: string,
    ) => {
        const updated = [...data.assessments];
        updated[index] = { ...updated[index], [field]: value };
        setData('assessments', updated);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/hr/performance/competencies/assess');
    };

    const grouped = competencies.reduce(
        (acc, comp) => {
            if (!acc[comp.category]) acc[comp.category] = [];
            acc[comp.category].push(comp);
            return acc;
        },
        {} as Record<string, Competency[]>,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Assess Competencies" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">
                        Competency Assessment
                    </h1>
                    <div className="mt-1 text-sm text-muted-foreground">
                        Rate an employee against the competency framework
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Select Employee
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="max-w-sm">
                                <Select
                                    value={data.employee_user_id}
                                    onValueChange={(val) =>
                                        setData('employee_user_id', val)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Choose employee..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {staff.map((s) => (
                                            <SelectItem
                                                key={s.id}
                                                value={String(s.id)}
                                            >
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.employee_user_id && (
                                    <p className="mt-1 text-xs text-status-critical">
                                        {errors.employee_user_id}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {Object.entries(grouped).map(([category, comps]) => (
                        <Card key={category}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    {category}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {comps.map((comp) => {
                                    const index = data.assessments.findIndex(
                                        (a) => a.competency_id === comp.id,
                                    );
                                    if (index === -1) return null;
                                    const levels = comp.proficiency_levels || [
                                        '1',
                                        '2',
                                        '3',
                                        '4',
                                        '5',
                                    ];

                                    return (
                                        <div
                                            key={comp.id}
                                            className="space-y-3 rounded-lg border p-4"
                                        >
                                            <div className="font-medium">
                                                {comp.name}
                                            </div>
                                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                                <div>
                                                    <Label className="text-xs text-muted-foreground">
                                                        Current Level
                                                    </Label>
                                                    <Select
                                                        value={
                                                            data.assessments[
                                                                index
                                                            ].proficiency_level
                                                        }
                                                        onValueChange={(val) =>
                                                            updateAssessment(
                                                                index,
                                                                'proficiency_level',
                                                                val,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select..." />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {levels.map(
                                                                (level, i) => (
                                                                    <SelectItem
                                                                        key={i}
                                                                        value={String(
                                                                            i +
                                                                                1,
                                                                        )}
                                                                    >
                                                                        {i + 1}{' '}
                                                                        -{' '}
                                                                        {level}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div>
                                                    <Label className="text-xs text-muted-foreground">
                                                        Target Level
                                                    </Label>
                                                    <Select
                                                        value={
                                                            data.assessments[
                                                                index
                                                            ].target_level
                                                        }
                                                        onValueChange={(val) =>
                                                            updateAssessment(
                                                                index,
                                                                'target_level',
                                                                val,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select..." />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {levels.map(
                                                                (level, i) => (
                                                                    <SelectItem
                                                                        key={i}
                                                                        value={String(
                                                                            i +
                                                                                1,
                                                                        )}
                                                                    >
                                                                        {i + 1}{' '}
                                                                        -{' '}
                                                                        {level}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div>
                                                    <Label className="text-xs text-muted-foreground">
                                                        Notes
                                                    </Label>
                                                    <Textarea
                                                        value={
                                                            data.assessments[
                                                                index
                                                            ].notes
                                                        }
                                                        onChange={(e) =>
                                                            updateAssessment(
                                                                index,
                                                                'notes',
                                                                e.target.value,
                                                            )
                                                        }
                                                        rows={1}
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>
                    ))}

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            Save Assessment
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href="/hr/performance/competencies">
                                Cancel
                            </Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
