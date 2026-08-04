import { PerformanceTabs } from '@/components/hr';
import {
    PerformanceSatelliteHero,
    SatelliteHeroAction,
} from '@/components/hr/performance/performance-hero';
import { PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Grid3X3, Plus, Star } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Skill = {
    id: number;
    name: string;
    category: string;
    description: string | null;
    is_active: boolean;
    employee_skills_count: number;
};

type SkillGap = {
    skill_id: number;
    name: string;
    category: string;
    coverage_pct: number;
    employees_with_skill: number;
    total_employees: number;
};

type Props = {
    skills: {
        data: Skill[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    categories: string[];
    skillGaps: SkillGap[];
    filters: { category: string | null; q: string };
    can: { create: boolean; manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Skills', href: '/hr/performance/skills' },
];

export default function SkillsIndex({
    skills,
    categories,
    skillGaps,
    filters,
    can,
}: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({
        name: '',
        category: '',
        description: '',
    });
    const [processing, setProcessing] = useState(false);

    const set = (key: string, value: string) =>
        setForm((p) => ({ ...p, [key]: value }));

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/performance/skills',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post('/hr/performance/skills', form, {
            onSuccess: () => {
                setOpen(false);
                setForm({ name: '', category: '', description: '' });
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Skills" />

            <PageLayout
                hero={
                    <PerformanceSatelliteHero
                        icon={Star}
                        title="Skills"
                        description="Manage organisational skills and competencies."
                        stats={[
                            { label: 'Skills', value: skills.data.length },
                            { label: 'Categories', value: categories.length },
                            {
                                label: 'Gaps',
                                value: skillGaps.length,
                                amber: skillGaps.length > 0,
                            },
                        ]}
                        actions={
                            <>
                                <SatelliteHeroAction
                                    label="Competencies"
                                    href="/hr/performance/competencies"
                                />
                                <SatelliteHeroAction
                                    icon={Grid3X3}
                                    label="Skills matrix"
                                    href="/hr/performance/skills/matrix"
                                />
                                {can.create && (
                                    <SatelliteHeroAction
                                        icon={Plus}
                                        label="New skill"
                                        primary
                                        onClick={() => setOpen(true)}
                                    />
                                )}
                            </>
                        }
                    />
                }
            >
                <PerformanceTabs active="competencies" />

                {/* Filters */}
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant={!filters.category ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => onFilter({ category: null })}
                    >
                        All
                    </Button>
                    {categories.map((cat) => (
                        <Button
                            key={cat}
                            variant={
                                filters.category === cat ? 'default' : 'outline'
                            }
                            size="sm"
                            onClick={() => onFilter({ category: cat })}
                        >
                            {cat}
                        </Button>
                    ))}
                    <Input
                        placeholder="Search skills..."
                        value={filters.q || ''}
                        onChange={(e) => onFilter({ q: e.target.value })}
                        className="ml-auto w-56"
                    />
                </div>

                {/* Skill Gaps Alert */}
                {skillGaps.length > 0 && (
                    <Card className="border-status-warning/30">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm text-status-warning">
                                <AlertTriangle className="h-4 w-4" />
                                Skill Gaps Detected
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {skillGaps.slice(0, 8).map((gap) => (
                                    <Badge
                                        key={gap.skill_id}
                                        variant="outline"
                                        className="border-status-warning/30 bg-status-warning-bg text-status-warning"
                                    >
                                        {gap.name}: {gap.coverage_pct}% coverage
                                    </Badge>
                                ))}
                                {skillGaps.length > 8 && (
                                    <Badge variant="outline">
                                        +{skillGaps.length - 8} more
                                    </Badge>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Skills Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="text-right">
                                        Employees
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {skills.data.map((skill) => (
                                    <TableRow key={skill.id}>
                                        <TableCell className="font-medium">
                                            {skill.name}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">
                                                {skill.category}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="max-w-xs truncate text-muted-foreground">
                                            {skill.description || '-'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {skill.employee_skills_count}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className={
                                                    skill.is_active
                                                        ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                        : 'border-border/30 bg-muted-foreground/10 text-muted-foreground'
                                                }
                                            >
                                                {skill.is_active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {skills.data.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No skills found. Create your first
                                            skill to get started.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {skills.links?.length > 3 && (
                    <LaravelPagination links={skills.links} />
                )}
            </PageLayout>

            {/* Create Skill Dialog */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>New Skill</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="skill-name">Name</Label>
                            <Input
                                id="skill-name"
                                name="name"
                                value={form.name}
                                onChange={(e) => set('name', e.target.value)}
                                placeholder="e.g. First Aid"
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="skill-category">Category</Label>
                            <Input
                                id="skill-category"
                                name="category"
                                value={form.category}
                                onChange={(e) =>
                                    set('category', e.target.value)
                                }
                                placeholder="e.g. Health & Safety"
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="skill-description">
                                Description
                            </Label>
                            <Textarea
                                id="skill-description"
                                name="description"
                                value={form.description}
                                onChange={(e) =>
                                    set('description', e.target.value)
                                }
                                rows={3}
                                placeholder="Optional description..."
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Creating...' : 'Create'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
