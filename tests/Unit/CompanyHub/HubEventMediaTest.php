<?php

use App\Domain\CompanyHub\Services\HubEventMedia;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('event photo references bound actual ids and staged upload indices without coercion', function () {
    foreach (['file:1', 'file:2147483647', 'library:2', 'upload:0', 'upload:9'] as $ref) {
        expect(HubEventMedia::validReference($ref))->toBeTrue();
    }
    expect(HubEventMedia::validReference('file:-2', true))->toBeTrue();
    foreach ([null, 2, [], 'file:0', 'file:-1', 'file:01', 'file:2147483648', 'file:'.str_repeat('9', 50), 'upload:10', 'upload:-1', 'library:0', 'library:01', 'media:2', 'file:1\n'] as $ref) {
        expect(HubEventMedia::validReference($ref))->toBeFalse();
    }
});

test('event media validates bounded lists versions and strings before copying any sources', function () {
    $rules = (new HubEventMedia)->rules(true);
    expect(Validator::make(['actor_id' => 1, 'hub_library_images' => [['media_id' => 2, 'version' => 3]],
        'hub_content' => ['recap_title' => null, 'photo_details' => [['ref' => 'file:-4', 'title' => null, 'caption' => 'Retained description']]]], $rules)->passes())->toBeTrue();
    foreach ([
        ['hub_library_images' => [['media_id' => 1, 'version' => 1]]],
        ['actor_id' => 1, 'hub_library_images' => [['media_id' => 1, 'version' => 0]]],
        ['actor_id' => 1, 'hub_library_images' => [['media_id' => 2147483648, 'version' => 1]]],
        ['actor_id' => 1, 'hub_library_images' => [['media_id' => 1, 'version' => 2147483648]]],
        ['actor_id' => 1, 'hub_library_images' => [['media_id' => 1, 'version' => 1], ['media_id' => 1, 'version' => 2]]],
        ['actor_id' => 1, 'hub_library_images' => ['chosen' => ['media_id' => 1, 'version' => 1]]],
        ['hub_content' => ['photo_details' => ['chosen' => ['ref' => 'file:1']]]],
        ['hub_content' => ['photo_details' => [['ref' => 'file:1', 'url' => '/private/source']]]],
        ['hub_content' => ['photo_details' => [['ref' => 'file:1'], ['ref' => 'file:1']]]],
        ['hub_content' => ['gallery_refs' => ['chosen' => 'file:1']]],
        ['hub_content' => ['gallery_refs' => array_map(fn ($id) => 'file:'.$id, range(1, 13))]],
        ['hub_content' => ['cover_ref' => 'file:0']],
        ['hub_content' => ['recap_title' => str_repeat('a', 161)]],
        ['hub_content' => ['photo_details' => [['ref' => 'file:1', 'caption' => str_repeat('a', 2001)]]]],
        ['actor_id' => 1, 'hub_library_images' => array_map(fn ($id) => ['media_id' => $id, 'version' => 1], range(1, 11))],
    ] as $input) {
        expect(Validator::make($input, $rules)->fails())->toBeTrue();
    }
});

test('materialised photo defaults preserve deliberate empty captions and normalize every reference', function () {
    $input = ['cover_ref' => 'library:5', 'gallery_refs' => ['upload:0', 'library:5'],
        'photo_details' => [['ref' => 'library:5', 'caption' => '', 'title' => 'Authored heading']]];
    $copies = [5 => ['ref' => 'file:-12', 'details' => ['title' => 'Library title', 'alt' => 'Actual description', 'caption' => 'Library caption', 'album' => 'Team moments']]];
    $result = HubEventMedia::materialiseContent($input, $copies);
    expect($result['cover_ref'])->toBe('file:-12')->and($result['gallery_refs'])->toBe(['upload:0', 'file:-12'])
        ->and($result['photo_details'])->toBe([['ref' => 'file:-12', 'title' => 'Authored heading', 'alt' => 'Actual description', 'caption' => '', 'album' => 'Team moments']])
        ->and($input['cover_ref'])->toBe('library:5');
    $published = HubEventMedia::mapReferences($result, fn ($ref) => $ref === 'file:-12' ? 'upload:1' : $ref);
    expect($published['photo_details'][0]['ref'])->toBe('upload:1')->and($published['gallery_refs'])->toBe(['upload:0', 'upload:1']);
});

