<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Eekes\Sulu\FormBuilderBundle\Application\Command\PruneSpamCommand;
use Eekes\Sulu\FormBuilderBundle\Application\Condition\ConditionEvaluator;
use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionFactory;
use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionProvider;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\CheckboxFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\ChoiceFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\CountryFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\DateFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\DateTimeFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\EmailFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\FileFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\HeadingFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\HiddenFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\NumberFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\PhoneFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\RatingFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\RepeaterFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TextareaFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TextBlockFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TextFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\TimeFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\UrlFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\Form\BootstrapColumnClassResolver;
use Eekes\Sulu\FormBuilderBundle\Application\Form\ColumnClassResolverInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Form\FieldKeyRenamer;
use Eekes\Sulu\FormBuilderBundle\Application\Form\FormFlowFactory;
use Eekes\Sulu\FormBuilderBundle\Application\Form\HandledFormRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\FormBuilderFlowType;
use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\RatingType;
use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\RepeaterRowType;
use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\RepeaterType;
use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\StaticContentType;
use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\StepType;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\FlowValidator;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\FlowValueMapper;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRunner;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\RetryPolicy;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\Webhook\WebhookIntegration;
use Eekes\Sulu\FormBuilderBundle\Application\Notification\NotificationSender;
use Eekes\Sulu\FormBuilderBundle\Application\Notification\NotificationTemplates;
use Eekes\Sulu\FormBuilderBundle\Application\Notification\PlaceholderRenderer;
use Eekes\Sulu\FormBuilderBundle\Application\Notification\SampleSubmission;
use Eekes\Sulu\FormBuilderBundle\Application\Rendering\FormRenderer;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\Honeypot\HoneypotSpamCheck;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\Honeypot\HoneypotType;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamVerdicts;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\RedirectUrlResolver;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionProcessor;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\ValueCollector;
use Eekes\Sulu\FormBuilderBundle\Application\Upload\FilesystemUploadStorage;
use Eekes\Sulu\FormBuilderBundle\Application\Upload\UploadStorageInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FlowRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\IntegrationCheckRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\IntegrationRunRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationDeliveryRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\SubmissionRepository;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\EventListener\FormSubmissionListener;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\EventListener\NotificationDeliveryListener;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Twig\FormBuilderExtension;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;

