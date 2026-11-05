<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Application;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationConfig;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationMetadata;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRequirement;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(IntegrationConfig::class)]
#[CoversClass(IntegrationRequirement::class)]
#[CoversClass(IntegrationStatus::class)]
#[CoversClass(IntegrationRegistry::class)]
final class IntegrationStatusTest extends TestCase
{
    public function testTheEnvironmentVariableBehindAConfiguredValueIsFound(): void
    {
        $parameters = new EnvPlaceholderParameterBag();
        $builder = new ContainerBuilder($parameters);

        self::assertSame('TEAMLEADER_CLIENT_ID', IntegrationConfig::envName($builder, $parameters->get('env(TEAMLEADER_CLIENT_ID)')));
        self::assertSame('MAILCHIMP_API_KEY', IntegrationConfig::envName($builder, $parameters->get('env(trim:MAILCHIMP_API_KEY)')), 'Also behind a processor.');
        self::assertNull(IntegrationConfig::envName($builder, 'abc123-us21'), 'A value written in the configuration itself.');
        self::assertNull(IntegrationConfig::envName($builder, null));
    }

    public function testAConfigurationValueSaysWhereToSetIt(): void
    {
        $missing = IntegrationRequirement::config('Client ID', '', 'TEAMLEADER_CLIENT_ID', 'acme.client_id');
        self::assertFalse($missing->met, 'Empty counts as missing.');
        self::assertSame('eekes_form_builder.requirement.set_env', $missing->hint);
        self::assertSame(['%env%' => 'TEAMLEADER_CLIENT_ID'], $missing->parameters);

        $set = IntegrationRequirement::config('Client ID', 'abc', 'TEAMLEADER_CLIENT_ID', 'acme.client_id');
        self::assertTrue($set->met);
        self::assertSame('eekes_form_builder.requirement.from_env', $set->hint);

        $inConfig = IntegrationRequirement::config('Client ID', null, null, 'acme.client_id');
        self::assertSame('eekes_form_builder.requirement.set_config', $inConfig->hint);
        self::assertSame(['%path%' => 'acme.client_id'], $inConfig->parameters);
    }

    public function testTheStatusFollowsItsRequirements(): void
    {
        $met = new IntegrationRequirement('A', true);
        $notMet = new IntegrationRequirement('B', false, 'Do B.');

        self::assertTrue(IntegrationStatus::fromRequirements([$met], 'Ready.', 'Not ready.')->isReady());

        $status = IntegrationStatus::fromRequirements([$met, $notMet], 'Ready.', 'Not ready.');
        self::assertFalse($status->isReady());
        self::assertSame('Not ready.', $status->message);
        self::assertSame([$met, $notMet], $status->requirements);
    }

    public function testAnIntegrationWhoseEnvironmentVariableDoesNotExistSaysWhich(): void
    {
        $registry = new IntegrationRegistry(
            new ServiceLocator(['crm' => static fn () => throw new EnvNotFoundException('Environment variable not found: "CRM_TOKEN".')]),
            ['crm' => new IntegrationMetadata('crm', 'CRM', 'su-industry')],
            ['crm'],
        );

        $status = $registry->status('crm');

        self::assertFalse($status->isReady());
        self::assertSame('eekes_form_builder.integration_not_buildable', $status->message);
        self::assertSame('CRM_TOKEN', $status->requirements[0]->label);
        self::assertSame(['%env%' => 'CRM_TOKEN'], $status->requirements[0]->parameters);
        self::assertNull($registry->find('crm'), 'The admin pages keep working without it.');
    }
}
