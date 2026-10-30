<?php

namespace App\Domain\CompanyHub\Services;

/** Fictional presentation data. Never writes HR, calendar or publication records. */
class HubShowcase
{
    public static function data(?string $story = null, ?int $viewerActorId = null): array
    {
        $home = HubContent::defaults('Welcome to Company Hub');
        $home['hero_label'] = 'OUR PEOPLE. OUR STORIES.';
        $home['summary'] = 'Your company news, people and everyday essentials.';
        $links = [
            ['id' => 'handbook', 'title' => 'People & culture guide', 'url' => '/company-hub/showcase?story=people-guide', 'description' => 'The little things that make us, us'],
            ['id' => 'learning', 'title' => 'Learn something new', 'url' => '/company-hub/showcase?story=learning-together', 'description' => 'Make space for your next step'],
            ['id' => 'support', 'title' => 'Support when you need it', 'url' => '/company-hub/showcase?story=people-guide', 'description' => 'Helpful people, just a click away'],
            ['id' => 'wellbeing', 'title' => 'People & wellbeing', 'url' => '/company-hub/showcase?story=people-guide', 'description' => 'Take care of yourself and each other'],
        ];
        $home['sections'] = array_map(function (array $section) use ($links): array {
            $section['blocks'] = array_map(fn (array $block): array => match ($block['type']) {
                'news' => [...$block, 'title' => 'Company news', 'limit' => 2],
                'links' => [...$block, 'title' => 'Quick links', 'links' => $links],
                'shoutouts' => [...$block, 'title' => 'Recognition', 'limit' => 1],
                default => $block,
            }, $section['blocks']);

            return $section;
        }, $home['sections']);
        $pages = collect([
            ['id' => -1, 'slug' => 'growing-together', 'kind' => 'news', 'title' => 'Growing something good, together', 'summary' => 'An afternoon in the garden. A lasting connection with our community.', 'category' => 'In our community', 'cover_url' => '/images/company-hub/community-garden.png', 'featured' => true],
            ['id' => -2, 'slug' => 'learning-together', 'kind' => 'news', 'title' => 'Fresh ideas start with a conversation', 'summary' => 'Meet the people making our new learning circles a little more inspiring.', 'category' => 'Our people', 'cover_url' => '/images/company-hub/colleagues-together.png', 'featured' => false],
            ['id' => -3, 'slug' => 'small-moments', 'kind' => 'news', 'title' => 'Small moments. A big difference.', 'summary' => 'Celebrating the everyday acts of kindness across our teams this month.', 'category' => 'Making a difference', 'cover_url' => '/images/company-hub/community-garden.png', 'featured' => false],
            ['id' => -4, 'slug' => 'people-guide', 'kind' => 'page', 'title' => 'Your people & culture guide', 'summary' => 'A good place to start, wherever you are in your journey with us.', 'category' => 'Helpful resources', 'cover_url' => '/images/company-hub/colleagues-together.png', 'featured' => false],
            ['id' => -5, 'slug' => 'welcome-to-harbour', 'kind' => 'news', 'title' => 'Welcome to Harbour House', 'summary' => 'The people and practical details that make a first day easier.', 'category' => 'Getting started', 'cover_url' => '/images/company-hub/workplace-welcome.png', 'featured' => false, 'author' => 'Aroha Bennett'],
            ['id' => -6, 'slug' => 'one-question', 'kind' => 'news', 'title' => 'One question, thirty minutes: our first learning circle', 'summary' => 'A short conversation led to practical ideas colleagues can try straight away.', 'category' => 'Learning', 'cover_url' => '/images/company-hub/colleagues-together.png', 'featured' => false, 'author' => 'Sam Taylor'],
            ['id' => -7, 'slug' => 'room-to-connect', 'kind' => 'news', 'title' => 'Making room to connect', 'summary' => 'Meet the colleagues turning a shared space into somewhere everyone feels welcome.', 'category' => 'Working together', 'cover_url' => '/images/company-hub/workplace-welcome.png', 'featured' => false, 'author' => 'Mia Chen'],
            ['id' => -8, 'slug' => 'small-learning-step', 'kind' => 'news', 'title' => 'Your next step can start small', 'summary' => 'A few useful questions to help you make space for learning this month.', 'category' => 'Learning', 'cover_url' => '/images/company-hub/colleagues-together.png', 'featured' => false, 'author' => 'Aroha Bennett'],
            ['id' => -9, 'slug' => 'fresh-air-together', 'kind' => 'news', 'title' => 'A little fresh air, a lot of shared care', 'summary' => 'The everyday moments helping our teams feel more connected.', 'category' => 'Staff wellbeing', 'cover_url' => '/images/company-hub/community-garden.png', 'featured' => false, 'author' => 'Sam Taylor'],
            ['id' => -10, 'slug' => 'first-week-checklist', 'kind' => 'page', 'title' => 'Before they arrive: a first-week checklist for colleagues joining across more than one site', 'summary' => 'Prepare the people, places and practical details for a smooth first week.', 'category' => 'Getting started', 'collection' => 'Getting started', 'resource_type' => 'Checklist', 'owner' => 'Mia Chen', 'topics' => ['Onboarding', 'Sites']],
            ['id' => -11, 'slug' => 'who-can-help', 'kind' => 'page', 'title' => 'Who can help?', 'summary' => 'Find the right team for a workplace question, learning opportunity or practical request.', 'category' => 'Getting started', 'collection' => 'Getting started', 'resource_type' => 'Guide', 'owner' => 'Sam Taylor', 'topics' => ['Contacts', 'Getting help']],
            ['id' => -12, 'slug' => 'team-gathering', 'kind' => 'page', 'title' => 'Planning a team gathering that more people can take part in', 'summary' => 'Choose a time, place and format that work for your colleagues.', 'category' => 'Working together', 'collection' => 'Working together', 'resource_type' => 'Guide', 'owner' => 'Mia Chen', 'topics' => ['Events', 'Inclusion']],
            ['id' => -13, 'slug' => 'meeting-space', 'kind' => 'page', 'title' => 'Booking a shared meeting space', 'summary' => 'Choose a suitable space and leave it ready for the next group.', 'category' => 'Working together', 'collection' => 'Working together', 'resource_type' => 'Process', 'owner' => 'Jamie Wilson', 'topics' => ['Workplace', 'Meeting rooms']],
            ['id' => -14, 'slug' => 'learning-plan', 'kind' => 'page', 'title' => 'Make room for your next learning step', 'summary' => 'Turn a question or skill you want to practise into a small, achievable learning plan.', 'category' => 'Learning', 'collection' => 'Learning & growth', 'resource_type' => 'Guide', 'owner' => 'Aroha Bennett', 'topics' => ['Learning', 'Development']],
            ['id' => -15, 'slug' => 'host-learning-circle', 'kind' => 'page', 'title' => 'Host a short learning circle', 'summary' => 'Bring a small group together around one question and leave with a useful next step.', 'category' => 'Learning', 'collection' => 'Learning & growth', 'resource_type' => 'Checklist', 'owner' => 'Aroha Bennett', 'topics' => ['Learning', 'Working together']],
            ['id' => -16, 'slug' => 'useful-resources', 'kind' => 'page', 'title' => 'Keeping shared resources useful when the people, links or process change', 'summary' => 'Keep a named owner, a review date and a clear route for colleagues to suggest a correction.', 'category' => 'Working together', 'collection' => 'Working together', 'resource_type' => 'Process', 'owner' => 'Jamie Wilson', 'topics' => ['Resources', 'Content ownership']],
            ['id' => -20, 'slug' => 'sample-campaign', 'kind' => 'campaign', 'title' => 'A little time to learn', 'summary' => 'A fictional shared initiative: make room for one useful conversation and one small learning step.', 'category' => 'Learning & growth', 'cover_url' => '/images/company-hub/colleagues-together.png', 'owner' => 'Aroha Bennett', 'campaign_progress' => ['total' => 3, 'done' => 1]],
            ['id' => -21, 'slug' => 'sample-team', 'kind' => 'department', 'title' => 'Learning & development', 'summary' => 'Meet a fictional team helping colleagues learn, share and find their next step.', 'category' => 'Our teams', 'cover_url' => '/images/company-hub/workplace-welcome.png', 'owner' => 'Aroha Bennett'],
            ['id' => -22, 'slug' => 'sample-polls', 'kind' => 'page', 'title' => 'A little input makes a difference', 'summary' => 'Two fictional polls show an open ballot and a completed conversation.', 'category' => 'Have your say', 'collection' => 'Working together', 'resource_type' => 'Guide'],
        ])->map(fn ($page) => [
            'published_at' => now()->subDays(abs($page['id']))->toIso8601String(), 'show_in_navigation' => false, 'navigation_order' => 0,
            'cover_url' => null, 'featured' => in_array($page['id'], [-11, -14], true),
            // Illustrative metadata stays inside this explicitly fictional, read-only showcase.
            'author' => $page['id'] === -1 ? 'Mia Chen' : 'Jamie Wilson', 'owner' => 'Jamie Wilson',
            'read_minutes' => 1, 'comments_enabled' => false, 'comment_count' => 0, 'bookmarked' => false,
            'topics' => $page['id'] === -1 ? ['People & culture'] : ['Learning'],
            'team_ids' => [$page['id'] === -1 ? -11 : -10],
            'team_names' => [$page['id'] === -1 ? 'Community garden' : 'Learning & development'],
            'collection' => $page['kind'] === 'page' ? 'Getting started' : null,
            'resource_type' => $page['kind'] === 'page' ? 'Guide' : null,
            ...$page,
        ])->all();
        $selected = collect($pages)->firstWhere('slug', $story);
        $reactions = ['heart' => ['count' => 18, 'mine' => false], 'party' => ['count' => 8, 'mine' => false], 'clap' => ['count' => 12, 'mine' => false]];

        $kudos = [
            ['id' => -1, 'message' => 'You made everyone feel welcome in our first learning circle. The way you listen, encourage and make space for others is something special. Thank you!', 'from' => 'Jamie Wilson', 'to' => 'Aroha Bennett', 'to_id' => -1, 'category' => 'Living our values', 'created_at' => now()->subHours(2)->toIso8601String(), 'can_reply' => false, 'reactions' => $reactions, 'replies' => []],
            ['id' => -2, 'message' => 'A huge thank-you for bringing the whole team together for our community garden day. An afternoon full of laughter, teamwork and muddy hands!', 'from' => 'Mia Chen', 'to' => 'Sam Taylor', 'to_id' => -2, 'category' => 'Going the extra mile', 'created_at' => now()->subHours(5)->toIso8601String(), 'can_reply' => false, 'reactions' => $reactions, 'replies' => []],
        ];
        $kudos[0]['recipient_type'] = 'person';
        $kudos[0]['team_ids'] = [-10];
        $kudos[0]['team_name'] = 'Learning & development';
        $kudos[1]['recipient_type'] = 'team';
        $kudos[1]['to'] = 'Community garden team';
        $kudos[1]['to_id'] = null;
        $kudos[1]['to_department_id'] = -11;
        $kudos[1]['team_ids'] = [-11];
        $kudos[1]['team_name'] = 'Community garden';

        return self::community([
            'demo' => true, 'home' => $home, 'homeId' => null, 'pages' => $pages, 'navigation' => [], 'editablePages' => [],
            'kudos' => $kudos,
            'recognition' => ['actor_id' => 0, 'version' => 1, 'can_feature' => false, 'spotlight' => $kudos[0], 'selection_unavailable' => false],
            'interests' => ['topics' => [], 'teams' => [], 'version' => 1, 'available_topics' => [], 'available_teams' => [['id' => -10, 'name' => 'Learning & development'], ['id' => -11, 'name' => 'Community garden']]],
            'events' => self::events(),
            'milestones' => [
                'birthdays' => [['user_id' => -3, 'user_name' => 'Alex Morgan', 'type' => 'birthday', 'days_away' => 0, 'date' => now()->format('M d')]],
                'anniversaries' => [['user_id' => -4, 'user_name' => 'Leah Williams', 'type' => 'anniversary', 'days_away' => 2, 'date' => now()->addDays(2)->format('M d'), 'years' => 5]],
                'new_hires' => [['user_id' => -5, 'user_name' => 'Jordan Lee', 'type' => 'new_hire', 'days_away' => 3, 'date' => now()->addDays(3)->format('M d')]],
            ],
            'announcements' => [], 'capabilities' => ['recognise' => true, 'write' => false, 'layout' => false, 'celebrations' => false, 'calendar' => false],
            'employees' => [], 'kudosCategories' => [], 'kudosImpacts' => [], 'shortcuts' => [], 'notificationPreferences' => [], 'bookmarks' => [], 'birthdaySettings' => [], 'search' => '',
            'view' => 'home', 'participation' => null,
            'selectedPage' => $selected ? ['id' => $selected['id'], 'kind' => $selected['kind'], 'slug' => $selected['slug'], 'content' => [
                ...HubContent::defaults($selected['title']), 'category' => $selected['category'], 'hero_label' => $selected['category'], 'summary' => $selected['summary'],
                'comments_enabled' => false, 'reactions_enabled' => false,
                'sections' => [['id' => 'story', 'columns' => 1, 'blocks' => [
                    ['id' => 'intro', 'type' => 'text', 'title' => $selected['kind'] === 'news' ? 'Every connection counts' : 'Start here', 'body' => $selected['kind'] === 'news'
                        ? "Some of the best things happen when we make a little room for each other. A shared idea. A helping hand. A moment to stop and say thank you.\n\nThis fictional story shows how your company’s news can bring those moments to life. Add your own photographs, tell the stories that matter to your people, and give everyone a place to feel connected."
                        : $selected['summary']."\n\nThis is an illustrative resource for the Company Hub showcase. Your published guides can bring together practical steps, a named owner and related information, so colleagues know where to start and who can help."],
                ]]],
            ], 'published_at' => $selected['published_at'], 'can_edit' => false] : null,
        ], $viewerActorId);
    }

