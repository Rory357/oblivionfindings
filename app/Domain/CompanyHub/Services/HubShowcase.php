<?php

namespace App\Domain\CompanyHub\Services;

/** Fictional presentation data. Never writes HR, calendar or publication records. */
class HubShowcase
{
    public static function data(?string $story = null): array
    {
        $home = HubContent::defaults('Welcome to Company Hub');
        $home['hero_label'] = 'OUR PEOPLE. OUR STORIES.';
        $home['summary'] = 'Your company news, people and everyday essentials.';
        $home['sections'][0]['blocks'][0]['title'] = 'Company news';
        $home['sections'][0]['blocks'][0]['limit'] = 2;
        $home['sections'][0]['blocks'][1]['title'] = 'Quick links';
        $home['sections'][0]['blocks'][1]['links'] = [
            ['id' => 'handbook', 'title' => 'People & culture guide', 'url' => '/company-hub/showcase?story=people-guide', 'description' => 'The little things that make us, us'],
            ['id' => 'learning', 'title' => 'Learn something new', 'url' => '/company-hub/showcase?story=learning-together', 'description' => 'Make space for your next step'],
            ['id' => 'support', 'title' => 'Support when you need it', 'url' => '/company-hub/showcase?story=people-guide', 'description' => 'Helpful people, just a click away'],
            ['id' => 'wellbeing', 'title' => 'People & wellbeing', 'url' => '/company-hub/showcase?story=people-guide', 'description' => 'Take care of yourself and each other'],
        ];
        $home['sections'][1]['blocks'][0]['title'] = 'Recognition';
        $home['sections'][1]['blocks'][0]['limit'] = 1;
        $pages = collect([
            ['id' => -1, 'slug' => 'growing-together', 'kind' => 'news', 'title' => 'Growing something good, together', 'summary' => 'An afternoon in the garden. A lasting connection with our community.', 'category' => 'In our community', 'cover_url' => '/images/company-hub/community-garden.png', 'featured' => true],
            ['id' => -2, 'slug' => 'learning-together', 'kind' => 'news', 'title' => 'Fresh ideas start with a conversation', 'summary' => 'Meet the people making our new learning circles a little more inspiring.', 'category' => 'Our people', 'cover_url' => '/images/company-hub/colleagues-together.png', 'featured' => false],
            ['id' => -3, 'slug' => 'small-moments', 'kind' => 'news', 'title' => 'Small moments. A big difference.', 'summary' => 'Celebrating the everyday acts of kindness across our teams this month.', 'category' => 'Making a difference', 'cover_url' => '/images/company-hub/community-garden.png', 'featured' => false],
            ['id' => -4, 'slug' => 'people-guide', 'kind' => 'page', 'title' => 'Your people & culture guide', 'summary' => 'A good place to start, wherever you are in your journey with us.', 'category' => 'Helpful resources', 'cover_url' => '/images/company-hub/colleagues-together.png', 'featured' => false],
        ])->map(fn ($page) => [...$page, 'published_at' => now()->subDays(abs($page['id']))->toIso8601String(), 'show_in_navigation' => false, 'navigation_order' => 0])->all();
        $selected = collect($pages)->firstWhere('slug', $story);
        $reactions = ['heart' => ['count' => 18, 'mine' => false], 'party' => ['count' => 8, 'mine' => false], 'clap' => ['count' => 12, 'mine' => false]];

        return [
            'demo' => true, 'home' => $home, 'homeId' => null, 'pages' => $pages, 'navigation' => [], 'editablePages' => [],
            'kudos' => [
                ['id' => -1, 'message' => 'You made everyone feel welcome in our first learning circle. The way you listen, encourage and make space for others is something special. Thank you!', 'from' => 'Jamie Wilson', 'to' => 'Aroha Bennett', 'to_id' => -1, 'category' => 'Living our values', 'created_at' => now()->subHours(2)->toIso8601String(), 'can_reply' => false, 'reactions' => $reactions, 'replies' => []],
                ['id' => -2, 'message' => 'A huge thank-you for bringing the whole team together for our community garden day. An afternoon full of laughter, teamwork and muddy hands!', 'from' => 'Mia Chen', 'to' => 'Sam Taylor', 'to_id' => -2, 'category' => 'Going the extra mile', 'created_at' => now()->subHours(5)->toIso8601String(), 'can_reply' => false, 'reactions' => $reactions, 'replies' => []],
            ],
            'events' => collect([['Coffee & connections', 3, 'The shared kitchen'], ['A little time to learn', 6, 'Online · everyone welcome'], ['Community garden afternoon', 10, 'Community garden']])->map(fn ($event, $i) => [
                'id' => 'demo-'.($i + 1), 'title' => $event[0], 'start' => now(config('app.worker_timezone', 'Pacific/Auckland'))->addDays($event[1])->setTime(10, 0)->toIso8601String(), 'end' => now(config('app.worker_timezone', 'Pacific/Auckland'))->addDays($event[1])->setTime(11, 0)->toIso8601String(), 'allDay' => false,
                'extendedProps' => ['eventId' => -$i - 1, 'location' => $event[2], 'description' => 'A chance to reconnect, share a little inspiration and spend time together. This is a fictional event for the Company Hub showcase.', 'createdBy' => 'People & Culture team', 'myRsvp' => null, 'recurring' => false, 'site' => null, 'recurrenceParentId' => null, 'occurrenceDate' => null],
            ])->all(),
            'milestones' => [
                'birthdays' => [['user_id' => -3, 'user_name' => 'Alex Morgan', 'type' => 'birthday', 'days_away' => 0, 'date' => now()->format('M d')]],
                'anniversaries' => [['user_id' => -4, 'user_name' => 'Leah Williams', 'type' => 'anniversary', 'days_away' => 2, 'date' => now()->addDays(2)->format('M d'), 'years' => 5]],
                'new_hires' => [['user_id' => -5, 'user_name' => 'Jordan Lee', 'type' => 'new_hire', 'days_away' => 3, 'date' => now()->addDays(3)->format('M d')]],
            ],
            'announcements' => [], 'capabilities' => ['recognise' => true, 'write' => false, 'layout' => false, 'celebrations' => false, 'calendar' => false],
            'employees' => [], 'kudosCategories' => [], 'kudosImpacts' => [], 'shortcuts' => [], 'notificationPreferences' => [], 'bookmarks' => [], 'birthdaySettings' => [], 'search' => '',
            'view' => 'home', 'participation' => null,
            'selectedPage' => $selected ? ['id' => $selected['id'], 'slug' => $selected['slug'], 'content' => [
                ...HubContent::defaults($selected['title']), 'hero_label' => $selected['category'], 'summary' => $selected['summary'],
                'comments_enabled' => false, 'reactions_enabled' => false,
                'sections' => [['id' => 'story', 'columns' => 1, 'blocks' => [
                    ['id' => 'intro', 'type' => 'text', 'title' => 'Every connection counts', 'body' => "Some of the best things happen when we make a little room for each other. A shared idea. A helping hand. A moment to stop and say thank you.\n\nThis fictional story shows how your company’s news can bring those moments to life. Add your own photographs, tell the stories that matter to your people, and give everyone a place to feel connected."],
                ]]],
            ], 'published_at' => $selected['published_at'], 'can_edit' => false] : null,
        ];
    }
}
