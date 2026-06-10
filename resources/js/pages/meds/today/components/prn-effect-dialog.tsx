/* PRN follow-up — record whether an as-needed dose helped. Posts to the
 * worker-scoped POST /meds/today/prn/effect endpoint, which writes the same
 * MedicationPrnEffectiveness register entry the admin path uses. */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, Segmented } from '@/components/wizard/primitives';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, Check, Loader2 } from 'lucide-react';

import type { ClientInfo, PrnFollowUp } from '../types';

type Effectiveness = 'effective' | 'partially_effective' | 'not_effective';

export function PrnEffectDialog({
    followUp,
    client,
    onClose,
}: {
    followUp: PrnFollowUp;
    client: ClientInfo | undefined;
    onClose: () => void;
}) {
    const form = useForm<{
        client_medication_administration_id: number;
        effectiveness: Effectiveness;
        observations: string;
        escalation_needed: boolean;
        escalation_action: string;
    }>({
        client_medication_administration_id: followUp.administration_id,
        effectiveness: 'effective',
        observations: '',
        escalation_needed: false,
        escalation_action: '',
    });

    const submit = () => {
        form.post('/meds/today/prn/effect', {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Did it help?</DialogTitle>
                    <DialogDescription>
                        {client?.name ?? 'Client'} ·{' '}
                        {followUp.medication_name ?? 'PRN dose'}
                        {followUp.given_time
                            ? ` · given ${followUp.given_time}`
                            : ''}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <Field label="Effect" required>
                        <Segmented<Effectiveness>
                            value={form.data.effectiveness}
                            onChange={(v) => form.setData('effectiveness', v)}
                            options={[
                                { value: 'effective', label: 'Helped' },
                                {
                                    value: 'partially_effective',
                                    label: 'Partly',
                                },
                                { value: 'not_effective', label: 'No effect' },
                            ]}
                        />
                    </Field>

                    <Field label="What did you observe?" hint="optional">
                        <Textarea
                            rows={2}
                            placeholder="e.g. Settled within 30 minutes, no further pain reported…"
                            value={form.data.observations}
                            onChange={(e) =>
                                form.setData('observations', e.target.value)
                            }
                        />
                    </Field>

                    <div className="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                        <div>
                            <div className="text-sm font-semibold">
                                Needs escalation
                            </div>
                            <div className="text-xs text-muted-foreground">
                                Tell the team leader or on-call nurse.
                            </div>
                        </div>
                        <Switch
                            checked={form.data.escalation_needed}
                            onCheckedChange={(checked) =>
                                form.setData('escalation_needed', checked)
                            }
                            aria-label="Needs escalation"
                        />
                    </div>

                    {form.data.escalation_needed ? (
                        <Field label="What was done?" required>
                            <Textarea
                                rows={2}
                                placeholder="e.g. Called the on-call nurse at 1:40 pm…"
                                value={form.data.escalation_action}
                                onChange={(e) =>
                                    form.setData(
                                        'escalation_action',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    ) : null}

                    {form.errors.client_medication_administration_id ||
                    form.errors.effectiveness ? (
                        <InfoCard icon={AlertTriangle} tone="crit">
                            {form.errors.client_medication_administration_id ??
                                form.errors.effectiveness}
                        </InfoCard>
                    ) : null}
                </div>

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={
                            form.processing ||
                            (form.data.escalation_needed &&
                                !form.data.escalation_action.trim())
                        }
                    >
                        {form.processing ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <Check className="h-4 w-4" />
                        )}
                        Record effect
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default PrnEffectDialog;
