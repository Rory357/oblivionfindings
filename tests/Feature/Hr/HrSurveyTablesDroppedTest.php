<?php

use Illuminate\Support\Facades\Schema;

test('the 4 standalone HrSurvey tables have been dropped', function () {
    expect(Schema::hasTable('hr_surveys'))->toBeFalse();
    expect(Schema::hasTable('hr_survey_questions'))->toBeFalse();
    expect(Schema::hasTable('hr_survey_responses'))->toBeFalse();
    expect(Schema::hasTable('hr_survey_answers'))->toBeFalse();
});

test('the 4 HrSurvey model files have been removed', function () {
    // class_exists() is unreliable in the copied-vendor worktree (cached classmap).
    foreach (['HrSurvey', 'HrSurveyQuestion', 'HrSurveyResponse', 'HrSurveyAnswer'] as $model) {
        expect(file_exists(app_path("Domain/Hr/Models/{$model}.php")))->toBeFalse();
    }
});

test('the HrSurveyFactory has been removed', function () {
    expect(file_exists(database_path('factories/Hr/HrSurveyFactory.php')))->toBeFalse();
});

test('the drop migration is reversible (defines a down that recreates the tables)', function () {
    $path = database_path('migrations/2026_06_16_000002_drop_hr_survey_tables.php');
    expect(file_exists($path))->toBeTrue();

    $migration = require $path;
    expect(method_exists($migration, 'down'))->toBeTrue();
});
