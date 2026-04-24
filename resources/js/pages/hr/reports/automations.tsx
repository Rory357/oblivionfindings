import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Option {
    value: string;
    label: string;
}

interface RecipientOption {
    id: number;
    name: string;
    email: string;
}

interface RuleRow {
    id: number;
    name: string;
    event_type: string;
    conditions: Record<string, unknown>;
    actions: Array<Record<string, unknown>>;
    is_active: boolean;
    stop_on_match: boolean;
    last_ran_at: string | null;
    last_status: string | null;
    last_error: string | null;
    runs_count: number;
    failed_runs_count: number;
}

interface RunRow {
    id: number;
    rule_id: number;
    rule_name: string | null;
    event_type: string;
    status: string;
    message: string | null;
    executed_at: string | null;
}

interface Props {
    rules: RuleRow[];
    recentRuns: RunRow[];
    eventOptions: Option[];
    actionOptions: Option[];
    roleGroupOptions: Option[];
    conditionOperatorOptions: Option[];
    conditionLogicOptions: Option[];
    reportTypeOptions: Option[];
    recipientOptions: RecipientOption[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Reports', href: '/hr/reports' },
    { title: 'Automations', href: '/hr/reports/automations' },
];

const statusClass: Record<string, string> = {
    success: 'border-status-success/30 text-status-success bg-status-success',
    failed: 'border-status-critical/30 text-status-critical bg-status-critical',
    skipped: 'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
};

export default function HrAutomationsPage({
    rules,
    recentRuns,
    eventOptions,
    actionOptions,
    roleGroupOptions,
    conditionOperatorOptions,
    conditionLogicOptions,
    reportTypeOptions,
    recipientOptions,
    can,
}: Props) {
    const [editingRuleId, setEditingRuleId] = useState<number | null>(null);
    const [advancedMode, setAdvancedMode] = useState(false);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        event_type: eventOptions[0]?.value ?? '',
        condition_logic: conditionLogicOptions[0]?.value ?? 'all',
        condition_field: '',
        condition_value: '',
        condition_rules_json: '',
        action_type: actionOptions[0]?.value ?? 'notify_role_group',
        action_title: '',
        action_body: '',
        action_url: '',
        action_webhook_url: '',
        action_webhook_timeout_seconds: '10',
        action_include_payload: false,
        actions_json: '',
        role_group: roleGroupOptions[0]?.value ?? 'managers',
        recipient_user_ids: [] as number[],
        report_type: reportTypeOptions[0]?.value ?? 'headcount',
        report_date_from: '',
        report_date_to: '',
        is_active: true,
        stop_on_match: false,
    });

    const hydrateFormFromRule = (rule: RuleRow) => {
        const equalsConditions =
            (rule.conditions as { equals?: Record<string, string> })?.equals ??
            {};
        const conditionEntries = Object.entries(equalsConditions);
        const [conditionField, conditionValue] = conditionEntries[0] ?? [
            '',
            '',
        ];

        const action = rule.actions[0] as Record<string, unknown> | undefined;
        const actionType = String(
            action?.type ?? actionOptions[0]?.value ?? 'notify_role_group',
        );
        const recipientIdsRaw =
            actionType === 'queue_report_export'
                ? (action?.recipient_user_ids as unknown[] | undefined)
                : (action?.user_ids as unknown[] | undefined);
        const recipientIds = (recipientIdsRaw ?? [])
            .filter((id): id is number => Number.isInteger(id))
            .map((id) => Number(id));
        const reportFilters =
            (action?.filters as Record<string, string> | undefined) ?? {};

        const hasAdvancedConditions =
            Array.isArray((rule.conditions as { rules?: unknown[] }).rules) &&
            ((rule.conditions as { rules?: unknown[] }).rules ?? []).length > 0;
        const hasAdvancedActions = rule.actions.length > 1;
        setAdvancedMode(hasAdvancedConditions || hasAdvancedActions);

        setData({
            name: rule.name,
            event_type: rule.event_type,
            condition_logic: String(
                (rule.conditions as { logic?: string }).logic ??
                    conditionLogicOptions[0]?.value ??
                    'all',
            ),
            condition_field: String(conditionField ?? ''),
            condition_value: String(conditionValue ?? ''),
            condition_rules_json: hasAdvancedConditions
                ? JSON.stringify(
                      (rule.conditions as { rules?: unknown[] }).rules ?? [],
                      null,
                      2,
                  )
                : '',
            action_type: actionType,
            action_title: String(action?.title ?? ''),
            action_body: String(action?.body ?? ''),
            action_url: String(action?.url ?? ''),
            action_webhook_url: String(action?.webhook_url ?? ''),
            action_webhook_timeout_seconds: String(
                action?.timeout_seconds ?? '10',
            ),
            action_include_payload: Boolean(action?.include_payload ?? false),
            actions_json: hasAdvancedActions
                ? JSON.stringify(rule.actions, null, 2)
                : '',
            role_group: String(
                action?.role_group ?? roleGroupOptions[0]?.value ?? 'managers',
            ),
            recipient_user_ids: recipientIds,
            report_type: String(
                action?.report_type ??
                    reportTypeOptions[0]?.value ??
                    'headcount',
            ),
            report_date_from: String(reportFilters.date_from ?? ''),
            report_date_to: String(reportFilters.date_to ?? ''),
            is_active: rule.is_active,
            stop_on_match: rule.stop_on_match,
        });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingRuleId) {
            put(`/hr/reports/automations/${editingRuleId}`, {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingRuleId(null);
                    reset();
                    setAdvancedMode(false);
                    setData('event_type', eventOptions[0]?.value ?? '');
                    setData(
                        'condition_logic',
                        conditionLogicOptions[0]?.value ?? 'all',
                    );
                    setData(
                        'action_type',
                        actionOptions[0]?.value ?? 'notify_role_group',
                    );
                    setData(
                        'role_group',
                        roleGroupOptions[0]?.value ?? 'managers',
                    );
                    setData(
                        'report_type',
                        reportTypeOptions[0]?.value ?? 'headcount',
                    );
                    setData('condition_rules_json', '');
                    setData('action_webhook_url', '');
                    setData('action_webhook_timeout_seconds', '10');
                    setData('action_include_payload', false);
                    setData('actions_json', '');
                    setData('is_active', true);
                    setData('stop_on_match', false);
                },
            });
            return;
        }

        post('/hr/reports/automations', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setAdvancedMode(false);
                setData('event_type', eventOptions[0]?.value ?? '');
                setData(
                    'condition_logic',
                    conditionLogicOptions[0]?.value ?? 'all',
                );
                setData(
                    'action_type',
                    actionOptions[0]?.value ?? 'notify_role_group',
                );
                setData('role_group', roleGroupOptions[0]?.value ?? 'managers');
                setData(
                    'report_type',
                    reportTypeOptions[0]?.value ?? 'headcount',
                );
                setData('condition_rules_json', '');
                setData('action_webhook_url', '');
                setData('action_webhook_timeout_seconds', '10');
                setData('action_include_payload', false);
                setData('actions_json', '');
                setData('is_active', true);
                setData('stop_on_match', false);
            },
        });
    };

    const toggleRule = (id: number) => {
        router.post(
            `/hr/reports/automations/${id}/toggle-active`,
            {},
            { preserveScroll: true },
        );
    };

    const updateRecipients = (value: string) => {
        const id = Number(value);
        if (!Number.isFinite(id) || id <= 0) return;
        if (data.recipient_user_ids.includes(id)) return;

        setData('recipient_user_ids', [...data.recipient_user_ids, id]);
    };

    const removeRecipient = (id: number) => {
        setData(
            'recipient_user_ids',
            data.recipient_user_ids.filter((userId) => userId !== id),
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR Automations" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">HR Automations</h1>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/hr/reports/webhooks">Webhooks</Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/hr/reports">Back to Reports</Link>
                        </Button>
                    </div>
                </div>

                {can.manage && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">
                                    {editingRuleId
                                        ? 'Edit Automation Rule'
                                        : 'Create Automation Rule'}
                                </CardTitle>
                                {editingRuleId && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setEditingRuleId(null);
                                            reset();
                                            setAdvancedMode(false);
                                            setData(
                                                'event_type',
                                                eventOptions[0]?.value ?? '',
                                            );
                                            setData(
                                                'condition_logic',
                                                conditionLogicOptions[0]
                                                    ?.value ?? 'all',
                                            );
                                            setData(
                                                'action_type',
                                                actionOptions[0]?.value ??
                                                    'notify_role_group',
                                            );
                                            setData(
                                                'role_group',
                                                roleGroupOptions[0]?.value ??
                                                    'managers',
                                            );
                                            setData(
                                                'report_type',
                                                reportTypeOptions[0]?.value ??
                                                    'headcount',
                                            );
                                            setData('condition_rules_json', '');
                                            setData('action_webhook_url', '');
                                            setData(
                                                'action_webhook_timeout_seconds',
                                                '10',
                                            );
                                            setData(
                                                'action_include_payload',
                                                false,
                                            );
                                            setData('actions_json', '');
                                            setData('is_active', true);
                                            setData('stop_on_match', false);
                                        }}
                                    >
                                        Cancel Edit
                                    </Button>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="grid gap-4 md:grid-cols-2"
                                onSubmit={submit}
                            >
                                <div className="space-y-2">
                                    <Label>Name</Label>
                                    <Input
                                        value={data.name}
                                        onChange={(e) =>
                                            setData('name', e.target.value)
                                        }
                                        placeholder="Escalate annual leave alerts"
                                    />
                                    {errors.name && (
                                        <p className="text-xs text-status-critical">
                                            {errors.name}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label>Event</Label>
                                    <Select
                                        value={data.event_type}
                                        onValueChange={(value) =>
                                            setData('event_type', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {eventOptions.map((eventOption) => (
                                                <SelectItem
                                                    key={eventOption.value}
                                                    value={eventOption.value}
                                                >
                                                    {eventOption.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label>Condition Field (optional)</Label>
                                    <Input
                                        value={data.condition_field}
                                        onChange={(e) =>
                                            setData(
                                                'condition_field',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="leave_type"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label>Condition Value (optional)</Label>
                                    <Input
                                        value={data.condition_value}
                                        onChange={(e) =>
                                            setData(
                                                'condition_value',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="annual"
                                    />
                                </div>

                                <div className="flex items-center gap-2 rounded-md border p-3 md:col-span-2">
                                    <Checkbox
                                        checked={advancedMode}
                                        onCheckedChange={(checked) =>
                                            setAdvancedMode(Boolean(checked))
                                        }
                                    />
                                    <div>
                                        <p className="text-sm font-medium">
                                            Use advanced workflow builder (JSON)
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Enables multi-condition logic and
                                            multiple actions per rule.
                                        </p>
                                    </div>
                                </div>

                                {advancedMode && (
                                    <>
                                        <div className="space-y-2">
                                            <Label>Condition Logic</Label>
                                            <Select
                                                value={data.condition_logic}
                                                onValueChange={(value) =>
                                                    setData(
                                                        'condition_logic',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {conditionLogicOptions.map(
                                                        (logicOption) => (
                                                            <SelectItem
                                                                key={
                                                                    logicOption.value
                                                                }
                                                                value={
                                                                    logicOption.value
                                                                }
                                                            >
                                                                {
                                                                    logicOption.label
                                                                }
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>Condition Rules JSON</Label>
                                            <Textarea
                                                value={
                                                    data.condition_rules_json
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        'condition_rules_json',
                                                        e.target.value,
                                                    )
                                                }
                                                rows={6}
                                                placeholder={`[\n  {"field":"status","operator":"equals","value":"approved"},\n  {"field":"leave_type","operator":"in","value":["annual","sick"]}\n]`}
                                            />
                                            {errors.condition_rules_json && (
                                                <p className="text-xs text-status-critical">
                                                    {
                                                        errors.condition_rules_json
                                                    }
                                                </p>
                                            )}
                                            <p className="text-xs text-muted-foreground">
                                                Supported operators:{' '}
                                                {conditionOperatorOptions
                                                    .map((item) => item.value)
                                                    .join(', ')}
                                                .
                                            </p>
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>
                                                Actions JSON (optional override)
                                            </Label>
                                            <Textarea
                                                value={data.actions_json}
                                                onChange={(e) =>
                                                    setData(
                                                        'actions_json',
                                                        e.target.value,
                                                    )
                                                }
                                                rows={8}
                                                placeholder={`[\n  {"type":"notify_users","title":"Leave approved","recipient_user_ids":[1,2]},\n  {"type":"queue_report_export","report_type":"leave_sla","report_date_from":"2026-01-01","report_date_to":"2026-12-31"}\n]`}
                                            />
                                            {errors.actions_json && (
                                                <p className="text-xs text-status-critical">
                                                    {errors.actions_json}
                                                </p>
                                            )}
                                            <p className="text-xs text-muted-foreground">
                                                Leave empty to use the single
                                                action fields below.
                                            </p>
                                        </div>
                                    </>
                                )}

                                <div className="space-y-2">
                                    <Label>Action</Label>
                                    <Select
                                        value={data.action_type}
                                        onValueChange={(value) =>
                                            setData('action_type', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {actionOptions.map(
                                                (actionOption) => (
                                                    <SelectItem
                                                        key={actionOption.value}
                                                        value={
                                                            actionOption.value
                                                        }
                                                    >
                                                        {actionOption.label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label>Action Title</Label>
                                    <Input
                                        value={data.action_title}
                                        onChange={(e) =>
                                            setData(
                                                'action_title',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Leave request approved"
                                    />
                                </div>

                                <div className="space-y-2 md:col-span-2">
                                    <Label>Action Body</Label>
                                    <Input
                                        value={data.action_body}
                                        onChange={(e) =>
                                            setData(
                                                'action_body',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Automation rule fired for leave approval."
                                    />
                                </div>

                                <div className="space-y-2 md:col-span-2">
                                    <Label>Action URL (optional)</Label>
                                    <Input
                                        value={data.action_url}
                                        onChange={(e) =>
                                            setData(
                                                'action_url',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="/hr/leave"
                                    />
                                </div>

                                {data.action_type === 'notify_role_group' && (
                                    <div className="space-y-2">
                                        <Label>Role Group</Label>
                                        <Select
                                            value={data.role_group}
                                            onValueChange={(value) =>
                                                setData('role_group', value)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {roleGroupOptions.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}

                                {(data.action_type === 'notify_users' ||
                                    data.action_type ===
                                        'queue_report_export') && (
                                    <div className="space-y-2 md:col-span-2">
                                        <Label>Recipients</Label>
                                        <Select
                                            value="__pick__"
                                            onValueChange={updateRecipients}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Add recipient" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__pick__">
                                                    Pick recipient
                                                </SelectItem>
                                                {recipientOptions.map(
                                                    (recipient) => (
                                                        <SelectItem
                                                            key={recipient.id}
                                                            value={String(
                                                                recipient.id,
                                                            )}
                                                        >
                                                            {recipient.name} (
                                                            {recipient.email})
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        {data.recipient_user_ids.length > 0 && (
                                            <div className="flex flex-wrap gap-2 pt-1">
                                                {data.recipient_user_ids.map(
                                                    (id) => {
                                                        const recipient =
                                                            recipientOptions.find(
                                                                (option) =>
                                                                    option.id ===
                                                                    id,
                                                            );
                                                        return (
                                                            <Badge
                                                                key={id}
                                                                variant="outline"
                                                                className="cursor-pointer"
                                                                onClick={() =>
                                                                    removeRecipient(
                                                                        id,
                                                                    )
                                                                }
                                                            >
                                                                {recipient?.name ??
                                                                    `User ${id}`}{' '}
                                                                x
                                                            </Badge>
                                                        );
                                                    },
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                                {data.action_type === 'queue_report_export' && (
                                    <>
                                        <div className="space-y-2">
                                            <Label>Report Type</Label>
                                            <Select
                                                value={data.report_type}
                                                onValueChange={(value) =>
                                                    setData(
                                                        'report_type',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {reportTypeOptions.map(
                                                        (option) => (
                                                            <SelectItem
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Report Date From</Label>
                                            <Input
                                                type="date"
                                                value={data.report_date_from}
                                                onChange={(e) =>
                                                    setData(
                                                        'report_date_from',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Report Date To</Label>
                                            <Input
                                                type="date"
                                                value={data.report_date_to}
                                                onChange={(e) =>
                                                    setData(
                                                        'report_date_to',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </>
                                )}

                                {data.action_type ===
                                    'notify_microsoft_teams' && (
                                    <>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>
                                                Microsoft Teams Webhook URL
                                            </Label>
                                            <Input
                                                type="url"
                                                value={data.action_webhook_url}
                                                onChange={(e) =>
                                                    setData(
                                                        'action_webhook_url',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="https://*.webhook.office.com/webhookb2/..."
                                            />
                                            {errors.action_webhook_url && (
                                                <p className="text-xs text-status-critical">
                                                    {errors.action_webhook_url}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>
                                                Webhook Timeout (seconds)
                                            </Label>
                                            <Input
                                                type="number"
                                                min={2}
                                                max={30}
                                                value={
                                                    data.action_webhook_timeout_seconds
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        'action_webhook_timeout_seconds',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <label className="flex items-center gap-2 pt-7 text-sm">
                                            <Checkbox
                                                checked={
                                                    data.action_include_payload
                                                }
                                                onCheckedChange={(checked) =>
                                                    setData(
                                                        'action_include_payload',
                                                        Boolean(checked),
                                                    )
                                                }
                                            />
                                            <span>
                                                Include event payload in Teams
                                                message
                                            </span>
                                        </label>
                                    </>
                                )}

                                <div className="flex flex-wrap items-center gap-6 rounded-md border p-3 md:col-span-2">
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={data.is_active}
                                            onCheckedChange={(checked) =>
                                                setData(
                                                    'is_active',
                                                    Boolean(checked),
                                                )
                                            }
                                        />
                                        <span>Active immediately</span>
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={data.stop_on_match}
                                            onCheckedChange={(checked) =>
                                                setData(
                                                    'stop_on_match',
                                                    Boolean(checked),
                                                )
                                            }
                                        />
                                        <span>
                                            Stop processing after this rule
                                            matches
                                        </span>
                                    </label>
                                </div>

                                <div className="flex justify-end md:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {editingRuleId
                                            ? 'Update Rule'
                                            : 'Create Rule'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Automation Rules
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Rule
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Event
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Last Run
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Stats
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rules.map((rule) => (
                                    <tr
                                        key={rule.id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {rule.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {JSON.stringify(
                                                    rule.conditions,
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {JSON.stringify(rule.actions)}
                                            </div>
                                            {rule.last_error && (
                                                <div className="mt-1 text-xs text-status-critical">
                                                    {rule.last_error}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {rule.event_type}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {rule.last_ran_at || '-'}
                                            {rule.last_status && (
                                                <Badge
                                                    variant="outline"
                                                    className={`ml-2 ${statusClass[rule.last_status] || statusClass.skipped}`}
                                                >
                                                    {rule.last_status}
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {rule.runs_count} runs,{' '}
                                            {rule.failed_runs_count} failed
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {can.manage && (
                                                <div className="flex items-center justify-end gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => {
                                                            setEditingRuleId(
                                                                rule.id,
                                                            );
                                                            hydrateFormFromRule(
                                                                rule,
                                                            );
                                                        }}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            toggleRule(rule.id)
                                                        }
                                                    >
                                                        {rule.is_active
                                                            ? 'Pause'
                                                            : 'Resume'}
                                                    </Button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {rules.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No automation rules configured.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Recent Automation Runs
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Rule
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Event
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Message
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Executed
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {recentRuns.map((run) => (
                                    <tr
                                        key={run.id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {run.rule_name ||
                                                `Rule #${run.rule_id}`}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {run.event_type}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant="outline"
                                                className={
                                                    statusClass[run.status] ||
                                                    statusClass.skipped
                                                }
                                            >
                                                {run.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {run.message || '-'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {run.executed_at || '-'}
                                        </td>
                                    </tr>
                                ))}
                                {recentRuns.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No automation runs recorded yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
