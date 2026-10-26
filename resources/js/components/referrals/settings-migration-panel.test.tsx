import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { SettingsMigrationPanel } from './settings-migration-panel';

const { get, post, reload } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('axios', () => ({
    default: {
        get,
        post,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            error.isAxiosError === true,
    },
}));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
const preview = {
    version: 9,
    from: { id: 1, version: 1 },
    target: { id: 3, version: 3, reason: 'Reviewed publication' },
    configuration_before: { public: { notice: 'Old notice' } },
    configuration_after: { public: { notice: 'New notice' } },
    funding: [],
    ready: true,
    issues: [],
    preview_token: 'a'.repeat(64),
};
beforeEach(() => {
    vi.clearAllMocks();
    get.mockResolvedValue({ data: preview });
});

it('previews the changes and requires a reason and review acknowledgement', async () => {
    render(<SettingsMigrationPanel reference="example" version={9} />);
    await screen.findByText('Old notice → New notice');
    expect(
        screen.getByRole('button', { name: 'Review migration' }),
    ).toBeDisabled();
    fireEvent.change(
        screen.getByRole('textbox', { name: /Reason for adopting/ }),
        {
            target: {
                value: 'Reviewed current settings with the service team.',
            },
        },
    );
    expect(
        screen.getByRole('button', { name: 'Review migration' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /^I reviewed the changes/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Review migration' }));
    expect(
        screen.getByRole('alertdialog', { name: 'Adopt settings version 3?' }),
    ).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
});

it('retries the identical migration after an uncertain response and shows confirmed success', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true }).mockResolvedValueOnce({
        data: { saved: true, target_id: 3 },
    });
    render(<SettingsMigrationPanel reference="example" version={9} />);
    fireEvent.change(
        await screen.findByRole('textbox', { name: /Reason for adopting/ }),
        { target: { value: 'Reviewed migration with the service team.' } },
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: /^I reviewed the changes/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Review migration' }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Apply reviewed migration' }),
    );
    await screen.findByRole('button', { name: 'Retry same migration' });
    expect(reload).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry same migration' }),
    );
    await screen.findByText(/Configuration updated\./);
    expect(post).toHaveBeenCalledTimes(2);
    expect(post.mock.calls[1]).toEqual(post.mock.calls[0]);
    await waitFor(() => expect(reload).toHaveBeenCalledTimes(1));
});

it('does not offer migration when the server identifies incompatible work', async () => {
    get.mockResolvedValue({
        data: {
            ...preview,
            ready: false,
            issues: ['Resolve incompatible funding first.'],
        },
    });
    render(<SettingsMigrationPanel reference="example" version={9} />);
    await screen.findByText('Resolve incompatible funding first.');
    expect(
        screen.getByRole('button', { name: 'Review migration' }),
    ).toBeDisabled();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
});

async function reviewMigration() {
    fireEvent.change(
        await screen.findByRole('textbox', { name: /Reason for adopting/ }),
        {
            target: {
                value: 'Reviewed this publication with the service team.',
            },
        },
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: /^I reviewed the changes/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Review migration' }));
}

it('recovers the identical request even when a refresh says the migration is already current', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true }).mockResolvedValueOnce({
        data: { saved: true, target_id: 3 },
    });
    const view = render(
        <SettingsMigrationPanel reference="example" version={9} />,
    );
    await reviewMigration();
    fireEvent.click(
        screen.getByRole('button', { name: 'Apply reviewed migration' }),
    );
    await screen.findByRole('button', { name: 'Retry same migration' });
    get.mockResolvedValue({
        data: {
            ...preview,
            version: 10,
            ready: false,
            issues: ['Already current.'],
        },
    });
    view.rerender(<SettingsMigrationPanel reference="example" version={10} />);
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2));
    expect(
        screen.getByRole('alertdialog', { name: 'Adopt settings version 3?' }),
    ).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry same migration' }),
    );
    await screen.findByText(/Configuration updated\./);
    expect(post.mock.calls[1]).toEqual(post.mock.calls[0]);
});

it('clears acknowledgement and closes an unsent confirmation when its preview changes', async () => {
    const view = render(
        <SettingsMigrationPanel reference="example" version={9} />,
    );
    await reviewMigration();
    get.mockResolvedValue({
        data: {
            ...preview,
            version: 10,
            target: { ...preview.target, version: 4 },
        },
    });
    view.rerender(<SettingsMigrationPanel reference="example" version={10} />);
    await screen.findByText('Recorded version 1 → Current version 4');
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    expect(
        screen.getByRole('checkbox', { name: /^I reviewed the changes/ }),
    ).not.toBeChecked();
    expect(
        screen.getByRole('button', { name: 'Review migration' }),
    ).toBeDisabled();
    expect(post).not.toHaveBeenCalled();
});

it('never carries an uncertain migration request into a different referral', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true });
    const view = render(
        <SettingsMigrationPanel reference="example" version={9} />,
    );
    await reviewMigration();
    fireEvent.click(
        screen.getByRole('button', { name: 'Apply reviewed migration' }),
    );
    await screen.findByRole('button', { name: 'Retry same migration' });
    view.rerender(
        <SettingsMigrationPanel reference="another-record" version={9} />,
    );
    expect(
        await screen.findByRole('textbox', { name: /Reason for adopting/ }),
    ).toHaveValue('');
    expect(
        screen.queryByRole('button', { name: 'Recover migration result' }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
});
