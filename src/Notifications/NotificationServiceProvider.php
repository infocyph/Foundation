<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
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
use Psr\Container\ContainerInterface;

final class NotificationServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);

        $builder->singleton(NotificationTemplateRegistry::class, FactoryDefinition::construct(
            NotificationTemplateRegistry::class,
            [new ServiceReference(ConfigRepository::class)],
        ));

        $mailAvailable = class_exists(Emailer::class);
        if ($mailAvailable) {
            $this->registerMail($builder);
        }

        $builder->singleton(NotificationChannelRegistry::class, FactoryDefinition::construct(
            NotificationChannelRegistry::class,
            [
                new ServiceReference(ConfigRepository::class),
                $mailAvailable ? new ServiceReference(MailNotificationChannel::class) : null,
                new ServiceReference(ContainerInterface::class),
            ],
        ));
        $builder->singleton(NotificationDispatcher::class, FactoryDefinition::construct(
            NotificationDispatcher::class,
            [new ServiceReference(NotificationChannelRegistry::class)],
        ));
        $builder->alias('foundation.notifications', NotificationDispatcher::class);
    }

    private function registerMail(ContainerBuilder $builder): void
    {
        if (!$builder->definitions()->has(EmailSenderFactory::class)) {
            $builder->singleton(EmailSenderFactory::class, FactoryDefinition::construct(EmailSenderFactory::class));
        }
        if (!$builder->definitions()->has(EmailReceiverFactory::class)) {
            $builder->singleton(EmailReceiverFactory::class, FactoryDefinition::construct(EmailReceiverFactory::class));
        }
        if (!$builder->definitions()->has(EmailMailboxFactory::class)) {
            $builder->singleton(EmailMailboxFactory::class, FactoryDefinition::construct(EmailMailboxFactory::class));
        }
        if (!$builder->definitions()->has(EmailLimits::class)) {
            $builder->singleton(EmailLimits::class, FactoryDefinition::staticFactory(
                NotificationGraphFactory::class,
                'emailLimits',
                [new ServiceReference(ConfigRepository::class)],
            ));
        }
        if (!$builder->definitions()->has(RawEmailParser::class)) {
            $builder->singleton(RawEmailParser::class, FactoryDefinition::staticFactory(
                NotificationGraphFactory::class,
                'rawEmailParser',
                [new ServiceReference(EmailLimits::class)],
            ));
        }
        if (!$builder->definitions()->has(BounceParser::class)) {
            $builder->singleton(BounceParser::class, FactoryDefinition::construct(BounceParser::class));
        }
        if (!$builder->definitions()->has(AuthenticationResultsParser::class)) {
            $builder->singleton(
                AuthenticationResultsParser::class,
                FactoryDefinition::construct(AuthenticationResultsParser::class),
            );
        }
        if (!$builder->definitions()->has(DkimPublicKeyResolver::class)) {
            $builder->singleton(
                DkimPublicKeyResolver::class,
                FactoryDefinition::construct(DnsDkimPublicKeyResolver::class),
            );
        }
        if (!$builder->definitions()->has(DkimVerifier::class)) {
            $builder->singleton(DkimVerifier::class, FactoryDefinition::construct(
                DkimVerifier::class,
                [new ServiceReference(DkimPublicKeyResolver::class)],
            ));
        }

        $builder->singleton(EmailProfiles::class, FactoryDefinition::construct(
            EmailProfiles::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(PathManager::class),
                new ServiceReference(EmailSenderFactory::class),
                new ServiceReference(EmailReceiverFactory::class),
                new ServiceReference(EmailMailboxFactory::class),
                new ServiceReference(RawEmailParser::class),
            ],
        ));

        if (!$builder->definitions()->has(Emailer::class)) {
            $builder->bind(
                Emailer::class,
                FactoryDefinition::staticFactory(
                    NotificationGraphFactory::class,
                    'emailer',
                    [new ServiceReference(EmailProfiles::class)],
                ),
                LifetimeEnum::Scoped,
            );
        }
        if (!$builder->definitions()->has(SpoolEmailReceiver::class)) {
            $builder->bind(
                SpoolEmailReceiver::class,
                FactoryDefinition::staticFactory(
                    NotificationGraphFactory::class,
                    'spoolReceiver',
                    [new ServiceReference(EmailProfiles::class)],
                ),
                LifetimeEnum::Scoped,
            );
        }

        $builder->singleton(Mailer::class, FactoryDefinition::construct(
            Mailer::class,
            [new ServiceReference(EmailProfiles::class), new ServiceReference(ConfigRepository::class)],
        ));
        $builder->singleton(MailNotificationChannel::class, FactoryDefinition::construct(
            MailNotificationChannel::class,
            [new ServiceReference(Mailer::class)],
        ));

        $builder->alias('foundation.notifications.emailer', Emailer::class, LifetimeEnum::Scoped);
        $builder->alias('foundation.email', Mailer::class);
    }
}
