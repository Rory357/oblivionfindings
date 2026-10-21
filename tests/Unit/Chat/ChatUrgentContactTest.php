<?php

use App\Services\Chat\ChatSettings;
use App\Services\Chat\ChatUrgentContact;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

abstract class ChatUrgentContactUnitTestCase extends TestCase
{
    private mixed $previousValidator = null;

    private mixed $previousContainerValidator = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousValidator = Validator::getFacadeRoot();
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        Validator::swap($factory);
        // ValidationException::withMessages() resolves the validator from the container.
        $this->previousContainerValidator = Container::getInstance()->bound('validator') ? Container::getInstance()->make('validator') : null;
        Container::getInstance()->instance('validator', $factory);
    }

    protected function tearDown(): void
    {
        if ($this->previousContainerValidator) {
            Container::getInstance()->instance('validator', $this->previousContainerValidator);
        } else {
            Container::getInstance()->forgetInstance('validator');
        }
        if ($this->previousValidator) {
            Validator::swap($this->previousValidator);
        } else {
            Validator::clearResolvedInstance('validator');
        }
        parent::tearDown();
    }
}

uses(ChatUrgentContactUnitTestCase::class);

it('publishes only an explicitly confirmed service contact with a safe dial link', function () {
    $service = new ChatUrgentContact;
    $value = $service->validate(['label' => ' Service on-call ', 'phone' => ' +64 (9) 555-0100 ', 'instructions' => ' Call for urgent service support. ', 'confirmed' => true]);
    expect($value)->toBe(['label' => 'Service on-call', 'phone' => '+64 (9) 555-0100', 'instructions' => 'Call for urgent service support.', 'confirmed' => true]);
    expect($service->publicContact($value))->toBe(['label' => 'Service on-call', 'phone' => '+64 (9) 555-0100', 'instructions' => 'Call for urgent service support.', 'phone_uri' => 'tel:+6495550100']);
    expect($service->publicContact(null))->toBeNull();
});

it('rejects unapproved or unsafe service contact configuration and withholds malformed saved contacts', function (array $changes) {
    $value = array_replace(['label' => 'Service', 'phone' => '0800 555 0100', 'instructions' => '', 'confirmed' => true], $changes);
    $service = new ChatUrgentContact;
    expect($service->publicContact($value))->toBeNull();
    expect(fn () => $service->validate($value))->toThrow(ValidationException::class);
})->with([
    'not confirmed' => [['confirmed' => false]],
    'blank label' => [['label' => '   ']],
    'URL instead of phone' => [['phone' => 'https://example.test/private']],
    'dial control characters' => [['phone' => '0800 555 0100;123']],
    'short number' => [['phone' => '111']],
    'too many digits' => [['phone' => '1234567890123456']],
    'unexpected private fields' => [['internal_note' => 'Not for families']],
]);

it('inherits or explicitly removes the urgent contact using canonical site policy', function () {
    $contact = ['label' => 'Service', 'phone' => '0800 555 0100', 'instructions' => '', 'confirmed' => true];
    $settings = ['urgent_contact' => $contact, 'site_overrides' => ['8' => ['urgent_contact' => null], '9' => ['response_expectation' => 'Reply here']]];
    expect(ChatSettings::effective($settings, 8)['urgent_contact'])->toBeNull();
    expect(ChatSettings::effective($settings, 9)['urgent_contact'])->toBe($contact);
    expect(ChatSettings::effective([], 8)['urgent_contact'])->toBeNull();
});
