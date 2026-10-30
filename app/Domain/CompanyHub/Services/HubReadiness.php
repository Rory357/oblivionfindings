<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Completeness is a release gate, never a reason to discard safe saved work. */
class HubReadiness
{
    public function __construct(private readonly HrCurrentStaffService $staff) {}

    public static function issues(array $content): array
    {
        $issues = [];
        $add = function (string $field, string $code, string $message, ?string $sectionId = null, ?string $blockId = null) use (&$issues): void {
            $issues[] = ['section_id' => $sectionId, 'block_id' => $blockId, 'field' => $field, 'code' => $code, 'message' => $message];
        };
        $filled = fn ($value) => is_string($value) && trim($value) !== '';
        if (! $filled($content['title'] ?? null)) {
            $add('title', 'page_title', 'Add a clear page title before submitting or publishing.');
        }
        if (($content['hero']['style'] ?? 'brand') === 'image' && empty($content['cover_id'])) {
            $add('cover_id', 'hero_image', 'Choose a hero image or use the company background.');
        }
        if ($filled($content['hero']['action_label'] ?? null) !== $filled($content['hero']['action_url'] ?? null)) {
            $add('hero.action_url', 'hero_action', 'Add both a label and destination for the hero link.');
        }
        foreach ($content['sections'] ?? [] as $sectionIndex => $section) {
            if (! empty($section['hidden'])) {
                continue;
            }
            foreach ($section['blocks'] ?? [] as $blockIndex => $block) {
                if (! empty($block['hidden'])) {
                    continue;
                }
                $path = 'sections.'.$sectionIndex.'.blocks.'.$blockIndex;
                $issue = fn ($field, $code, $message) => $add($path.'.'.$field, $code, $message, $section['id'], $block['id']);
                if ($block['type'] !== 'featured' && ! $filled($block['title'] ?? null)) {
                    $issue('title', 'block_heading', 'Add a heading for this visible block, or hide it.');
                }
                switch ($block['type']) {
                    case 'text':
                        if (isset($block['rich_text'])) {
                            try {
                                $body = HubRichText::plainText(HubRichText::normalise($block['rich_text']));
                            } catch (InvalidArgumentException) {
                                $issue('rich_text', 'block_rich_text', 'Check the formatted text before sharing this block.');
                                break;
                            }
                            if (preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u', $body) !== 1) {
                                $issue('body', 'block_body', 'Add the visible block content, or hide this block.');
                            }
                            break;
                        }
                        // Legacy text blocks continue to use their plain body.
                    case 'notice':
                        if (! $filled($block['body'] ?? null)) {
                            $issue('body', 'block_body', 'Add the visible block content, or hide this block.');
                        }
                        break;
                    case 'image':
                    case 'file':
                        if (empty($block['media_id'])) {
                            $issue('media_id', 'block_media', 'Choose the image or file for this visible block, or hide it.');
                        }
                        break;
                    case 'video':
                        if (! $filled($block['url'] ?? null)) {
                            $issue('url', 'block_video', 'Add an approved video link, or hide this block.');
                        }
                        break;
                    case 'button':
                        if (! $filled($block['action_label'] ?? null) || ! $filled($block['url'] ?? null)) {
                            $issue('action_label', 'block_action', 'Add a button label and destination, or hide this block.');
                        }
                        break;
                    case 'links':
                        $links = $block['links'] ?? [];
                        if ($links === [] || array_filter($links, fn ($link) => ! $filled($link['title'] ?? null) || ! $filled($link['url'] ?? null))) {
                            $issue('links', 'block_links', 'Add at least one complete link, or hide this block.');
                        }
                        break;
                    case 'poll':
                        $options = $block['options'] ?? [];
                        $answers = array_map(fn ($answer) => is_string($answer) ? trim($answer) : '', $options);
                        if (! $filled($block['question'] ?? null) || count($answers) < 2 || in_array('', $answers, true) || count(array_unique($answers)) !== count($answers)) {
                            $issue('question', 'block_poll', 'Add a question and at least two different answers, or hide this block.');
                        }
                        break;
                }
                // Automatic feeds and featured fallback remain valid even when
                // their currently authorised source pool happens to be empty.
            }
        }

        return $issues;
    }

    public static function assertReady(array $content): void
    {
        $issues = self::issues($content);
        if ($issues !== []) {
            $messages = [];
            foreach ($issues as $issue) {
                $messages[$issue['field']] = $issue['message'];
            }
            throw ValidationException::withMessages($messages);
        }
    }

    /** Advisories never change completeness gates or invent governance dates. */
    public static function governanceWarnings(array $content, bool $ownerAvailable, ?string $today = null): array
    {
        $warnings = [];
        if (! $ownerAvailable) {
            $warnings[] = ['field' => 'owner_id', 'code' => 'owner_unavailable',
                'message' => 'A current contact is unavailable. Ask your manager or the Company Hub team who maintains this guidance.'];
        }
        if (empty($content['review_on'])) {
            $warnings[] = ['field' => 'review_on', 'code' => 'review_missing',
                'message' => 'No review date is set. Confirm this guidance is current with its owner.'];
        } elseif (HubReaderMetadata::overdue($content['review_on'], $today)) {
            $warnings[] = ['field' => 'review_on', 'code' => 'review_overdue',
                'message' => 'The review date has passed. Check with the owner that this guidance is current.'];
        }

        return $warnings;
    }

    public static function blockingRevision(HubPage $page): ?HubRevision
    {
        return $page->revisions()->where('state', 'submitted')->where('draft_version', $page->draft_version)->orderByDesc('id')->first()
            ?? $page->revisions()->where('state', 'approved')->where('publish_at', '>', now())->orderBy('publish_at')->orderBy('id')->first();
    }

    public static function assertEditable(HubPage $page): void
    {
        $blocking = self::blockingRevision($page);
        abort_if($blocking, 409, $blocking?->state === 'submitted'
            ? 'Withdraw the current review before changing this draft.'
            : 'Cancel the scheduled publication before changing this draft.');
    }

    public function forPage(User $actor, HubPage $page): array
    {
        $actor = $actor->fresh();
        abort_unless($actor, 404);
        $access = new HubAccess($this->staff, new UserSiteAccessService);
        $access->assertEdit($actor, $page);
        $issues = self::issues($page->draft);
        $blocking = self::blockingRevision($page);
        $owner = User::find($page->owner_id);
        $resource = $page->kind === 'knowledge' || ! empty($page->draft['resource_type'])
            || HubContent::visibleBlocks($page->draft)->contains('type', 'file');

        return [
            'actor_id' => (int) $actor->id, 'page_id' => (int) $page->id, 'version' => (int) $page->draft_version,
            'content_ready' => $issues === [], 'issues' => $issues, 'editable' => $blocking === null,
            'blocked_reason' => $blocking ? ($blocking->state === 'submitted' ? 'review' : 'schedule') : null,
            'blocking_revision_id' => $blocking && $access->canAuthorAudience($actor, $blocking->content) ? (int) $blocking->id : null,
            'governance_warnings' => $resource ? self::governanceWarnings($page->draft, $owner && $access->canEdit($owner, $page)) : [],
        ];
    }
}