    private static function events(): array
    {
        $clock = now(config('app.worker_timezone', 'Pacific/Auckland'));
        $events = collect([
            ['Coffee & connections', 3, 'The shared kitchen'],
            ['A little time to learn', 6, 'Online · everyone welcome'],
            ['Community garden afternoon', 10, 'Community garden'],
            ['Growing something good, together', -7, 'Community garden'],
        ])->map(function ($event, $i) use ($clock): array {
            $start = $clock->copy()->addDays($event[1])->setTime(10, 0);
            $past = $event[1] < 0;

            return [
                'id' => 'demo-'.($i + 1), 'title' => $event[0], 'start' => $start->toIso8601String(), 'end' => $start->copy()->addHour()->toIso8601String(), 'allDay' => false, 'editable' => false,
                'extendedProps' => [
                    'eventId' => -$i - 1, 'editVersion' => 1, 'location' => $event[2], 'description' => 'A chance to reconnect, share a little inspiration and spend time together. This is a fictional event for the Company Hub showcase.', 'createdBy' => 'People & Culture team', 'myRsvp' => null, 'recurring' => false, 'site' => null, 'recurrenceParentId' => null, 'occurrenceDate' => null,
                    'category' => $past ? 'Community' : 'Learning', 'departmentId' => $i === 1 ? -10 : -11,
                    'cancellation' => ['cancelled' => false, 'cancelled_at' => null, 'reason' => null, 'actor_id' => null, 'can_cancel' => false, 'scopes' => []],
                    'hubContent' => [
                        'cover' => ['id' => -301 - $i, 'name' => $past ? 'Community garden' : 'Colleagues together', 'url' => '/images/company-hub/'.($past ? 'community-garden' : 'colleagues-together').'.png', 'alt' => $past ? 'Fictional colleagues working together in a community garden' : 'Fictional colleagues sharing a conversation'],
                        'host' => ['id' => -101, 'name' => 'Aroha Bennett', 'profile_url' => null],
                        'agenda' => "Welcome and a quick introduction.\nTime to connect and share one useful idea.",
                        'recap' => $past ? "We made room for new planting and a few good conversations. Thank you to everyone who shared their time.\n\nThese are fictional photographs and a sample recap; no real attendance is recorded." : '',
                        'recap_title' => $past ? 'Small steps, shared together' : '',
                        'gallery' => [], 'story' => $past ? ['title' => 'Read the garden day story', 'url' => '/company-hub/showcase?story=growing-together'] : null,
                        'past' => $past, 'editor' => null,
                    ],
                ],
            ];
        })->all();
        $registration = [
            'enabled' => true, 'capacity' => 8, 'version' => 1, 'going' => 8, 'attended' => 0, 'waiting' => 2, 'mine' => 'waiting', 'queue_position' => 2, 'attendance' => null,
            'can_respond' => true, 'can_decline' => true, 'can_configure' => false, 'cutoff_supported' => true, 'closes_before_minutes' => 60, 'closes_at' => $clock->copy()->addDays(3)->setTime(9, 0)->toIso8601String(),
            'cancelled' => false, 'place_reserved' => false,
            'personal_reminder' => ['supported' => true, 'enabled' => false, 'version' => 1, 'can_enable' => false, 'can_disable' => false, 'due_at' => null, 'inapp_allowed' => false],
        ];
        $events[0]['extendedProps']['registration'] = [...$registration, 'date' => substr($events[0]['start'], 0, 10)];
        $events[0]['extendedProps']['attachments'] = [[
            'id' => -501, 'name' => 'Fictional gathering photo.png', 'mime' => 'image/png',
            'size' => 2255790,
            'url' => '/images/company-hub/colleagues-together.png',
            'download_url' => '/images/company-hub/colleagues-together.png',
            'preview_url' => '/images/company-hub/colleagues-together.png',
        ]];
        $events[3]['extendedProps']['registration'] = [...$registration, 'capacity' => 20, 'going' => 3, 'attended' => 2, 'waiting' => 0, 'mine' => 'going', 'queue_position' => null, 'attendance' => 'attended', 'can_respond' => false, 'can_decline' => false, 'closes_at' => null, 'date' => substr($events[3]['start'], 0, 10)];
        $events[3]['extendedProps']['hubContent']['gallery'] = collect([
            ['community-garden', 'A little room to grow', 'Fictional colleagues tending a community garden', 'An afternoon of planting and shared care.', 'In the garden'],
            ['colleagues-together', 'Good conversations', 'Fictional colleagues spending time together', 'Taking a break to exchange ideas.', 'People together'],
            ['workplace-welcome', 'Everyone has a place', 'A welcoming fictional workplace', 'A warm welcome for everyone who joined in.', 'People together'],
        ])->map(fn ($photo, $i) => [
            'id' => -310 - $i, 'name' => $photo[0].'.png', 'url' => '/images/company-hub/'.$photo[0].'.png', 'title' => $photo[1], 'alt' => $photo[2], 'caption' => $photo[3], 'album' => $photo[4],
            'shared_by' => ['name' => 'Mia Chen', 'profile_url' => null], 'shared_at' => $clock->copy()->subDays(6)->setTime(14, 30)->toIso8601String(),
        ])->all();

        return $events;
    }

