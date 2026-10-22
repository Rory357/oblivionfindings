<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralDocumentRequest;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Staff messages from a referral (specification §9 and §12): an approved template, sent by email or
 * text to the enquiry's own contact or to a reviewed, current contact in People involved, only where
 * that recipient's saved contact preferences allow it.
 *
 * The same contact-safety check runs when the message is composed, when it is queued and again at
 * delivery, so a preference changed in between always wins. Messages are queued in the referral
 * outbox inside the referral command; ReferralDelivery sends them after its claim commits and never
 * before the outbox row's `available_at`. A recipient with a safe contact time is messaged at the time
 * staff choose next to it: the free-text safe time can't be read reliably by the system.
 */
final class ReferralMessaging
{
    /** The enquiry's own contact, as the person or as the referrer. People involved use `contact:{id}`. */
    public const ENQUIRY_RECIPIENTS = ['person', 'referrer'];

    public const RECIPIENT_PATTERN = '/\A(person|referrer|contact:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\z/';

    /** A scheduled message is sent within this many days. */
    public const SCHEDULE_MAX_DAYS = 30;

    public const RELATIONSHIPS = [
        'self' => 'Contacted us themselves',
        'family' => 'Family or whānau',
        'supporter' => 'Supporter',
        'professional' => 'Professional referrer',
        'general' => 'General enquiry',
    ];

    /** People involved roles (ReferralPeopleContacts::ROLES), as staff read them. */
    public const ROLES = [
        'family' => 'Family or whānau',
        'supporter' => 'Supporter',
        'advocate' => 'Advocate',
        'representative' => 'Representative',
        'referrer' => 'Referrer',
        'professional' => 'Professional',
        'other' => 'Other contact',
    ];

    /** Plain-language reasons. The codes are also recorded on messages that could not be sent. */
    public const REASONS = [
        'settings_not_published' => 'Publish the referral settings first, so messages use your organisation’s name and phone number.',
        'referral_closed' => 'This referral is closed. Reopen it before sending a message.',
        'contact_not_permitted' => 'There is no current permission to contact them. Review contact authority first.',
        'no_contact' => 'No contact details were recorded with this referral.',
        'person_contact_missing' => 'Only the referrer’s contact details were given, so the person can’t be messaged directly.',
        'no_separate_referrer' => 'They contacted us themselves, so there is no separate referrer.',
        'contact_unavailable' => 'This contact was withdrawn or archived, so they can’t be messaged.',
        'contact_review_needed' => 'Their contact review isn’t current. Review them in People involved first.',
        'contact_permission_missing' => 'Contact with them isn’t permitted. Review their contact permission in People involved.',
        'no_direct_contact' => 'They asked not to be contacted directly.',
        'messages_not_permitted' => 'They haven’t agreed to messages. Call them and log the contact instead.',
        'phone_messages_not_permitted' => 'They haven’t agreed to messages being left on their phone, so texts aren’t sent.',
        'email_not_preferred' => 'Their safe way to be contacted is by phone, so emails aren’t sent.',
        'sms_not_preferred' => 'Their safe way to be contacted is by email, so texts aren’t sent.',
        'email_missing' => 'No valid email address is recorded.',
        'mobile_missing' => 'No phone number is recorded.',
        'not_mobile' => 'This number doesn’t look like a mobile, so it can’t receive texts.',
        'email_not_configured' => 'Email sending isn’t set up yet. Ask your system administrator.',
        'sms_not_configured' => 'Text messaging isn’t set up yet. Ask your system administrator.',
        'coordinator_missing' => 'Assign a coordinator first. This template names them.',
        'provider_phone_missing' => 'Add your phone number in Settings › Referrals first. This template uses it.',
        'disclose_needed' => 'Sending a secure upload link needs permission to request information.',
        'document_request_needed' => 'Create a secure document request first. This template sends its link.',
        'template_unavailable' => 'This template was turned off or changed. Choose another one.',
        'recipient_changed' => 'Their contact details or preferences changed after this was queued. Check them before sending a new message.',
        'safe_time_changed' => 'Their safe contact time changed after this was queued. Check it and send a new message at a time that suits.',
        'sender_access_changed' => 'The person who queued this can no longer send messages for this referral.',
        'document_request_unavailable' => 'The secure document request was received, revoked or has expired. Create a new request and message.',
        'sms_rejected' => 'The text service didn’t accept the message. Check the number with the person.',
    ];

