import { Button } from '@/components/ui/button';
import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import {
    DocumentHandoverFields,
    useDocumentHandovers,
} from './document-handover-fields';
import type { ReferralDocument } from './evidence-picker';

const first = { id: 1, title: 'Reviewed assessment' } as ReferralDocument;
const second = { id: 2, title: 'Communication support' } as ReferralDocument;
function Harness() {
    const documents = useDocumentHandovers();
    return (
        <>
            <Button onClick={() => documents.select([1], first)}>
                Select first page
            </Button>
            <Button onClick={() => documents.select([1, 2], second)}>
                Select second page
            </Button>
            <Button onClick={() => documents.select([2], first)}>
                Remove first evidence
            </Button>
            <DocumentHandoverFields
                documents={documents.selected}
                reviews={documents.reviews}
                onChange={documents.setReviews}
            />
            <output data-testid="valid">{String(documents.valid)}</output>
            <output data-testid="count">{documents.reviews.length}</output>
        </>
    );
}

it('requires whole-document confirmation and resets it when the reviewed title changes', () => {
    render(<Harness />);
    fireEvent.click(screen.getByText(/Documents for the person’s profile/));
    fireEvent.click(screen.getByText('Select first page'));
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'Release Reviewed assessment to care staff',
        }),
    );
    expect(screen.getByTestId('valid')).toHaveTextContent('false');
    fireEvent.change(screen.getByLabelText('Title on the person’s profile'), {
        target: { value: 'Care assessment summary' },
    });
    fireEvent.change(
        screen.getByLabelText('Why is the whole document needed for care?'),
        { target: { value: 'Reviewed support instructions for daily care' } },
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I reviewed every page/ }),
    );
    expect(screen.getByTestId('valid')).toHaveTextContent('true');
    fireEvent.change(screen.getByLabelText('Title on the person’s profile'), {
        target: { value: 'Updated care title' },
    });
    expect(
        screen.getByRole('checkbox', { name: /I reviewed every page/ }),
    ).not.toBeChecked();
    expect(screen.getByTestId('valid')).toHaveTextContent('false');
});

it('keeps selected documents across pages and drops a release when its evidence is deselected', () => {
    render(<Harness />);
    fireEvent.click(screen.getByText(/Documents for the person’s profile/));
    fireEvent.click(screen.getByText('Select first page'));
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'Release Reviewed assessment to care staff',
        }),
    );
    fireEvent.click(screen.getByText('Select second page'));
    expect(
        screen.getByRole('checkbox', {
            name: 'Release Reviewed assessment to care staff',
        }),
    ).toBeChecked();
    expect(
        screen.getByRole('checkbox', {
            name: 'Release Communication support to care staff',
        }),
    ).not.toBeChecked();
    fireEvent.click(screen.getByText('Remove first evidence'));
    expect(
        screen.queryByRole('checkbox', {
            name: 'Release Reviewed assessment to care staff',
        }),
    ).not.toBeInTheDocument();
    expect(screen.getByTestId('count')).toHaveTextContent('0');
    expect(screen.getByTestId('valid')).toHaveTextContent('true');
});
