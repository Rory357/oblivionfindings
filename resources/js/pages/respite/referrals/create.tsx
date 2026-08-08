import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
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
import { Head, useForm } from '@inertiajs/react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

export default function RespiteReferralCreate({ clients }: Props) {
    const form = useForm({
        client_id: '',
        referrer_type: '',
        referrer_name: '',
        referrer_contact: '',
        referral_reason: '',
        urgency: 'planned',
    });

    const { data, setData, post, processing, errors } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/respite/referrals');
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                { title: 'New Referral', href: '/respite/referrals/create' },
            ]}
        >
            <Head title="New Respite Referral" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/referrals"
                        title="New Respite Referral"
                        description="Capture intake details for respite support."
                    />
                }
            >
                <RespiteSubnav />

                <form onSubmit={submit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Referral Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Client *</Label>
                                    <Select
                                        value={data.client_id}
                                        onValueChange={(v) =>
                                            setData('client_id', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select client" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem
                                                    key={c.id}
                                                    value={String(c.id)}
                                                >
                                                    {c.first_name} {c.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && (
                                        <div className="mt-1 text-xs text-status-critical">
                                            {errors.client_id}
                                        </div>
                                    )}
                                </div>
                                <div>
                                    <Label>Urgency *</Label>
                                    <Select
                                        value={data.urgency}
                                        onValueChange={(v) =>
                                            setData('urgency', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select urgency" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[
                                                'planned',
                                                'urgent',
                                                'crisis',
                                            ].map((u) => (
                                                <SelectItem key={u} value={u}>
                                                    {u}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.urgency && (
                                        <div className="mt-1 text-xs text-status-critical">
                                            {errors.urgency}
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Referrer Type</Label>
                                    <Select
                                        value={data.referrer_type}
                                        onValueChange={(v) =>
                                            setData('referrer_type', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select referrer type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[
                                                'Self',
                                                'Family/Whanau',
                                                'GP',
                                                'Hospital',
                                                'NASC',
                                                'Social Worker',
                                                'School',
                                                'Community',
                                                'Other',
                                            ].map((t) => (
                                                <SelectItem key={t} value={t}>
                                                    {t}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Referrer Name *</Label>
                                    <Input
                                        value={data.referrer_name}
                                        onChange={(e) =>
                                            setData(
                                                'referrer_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.referrer_name && (
                                        <div className="mt-1 text-xs text-status-critical">
                                            {errors.referrer_name}
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div>
                                <Label>Referrer Contact</Label>
                                <Input
                                    value={data.referrer_contact}
                                    onChange={(e) =>
                                        setData(
                                            'referrer_contact',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div>
                                <Label>Referral Reason *</Label>
                                <Textarea
                                    value={data.referral_reason}
                                    onChange={(e) =>
                                        setData(
                                            'referral_reason',
                                            e.target.value,
                                        )
                                    }
                                    rows={4}
                                />
                                {errors.referral_reason && (
                                    <div className="mt-1 text-xs text-status-critical">
                                        {errors.referral_reason}
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.history.back()}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Create Referral'}
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