    public function __construct(
        private readonly ReferralAccess $access,
        private readonly ReferralCommand $command,
        private readonly ReferralMessageTransport $transport,
        private readonly ReferralSettings $settings,
    ) {}

    /** The configuration messages are written with: the published settings in effect now. */
    public function configuration(): ?array
    {
        $effective = $this->settings->effective();

        return $effective ? ReferralSettings::presentable($effective->configuration) : null;
    }

    public static function isPeopleContact(string $key): bool
    {
        return str_starts_with($key, 'contact:');
    }

    /**
     * Who a recipient is, with their own saved preferences. The enquiry's own contact is the person
     * when they contacted us themselves, otherwise the referrer. A People involved contact must be
     * reviewed and current, with contact permission and a saved channel.
     *
     * @return array{key: string, kind: string, audience: string, role: string, role_label: ?string, name: ?string, first_name: ?string, relationship: ?string, contact: array, reason: ?string}
     */
    public function recipient(CareReferral $referral, string $key): array
    {
        if (self::isPeopleContact($key)) {
            return $this->peopleContact($referral, substr($key, 8), $key);
        }
        $contact = $referral->intake['submitted_contact'] ?? [];
        $relationship = $referral->intake['relationship'] ?? null;
        $self = $relationship === 'self';
        $name = trim((string) ($contact['name'] ?? ''));
        $identity = $referral->identity ?? [];
        $first = $key === 'person'
            ? (trim((string) ($identity['preferred_name'] ?? '')) ?: trim((string) ($identity['first_name'] ?? '')) ?: strtok($name, ' '))
            : strtok($name, ' ');
        $reason = match (true) {
            $contact === [] || $name === '' => 'no_contact',
            $key === 'person' && ! $self => 'person_contact_missing',
            $key === 'referrer' && $self => 'no_separate_referrer',
            default => null,
        };

        return ['key' => $key, 'kind' => 'enquiry', 'audience' => $key, 'role' => $key, 'role_label' => $key === 'person' ? 'The person' : 'Referrer',
            'name' => $name !== '' ? $name : null, 'first_name' => $first !== false && $first !== '' ? mb_substr($first, 0, 60) : null,
            'relationship' => self::RELATIONSHIPS[$relationship] ?? null, 'contact' => $reason ? [] : $contact, 'reason' => $reason];
    }

    /** A People involved contact. Withdrawn and archived contacts are never offered. */
    private function peopleContact(CareReferral $referral, string $id, string $key): array
    {
        $record = $referral->intake['people_contacts'][$id] ?? null;
        $details = $record['details'] ?? [];
        $name = trim((string) ($details['name'] ?? ''));
        $role = (string) ($details['role'] ?? 'other');
        $first = strtok($name, ' ');
        $reason = match (true) {
            ! $record || in_array($details['state'] ?? '', ['withdrawn', 'archived'], true) => 'contact_unavailable',
            ! app(ReferralPeopleContacts::class)->current($referral, $record) => 'contact_review_needed',
            ($details['channel'] ?? 'none') === 'none' => 'no_direct_contact',
            ! ($details['may_contact'] ?? false) => 'contact_permission_missing',
            default => null,
        };
        $relationship = implode(' · ', array_filter([trim((string) ($details['relationship'] ?? '')), trim((string) ($details['organisation'] ?? ''))]));

        return ['key' => $key, 'kind' => 'contact', 'audience' => $role === 'referrer' ? 'referrer' : 'contact', 'role' => $role,
            'role_label' => self::ROLES[$role] ?? 'Other contact', 'name' => $name !== '' ? $name : null,
            'first_name' => $first !== false && $first !== '' ? mb_substr($first, 0, 60) : null, 'relationship' => $relationship !== '' ? $relationship : null,
            'contact' => $reason ? [] : $details, 'reason' => $reason];
    }

    /** People involved the actor may see, current or not; withdrawn and archived contacts are left out. */
    public function peopleContactKeys(User $actor, CareReferral $referral): array
    {
        if (! $this->access->audience($actor, 'assessment')) {
            return [];
        }

        return array_values(array_map(fn (array $contact) => 'contact:'.$contact['id'], array_filter(
            $referral->intake['people_contacts'] ?? [],
            fn (array $contact) => ! in_array($contact['details']['state'] ?? '', ['withdrawn', 'archived'], true),
        )));
    }

