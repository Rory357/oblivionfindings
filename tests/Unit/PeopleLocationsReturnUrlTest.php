<?php

use App\Exceptions\RecoverableTaskAuthorizationException;

it('preserves supported People Locations context without opening redirects', function () {
    expect(RecoverableTaskAuthorizationException::validatedReturnTo('/operations/people-locations/alerts?site=3&selected=c8&cohort=battery%3Alow'))
        ->toBe('/operations/people-locations/alerts?site=3&selected=c8&cohort=battery%3Alow');
    foreach (['https://other.test/operations/people-locations/map', '//other.test/operations/people-locations/map',
        '/operations/people-locations/export', '/operations/people-locations/../clients', '/operations/people-locations/map#private'] as $url) {
        expect(RecoverableTaskAuthorizationException::validatedReturnTo($url))->toBeNull();
    }
    expect(RecoverableTaskAuthorizationException::validatedReturnTo('/operations/people-locations/map?redirect=elsewhere&source[]=1'))->toBe('/operations/people-locations/map');
    expect(RecoverableTaskAuthorizationException::validatedReturnTo('/tasks?sources=alert&page=2'))->toBe('/tasks?sources=alert&page=2');
});
