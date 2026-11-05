<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Metadata;

use Eekes\Sulu\FormBuilderBundle\Application\Notification\NotificationTemplates;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataVisitorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The notification modal: the template the e-mail is sent with - the templates are configured,
 * so they cannot be in the XML - and a preview of the e-mail as it is being edited.
 */
final readonly class NotificationTemplateFormMetadataVisitor implements FormMetadataVisitorInterface
{
    public const string PREVIEW_SECTION = 'preview_section';

    public function __construct(
        private NotificationTemplates $templates,
        private TranslatorInterface $translator,
    ) {
    }

    public function visitFormMetadata(FormMetadata $formMetadata, string $locale, array $metadataOptions = []): void
    {
        if ($formMetadata->getKey() !== Notification::FORM_KEY) {
            return;
        }

        $items = $formMetadata->getItems();
        $message = $items['message'] ?? null;

        if ($message instanceof SectionMetadata) {
            $items['message'] = $this->withTemplate($message, $locale);
        }

        // The preview right below the text, before the conditions.
        $ordered = [];

        foreach ($items as $name => $item) {
            $ordered[$name] = $item;

            if ($name === 'message') {
                $ordered[self::PREVIEW_SECTION] = $this->previewSection($locale);
            }
        }

        $formMetadata->setItems($ordered);
    }

    private function withTemplate(SectionMetadata $message, string $locale): SectionMetadata
    {
        $field = new FieldMetadata('template');
        $field->setType('single_select');
        $field->setColSpan(12);
        $field->setLabel($this->translate('eekes_form_builder.email_template', $locale), $locale);
        $field->setDescription($this->translate('eekes_form_builder.email_template_info', $locale), $locale);

        $values = new OptionMetadata();
        $values->setName('values');
        $values->setType('collection');

        foreach ($this->templates->all() as $template) {
            $value = new OptionMetadata();
            $value->setName($template->key);
            $value->setTitle($this->translate($template->label, $locale), $locale);
            $values->addValueOption($value);
        }

        $field->addOption($values);

        $default = new OptionMetadata();
        $default->setName('default_value');
        $default->setValue($this->templates->defaultKey());
        $field->addOption($default);

        // First in the section, above the subject.
        $section = new SectionMetadata($message->getName());
        $section->setLabels($message->getLabels());
        $section->setDescriptions($message->getDescriptions());
        $section->setColSpan($message->getColSpan());
        $section->addItem($field);

        foreach ($message->getItems() as $item) {
            $section->addItem($item);
        }

        return $section;
    }

    private function previewSection(string $locale): SectionMetadata
    {
        $preview = new FieldMetadata('preview');
        $preview->setType('eekes_form_builder_notification_preview');
        $preview->setColSpan(12);

        $section = new SectionMetadata(self::PREVIEW_SECTION);
        $section->setLabel($this->translate('eekes_form_builder.email_preview', $locale), $locale);
        $section->setDescription($this->translate('eekes_form_builder.email_preview_info', $locale), $locale);
        $section->setColSpan(12);
        $section->addItem($preview);

        return $section;
    }

    private function translate(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], 'admin', $locale);
    }
}