    /** A New Zealand or international mobile in E.164 form, or null. NZ landlines can't receive texts. */
    public static function mobile(?string $phone): ?string
    {
        $number = preg_replace('/[\s().-]/', '', (string) $phone);
        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        } elseif (str_starts_with($number, '0')) {
            $number = '+64'.substr($number, 1);
        }
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $number)) {
            return null;
        }

        return str_starts_with($number, '+64') && ! preg_match('/^\+642\d{7,9}$/', $number) ? null : $number;
    }

    /** The address a message on this channel would go to, normalised. */
    public function address(CareReferral $referral, string $key, string $channel): ?string
    {
        $contact = $this->recipient($referral, $key)['contact'];
        if ($channel === 'email') {
            $email = trim((string) ($contact['email'] ?? ''));

            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }

        return self::mobile($contact['phone'] ?? null);
    }

    /** The recipient's saved safe contact time, or null when they gave none. */
    public function safeTime(CareReferral $referral, string $key): ?string
    {
        $time = trim((string) ($this->recipient($referral, $key)['contact']['safe_time'] ?? ''));

        return $time !== '' ? $time : null;
    }

    /** Why the referral can't be messaged at all right now. */
    public function referralBlocker(CareReferral $referral): ?string
    {
        return match (true) {
            $this->configuration() === null => 'settings_not_published',
            $referral->stage === 'closed' => 'referral_closed',
            ! ReferralAssessment::scope($referral, 'contact') => 'contact_not_permitted',
            default => null,
        };
    }

    /**
     * The contact-safety check for one recipient and channel, against the referral as it is now.
     *
     * The enquiry form asks whether it is safe to leave a message at all, so that answer covers email
     * and texts. People involved record contact permission plus, for phone contacts only, whether a
     * message may be left: email needs the contact permission, texts need both.
     */
    public function channelBlocker(CareReferral $referral, string $key, string $channel): ?string
    {
        if ($reason = $this->referralBlocker($referral)) {
            return $reason;
        }
        $recipient = $this->recipient($referral, $key);
        if ($recipient['reason']) {
            return $recipient['reason'];
        }
        $contact = $recipient['contact'];
        $people = $recipient['kind'] === 'contact';
        $messages = (bool) ($contact['leave_message'] ?? false);
        if (! $people && ! $messages) {
            return 'messages_not_permitted';
        }
        $preferred = $contact['channel'] ?? null;
        if ($channel === 'email') {
            if ($preferred !== 'email') {
                return 'email_not_preferred';
            }
            if ($this->address($referral, $key, 'email') === null) {
                return 'email_missing';
            }
        } else {
            if ($preferred !== 'phone') {
                return 'sms_not_preferred';
            }
            if ($people && ! $messages) {
                return 'phone_messages_not_permitted';
            }
            if (! filled($contact['phone'] ?? null)) {
                return 'mobile_missing';
            }
            if ($this->address($referral, $key, 'sms') === null) {
                return 'not_mobile';
            }
        }

        return $this->transport->configured($channel) ? null : $channel.'_not_configured';
    }

    /** Why a template can't be used on this referral by this person. Its merge fields decide what it needs. */
    public function templateBlocker(User $actor, CareReferral $referral, array $template, array $configuration): ?string
    {
        $fields = ReferralMessageTemplates::fields(($template['subject'] ?? '').' '.$template['body']);

        return match (true) {
            in_array('coordinator_name', $fields, true) && ! $referral->owner_id => 'coordinator_missing',
            in_array('contact_phone', $fields, true) && ! filled($configuration['public']['contact_phone'] ?? null) => 'provider_phone_missing',
            in_array('document_link', $fields, true) && ! $this->access->can($actor, 'disclose') => 'disclose_needed',
            in_array('document_link', $fields, true) && $this->openDocumentRequests($actor, $referral) === [] => 'document_request_needed',
            default => null,
        };
    }

    /**
     * Open secure document requests whose link can still be used and whose information the actor may see.
     *
     * @return list<array{id: int, reference: string, title: string, recipient: string, expires_at: string}>
     */
    public function openDocumentRequests(User $actor, CareReferral $referral): array
    {
        $requests = app(ReferralDocumentRequests::class);

        return ReferralDocumentRequest::query()->where('referral_id', $referral->id)->where('state', 'pending')->where('expires_at', '>', now())
            ->orderByDesc('id')->limit(20)->get()
            ->filter(fn (ReferralDocumentRequest $request) => $this->access->audience($actor, $request->audience)
                && filled($request->credentials['token'] ?? null) && $requests->summary($request, $referral)['state'] === 'pending')
            ->map(fn (ReferralDocumentRequest $request) => ['id' => $request->id, 'reference' => $request->reference, 'title' => $request->details['title'],
                'recipient' => $request->details['recipient'], 'expires_at' => $request->expires_at->toISOString()])
            ->values()->all();
    }

    /** The secure upload link for an open request, or null once it is received, revoked, expired or locked. */
    public function documentLink(CareReferral $referral, int $requestId): ?string
    {
        $request = ReferralDocumentRequest::query()->where('referral_id', $referral->id)->find($requestId);
        $token = $request?->credentials['token'] ?? null;
        if (! $request || ! is_string($token) || app(ReferralDocumentRequests::class)->summary($request, $referral)['state'] !== 'pending') {
            return null;
        }

        return self::linkBase().$token;
    }

    public static function linkBase(): string
    {
        return rtrim((string) config('app.url'), '/').ReferralMessageTemplates::LINK_PATH;
    }

    /** Merge values for this referral. Names, the reference and the provider's own details only. */
    public function values(CareReferral $referral, array $recipient, array $configuration): array
    {
        return [
            'first_name' => $recipient['first_name'] ?? '',
            'reference' => $referral->reference,
            'provider_name' => (string) ($configuration['public']['provider_name'] ?? ''),
            'coordinator_name' => (string) ($referral->owner?->name ?? ''),
            'contact_phone' => (string) ($configuration['public']['contact_phone'] ?? ''),
            'response_hours' => (string) ($configuration['public']['response_hours'] ?? ''),
        ];
    }

    /** Everything the Send a message dialog needs, limited to what the actor may use. */
    public function compose(User $actor, CareReferral $referral): array
    {
        $configuration = $this->configuration();
        $blocked = $this->referralBlocker($referral);
        $recipients = array_map(function (string $key) use ($referral): array {
            $recipient = $this->recipient($referral, $key);
            $contact = $recipient['contact'];
            $shown = $recipient['reason'] !== 'contact_unavailable';

            return [
                'key' => $key, 'kind' => $recipient['kind'], 'audience' => $recipient['audience'], 'role_label' => $recipient['role_label'],
                'name' => $shown && ($recipient['kind'] === 'contact' || ! $recipient['reason']) ? $recipient['name'] : null,
                'first_name' => $recipient['reason'] ? null : $recipient['first_name'],
                'relationship' => $recipient['relationship'], 'reason' => $recipient['reason'] ? self::REASONS[$recipient['reason']] : null,
                'preferences' => $recipient['reason'] ? null : [
                    'channel' => $contact['channel'] ?? null, 'leave_message' => (bool) ($contact['leave_message'] ?? false),
                    'safe_time' => filled($contact['safe_time'] ?? null) ? $contact['safe_time'] : null,
                    'communication' => filled($contact['communication'] ?? null) ? $contact['communication'] : null,
                ],
                'channels' => array_combine(ReferralMessageTemplates::CHANNELS, array_map(function (string $channel) use ($referral, $key, $recipient, $contact): array {
                    $reason = $this->channelBlocker($referral, $key, $channel);
                    $address = $channel === 'email' ? ($contact['email'] ?? null) : ($contact['phone'] ?? null);

                    return ['address' => $recipient['reason'] || ! filled($address) ? null : $address, 'reason' => $reason ? self::REASONS[$reason] : null];
                }, ReferralMessageTemplates::CHANNELS)),
            ];
        }, [...self::ENQUIRY_RECIPIENTS, ...$this->peopleContactKeys($actor, $referral)]);
        $templates = $configuration ? array_values(array_map(function (array $template) use ($actor, $referral, $configuration): array {
            $reason = $this->templateBlocker($actor, $referral, $template, $configuration);

            return [...$template, 'fields' => array_values(array_unique(ReferralMessageTemplates::fields(($template['subject'] ?? '').' '.$template['body']))),
                'reason' => $reason ? self::REASONS[$reason] : null];
        }, array_filter(ReferralMessageTemplates::templates($configuration), fn (array $template) => $template['enabled']))) : [];

        return [
            'version' => $referral->lock_version,
            'blocked' => $blocked ? self::REASONS[$blocked] : null,
            'recipients' => $recipients,
            'templates' => $templates,
            // The recipient's first name comes with each recipient above.
            'values' => $configuration ? array_diff_key($this->values($referral, [], $configuration), ['first_name' => true]) : null,
            'senders' => ['email' => $this->transport->sender('email'), 'sms' => $this->transport->sender('sms')],
            'document_requests' => $this->access->can($actor, 'disclose') ? $this->openDocumentRequests($actor, $referral) : [],
            'link_length' => strlen(self::linkBase()) + 64,
            'limits' => ['sms_parts' => ReferralMessageTemplates::SMS_MAX_PARTS, 'edit_allowance' => ReferralMessageTemplates::EDIT_ALLOWANCE,
                'email_body' => ReferralMessageTemplates::EMAIL_BODY_MAX, 'schedule_days' => self::SCHEDULE_MAX_DAYS],
        ];
    }

    /** Queue a message: checked, rendered and recorded in one referral command; sent later by ReferralDelivery. */
    public function queue(User $actor, CareReferral $referral, array $input): array
    {
        $data = Validator::make($input, [
            'version' => 'required|integer|min:1', 'request_key' => 'required|uuid',
            'recipient' => ['required', 'string', 'regex:'.self::RECIPIENT_PATTERN], 'channel' => ['required', Rule::in(ReferralMessageTemplates::CHANNELS)],
            'template_id' => 'required|string|max:64', 'body' => 'required|string|max:'.(ReferralMessageTemplates::EMAIL_BODY_MAX + ReferralMessageTemplates::EDIT_ALLOWANCE),
            'document_request_id' => 'present|nullable|integer|min:1', 'send_at' => 'present|nullable|string|max:40',
        ])->validate();
        $data['body'] = str_replace("\r\n", "\n", $data['body']);
        $sendAt = filled($data['send_at']) ? ReferralDates::parse($data['send_at'], 'send_at')->utc()->startOfMinute() : null;
        $hash = hash('sha256', json_encode([$data['recipient'], $data['channel'], $data['template_id'], $data['body'], $data['document_request_id'], $sendAt?->toISOString()], JSON_THROW_ON_ERROR));
        $key = 'staff_message:'.$referral->id.':'.$data['request_key'];

        return $this->command->run($actor, $referral->id, $data['version'], 'communications.send', function (CareReferral $current, User $actor) use ($data, $hash, $key, $sendAt): array {
            $configuration = $this->configuration();
            abort_if($configuration === null, 422, self::REASONS['settings_not_published']);
            // People involved are assessment information: only staff who can see them may message them.
            abort_if(self::isPeopleContact($data['recipient']) && ! $this->access->audience($actor, 'assessment'), 403);
            $recipient = $this->recipient($current, $data['recipient']);
            $template = ReferralMessageTemplates::find($configuration, $data['template_id']);
            abort_unless($template && $template['enabled'] && $template['channel'] === $data['channel'] && $template['audience'] === $recipient['audience'], 422, self::REASONS['template_unavailable']);
            if ($reason = $this->channelBlocker($current, $data['recipient'], $data['channel'])) {
                abort(422, self::REASONS[$reason]);
            }
            if ($reason = $this->templateBlocker($actor, $current, $template, $configuration)) {
                abort(422, self::REASONS[$reason]);
            }
            $safeTime = $this->safeTime($current, $data['recipient']);
            // The safe time is free text, so staff choose when it is sent; the outbox holds it until then.
            abort_if($safeTime !== null && $sendAt === null, 422, 'Choose when to send it, to suit their safe contact time: “'.$safeTime.'”.');
            if ($sendAt !== null) {
                abort_if($sendAt->lt(now()->subMinutes(2)->startOfMinute()), 422, 'Choose a time that hasn’t passed yet.');
                abort_if($sendAt->gt(now()->addDays(self::SCHEDULE_MAX_DAYS)), 422, 'Choose a time within the next '.self::SCHEDULE_MAX_DAYS.' days.');
            }
            $this->checkEdit($template, $data['body']);
            $link = null;
            $request = null;
            if (in_array('document_link', ReferralMessageTemplates::fields($template['body']), true)) {
                abort_unless(in_array((int) $data['document_request_id'], array_column($this->openDocumentRequests($actor, $current), 'id'), true), 422, 'Choose an open secure document request for the link.');
                $request = ReferralDocumentRequest::query()->where('referral_id', $current->id)->lockForUpdate()->findOrFail($data['document_request_id']);
                $link = $this->documentLink($current, $request->id);
                abort_if($link === null, 422, self::REASONS['document_request_unavailable']);
            }
            $values = $this->values($current, $recipient, $configuration);
            $subject = $data['channel'] === 'email' ? ReferralMessageTemplates::render($template['subject'], $values) : null;
            // The secure link stays a merge field until delivery, so its token is never stored with the message.
            $body = ReferralMessageTemplates::render($data['body'], $values);
            if ($data['channel'] === 'sms') {
                $parts = ReferralMessageTemplates::smsParts(ReferralMessageTemplates::render($body, ['document_link' => $link ?? '']));
                abort_if($parts > ReferralMessageTemplates::SMS_MAX_PARTS, 422, 'This text would take '.$parts.' parts. Shorten it to '.ReferralMessageTemplates::SMS_MAX_PARTS.' parts or fewer.');
            }
            $scheduled = $sendAt !== null && $sendAt->isFuture();
            $row = ReferralOutbox::query()->create([
                'referral_id' => $current->id, 'deduplication_key' => $key, 'kind' => 'staff_message', 'state' => 'pending',
                // Delivery never sends before this time.
                'available_at' => $scheduled ? $sendAt : now(),
                'message' => [
                    'channel' => $data['channel'], 'recipient' => $data['recipient'], 'recipient_kind' => $recipient['kind'], 'recipient_role' => $recipient['role'],
                    'recipient_name' => $recipient['name'], 'address' => $this->address($current, $data['recipient'], $data['channel']), 'safe_time' => $safeTime,
                    'template_id' => $template['id'], 'template_label' => $template['label'], 'subject' => $subject, 'body' => $body,
                    'edited' => $data['body'] !== str_replace("\r\n", "\n", $template['body']), 'document_request_id' => $request?->id,
                    'scheduled_for' => $scheduled ? $sendAt->toISOString() : null, 'sender_id' => $actor->id, 'request_hash' => $hash,
                ],
            ]);
            // History records what was sent, to whom and in what role, never the address, the message text
            // or the secure link. Messages to People involved stay within the assessment audience.
            $when = $scheduled ? ', to send '.$sendAt->setTimezone('Pacific/Auckland')->format('j M Y, g:i a').' NZ time' : '';
            $this->command->record($current, $actor, 'communication.queued', $recipient['kind'] === 'contact' ? 'assessment' : 'intake', [
                'outbox_id' => $row->id, 'template' => $template['label'], 'template_id' => $template['id'], 'channel' => $data['channel'],
                'recipient' => $recipient['kind'] === 'contact' ? 'people_contact' : $data['recipient'], 'recipient_role' => $recipient['role_label'],
                'recipient_name' => $recipient['name'],
                'summary' => '“'.$template['label'].'” by '.($data['channel'] === 'sms' ? 'text' : 'email').' to '.$recipient['name'].' ('.$recipient['role_label'].')'.$when,
                'edited' => $row->message['edited'], 'scheduled_for' => $row->message['scheduled_for'], 'document_request_reference' => $request?->reference,
            ]);

            return ['saved' => true, 'communication_id' => $row->id, 'scheduled_for' => $row->message['scheduled_for']];
        }, [], function (CareReferral $current, User $actor) use ($key, $hash): ?array {
            $row = ReferralOutbox::query()->where('referral_id', $current->id)->where('deduplication_key', $key)->first();
            if (! $row) {
                return null;
            }
            abort_unless((int) ($row->message['sender_id'] ?? 0) === (int) $actor->id && hash_equals((string) ($row->message['request_hash'] ?? ''), $hash), 409, 'This request was already used for a different message. Refresh before sending another.');

            return ['saved' => true, 'communication_id' => $row->id, 'scheduled_for' => $row->message['scheduled_for'] ?? null];
        });
    }

    /** Staff may reword a template a little; its merge fields stay exactly as they are. */
    private function checkEdit(array $template, string $body): void
    {
        abort_if(trim($body) === '', 422, 'Write the message.');
        abort_if(ReferralMessageTemplates::strayBraces($body), 422, 'Use braces only around a merge field, like {first_name}.');
        $expected = ReferralMessageTemplates::fields($template['body']);
        $actual = ReferralMessageTemplates::fields($body);
        sort($expected);
        sort($actual);
        abort_unless($expected === $actual, 422, 'Keep the merge fields as they are'.($expected ? ': {'.implode('}, {', array_unique($expected)).'}' : '').'. They are filled in for you.');
        abort_if(mb_strlen($body) > mb_strlen($template['body']) + ReferralMessageTemplates::EDIT_ALLOWANCE, 422, 'Keep edits short: add no more than '.ReferralMessageTemplates::EDIT_ALLOWANCE.' characters to the template.');
        abort_if(mb_strlen($body) > ReferralMessageTemplates::EMAIL_BODY_MAX, 422, 'Shorten the message.');
    }

    /**
     * The delivery-time check for a queued staff message, under the referral lock. Revoked access,
     * changed contact details, preferences or safe time, and a used secure link all stop the send.
     */
    public function deliveryBlocker(ReferralOutbox $row, CareReferral $referral): ?string
    {
        $message = $row->message;
        $key = (string) ($message['recipient'] ?? '');
        $sender = User::query()->find($message['sender_id'] ?? 0);
        if (! $sender || ! $sender->approved_at || ! $this->access->can($sender, 'communications.send') || ! $this->access->query($sender)->whereKey($referral->id)->exists()
            || (self::isPeopleContact($key) && ! $this->access->audience($sender, 'assessment'))) {
            return 'sender_access_changed';
        }
        if ($reason = $this->channelBlocker($referral, $key, $message['channel'])) {
            return $reason;
        }
        if ($this->address($referral, $key, $message['channel']) !== ($message['address'] ?? null)) {
            return 'recipient_changed';
        }
        if ($this->safeTime($referral, $key) !== ($message['safe_time'] ?? null)) {
            return 'safe_time_changed';
        }
        if (! empty($message['document_request_id']) && $this->documentLink($referral, (int) $message['document_request_id']) === null) {
            return 'document_request_unavailable';
        }

        return null;
    }

    /** The message as it will be sent, with the secure link filled in. Held in memory only. */
    public function prepare(ReferralOutbox $row, CareReferral $referral): ?array
    {
        $message = $row->message;
        $link = null;
        if (! empty($message['document_request_id'])) {
            $link = $this->documentLink($referral, (int) $message['document_request_id']);
            if ($link === null) {
                return null;
            }
        }

        return ['channel' => $message['channel'], 'address' => $message['address'], 'subject' => $message['subject'] ?? null,
            'body' => ReferralMessageTemplates::render($message['body'], $link ? ['document_link' => $link] : []), 'key' => $row->id.'-'.$row->attempts];
    }

    /** Send outside any transaction. Null when the service accepted it, otherwise a failure code. Exceptions mean "unknown". */
    public function send(array $prepared): ?string
    {
        if ($prepared['channel'] === 'sms') {
            return $this->transport->sendSms($prepared['address'], $prepared['body'])->sent ? null : 'sms_rejected';
        }

        return $this->transport->sendEmail($prepared['address'], (string) $prepared['subject'], $prepared['body'], $prepared['key']) ? null : 'transport_suppressed';
    }

    /**
     * A sent staff message is a contact attempt (Stephan, 21 Oct 2026): it sets the first contact
     * attempt at its sent time, which reports and working-time targets read. It is never a conversation,
     * so it never sets first human contact. Caller holds the referral lock.
     */
    public static function recordAttempt(int $referralId, CarbonImmutable $sentAt): void
    {
        CareReferral::query()->whereKey($referralId)
            ->where(fn ($query) => $query->whereNull('first_contact_attempt_at')->orWhere('first_contact_attempt_at', '>', $sentAt))
            ->update(['first_contact_attempt_at' => $sentAt]);
    }

    /** The message as staff see it in the list: the secure link is shown as a placeholder. */
    public static function display(?string $text): ?string
    {
        return $text === null ? null : str_replace('{document_link}', '[secure upload link]', $text);
    }
}
