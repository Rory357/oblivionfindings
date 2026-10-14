import { JourneySteps } from '@/components/auth/journey-steps';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import AuthJourneyLayout, {
    type AuthAudience,
} from '@/layouts/auth/auth-journey-layout';
import { store } from '@/routes/two-factor/login';
import { Form } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';

/** Two-step verification after the password (staff: then My Day; family: their page). */
export default function TwoFactorChallenge({
    audience = 'staff',
}: {
    audience?: AuthAudience;
}) {
    const [showRecoveryInput, setShowRecoveryInput] = useState(false);
    const [code, setCode] = useState('');
    const family = audience === 'family';

    return (
        <AuthJourneyLayout audience={audience} title="Two-step verification">
            <JourneySteps
                steps={[
                    'Password',
                    'Code',
                    family ? 'Family portal' : 'My Day',
                ]}
                current={1}
                label="Step 2 of 3: enter your code"
            />
            <div className="flex flex-col gap-1.5">
                <h1 className="journey-title">
                    {showRecoveryInput
                        ? 'Enter a recovery code'
                        : 'Enter your 6-digit code'}
                </h1>
                <p className="journey-subtitle">
                    {showRecoveryInput
                        ? 'Use one of the recovery codes you saved when you turned on two-step verification.'
                        : 'Open your authenticator app and enter the code it shows for oblivion care.'}
                </p>
            </div>

            <Form
                action={store()}
                className="flex flex-col gap-4"
                resetOnError
                resetOnSuccess={!showRecoveryInput}
            >
                {({ errors, processing, clearErrors }) => (
                    <>
                        {showRecoveryInput ? (
                            <div className="flex flex-col gap-2">
                                <Input
                                    name="recovery_code"
                                    type="text"
                                    aria-label="Recovery code"
                                    autoComplete="one-time-code"
                                    autoFocus
                                    required
                                    className="journey-control"
                                />
                                <InputError message={errors.recovery_code} />
                            </div>
                        ) : (
                            <div className="flex flex-col gap-2">
                                <InputOTP
                                    name="code"
                                    maxLength={OTP_MAX_LENGTH}
                                    value={code}
                                    onChange={setCode}
                                    disabled={processing}
                                    pattern={REGEXP_ONLY_DIGITS}
                                    autoFocus
                                    aria-label="6-digit code"
                                    containerClassName="w-full"
                                >
                                    <InputOTPGroup className="grid w-full grid-cols-6 gap-2">
                                        {Array.from(
                                            { length: OTP_MAX_LENGTH },
                                            (_, index) => (
                                                <InputOTPSlot
                                                    key={index}
                                                    index={index}
                                                    className="h-[50px] w-full rounded-[10px] border bg-journey-field text-xl font-semibold first:rounded-[10px] last:rounded-[10px]"
                                                />
                                            ),
                                        )}
                                    </InputOTPGroup>
                                </InputOTP>
                                <InputError message={errors.code} />
                            </div>
                        )}

                        <Button
                            type="submit"
                            className="journey-control w-full"
                            disabled={processing}
                        >
                            Verify
                        </Button>

                        <Button
                            type="button"
                            variant="link"
                            className="self-center"
                            onClick={() => {
                                setShowRecoveryInput((value) => !value);
                                clearErrors();
                                setCode('');
                            }}
                        >
                            {showRecoveryInput
                                ? 'Use your 6-digit code instead'
                                : 'Use a recovery code instead'}
                        </Button>
                    </>
                )}
            </Form>
        </AuthJourneyLayout>
    );
}
