<?php

namespace Tests\Feature\ControlRoom;

use App\Jobs\Notifications\BroadcastDeliveryRetry;
use App\Jobs\Notifications\DeliverBroadcastCommunicationJob;
use App\Models\ControlRoom\Communication;
use App\Services\Notifications\SmsProvider;
use App\Services\Notifications\SmsSendResult;
use App\Services\Notifications\TwilioSmsProvider;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Twilio\Exceptions\EnvironmentException;
use Twilio\Exceptions\RestException;

/**
 * Broadcast texts are retried only when the provider definitely did not take
 * them, and are never sent twice (Stephan, 24 Oct 2026).
 */
class BroadcastTextSentOnceTest extends TestCase
{
    use RefreshDatabase;

    /** A provider that answers from a script and counts every send. */
    private function provider(array $answers): SmsProvider
    {
        $provider = new class($answers) implements SmsProvider
        {
            public int $calls = 0;

            public function __construct(private array $answers) {}

            public function send(string $to, string $message): SmsSendResult
            {
                $answer = $this->answers[min($this->calls, count($this->answers) - 1)];
                $this->calls++;
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        };
        $this->app->instance(SmsProvider::class, $provider);

        return $provider;
    }

    private function text(array $overrides = []): Communication
    {
        return Communication::create(array_merge([
            'channel' => 'sms',
            'direction' => 'outbound',
            'purpose' => 'broadcast',
            'target_phone' => '+64210000000',
            'content' => 'Power cut at Aurora House.',
            'status' => 'pending',
            'sent_at' => now(),
        ], $overrides));
    }

    private function deliver(Communication $text, int $attempt = 1): void
    {
        $job = new DeliverBroadcastCommunicationJob((int) $text->id);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $job->setJob($queueJob);
        $job->handle();
    }

    public function test_a_text_the_provider_did_not_take_is_retried_then_sent_once(): void
    {
        $provider = $this->provider([
            SmsSendResult::retryLater('Twilio error 20429: Too Many Requests'),
            SmsSendResult::sent('SM1'),
        ]);
        $text = $this->text();

        try {
            $this->deliver($text, 1);
            $this->fail('The first try should ask the queue to retry.');
        } catch (BroadcastDeliveryRetry) {
        }
        $text->refresh();
        $this->assertSame('pending', $text->status);
        $this->assertStringStartsWith('Not sent yet, trying again', (string) $text->status_detail);

        $this->deliver($text, 2);
        $this->assertSame('delivered', $text->refresh()->status);

        // Any later run (a duplicate job) does nothing.
        $this->deliver($text, 3);
        $this->assertSame(2, $provider->calls);
        $this->assertSame(2, (int) $text->refresh()->retry_count);
    }

    public function test_the_last_try_records_a_failure_instead_of_retrying(): void
    {
        $provider = $this->provider([SmsSendResult::retryLater('Twilio error 20503: Service unavailable')]);
        $text = $this->text();

        $this->deliver($text, 3);

        $text->refresh();
        $this->assertSame('failed', $text->status);
        $this->assertStringContainsString('(tried 3 times)', (string) $text->status_detail);
        $this->assertSame(1, $provider->calls);
    }

    public function test_an_unclear_outcome_is_never_sent_again(): void
    {
        $provider = $this->provider([
            new RuntimeException('Operation timed out after 30000 milliseconds with 0 bytes received'),
            SmsSendResult::sent('SM2'),
        ]);
        $text = $this->text();

        $this->deliver($text, 1); // must not throw: no retry for an unclear send
        $text->refresh();
        $this->assertSame('sent', $text->status);
        $this->assertStringStartsWith('Not confirmed', (string) $text->status_detail);

        $this->deliver($text, 2);
        $this->assertSame(1, $provider->calls);
        $this->assertSame('sent', $text->refresh()->status);
    }

    public function test_a_server_error_from_the_provider_is_not_confirmed_not_retried(): void
    {
        $provider = $this->provider([SmsSendResult::unconfirmed('Twilio error 20500: Internal Server Error')]);
        $text = $this->text();

        $this->deliver($text, 1);
        $this->deliver($text, 2);

        $this->assertSame(1, $provider->calls);
        $this->assertStringStartsWith('Not confirmed', (string) $text->refresh()->status_detail);
    }

    public function test_a_text_already_being_sent_is_never_sent_by_a_second_job(): void
    {
        $provider = $this->provider([SmsSendResult::sent('SM3')]);
        $text = $this->text(['status' => 'sent', 'status_detail' => DeliverBroadcastCommunicationJob::SENDING]);

        $this->deliver($text, 1);

        $this->assertSame(0, $provider->calls);
    }

    public function test_twilio_answers_are_sorted_into_retry_failed_and_unconfirmed(): void
    {
        $twilio = new TwilioSmsProvider('AC123', 'token', '+6400000000');
        $rest = new \ReflectionMethod($twilio, 'resultForRestError');
        $connection = new \ReflectionMethod($twilio, 'resultForConnectionError');

        $this->assertTrue($rest->invoke($twilio, new RestException('Too Many Requests', 20429, 429))->retryable);
        $this->assertTrue($rest->invoke($twilio, new RestException('Service Unavailable', 20503, 503))->retryable);
        $this->assertTrue($rest->invoke($twilio, new RestException('Internal Server Error', 20500, 500))->unconfirmed);
        $invalid = $rest->invoke($twilio, new RestException('Invalid To number', 21211, 400));
        $this->assertFalse($invalid->retryable);
        $this->assertFalse($invalid->unconfirmed);

        $this->assertTrue($connection->invoke($twilio, new EnvironmentException('Failed to connect to api.twilio.com port 443: Connection refused'))->retryable);
        $this->assertTrue($connection->invoke($twilio, new EnvironmentException('Could not resolve host: api.twilio.com'))->retryable);
        $this->assertTrue($connection->invoke($twilio, new EnvironmentException('Operation timed out after 30000 milliseconds with 0 bytes received'))->unconfirmed);
    }
}
