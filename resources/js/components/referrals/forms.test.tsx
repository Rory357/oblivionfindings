import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { FormAnswersPanel } from './form-answers-panel';
import {
    answerText,
    applicableAnswers,
    applicableQuestions,
    requiredQuestionErrors,
    type ConfiguredQuestion,
} from './form-types';
import { SettingsForms } from './settings-forms';
import type { ReferralConfiguration } from './settings-types';
import type { ReferralDetail } from './types';

const { patch, reload } = vi.hoisted(() => ({
    patch: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('axios', () => ({
    default: {
        patch,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            !!error.isAxiosError,
    },
}));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
const field: ConfiguredQuestion = {
    id: 'qa_question',
    label: 'Preferred introduction',
    help: '',
    type: 'text',
    options: [],
    enabled: true,
    public: true,
    required: 'intake',
    audience: 'intake',
    destination: 'referral_intake',
    service_ids: ['supported_living'],
    purposes: ['support'],
};
const referral = {
    reference: 'fictional',
    version: 4,
    name: 'Fictional person',
    form_fields: [
        { ...field, value: 'Phone', editable: true },
        {
            ...field,
            id: 'qa_funding',
            label: 'Funding question',
            audience: 'funding',
            destination: 'referral_funding',
            value: null,
            editable: true,
        },
    ],
    form_answers: [],
} as unknown as ReferralDetail;
beforeEach(() => {
    vi.clearAllMocks();
    HTMLElement.prototype.scrollTo = vi.fn();
});

it('omits conditional and process-only answers from the outgoing payload', () => {
    expect(answerText('2026-10-20', 'date')).toBe('20 Oct 2026');
    expect(applicableQuestions([field], 'unsure', 'support', false)).toEqual(
        [],
    );
    expect(
        applicableQuestions([field], 'supported_living', 'support', true),
    ).toEqual([]);
    expect(
        applicableAnswers([], {
            qa_question: 'Do not submit hidden personal information',
        }),
    ).toEqual({});
    expect(
        requiredQuestionErrors([{ ...field, type: 'checkbox' }], {
            qa_question: false,
        }),
    ).toEqual({});
    expect(
        requiredQuestionErrors([{ ...field, type: 'number' }], {
            qa_question: 0,
        }),
    ).toEqual({});
});

it('provides a public-form preview without storing its synthetic answers in settings', () => {
    const change = vi.fn();
    render(
        <SettingsForms
            value={
                {
                    forms: { fields: [field] },
                    services: [
                        {
                            id: 'supported_living',
                            label: 'Supported living',
                            enabled: true,
                        },
                    ],
                } as ReferralConfiguration
            }
            onChange={change}
            disabled={false}
            readOnly
        />,
    );
    fireEvent.click(screen.getByText('Preview public questions'));
    fireEvent.change(
        screen.getByRole('textbox', { name: /Preferred introduction/ }),
        { target: { value: 'Fictional preview only' } },
    );
    expect(change).not.toHaveBeenCalled();
    expect(
        screen.queryByRole('button', { name: 'Add question' }),
    ).not.toBeInTheDocument();
});

it('edits one audience at a time and posts only changed values with the original record version', async () => {
    patch.mockResolvedValue({ data: { saved: true } });
    render(<FormAnswersPanel referral={referral} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Update intake answers' }),
    );
    const dialog = screen.getByRole('dialog');
    expect(
        within(dialog).queryByText(/Funding question/),
    ).not.toBeInTheDocument();
    fireEvent.change(
        within(dialog).getByRole('textbox', { name: /Preferred introduction/ }),
        { target: { value: 'Video call' } },
    );
    fireEvent.change(
        within(dialog).getByRole('textbox', { name: 'Reason for this change' }),
        { target: { value: 'Person clarified preference' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save answers' }));
    await screen.findByText('Answers saved');
    expect(patch).toHaveBeenCalledWith(
        '/operations/referrals/fictional/forms',
        {
            version: 4,
            answers: { qa_question: 'Video call' },
            reason: 'Person clarified preference',
        },
    );
    expect(reload).toHaveBeenCalledTimes(1);
});

it('does not resend or claim success after an uncertain answer update', async () => {
    patch.mockRejectedValue({ isAxiosError: true });
    render(<FormAnswersPanel referral={referral} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Update intake answers' }),
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: /Preferred introduction/ }),
        { target: { value: 'Video call' } },
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Reason for this change' }),
        { target: { value: 'Person clarified preference' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save answers' }));
    await screen.findByText(/Save was not confirmed/);
    expect(screen.getByRole('button', { name: 'Save answers' })).toBeDisabled();
    expect(screen.queryByText('Answers saved')).not.toBeInTheDocument();
    expect(patch).toHaveBeenCalledTimes(1);
});
