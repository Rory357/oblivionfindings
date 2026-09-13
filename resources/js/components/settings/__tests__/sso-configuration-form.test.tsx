import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SsoProviderForm, SsoProvisioningForm, type ProviderConfiguration, type ProvisioningConfiguration } from '../sso-configuration-form';

vi.mock('axios', () => ({ default: { put: vi.fn(), get: vi.fn(), post: vi.fn(), isAxiosError: (value: unknown) => !!value && typeof value === 'object' && 'isAxiosError' in value } }));
vi.mock('@inertiajs/react', () => ({ router: { on: vi.fn(() => () => {}) } }));
vi.mock('@/components/ui/select', () => ({
    Select: ({ value, onValueChange, children }: PropsWithChildren<{ value: string; onValueChange: (value: string) => void }>) => <select aria-label="Client secret action" value={value} onChange={event => onValueChange(event.target.value)}>{children}</select>,
    SelectTrigger: () => null, SelectValue: () => null,
    SelectContent: ({ children }: PropsWithChildren) => <>{children}</>,
    SelectItem: ({ children, value }: PropsWithChildren<{ value: string }>) => <option value={value}>{children}</option>,
}));

const configuration: ProviderConfiguration = {
    version: 0, source: 'deployment', saved_at: null,
    client_id: 'existing-client', directory_id: '', domain: 'example.test', staff_enabled: true, portal_enabled: false,
    secret_source: 'deployment', secret_present: true, secret_readable: true,
    callback_urls: { staff: 'https://app.example.test/auth/google/callback', portal: 'https://app.example.test/portal/auth/google/callback' },
    checks: {}, consent_status: 'unverified', sign_in_status: 'unverified',
};
function saved(version = 1, changes: Partial<ProviderConfiguration> = {}) { return { ...configuration, source: 'saved' as const, version, ...changes }; }
function error(status: number, errors?: Record<string, string[]>) { return { isAxiosError: true, response: { status, data: { errors } } }; }
function setup(initial = configuration) {
    const onSaved = vi.fn();
    render(<SsoProviderForm provider="google" initial={initial} onSaved={onSaved} onDirtyChange={vi.fn()} />);
    return onSaved;
}

