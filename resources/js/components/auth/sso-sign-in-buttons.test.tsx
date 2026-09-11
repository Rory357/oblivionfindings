import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { SsoSignInButtons } from './sso-sign-in-buttons';
afterEach(cleanup);
describe('SSO sign-in availability', () => {
    it('shows only explicitly available native links on the correct account surface', () => {
        render(<SsoSignInButtons audience="portal" providers={{ microsoft: false, google: true }} />);
        expect(screen.getByRole('link', { name: 'Continue with Google' })).toHaveAttribute('href', '/portal/auth/google/redirect');
        expect(screen.queryByRole('link', { name: 'Continue with Microsoft' })).not.toBeInTheDocument();
    });
    it('does not fabricate availability when configuration is missing', () => {
        render(<SsoSignInButtons />); expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
    it('keeps callback failure readable even after the provider was disabled', () => {
        render(<SsoSignInButtons providers={{ microsoft: false, google: false }} errors={{ google: 'Start sign-in again.' }} />);
        expect(screen.getByRole('alert')).toHaveTextContent('Start sign-in again.');
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
