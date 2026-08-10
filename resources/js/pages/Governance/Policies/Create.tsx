import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { BookOpen } from 'lucide-react';

export default function PolicyCreate({ auth }: PageProps) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        category: 'governance',
        description: '',
        content: '',
        effective_date: new Date().toISOString().split('T')[0],
        review_date: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000)
            .toISOString()
            .split('T')[0],
        requires_attestation: false,
        attestation_frequency: 'annual',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/governance/policies');
    };

    return (
        <AppLayout>
            <Head title="Create Policy" />
            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/policies"
                        icon={BookOpen}
                        title="Create Governance Policy"
                    />
                }
            >
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardContent className="space-y-6 p-6">
                            <div>
                                <Label htmlFor="title">Policy Title</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                />
                                {errors.title && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.title}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="category">Category</Label>
                                <Select
                                    value={data.category}
                                    onValueChange={(val) =>
                                        setData('category', val)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="governance">
                                            Governance
                                        </SelectItem>
                                        <SelectItem value="financial">
                                            Financial
                                        </SelectItem>
                                        <SelectItem value="hr">
                                            Human Resources
                                        </SelectItem>
                                        <SelectItem value="health_safety">
                                            Health & Safety
                                        </SelectItem>
                                        <SelectItem value="privacy">
                                            Privacy
                                        </SelectItem>
                                        <SelectItem value="clinical">
                                            Clinical
                                        </SelectItem>
                                        <SelectItem value="operational">
                                            Operational
                                        </SelectItem>
                                        <SelectItem value="other">
                                            Other
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    rows={2}
                                />
                            </div>

                            <div>
                                <Label htmlFor="content">Policy Content</Label>
                                <Textarea
                                    id="content"
                                    value={data.content}
                                    onChange={(e) =>
                                        setData('content', e.target.value)
                                    }
                                    rows={12}
                                />
                                {errors.content && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.content}
                                    </p>
                                )}
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="effective_date">
                                        Effective Date
                                    </Label>
                                    <Input
                                        id="effective_date"
                                        type="date"
                                        value={data.effective_date}
                                        onChange={(e) =>
                                            setData(
                                                'effective_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="review_date">
                                        Review Date
                                    </Label>
                                    <Input
                                        id="review_date"
                                        type="date"
                                        value={data.review_date}
                                        onChange={(e) =>
                                            setData(
                                                'review_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="requires_attestation"
                                    checked={data.requires_attestation}
                                    onCheckedChange={(val) =>
                                        setData(
                                            'requires_attestation',
                                            val === true,
                                        )
                                    }
                                />
                                <label
                                    htmlFor="requires_attestation"
                                    className="text-sm"
                                >
                                    Require board member attestation
                                </label>
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
                                    Create Policy
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