describe('SSO settings persistence and recovery', () => {
    beforeEach(() => { vi.clearAllMocks(); });

    it('keeps the existing secret by default and accepts only a confirmed saved version', async () => {
        vi.mocked(axios.put).mockResolvedValue({ data: { status: 'saved', configuration: saved() } });
        const onSaved = setup();
        const storage = vi.spyOn(Storage.prototype, 'setItem');
        fireEvent.click(screen.getByRole('button', { name: 'Save Google settings' }));
        await screen.findByText('Settings saved. Provider consent and successful sign-in remain unverified.');
        expect(axios.put).toHaveBeenCalledWith('/settings/sso/providers/google', expect.objectContaining({ expected_version: 0, secret_action: 'keep', client_secret: '' }), expect.objectContaining({ timeout: 30000 }));
        expect(onSaved).toHaveBeenCalledWith(saved());
        expect(screen.queryByLabelText('Replacement secret')).not.toBeInTheDocument();
        expect(storage).not.toHaveBeenCalled(); storage.mockRestore();
    });

    it('retains nonsensitive entries on validation failure and clears a replacement secret', async () => {
        vi.mocked(axios.put).mockRejectedValue(error(422, { domain: ['Use an exact domain.'] }));
        setup();
        fireEvent.change(screen.getByLabelText('Staff organisation domain'), { target: { value: '*.example.test' } });
        fireEvent.change(screen.getByLabelText('Client secret action'), { target: { value: 'replace' } });
        fireEvent.change(screen.getByLabelText('Replacement secret'), { target: { value: 'synthetic-private-secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save Google settings' }));
        await screen.findByText('Use an exact domain.');
        expect(screen.getByLabelText('Staff organisation domain')).toHaveValue('*.example.test');
        expect(screen.queryByDisplayValue('synthetic-private-secret')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save Google settings' })).toBeEnabled();
        expect(screen.getByRole('alert')).toHaveFocus();
        expect(screen.queryByText('Settings saved')).not.toBeInTheDocument();
    });

    it('treats malformed success as unknown, freezes edits, then requires current-version review before retry', async () => {
        vi.mocked(axios.put).mockResolvedValueOnce({ data: { message: 'ok' } }).mockResolvedValueOnce({ data: { status: 'saved', configuration: saved(3, { domain: 'my.example.test' }) } });
        vi.mocked(axios.get).mockResolvedValue({ data: { configuration: saved(2, { domain: 'other.example.test' }) } });
        const onSaved = setup();
        fireEvent.change(screen.getByLabelText('Staff organisation domain'), { target: { value: 'my.example.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save Google settings' }));
        await screen.findByText(/The save outcome is unknown/);
        expect(onSaved).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Staff organisation domain')).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Review current saved settings' }));
        await screen.findByText('Current saved version 2');
        expect(axios.put).toHaveBeenCalledTimes(1);
        expect(screen.getByText('other.example.test')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Keep my field changes' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save Google settings' }));
        await screen.findByText('Settings saved. Provider consent and successful sign-in remain unverified.');
        expect(axios.put).toHaveBeenLastCalledWith(expect.any(String), expect.objectContaining({ expected_version: 2, domain: 'my.example.test', client_secret: '' }), expect.anything());
    });

    it.each([401, 419])('conceals inputs on session %s and provides normal login plus reread', async status => {
        vi.mocked(axios.put).mockRejectedValue(error(status));
        setup(); fireEvent.click(screen.getByRole('button', { name: 'Save Google settings' }));
        await screen.findByText(/Your session expired/);
        expect(screen.queryByLabelText('Client ID')).not.toBeInTheDocument();
        const login = screen.getByRole('link', { name: 'Sign in again' });
        expect(login).toHaveAttribute('href', '/login'); expect(login).toHaveAttribute('target', '_blank');
        expect(screen.getByRole('button', { name: 'Review current saved settings' })).toBeEnabled();
    });

    it('conceals revoked access and never retains a secret in its denied result', async () => {
        vi.mocked(axios.put).mockRejectedValue(error(403));
        setup(); fireEvent.change(screen.getByLabelText('Client secret action'), { target: { value: 'replace' } });
        fireEvent.change(screen.getByLabelText('Replacement secret'), { target: { value: 'revoked-synthetic-secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save Google settings' }));
        await screen.findByText(/Your current access does not allow SSO configuration/);
        expect(screen.queryByDisplayValue('revoked-synthetic-secret')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Client ID')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Check access again' })).toBeInTheDocument();
    });

    it('cancels only the HTTP wait and makes no rollback or success claim', async () => {
        vi.mocked(axios.put).mockImplementation((_url, _body, options) => new Promise((_resolve, reject) => options?.signal?.addEventListener?.('abort', () => reject(new Error('aborted')))));
        setup(); fireEvent.click(screen.getByRole('button', { name: 'Save Google settings' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel wait' }));
        await screen.findByText(/cancelling the wait does not undo a save/);
        expect(screen.getByRole('button', { name: 'Save Google settings' })).toBeDisabled();
    });

    it('aborts its request on unmount and ignores a late successful response', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(axios.put).mockImplementation(() => new Promise(done => { resolve = done; }));
        const onSaved = vi.fn();
        const { unmount } = render(<SsoProviderForm provider="google" initial={configuration} onSaved={onSaved} onDirtyChange={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Save Google settings' }));
        const signal = vi.mocked(axios.put).mock.calls[0][2]?.signal;
        unmount(); expect(signal?.aborted).toBe(true);
        await act(async () => resolve({ data: { status: 'saved', configuration: saved() } }));
        expect(onSaved).not.toHaveBeenCalled();
    });

    it('local check never claims connected and sends no unsaved secret', async () => {
        vi.mocked(axios.post).mockResolvedValue({ data: { provider: 'google', configuration_valid: true, checks: {}, consent_status: 'unverified' } });
        setup(); fireEvent.click(screen.getByRole('button', { name: 'Check saved configuration' }));
        await screen.findByText('Saved configuration passes local checks. Provider consent and sign-in remain unverified.');
        expect(axios.post).toHaveBeenCalledWith('/settings/sso/providers/google/check', {}, expect.anything());
        expect(screen.queryByText('Connected')).not.toBeInTheDocument();
    });

    it.each([401, 419, 403])('local check %s conceals the form and purges an entered replacement secret', async (status) => {
        vi.mocked(axios.post).mockRejectedValue(error(status));
        setup();
        fireEvent.change(screen.getByLabelText('Client secret action'), { target: { value: 'replace' } });
        fireEvent.change(screen.getByLabelText('Replacement secret'), { target: { value: 'synthetic-check-secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Check saved configuration' }));
        await screen.findByRole('alert');
        expect(screen.queryByLabelText('Client ID')).not.toBeInTheDocument();
        expect(screen.queryByDisplayValue('synthetic-check-secret')).not.toBeInTheDocument();
        if (status !== 403) expect(screen.getByRole('link', { name: 'Sign in again' })).toHaveAttribute('target', '_blank');
    });

    it('provisioning submits only editable policy flags and preserves mandatory approval and roles', async () => {
        const initial: ProvisioningConfiguration = { version: 0, source: 'deployment', saved_at: null, auto_create_staff: true, auto_link_existing: true, portal_auto_create: true, require_admin_approval: true, default_role_name: 'support_worker', portal_role_name: 'next_of_kin' };
        vi.mocked(axios.put).mockResolvedValue({ data: { status: 'saved', configuration: { ...initial, version: 1, source: 'saved', auto_create_staff: false } } });
        render(<SsoProvisioningForm initial={initial} onSaved={vi.fn()} onDirtyChange={vi.fn()} />);
        fireEvent.click(screen.getByRole('switch', { name: 'Create pending staff accounts' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save provisioning settings' }));
        await waitFor(() => expect(axios.put).toHaveBeenCalled());
        expect(vi.mocked(axios.put).mock.calls[0][1]).toEqual({ expected_version: 0, auto_create_staff: false, auto_link_existing: true, portal_auto_create: true });
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    });
});
