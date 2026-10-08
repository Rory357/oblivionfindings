import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    QualificationRemoveDialog,
    QualificationRequirementEditor,
    matchesQualificationResult,
    type QualificationRequirement,
} from './requirement-editor';
const transport = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ router: transport }));
const requirement: QualificationRequirement = {
    id: 18,
    qualification_name: 'First aid',
    qualification_type: 'certification',
    description: 'Existing guidance',
    is_mandatory: true,
    service_context_id: null,
    mapping: {
        status: 'unmapped',
        requirement_id: null,
        label: null,
        code: null,
        check_type: null,
    },
    client: { id: 5, first_name: 'Ari', last_name: 'Kauri' },
};
const props = {
    requirement,
    actorId: 7,
    canEdit: true,
    clients: [{ id: 5, first_name: 'Ari', last_name: 'Kauri', site_id: 2 }],
    contexts: [
        { id: 3, name: 'Home support', site_id: 2 },
        { id: 4, name: 'Other House', site_id: 9 },
    ],
    options: [
        {
            id: 11,
            name: 'Recorded first aid',
            code: 'FIRST_AID',
            check_type: 'credential',
        },
    ],
    onClose: vi.fn(),
    onSaved: vi.fn(),
};
const values = {
    client_id: 5,
    qualification_name: 'First aid',
    qualification_type: 'certification',
    description: null,
    is_mandatory: true,
    service_context_id: null,
    hr_compliance_requirement_id: 11,
};
const receipt = (submitted: Record<string, unknown> = values) => ({
    props: {
        auth: { user: { id: 7 } },
        flash: {
            qualification_requirement_result: {
                action: 'updated',
                actor_id: 7,
                requirement_id: 18,
                values: submitted,
            },
        },
    },
});
beforeEach(() => vi.clearAllMocks());
function editMapping() {
    fireEvent.change(screen.getByLabelText('Guidance'), {
        target: { value: '' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(
        screen.getByRole('combobox', { name: 'Recognised qualification' }),
    );
    fireEvent.click(screen.getByRole('option', { name: /Recorded first aid/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}
describe('Qualification requirement editor', () => {
    it('reviews a precise mapping change and explicit cleared guidance before confirming a matching saved result', () => {
        render(<QualificationRequirementEditor {...props} />);
        editMapping();
        expect(screen.getByText('Recorded first aid')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save requirement' }),
        );
        expect(transport.put).toHaveBeenCalledTimes(1);
        expect(transport.put.mock.calls[0][1]).toEqual(values);
        expect(props.onSaved).not.toHaveBeenCalled();
        act(() => {
            transport.put.mock.calls[0][2].onSuccess(receipt());
            transport.put.mock.calls[0][2].onFinish();
        });
        expect(props.onSaved).toHaveBeenCalledOnce();
    });
    it('holds a mismatching response, preserves the entered mapping and prevents replay', () => {
        render(<QualificationRequirementEditor {...props} />);
        editMapping();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save requirement' }),
        );
        act(() => {
            transport.put.mock.calls[0][2].onSuccess(
                receipt({ ...values, hr_compliance_requirement_id: 55 }),
            );
            transport.put.mock.calls[0][2].onFinish();
        });
        expect(screen.getByText(/result could not be confirmed/)).toBeVisible();
        expect(screen.getByText('Recorded first aid')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save requirement' }),
        ).toBeDisabled();
        expect(props.onSaved).not.toHaveBeenCalled();
        expect(transport.put).toHaveBeenCalledOnce();
    });
    it('retains a draft when access is revoked and excludes a different House context', () => {
        const view = render(<QualificationRequirementEditor {...props} />);
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Service context' }),
        );
        expect(
            screen.queryByRole('option', { name: /Other House/ }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('option', { name: 'Home support' }));
        view.rerender(
            <QualificationRequirementEditor {...props} canEdit={false} />,
        );
        expect(
            screen.getByText(/current access does not allow saving/),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        expect(
            screen.getByRole('combobox', { name: 'Service context' }),
        ).toHaveTextContent('Home support');
    });
    it('asks before discarding edited values', () => {
        render(<QualificationRequirementEditor {...props} />);
        fireEvent.change(screen.getByLabelText('Guidance'), {
            target: { value: 'Unfinished guidance' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByRole('alertdialog')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Guidance')).toHaveValue(
            'Unfinished guidance',
        );
        expect(props.onClose).not.toHaveBeenCalled();
    });
    it('does not accept receipts for another actor, source or action, or a fabricated removed value', () => {
        expect(
            matchesQualificationResult(receipt(), 7, 'updated', 18, values),
        ).toBe(true);
        expect(
            matchesQualificationResult(receipt(), 8, 'updated', 18, values),
        ).toBe(false);
        expect(
            matchesQualificationResult(receipt(), 7, 'updated', 19, values),
        ).toBe(false);
        expect(
            matchesQualificationResult(receipt(), 7, 'created', null, values),
        ).toBe(false);
        expect(
            matchesQualificationResult(receipt(), 7, 'deleted', 18, null),
        ).toBe(false);
    });
    it('turns uncertain removal into a read rather than a second deletion', () => {
        const onRemoved = vi.fn(),
            onClose = vi.fn();
        render(
            <QualificationRemoveDialog
                requirement={requirement}
                actorId={7}
                allowed
                onClose={onClose}
                onRemoved={onRemoved}
            />,
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Remove requirement',
            }),
        );
        expect(transport.delete).toHaveBeenCalledOnce();
        act(() => transport.delete.mock.calls[0][1].onFinish());
        expect(
            screen.getByText(/Removal could not be confirmed/),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check current requirements' }),
        );
        expect(transport.delete).toHaveBeenCalledOnce();
        expect(transport.reload).toHaveBeenCalledOnce();
        expect(onRemoved).not.toHaveBeenCalled();
    });
});
