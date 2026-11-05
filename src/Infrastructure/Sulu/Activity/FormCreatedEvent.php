<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Activity;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Sulu\Bundle\ActivityBundle\Domain\Event\DomainEvent;

/**
 * Shows up in the activity tab of the form and in Sulu's global activity log.
 */
final class FormCreatedEvent extends DomainEvent
{
    private readonly string $formId;
    private readonly string $formTitle;

    /**
     * The id and title are copied right away, so the event still reads correctly once the form is
     * gone - which is the point of a "removed" entry.
     */
    public function __construct(Form $form)
    {
        parent::__construct();

        $this->formId = (string) $form->id;
        $this->formTitle = $form->title;
    }

    public function getEventType(): string
    {
        return 'created';
    }

    public function getResourceKey(): string
    {
        return Form::RESOURCE_KEY;
    }

    public function getResourceId(): string
    {
        return $this->formId;
    }

    public function getResourceTitle(): string
    {
        return $this->formTitle;
    }

    public function getResourceSecurityContext(): string
    {
        return Form::SECURITY_CONTEXT;
    }
}
