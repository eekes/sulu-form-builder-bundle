<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Metadata;

use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRegistry;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\FormAdmin;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\FormAdminMapper;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataVisitorInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A "Spam protection" section in the settings of a form, with a switch per installed spam check.
 * Which checks exist depends on the packages a project installed, so they cannot be in the XML.
 */
final readonly class SpamProtectionFormMetadataVisitor implements FormMetadataVisitorInterface
{
    public const string SECTION = 'spam_protection';

    public function __construct(
        private SpamCheckRegistry $spamCheckRegistry,
        private TranslatorInterface $translator,
    ) {
    }

    public function visitFormMetadata(FormMetadata $formMetadata, string $locale, array $metadataOptions = []): void
    {
        $checks = $this->spamCheckRegistry->all();

        if ($formMetadata->getKey() !== FormAdmin::SETTINGS_FORM_KEY || $checks === []) {
            return;
        }

        $section = new SectionMetadata(self::SECTION);
        $section->setLabel($this->translate('eekes_form_builder.spam_protection', $locale), $locale);
        $section->setDescription($this->translate('eekes_form_builder.spam_protection_info', $locale), $locale);
        $section->setColSpan(12);

        foreach ($checks as $check) {
            $field = new FieldMetadata(FormAdminMapper::SPAM_CHECK_PREFIX.$check->key);
            $field->setType('checkbox');
            $field->setColSpan(12);
            $field->addOption(self::option('type', 'toggler'));
            $field->addOption(self::option('default_value', $check->enabledByDefault));
            // Sulu's checkbox reads the text next to the switch from the title of this option.
            $label = new OptionMetadata();
            $label->setName('label');
            $label->setTitle($this->translate($check->title, $locale), $locale);
            $field->addOption($label);

            if ($check->description !== null) {
                $field->setDescription($this->translate($check->description, $locale), $locale);
            }

            $section->addItem($field);
        }

        $items = $formMetadata->getItems();
        $items[self::SECTION] = $section;
        $formMetadata->setItems($items);
    }

    private function translate(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], 'admin', $locale);
    }

    private static function option(string $name, string|bool $value): OptionMetadata
    {
        $option = new OptionMetadata();
        $option->setName($name);
        $option->setValue($value);

        return $option;
    }
}
