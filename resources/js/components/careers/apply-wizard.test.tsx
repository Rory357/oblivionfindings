import type { PublicJob } from '@/components/careers/careers-parts';
import '@testing-library/jest-dom/vitest';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ApplyWizard, firstProblem, stepForField } from './apply-wizard';

type VisitOptions = {
    forceFormData?: boolean;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

const { post } = vi.hoisted(() => ({
    post: vi.fn<(url: string, data: unknown, options: VisitOptions) => void>(),
}));
vi.mock('@inertiajs/react', () => ({
    router: { post },
    usePage: () => ({ props: {} }),
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

const job: PublicJob = {
    id: 1,
    title: 'Support Worker',
    slug: 'support-worker-kapiti-coast',
    position_role: 'support_worker',
    employment_type: 'part_time',
    summary: 'Support three adults in their shared home.',
    description: 'You’ll join a small, steady team.',
    requirements: 'A full or restricted New Zealand driver licence',
    responsibilities: 'Write clear daily notes',
    screening_questions: ['Which days and times can you usually work?'],
    site: { id: 3, name: 'Kāpiti Lodge' },
    pay: '$28.50 to $31.00 an hour',
    closing_at: null,
};

function renderWizard() {
    return render(
        <ApplyWizard
            job={job}
            open
            onClose={vi.fn()}
            siteName="Oblivion Living"
        />,
    );
}

const press = (name: RegExp | string) =>
    fireEvent.click(screen.getByRole('button', { name }));

function fillAboutYou() {
    fireEvent.change(screen.getByLabelText('First name'), {
        target: { value: 'Aroha' },
    });
    fireEvent.change(screen.getByLabelText('Last name'), {
        target: { value: 'Ngata' },
    });
    fireEvent.change(screen.getByLabelText('Email'), {
        target: { value: 'aroha@example.test' },
    });
}

beforeEach(() => {
    post.mockReset();
});
afterEach(cleanup);

it('jumps back to the first missing field when sent empty, without posting', async () => {
    renderWizard();
    press('Continue');
    press('Continue');
    press('Continue');
    press('Send application');

    expect(post).not.toHaveBeenCalled();
    expect(screen.getByText('Enter your first name.')).toBeInTheDocument();
    expect(screen.getByLabelText('First name')).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    await waitFor(() =>
        expect(document.activeElement).toBe(
            screen.getByLabelText('First name'),
        ),
    );
});

it('asks about the right to work before sending, on its own step', async () => {
    renderWizard();
    fillAboutYou();
    press('Continue');
    press('Continue');
    press('Continue');
    fireEvent.click(screen.getByRole('checkbox'));
    press('Send application');

    expect(post).not.toHaveBeenCalled();
    expect(
        screen.getByText('Tell us whether you can work in New Zealand.'),
    ).toBeInTheDocument();
    const firstTile = screen.getByRole('radio', {
        name: /Yes, citizen or resident/,
    });
    await waitFor(() => expect(document.activeElement).toBe(firstTile));
});

it('sends the new experience, right to work and licence answers to the existing endpoint', async () => {
    post.mockImplementation((_url, _data, options) => {
        options.onSuccess?.();
        options.onFinish?.();
    });
    renderWizard();
    fillAboutYou();
    press('Continue');

    fireEvent.click(screen.getByRole('radio', { name: /1 to 3 years/ }));
    fireEvent.change(
        screen.getByLabelText(
            'Your experience, in a sentence or two (optional)',
        ),
        { target: { value: 'Two years at a rest home.' } },
    );
    press('Continue');

    expect(screen.getByText('This role needs one.')).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('radio', { name: /Yes, citizen or resident/ }),
    );
    fireEvent.click(screen.getByRole('radio', { name: /Full licence/ }));
    expect(screen.getByText('A note on police vetting')).toBeInTheDocument();
    press('Continue');

    fireEvent.click(screen.getByRole('checkbox'));
    press('Send application');

    expect(post).toHaveBeenCalledTimes(1);
    const [url, data, options] = post.mock.calls[0];
    expect(url).toBe('/careers/jobs/support-worker-kapiti-coast/apply');
    expect(options.forceFormData).toBe(true);
    expect(data).toMatchObject({
        first_name: 'Aroha',
        last_name: 'Ngata',
        personal_email: 'aroha@example.test',
        source_channel: 'career_page',
        years_of_experience: '1_to_3',
        experience_summary: 'Two years at a rest home.',
        right_to_work_status: 'citizen_or_resident',
        driver_licence_class: 'full',
        privacy_consent: true,
        screening_answers: [
            {
                question: 'Which days and times can you usually work?',
                answer: '',
            },
        ],
    });

    expect(
        await screen.findByText('Thanks — your application is with us'),
    ).toBeInTheDocument();
    expect(screen.getByText('aroha@example.test')).toBeInTheDocument();
    expect(
        screen.getByText(/link to check your application’s progress/),
    ).toBeInTheDocument();
});

it('moves to the step a server error belongs to and focuses that field', async () => {
    post.mockImplementation((_url, _data, options) => {
        options.onError?.({
            personal_email: 'The personal email must be a valid address.',
        });
        options.onFinish?.();
    });
    renderWizard();
    fillAboutYou();
    press('Continue');
    press('Continue');
    fireEvent.click(
        screen.getByRole('radio', { name: /Yes, citizen or resident/ }),
    );
    press('Continue');
    fireEvent.click(screen.getByRole('checkbox'));
    press('Send application');

    expect(
        await screen.findByText('The personal email must be a valid address.'),
    ).toBeInTheDocument();
    await waitFor(() =>
        expect(document.activeElement).toBe(screen.getByLabelText('Email')),
    );
});

it('says so honestly when the application could not be sent', async () => {
    post.mockImplementation((_url, _data, options) => options.onFinish?.());
    renderWizard();
    fillAboutYou();
    press('Continue');
    press('Continue');
    fireEvent.click(
        screen.getByRole('radio', { name: /Yes, citizen or resident/ }),
    );
    press('Continue');
    fireEvent.click(screen.getByRole('checkbox'));
    press('Send application');

    expect(
        await screen.findByText(/We couldn’t send your application/),
    ).toBeInTheDocument();
    expect(
        screen.queryByText('Thanks — your application is with us'),
    ).not.toBeInTheDocument();
});

it('refuses a CV that is not a PDF or Word document', () => {
    renderWizard();
    press('Continue');
    const input = document.getElementById('apply-cv-input') as HTMLInputElement;
    fireEvent.change(input, {
        target: {
            files: [new File(['x'], 'photo.png', { type: 'image/png' })],
        },
    });

    expect(
        screen.getByText('Choose a PDF or Word document (DOC or DOCX).'),
    ).toBeInTheDocument();
});

it('maps server field keys to the step that owns them', () => {
    expect(stepForField('first_name')).toBe(0);
    expect(stepForField('screening_answers.0.answer')).toBe(1);
    expect(stepForField('cv')).toBe(1);
    expect(stepForField('right_to_work_status')).toBe(2);
    expect(stepForField('privacy_consent')).toBe(3);
    expect(stepForField('application')).toBe(3);
    expect(
        firstProblem({
            privacy_consent: 'x',
            driver_licence_class: 'y',
            last_name: 'z',
        }),
    ).toBe('last_name');
});
