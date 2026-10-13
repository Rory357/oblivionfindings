import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Plus, Trash2 } from 'lucide-react';
import type { HubContent } from './_types';

export function CampaignSettings({
    value,
    onChange,
}: {
    value: HubContent['campaign'];
    onChange: (value: HubContent['campaign']) => void;
}) {
    const steps = value?.steps ?? [];
    return (
        <section className="space-y-4 border-t pt-4">
            <h3 className="font-semibold">Campaign activities</h3>
            <p className="text-xs text-muted-foreground">
                Staff can complete activities and track their own progress.
                Changing an activity starts a fresh completion count.
            </p>
            {steps.length > 0 && (
                <Label className="grid gap-2">
                    End date (optional)
                    <Input
                        type="date"
                        value={value?.ends_on ?? ''}
                        onChange={(e) =>
                            onChange({ steps, ends_on: e.target.value || null })
                        }
                    />
                </Label>
            )}
            {steps.map((step, i) => (
                <div className="space-y-2 rounded-lg border p-3" key={step.id}>
                    <Label className="grid gap-2">
                        Activity {i + 1}
                        <Input
                            maxLength={200}
                            value={step.title}
                            onChange={(e) =>
                                onChange({
                                    ends_on: value?.ends_on ?? null,
                                    steps: steps.map((s) =>
                                        s.id === step.id
                                            ? { ...s, title: e.target.value }
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
                            onChange(
                                steps.length === 1
                                    ? null
                                    : {
                                          ends_on: value?.ends_on ?? null,
                                          steps: steps.filter(
                                              (s) => s.id !== step.id,
                                          ),
                                      },
                            )
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
    );
}
