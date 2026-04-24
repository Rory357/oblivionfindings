import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type QuestionResult = {
    id: number;
    question_text: string;
    question_type: string;
    total_answers: number;
    average?: number | null;
    min?: number | null;
    max?: number | null;
    distribution?: Record<string, number>;
    responses?: string[];
};

type SurveyData = {
    id: number;
    title: string;
    description: string | null;
    survey_type: string;
    status: string;
    is_anonymous: boolean;
    starts_at: string | null;
    ends_at: string | null;
};

type ENPSData = {
    score: number;
    promoters: number;
    passives: number;
    detractors: number;
    total: number;
} | null;

type Props = {
    survey: SurveyData;
    results: { total_responses: number; questions: QuestionResult[] };
    enps: ENPSData;
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Surveys', href: '/hr/surveys' },
    { title: 'Results', href: '#' },
];

const PIE_COLORS = [
    '#3b82f6',
    '#10b981',
    '#f59e0b',
    '#ef4444',
    '#8b5cf6',
    '#ec4899',
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Draft',
    },
    active: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Active',
    },
    closed: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'Closed',
    },
};

export default function SurveyResults({ survey, results, enps, can }: Props) {
    const config = statusConfig[survey.status] || statusConfig.draft;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Results: ${survey.title}`} />
            <div className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">{survey.title}</h1>
                    {survey.description && (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {survey.description}
                        </p>
                    )}
                    <div className="mt-2 flex gap-2">
                        <Badge variant="outline" className={config.className}>
                            {config.label}
                        </Badge>
                        <Badge variant="secondary">
                            {results.total_responses} responses
                        </Badge>
                    </div>
                </div>

                {/* eNPS Score Card */}
                {enps && enps.total > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Employee Net Promoter Score (eNPS)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-8">
                                <div className="text-center">
                                    <p
                                        className={`text-4xl font-bold ${enps.score >= 0 ? 'text-status-success' : 'text-status-critical'}`}
                                    >
                                        {enps.score}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        eNPS Score
                                    </p>
                                </div>
                                <div className="flex gap-6 text-sm">
                                    <div className="text-center">
                                        <p className="text-lg font-semibold text-status-success">
                                            {enps.promoters}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Promoters (9-10)
                                        </p>
                                    </div>
                                    <div className="text-center">
                                        <p className="text-lg font-semibold text-status-warning">
                                            {enps.passives}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Passives (7-8)
                                        </p>
                                    </div>
                                    <div className="text-center">
                                        <p className="text-lg font-semibold text-status-critical">
                                            {enps.detractors}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Detractors (0-6)
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Question Results */}
                <div className="space-y-4">
                    {results.questions.map((question, index) => (
                        <Card key={question.id}>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium">
                                    {index + 1}. {question.question_text}
                                </CardTitle>
                                <p className="text-xs text-muted-foreground">
                                    {question.total_answers} answers
                                </p>
                            </CardHeader>
                            <CardContent>
                                {(question.question_type === 'rating' ||
                                    question.question_type ===
                                        'enps_score') && (
                                    <div>
                                        {question.average !== null &&
                                            question.average !== undefined && (
                                                <p className="mb-3 text-lg font-semibold">
                                                    Average: {question.average}
                                                    <span className="ml-2 text-sm font-normal text-muted-foreground">
                                                        (min: {question.min},
                                                        max: {question.max})
                                                    </span>
                                                </p>
                                            )}
                                        {question.distribution &&
                                            Object.keys(question.distribution)
                                                .length > 0 && (
                                                <div className="h-40">
                                                    <ResponsiveContainer
                                                        width="100%"
                                                        height="100%"
                                                    >
                                                        <BarChart
                                                            data={Object.entries(
                                                                question.distribution,
                                                            ).map(([k, v]) => ({
                                                                score: k,
                                                                count: v,
                                                            }))}
                                                        >
                                                            <CartesianGrid
                                                                strokeDasharray="3 3"
                                                                className="stroke-muted"
                                                            />
                                                            <XAxis
                                                                dataKey="score"
                                                                tick={{
                                                                    fontSize: 11,
                                                                }}
                                                            />
                                                            <YAxis
                                                                tick={{
                                                                    fontSize: 11,
                                                                }}
                                                            />
                                                            <Tooltip />
                                                            <Bar
                                                                dataKey="count"
                                                                fill="#3b82f6"
                                                                radius={[
                                                                    4, 4, 0, 0,
                                                                ]}
                                                            />
                                                        </BarChart>
                                                    </ResponsiveContainer>
                                                </div>
                                            )}
                                    </div>
                                )}

                                {question.question_type === 'multiple_choice' &&
                                    question.distribution && (
                                        <div className="flex gap-6">
                                            <div className="h-48 flex-1">
                                                <ResponsiveContainer
                                                    width="100%"
                                                    height="100%"
                                                >
                                                    <PieChart>
                                                        <Pie
                                                            data={Object.entries(
                                                                question.distribution,
                                                            ).map(([k, v]) => ({
                                                                name: k,
                                                                value: v,
                                                            }))}
                                                            dataKey="value"
                                                            nameKey="name"
                                                            cx="50%"
                                                            cy="50%"
                                                            outerRadius={70}
                                                            label={({
                                                                name,
                                                                value,
                                                            }) =>
                                                                `${name}: ${value}`
                                                            }
                                                        >
                                                            {Object.keys(
                                                                question.distribution,
                                                            ).map((_, i) => (
                                                                <Cell
                                                                    key={i}
                                                                    fill={
                                                                        PIE_COLORS[
                                                                            i %
                                                                                PIE_COLORS.length
                                                                        ]
                                                                    }
                                                                />
                                                            ))}
                                                        </Pie>
                                                        <Tooltip />
                                                    </PieChart>
                                                </ResponsiveContainer>
                                            </div>
                                            <div className="space-y-2">
                                                {Object.entries(
                                                    question.distribution,
                                                ).map(([choice, count], i) => (
                                                    <div
                                                        key={choice}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <span
                                                            className="inline-block h-3 w-3 rounded-full"
                                                            style={{
                                                                backgroundColor:
                                                                    PIE_COLORS[
                                                                        i %
                                                                            PIE_COLORS.length
                                                                    ],
                                                            }}
                                                        />
                                                        <span>
                                                            {choice}: {count}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                {question.question_type === 'text' &&
                                    question.responses && (
                                        <div className="max-h-60 space-y-2 overflow-y-auto">
                                            {question.responses.map(
                                                (text, i) => (
                                                    <div
                                                        key={i}
                                                        className="rounded-md bg-muted/50 p-3 text-sm"
                                                    >
                                                        {text}
                                                    </div>
                                                ),
                                            )}
                                            {question.responses.length ===
                                                0 && (
                                                <p className="text-sm text-muted-foreground">
                                                    No text responses.
                                                </p>
                                            )}
                                        </div>
                                    )}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {results.questions.length === 0 && (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            No responses yet. Share the survey link to start
                            collecting feedback.
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
