import { Badge } from '@/components/ui/badge';
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
import { formatDateTimeLong } from '@/lib/datetime';
import { FoodMealPreferences } from '@/pages/operations/clients/_food-meal-preferences';
import { router } from '@inertiajs/react';
import { CheckCircle2, Plus, Utensils } from 'lucide-react';
import { useMemo, useState } from 'react';

type MealLog = {
    id: number;
    meal_type: string;
    status: string;
    occurred_at?: string | null;
    portion_note?: string | null;
    notes?: string | null;
    recorded_by?: { id: number; name: string } | null;
};

type MealLogPayload = {
    today?: MealLog[];
    history?: MealLog[];
    summary?: {
        eaten?: number;
        expected?: number;
        status?: string;
    };
};

type FoodMealTabProps = {
    clientId: number;
    canEdit: boolean;
    mealLogs?: MealLogPayload | null;
    onAddPreference: () => void;
};

const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack'];
const MEAL_STATUS = ['eaten', 'partial', 'refused', 'declined'];

function localDateTimeValue(): string {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    return now.toISOString().slice(0, 16);
}

function label(value: string): string {
    return value.replace(/_/g, ' ');
}

export function FoodMealTab({
    clientId,
    canEdit,
    mealLogs,
    onAddPreference,
}: FoodMealTabProps) {
    const [log, setLog] = useState({
        meal_type: 'breakfast',
        status: 'eaten',
        occurred_at: localDateTimeValue(),
        portion_note: '',
        notes: '',
    });

    const today = useMemo(() => mealLogs?.today ?? [], [mealLogs?.today]);
    const history = useMemo(() => mealLogs?.history ?? [], [mealLogs?.history]);
    const summary = mealLogs?.summary ?? {
        eaten: 0,
        expected: 3,
        status: 'not_started',
    };
    const mealsByType = useMemo(
        () => new Map(today.map((entry) => [entry.meal_type, entry])),
        [today],
    );

    const submitMeal = () => {
        router.post(
            `/operations/clients/${clientId}/meal-logs`,
            {
                meal_type: log.meal_type,
                status: log.status,
                occurred_at: log.occurred_at,
                portion_note: log.portion_note || null,
                notes: log.notes || null,
            },
            {
                preserveScroll: true,
                preserveState: false,
                onSuccess: () =>
                    setLog({
                        meal_type: 'breakfast',
                        status: 'eaten',
                        occurred_at: localDateTimeValue(),
                        portion_note: '',
                        notes: '',
                    }),
            },
        );
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-primary">
                        <Utensils className="h-[19px] w-[19px]" />
                    </span>
                    <div>
                        <h2 className="text-lg leading-tight font-semibold">
                            Food & meal
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Intake logging, texture safety, likes & mealtime
                            support
                        </p>
                    </div>
                </div>
                {canEdit ? (
                    <Button onClick={onAddPreference}>
                        <Plus className="mr-1.5 h-4 w-4" />
                        Add preference
                    </Button>
                ) : null}
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <CheckCircle2 className="h-4 w-4 text-status-success" />
                            Today
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-4">
                            {MEAL_TYPES.map((mealType) => {
                                const entry = mealsByType.get(mealType);
                                return (
                                    // eslint-disable-next-line no-restricted-syntax -- Meal status tile follows the compact profile stat pattern.
                                    <div
                                        key={mealType}
                                        className="rounded-lg border bg-card p-3"
                                    >
                                        <p className="text-xs font-medium text-muted-foreground capitalize">
                                            {label(mealType)}
                                        </p>
                                        <p className="mt-1 text-sm font-semibold capitalize">
                                            {entry
                                                ? label(entry.status)
                                                : 'Not logged'}
                                        </p>
                                        {entry?.occurred_at ? (
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {formatDateTimeLong(
                                                    entry.occurred_at,
                                                )}
                                            </p>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>

                        <div className="rounded-lg border bg-muted/30 p-3">
                            <p className="text-sm font-semibold">
                                Meals {summary.eaten ?? 0}/
                                {summary.expected ?? 3}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {summary.status === 'on_track'
                                    ? 'On track today.'
                                    : 'Log meals as they happen so handover has the current picture.'}
                            </p>
                        </div>

                        <div className="space-y-2">
                            <p className="text-sm font-medium">7-day history</p>
                            {history.length > 0 ? (
                                <div className="space-y-2">
                                    {history.slice(0, 12).map((entry) => (
                                        <div
                                            key={entry.id}
                                            className="flex items-start justify-between gap-3 rounded-md border p-3"
                                        >
                                            <div>
                                                <p className="text-sm font-medium capitalize">
                                                    {label(entry.meal_type)}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDateTimeLong(
                                                        entry.occurred_at,
                                                    )}
                                                </p>
                                                {entry.notes ? (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {entry.notes}
                                                    </p>
                                                ) : null}
                                            </div>
                                            <Badge
                                                variant="outline"
                                                className="capitalize"
                                            >
                                                {label(entry.status)}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                                    No meal logs in the last 7 days.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Plus className="h-4 w-4 text-primary" />
                            Log meal
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                            <div className="space-y-2">
                                <Label>Meal</Label>
                                <Select
                                    value={log.meal_type}
                                    onValueChange={(value) =>
                                        setLog((current) => ({
                                            ...current,
                                            meal_type: value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="min-h-11">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {MEAL_TYPES.map((mealType) => (
                                            <SelectItem
                                                key={mealType}
                                                value={mealType}
                                            >
                                                {label(mealType)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label>Status</Label>
                                <Select
                                    value={log.status}
                                    onValueChange={(value) =>
                                        setLog((current) => ({
                                            ...current,
                                            status: value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="min-h-11">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {MEAL_STATUS.map((status) => (
                                            <SelectItem
                                                key={status}
                                                value={status}
                                            >
                                                {label(status)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label>When</Label>
                            <Input
                                type="datetime-local"
                                value={log.occurred_at}
                                onChange={(event) =>
                                    setLog((current) => ({
                                        ...current,
                                        occurred_at: event.target.value,
                                    }))
                                }
                                className="min-h-11"
                            />
                        </div>
                        <Input
                            value={log.portion_note}
                            onChange={(event) =>
                                setLog((current) => ({
                                    ...current,
                                    portion_note: event.target.value,
                                }))
                            }
                            placeholder="Portion note"
                            className="min-h-11"
                        />
                        <Textarea
                            value={log.notes}
                            onChange={(event) =>
                                setLog((current) => ({
                                    ...current,
                                    notes: event.target.value,
                                }))
                            }
                            placeholder="Notes"
                            className="min-h-24"
                        />
                        <Button
                            type="button"
                            onClick={submitMeal}
                            disabled={!log.occurred_at}
                            className="min-h-11 w-full"
                        >
                            Save meal log
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <FoodMealPreferences clientId={clientId} canEdit={canEdit} />
        </div>
    );
}
