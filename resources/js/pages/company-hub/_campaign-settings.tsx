import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Plus, Trash2 } from 'lucide-react';
import { CampaignCuration } from './_campaign-curation';
import { HubDateField } from './_date-fields';
import type { HubContent, HubProps } from './_types';

export function CampaignSettings({
    value,
    onChange,
    data,
}: {
    value: HubContent['campaign'];
    onChange: (value: HubContent['campaign']) => void;
    data: HubProps;
}) {
    const steps = value?.steps ?? [];
    return (
        <>
            <CampaignCuration value={value} data={data} onChange={onChange} />
            <section className="space-y-4 border-t pt-4">
                <h3 className="font-semibold">Personal activities</h3>
                <p className="text-xs text-muted-foreground">
                    Staff can complete activities and track their own progress.
                    Changing an activity starts a fresh completion count.
                </p>
                <Label className="grid gap-2">
                    End date (optional)
                    <HubDateField
                        label="Campaign end date"
                        value={value?.ends_on ?? ''}
                        onChange={(nextDate) =>
                            onChange({
                                ...value,
                                steps,
                                ends_on: nextDate || null,
                            })
                        }
                    />
                </Label>
                {steps.map((step, i) => (
                    <div
                        className="space-y-2 rounded-lg border p-3"
                        key={step.id}
                    >
                        <Label className="grid gap-2">
                            Activity {i + 1}
                            <Input
                                maxLength={200}
                                value={step.title}
                                onChange={(e) =>
                                    onChange({
                                        ...value,
                                        ends_on: value?.ends_on ?? null,
                                        steps: steps.map((s) =>
                                            s.id === step.id
                                                ? {
                                                      ...s,
                                                      title: e.target.value,
                                                  }
                                                : s,
                                        ),
                                    })
                                }
                            />
                        </Label>
                        <Label className="grid gap-2">
                            Activity link (optional)
                            <Input
                                value={step.url ?? ''}
                                placeholder="/company-hub… or https://…"
                                onChange={(e) =>
                                    onChange({
                                        ...value,
                                        ends_on: value?.ends_on ?? null,
                                        steps: steps.map((s) =>
                                            s.id === step.id
                                                ? { ...s, url: e.target.value }
                                                : s,
                                        ),
                                    })
                                }
                            />
                        </Label>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                onChange({
                                    ...value,
                                    ends_on: value?.ends_on ?? null,
                                    steps: steps.filter(
                                        (s) => s.id !== step.id,
                                    ),
                                })
                            }
                        >
                            <Trash2 className="size-3" />
                            Remove activity
                        </Button>
                    </div>
                ))}
                <Button
                    variant="outline"
                    disabled={steps.length >= 12}
                    onClick={() =>
                        onChange({
                            ...value,
                            ends_on: value?.ends_on ?? null,
                            steps: [
                                ...steps,
                                {
                                    id: crypto.randomUUID(),
                                    title: 'New activity',
                                    url: '',
                                },
                            ],
                        })
                    }
                >
                    <Plus className="size-4" />
                    Add activity
                </Button>
            </section>
        </>
    );
}
