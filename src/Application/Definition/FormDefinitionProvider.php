<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Definition;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Loads the definition of a form once per request, however often a page asks for it - the
 * request listener and the Twig function both do.
 */
final class FormDefinitionProvider implements ResetInterface
{
    /**
     * @var array<string, null|FormDefinition>
     */
    private array $cache = [];

    public function __construct(
        private readonly FormRepository $formRepository,
        private readonly FormDefinitionFactory $formDefinitionFactory,
    ) {
    }

    public function getByKey(string $key, string $locale): ?FormDefinition
    {
        return $this->cache['key:'.$key.':'.$locale] ??= $this->create($this->formRepository->findByKey($key), $locale);
    }

    public function getById(int $id, string $locale): ?FormDefinition
    {
        return $this->cache['id:'.$id.':'.$locale] ??= $this->create($this->formRepository->findById($id), $locale);
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    private function create(?Form $form, string $locale): ?FormDefinition
    {
        return $form === null ? null : $this->formDefinitionFactory->create($form, $locale);
    }
}
