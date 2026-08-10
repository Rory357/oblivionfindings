import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { WorkflowTemplateDestination } from './workflow-template-destination';

describe('IT workflow template destination', () => {
    it('requires IT management permission before rendering the setup link', () => {
        const { rerender } = render(
            <WorkflowTemplateDestination canManage={false} />,
        );

        expect(
            screen.queryByRole('link', {
                name: /manage workflow templates/i,
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('IT management access required'),
        ).toBeInTheDocument();

        rerender(<WorkflowTemplateDestination canManage />);

        expect(
            screen.getByRole('link', {
                name: /manage workflow templates/i,
            }),
        ).toHaveAttribute('href', '/it/setup');
    });
});