test('metadata patches retain omitted older fields and following copies remap only actual retained photos', function () {
    $previous = ['photo_details' => [1 => ['title' => 'Earlier title', 'alt' => 'Earlier alt', 'caption' => 'Keep this', 'album' => 'Earlier album'],
        2 => ['caption' => 'Removed image'], 3 => ['caption' => 'Date-owned image']]];
    $resolve = fn ($ref) => (int) substr($ref, 5);
    $metadata = HubEventMedia::storedDetails(['photo_details' => [['ref' => 'file:9', 'caption' => null]]], $previous, [9, 3], $resolve, [1 => 9]);
    expect($metadata[9])->toBe(['title' => 'Earlier title', 'alt' => 'Earlier alt', 'caption' => '', 'album' => 'Earlier album'])
        ->and($metadata[3]['caption'])->toBe('Date-owned image')->and($metadata)->not->toHaveKey(2)
        ->and($previous['photo_details'][1]['caption'])->toBe('Keep this');
    $legacy = HubEventMedia::storedDetails([], $previous, [1, 3], $resolve);
    expect($legacy[1]['caption'])->toBe('Keep this')->and($legacy)->not->toHaveKey(2);
});

test('gallery projection uses authored order and removes missing and duplicate attachment identities', function () {
    $photos = collect([3 => (object) ['id' => 3], 7 => (object) ['id' => 7], 9 => (object) ['id' => 9]]);
    expect(HubEventMedia::orderedPhotos([9, 3, 999, 9], $photos)->pluck('id')->all())->toBe([9, 3]);
    expect(HubEventMedia::materialiseContent(['agenda' => 'Keep it'], []))->toBe(['agenda' => 'Keep it']);
    expect(HubEventMedia::details(['caption' => null, 'alt' => '  Actual photo  ', 'source_media_id' => 55]))
        ->toBe(['title' => '', 'alt' => 'Actual photo', 'caption' => '', 'album' => '']);
});

test('saved drafts normalize nullable additions retain omitted choices and drop only removed old photo details', function () {
    $previous = ['recap_title' => 'Keep the recap heading', 'photo_details' => [
        ['ref' => 'file:-1', 'title' => 'Keep the photo title', 'alt' => 'Keep the description', 'caption' => 'Old caption', 'album' => 'An album'],
        ['ref' => 'file:-2', 'caption' => 'Removed photo'],
    ]];
    $result = HubEventMedia::mergeDraftContent(['photo_details' => [['ref' => 'file:-1', 'caption' => null]]], $previous, fn ($ref) => $ref === 'file:-1');
    expect($result['recap_title'])->toBe('Keep the recap heading')->and($result['photo_details'])->toBe([
        ['ref' => 'file:-1', 'title' => 'Keep the photo title', 'alt' => 'Keep the description', 'caption' => '', 'album' => 'An album'],
    ]);
    expect(HubEventMedia::mergeDraftContent(['recap_title' => null], [], fn () => true))->toBe(['recap_title' => '']);
    expect(HubEventMedia::editorDetails([1 => ['caption' => 'Retained'], 2 => ['caption' => 'Unavailable']], collect([1 => (object) ['id' => 1]])))
        ->toBe([['ref' => 'file:1', 'title' => '', 'alt' => '', 'caption' => 'Retained', 'album' => '']]);
});

test('metadata patching cannot grow persisted photo details beyond the bounded authored list', function () {
    $rows = array_map(fn ($id) => ['ref' => 'file:'.$id, 'caption' => 'Existing'], range(1, 100));
    expect(fn () => HubEventMedia::mergeDraftContent(['photo_details' => [['ref' => 'file:101', 'caption' => 'Added']]],
        ['photo_details' => $rows], fn () => true))->toThrow(ValidationException::class);
    $stored = array_combine(range(1, 100), array_fill(0, 100, ['caption' => 'Existing']));
    expect(fn () => HubEventMedia::storedDetails(['photo_details' => [['ref' => 'file:101', 'caption' => 'Added']]],
        ['photo_details' => $stored], range(1, 101), fn ($ref) => (int) substr($ref, 5)))->toThrow(ValidationException::class);
});
