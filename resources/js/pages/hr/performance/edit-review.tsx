import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type ReviewType = {
    value: string;
    label: string;
};

type PerformanceReview = {
    id: number;
    employee_user_id: number;
    review_type: string;
    review_period_start: string;
    review_period_end: string;
    overall_rating: number | null;
    strengths: string | null;
    development_areas: string | null;
    goals: string[] | null;
    training_recommendations: string[] | null;
    next_review_date: string | null;
    status: string;
    employee: {
        id: number;
        name: string;
    };
};

type Props = {
    review: PerformanceReview;
    reviewTypes: ReviewType[];
};

export default function EditReview({ review, reviewTypes }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Performance & Supervision', href: '/hr/performance' },
        { title: 'Reviews', href: '/hr/performance/reviews' },
        {
            title: review.employee.name,
            href: `/hr/performance/reviews/${review.id}`,
        },
        { title: 'Edit', href: `/hr/performance/reviews/${review.id}/edit` },
    ];

    const initialGoals = review.goals?.length ? review.goals : [''];
    const initialTraining = review.training_recommendations?.length
        ? review.training_recommendations
        : [''];

    const [goals, setGoals] = useState<string[]>(initialGoals);
    const [training, setTraining] = useState<string[]>(initialTraining);

    const { data, setData, put, processing, errors } = useForm({
        review_type: review.review_type,
        review_period_start: review.review_period_start.split('T')[0],
        review_period_end: review.review_period_end.split('T')[0],
        overall_rating: review.overall_rating?.toString() || '',
        strengths: review.strengths || '',
        development_areas: review.development_areas || '',
        goals: review.goals || [],
        training_recommendations: review.training_recommendations || [],
        next_review_date: review.next_review_date?.split('T')[0] || '',
    });

    const addGoal = () => setGoals([...goals, '']);
    const updateGoal = (index: number, value: string) => {
        const newGoals = [...goals];
        newGoals[index] = value;
        setGoals(newGoals);
        setData(
            'goals',
            newGoals.filter((g) => g.trim() !== ''),
        );
    };
    const removeGoal = (index: number) => {
        const newGoals = goals.filter((_, i) => i !== index);
        setGoals(newGoals);
        setData(
            'goals',
            newGoals.filter((g) => g.trim() !== ''),
        );
    };

    const addTraining = () => setTraining([...training, '']);
    const updateTraining = (index: number, value: string) => {
        const newTraining = [...training];
        newTraining[index] = value;
        setTraining(newTraining);
        setData(
            'training_recommendations',
            newTraining.filter((t) => t.trim() !== ''),
        );
    };
    const removeTraining = (index: number) => {
        const newTraining = training.filter((_, i) => i !== index);
        setTraining(newTraining);
        setData(
            'training_recommendations',
            newTraining.filter((t) => t.trim() !== ''),
        );
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/hr/performance/reviews/${review.id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Performance Review" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref={`/hr/performance/reviews/${review.id}`}
                        title="Edit Performance Review"
                        description={`Update review for ${review.employee.name}`}
                    />
                }
            >
                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Review Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="employee_user_id">
                                        Staff Member
                                    </Label>
                                    <Input
                                        value={review.employee.name}
                                        disabled
                                        className="bg-muted"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Employee cannot be changed
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="review_type">
                                        Review Type{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={data.review_type}
                                        onValueChange={(value) =>
                                            setData('review_type', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="review_type"
                                            className={
                                                errors.review_type
                                                    ? 'border-status-critical/30'
                                                    : ''
                                            }
                                        >
                                            <SelectValue placeholder="Select review type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {reviewTypes.map((type) => (
                                                <SelectItem
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.review_type && (
                                        <p className="text-sm text-status-critical">
                                            {errors.review_type}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="review_period_start">
                                        Period Start{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        id="review_period_start"
                                        type="date"
                                        value={data.review_period_start}
                                        onChange={(e) =>
                                            setData(
                                                'review_period_start',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.review_period_start
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.review_period_start && (
                                        <p className="text-sm text-status-critical">
                                            {errors.review_period_start}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="review_period_end">
                                        Period End{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        id="review_period_end"
                                        type="date"
                                        value={data.review_period_end}
                                        onChange={(e) =>
                                            setData(
                                                'review_period_end',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.review_period_end
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.review_period_end && (
                                        <p className="text-sm text-status-critical">
                                            {errors.review_period_end}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="next_review_date">
                                        Next Review Date
                                    </Label>
                                    <Input
                                        id="next_review_date"
                                        type="date"
                                        value={data.next_review_date}
                                        onChange={(e) =>
                                            setData(
                                                'next_review_date',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.next_review_date
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.next_review_date && (
                                        <p className="text-sm text-status-critical">
                                            {errors.next_review_date}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="overall_rating">
                                        Overall Rating
                                    </Label>
                                    <Select
                                        value={data.overall_rating}
                                        onValueChange={(value) =>
                                            setData('overall_rating', value)
                                        }
                                    >
                                        <SelectTrigger id="overall_rating">
                                            <SelectValue placeholder="Select rating" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="1">
                                                1 - Needs Improvement
                                            </SelectItem>
                                            <SelectItem value="2">
                                                2 - Below Expectations
                                            </SelectItem>
                                            <SelectItem value="3">
                                                3 - Meets Expectations
                                            </SelectItem>
                                            <SelectItem value="4">
                                                4 - Exceeds Expectations
                                            </SelectItem>
                                            <SelectItem value="5">
                                                5 - Outstanding
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Performance Assessment</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="strengths">Strengths</Label>
                                <Textarea
                                    id="strengths"
                                    placeholder="What has the employee done well..."
                                    rows={4}
                                    value={data.strengths}
                                    onChange={(e) =>
                                        setData('strengths', e.target.value)
                                    }
                                    className={
                                        errors.strengths
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.strengths && (
                                    <p className="text-sm text-status-critical">
                                        {errors.strengths}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="development_areas">
                                    Development Areas
                                </Label>
                                <Textarea
                                    id="development_areas"
                                    placeholder="Areas where the employee can improve..."
                                    rows={4}
                                    value={data.development_areas}
                                    onChange={(e) =>
                                        setData(
                                            'development_areas',
                                            e.target.value,
                                        )
                                    }
                                    className={
                                        errors.development_areas
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.development_areas && (
                                    <p className="text-sm text-status-critical">
                                        {errors.development_areas}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Goals</CardTitle>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addGoal}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Add Goal
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {goals.map((goal, index) => (
                                <div key={index} className="flex gap-2">
                                    <Input
                                        placeholder={`Goal ${index + 1}`}
                                        value={goal}
                                        onChange={(e) =>
                                            updateGoal(index, e.target.value)
                                        }
                                    />
                                    {goals.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => removeGoal(index)}
                                            className="text-status-critical hover:text-status-critical"
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Training Recommendations</CardTitle>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addTraining}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Add Training
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {training.map((item, index) => (
                                <div key={index} className="flex gap-2">
                                    <Input
                                        placeholder={`Training ${index + 1}`}
                                        value={item}
                                        onChange={(e) =>
                                            updateTraining(
                                                index,
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {training.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                removeTraining(index)
                                            }
                                            className="text-status-critical hover:text-status-critical"
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-4">
                        <Link href={`/hr/performance/reviews/${review.id}`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Update Review'}
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
