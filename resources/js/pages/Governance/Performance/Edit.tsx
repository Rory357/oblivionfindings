import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { UserCheck } from 'lucide-react';

interface Review {
    id: number;
    review_cycle: string;
    review_type: string;
    period_start: string;
    period_end: string;
    overall_assessment: string | null;
    overall_rating: string | null;
    board_decision: string | null;
    decision_notes: string | null;
}

export default function EditPerformance({ auth, review }: { auth: any; review: Review }) {
    const { data, setData, put, processing, errors } = useForm({
        review_cycle: review.review_cycle,
        review_type: review.review_type,
        period_start: review.period_start,
        period_end: review.period_end,
        overall_assessment: review.overall_assessment ?? '',
        overall_rating: review.overall_rating ?? '',
        board_decision: review.board_decision ?? '',
        decision_notes: review.decision_notes ?? '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/governance/performance/${review.id}`);
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Performance', href: '/governance/performance' },
                { title: review.review_cycle, href: `/governance/performance/${review.id}` },
                { title: 'Edit', href: `/governance/performance/${review.id}/edit` },
            ]}
        >
            <Head title={`Edit: ${review.review_cycle} Review`} />
            <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex items-center gap-3 mb-6">
                    <UserCheck className="w-8 h-8 text-status-info" />
                    <h1 className="text-3xl font-bold text-foreground">Edit Performance Review</h1>
                </div>
                <Card>
                    <CardHeader><CardTitle>Review Details</CardTitle></CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label>Review Cycle</Label>
                                    <Input value={data.review_cycle} onChange={(e) => setData('review_cycle', e.target.value)} placeholder="e.g., 2026 Q1" />
                                </div>
                                <div>
                                    <Label>Review Type</Label>
                                    <Select value={data.review_type} onValueChange={(v) => setData('review_type', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="quarterly">Quarterly Check-in</SelectItem>
                                            <SelectItem value="annual">Annual Review</SelectItem>
                                            <SelectItem value="probation">Probation Review</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label>Period Start</Label>
                                    <Input type="date" value={data.period_start} onChange={(e) => setData('period_start', e.target.value)} />
                                </div>
                                <div>
                                    <Label>Period End</Label>
                                    <Input type="date" value={data.period_end} onChange={(e) => setData('period_end', e.target.value)} />
                                </div>
                            </div>
                            <div>
                                <Label>Overall Assessment</Label>
                                <Textarea value={data.overall_assessment} onChange={(e) => setData('overall_assessment', e.target.value)} rows={4} placeholder="Overall assessment narrative..." />
                            </div>
                            <div>
                                <Label>Overall Rating</Label>
                                <Select value={data.overall_rating || undefined} onValueChange={(v) => setData('overall_rating', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select rating" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="exceptional">Exceptional</SelectItem>
                                        <SelectItem value="exceeds">Exceeds Expectations</SelectItem>
                                        <SelectItem value="meets">Meets Expectations</SelectItem>
                                        <SelectItem value="developing">Developing</SelectItem>
                                        <SelectItem value="below">Below Expectations</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Board Decision</Label>
                                <Textarea value={data.decision_notes} onChange={(e) => setData('decision_notes', e.target.value)} rows={3} placeholder="Board decision notes..." />
                            </div>
                            <div className="flex gap-2 pt-4">
                                <Button type="submit" disabled={processing}>Update Review</Button>
                                <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
