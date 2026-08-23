import { fireEvent, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { DiscontinueDialog } from '@/pages/emar/_dialogs';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = React.useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                post: inertia.post,
                reset: vi.fn(),
                setData: (key: keyof T, value: T[keyof T]) =>
                    setState((current) => ({ ...current, [key]: value })),
            };
        },
    };
});

vi.mock('sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

describe('medication order discontinuation', () => {
    afterEach(() => {
        inertia.post.mockReset();
    });

    it('requires a reason before posting to the selected profile action', () => {
        render(
            <DiscontinueDialog
                medication={{ id: 42, name: 'Warfarin' }}
                action="/operations/clients/7/medical/medications/42/discontinue"
                onClose={vi.fn()}
            />,
        );

        const submit = screen.getByRole('button', {
            name: 'Discontinue medication',
        });
        expect(submit).toBeDisabled();

        fireEvent.change(
            screen.getByPlaceholderText('Why is this medication being ceased?'),
            { target: { value: 'Prescriber stopped treatment' } },
        );
        expect(submit).toBeEnabled();

        fireEvent.click(submit);
        expect(inertia.post).toHaveBeenCalledWith(
            '/operations/clients/7/medical/medications/42/discontinue',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('uses the shared discontinue interaction on both profile pages and has no one-click medication delete', () => {
        for (const page of [
            'resources/js/pages/clients/medical.tsx',
            'resources/js/pages/operations/clients/medical.tsx',
        ]) {
            const source = readFileSync(resolve(process.cwd(), page), 'utf8');

            expect(source).toContain('<DiscontinueDialog');
            expect(source).toMatch(/>\s*Discontinue\s*<\/Button>/);
            expect(source).toContain("m.state !== 'ceased'");
            expect(source).toContain('!m.ceased_at');
            expect(source).toContain('auth?.can?.medications?.view');
            expect(source).toContain('auth?.can?.medications?.ordersManage');
            expect(source).toContain('canDiscontinue &&');
            expect(source).not.toMatch(/medForm\.delete\(/);
            expect(source).not.toMatch(/medications\/\$\{m\.id\}`/);
        }

        const detailSource = readFileSync(
            resolve(process.cwd(), 'resources/js/pages/emar/_dialogs.tsx'),
            'utf8',
        );
        expect(detailSource).toContain('request_key: crypto.randomUUID()');
        expect(detailSource).toContain('maxLength={255}');
        expect(detailSource).toContain("medication.state === 'ceased'");
        expect(detailSource).toContain('medication.ceased_reason');
    });
});
