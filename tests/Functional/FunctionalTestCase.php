<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionProvider;
use Eekes\Sulu\FormBuilderBundle\Application\Form\HandledFormRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRun;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldMapping;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormStructure;
use Eekes\Sulu\FormBuilderBundle\Tests\Application\EventListener\EventRecorder;
use Eekes\Sulu\FormBuilderBundle\Tests\Application\Integration\FakeCrmIntegration;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\RecordingHttpClient;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;

/**
 * Boots the test application - Sulu's own test kernel plus this bundle - against a real database.
 *
 * The schema is built from the entity metadata rather than from a migration: the bundle ships no
 * migrations, because the project that installs it generates its own.
 */
abstract class FunctionalTestCase extends SuluTestCase
{
    private static bool $schemaCreated = false;

    protected function setUp(): void
    {
        // A test that created a client first keeps the kernel of that client, so the test and the
        // requests it makes share one container.
        if (!static::$booted) {
            self::bootKernel();
        }

        if (!self::$schemaCreated) {
            $entityManager = self::entityManager();
            $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
            $schemaTool = new SchemaTool($entityManager);
            $schemaTool->dropSchema($metadata);
            $schemaTool->createSchema($metadata);
            self::$schemaCreated = true;
        }

        static::purgeDatabase();
        self::recorder()->reset();
        self::service(FakeCrmIntegration::class)->requests = [];
        self::service(FakeCrmIntegration::class)->behaviour = 'ok';
        self::service(RecordingHttpClient::class)->requests = [];
        self::service(RecordingHttpClient::class)->status = 200;
        self::service(HandledFormRegistry::class)->reset();
        self::service(FormDefinitionProvider::class)->reset();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    protected static function entityManager(): EntityManagerInterface
    {
        return self::service(EntityManagerInterface::class);
    }

    protected static function recorder(): EventRecorder
    {
        return self::service(EventRecorder::class);
    }

    /**
     * A form with the structure as the admin stores it (see FormStructure::fromArray()).
     *
     * @param list<array<string, mixed>> $steps
     */
    protected static function createForm(string $key, array $steps, ?\Closure $configure = null): Form
    {
        $form = new Form($key, ucfirst($key));
        $form->setStructure(FormStructure::fromArray(['steps' => $steps]));

        if ($configure !== null) {
            $configure($form);
        }

        self::entityManager()->persist($form);
        self::entityManager()->flush();

        return $form;
    }

    /**
     * A one-step contact form: name (half width), e-mail (half width, shown in the list) and a
     * message.
     */
    protected static function createContactForm(?\Closure $configure = null): Form
    {
        return self::createForm('contact', [[
            'key' => 'step1',
            'titles' => ['en' => 'Contact'],
            'fields' => [
                ['key' => 'name', 'type' => 'text', 'required' => true, 'width' => ['desktop' => 6], 'translations' => ['en' => ['label' => 'Name']]],
                ['key' => 'email', 'type' => 'email', 'required' => true, 'showInList' => true, 'width' => ['desktop' => 6], 'translations' => ['en' => ['label' => 'E-mail']]],
                ['key' => 'message', 'type' => 'textarea', 'translations' => ['en' => ['label' => 'Message']]],
            ],
        ]], $configure);
    }

    protected static function createNotification(Form $form, string $to, ?\Closure $configure = null): Notification
    {
        $notification = new Notification($form, 'Team');
        $notification->to = $to;
        $notification->setSubject('en', 'New message from {{ name }}');
        $notification->setBody('en', '<p>{{ name }} wrote:</p>');

        if ($configure !== null) {
            $configure($notification);
        }

        self::entityManager()->persist($notification);
        self::entityManager()->flush();

        return $notification;
    }

    /**
     * @param array<string, array<string, mixed>> $mapping target => ['source' => 'field', 'field' => ...] or ['source' => 'value', 'value' => ...]
     * @param array<string, mixed> $settings
     */
    protected static function createFlow(Form $form, string $integration, string $action, array $mapping, array $settings = [], ?ConditionSet $conditions = null, string $name = 'Flow'): Flow
    {
        $flow = new Flow($form, $name, $integration, $action);
        $flow->settings = $settings;
        $flow->setMapping(array_filter(array_map(static fn (array $source): ?FieldMapping => FieldMapping::fromArray($source), $mapping)));
        $flow->setConditions($conditions);

        self::entityManager()->persist($flow);
        self::entityManager()->flush();

        return $flow;
    }

    protected static function definition(Form $form, string $locale = 'en'): FormDefinition
    {
        $definition = self::service(FormDefinitionProvider::class)->getById($form->id, $locale);
        self::assertNotNull($definition);

        return $definition;
    }

    /**
     * @return list<IntegrationRun>
     */
    protected static function runs(): array
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(IntegrationRun::class)->findBy([], ['id' => 'ASC']);
    }

    /**
     * Reads the database every time, so two calls around a submit differ.
     *
     * @return list<Submission>
     *
     * @phpstan-impure
     */
    protected static function submissions(): array
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(Submission::class)->findBy([], ['id' => 'ASC']);
    }
}
