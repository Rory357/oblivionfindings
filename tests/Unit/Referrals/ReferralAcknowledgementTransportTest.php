<?php

namespace Tests\Unit\Referrals;

use App\Domain\Referrals\Services\ReferralAcknowledgementTransport;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\TestCase;

class ReferralAcknowledgementTransportTest extends TestCase
{
    public function test_real_plaintext_rendering_uses_only_the_explicit_sender_and_minimal_receipt(): void
    {
        $this->inContainer(function (Repository $config): void {
            $transport = new ArrayTransport;
            $mailer = new Mailer('isolated-test', $this->createStub(Factory::class), $transport);
            $builder = new class($mailer)
            {
                public array $built = [];

                public function __construct(private Mailer $mailer) {}

                public function build(array $configuration): Mailer
                {
                    $this->built[] = $configuration;

                    return $this->mailer;
                }
            };
            Mail::swap($builder);
            $service = new ReferralAcknowledgementTransport;
            self::assertTrue($service->configured());
            self::assertTrue($service->send('fictional@example.test', '01M51QPTRC51DW5P388XDM2Z5F'));
            self::assertSame(30, $builder->built[0]['timeout']);
            self::assertSame('smtp', $builder->built[0]['transport']);
            self::assertSame(1, $transport->messages()->count());
            $message = $transport->messages()->first()->getOriginalMessage();
            self::assertSame('enquiries@provider.example', $message->getFrom()[0]->getAddress());
            self::assertSame('Fictional provider', $message->getFrom()[0]->getName());
            self::assertSame('fictional@example.test', $message->getTo()[0]->getAddress());
            self::assertSame('Your enquiry has been received', $message->getSubject());
            self::assertSame(ReferralAcknowledgementTransport::body('01M51QPTRC51DW5P388XDM2Z5F'), $message->getTextBody());
            self::assertNull($message->getHtmlBody());
            self::assertSame([], $message->getAttachments());
            self::assertSame([], $message->getCc());
            self::assertSame([], $message->getBcc());
            self::assertSame('referral-ack-01m51qptrc51dw5p388xdm2z5f@provider.example', $message->getHeaders()->get('Message-ID')->getId());
        });
    }

    public function test_disabled_or_non_delivery_transports_never_qualify_as_configured_mail(): void
    {
        $this->inContainer(function (Repository $config): void {
            $service = new ReferralAcknowledgementTransport;
            foreach (['log', 'array', 'failover', 'roundrobin'] as $transport) {
                $config->set('mail.mailers.referral.transport', $transport);
                self::assertFalse($service->configured());
            }
            $config->set('mail.mailers.referral.transport', 'smtp');
            $config->set('referral-delivery.from_address', null);
            self::assertFalse($service->configured());
            $config->set('referral-delivery.from_address', 'enquiries@provider.example');
            $config->set('referral-delivery.enabled', false);
            self::assertFalse($service->configured());
            $this->expectException(\LogicException::class);
            $service->send('fictional@example.test', '01M51QPTRC51DW5P388XDM2Z5F');
        });
    }

    private function inContainer(callable $test): void
    {
        $previous = Container::getInstance();
        $facades = Facade::getFacadeApplication();
        $container = new Container;
        $config = new Repository(['referral-delivery' => ['enabled' => true, 'mailer' => 'referral', 'from_address' => 'enquiries@provider.example', 'from_name' => 'Fictional provider'],
            'mail' => ['mailers' => ['referral' => ['transport' => 'smtp', 'timeout' => 600]]]]);
        $container->instance('config', $config);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        try {
            $test($config);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($facades);
            Container::setInstance($previous);
        }
    }
}
