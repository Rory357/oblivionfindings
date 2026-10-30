<?php

use App\Domain\CompanyHub\Services\HubContent;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;

test('malformed poll closing payloads return field validation errors before canonical lookups', function () {
    // Exercise the real validator without application boot, persistence or a database.
    $previousContainer = Container::getInstance();
    $previousFacade = Facade::getFacadeApplication();
    $container = new Container;
    $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Container::setInstance($container);
    Facade::clearResolvedInstance('validator');
    Facade::setFacadeApplication($container);
    try {
        foreach ([['unexpected' => 'time'], ['2026-10-21T17:00:00Z']] as $invalid) {
            $content = HubContent::defaults();
            $content['sections'] = [['id' => 'main', 'columns' => 1, 'blocks' => [
                ['id' => 'poll', 'type' => 'poll', 'title' => 'Have your say', 'question' => 'Which activity?', 'options' => ['Walk', 'Lunch'], 'closes_at' => $invalid],
            ]]];
            try {
                (new HubContent)->validate($content, new User);
                $this->fail('A malformed closing payload must not reach time parsing or persistence.');
            } catch (ValidationException $error) {
                expect($error->errors())->toHaveKey('sections.0.blocks.0.closes_at');
            }
        }
    } finally {
        Facade::clearResolvedInstance('validator');
        Facade::setFacadeApplication($previousFacade);
        Container::setInstance($previousContainer);
    }
});
