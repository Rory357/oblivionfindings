<?php

namespace App\Domain\CompanyHub\Services;

class HubTemplates
{
    public const KEYS = ['story', 'leadership', 'guide', 'team', 'welcome', 'information', 'campaign', 'blank'];

    public static function sections(string $template): array
    {
        $text = fn ($id, $title) => ['id' => $id, 'type' => 'text', 'title' => $title, 'body' => ''];
        $links = fn ($id, $title) => ['id' => $id, 'type' => 'links', 'title' => $title, 'links' => []];

        return match ($template) {
            'blank' => [],
            'leadership' => [
                ['id' => 'priorities', 'title' => 'Our shared priorities', 'columns' => 1, 'blocks' => [$text('context', 'A note to our people'), $text('progress', 'What we are working towards')]],
                ['id' => 'next', 'title' => 'What comes next', 'columns' => 2, 'blocks' => [$text('next-steps', 'Our next steps'), $links('resources', 'Explore the details')]],
            ],
            'information' => [
                ['id' => 'introduction', 'title' => 'Introduction', 'columns' => 1, 'blocks' => [$text('about', 'About this page')]],
                ['id' => 'information', 'title' => 'Useful information', 'columns' => 2, 'layout' => 'wide-left', 'blocks' => [$text('details', 'What you need to know'), $links('links', 'Useful links')]],
            ],
            'campaign' => [
                ['id' => 'purpose', 'title' => 'Our shared focus', 'columns' => 1, 'blocks' => [$text('purpose', 'Why this matters')]],
                ['id' => 'participate', 'title' => 'Get involved', 'columns' => 2, 'layout' => 'wide-left', 'blocks' => [$text('steps', 'How to take part'), $links('activities', 'Activities and resources')]],
                ['id' => 'updates', 'title' => 'Latest updates', 'columns' => 1, 'blocks' => [['id' => 'updates', 'type' => 'news', 'title' => 'Campaign updates', 'limit' => 3]]],
            ],
            'guide' => [
                ['id' => 'intro', 'columns' => 1, 'blocks' => [$text('about', 'Everything you need to know')]],
                ['id' => 'essentials', 'columns' => 2, 'blocks' => [$text('steps', 'Getting started'), $links('tools', 'Helpful links')]],
                ['id' => 'help', 'columns' => 1, 'blocks' => [['id' => 'people', 'type' => 'people', 'title' => 'Need a hand?']]],
            ],
            'team' => [
                ['id' => 'intro', 'columns' => 1, 'blocks' => [$text('about', 'Meet our team')]],
                ['id' => 'contacts', 'title' => 'Your team contacts', 'columns' => 1, 'blocks' => [['id' => 'contacts', 'type' => 'people', 'title' => 'People who can help', 'limit' => 6]]],
                ['id' => 'team-life', 'columns' => 2, 'blocks' => [
                    ['id' => 'thanks', 'type' => 'shoutouts', 'title' => 'The people making a difference', 'limit' => 3],
                    $links('tools', 'Our everyday essentials'),
                ]],
                ['id' => 'upcoming', 'columns' => 1, 'blocks' => [['id' => 'events', 'type' => 'events', 'title' => 'Coming together', 'limit' => 3]]],
            ],
            'welcome' => [
                ['id' => 'welcome', 'columns' => 1, 'blocks' => [$text('intro', 'Welcome aboard')]],
                ['id' => 'first-week', 'columns' => 2, 'blocks' => [$text('start', 'Your first week'), $links('links', 'Start here')]],
                ['id' => 'people', 'columns' => 1, 'blocks' => [['id' => 'directory', 'type' => 'people', 'title' => 'Your people']]],
            ],
            default => [['id' => 'story', 'columns' => 1, 'blocks' => [$text('body', 'The story')]]],
        };
    }
}
