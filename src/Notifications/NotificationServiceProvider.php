<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\Dkim\DkimPublicKeyResolver;
use Infocyph\TalkingBytes\Email\Dkim\DkimVerifier;
use Infocyph\TalkingBytes\Email\Dkim\DnsDkimPublicKeyResolver;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMailboxFactory;
use Infocyph\TalkingBytes\Email\EmailReceiverFactory;
use Infocyph\TalkingBytes\Email\EmailSenderFactory;
use Infocyph\TalkingBytes\Email\Parser\AuthenticationResultsParser;
use Infocyph\TalkingBytes\Email\Parser\BounceParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

final class NotificationServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $app = $this->application($builder, $context);
        $container = $builder->development();

        $this->bindFactory($container, NotificationTemplateRegistry::class, fn() => new NotificationTemplateRegistry(
            $app->config(),
        ), LifetimeEnum::Singleton);

        $mailAvailable = class_exists(Emailer::class);
        if ($mailAvailable) {
            $this->registerMail($app);
        }

        $this->bindFactory($container, NotificationChannelRegistry::class, fn() => new NotificationChannelRegistry(
            config: $app->config(),
            mail: $mailAvailable ? $app->make(MailNotificationChannel::class) : null,
            resolver: fn(string $service): mixed => $app->make($service),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, NotificationDispatcher::class, fn() => new NotificationDispatcher(
            $app->make(NotificationChannelRegistry::class),
        ), LifetimeEnum::Singleton);
        $container->alias('foundation.notifications', NotificationDispatcher::class, LifetimeEnum::Singleton);
    }

    private function emailLimits(\Infocyph\Foundation\Application\Application $app): EmailLimits
    {
        $config = ValueNormalizer::associativeArray(
            $app->config()->get('notifications.email.parsing.limits', []),
        );

        return new EmailLimits(
            maxMessageBytes: ValueNormalizer::int($config['maxMessageBytes'] ?? 10 * 1024 * 1024, 10 * 1024 * 1024),
            maxAttachmentBytes: ValueNormalizer::int($config['maxAttachmentBytes'] ?? 25 * 1024 * 1024, 25 * 1024 * 1024),
            maxAttachmentCount: ValueNormalizer::int($config['maxAttachmentCount'] ?? 500, 500),
            maxDecodedBodyBytes: ValueNormalizer::int($config['maxDecodedBodyBytes'] ?? 10 * 1024 * 1024, 10 * 1024 * 1024),
            maxMimeDepth: ValueNormalizer::int($config['maxMimeDepth'] ?? 20, 20),
            maxMimeParts: ValueNormalizer::int($config['maxMimeParts'] ?? 500, 500),
            maxHeaderBytes: ValueNormalizer::int($config['maxHeaderBytes'] ?? 131072, 131072),
            maxHeaderCount: ValueNormalizer::int($config['maxHeaderCount'] ?? 2000, 2000),
        );
    }

    private function registerMail(\Infocyph\Foundation\Application\Application $app): void
    {
        $container = $app->container();

        if (!$this->hasExplicitBinding($container, EmailSenderFactory::class)) {
            $container->bind(EmailSenderFactory::class, new EmailSenderFactory(), LifetimeEnum::Singleton);
        }
        if (!$this->hasExplicitBinding($container, EmailReceiverFactory::class)) {
            $container->bind(EmailReceiverFactory::class, new EmailReceiverFactory(), LifetimeEnum::Singleton);
        }
        if (!$this->hasExplicitBinding($container, EmailMailboxFactory::class)) {
            $container->bind(EmailMailboxFactory::class, new EmailMailboxFactory(), LifetimeEnum::Singleton);
        }
        if (!$this->hasExplicitBinding($container, EmailLimits::class)) {
            $this->bindFactory(
                $container,
                EmailLimits::class,
                fn(): EmailLimits => $this->emailLimits($app),
                LifetimeEnum::Singleton,
            );
        }
        if (!$this->hasExplicitBinding($container, RawEmailParser::class)) {
            $this->bindFactory(
                $container,
                RawEmailParser::class,
                fn(): RawEmailParser => new RawEmailParser(limits: $app->make(EmailLimits::class)),
                LifetimeEnum::Singleton,
            );
        }
        if (!$this->hasExplicitBinding($container, BounceParser::class)) {
            $container->bind(BounceParser::class, new BounceParser(), LifetimeEnum::Singleton);
        }
        if (!$this->hasExplicitBinding($container, AuthenticationResultsParser::class)) {
            $container->bind(AuthenticationResultsParser::class, new AuthenticationResultsParser(), LifetimeEnum::Singleton);
        }
        if (!$this->hasExplicitBinding($container, DkimPublicKeyResolver::class)) {
            $container->bind(DkimPublicKeyResolver::class, new DnsDkimPublicKeyResolver(), LifetimeEnum::Singleton);
        }
        if (!$this->hasExplicitBinding($container, DkimVerifier::class)) {
            $this->bindFactory(
                $container,
                DkimVerifier::class,
                fn(): DkimVerifier => new DkimVerifier($app->make(DkimPublicKeyResolver::class)),
                LifetimeEnum::Singleton,
            );
        }

        $this->bindFactory($container, EmailProfiles::class, fn() => new EmailProfiles(
            config: $app->config(),
            paths: $app->paths(),
            senders: $app->make(EmailSenderFactory::class),
            receivers: $app->make(EmailReceiverFactory::class),
            mailboxes: $app->make(EmailMailboxFactory::class),
            parser: $app->make(RawEmailParser::class),
        ), LifetimeEnum::Singleton);

        if (!$this->hasExplicitBinding($container, Emailer::class)) {
            $this->bindFactory(
                $container,
                Emailer::class,
                fn(): Emailer => $app->make(EmailProfiles::class)->sender(),
                LifetimeEnum::Scoped,
            );
        }
        if (!$this->hasExplicitBinding($container, SpoolEmailReceiver::class)) {
            $this->bindFactory(
                $container,
                SpoolEmailReceiver::class,
                fn(): SpoolEmailReceiver => $app->make(EmailProfiles::class)->spoolReceiver(),
                LifetimeEnum::Scoped,
            );
        }

        $this->bindFactory($container, Mailer::class, fn() => new Mailer(
            $app->make(EmailProfiles::class),
            $app->config(),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, MailNotificationChannel::class, fn() => new MailNotificationChannel(
            $app->make(Mailer::class),
        ), LifetimeEnum::Singleton);

        $container->alias('foundation.notifications.emailer', Emailer::class, LifetimeEnum::Scoped);
        $container->alias('foundation.email', Mailer::class, LifetimeEnum::Singleton);
    }
}
