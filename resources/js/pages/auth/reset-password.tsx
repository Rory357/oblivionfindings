import { PasswordField } from '@/components/auth/password-field';
import {
    PasswordRules,
    type PasswordRequirements,
} from '@/components/auth/password-rules';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AuthJourneyLayout, {
    type AuthAudience,
} from '@/layouts/auth/auth-journey-layout';
import { useI18n } from '@/lib/i18n';
import { update } from '@/routes/password';
import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

interface ResetPasswordProps {
    token: string;
    email: string;
    audience?: AuthAudience;
    passwordRules?: PasswordRequirements;
}

const DEFAULT_RULES: PasswordRequirements = {
    minLength: 12,
    mixedCase: true,
    numbers: true,
    symbols: true,
};

/** Step 3 of the reset flow: choose a new password (live rule checklist). */
export default function ResetPassword({
    token,
    email,
    audience = 'staff',
    passwordRules = DEFAULT_RULES,
}: ResetPasswordProps) {
    const { t } = useI18n();
    const form = useForm({ email, password: '', password_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, token }));
        form.post(update().url, {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthJourneyLayout
            audience={audience}
            title={t('app.auth.reset.title', 'Choose a new password')}
            signing={form.processing}
        >
            <div className="flex flex-col gap-1.5">
                <h1 className="journey-title">
                    {t('app.auth.reset.title', 'Choose a new password')}
                </h1>
                <p className="journey-subtitle">
                    For{' '}
                    <span className="font-semibold text-foreground">
                        {email}
                    </span>
                </p>
            </div>
            <form onSubmit={submit} className="flex flex-col gap-4">
                <InputError message={form.errors.email} />
                <div className="flex flex-col gap-2">
                    <Label htmlFor="password" className="journey-label">
                        New password
                    </Label>
                    <PasswordField
                        id="password"
                        name="password"
                        autoComplete="new-password"
                        autoFocus
                        required
                        value={form.data.password}
                        onChange={(event) =>
                            form.setData('password', event.target.value)
                        }
                        aria-describedby="password-rules"
                        aria-invalid={
                            Boolean(form.errors.password) || undefined
                        }
                    />
                    <PasswordRules
                        id="password-rules"
                        rules={passwordRules}
                        value={form.data.password}
                    />
                    <InputError message={form.errors.password} />
                </div>
                <div className="flex flex-col gap-2">
                    <Label
                        htmlFor="password_confirmation"
                        className="journey-label"
                    >
                        Type it again
                    </Label>
                    <PasswordField
                        id="password_confirmation"
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        value={form.data.password_confirmation}
                        onChange={(event) =>
                            form.setData(
                                'password_confirmation',
                                event.target.value,
                            )
                        }
                    />
                    <InputError message={form.errors.password_confirmation} />
                </div>
                <Button
                    type="submit"
                    className="journey-control w-full"
                    disabled={form.processing}
                    data-test="reset-password-button"
                >
                    {t('app.auth.reset.submit', 'Save new password')}
                </Button>
            </form>
        </AuthJourneyLayout>
    );
}
