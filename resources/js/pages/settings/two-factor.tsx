import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { disable, enable, show } from '@/routes/two-factor';
import { type BreadcrumbItem } from '@/types';
import { Form, Head } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Shield,
    ShieldBan,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

interface TwoFactorProps {
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Two-Factor Authentication',
        href: show.url(),
    },
];

export default function TwoFactor({
    requiresConfirmation = false,
    twoFactorEnabled = false,
}: TwoFactorProps) {
    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Two-Factor Authentication" />
            <SettingsLayout>
                {/* Status banner */}
                {twoFactorEnabled ? (
                    <div className="flex items-center gap-3 rounded-lg border border-status-success/30 bg-status-success-bg px-4 py-3 dark:border-status-success/50 dark:bg-status-success">
                        <CheckCircle2 className="h-5 w-5 shrink-0 text-status-success" />
                        <p className="text-sm font-medium text-status-success dark:text-status-success">
                            Two-factor authentication is enabled
                        </p>
                    </div>
                ) : (
                    <div className="flex items-center gap-3 rounded-lg border border-status-warning/30 bg-status-warning-bg px-4 py-3 dark:border-status-warning/50 dark:bg-status-warning">
                        <AlertCircle className="h-5 w-5 shrink-0 text-status-warning" />
                        <p className="text-sm font-medium text-status-warning dark:text-status-warning">
                            Two-factor authentication is not enabled
                        </p>
                    </div>
                )}

                {/* Main 2FA Card */}
                <Card>
                    <CardHeader>
                        <CardTitle>Two-Factor Authentication</CardTitle>
                        <CardDescription>
                            Add an extra layer of security to your account
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {twoFactorEnabled ? (
                            <div className="space-y-6">
                                <p className="text-sm text-muted-foreground">
                                    With two-factor authentication enabled, you
                                    will be prompted for a secure, random pin
                                    during login, which you can retrieve from
                                    the TOTP-supported application on your
                                    phone.
                                </p>

                                {/* QR Code display */}
                                {qrCodeSvg && (
                                    <div className="flex justify-center">
                                        {/* eslint-disable-next-line no-restricted-syntax -- QR SVG needs a tight white scan surface inside the settings Card. */}
                                        <div
                                            className="rounded-lg border bg-white p-4"
                                            dangerouslySetInnerHTML={{
                                                __html: qrCodeSvg,
                                            }}
                                        />
                                    </div>
                                )}

                                {/* Recovery codes */}
                                <TwoFactorRecoveryCodes
                                    recoveryCodesList={recoveryCodesList}
                                    fetchRecoveryCodes={fetchRecoveryCodes}
                                    errors={errors}
                                />

                                {/* Disable button */}
                                <div className="pt-2">
                                    <Form {...disable.form()}>
                                        {({ processing }) => (
                                            <Button
                                                variant="destructive"
                                                type="submit"
                                                disabled={processing}
                                            >
                                                <ShieldBan className="mr-1.5 h-4 w-4" />
                                                Disable 2FA
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            </div>
                        ) : (
                            <div className="flex flex-col items-center py-8 text-center">
                                <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 dark:bg-primary/50">
                                    <Shield className="h-8 w-8 text-primary" />
                                </div>
                                <h3 className="mb-2 text-lg font-medium">
                                    Protect your account
                                </h3>
                                <p className="mb-6 max-w-sm text-sm text-muted-foreground">
                                    When you enable two-factor authentication,
                                    you will be prompted for a secure pin during
                                    login. This pin can be retrieved from a
                                    TOTP-supported application on your phone.
                                </p>

                                {hasSetupData ? (
                                    <Button
                                        className="bg-primary hover:bg-primary"
                                        onClick={() => setShowSetupModal(true)}
                                    >
                                        <ShieldCheck className="mr-1.5 h-4 w-4" />
                                        Continue Setup
                                    </Button>
                                ) : (
                                    <Form
                                        {...enable.form()}
                                        onSuccess={() =>
                                            setShowSetupModal(true)
                                        }
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="bg-primary hover:bg-primary"
                                            >
                                                <ShieldCheck className="mr-1.5 h-4 w-4" />
                                                Enable 2FA
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <TwoFactorSetupModal
                    isOpen={showSetupModal}
                    onClose={() => setShowSetupModal(false)}
                    requiresConfirmation={requiresConfirmation}
                    twoFactorEnabled={twoFactorEnabled}
                    qrCodeSvg={qrCodeSvg}
                    manualSetupKey={manualSetupKey}
                    clearSetupData={clearSetupData}
                    fetchSetupData={fetchSetupData}
                    errors={errors}
                />
            </SettingsLayout>
        </AppLayout>
    );
}
