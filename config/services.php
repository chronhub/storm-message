<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * Message package wiring.
 *
 * Registers the package's services: the enrichers, registry, identity generator, and the
 * ambient holders, current message context and current stored header. Wired with autowire
 * + autoconfigure, so the declarative attributes on the implementations take effect:
 *   - #[AutoconfigureTag('storm.message_enricher', priority)] on each enricher
 *   - #[AutowireIterator('storm.message_enricher')] on EnricherRegistry
 *   - #[AsAlias(...)] on EnricherRegistry, UuidV7MetaIdentityGenerator and CurrentMessageContext
 *
 * Value objects Message, Header, and ContextValues are not services, nor are the exceptions.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Storm\\Message\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/Message.php',
            dirname(__DIR__).'/Header.php',
            dirname(__DIR__).'/ContextValues.php',
            dirname(__DIR__).'/EventType.php',
            dirname(__DIR__).'/Attribute/', // declarations, not services
            dirname(__DIR__).'/Exception/',
            dirname(__DIR__).'/Tests/',
            dirname(__DIR__).'/config/',
        ]);
};
