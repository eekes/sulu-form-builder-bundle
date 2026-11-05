<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form;

use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Hands a flow that was handled early in the request - by the request listener, before Sulu
 * renders the page - to the Twig function that renders it, so the visitor sees their errors or
 * the next step instead of a fresh form.
 */
final class HandledFormRegistry implements ResetInterface
{
    /**
     * @var array<string, FormFlowInterface>
     */
    private array $flows = [];

    public function set(string $formKey, FormFlowInterface $flow): void
    {
        $this->flows[$formKey] = $flow;
    }

    public function get(string $formKey): ?FormFlowInterface
    {
        return $this->flows[$formKey] ?? null;
    }

    public function hasInvalid(): bool
    {
        foreach ($this->flows as $flow) {
            if ($flow->isSubmitted() && !$flow->isValid()) {
                return true;
            }
        }

        return false;
    }

    public function reset(): void
    {
        $this->flows = [];
    }
}
