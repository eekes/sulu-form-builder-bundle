<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Unit\Infrastructure;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\FormAdmin;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Content\SingleFormSelectionPropertyResolver;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\FieldTypeController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\FormController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\IntegrationConnectionController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\NotificationController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\NotificationDeliveryController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\SpamController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\SubmissionController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\DependencyInjection\EekesSuluFormBuilderExtension;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Twig\FormComponent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(EekesSuluFormBuilderExtension::class)]
final class ContainerWiringTest extends TestCase
{
    public function testTheWebsitePartWorksWithoutTheSuluAdminOrContentBundles(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->hasDefinition(FieldTypeRegistry::class));
        self::assertFalse($container->hasDefinition(FormAdmin::class));
        self::assertFalse($container->hasDefinition(SingleFormSelectionPropertyResolver::class));
        self::assertFalse($container->hasDefinition(FormComponent::class));
    }

    public function testEachIntegrationIsLoadedWithItsBundle(): void
    {
        $container = $this->load(['SuluAdminBundle' => 'x', 'SuluContentBundle' => 'x', 'TwigComponentBundle' => 'x']);

        self::assertTrue($container->hasDefinition(FormAdmin::class));
        self::assertTrue($container->hasDefinition(SingleFormSelectionPropertyResolver::class));
        self::assertTrue($container->hasDefinition(FormComponent::class));
    }

    public function testTheConfigurationBecomesParameters(): void
    {
        $container = $this->load([], ['sender' => ['email' => 'forms@example.com'], 'rendering' => ['column_classes' => 'mb-4']]);

        self::assertSame('forms@example.com', $container->getParameter('eekes_sulu_form_builder.sender.email'));
        self::assertNull($container->getParameter('eekes_sulu_form_builder.sender.name'));
        self::assertSame('mb-4', $container->getParameter('eekes_sulu_form_builder.rendering.column_classes'));
        self::assertFalse($container->getParameter('eekes_sulu_form_builder.csrf_protection'), 'Cached pages cannot carry a valid token.');
        self::assertSame('submitted', $container->getParameter('eekes_sulu_form_builder.success_parameter'));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function controllers(): iterable
    {
        yield 'forms' => [FormController::class];
        yield 'field types' => [FieldTypeController::class];
        yield 'notifications' => [NotificationController::class];
        yield 'submissions' => [SubmissionController::class];
        yield 'spam' => [SpamController::class];
        yield 'notification deliveries' => [NotificationDeliveryController::class];
        yield 'integration connections' => [IntegrationConnectionController::class];
    }

    /**
     * Autoconfigured controllers would also be published by a project's "routing.controllers"
     * import, without the /admin/api prefix and outside the admin firewall; see
     * config/services_sulu_admin.php.
     *
     * @param class-string $controller
     */
    #[DataProvider('controllers')]
    public function testAdminControllersAreNeverAutoconfigured(string $controller): void
    {
        $definition = $this->load(['SuluAdminBundle' => 'x'])->getDefinition($controller);

        self::assertFalse($definition->isAutoconfigured());
        self::assertTrue($definition->hasTag('container.service_subscriber'), 'Without it, AbstractController never gets its container.');
        self::assertTrue($definition->hasTag('controller.service_arguments'));
    }

    /**
     * @param array<string, string> $bundles
     * @param array<string, mixed> $config
     */
    private function load(array $bundles, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', $bundles);
        $container->setParameter('kernel.project_dir', '/app');

        (new EekesSuluFormBuilderExtension())->load([$config], $container);

        return $container;
    }
}
