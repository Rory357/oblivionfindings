import { PerformanceTabs } from '@/components/hr';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { Head, router } from '@inertiajs/react';
import { Award } from 'lucide-react';
import { FormEvent, useState } from 'react';

type SkillDef = {
    id: number;
    name: string;
    category: string;
};

type EmployeeRow = {
    employee_id: number;
    name: string;
    position: string | null;
    department: string | null;
    skills: Record<number, string | null>; // skill_id -> proficiency_level or null
};

type Props = {
    employees: EmployeeRow[];
    skills: SkillDef[];
    proficiencyLevels: string[];
    can: { assess: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Skills', href: '/hr/performance/skills' },
    { title: 'Matrix', href: '/hr/performance/skills/matrix' },
];

const proficiencyColors: Record<string, string> = {
    beginner:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    intermediate:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    advanced: 'bg-status-info-bg text-status-info border-status-info/30',
    expert: 'bg-status-success-bg text-status-success border-status-success/30',
};

const proficiencyShort: Record<string, string> = {
    beginner: 'B',
    intermediate: 'I',
    advanced: 'A',
    expert: 'E',
};

export default function SkillsMatrix({
    employees,
    skills,
    proficiencyLevels,
    can,
}: Props) {
    const [assessOpen, setAssessOpen] = useState(false);
    const [assessData, setAssessData] = useState({
        employee_profile_id: 0,
        skill_id: 0,
        proficiency_level: 'beginner',
        notes: '',
        employeeName: '',
        skillName: '',
    });
    const [processing, setProcessing] = useState(false);

    // Group skills by category
    const skillsByCategory = skills.reduce<Record<string, SkillDef[]>>(
        (acc, skill) => {
            if (!acc[skill.category]) acc[skill.category] = [];
            acc[skill.category].push(skill);
            return acc;
        },
        {},
    );

    const openAssess = (
        emp: EmployeeRow,
        skill: SkillDef,
        currentLevel: string | null,
    ) => {
        setAssessData({
            employee_profile_id: emp.employee_id,
            skill_id: skill.id,
            proficiency_level: currentLevel || 'beginner',
            notes: '',
            employeeName: emp.name,
            skillName: skill.name,
        });
        setAssessOpen(true);
    };

    const handleAssess = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post(
            '/hr/performance/skills/assess',
            {
                employee_profile_id: assessData.employee_profile_id,
                skill_id: assessData.skill_id,
                proficiency_level: assessData.proficiency_level,
                notes: assessData.notes || null,
            },
            {
                onSuccess: () => setAssessOpen(false),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Skills Matrix" />
            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        icon={Award}
                        title="Skills Matrix"
                        description={`Employee skills overview. ${can.assess ? 'Click a cell to assess.' : ''}`}
                        stats={[
                            { label: 'Employees', value: employees.length },
                            { label: 'Skills', value: skills.length },
                        ]}
                    />
                }
            >
                <PerformanceTabs active="competencies" />

                {/* Legend */}
                <div className="flex flex-wrap gap-3">
                    {proficiencyLevels.map((level) => (
                        <div key={level} className="flex items-center gap-1.5">
                            <span
                                className={`inline-flex h-6 w-6 items-center justify-center rounded text-xs font-bold ${proficiencyColors[level] || ''}`}
                            >
                                {proficiencyShort[level] ||
                                    level[0]?.toUpperCase()}
                            </span>
                            <span className="text-xs text-muted-foreground capitalize">
                                {level}
                            </span>
                        </div>
                    ))}
                    <div className="flex items-center gap-1.5">
                        <span className="inline-flex h-6 w-6 items-center justify-center rounded border border-dashed text-xs text-muted-foreground">
                            -
                        </span>
                        <span className="text-xs text-muted-foreground">
                            Not assessed
                        </span>
                    </div>
                </div>

                {/* Matrix Grid */}
                <Card>
                    <CardContent className="overflow-x-auto p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="sticky left-0 z-10 bg-muted/50 px-4 py-3 text-left font-medium">
                                        Employee
                                    </th>
                                    <th className="px-3 py-3 text-left font-medium">
                                        Department
                                    </th>
                                    {Object.entries(skillsByCategory).map(
                                        ([category, catSkills]) =>
                                            catSkills.map((skill) => (
                                                <th
                                                    key={skill.id}
                                                    className="px-2 py-3 text-center font-medium"
                                                >
                                                    <div className="flex flex-col items-center">
                                                        <span className="text-[10px] text-muted-foreground">
                                                            {skill.category}
                                                        </span>
                                                        <span className="text-xs whitespace-nowrap">
                                                            {skill.name}
                                                        </span>
                                                    </div>
                                                </th>
                                            )),
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {employees.map((emp) => (
                                    <tr
                                        key={emp.employee_id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="sticky left-0 z-10 bg-background px-4 py-2 font-medium">
                                            {emp.name}
                                            {emp.position && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {emp.position}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">
                                            {emp.department || '-'}
                                        </td>
                                        {skills.map((skill) => {
                                            const level = emp.skills[skill.id];
                                            return (
                                                <td
                                                    key={skill.id}
                                                    className="px-2 py-2 text-center"
                                                >
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() =>
                                                            can.assess &&
                                                            openAssess(
                                                                emp,
                                                                skill,
                                                                level,
                                                            )
                                                        }
                                                        className={`h-7 w-7 text-xs font-bold ${
                                                            level
                                                                ? proficiencyColors[
                                                                      level
                                                                  ] || ''
                                                                : 'border border-dashed text-muted-foreground hover:bg-muted'
                                                        } ${can.assess ? 'cursor-pointer' : 'cursor-default'}`}
                                                        disabled={!can.assess}
                                                    >
                                                        {level
                                                            ? proficiencyShort[
                                                                  level
                                                              ] || '-'
                                                            : '-'}
                                                    </Button>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                                {employees.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={skills.length + 2}
                                            className="py-12 text-center text-muted-foreground"
                                        >
                                            No employees or skills found. Add
                                            skills and employees to populate the
                                            matrix.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </PageLayout>

            {/* Assess Dialog */}
            <Dialog open={assessOpen} onOpenChange={setAssessOpen}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Assess Skill</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleAssess} className="space-y-4">
                        <div>
                            <p className="text-sm">
                                <span className="font-medium">
                                    {assessData.employeeName}
                                </span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    /{' '}
                                </span>
                                <span className="font-medium">
                                    {assessData.skillName}
                                </span>
                            </p>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="skill-proficiency-level">
                                Proficiency Level
                            </Label>
                            <Select
                                value={assessData.proficiency_level}
                                onValueChange={(v) =>
                                    setAssessData((p) => ({
                                        ...p,
                                        proficiency_level: v,
                                    }))
                                }
                            >
                                <SelectTrigger id="skill-proficiency-level">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {proficiencyLevels.map((level) => (
                                        <SelectItem key={level} value={level}>
                                            <span className="capitalize">
                                                {level}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="skill-assessment-notes">
                                Notes
                            </Label>
                            <Textarea
                                id="skill-assessment-notes"
                                name="notes"
                                rows={2}
                                value={assessData.notes}
                                onChange={(e) =>
                                    setAssessData((p) => ({
                                        ...p,
                                        notes: e.target.value,
                                    }))
                                }
                                placeholder="Optional assessment notes..."
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAssessOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Saving...' : 'Save Assessment'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
