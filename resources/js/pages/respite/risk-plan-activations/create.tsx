import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import RespiteSubnav from '@/components/respite-subnav';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    stays: any[];
    stayId?: string;
    clientId?: string;
    clientRisks: any[];
    planTypes: Record<string, string>;
};

export default function RiskPlanActivationCreate({ stays, stayId, clientId, clientRisks, planTypes }: Props) {
    const resolveClientId = (selectedStayId: string): string => {
        const selectedStay = stays.find((stay: any) => String(stay.id) === selectedStayId);

        if (selectedStay?.client?.id != null) {
            return String(selectedStay.client.id);
        }

        return clientId || '';
    };

    const { data, setData, post, processing, errors, transform } = useForm({
        stay_id: stayId || '',
        client_id: stayId ? resolveClientId(stayId) : clientId || '',
        plan_type: '',
        plan_name: '',
        plan_details: [] as string[],
        triggers: [] as string[],
        interventions: [] as string[],
        escalation_steps: [] as string[],
    });

    const [planDetails, setPlanDetails] = useState<string[]>(data.plan_details.length ? data.plan_details : ['']);
    const [triggers, setTriggers] = useState<string[]>(data.triggers.length ? data.triggers : ['']);
    const [interventions, setInterventions] = useState<string[]>(data.interventions.length ? data.interventions : ['']);
    const [escalationSteps, setEscalationSteps] = useState<string[]>(data.escalation_steps.length ? data.escalation_steps : ['']);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        transform((current) => ({
            ...current,
            client_id: current.client_id || resolveClientId(current.stay_id),
            plan_details: planDetails.filter(Boolean),
            triggers: triggers.filter(Boolean),
            interventions: interventions.filter(Boolean),
            escalation_steps: escalationSteps.filter(Boolean),
        }));
        post('/respite/risk-plan-activations');
    };

    const updateArray = (arr: string[], setArr: (v: string[]) => void, idx: number, val: string) => {
        const next = [...arr];
        next[idx] = val;
        setArr(next);
    };

    const addToArray = (arr: string[], setArr: (v: string[]) => void) => {
        setArr([...arr, '']);
    };

    const removeFromArray = (arr: string[], setArr: (v: string[]) => void, idx: number) => {
        const next = [...arr];
        next.splice(idx, 1);
        setArr(next);
    };

    const renderDynamicList = (label: string, arr: string[], setArr: (v: string[]) => void, errorKey: string) => (
        <div className="space-y-2">
            <Label>{label}</Label>
            {arr.map((item, idx) => (
                <div key={idx} className="flex gap-2">
                    <Input value={item} onChange={(e) => updateArray(arr, setArr, idx, e.target.value)} placeholder={`${label} item...`} />
                    {arr.length > 1 && (
                        <Button type="button" variant="outline" size="sm" onClick={() => removeFromArray(arr, setArr, idx)}>Remove</Button>
                    )}
                </div>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={() => addToArray(arr, setArr)}>Add {label} Item</Button>
            {(errors as any)[errorKey] && <div className="mt-1 text-xs text-red-500">{(errors as any)[errorKey]}</div>}
        </div>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Risk Plan Activations', href: '/respite/risk-plan-activations' }, { title: 'New', href: '/respite/risk-plan-activations/create' }]}>
            <Head title="New Risk Plan Activation" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">New Risk Plan Activation</h1>
                    <div className="mt-1 text-sm text-slate-500">Activate a risk plan for a respite stay.</div>
                </div>
                <RespiteSubnav />

                <form onSubmit={handleSubmit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Plan Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Stay *</Label>
                                    <Select
                                        value={data.stay_id}
                                        onValueChange={(value) => {
                                            setData('stay_id', value);
                                            setData('client_id', resolveClientId(value));
                                        }}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Select stay" /></SelectTrigger>
                                        <SelectContent>
                                            {stays.map((s: any) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.client?.first_name} {s.client?.last_name} — Stay #{s.id}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.stay_id && <div className="mt-1 text-xs text-red-500">{errors.stay_id}</div>}
                                </div>
                                <div>
                                    <Label>Plan Type *</Label>
                                    <Select value={data.plan_type} onValueChange={(v) => setData('plan_type', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(planTypes).map(([value, label]) => (
                                                <SelectItem key={value} value={value}>{label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.plan_type && <div className="mt-1 text-xs text-red-500">{errors.plan_type}</div>}
                                </div>
                            </div>

                            <div>
                                <Label>Plan Name *</Label>
                                <Input value={data.plan_name} onChange={(e) => setData('plan_name', e.target.value)} placeholder="Enter plan name" />
                                {errors.plan_name && <div className="mt-1 text-xs text-red-500">{errors.plan_name}</div>}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Plan Content</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {renderDynamicList('Plan Details', planDetails, setPlanDetails, 'plan_details')}
                            {renderDynamicList('Triggers', triggers, setTriggers, 'triggers')}
                            {renderDynamicList('Interventions', interventions, setInterventions, 'interventions')}
                            {renderDynamicList('Escalation Steps', escalationSteps, setEscalationSteps, 'escalation_steps')}
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                        <Button type="submit" disabled={processing}>{processing ? 'Saving...' : 'Create Activation'}</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
