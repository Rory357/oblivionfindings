import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

type GroupedEvents = Record<string, string[]>;

type Rule = {
    enabled: boolean;
    require_ack: boolean;
    must_ack_before_close: boolean;
    force_delivery: boolean;
    remind_after_minutes: number;
    repeat_every_minutes: number;
    max_reminders: number;
    escalate_to_role_groups: string[];
    tiers: any[];
};

type Props = {
    groups: GroupedEvents;
    rules: Record<string, Rule>;
    availableRoleGroups: Record<string, string>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    {
        title: 'Notification Escalations',
        href: '/settings/notification-escalations',
    },
];

export default function NotificationEscalations({
    groups,
    rules,
    availableRoleGroups,
}: Props) {
    const form = useForm<{ rules: Record<string, Rule> }>({
        rules,
    });

    const [tierErrors, setTierErrors] = useState<Record<string, string>>({});

    const setRule = (key: string, patch: Partial<Rule>) => {
        form.setData('rules', {
            ...form.data.rules,
            [key]: {
                ...form.data.rules[key],
                ...patch,
            },
        });
    };

    const toggleGroup = (eventKey: string, groupKey: string, on: boolean) => {
        const existing = new Set(
            form.data.rules[eventKey].escalate_to_role_groups || [],
        );
        if (on) existing.add(groupKey);
        else existing.delete(groupKey);
        setRule(eventKey, { escalate_to_role_groups: Array.from(existing) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification Escalations" />

            <SettingsLayout>
                <div className="space-y-6">
                    <div>
                        <HeadingSmall
                            title="Escalation rules"
                            description="Control acknowledgement requirements and automatic reminders for operational notifications."
                        />
                    </div>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put('/settings/notifications/escalations');
                        }}
                        className="space-y-6"
                    >
                        {Object.entries(groups).map(([groupLabel, keys]) => (
                            <Card key={groupLabel}>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        {groupLabel}
                                    </CardTitle>
                                </CardHeader>

                                <CardContent className="space-y-4">
                                    {keys.map((k, idx) => {
                                        const r = form.data.rules[k];
                                        if (!r) return null;

                                        return (
                                            <div key={k} className="space-y-3">
                                                {idx > 0 && <Separator />}

                                                <div className="flex flex-wrap items-center justify-between gap-3">
                                                    <div>
                                                        <div className="text-sm font-semibold">
                                                            {k}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            Re-notify pending
                                                            items until
                                                            acknowledged or
                                                            resolved.
                                                        </div>
                                                    </div>

                                                    <div className="flex items-center gap-3">
                                                        <div className="flex items-center gap-2">
                                                            <Checkbox
                                                                checked={
                                                                    !!r.enabled
                                                                }
                                                                onCheckedChange={(
                                                                    v,
                                                                ) =>
                                                                    setRule(k, {
                                                                        enabled:
                                                                            !!v,
                                                                    })
                                                                }
                                                                id={`${k}-enabled`}
                                                            />
                                                            <Label
                                                                htmlFor={`${k}-enabled`}
                                                                className="text-sm"
                                                            >
                                                                Enable
                                                            </Label>
                                                        </div>

                                                        <div className="flex items-center gap-2">
                                                            <Checkbox
                                                                checked={
                                                                    !!r.require_ack
                                                                }
                                                                onCheckedChange={(
                                                                    v,
                                                                ) =>
                                                                    setRule(k, {
                                                                        require_ack:
                                                                            !!v,
                                                                    })
                                                                }
                                                                id={`${k}-ack`}
                                                                disabled={
                                                                    !r.enabled
                                                                }
                                                            />
                                                            <Label
                                                                htmlFor={`${k}-ack`}
                                                                className="text-sm"
                                                            >
                                                                Require
                                                                acknowledgement
                                                            </Label>
                                                        </div>

                                                        <div className="flex items-center gap-2">
                                                            <Checkbox
                                                                checked={
                                                                    !!r.must_ack_before_close
                                                                }
                                                                onCheckedChange={(
                                                                    v,
                                                                ) =>
                                                                    setRule(k, {
                                                                        must_ack_before_close:
                                                                            !!v,
                                                                    })
                                                                }
                                                                id={`${k}-ackclose`}
                                                                disabled={
                                                                    !r.enabled ||
                                                                    !r.require_ack
                                                                }
                                                            />
                                                            <Label
                                                                htmlFor={`${k}-ackclose`}
                                                                className="text-sm"
                                                            >
                                                                Must acknowledge
                                                                before close
                                                            </Label>
                                                        </div>

                                                        <div className="flex items-center gap-2">
                                                            <Checkbox
                                                                checked={
                                                                    !!r.force_delivery
                                                                }
                                                                onCheckedChange={(
                                                                    v,
                                                                ) =>
                                                                    setRule(k, {
                                                                        force_delivery:
                                                                            !!v,
                                                                    })
                                                                }
                                                                id={`${k}-force`}
                                                                disabled={
                                                                    !r.enabled
                                                                }
                                                            />
                                                            <Label
                                                                htmlFor={`${k}-force`}
                                                                className="text-sm"
                                                            >
                                                                Force delivery
                                                            </Label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="grid gap-3 md:grid-cols-3">
                                                    <div className="space-y-1">
                                                        <Label className="text-xs">
                                                            Remind after
                                                            (minutes)
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            min={1}
                                                            value={
                                                                r.remind_after_minutes
                                                            }
                                                            disabled={
                                                                !r.enabled
                                                            }
                                                            onChange={(e) =>
                                                                setRule(k, {
                                                                    remind_after_minutes:
                                                                        Number(
                                                                            e
                                                                                .target
                                                                                .value ||
                                                                                0,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                    <div className="space-y-1">
                                                        <Label className="text-xs">
                                                            Repeat every
                                                            (minutes)
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            min={1}
                                                            value={
                                                                r.repeat_every_minutes
                                                            }
                                                            disabled={
                                                                !r.enabled
                                                            }
                                                            onChange={(e) =>
                                                                setRule(k, {
                                                                    repeat_every_minutes:
                                                                        Number(
                                                                            e
                                                                                .target
                                                                                .value ||
                                                                                0,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                    <div className="space-y-1">
                                                        <Label className="text-xs">
                                                            Max reminders
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            min={0}
                                                            value={
                                                                r.max_reminders
                                                            }
                                                            disabled={
                                                                !r.enabled
                                                            }
                                                            onChange={(e) =>
                                                                setRule(k, {
                                                                    max_reminders:
                                                                        Number(
                                                                            e
                                                                                .target
                                                                                .value ||
                                                                                0,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                </div>

                                                <div className="space-y-2">
                                                    <div className="text-xs font-semibold text-muted-foreground">
                                                        Escalate to additional
                                                        role groups (optional)
                                                    </div>
                                                    <div className="flex flex-wrap gap-4">
                                                        {Object.entries(
                                                            availableRoleGroups,
                                                        ).map(
                                                            ([
                                                                gKey,
                                                                gLabel,
                                                            ]) => (
                                                                <div
                                                                    key={gKey}
                                                                    className="flex items-center gap-2"
                                                                >
                                                                    <Checkbox
                                                                        id={`${k}-group-${gKey}`}
                                                                        disabled={
                                                                            !r.enabled
                                                                        }
                                                                        checked={(
                                                                            r.escalate_to_role_groups ||
                                                                            []
                                                                        ).includes(
                                                                            gKey,
                                                                        )}
                                                                        onCheckedChange={(
                                                                            v,
                                                                        ) =>
                                                                            toggleGroup(
                                                                                k,
                                                                                gKey,
                                                                                !!v,
                                                                            )
                                                                        }
                                                                    />
                                                                    <Label
                                                                        htmlFor={`${k}-group-${gKey}`}
                                                                        className="text-sm"
                                                                    >
                                                                        {gLabel}
                                                                    </Label>
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>

                                                <div className="space-y-1">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <div className="text-xs font-semibold text-muted-foreground">
                                                            Escalation tiers
                                                            (optional)
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {
                                                                '[{"from_reminder":3,"role_groups":["managers"]}]'
                                                            }
                                                        </div>
                                                    </div>

                                                    <textarea
                                                        className="min-h-[110px] w-full rounded-md border bg-background p-2 font-mono text-xs"
                                                        disabled={!r.enabled}
                                                        value={JSON.stringify(
                                                            r.tiers || [],
                                                            null,
                                                            2,
                                                        )}
                                                        onChange={(e) => {
                                                            try {
                                                                const parsed =
                                                                    JSON.parse(
                                                                        e.target
                                                                            .value ||
                                                                            '[]',
                                                                    );
                                                                setTierErrors(
                                                                    (prev) => ({
                                                                        ...prev,
                                                                        [k]: '',
                                                                    }),
                                                                );
                                                                setRule(k, {
                                                                    tiers: Array.isArray(
                                                                        parsed,
                                                                    )
                                                                        ? parsed
                                                                        : [],
                                                                });
                                                            } catch {
                                                                setTierErrors(
                                                                    (prev) => ({
                                                                        ...prev,
                                                                        [k]: 'Invalid JSON',
                                                                    }),
                                                                );
                                                            }
                                                        }}
                                                    />

                                                    {tierErrors[k] ? (
                                                        <div className="text-xs text-destructive">
                                                            {tierErrors[k]}
                                                        </div>
                                                    ) : (
                                                        <div className="text-xs text-muted-foreground">
                                                            Tiers widen
                                                            recipients as
                                                            reminders increase.
                                                            Each tier:
                                                            from_reminder (1..N)
                                                            and role_groups.
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}

                                    {form.errors?.rules && (
                                        <InputError
                                            message={form.errors.rules as any}
                                        />
                                    )}
                                </CardContent>
                            </Card>
                        ))}

                        <div className="flex items-center justify-end gap-2">
                            <Button type="submit" disabled={form.processing}>
                                Save escalation rules
                            </Button>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
