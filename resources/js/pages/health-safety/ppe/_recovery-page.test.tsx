import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { StrictMode, type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { PpeRecoveryQueue } from './recovery';

const io = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: { get: io.get, post: io.post, isAxiosError: (error: unknown) => !!error && typeof error === 'object' && 'response' in error } }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children, href }: { children: ReactNode; href: string }) => <a href={href}>{children}</a> }));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@/components/page', () => ({ PageHeader: () => null, PageHeaderGlassButton: () => null, PageHeaderPrimaryButton: () => null, PageHeaderRail: () => null,
    PageLayout: ({ children }: { children: ReactNode }) => <>{children}</> }));
const removal = (state = 'pending') => ({ id: 9, state, removed_from_record: true, recorded_at: '2026-10-12T03:00:00Z', can_retry: state !== 'complete' });
const queue = (attempts: unknown[], next_cursor: number | null = null) => ({ data: { attempts, next_cursor } });
beforeEach(() => { vi.resetAllMocks(); });
afterEach(cleanup);

it('withdraws a loaded queue after current access is revoked instead of leaving old attempts actionable', async () => {
    io.get.mockResolvedValueOnce(queue([removal()])).mockRejectedValueOnce({ response: { status: 403 } });
    render(<PpeRecoveryQueue kind="removals" />);
    // A loaded queue is re-read from the header's Check again or the row's menu (drawing pg-hsn-124).
    fireEvent.contextMenu(await screen.findByText('File attempt 9'), { clientX: 120, clientY: 150 });
    fireEvent.click(screen.getByRole('menuitem', { name: 'Check current attempts' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('unavailable with current access');
    expect(screen.queryByText('File attempt 9')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Retry file cleanup' })).not.toBeInTheDocument();
});
it('requires a fresh current check after an interrupted retry and never automatically repeats cleanup', async () => {
    io.get.mockResolvedValueOnce(queue([removal()])).mockResolvedValueOnce(queue([removal('complete')]));
    io.post.mockRejectedValueOnce(new Error('Connection interrupted'));
    render(<PpeRecoveryQueue kind="removals" />);
    fireEvent.click(await screen.findByRole('button', { name: 'Retry file cleanup' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Delete the file' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Check current attempts before retrying');
    expect(io.post).toHaveBeenCalledTimes(1); expect(io.get).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('button', { name: 'Retry file cleanup' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Check current attempts' }));
    expect(await screen.findByText('File cleanup confirmed')).toBeInTheDocument();
    expect(io.post).toHaveBeenCalledTimes(1); expect(screen.queryByRole('button', { name: 'Retry file cleanup' })).not.toBeInTheDocument();
});
it('keeps public-origin cleanup separate and refuses a response that claims removal of the evidence record', async () => {
    const origin = { id: 9, purpose: 'legacy_public_origin', private_reference_recorded: true, state: 'pending', public_copy_cleanup: 'pending', can_retry: true };
    io.get.mockResolvedValue(queue([origin]));
    io.post.mockResolvedValue({ data: { relocation_cleanup: { ...origin, removed_from_record: true } } });
    render(<PpeRecoveryQueue kind="relocations" />);
    fireEvent.click(await screen.findByRole('button', { name: 'Retry file cleanup' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Delete the file' }));
    expect(io.post).toHaveBeenCalledWith('/health-safety/ppe/relocation-cleanup/9/retry', {}, expect.objectContaining({ headers: { Accept: 'application/json' } }));
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    expect(screen.queryByText('Old public copy cleanup confirmed')).not.toBeInTheDocument();
});
it('retains the purpose of a confirmed transfer while protecting the active private evidence', async () => {
    const origin = { id: 9, purpose: 'legacy_public_origin', private_reference_recorded: true, state: 'pending', public_copy_cleanup: 'pending', can_retry: true };
    io.get.mockResolvedValue(queue([origin]));
    io.post.mockResolvedValue({ data: { relocation_cleanup: { ...origin, state: 'complete', public_copy_cleanup: 'complete' } } });
    render(<PpeRecoveryQueue kind="relocations" />);
    fireEvent.click(await screen.findByRole('button', { name: 'Retry file cleanup' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Delete the file' }));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Retry file cleanup' })).not.toBeInTheDocument());
    expect(screen.getByRole('status')).toHaveTextContent('Old public copy cleanup confirmed');
    expect(screen.getByText(/The evidence record remains available/)).toBeInTheDocument();
});
it('does not mistake malformed or empty current pages for confirmation of all file cleanup', async () => {
    io.get.mockResolvedValueOnce(queue([removal(), removal()])).mockResolvedValueOnce(queue([]));
    render(<PpeRecoveryQueue kind="removals" />);
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be checked');
    fireEvent.click(screen.getByRole('button', { name: 'Check current attempts' }));
    expect(await screen.findByText(/does not confirm organisation-wide/)).toBeInTheDocument();
    expect(screen.queryByText('File cleanup confirmed')).not.toBeInTheDocument();
});
it('restarts the aborted initial read under StrictMode and uses the server continuation cursor', async () => {
    io.get.mockImplementation((_path, config) => config.signal.aborted ? new Promise(() => {}) : Promise.resolve(queue([removal()], 9)));
    render(<StrictMode><PpeRecoveryQueue kind="removals" /></StrictMode>);
    expect(await screen.findByText('File attempt 9')).toBeInTheDocument();
    expect(io.get).toHaveBeenCalledTimes(2);
    expect(io.get.mock.calls[0][1].signal.aborted).toBe(true);
    io.get.mockResolvedValueOnce(queue([{ ...removal(), id: 10 }]));
    fireEvent.click(screen.getByRole('button', { name: 'Next attempts' }));
    expect(await screen.findByText('File attempt 10')).toBeInTheDocument();
    expect(io.get.mock.calls.at(-1)?.[1].params).toEqual({ after_id: 9 });
});
it('admits one retry before React updates when a control is activated twice', async () => {
    let resolve!: (value: unknown) => void;
    io.get.mockResolvedValue(queue([removal()]));
    io.post.mockReturnValue(new Promise((done) => { resolve = done; }));
    render(<PpeRecoveryQueue kind="removals" />);
    const button = await screen.findByRole('button', { name: 'Retry file cleanup' });
    act(() => { button.click(); button.click(); });
    const confirm = await screen.findByRole('button', { name: 'Delete the file' });
    act(() => { confirm.click(); confirm.click(); });
    expect(io.post).toHaveBeenCalledTimes(1);
    await act(async () => { resolve({ data: { cleanup: removal('complete') } }); });
    expect(screen.queryByRole('button', { name: 'Retry file cleanup' })).not.toBeInTheDocument();
});

it('uses the shared context menu and removes its actions when a fresh check withdraws access', async () => {
    io.get.mockResolvedValueOnce(queue([removal()])).mockRejectedValueOnce({ response: { status: 403 } });
    render(<PpeRecoveryQueue kind="removals" />);
    const label = await screen.findByText('File attempt 9');
    fireEvent.contextMenu(label, { clientX: 120, clientY: 150 });
    expect(screen.getByRole('menu', { name: 'File attempt 9' })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Retry file cleanup' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitem', { name: 'Check current attempts' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('unavailable with current access');
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: 'Retry file cleanup' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'More actions' })).not.toBeInTheDocument();
    expect(io.post).not.toHaveBeenCalled();
});
