/* Account settings › Witness PIN (eMAR P00 v5 / PIN-1). The owner sets,
 * changes or resets their own 6-digit PIN; nobody else can see or set it.
 * Used when they co-sign, witness or confirm a colleague's dose. */
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { InfoCard } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDateLong, formatTime } from '@/lib/datetime';
import {
    sanitiseWitnessPin,
    WITNESS_PIN_LENGTH,
    type WitnessPinStatus,
} from '@/lib/witness-pin';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Lock, RefreshCw } from 'lucide-react';
import { type FormEvent, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Witness PIN', href: '/settings/witness-pin' },
];

interface Props {
    witnessPin: {
        status: WitnessPinStatus;
        setAt: string | null;
        lockedUntil: string | null;
        resetAt: string | null;
        resetBy: string | null;
    };
    rules: {
        maxAttempts: number;
        lockoutMinutes: number;
        renewalMonths: number | null;
        /** Setting a PIN while none is usable needs the login password. */
        loginCheckToSet: boolean;
    };
}

const PIN_HELP =
    '6 digits. Not your login password, and not an easy pattern like 123456, 890123 or 121212.';

function PinField({
    id,
    label,
    value,
    onChange,
    error,
    help,
    autoComplete = 'new-password',
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    help?: string;
    autoComplete?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type="password"
                inputMode="numeric"
                pattern="[0-9]*"
                maxLength={WITNESS_PIN_LENGTH}
                autoComplete={autoComplete}
                data-lpignore="true"
                data-1p-ignore="true"
                className="max-w-[12rem] tracking-[0.3em] tabular-nums"
                value={value}
                aria-invalid={error ? true : undefined}
                aria-describedby={help ? `${id}-help` : undefined}
                onChange={(event) =>
                    onChange(sanitiseWitnessPin(event.target.value))
                }
            />
            {help ? (
                <p id={`${id}-help`} className="text-caption">
                    {help}
                </p>
            ) : null}
            <InputError message={error} />
        </div>
    );
}

