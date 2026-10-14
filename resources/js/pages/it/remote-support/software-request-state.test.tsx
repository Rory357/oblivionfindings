import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    SoftwareRequestState,
    type SoftwareRequest,
} from './software-request-state';
afterEach(cleanup);
const base: SoftwareRequest = {
    packageUuid: null,
    packageName: 'Document viewer',
    publisher: 'Example Software',
    productCode: null,
    productVersion: '4.2.1',
    architecture: 'amd64',
    consentMode: 'attended',
    state: 'awaiting_observation',
    rebootRequired: false,
    exitCode: 0,
};
describe('software installation evidence', () => {
    it('does not call an installer receipt independently verified success', () => {
        render(<SoftwareRequestState software={base} />);
        expect(
            screen.getByText('Checking installed version'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/separate check of the installed product/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Installed version verified'),
        ).not.toBeInTheDocument();
    });
    it('keeps reboot required separate from successful installed-product proof', () => {
        const restart = vi.fn();
        render(
            <SoftwareRequestState
                software={{
                    ...base,
                    state: 'confirmed',
                    rebootRequired: true,
                    exitCode: 3010,
                }}
                onArrangeRestart={restart}
            />,
        );
        expect(
            screen.getByText('Installed · restart needed'),
        ).toBeInTheDocument();
        expect(restart).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Arrange restart' }),
        );
        expect(restart).toHaveBeenCalledOnce();
    });
    it('does not offer restart as a repair for an uncertain installation', () => {
        render(
            <SoftwareRequestState
                software={{
                    ...base,
                    state: 'uncertain',
                    rebootRequired: true,
                    exitCode: 1641,
                }}
                onArrangeRestart={vi.fn()}
            />,
        );
        expect(
            screen.getByText(/restart alone does not prove/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Arrange restart' }),
        ).not.toBeInTheDocument();
    });
});
