import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FileText } from 'lucide-react';

interface Meeting {
    id: number;
    title: string;
    scheduled_at: string;
}

interface Props extends PageProps {
    meetings: Meeting[];
}

export default function CeoReportCreate({ auth, meetings }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        governance_meeting_id: '',
        operational_summary: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/governance/ceo-reports');
    };

    return (
        <AppLayout>
            <Head title="Create CEO Report" />
            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/ceo-reports"
                        icon={FileText}
                        title="Create CEO Board Report"
                        description="Compose a new CEO update for the board"
                    />
                }
            >
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardContent className="space-y-6 p-6">
                            <div>
                                <Label>Board Meeting</Label>
                                <Select
                                    value={
                                        data.governance_meeting_id || undefined
                                    }
                                    onValueChange={(val) =>
                                        setData('governance_meeting_id', val)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select meeting..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {meetings.map((m) => (
                                            <SelectItem
                                                key={m.id}
                                                value={String(m.id)}
                                            >
                                                {m.title} (
                                                {new Date(
                                                    m.scheduled_at,
                                                ).toLocaleDateString('en-NZ')}
                                                )
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.governance_meeting_id && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.governance_meeting_id}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label>Report Title</Label>
                                <Input
                                    value={
                                        meetings.find(
                                            (meeting) =>
                                                String(meeting.id) ===
                                                data.governance_meeting_id,
                                        )?.title ??
                                        'Will be generated from the selected meeting'
                                    }
                                    readOnly
                                />
                            </div>

                            <div>
                                <Label>Executive Summary</Label>
                                <Textarea
                                    value={data.operational_summary}
                                    onChange={(e) =>
                                        setData(
                                            'operational_summary',
                                            e.target.value,
                                        )
                                    }
                                    rows={8}
                                />
                                {errors.operational_summary && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.operational_summary}
                                    </p>
                                )}
                            </div>

                            <div className="flex justify-end gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Create Report
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
