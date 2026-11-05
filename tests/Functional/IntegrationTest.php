<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Content\FormResourceLoader;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Content\SingleFormSelectionPropertyResolver;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\ActivityBundle\Domain\Model\ActivityInterface;
use Sulu\Content\Application\ContentResolver\Value\ResolvableResource;
use Sulu\Content\Application\ResourceLoader\Loader\ResourceLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Where the bundle plugs into the rest of Sulu and Symfony: page templates, Twig and the activity
 * log.
 */
#[CoversNothing]
final class IntegrationTest extends FunctionalTestCase
{
    public function testAPageTemplateCanPickAForm(): void
    {
        $form = self::createContactForm();

        $view = self::service(SingleFormSelectionPropertyResolver::class)->resolve($form->id, 'en');

        $content = $view->getContent();
        self::assertInstanceOf(ResolvableResource::class, $content);
        self::assertSame(FormResourceLoader::RESOURCE_LOADER_KEY, $content->getResourceLoaderKey());
        self::assertSame(['id' => $form->id], $view->getView());

        self::assertNull(self::service(SingleFormSelectionPropertyResolver::class)->resolve(null, 'en')->getContent());
    }

    public function testTheResourceLoaderGivesTheDefinitionInTheLanguageOfThePage(): void
    {
        $form = self::createContactForm();

        // Sulu wraps every resource loader in a cache, which is exactly how pages use it.
        $loader = self::getContainer()->get(FormResourceLoader::class);
        self::assertInstanceOf(ResourceLoaderInterface::class, $loader);
        $loaded = $loader->load([$form->id, 999], 'nl');

        self::assertSame([$form->id], array_keys($loaded));
        self::assertInstanceOf(FormDefinition::class, $loaded[$form->id]);
        self::assertSame('nl', $loaded[$form->id]->locale);
    }

    public function testTheTwigFunctionAcceptsAKeyAnIdOrADefinition(): void
    {
        $form = self::createContactForm();
        $this->pushRequest();
        $twig = self::service(Environment::class);

        foreach (["eekes_form('contact')", 'eekes_form('.$form->id.')', 'eekes_form(definition)'] as $call) {
            $html = $twig->createTemplate('{{ '.$call.' }}')->render(['definition' => self::definition($form)]);

            self::assertStringContainsString('name="eekes_form_contact"', $html, $call);
        }

        self::assertSame('Contact', $twig->createTemplate("{{ eekes_form_definition('contact').title }}")->render());
    }

    public function testTheTwigComponentRendersTheSameForm(): void
    {
        self::createContactForm();
        $this->pushRequest();

        $html = self::service(Environment::class)->createTemplate('<twig:EekesFormBuilder:Form form="contact" class="my-form"/>')->render();

        self::assertStringContainsString('class="my-form"', $html);
        self::assertStringContainsString('name="eekes_form_contact"', $html);
    }

    public function testChangesToAFormEndUpInTheActivityLog(): void
    {
        self::ensureKernelShutdown();
        $client = self::createAuthenticatedClient();
        parent::setUp();

        $client->jsonRequest('POST', '/admin/api/forms?locale=en', ['title' => 'Contact']);
        $id = Json::decode((string) $client->getResponse()->getContent())->int('id');
        $client->jsonRequest('PUT', '/admin/api/forms/'.$id.'?locale=en', ['title' => 'Contact us']);
        $client->jsonRequest('POST', '/admin/api/forms/'.$id.'?action=copy');
        $client->jsonRequest('DELETE', '/admin/api/forms/'.$id);

        $activities = self::entityManager()->getRepository(ActivityInterface::class)->findBy(['resourceKey' => Form::RESOURCE_KEY], ['timestamp' => 'ASC', 'id' => 'ASC']);

        self::assertSame(['created', 'modified', 'copied', 'removed'], array_map(static fn (ActivityInterface $activity): string => $activity->getType(), $activities));
        self::assertSame('Contact us', $activities[3]->getResourceTitle());
        self::assertSame(Form::SECURITY_CONTEXT, $activities[0]->getResourceSecurityContext());
    }

    private function pushRequest(): void
    {
        $request = Request::create('https://example.com/contact');
        $request->setLocale('en');
        self::service(RequestStack::class)->push($request);
    }
}
