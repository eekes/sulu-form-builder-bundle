<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Application\Form\FormFlowFactory;
use Eekes\Sulu\FormBuilderBundle\Application\Rendering\FormRenderer;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\EventListener\FormSubmissionListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Plays a visitor on a page with a form, without rendering a Sulu page: the request goes through
 * the request listener exactly as it would before Sulu renders the page, and the form is then
 * rendered the way {{ eekes_form() }} renders it.
 *
 * One session is kept for the whole test, like a browser keeps its cookie.
 */
abstract class WebsiteTestCase extends FunctionalTestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new Session(new MockArraySessionStorage());
    }

    /**
     * Posts a step of a form to the page it is on.
     *
     * @param array<string, mixed> $stepData the answers of the step
     * @param string $button "next", "previous" or "finish"
     * @param array<string, mixed> $files uploads of the step, by field key
     *
     * @return null|Response the response the listener set: a redirect after the last step, nothing otherwise
     */
    protected function submit(Form $form, string $step, array $stepData, string $button = 'finish', array $files = [], string $query = ''): ?Response
    {
        $name = FormFlowFactory::NAME_PREFIX.$form->key;
        $request = Request::create(
            'https://example.com/contact'.($query !== '' ? '?'.$query : ''),
            'POST',
            [$name => [$step => $stepData, 'navigator' => [$button => '']]],
            [],
            $files === [] ? [] : [$name => [$step => $files]],
        );

        $this->handle($request);

        return $this->lastResponse;
    }

    private ?Response $lastResponse = null;

    protected function handle(Request $request): void
    {
        $request->setSession($this->session);
        $request->setLocale('en');
        self::service(RequestStack::class)->push($request);

        $event = new RequestEvent(self::$kernel ?? self::bootKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
        self::service(FormSubmissionListener::class)->onKernelRequest($event);

        $this->lastResponse = $event->getResponse();
    }

    /**
     * Renders the form on the current request, the way a page template does.
     */
    protected function render(Form $form, ?string $url = null): string
    {
        if ($url !== null) {
            $request = Request::create($url);
            $request->setSession($this->session);
            $request->setLocale('en');
            self::service(RequestStack::class)->push($request);
        }

        return self::service(FormRenderer::class)->render($form->key);
    }

    /**
     * The status the page would be sent with.
     */
    protected function pageStatus(): int
    {
        $request = self::service(RequestStack::class)->getCurrentRequest() ?? Request::create('/');
        $event = new ResponseEvent(self::$kernel ?? self::bootKernel(), $request, HttpKernelInterface::MAIN_REQUEST, new Response('page'));
        self::service(FormSubmissionListener::class)->onKernelResponse($event);

        return $event->getResponse()->getStatusCode();
    }
}
