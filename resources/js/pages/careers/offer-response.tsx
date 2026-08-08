import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm } from '@inertiajs/react';
import { FileCheck } from 'lucide-react';

interface OfferData {
    position_title: string;
    position_role: string | null;
    employment_type: string;
    proposed_start_date: string | null;
    hours_per_week: number | null;
    hourly_rate: number | null;
    annual_salary: number | null;
    conditions: string | null;
    response: string | null;
    response_at: string | null;
    site_name: string | null;
}

interface Props {
    valid: boolean;
    expired: boolean;
    token?: string;
    offer?: OfferData;
    candidate?: { name: string; email: string | null };
}

export default function OfferResponse({
    valid,
    expired,
    token,
    offer,
    candidate,
}: Props) {
    const form = useForm({
        response: 'accepted',
        signature_name: candidate?.name || '',
        response_notes: '',
        terms_accepted: false,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!token) {
            return;
        }

        form.post(`/careers/offers/${token}`);
    }

    if (!valid) {
        return (
            <>
                <Head title="Offer Response" />
                <div className="mx-auto max-w-xl px-4 py-14">
                    <Card>
                        <CardContent className="py-10 text-center">
                            <h1 className="text-xl font-semibold">
                                Offer link is invalid
                            </h1>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Please contact our HR team for a new offer link.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Respond to Offer" />
            <div className="mx-auto max-w-3xl px-4 py-10">
                <PageLayout
                    padding="none"
                    hero={
                        <PageHero
                            variant="compact"
                            icon={FileCheck}
                            title="Offer Response"
                            description="Review your offer and respond below."
                        />
                    }
                >
                    <Card>
                        <CardHeader>
                            <CardTitle>{offer?.position_title}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <p className="text-muted-foreground">
                                {offer?.position_role
                                    ? offer.position_role.replace('_', ' ')
                                    : 'Role'}
                                {' · '}
                                {offer?.employment_type?.replace('_', ' ')}
                                {offer?.site_name
                                    ? ` · ${offer.site_name}`
                                    : ''}
                            </p>
                            <p>
                                Proposed start:{' '}
                                {offer?.proposed_start_date || '-'}
                            </p>
                            <p>
                                Hours per week: {offer?.hours_per_week ?? '-'}
                            </p>
                            <p>
                                Hourly rate:{' '}
                                {offer?.hourly_rate !== null &&
                                offer?.hourly_rate !== undefined
                                    ? `$${offer.hourly_rate}`
                                    : '-'}
                            </p>
                            <p>
                                Annual salary:{' '}
                                {offer?.annual_salary !== null &&
                                offer?.annual_salary !== undefined
                                    ? `$${offer.annual_salary}`
                                    : '-'}
                            </p>
                            {offer?.conditions && (
                                <p className="whitespace-pre-wrap">
                                    Conditions: {offer.conditions}
                                </p>
                            )}

                            {offer?.response && (
                                <div className="pt-2">
                                    <Badge
                                        variant={
                                            offer.response === 'accepted'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                        className="capitalize"
                                    >
                                        {offer.response}
                                    </Badge>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Responded at {offer.response_at}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {expired ? (
                        <Card>
                            <CardContent className="py-8 text-center text-sm text-muted-foreground">
                                This offer link has expired. Please contact HR.
                            </CardContent>
                        </Card>
                    ) : !offer?.response ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>Respond & E-Sign</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={submit} className="space-y-4">
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <Button
                                            type="button"
                                            variant={
                                                form.data.response ===
                                                'accepted'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() =>
                                                form.setData(
                                                    'response',
                                                    'accepted',
                                                )
                                            }
                                        >
                                            Accept
                                        </Button>
                                        <Button
                                            type="button"
                                            variant={
                                                form.data.response ===
                                                'declined'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() =>
                                                form.setData(
                                                    'response',
                                                    'declined',
                                                )
                                            }
                                        >
                                            Decline
                                        </Button>
                                        <Button
                                            type="button"
                                            variant={
                                                form.data.response ===
                                                'withdrawn'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() =>
                                                form.setData(
                                                    'response',
                                                    'withdrawn',
                                                )
                                            }
                                        >
                                            Withdraw
                                        </Button>
                                    </div>

                                    {form.data.response === 'accepted' && (
                                        <div className="space-y-2">
                                            <Label>
                                                Digital signature (full name)
                                            </Label>
                                            <Input
                                                value={form.data.signature_name}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'signature_name',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            {form.errors.signature_name && (
                                                <p className="text-sm text-destructive">
                                                    {form.errors.signature_name}
                                                </p>
                                            )}

                                            <div className="flex items-center gap-2">
                                                <Checkbox
                                                    id="terms"
                                                    checked={
                                                        form.data.terms_accepted
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        form.setData(
                                                            'terms_accepted',
                                                            Boolean(checked),
                                                        )
                                                    }
                                                />
                                                <Label
                                                    htmlFor="terms"
                                                    className="font-normal"
                                                >
                                                    I confirm this is my
                                                    electronic signature and I
                                                    accept this offer.
                                                </Label>
                                            </div>
                                            {form.errors.terms_accepted && (
                                                <p className="text-sm text-destructive">
                                                    {form.errors.terms_accepted}
                                                </p>
                                            )}
                                        </div>
                                    )}

                                    <div className="space-y-2">
                                        <Label>Notes (optional)</Label>
                                        <Textarea
                                            rows={4}
                                            value={form.data.response_notes}
                                            onChange={(e) =>
                                                form.setData(
                                                    'response_notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>

                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        {form.processing
                                            ? 'Submitting...'
                                            : 'Submit Response'}
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    ) : null}
                </PageLayout>
            </div>
        </>
    );
}