/*
 * Core services: building, rendering and processing forms on the website. Everything that needs
 * the Sulu admin or Sulu's content bundle lives in the sibling files.
 *
 * Every service an application may want to replace is registered under its class or interface,
 * so decorating or aliasing it is one line in the project.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $prefix = 'eekes_sulu_form_builder.';

    // Repositories
    $services->set(FormRepository::class)->tag('doctrine.repository_service');
    $services->set(NotificationRepository::class)->tag('doctrine.repository_service');
    $services->set(SubmissionRepository::class)->tag('doctrine.repository_service');

    // Field types. Autoconfigured, so the AsFormFieldType attribute on each class tags it - the same
    // way a field type of an application is registered.
    $services->set(FieldTypeRegistry::class)
        ->args(['$fieldTypes' => abstract_arg('set by FieldTypePass'), '$metadata' => abstract_arg('set by FieldTypePass')]);

    foreach ([
        TextFieldType::class,
        EmailFieldType::class,
        TextareaFieldType::class,
        NumberFieldType::class,
        PhoneFieldType::class,
        UrlFieldType::class,
        DateFieldType::class,
        TimeFieldType::class,
        DateTimeFieldType::class,
        FileFieldType::class,
        HiddenFieldType::class,
        RepeaterFieldType::class,
        ChoiceFieldType::class,
        CheckboxFieldType::class,
        CountryFieldType::class,
        RatingFieldType::class,
        HeadingFieldType::class,
        TextBlockFieldType::class,
    ] as $fieldType) {
        $services->set($fieldType);
    }

    // Building forms
    $services->set(ConditionEvaluator::class);
    $services->set(FormDefinitionFactory::class);
    $services->set(FormDefinitionProvider::class)->tag('kernel.reset', ['method' => 'reset']);

    $services->set(BootstrapColumnClassResolver::class)
        ->arg('$extraClasses', param($prefix.'rendering.column_classes'));
    $services->alias(ColumnClassResolverInterface::class, BootstrapColumnClassResolver::class);

    $services->set(StepType::class)
        ->arg('$logger', service('logger'))
        ->tag('monolog.logger', ['channel' => 'form_builder']);
    $services->set(FormBuilderFlowType::class);
    $services->set(StaticContentType::class);
    $services->set(RatingType::class);
    $services->set(RepeaterType::class);
    $services->set(RepeaterRowType::class);

    $services->set(FilesystemUploadStorage::class)
        ->arg('$directory', param($prefix.'upload_directory'));
    $services->alias(UploadStorageInterface::class, FilesystemUploadStorage::class);

    $services->set(FormFlowFactory::class)
        ->arg('$csrfProtection', param($prefix.'csrf_protection'));
    $services->set(HandledFormRegistry::class)->tag('kernel.reset', ['method' => 'reset']);
    $services->set(FieldKeyRenamer::class);

    // Spam protection. Autoconfigured, so the AsSpamCheck attribute tags each check - the same way
    // a check of another package or of the application is registered.
    $services->set(SpamCheckRegistry::class)
        ->args(['$checks' => abstract_arg('set by SpamCheckPass'), '$metadata' => abstract_arg('set by SpamCheckPass')]);
    $services->set(SpamVerdicts::class)->tag('kernel.reset', ['method' => 'reset']);
    $services->set(HoneypotSpamCheck::class);
    $services->set(HoneypotType::class);
    $services->set(PruneSpamCommand::class)
        ->arg('$retentionDays', param($prefix.'spam_protection.retention_days'));

    // Processing submissions
    $services->set(ValueCollector::class);
    $services->set(PlaceholderRenderer::class);
    $services->set(SampleSubmission::class);
    $services->set(NotificationTemplates::class)
        ->args([param($prefix.'notifications.templates'), param($prefix.'notifications.default_template')]);
    $services->set(NotificationDeliveryRepository::class)->tag('doctrine.repository_service');
    $services->set(NotificationSender::class)
        ->arg('$logger', service('logger'))
        ->arg('$defaultSenderEmail', param($prefix.'sender.email'))
        ->arg('$defaultSenderName', param($prefix.'sender.name'))
        ->arg('$branding', param($prefix.'notifications.branding'))
        ->arg('$adminPath', param($prefix.'notifications.admin_path'))
        ->arg('$bodyRenderer', service('twig.mime_body_renderer')->nullOnInvalid())
        ->tag('monolog.logger', ['channel' => 'form_builder']);
    // The status of an e-mail follows the mailer, also when it sends in a Messenger worker.
    $services->set(NotificationDeliveryListener::class)
        ->tag('kernel.event_listener', ['event' => SentMessageEvent::class, 'method' => 'onSent'])
        ->tag('kernel.event_listener', ['event' => FailedMessageEvent::class, 'method' => 'onFailed']);
    $services->set(SubmissionProcessor::class)
        ->arg('$logger', service('logger'))
        ->arg('$logSpam', param($prefix.'spam_protection.log'))
        ->arg('$storeSpamPayload', param($prefix.'spam_protection.store_payload'))
        ->arg('$anonymizeIp', param($prefix.'spam_protection.anonymize_ip'))
        ->tag('monolog.logger', ['channel' => 'form_builder']);
    // Integrations
    $services->set(FlowRepository::class)->tag('doctrine.repository_service');
    $services->set(IntegrationCheckRepository::class)->tag('doctrine.repository_service');
    $services->set(IntegrationRunRepository::class)->tag('doctrine.repository_service');
    $services->set(IntegrationRegistry::class)
        ->args([
            '$integrations' => abstract_arg('set by IntegrationPass'),
            '$metadata' => abstract_arg('set by IntegrationPass'),
            '$enabled' => param($prefix.'integrations.enabled'),
        ]);
    $services->set(FlowValueMapper::class);
    $services->set(FlowValidator::class);
    $services->set(RetryPolicy::class)
        ->args([
            param($prefix.'integrations.retry.max_attempts'),
            param($prefix.'integrations.retry.delay'),
            param($prefix.'integrations.retry.multiplier'),
            param($prefix.'integrations.retry.max_delay'),
            param($prefix.'integrations.retry.integrations'),
        ]);
    $services->set(IntegrationRunner::class)
        ->arg('$logger', service('logger'))
        ->arg('$messageBus', service('messenger.default_bus')->nullOnInvalid())
        ->arg('$async', param($prefix.'integrations.async'))
        ->arg('$sendersLocator', service('messenger.senders_locator')->nullOnInvalid())
        ->tag('monolog.logger', ['channel' => 'form_builder']);
    $services->set(WebhookIntegration::class)
        ->arg('$endpoints', param($prefix.'integrations.webhook.endpoints'))
        ->arg('$timeout', param($prefix.'integrations.webhook.timeout'));

    $services->set(RedirectUrlResolver::class)
        ->arg('$linkProviderPool', service('sulu_markup.link_tag.provider_pool')->nullOnInvalid());

    $services->set(FormSubmissionListener::class)
        ->arg('$successParameter', param($prefix.'success_parameter'))
        // After the router (32) and the locale listener (16), so the locale of the page is known.
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 0])
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse']);

    // Rendering
    $services->set(FormRenderer::class)
        ->arg('$template', param($prefix.'rendering.template'))
        ->arg('$successTemplate', param($prefix.'rendering.success_template'))
        ->arg('$formTheme', param($prefix.'rendering.form_theme'))
        ->arg('$successParameter', param($prefix.'success_parameter'))
        ->arg('$includeScript', param($prefix.'rendering.include_script'))
        ->arg('$turboFrame', param($prefix.'rendering.turbo_frame'))
        ->tag('twig.runtime');
    $services->set(FormBuilderExtension::class);
};
