import { PageHero, PageLayout } from '@/components/page';
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
import AppLayout from '@/layouts/app-layout';
import { store as storePerformance } from '@/routes/governance/performance';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Target } from 'lucide-react';

interface BoardMember {
    id: number;
    user: { id: number; name: string };
    board_role: string;
}

interface Props extends PageProps {
    boardMembers: BoardMember[];
}

export default function CreatePerformance({ auth, boardMembers }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        reviewee_id: '',
        review_cycle: 'annual',
        review_type: 'annual',
        period_start: '',
        period_end: '',
        overall_rating: '',
        reviewer_notes: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(storePerformance.url());
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Performance', href: '/governance/performance' },
                { title: 'Create', href: '/governance/performance/create' },
            ]}
        >
            <Head title="New Performance Review" />

            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/performance"
                        icon={Target}
                        title="New Performance Review"
                        description="Set up a performance review cycle"
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle>Review Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <Label htmlFor="reviewee_id">Reviewee</Label>
                                <Select
                                    value={data.reviewee_id || undefined}
                                    onValueChange={(v) =>
                                        setData('reviewee_id', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a board member" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {boardMembers.map((member) => (
                                            <SelectItem
                                                key={member.id}
                                                value={String(member.user.id)}
                                            >
                                                {member.user.name} (
                                                {member.board_role})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.reviewee_id && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.reviewee_id}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="review_type">Review Type</Label>
                                <Select
                                    value={data.review_type}
                                    onValueChange={(v) => {
                                        setData('review_type', v);
                                        setData('review_cycle', v);
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="quarterly">
                                            Quarterly
                                        </SelectItem>
                                        <SelectItem value="annual">
                                            Annual
                                        </SelectItem>
                                        <SelectItem value="ad_hoc">
                                            Ad-hoc
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.review_type && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.review_type}
                                    </p>
                                )}
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="period_start">
                                        Period Start
                                    </Label>
                                    <Input
                                        id="period_start"
                                        type="date"
                                        value={data.period_start}
                                        onChange={(e) =>
                                            setData(
                                                'period_start',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.period_start && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {errors.period_start}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="period_end">
                                        Period End
                                    </Label>
                                    <Input
                                        id="period_end"
                                        type="date"
                                        value={data.period_end}
                                        onChange={(e) =>
                                            setData(
                                                'period_end',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.period_end && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {errors.period_end}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="reviewer_notes">
                                    Reviewer Notes
                                </Label>
                                <Textarea
                                    id="reviewer_notes"
                                    value={data.reviewer_notes}
                                    onChange={(e) =>
                                        setData(
                                            'reviewer_notes',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Initial observations and notes..."
                                    rows={4}
                                />
                            </div>

                            <div className="flex gap-2 pt-4">
                                <Button type="submit" disabled={processing}>
                                    Create Review
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
