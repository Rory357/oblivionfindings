import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type BreadcrumbItem } from '@/types';

interface RadarPoint {
    competency: string;
    level: number;
    target: number | null;
}

interface Assessment {
    id: number;
    competency: { id: number; name: string };
    assessor: { id: number; name: string } | null;
    proficiency_level: number;
    target_level: number | null;
    assessment_date: string;
    notes: string | null;
}

interface Props {
    employee: { id: number; name: string; email: string };
    profile: any;
    latestAssessments: Assessment[];
    history: Assessment[];
    radarData: RadarPoint[];
    can: { manage: boolean };
}

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const levelColors = ['bg-red-100 text-red-800', 'bg-orange-100 text-orange-800', 'bg-yellow-100 text-yellow-800', 'bg-blue-100 text-blue-800', 'bg-green-100 text-green-800'];

export default function CompetencyProfile({ employee, profile, latestAssessments, history, radarData, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Performance', href: '/hr/performance' },
        { title: 'Competencies', href: '/hr/performance/competencies' },
        { title: employee.name, href: `/hr/performance/competencies/profile/${profile?.id ?? employee.id}` },
    ];

    // SVG radar chart
    const maxLevel = 5;
    const cx = 150;
    const cy = 150;
    const radius = 120;
    const points = radarData.length;

    const getCoord = (index: number, level: number) => {
        const angle = (Math.PI * 2 * index) / points - Math.PI / 2;
        const r = (level / maxLevel) * radius;
        return { x: cx + r * Math.cos(angle), y: cy + r * Math.sin(angle) };
    };

    const gridLevels = [1, 2, 3, 4, 5];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${employee.name} - Competency Profile`} />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">{employee.name}</h1>
                    <div className="mt-1 text-sm text-muted-foreground">Competency profile and assessment history</div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Radar Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Competency Radar</CardTitle>
                        </CardHeader>
                        <CardContent className="flex justify-center">
                            {points === 0 ? (
                                <p className="py-8 text-sm text-muted-foreground">No assessments yet</p>
                            ) : (
                                <svg width={300} height={300} viewBox="0 0 300 300">
                                    {/* Grid */}
                                    {gridLevels.map((level) => (
                                        <polygon
                                            key={level}
                                            points={Array.from({ length: points }, (_, i) => {
                                                const { x, y } = getCoord(i, level);
                                                return `${x},${y}`;
                                            }).join(' ')}
                                            fill="none"
                                            stroke="#e2e8f0"
                                            strokeWidth={1}
                                        />
                                    ))}

                                    {/* Axis lines */}
                                    {radarData.map((_, i) => {
                                        const { x, y } = getCoord(i, maxLevel);
                                        return <line key={i} x1={cx} y1={cy} x2={x} y2={y} stroke="#e2e8f0" strokeWidth={1} />;
                                    })}

                                    {/* Target polygon */}
                                    {radarData.some((d) => d.target !== null) && (
                                        <polygon
                                            points={radarData.map((d, i) => {
                                                const { x, y } = getCoord(i, d.target || 0);
                                                return `${x},${y}`;
                                            }).join(' ')}
                                            fill="rgba(239,68,68,0.1)"
                                            stroke="#ef4444"
                                            strokeWidth={1.5}
                                            strokeDasharray="4,4"
                                        />
                                    )}

                                    {/* Current polygon */}
                                    <polygon
                                        points={radarData.map((d, i) => {
                                            const { x, y } = getCoord(i, d.level);
                                            return `${x},${y}`;
                                        }).join(' ')}
                                        fill="rgba(59,130,246,0.2)"
                                        stroke="#3b82f6"
                                        strokeWidth={2}
                                    />

                                    {/* Data points and labels */}
                                    {radarData.map((d, i) => {
                                        const { x, y } = getCoord(i, d.level);
                                        const label = getCoord(i, maxLevel + 0.6);
                                        return (
                                            <g key={i}>
                                                <circle cx={x} cy={y} r={4} fill="#3b82f6" />
                                                <text
                                                    x={label.x}
                                                    y={label.y}
                                                    textAnchor="middle"
                                                    dominantBaseline="middle"
                                                    className="text-[10px] fill-slate-600"
                                                >
                                                    {d.competency.length > 12 ? d.competency.substring(0, 12) + '...' : d.competency}
                                                </text>
                                            </g>
                                        );
                                    })}
                                </svg>
                            )}
                        </CardContent>
                    </Card>

                    {/* Current Levels */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Current Levels</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Competency</TableHead>
                                        <TableHead className="text-center">Level</TableHead>
                                        <TableHead className="text-center">Target</TableHead>
                                        <TableHead>Assessor</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {latestAssessments.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-center text-muted-foreground">No assessments</TableCell>
                                        </TableRow>
                                    )}
                                    {latestAssessments.map((a) => (
                                        <TableRow key={a.id}>
                                            <TableCell className="font-medium">{a.competency.name}</TableCell>
                                            <TableCell className="text-center">
                                                <Badge className={levelColors[a.proficiency_level - 1] || ''} variant="outline">
                                                    {a.proficiency_level}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                {a.target_level ? (
                                                    <Badge variant="outline">{a.target_level}</Badge>
                                                ) : '-'}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">{a.assessor?.name ?? '-'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                {/* History */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Assessment History</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Competency</TableHead>
                                    <TableHead className="text-center">Level</TableHead>
                                    <TableHead>Assessor</TableHead>
                                    <TableHead>Notes</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {history.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center text-muted-foreground">No history</TableCell>
                                    </TableRow>
                                )}
                                {history.map((a) => (
                                    <TableRow key={a.id}>
                                        <TableCell className="text-sm">{formatDate(a.assessment_date)}</TableCell>
                                        <TableCell className="font-medium">{a.competency.name}</TableCell>
                                        <TableCell className="text-center">
                                            <Badge className={levelColors[a.proficiency_level - 1] || ''} variant="outline">
                                                {a.proficiency_level}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">{a.assessor?.name ?? '-'}</TableCell>
                                        <TableCell className="text-sm text-muted-foreground">{a.notes || '-'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
