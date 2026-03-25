import PageHeader from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, KeyRound, Lock, Shield, ShieldCheck, Timer } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Security', href: '/settings/security' },
];

type Props = {
    settings: Record<string, string>;
    twoFaStats?: { enabled: number; total: number };
};

function toBoolean(value: any): boolean {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'string') return value === 'true' || value === '1';
    if (typeof value === 'number') return value === 1;
    return false;
}

export default function SecuritySettings({
    settings = {},
    twoFaStats = { enabled: 0, total: 0 },
}: Props) {
    const form = useForm({
        password_min_length: settings.password_min_length ?? '8',
        password_require_uppercase: toBoolean(settings.password_require_uppercase),
        password_require_numbers: toBoolean(settings.password_require_numbers),
        password_require_symbols: toBoolean(settings.password_require_symbols),
        password_expiry_days: settings.password_expiry_days ?? '0',
        session_timeout_minutes: settings.session_timeout_minutes ?? '120',
        max_login_attempts: settings.max_login_attempts ?? '5',
        lockout_duration_minutes: settings.lockout_duration_minutes ?? '15',
        force_2fa: toBoolean(settings.force_2fa),
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.put('/settings/security');
    }

    const twoFaEnabled = twoFaStats?.enabled ?? 0;
    const twoFaTotal = twoFaStats?.total ?? 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Security Settings" />
            <SettingsLayout>
                <form onSubmit={handleSubmit} className="space-y-6">
                    <PageHeader
                        title="Security Settings"
                        description="Configure password policies, session security, and two-factor authentication for your organisation"
                    />

                    {/* Password Policy */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-violet-100 p-2">
                                    <KeyRound className="h-5 w-5 text-violet-600" />
                                </div>
                                <div>
                                    <CardTitle>Password Policy</CardTitle>
                                    <CardDescription>Set requirements for user passwords</CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="grid gap-6 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="password_min_length">Minimum Password Length</Label>
                                    <Input
                                        id="password_min_length"
                                        type="number"
                                        min={6}
                                        max={128}
                                        value={form.data.password_min_length}
                                        onChange={(e) =>
                                            form.setData('password_min_length', e.target.value)
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Minimum number of characters required (recommended: 8+)
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <Label>Password Expiry</Label>
                                    <Select
                                        value={form.data.password_expiry_days}
                                        onValueChange={(val) =>
                                            form.setData('password_expiry_days', val)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="0">Never</SelectItem>
                                            <SelectItem value="30">30 days</SelectItem>
                                            <SelectItem value="60">60 days</SelectItem>
                                            <SelectItem value="90">90 days</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        How often users must change their password
                                    </p>
                                </div>
                            </div>

                            <div className="space-y-4 rounded-lg border p-4">
                                <h4 className="text-sm font-medium">Password Complexity</h4>
                                <div className="space-y-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label htmlFor="require_uppercase">Require uppercase letters</Label>
                                            <p className="text-xs text-muted-foreground">
                                                At least one uppercase letter (A-Z)
                                            </p>
                                        </div>
                                        <Switch
                                            id="require_uppercase"
                                            checked={form.data.password_require_uppercase}
                                            onCheckedChange={(checked) =>
                                                form.setData('password_require_uppercase', checked)
                                            }
                                        />
                                    </div>

                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label htmlFor="require_numbers">Require numbers</Label>
                                            <p className="text-xs text-muted-foreground">
                                                At least one number (0-9)
                                            </p>
                                        </div>
                                        <Switch
                                            id="require_numbers"
                                            checked={form.data.password_require_numbers}
                                            onCheckedChange={(checked) =>
                                                form.setData('password_require_numbers', checked)
                                            }
                                        />
                                    </div>

                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label htmlFor="require_symbols">Require special characters</Label>
                                            <p className="text-xs text-muted-foreground">
                                                At least one symbol (!@#$%^&* etc.)
                                            </p>
                                        </div>
                                        <Switch
                                            id="require_symbols"
                                            checked={form.data.password_require_symbols}
                                            onCheckedChange={(checked) =>
                                                form.setData('password_require_symbols', checked)
                                            }
                                        />
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Session Security */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-blue-100 p-2">
                                    <Timer className="h-5 w-5 text-blue-600" />
                                </div>
                                <div>
                                    <CardTitle>Session Security</CardTitle>
                                    <CardDescription>
                                        Control session timeouts and login behaviour
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-6 sm:grid-cols-3">
                                <div className="space-y-2">
                                    <Label>Session Timeout</Label>
                                    <Select
                                        value={form.data.session_timeout_minutes}
                                        onValueChange={(val) =>
                                            form.setData('session_timeout_minutes', val)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="15">15 minutes</SelectItem>
                                            <SelectItem value="30">30 minutes</SelectItem>
                                            <SelectItem value="60">1 hour</SelectItem>
                                            <SelectItem value="120">2 hours</SelectItem>
                                            <SelectItem value="480">8 hours</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        Inactive users will be logged out after this period
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="max_login_attempts">Max Login Attempts</Label>
                                    <Input
                                        id="max_login_attempts"
                                        type="number"
                                        min={1}
                                        max={20}
                                        value={form.data.max_login_attempts}
                                        onChange={(e) =>
                                            form.setData('max_login_attempts', e.target.value)
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Failed attempts before account lockout
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <Label>Lockout Duration</Label>
                                    <Select
                                        value={form.data.lockout_duration_minutes}
                                        onValueChange={(val) =>
                                            form.setData('lockout_duration_minutes', val)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="5">5 minutes</SelectItem>
                                            <SelectItem value="15">15 minutes</SelectItem>
                                            <SelectItem value="30">30 minutes</SelectItem>
                                            <SelectItem value="60">1 hour</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        How long a locked account stays locked
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Two-Factor Authentication */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-emerald-100 p-2">
                                    <ShieldCheck className="h-5 w-5 text-emerald-600" />
                                </div>
                                <div>
                                    <CardTitle>Two-Factor Authentication</CardTitle>
                                    <CardDescription>
                                        Enforce 2FA across your organisation
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between rounded-lg border p-4">
                                <div className="space-y-1">
                                    <Label htmlFor="force_2fa" className="text-sm font-medium">
                                        Force 2FA for all users
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        When enabled, all users will be required to set up two-factor
                                        authentication on their next login.
                                    </p>
                                    {form.data.force_2fa && (
                                        <div className="mt-2 flex items-center gap-2 text-xs text-amber-600">
                                            <AlertTriangle className="h-3.5 w-3.5" />
                                            <span>
                                                Users without 2FA will be prompted to set it up immediately.
                                            </span>
                                        </div>
                                    )}
                                </div>
                                <Switch
                                    id="force_2fa"
                                    checked={form.data.force_2fa}
                                    onCheckedChange={(checked) => form.setData('force_2fa', checked)}
                                />
                            </div>

                            <div className="flex items-center gap-3">
                                <Badge
                                    variant="outline"
                                    className={
                                        twoFaEnabled === twoFaTotal && twoFaTotal > 0
                                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
                                            : 'border-amber-300 bg-amber-50 text-amber-700'
                                    }
                                >
                                    <ShieldCheck className="mr-1 h-3 w-3" />
                                    {twoFaEnabled} of {twoFaTotal} users have 2FA enabled
                                </Badge>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Save Button */}
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            className="bg-violet-600 hover:bg-violet-700"
                            disabled={form.processing}
                        >
                            {form.processing ? 'Saving...' : 'Save Security Settings'}
                        </Button>
                    </div>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}