export default function WitnessPin({ witnessPin, rules }: Props) {
    const { status } = witnessPin;
    const [resetting, setResetting] = useState(status === 'locked');

    const needsLogin = status !== 'set' && rules.loginCheckToSet;
    const setForm = useForm({
        current_pin: '',
        current_password: '',
        pin: '',
        pin_confirmation: '',
    });
    const resetForm = useForm({
        current_password: '',
        pin: '',
        pin_confirmation: '',
    });

    function submitSet(event: FormEvent) {
        event.preventDefault();
        setForm.put('/settings/witness-pin', {
            preserveScroll: true,
            onSuccess: () => setForm.reset(),
            onError: () => setForm.reset(),
        });
    }

    function submitReset(event: FormEvent) {
        event.preventDefault();
        resetForm.post('/settings/witness-pin/reset', {
            preserveScroll: true,
            onSuccess: () => {
                resetForm.reset();
                setResetting(false);
            },
            onError: () => resetForm.reset(),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Witness PIN" />
            <SettingsLayout>
                <Card>
                    <CardHeader>
                        <CardTitle>Witness PIN</CardTitle>
                        <CardDescription>
                            Used when you co-sign, witness or confirm a
                            colleague’s dose. Separate from your password.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        {status === 'set' ? (
                            <InfoCard icon={CheckCircle2} tone="info">
                                <strong>Your witness PIN is set.</strong>
                                {witnessPin.setAt
                                    ? ` Last changed ${formatDateLong(witnessPin.setAt)}.`
                                    : null}
                            </InfoCard>
                        ) : null}
                        {status === 'not_set' ? (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                <strong>You haven’t set a witness PIN.</strong>{' '}
                                You can’t be chosen to co-sign or witness a dose
                                until you set one.
                            </InfoCard>
                        ) : null}
                        {status === 'reset' ? (
                            <InfoCard icon={RefreshCw} tone="warn">
                                <strong>
                                    Your PIN was reset
                                    {witnessPin.resetBy
                                        ? ` by ${witnessPin.resetBy}`
                                        : ''}
                                    .
                                </strong>{' '}
                                {witnessPin.resetAt
                                    ? `Reset on ${formatDateLong(witnessPin.resetAt)} at ${formatTime(witnessPin.resetAt)}. `
                                    : ''}
                                Set a new one before you can co-sign or witness.
                            </InfoCard>
                        ) : null}
                        {status === 'expired' ? (
                            <InfoCard icon={RefreshCw} tone="warn">
                                <strong>Your PIN needs renewing.</strong> Your
                                organisation asks for a new PIN every{' '}
                                {rules.renewalMonths} months. Set a new one
                                before you can co-sign or witness.
                            </InfoCard>
                        ) : null}
                        {status === 'locked' ? (
                            <InfoCard icon={Lock} tone="crit">
                                <strong>Your PIN is locked.</strong> Too many
                                wrong attempts ({rules.maxAttempts}).
                                {witnessPin.lockedUntil
                                    ? ` It unlocks at ${formatTime(witnessPin.lockedUntil)}.`
                                    : ''}{' '}
                                You can reset it now by confirming your login
                                password.
                            </InfoCard>
                        ) : null}

                        {!resetting ? (
                            <form
                                onSubmit={submitSet}
                                className="space-y-4"
                                autoComplete="off"
                            >
                                {status === 'set' ? (
                                    <div className="space-y-1">
                                        <PinField
                                            id="current_pin"
                                            label="Current PIN"
                                            value={setForm.data.current_pin}
                                            onChange={(v) =>
                                                setForm.setData(
                                                    'current_pin',
                                                    v,
                                                )
                                            }
                                            error={setForm.errors.current_pin}
                                            autoComplete="off"
                                        />
                                        <p className="text-caption">
                                            Or{' '}
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto p-0 text-xs"
                                                onClick={() =>
                                                    setResetting(true)
                                                }
                                            >
                                                confirm your login instead
                                            </Button>
                                        </p>
                                    </div>
                                ) : null}
                                {needsLogin ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="set_current_password">
                                            Your login password
                                        </Label>
                                        <Input
                                            id="set_current_password"
                                            type="password"
                                            autoComplete="current-password"
                                            className="max-w-md"
                                            value={
                                                setForm.data.current_password
                                            }
                                            onChange={(event) =>
                                                setForm.setData(
                                                    'current_password',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={
                                                setForm.errors.current_password
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby="set_current_password-help"
                                        />
                                        <p
                                            id="set_current_password-help"
                                            className="text-caption"
                                        >
                                            Confirms it’s you choosing your PIN,
                                            not someone at your screen.
                                        </p>
                                        <InputError
                                            message={
                                                setForm.errors.current_password
                                            }
                                        />
                                    </div>
                                ) : null}
                                <PinField
                                    id="pin"
                                    label="New 6-digit PIN"
                                    value={setForm.data.pin}
                                    onChange={(v) => setForm.setData('pin', v)}
                                    error={setForm.errors.pin}
                                    help={`${PIN_HELP} Don’t share it.`}
                                />
                                <PinField
                                    id="pin_confirmation"
                                    label="Enter it again"
                                    value={setForm.data.pin_confirmation}
                                    onChange={(v) =>
                                        setForm.setData('pin_confirmation', v)
                                    }
                                    error={setForm.errors.pin_confirmation}
                                />
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="submit"
                                        disabled={setForm.processing}
                                    >
                                        {status === 'set'
                                            ? 'Change PIN'
                                            : status === 'not_set'
                                              ? 'Set PIN'
                                              : 'Set new PIN'}
                                    </Button>
                                    {status === 'set' ? (
                                        <Button
                                            type="button"
                                            variant="link"
                                            className="px-0"
                                            onClick={() => setResetting(true)}
                                        >
                                            Forgot your PIN?
                                        </Button>
                                    ) : null}
                                </div>
                            </form>
                        ) : (
                            <form
                                onSubmit={submitReset}
                                className="space-y-4"
                                autoComplete="off"
                            >
                                <p className="text-sm text-muted-foreground">
                                    Confirm your login password, then choose a
                                    new PIN.
                                </p>
                                <div className="grid gap-2">
                                    <Label htmlFor="current_password">
                                        Login password
                                    </Label>
                                    <Input
                                        id="current_password"
                                        type="password"
                                        autoComplete="current-password"
                                        className="max-w-md"
                                        value={resetForm.data.current_password}
                                        onChange={(event) =>
                                            resetForm.setData(
                                                'current_password',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            resetForm.errors.current_password
                                                ? true
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        message={
                                            resetForm.errors.current_password
                                        }
                                    />
                                </div>
                                <PinField
                                    id="reset_pin"
                                    label="New 6-digit PIN"
                                    value={resetForm.data.pin}
                                    onChange={(v) =>
                                        resetForm.setData('pin', v)
                                    }
                                    error={resetForm.errors.pin}
                                    help={PIN_HELP}
                                />
                                <PinField
                                    id="reset_pin_confirmation"
                                    label="Enter it again"
                                    value={resetForm.data.pin_confirmation}
                                    onChange={(v) =>
                                        resetForm.setData('pin_confirmation', v)
                                    }
                                    error={resetForm.errors.pin_confirmation}
                                />
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="submit"
                                        disabled={resetForm.processing}
                                    >
                                        Reset my PIN
                                    </Button>
                                    {status !== 'locked' ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => {
                                                resetForm.reset();
                                                resetForm.clearErrors();
                                                setResetting(false);
                                            }}
                                        >
                                            Cancel
                                        </Button>
                                    ) : null}
                                </div>
                            </form>
                        )}
                        <p className="text-caption">
                            {rules.maxAttempts} wrong attempts lock the PIN for{' '}
                            {rules.lockoutMinutes} minutes. Nobody else can see
                            or set your PIN; a lead can reset it, and then you
                            choose a new one.
                        </p>
                    </CardContent>
                </Card>
            </SettingsLayout>
        </AppLayout>
    );
}
