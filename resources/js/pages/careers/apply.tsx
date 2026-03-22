import MarketingLayout from '@/layouts/marketing-layout';
import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ArrowLeft } from 'lucide-react';

type Props = {
    posting: {
        id: number;
        title: string;
        department: string | null;
        location: string | null;
        employment_type: string;
    };
};

export default function CareersApply({ posting }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        cover_letter: '',
        cv: null as File | null,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/careers/${posting.id}/apply`, {
            forceFormData: true,
        });
    };

    return (
        <MarketingLayout title={`Apply - ${posting.title}`} description={`Apply for ${posting.title} position.`}>
            <div className="mx-auto max-w-2xl px-4 py-16 sm:px-6 lg:px-8">
                <Link href={`/careers/${posting.id}`} className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-6">
                    <ArrowLeft className="h-4 w-4" />
                    Back to job details
                </Link>

                <div className="mb-8">
                    <h1 className="text-3xl font-bold tracking-tight">Apply for {posting.title}</h1>
                    <p className="mt-2 text-muted-foreground">
                        {posting.department && `${posting.department} `}
                        {posting.location && `- ${posting.location}`}
                    </p>
                </div>

                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle>Your Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="first_name">First Name *</Label>
                                    <Input
                                        id="first_name"
                                        value={data.first_name}
                                        onChange={(e) => setData('first_name', e.target.value)}
                                        className="mt-1"
                                        required
                                    />
                                    {errors.first_name && <p className="text-sm text-destructive mt-1">{errors.first_name}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="last_name">Last Name *</Label>
                                    <Input
                                        id="last_name"
                                        value={data.last_name}
                                        onChange={(e) => setData('last_name', e.target.value)}
                                        className="mt-1"
                                        required
                                    />
                                    {errors.last_name && <p className="text-sm text-destructive mt-1">{errors.last_name}</p>}
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="email">Email Address *</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    className="mt-1"
                                    required
                                />
                                {errors.email && <p className="text-sm text-destructive mt-1">{errors.email}</p>}
                            </div>

                            <div>
                                <Label htmlFor="phone">Phone Number</Label>
                                <Input
                                    id="phone"
                                    type="tel"
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                    className="mt-1"
                                />
                                {errors.phone && <p className="text-sm text-destructive mt-1">{errors.phone}</p>}
                            </div>

                            <div>
                                <Label htmlFor="cover_letter">Cover Letter</Label>
                                <Textarea
                                    id="cover_letter"
                                    value={data.cover_letter}
                                    onChange={(e) => setData('cover_letter', e.target.value)}
                                    rows={6}
                                    className="mt-1"
                                    placeholder="Tell us why you'd be a great fit for this role..."
                                />
                                {errors.cover_letter && <p className="text-sm text-destructive mt-1">{errors.cover_letter}</p>}
                            </div>

                            <div>
                                <Label htmlFor="cv">CV / Resume (PDF, DOC, DOCX - max 10MB)</Label>
                                <Input
                                    id="cv"
                                    type="file"
                                    accept=".pdf,.doc,.docx"
                                    onChange={(e) => setData('cv', e.target.files?.[0] || null)}
                                    className="mt-1"
                                />
                                {errors.cv && <p className="text-sm text-destructive mt-1">{errors.cv}</p>}
                            </div>

                            <div className="pt-4 flex justify-end gap-3">
                                <Button type="button" variant="outline" onClick={() => window.history.back()}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Submitting...' : 'Submit Application'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </MarketingLayout>
    );
}
