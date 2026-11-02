/**
 * Pop-up 63, "Update the saved login" (S&D Phase G): replace the login the hub uses to reach this
 * device, from the device itself, with the failure in one line. It goes through today's saved-login
 * path (written where logins are kept, then checked and switched back on), and nothing secret comes
 * back: not the password, and not the username either, so the form starts empty.
 *
 * The drawing's "Test login" (a real login to the device through the hub before saving) isn't built,
 * so it isn't offered.
 */
import { PopupField, PopupForm } from '@/components/popup';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { Note } from '@/components/signature/blocks';
import { Input } from '@/components/ui/input';
import type { StatusVariant } from '@/components/ui/status-badge';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { KeyRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

export type SavedLogin = {
    id: number;
    name: string;
    state: { tone: StatusVariant; text: string };
    failed_at: string | null;
    used_by: string;
    shared_with: { count: number; names: string[] };
    store: string;
    url: string;
};

type Errors = { username?: string; password?: string; login?: string };

const capitalise = (text: string) => (text ? text.charAt(0).toUpperCase() + text.slice(1) : text);

export function SavedLoginPopup({
    open,
    device,
    login,
    onClose,
    now = new Date(),
}: {
    open: boolean;
    device: { id: number; name: string; model: string; kind: string; site: string | null };
    login: SavedLogin;
    onClose: () => void;
    now?: Date;
}) {
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    // Nothing typed outlives the pop-up.
    useEffect(() => {
        setUsername('');
        setPassword('');
        setErrors({});
        setProcessing(false);
    }, [open]);

    const save = () => {
        const next: Errors = {};
        if (username.trim() === '') next.username = 'Enter the username the device expects.';
        if (password === '') next.password = 'Enter the password.';
        setErrors(next);
        if (Object.keys(next).length > 0) return;
        setProcessing(true);
        axios
            .put<{ state: 'working' | 'paused'; message: string }>(login.url, { username: username.trim(), password }, { headers: { Accept: 'application/json' } })
            .then((response) => {
                setPassword('');
                if (response.data.state === 'working') toast.success(response.data.message);
                else toast.warning(response.data.message);
                onClose();
                router.reload({ only: ['actions'] });
            })
            .catch((error) => {
                const status = axios.isAxiosError(error) ? error.response?.status : undefined;
                const data = axios.isAxiosError(error) ? error.response?.data : undefined;
                const first = (key: string) => (Array.isArray(data?.errors?.[key]) ? (data.errors[key][0] as string) : undefined);
                setErrors(
                    status === 422
                        ? { username: first('username'), password: first('password'), login: first('login') }
                        : status === 403
                          ? { login: 'You can’t replace saved logins.' }
                          : status === 404
                            ? { login: 'This device isn’t here any more.' }
                            : { login: 'The login couldn’t be saved. Nothing was changed; try again.' },
                );
            })
            .finally(() => setProcessing(false));
    };

    const shared = login.shared_with;
    const others = shared.names.join(', ') + (shared.count > shared.names.length ? ` and ${shared.count - shared.names.length} more` : '');

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="sm"
            icon={KeyRound}
            title="Update the saved login"
            description={`${capitalise(login.used_by)} uses this to reach the device · stored ${login.store}`}
            context={{
                title: device.name,
                tag: login.state.text,
                note: [device.model, device.site].filter(Boolean).join(' · ') || undefined,
                icon: iconForKind(device.kind),
            }}
            callout={
                login.failed_at
                    ? { tone: 'warning', content: `Login failed at ${seenTime(login.failed_at, now)}. Someone may have changed the device’s password.` }
                    : undefined
            }
            error={errors.login}
            submitLabel="Save login"
            processingLabel="Saving…"
            processing={processing}
            onSubmit={save}
            dirty={username !== '' || password !== ''}
            draftName={`the new login for ${device.name}`}
            testId="sd-saved-login"
        >
            <PopupField label="Username" htmlFor="sd-login-username" required error={errors.username}>
                <Input
                    id="sd-login-username"
                    value={username}
                    onChange={(event) => setUsername(event.target.value)}
                    autoComplete="off"
                    spellCheck={false}
                    aria-invalid={errors.username ? true : undefined}
                />
            </PopupField>
            <PopupField label="Password" htmlFor="sd-login-password" required hint={`stored ${login.store}; it can’t be read back`} error={errors.password}>
                <Input
                    id="sd-login-password"
                    type="password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    autoComplete="new-password"
                    aria-invalid={errors.password ? true : undefined}
                />
            </PopupField>
            {shared.count > 0 ? (
                <Note>
                    The same saved login is used for {others}
                    {device.site ? ` at ${device.site}` : ''}. Changing it here changes it for {shared.count === 1 ? 'that device' : 'those devices'} too.
                </Note>
            ) : null}
        </PopupForm>
    );
}
