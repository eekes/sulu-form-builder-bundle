<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The palette of the builder: every registered field type with the title, icon and group it is
 * shown with, and the key of the Sulu form its settings modal renders.
 */
final class FieldTypeController extends AbstractAdminController
{
    public const string ROUTE = 'eekes_form_builder.get_field_types';
    public const string RESOURCE_KEY = 'eekes_form_field_types';

    public function __construct(
        private readonly FieldTypeRegistry $fieldTypeRegistry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/form-field-types', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // The palette is shown in the language of the admin user, not the one of the content.
        $locale = $request->query->getString('userLocale') ?: null;
        $types = [];

        foreach ($this->fieldTypeRegistry->all() as $metadata) {
            $types[] = [
                'key' => $metadata->key,
                'title' => $this->translator->trans($metadata->title, [], 'admin', $locale),
                'icon' => $metadata->icon,
                'group' => $metadata->group,
                'groupTitle' => $this->translator->trans('eekes_form_builder.field_group.'.$metadata->group, [], 'admin', $locale),
                'formKey' => $metadata->adminFormKey,
                'input' => $this->fieldTypeRegistry->get($metadata->key)->isInput(),
                'localizedOptions' => $this->fieldTypeRegistry->get($metadata->key)->getLocalizedOptions(),
            ];
        }

        return $this->json(['_embedded' => [self::RESOURCE_KEY => $types], 'total' => \count($types)]);
    }
}
