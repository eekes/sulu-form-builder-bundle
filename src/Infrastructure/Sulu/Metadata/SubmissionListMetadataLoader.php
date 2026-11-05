<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Metadata;

use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionFactory;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\SubmissionPresenter;
use Sulu\Bundle\AdminBundle\Metadata\ListMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\ListMetadata\ListMetadata;
use Sulu\Bundle\AdminBundle\Metadata\ListMetadata\ListMetadataLoaderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the submissions list are the fields of the form: the ones the editor marked
 * "show in list" are visible, every other answer is one click away in the column settings.
 *
 * Built per request rather than from XML, and never cached: the form changes whenever an editor
 * adds a field.
 */
final readonly class SubmissionListMetadataLoader implements ListMetadataLoaderInterface
{
    public function __construct(
        private FormRepository $formRepository,
        private FieldTypeRegistry $fieldTypeRegistry,
        private TranslatorInterface $translator,
    ) {
    }

    public function getMetadata(string $key, string $locale, array $metadataOptions = []): ?ListMetadata
    {
        if ($key !== Submission::LIST_KEY && $key !== Submission::SPAM_LIST_KEY) {
            return null;
        }

        $list = new ListMetadata();
        $list->setCacheable(false);

        $list->addField(self::field('id', $this->translator->trans('sulu_admin.id', [], 'admin', $locale), 'integer', 'no', true));
        $list->addField(self::field('createdAt', $this->translator->trans('eekes_form_builder.submitted_at', [], 'admin', $locale), 'datetime', 'always', true));

        // Why a blocked submission was caught, and where it came from: what tells a bot from a
        // person caught by mistake.
        if ($key === Submission::SPAM_LIST_KEY) {
            foreach ([
                'spamCheck' => ['eekes_form_builder.spam_check', 'always'],
                'spamReason' => ['eekes_form_builder.spam_reason', 'always'],
                'spamScore' => ['eekes_form_builder.spam_score', 'no'],
                'ipAddress' => ['eekes_form_builder.ip_address', 'always'],
                'userAgent' => ['eekes_form_builder.user_agent', 'no'],
            ] as $name => [$label, $visibility]) {
                $list->addField(self::field($name, $this->translator->trans($label, [], 'admin', $locale), 'string', $visibility, false));
            }
        }

        $formId = $metadataOptions['formId'] ?? null;
        $form = is_numeric($formId) ? $this->formRepository->findById((int) $formId) : null;

        if ($form === null) {
            return $list;
        }

        // Labels in the language of the content, which is what the editor typed them in.
        $contentLocale = \is_string($metadataOptions['locale'] ?? null) ? $metadataOptions['locale'] : $locale;

        foreach ($form->structure()->fields() as $field) {
            if ($this->fieldTypeRegistry->has($field->type) && !$this->fieldTypeRegistry->get($field->type)->isInput()) {
                continue;
            }

            $label = FormDefinitionFactory::fieldTexts($field, $contentLocale)['label'] ?? null;
            $label = \is_string($label) && trim(strip_tags($label)) !== '' ? trim(strip_tags($label)) : $field->key;

            $list->addField(self::field(SubmissionPresenter::COLUMN_PREFIX.$field->key, $label, 'string', $field->showInList ? 'always' : 'no', false));
        }

        $list->addField(self::field('locale', $this->translator->trans('sulu_admin.language', [], 'admin', $locale), 'string', 'no', false));

        return $list;
    }

    private static function field(string $name, string $label, string $type, string $visibility, bool $sortable): FieldMetadata
    {
        $field = new FieldMetadata($name);
        $field->setLabel($label);
        $field->setType($type);
        $field->setVisibility($visibility);
        $field->setSortable($sortable);
        $field->setTransformerTypeParameters([]);
        $field->setFilterType(null);
        $field->setFilterTypeParameters(null);
        $field->setWidth('auto');

        return $field;
    }
}
