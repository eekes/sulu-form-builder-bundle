<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\EventListener;

use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionProvider;
use Eekes\Sulu\FormBuilderBundle\Application\Event\StepCompletedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Form\FlowData;
use Eekes\Sulu\FormBuilderBundle\Application\Form\FormFlowFactory;
use Eekes\Sulu\FormBuilderBundle\Application\Form\HandledFormRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\RedirectUrlResolver;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionContext;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionProcessor;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\AfterSubmit;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Flow\ButtonFlowInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Handles a form submission before Sulu renders the page it was posted to.
 *
 * A form posts back to the page it is on. Handling it here, rather than while the template
 * renders, is what makes a redirect possible after the last step; a step that is invalid or not
 * the last one is handed to the Twig function through HandledFormRegistry, and the page renders
 * as usual with the errors or the next step in it.
 *
 * After the last step the visitor is always redirected (post/redirect/get), also for a success
 * message: refreshing the page then shows the message again instead of sending the form twice.
 */
final readonly class FormSubmissionListener
{
    public function __construct(
        private FormDefinitionProvider $formDefinitionProvider,
        private FormFlowFactory $formFlowFactory,
        private HandledFormRegistry $handledFormRegistry,
        private SubmissionProcessor $submissionProcessor,
        private RedirectUrlResolver $redirectUrlResolver,
        private EventDispatcherInterface $eventDispatcher,
        private string $successParameter,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !$request->isMethod('POST')) {
            return;
        }

        foreach ($this->submittedFormKeys($request) as $key) {
            $definition = $this->formDefinitionProvider->getByKey($key, $request->getLocale());

            if ($definition === null) {
                continue;
            }

            $response = $this->handle($definition, $request);

            if ($response !== null) {
                $event->setResponse($response);

                return;
            }
        }
    }

    /**
     * Answers an invalid step with 422, which is what Turbo and most fetch-based form libraries
     * expect before they render the response in place.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $event->getResponse()->getStatusCode() === Response::HTTP_OK && $this->handledFormRegistry->hasInvalid()) {
            $event->getResponse()->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function handle(FormDefinition $definition, Request $request): ?Response
    {
        $flow = $this->formFlowFactory->createForRequest($definition, $request);
        $currentStep = $flow->getCursor()->getCurrentStep();

        $flow->handleRequest($request);

        if (!$flow->isSubmitted()) {
            $this->handledFormRegistry->set($definition->key, $flow);

            return null;
        }

        $context = SubmissionContext::fromRequest($request);
        $button = $flow->getClickedButton();

        // Moving on can also finish the flow: "next" on a step after which every remaining step
        // is skipped by its condition ends the form right there.
        $stepForm = $flow->isFinished() ? $flow : $flow->getStepForm();

        if ($flow->isFinished()) {
            $data = $flow->getData();
            $result = $this->submissionProcessor->process($definition, \is_array($data) ? $data : [], $context);

            return $result->response ?? new RedirectResponse($this->targetUrl($definition, $request));
        }

        if ($flow->isValid() && $button instanceof ButtonFlowInterface && $button->isNextAction()) {
            $step = $definition->step($currentStep);
            $data = $flow->getData();

            if ($step !== null) {
                $this->eventDispatcher->dispatch(new StepCompletedEvent($definition, $step, FlowData::values(\is_array($data) ? $data : []), $context));
            }
        }

        $this->handledFormRegistry->set($definition->key, $stepForm);

        return null;
    }

    private function targetUrl(FormDefinition $definition, Request $request): string
    {
        if ($definition->afterSubmit === AfterSubmit::Redirect) {
            $url = $this->redirectUrlResolver->resolve($definition);

            if ($url !== null) {
                return $url;
            }
        }

        $query = $request->query->all();
        $query[$this->successParameter] = $definition->key;

        return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().'?'.http_build_query($query).'#'.FormFlowFactory::NAME_PREFIX.$definition->key;
    }

    /**
     * @return list<string>
     */
    private function submittedFormKeys(Request $request): array
    {
        $keys = [];

        foreach ([...array_keys($request->request->all()), ...array_keys($request->files->all())] as $name) {
            if (str_starts_with($name, FormFlowFactory::NAME_PREFIX)) {
                $keys[] = substr($name, \strlen(FormFlowFactory::NAME_PREFIX));
            }
        }

        return array_values(array_unique($keys));
    }
}
