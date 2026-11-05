<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form;

use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\FormBuilderFlowType;
use Eekes\Sulu\FormBuilderBundle\Application\Upload\UploadAwareDataStorage;
use Eekes\Sulu\FormBuilderBundle\Application\Upload\UploadStorageInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Symfony\Component\Form\Flow\DataStorage\DataStorageInterface;
use Symfony\Component\Form\Flow\DataStorage\NullDataStorage;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Creates the Symfony form flow of a form definition.
 *
 * Pages are cached by Sulu's HTTP cache, so the first step is always rendered without touching
 * the session: a fresh flow, no CSRF token. Only a POST - which is never cached - restores the
 * progress of a visitor from the session.
 */
final readonly class FormFlowFactory
{
    public const string NAME_PREFIX = 'eekes_form_';

    public function __construct(
        private FormFactoryInterface $formFactory,
        private RequestStack $requestStack,
        private UploadStorageInterface $uploadStorage,
        private bool $csrfProtection = false,
    ) {
    }

    public static function name(FormDefinition $definition): string
    {
        return self::NAME_PREFIX.$definition->key;
    }

    /**
     * A flow on its first step, for rendering a form that was not submitted.
     */
    public function createFresh(FormDefinition $definition): FormFlowInterface
    {
        return $this->create($definition, new NullDataStorage());
    }

    /**
     * A flow that continues where the visitor left off, for handling a submission.
     *
     * The page itself is cached, so a visitor who abandoned a form halfway and comes back sees
     * the first step again while the session still remembers a later one. Submitting that first
     * step starts over instead of being ignored as a stale reload.
     */
    public function createForRequest(FormDefinition $definition, Request $request): FormFlowInterface
    {
        $storage = new UploadAwareDataStorage(
            new SessionDataStorage(self::sessionKey($definition), $this->requestStack),
            $this->uploadStorage,
        );

        $firstStep = $definition->steps[0]->key;
        $submitted = [...$request->request->all(self::name($definition)), ...$request->files->all(self::name($definition))];
        $stored = $storage->load();

        if (\array_key_exists($firstStep, $submitted) && \is_array($stored) && ($stored[FlowData::STEP_KEY] ?? $firstStep) !== $firstStep) {
            $storage->clear();
        }

        return $this->create($definition, $storage);
    }

    public static function isSubmitted(FormDefinition $definition, Request $request): bool
    {
        return $request->isMethod('POST') && ($request->request->has(self::name($definition)) || $request->files->has(self::name($definition)));
    }

    private function create(FormDefinition $definition, DataStorageInterface $storage): FormFlowInterface
    {
        $flow = $this->formFactory->createNamed(self::name($definition), FormBuilderFlowType::class, [], [
            'definition' => $definition,
            'data_storage' => $storage,
            'csrf_protection' => $this->csrfProtection,
        ]);

        if (!$flow instanceof FormFlowInterface) {
            throw new \LogicException('The form builder type did not produce a form flow.');
        }

        return $flow;
    }

    private static function sessionKey(FormDefinition $definition): string
    {
        return 'eekes_form_builder.'.$definition->key.'.'.$definition->locale;
    }
}
