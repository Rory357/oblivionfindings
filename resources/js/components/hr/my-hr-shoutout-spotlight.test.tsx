import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MyHrShoutoutSpotlight } from './my-hr-shoutout-spotlight';
import type { MyHrShoutout } from './my-hr-types';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post } }));
const shoutout: MyHrShoutout = {
    id: 20,
    can_reply: true,
    giver: {
        id: 1,
        name: 'Aroha Bennett',
        initials: 'AB',
        role: 'Coordinator',
        you: false,
    },
    recipient: {
        id: null,
        type: 'team',
        department_id: 3,
        name: 'Learning team',
        initials: 'LT',
        role: 'Team',
        you: true,
    },
    category: 'teamwork',
    message: 'Everyone made new colleagues feel welcome.',
    created_at: null,
    reactions: { heart: [], party: [], hands: [] },
    replies: [],
};
const props = {
    shoutouts: [shoutout],
    me: { initials: 'JB', firstName: 'Jamie' },
};
beforeEach(() => post.mockReset());

describe('My HR canonical team recognition', () => {
    it('keeps the whole team identity and sends replies through its one canonical record', () => {
        const view = render(
            <MyHrShoutoutSpotlight {...props} perspective="given" />,
        );
        expect(screen.getByText('To Learning team')).toBeVisible();
        expect(view.container.innerHTML).not.toContain('NaN');
        fireEvent.click(screen.getByRole('button', { name: /Reply/ }));
        fireEvent.change(screen.getByPlaceholderText('Write a reply…'), {
            target: { value: 'Proud of the whole team.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        expect(post).toHaveBeenCalledWith(
            '/hr/my/kudos/20/reply',
            { body: 'Proud of the whole team.' },
            expect.any(Object),
        );
    });
    it('uses current reply permission and never infers team membership as permission', () => {
        const view = render(
            <MyHrShoutoutSpotlight
                {...props}
                shoutouts={[{ ...shoutout, can_reply: false }]}
            />,
        );
        expect(
            screen.getByText('Learning team got a shout-out 🎉'),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /Say thanks/ }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /View replies/ }));
        expect(screen.getByPlaceholderText('Write a reply…')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
        view.rerender(
            <MyHrShoutoutSpotlight
                {...props}
                shoutouts={[{ ...shoutout, can_reply: undefined }]}
            />,
        );
        expect(screen.getByPlaceholderText('Write a reply…')).toBeDisabled();
    });
});
