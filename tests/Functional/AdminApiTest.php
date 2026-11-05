<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Functional;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\Condition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionOperator;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\SubmittedValue;
use Eekes\Sulu\FormBuilderBundle\Tests\Double\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Everything the administration interface asks of the bundle, through the same routes and the
 * same security as a real admin.
 */
#[CoversNothing]
final class AdminApiTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createAuthenticatedClient();

        parent::setUp();
    }

    public function testAFormIsCreatedWithAKeyFromItsTitle(): void
    {
        $form = $this->request('POST', '/admin/api/forms?locale=en', ['title' => 'Request a quote', 'afterSubmit' => 'message']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('request-a-quote', $form->at('key'));
        self::assertSame(['steps' => [['key' => 'step1', 'title' => '', 'titles' => [], 'visibleIf' => null, 'fields' => []]]], $form->at('builder'));

        $again = $this->request('POST', '/admin/api/forms?locale=en', ['title' => 'Request a quote']);
        self::assertSame('request-a-quote-2', $again->at('key'), 'A key is unique.');
    }

    public function testTheBuilderIsSavedAndReadBackPerLanguage(): void
    {
        $id = $this->createViaApi();

        $this->request('PUT', '/admin/api/forms/'.$id.'?locale=en', [
            'title' => 'Contact',
            'builder' => ['steps' => [[
                'key' => 'step1',
                'title' => 'Your details',
                'fields' => [
                    ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'width' => ['desktop' => 6, 'tablet' => 6, 'mobile' => 12], 'options' => ['maxLength' => 80]],
                    ['key' => 'topic', 'type' => 'choice', 'label' => 'Topic', 'options' => ['display' => 'select', 'choices' => [['type' => 'choice', 'label' => 'Sales', 'value' => 'sales']]]],
                ],
            ]]],
        ]);
        self::assertResponseIsSuccessful();

        $dutch = $this->request('GET', '/admin/api/forms/'.$id.'?locale=nl');
        $fields = $dutch->get('builder.steps.0.fields');
        self::assertSame('Name', $fields->at('0.label'), 'A language that was never edited starts from an existing one.');
        self::assertSame(['maxLength' => 80], $fields->at('0.options'));
        self::assertSame(['desktop' => 6, 'tablet' => 6, 'mobile' => 12], $fields->at('0.width'));

        $dutch = $dutch->with('builder.steps.0.title', 'Je gegevens');
        $dutch = $dutch->with('builder.steps.0.fields.0.label', 'Naam');
        $dutch = $dutch->with('builder.steps.0.fields.1.options.choices.0.label', 'Verkoop');
        $this->request('PUT', '/admin/api/forms/'.$id.'?locale=nl', $dutch->data);

        $english = $this->request('GET', '/admin/api/forms/'.$id.'?locale=en');
        self::assertSame('Your details', $english->at('builder.steps.0.title'));
        self::assertSame('Name', $english->at('builder.steps.0.fields.0.label'));
        self::assertSame('Sales', $english->at('builder.steps.0.fields.1.options.choices.0.label'));

        $dutch = $this->request('GET', '/admin/api/forms/'.$id.'?locale=nl');
        self::assertSame('Verkoop', $dutch->at('builder.steps.0.fields.1.options.choices.0.label'));
    }

    public function testChoiceValuesAreSharedByEveryLanguageAndOnlyLabelsAreTranslated(): void
    {
        $id = $this->createViaApi();
        $this->request('PUT', '/admin/api/forms/'.$id.'?locale=en', ['builder' => ['steps' => [['key' => 'step1', 'fields' => [
            ['key' => 'topic', 'type' => 'choice', 'label' => 'Topic', 'options' => ['display' => 'select', 'choices' => [
                ['type' => 'choice', 'label' => 'Sales', 'value' => 'sales'],
                ['type' => 'choice', 'label' => 'Support', 'value' => 'support'],
            ]]],
        ]]]]]);
        $dutch = $this->request('GET', '/admin/api/forms/'.$id.'?locale=nl');
        $this->request('PUT', '/admin/api/forms/'.$id.'?locale=nl', $dutch
            ->with('builder.steps.0.fields.0.options.choices', [
                ['type' => 'choice', 'label' => 'Verkoop', 'value' => 'sales'],
                ['type' => 'choice', 'label' => 'Ondersteuning', 'value' => 'support'],
            ])->data);

        // In Dutch, a value is changed and a choice added.
        $dutch = $this->request('GET', '/admin/api/forms/'.$id.'?locale=nl');
        $this->request('PUT', '/admin/api/forms/'.$id.'?locale=nl', $dutch
            ->with('builder.steps.0.fields.0.options.choices', [
                ['type' => 'choice', 'label' => 'Verkoop', 'value' => 'sales'],
                ['type' => 'choice', 'label' => 'Ondersteuning', 'value' => 'help'],
                ['type' => 'choice', 'label' => 'Partners', 'value' => 'partners'],
            ])->data);

        $english = $this->request('GET', '/admin/api/forms/'.$id.'?locale=en');
        self::assertSame([
            ['type' => 'choice', 'label' => 'Sales', 'value' => 'sales'],
            ['type' => 'choice', 'label' => 'Support', 'value' => 'help'],
            ['type' => 'choice', 'label' => 'Partners', 'value' => 'partners'],
        ], $english->at('builder.steps.0.fields.0.options.choices'), 'English keeps its labels and follows the values and choices.');

        $form = self::entityManager()->find(Form::class, $id);
        self::assertNotNull($form);
        self::entityManager()->refresh($form);
        $choices = self::definition($form, 'en')->steps[0]->fields[0]->option('choices');
        self::assertIsArray($choices);
        self::assertSame(['sales', 'help', 'partners'], array_column($choices, 'value'));
    }

    public function testAFormStoredWithValuesPerLanguageReadsTheValuesOfOneLanguage(): void
    {
        $form = self::createForm('legacy', [['key' => 'step1', 'fields' => [[
            'key' => 'topic', 'type' => 'choice', 'options' => ['display' => 'select'], 'translations' => [
                'en' => ['label' => 'Topic', 'choices' => [['type' => 'choice', 'label' => 'Sales', 'value' => 'sales']]],
                'nl' => ['label' => 'Onderwerp', 'choices' => [['type' => 'choice', 'label' => 'Verkoop', 'value' => 'verkoop']]],
            ],
        ]]]]);

        $choices = self::definition($form, 'nl')->steps[0]->fields[0]->option('choices');

        self::assertSame([['type' => 'choice', 'label' => 'Verkoop', 'value' => 'sales']], $choices, 'A condition on "sales" works for a Dutch visitor too.');
    }

    public function testRenamingAFieldKeyFollowsEverythingThatRefersToIt(): void
    {
        $form = self::createContactForm();
        $notification = self::createNotification($form, '{{ email }}', static function (Notification $notification): void {
            $notification->setConditions(ConditionSet::of(new Condition('email', ConditionOperator::Filled)));
            $notification->setSubject('nl', 'Bericht van {{email}} en {{ name }}');
        });
        $flow = self::createFlow($form, 'fake_crm', 'create_lead', [
            'email' => ['source' => 'field', 'field' => 'email'],
            'source' => ['source' => 'value', 'value' => 'Sent by {{ email }}'],
        ], conditions: ConditionSet::of(new Condition('email', ConditionOperator::Filled)));
        $this->storeSubmission($form);

        $builder = $this->request('GET', '/admin/api/forms/'.$form->id.'?locale=en');
        self::assertSame('email', $builder->at('builder.steps.0.fields.1.id'), 'A field stored before ids existed is identified by its key.');

        // Swap two keys at once: "email" becomes "mail" and "name" becomes "email".
        $renamed = $builder->with('builder.steps.0.fields.1.key', 'mail')->with('builder.steps.0.fields.0.key', 'email');
        $this->request('PUT', '/admin/api/forms/'.$form->id.'?locale=en', $renamed->data);
        self::assertResponseIsSuccessful();

        self::entityManager()->clear();
        $notification = self::entityManager()->find(Notification::class, $notification->id);
        $flow = self::entityManager()->find(Flow::class, $flow->id);
        self::assertNotNull($notification);
        self::assertNotNull($flow);

        self::assertSame('{{ mail }}', $notification->to);
        self::assertSame(['mail'], $notification->conditions()?->fields());
        self::assertSame('New message from {{ email }}', $notification->subject('en'));
        self::assertSame('Bericht van {{mail}} en {{ email }}', $notification->subject('nl'));
        self::assertSame('<p>{{ email }} wrote:</p>', $notification->body('en'));

        self::assertSame('mail', $flow->mapping()['email']->value);
        self::assertSame('Sent by {{ mail }}', $flow->mapping()['source']->value);
        self::assertSame(['mail'], $flow->conditions()?->fields());

        $list = $this->request('GET', '/admin/api/form-submissions?formId='.$form->id.'&locale=en&page=1&limit=10');
        self::assertSame('jane@example.com', $list->at('_embedded.'.Submission::RESOURCE_KEY.'.0.field_mail'), 'An older submission stays in the column of the renamed field.');
        self::assertSame(['mail'], array_map(static fn (SubmittedValue $value): string => $value->key, self::submissions()[0]->values()));
    }

    public function testEveryFieldKeepsItsOwnId(): void
    {
        $id = $this->createViaApi();

        $form = $this->request('PUT', '/admin/api/forms/'.$id.'?locale=en', ['builder' => ['steps' => [['key' => 'step1', 'fields' => [
            ['key' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['key' => 'name_2', 'type' => 'text', 'label' => 'Name'],
        ]]]]]);

        $first = $form->string('builder.steps.0.fields.0.id');
        self::assertStringContainsString('-', $first, 'A new id can never be mistaken for a key.');

        // A duplicated card that kept the id of its original gets an id of its own.
        $again = $this->request('PUT', '/admin/api/forms/'.$id.'?locale=en', $form->with('builder.steps.0.fields.1.id', $first)->data);
        self::assertSame($first, $again->at('builder.steps.0.fields.0.id'));
        self::assertNotSame($first, $again->at('builder.steps.0.fields.1.id'));
    }

    public function testTheSettingsTabSavesWithoutTouchingTheBuilder(): void
    {
        $id = $this->createViaApi();
        $this->request('PUT', '/admin/api/forms/'.$id.'?locale=en', ['builder' => ['steps' => [['key' => 'step1', 'fields' => [['key' => 'name', 'type' => 'text', 'label' => 'Name']]]]]]);

        $form = $this->request('PUT', '/admin/api/forms/'.$id.'?locale=en', [
            'afterSubmit' => 'redirect',
            'redirectUrl' => 'https://example.com/thanks',
            'submitLabel' => 'Send it',
            'storeSubmissions' => false,
        ]);

        self::assertSame('redirect', $form->at('afterSubmit'));
        self::assertSame('https://example.com/thanks', $form->at('redirectUrl'));
        self::assertSame('Send it', $form->at('submitLabel'));
        self::assertFalse($form->at('storeSubmissions'));
        self::assertSame('name', $form->at('builder.steps.0.fields.0.key'));
    }

    public function testTheFormsAreListed(): void
    {
        $this->createViaApi('Contact');
        $this->createViaApi('Newsletter');

        $list = $this->request('GET', '/admin/api/forms?locale=en&fields=id,title,key&sortBy=title&sortOrder=asc');

        self::assertSame(2, $list->at('total'));
        self::assertSame(['Contact', 'Newsletter'], $list->column('_embedded.'.Form::RESOURCE_KEY, 'title'));
    }

    public function testAFormIsDuplicatedWithItsNotificationsButWithoutItsSubmissions(): void
    {
        $form = self::createContactForm();
        self::createNotification($form, 'team@example.com');
        $this->storeSubmission($form);

        $copy = $this->request('POST', '/admin/api/forms/'.$form->id.'?action=copy&locale=en');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('contact-2', $copy->at('key'));
        self::assertSame('Contact (2)', $copy->at('title'));
        self::assertSame('name', $copy->at('builder.steps.0.fields.0.key'));
        self::assertSame(1, $this->request('GET', '/admin/api/form-notifications?formId='.$copy->int('id').'&locale=en')->at('total'));
        self::assertSame(0, $this->request('GET', '/admin/api/form-submissions?formId='.$copy->int('id').'&locale=en')->at('total'));
    }

    public function testDeletingAFormTakesItsSubmissionsAndTheirFilesAlong(): void
    {
        $form = self::createContactForm();
        $file = $this->storeSubmission($form, withFile: true);

        $this->client->request('DELETE', '/admin/api/forms/'.$form->id);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], self::submissions());
        self::assertFileDoesNotExist($file);
    }

    public function testThePaletteListsEveryFieldTypeWithItsModal(): void
    {
        $types = $this->request('GET', '/admin/api/form-field-types?locale=en');
        $keys = $types->column('_embedded.eekes_form_field_types', 'key');

        self::assertContains('grade', $keys, 'Field types of the application are in the palette.');
        self::assertSame('text', $keys[0]);

        $text = $types->find('_embedded.eekes_form_field_types', 'key', 'text');
        self::assertSame('eekes_form_builder_field_text', $text->at('formKey'));
        self::assertSame('Text', $text->at('title'));
        self::assertSame('Fields', $text->at('groupTitle'));
        self::assertTrue($text->at('input'));

        $choice = $types->find('_embedded.eekes_form_field_types', 'key', 'choice');
        self::assertSame(['choices'], $choice->at('localizedOptions'));
    }

    public function testTheModalOfEveryBuiltInFieldTypeLoads(): void
    {
        foreach (self::service(FieldTypeRegistry::class)->all() as $metadata) {
            if ($metadata->key === 'grade') {
                continue;
            }

            $this->client->request('GET', '/admin/metadata/form/'.$metadata->adminFormKey);
            self::assertResponseIsSuccessful(\sprintf('The form "%s" did not load.', $metadata->adminFormKey));

            $content = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('"key"', $content, 'The partials were included.');
        }
    }

    public function testTheFormsOfTheTabsLoad(): void
    {
        foreach (['eekes_form_builder', 'eekes_form_settings', 'eekes_form_notification_details'] as $key) {
            $this->client->request('GET', '/admin/metadata/form/'.$key);
            self::assertResponseIsSuccessful($key);
        }

        $this->client->request('GET', '/admin/metadata/list/'.Form::LIST_KEY);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/metadata/list/'.Notification::LIST_KEY);
        self::assertResponseIsSuccessful();
    }

    public function testNotificationsAreManagedPerForm(): void
    {
        $form = self::createContactForm();

        $created = $this->request('POST', '/admin/api/form-notifications?formId='.$form->id.'&locale=en', [
            'name' => 'Team',
            'to' => 'team@example.com',
            'subject' => 'New message from {{ name }}',
            'body' => '<p>Hi</p>',
            'enabled' => true,
            'conditions' => ['match' => 'any', 'rules' => [['field' => 'message', 'operator' => 'contains', 'value' => 'price'], ['field' => '']]],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['match' => 'any', 'rules' => [['field' => 'message', 'operator' => 'contains', 'value' => 'price']]], $created->at('conditions'), 'An incomplete rule is dropped.');

        $this->request('PUT', '/admin/api/form-notifications/'.$created->int('id').'?locale=nl', ['subject' => 'Nieuw bericht']);
        self::assertSame('New message from {{ name }}', $this->request('GET', '/admin/api/form-notifications/'.$created->int('id').'?locale=en')->at('subject'));
        self::assertSame('Nieuw bericht', $this->request('GET', '/admin/api/form-notifications/'.$created->int('id').'?locale=nl')->at('subject'));

        $list = $this->request('GET', '/admin/api/form-notifications?formId='.$form->id.'&locale=en');
        self::assertSame('team@example.com', $list->at('_embedded.'.Notification::RESOURCE_KEY.'.0.to'));

        $this->client->request('DELETE', '/admin/api/form-notifications?ids='.$created->int('id'));
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->request('GET', '/admin/api/form-notifications?formId='.$form->id.'&locale=en')->at('total'));
    }

    public function testTheSubmissionsListShowsTheAnswersAsColumns(): void
    {
        $form = self::createContactForm();
        $this->storeSubmission($form);
        $this->storeSubmission($form, 'john@example.com');

        $list = $this->request('GET', '/admin/api/form-submissions?formId='.$form->id.'&locale=en&page=1&limit=10');
        self::assertSame(2, $list->at('total'));
        self::assertSame('john@example.com', $list->at('_embedded.'.Submission::RESOURCE_KEY.'.0.field_email'), 'Newest first.');

        $search = $this->request('GET', '/admin/api/form-submissions?formId='.$form->id.'&locale=en&search=JANE');
        self::assertSame(1, $search->at('total'));

        $columns = $this->request('GET', '/admin/metadata/list/'.Submission::LIST_KEY.'?formId='.$form->id.'&locale=en');
        self::assertSame('always', $columns->at('field_email.visibility'), 'The field the editor marked "show in list".');
        self::assertSame('no', $columns->at('field_message.visibility'));
        self::assertSame('E-mail', $columns->at('field_email.label'));
        self::assertSame('datetime', $columns->at('createdAt.type'));
    }

    public function testASubmissionIsReadWithEveryAnswerAndItsFiles(): void
    {
        $form = self::createContactForm();
        $this->storeSubmission($form, withFile: true);
        $id = self::submissions()[0]->id;

        $submission = $this->request('GET', '/admin/api/form-submissions/'.$id.'?locale=en');

        self::assertSame('Contact', $submission->at('formTitle'));
        self::assertSame(['email', 'cv'], $submission->column('values', 'key'));
        $file = $submission->get('values.1.files.0');
        self::assertSame('cv.pdf', $file->at('name'));

        $this->client->request('GET', $file->string('url'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment; filename=cv.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testSubmissionsAreDeletedWithTheirFiles(): void
    {
        $form = self::createContactForm();
        $file = $this->storeSubmission($form, withFile: true);
        $id = self::submissions()[0]->id;

        $this->client->request('DELETE', '/admin/api/form-submissions?ids='.$id);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], self::submissions());
        self::assertFileDoesNotExist($file);
    }

    public function testTheViewsAndTheNavigationAreRegistered(): void
    {
        $this->client->request('GET', '/admin/config');
        self::assertResponseIsSuccessful();
        $config = (string) $this->client->getResponse()->getContent();

        foreach (['eekes_form_builder.forms.list', 'eekes_form_builder.forms.edit_form.builder', 'eekes_form_builder.forms.edit_form.notifications', 'eekes_form_builder.forms.edit_form.submissions', 'eekes_form_builder.show_submission', 'single_eekes_form_selection'] as $expected) {
            self::assertStringContainsString($expected, $config);
        }
    }

    public function testAnUnknownFormIsA404(): void
    {
        $this->client->request('GET', '/admin/api/forms/999?locale=en');

        self::assertResponseStatusCodeSame(404);
    }

    private function createViaApi(string $title = 'Contact'): int
    {
        $form = $this->request('POST', '/admin/api/forms?locale=en', ['title' => $title]);

        return $form->int('id');
    }

    /**
     * @return string the path of the stored file, when there is one
     */
    private function storeSubmission(Form $form, string $email = 'jane@example.com', bool $withFile = false): string
    {
        $values = [new SubmittedValue('email', 'E-mail', 'email', $email, $email, true)];
        $path = '';

        if ($withFile) {
            $directory = self::getContainer()->getParameter('eekes_sulu_form_builder.upload_directory');
            self::assertIsString($directory);
            $path = $directory.'/test/'.uniqid().'.pdf';
            (new Filesystem())->dumpFile($path, '%PDF');
            $values[] = new SubmittedValue('cv', 'CV', 'file', ['cv.pdf'], 'cv.pdf', files: [new StoredFile(substr($path, \strlen($directory) + 1), 'cv.pdf', 'application/pdf', 4)]);
        }

        $submission = new Submission(self::entityManager()->getReference(Form::class, $form->id) ?? $form, 'en', $values);
        self::entityManager()->persist($submission);
        self::entityManager()->flush();
        // Two submissions in the same second still sort newest first.
        usleep(1000);

        return $path;
    }

    /**
     * @param null|array<mixed> $payload
     */
    private function request(string $method, string $uri, ?array $payload = null): Json
    {
        $this->client->jsonRequest($method, $uri, $payload ?? []);

        return Json::decode((string) $this->client->getResponse()->getContent());
    }
}
