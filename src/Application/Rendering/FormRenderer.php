<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Rendering;

use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionProvider;
use Eekes\Sulu\FormBuilderBundle\Application\Form\FormFlowFactory;
use Eekes\Sulu\FormBuilderBundle\Application\Form\HandledFormRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\AfterSubmit;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Renders a form where a template asks for it: the success message right after it was sent, the
 * step the visitor is on when a submission was handled earlier in the request, and a fresh first
 * step otherwise.
 */
final readonly class FormRenderer
{
    public const string SCRIPT = __DIR__.'/../../../public/js/form-builder.js';

    public function __construct(
        private FormDefinitionProvider $formDefinitionProvider,
        private FormFlowFactory $formFlowFactory,
        private HandledFormRegistry $handledFormRegistry,
        private RequestStack $requestStack,
        private Environment $twig,
        private string $template,
        private string $successTemplate,
        private string $formTheme,
        private string $successParameter,
        private bool $includeScript,
        private bool $turboFrame = false,
    ) {
    }

    /**
     * @param FormDefinition|int|string $form a definition, a form key or a form id
     * @param array<string, mixed> $context extra variables for the template
     */
    public function render(FormDefinition|string|int $form, array $context = []): string
    {
        $definition = $this->definition($form);

        if ($definition === null) {
            return '';
        }

        $request = $this->requestStack->getCurrentRequest();

        $turboFrame = \is_bool($context['turbo_frame'] ?? null) ? $context['turbo_frame'] : $this->turboFrame;

        if ($request !== null && $request->query->get($this->successParameter) === $definition->key && $definition->afterSubmit === AfterSubmit::Message) {
            return $this->twig->render($this->successTemplate, [...$context, 'definition' => $definition, 'turbo_frame' => $turboFrame, 'anchor' => FormFlowFactory::NAME_PREFIX.$definition->key]);
        }

        $flow = $this->handledFormRegistry->get($definition->key) ?? $this->formFlowFactory->createFresh($definition);

        return $this->twig->render($this->template, [
            ...$context,
            'definition' => $definition,
            'form' => $flow->createView(),
            'form_theme' => $this->formTheme,
            'include_script' => $this->includeScript,
            'script_version' => self::scriptVersion(),
            'turbo_frame' => $turboFrame,
            // A redirect leads to another page, which has no frame to put into this one.
            'break_out_of_frame' => $turboFrame && $definition->afterSubmit === AfterSubmit::Redirect,
            'anchor' => FormFlowFactory::NAME_PREFIX.$definition->key,
        ]);
    }

    /**
     * A short hash of the website script: part of its URL, so a browser that cached an older
     * version (one without a feature the form needs) loads the new one.
     */
    public static function scriptVersion(): string
    {
        // xxh3 over a file of a few kilobytes: cheaper than anything that would cache it.
        $hash = is_file(self::SCRIPT) ? hash_file('xxh3', self::SCRIPT) : false;

        return $hash === false ? '' : substr($hash, 0, 8);
    }

    public function definition(FormDefinition|string|int $form): ?FormDefinition
    {
        if ($form instanceof FormDefinition) {
            return $form;
        }

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        return \is_int($form) || ctype_digit($form)
            ? $this->formDefinitionProvider->getById((int) $form, $locale)
            : $this->formDefinitionProvider->getByKey($form, $locale);
    }
}
