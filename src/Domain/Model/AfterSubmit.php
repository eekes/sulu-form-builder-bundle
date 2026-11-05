<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * What a visitor sees once the last step is sent.
 */
enum AfterSubmit: string
{
    /**
     * The form is replaced by the success message on the same page.
     */
    case Message = 'message';

    /**
     * The visitor is sent to another page, internal or external.
     */
    case Redirect = 'redirect';
}