    private static function community(array $data, ?int $viewerActorId): array
    {
        $clock = now(config('app.worker_timezone', 'Pacific/Auckland'));
        $team = ['id' => -10, 'name' => 'Learning & development'];
        $people = collect([
            ['Aroha Bennett', 'Learning partner'], ['Mia Chen', 'People & culture adviser'], ['Sam Taylor', 'Learning coordinator'],
        ])->map(fn ($person, $i) => [
            'id' => -101 - $i, 'user_id' => -201 - $i, 'name' => $person[0], 'position' => $person[1], 'department' => $team['name'], 'department_id' => $team['id'], 'site' => 'Harbour House',
            'work_email' => null, 'work_phone' => null, 'first_aider' => false, 'fire_warden' => false, 'can_edit_profile' => false, 'staff_record_url' => null,
        ])->all();
        $owner = ['id' => $people[0]['user_id'], 'profile_id' => $people[0]['id'], 'name' => $people[0]['name'], 'position' => $people[0]['position'], 'url' => '/company-hub/showcase?story=sample-team'];
        $contact = array_intersect_key($people[0], array_flip(['id', 'name', 'position', 'department', 'work_email', 'work_phone']));
        $updates = collect($data['pages'])->whereIn('id', [-2, -6])->values()->all();
        $resources = collect($data['pages'])->whereIn('id', [-14, -15])->values()->all();
        $campaign = [
            'ends_on' => $clock->copy()->addDays(30)->toDateString(),
            'steps' => [
                ['id' => 'choose-a-step', 'title' => 'Choose one small learning step', 'url' => '/company-hub/showcase?story=learning-plan'],
                ['id' => 'share-a-conversation', 'title' => 'Join a learning conversation', 'url' => '/company-hub/showcase?view=events'],
            ],
            'milestones' => [
                ['id' => 'listen', 'title' => 'Listen to what colleagues need', 'status' => 'done'],
                ['id' => 'make-space', 'title' => 'Make space for learning circles', 'status' => 'active'],
                ['id' => 'share', 'title' => 'Share what we are learning', 'status' => 'next'],
            ],
            'story_ids' => array_column($updates, 'id'), 'resource_ids' => array_column($resources, 'id'), 'event_keys' => ['demo-2'],
        ];
        $data = [...$data,
            'people' => $people, 'canViewOrg' => false, 'followedPages' => [],
            'ideaActorId' => null, 'ideaProgressReady' => true, 'ideaTeams' => [$team], 'ideaOwners' => [array_diff_key($owner, ['url' => true])],
            'ideas' => [[
                'id' => -401, 'title' => 'A short learning circle for every team', 'body' => "Could we make room for a short, friendly learning circle each month?\n\nColleagues could bring one question, share what helps and leave with a practical next step. This is a fictional idea for the showcase.",
                'category' => 'Better ways of working', 'status' => 'in_progress', 'status_note' => 'A small trial is under way with the learning team.', 'version' => 3, 'author' => 'Mia Chen', 'created_at' => $clock->copy()->subDays(15)->toIso8601String(),
                'can_remove' => false, 'can_update' => false, 'support_count' => 18, 'supported' => false, 'team' => $team, 'owner' => $owner,
                'updates' => [
                    ['id' => -411, 'status' => 'reviewing', 'note' => 'We are listening to teams and choosing a simple format.', 'created_at' => $clock->copy()->subDays(10)->toIso8601String(), 'author' => ['id' => -201, 'name' => 'Aroha Bennett'], 'owner' => $owner, 'team' => $team, 'legacy' => false],
                    ['id' => -412, 'status' => 'in_progress', 'note' => 'A small trial is under way with the learning team.', 'created_at' => $clock->copy()->subDays(3)->toIso8601String(), 'author' => ['id' => -201, 'name' => 'Aroha Bennett'], 'owner' => $owner, 'team' => $team, 'legacy' => false],
                ],
                'comments' => [['id' => -421, 'body' => 'One question and a practical next step sounds like a useful place to start.', 'author' => 'Sam Taylor', 'created_at' => $clock->copy()->subDays(2)->toIso8601String(), 'can_remove' => false]],
            ]],
            'showcaseEventRosters' => ['demo-4' => [
                'actor_id' => 0, 'event_id' => -4, 'edit_version' => 1, 'registration_version' => 1, 'date' => $data['events'][3]['extendedProps']['registration']['date'], 'can_export' => false, 'can_record' => false,
                'registrations' => collect($people)->map(fn ($person, $i) => ['id' => -431 - $i, 'name' => $person['name'], 'status' => 'going', 'attendance' => $i === 2 ? 'no_show' : 'attended', 'version' => 1, 'can_offer_place' => false])->all(),
            ]],
            'campaign' => null, 'department' => null,
        ];
        $selected = $data['selectedPage'];
        if (! $selected) {
            return $data;
        }
        $data['selectedPage']['revision_id'] = $selected['id'] * 10;
        if ($selected['slug'] === 'sample-campaign') {
            $data['selectedPage']['content']['campaign'] = $campaign;
            $data['campaign'] = ['total' => 3, 'done' => 1, 'ends_on' => $campaign['ends_on'], 'milestones' => $campaign['milestones'], 'stories' => $updates, 'resources' => $resources, 'events' => [$data['events'][1]], 'owner' => $contact];
            $data['participation'] = [
                'actor_id' => 0, 'page_id' => $selected['id'], 'revision_id' => $selected['id'] * 10, 'can_moderate' => false, 'polls' => [], 'reactions' => [], 'comments' => [],
                'campaign' => collect($campaign['steps'])->map(fn ($step, $i) => [...$step, 'key' => 'showcase-'.$step['id'], 'done' => $i === 0, 'count' => $i === 0 ? 12 : 5])->all(),
            ];
        } elseif ($selected['slug'] === 'sample-team') {
            $data['selectedPage']['content']['team_ids'] = [$team['id']];
            $data['selectedPage']['content']['sections'] = [['id' => 'team-overview', 'columns' => 1, 'blocks' => [
                ['id' => 'team-introduction', 'type' => 'text', 'title' => 'A small next step, together', 'body' => 'Our fictional learning team helps colleagues turn questions into practical next steps. Explore the tabs for sample team updates, resources and people.'],
            ]]];
            $data['department'] = ['actor_id' => 0, 'page_id' => $selected['id'], 'revision_id' => $selected['id'] * 10, 'version' => 1, 'teams' => [$team], 'owner' => $contact, 'updates' => $updates, 'resources' => $resources, 'people' => $people, 'can_create_update' => false];
        } elseif ($selected['slug'] === 'sample-polls') {
            $data['selectedPage']['content']['sections'] = [['id' => 'sample-polls', 'columns' => 2, 'blocks' => [
                ['id' => 'next-circle', 'type' => 'poll', 'title' => 'Our next learning circle', 'question' => 'What would you like to explore together?', 'options' => ['Practical skills', 'Working together', 'Wellbeing'], 'closes_at' => $clock->copy()->addDays(7)->toIso8601String()],
                ['id' => 'meeting-format', 'type' => 'poll', 'title' => 'Thank you for sharing', 'question' => 'Which meeting format works best for you?', 'options' => ['In person', 'A mix of both', 'Online'], 'closes_at' => $clock->copy()->subDay()->toIso8601String()],
            ]]];
            // The current viewer only selects the production results display branch.
            // demo=true and negative publication identities never authorise a command.
            $data['participation'] = [
                'actor_id' => max(0, $viewerActorId ?? 0), 'page_id' => $selected['id'], 'revision_id' => $selected['id'] * 10, 'can_moderate' => false, 'reactions' => [], 'comments' => [],
                'polls' => [
                    'next-circle' => ['key' => 'showcase-next-circle-v1', 'total' => null, 'mine' => null, 'counts' => null, 'closed' => false, 'closes_at' => $clock->copy()->addDays(7)->toIso8601String(), 'timezone' => 'Pacific/Auckland', 'version' => 1, 'results_visible' => false, 'can_manage' => false],
                    'meeting-format' => ['key' => 'showcase-meeting-format-v1', 'total' => 10, 'mine' => 1, 'counts' => [3, 5, 2], 'closed' => true, 'closes_at' => $clock->copy()->subDay()->toIso8601String(), 'timezone' => 'Pacific/Auckland', 'version' => 1, 'results_visible' => true, 'can_manage' => false],
                ],
            ];
        }

        return $data;
    }
}
