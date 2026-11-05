<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Application\Form\FieldKeyRenamer;
use Eekes\Sulu\FormBuilderBundle\Application\Upload\UploadStorageInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FlowRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\SubmissionRepository;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Activity\FormCopiedEvent;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Activity\FormCreatedEvent;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Activity\FormModifiedEvent;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Activity\FormRemovedEvent;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\FormAdminMapper;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;
use Sulu\Component\Rest\ListBuilder\Doctrine\DoctrineListBuilderFactoryInterface;
use Sulu\Component\Rest\ListBuilder\Metadata\FieldDescriptorFactoryInterface;
use Sulu\Component\Rest\ListBuilder\PaginatedRepresentation;
use Sulu\Component\Rest\RestHelperInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class FormController extends AbstractAdminController
{
    public const string LIST_ROUTE = 'eekes_form_builder.get_forms';
    public const string DETAIL_ROUTE = 'eekes_form_builder.get_form';

    public function __construct(
        private readonly FormRepository $formRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly FlowRepository $flowRepository,
        private readonly SubmissionRepository $submissionRepository,
        private readonly FormAdminMapper $formAdminMapper,
        private readonly FieldKeyRenamer $fieldKeyRenamer,
        private readonly UploadStorageInterface $uploadStorage,
        private readonly DomainEventCollectorInterface $domainEventCollector,
        private readonly RestHelperInterface $restHelper,
        private readonly FieldDescriptorFactoryInterface $fieldDescriptorFactory,
        private readonly DoctrineListBuilderFactoryInterface $listBuilderFactory,
    ) {
    }

    #[Route(path: '/forms', name: self::LIST_ROUTE, methods: ['GET'])]
    public function list(): Response
    {
        $listBuilder = $this->listBuilderFactory->create(Form::class);
        $fieldDescriptors = $this->fieldDescriptorFactory->getFieldDescriptors(Form::LIST_KEY)
            ?? throw new \LogicException(\sprintf('The list "%s" is not registered.', Form::LIST_KEY));

        $this->restHelper->initializeListBuilder($listBuilder, $fieldDescriptors);

        $list = new PaginatedRepresentation(
            $listBuilder->execute(),
            Form::RESOURCE_KEY,
            (int) $listBuilder->getCurrentPage(),
            (int) $listBuilder->getLimit(),
            (int) $listBuilder->count(),
        );

        return $this->json($list->toArray());
    }

    #[Route(path: '/forms/{id}', name: self::DETAIL_ROUTE, requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id, Request $request): Response
    {
        return $this->json($this->formAdminMapper->toArray($this->load($id), self::locale($request)));
    }

    #[Route(path: '/forms', name: 'eekes_form_builder.post_form', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $locale = self::locale($request);
        $payload = self::payload($request);
        $title = \is_string($payload['title'] ?? null) && trim($payload['title']) !== '' ? trim($payload['title']) : 'Form';

        $form = new Form('form', $title);
        $this->formAdminMapper->apply($form, [...$payload, 'key' => $payload['key'] ?? ''], $locale);
        $this->formRepository->save($form);

        $this->domainEventCollector->collect(new FormCreatedEvent($form));
        $this->formRepository->save($form);

        return $this->json($this->formAdminMapper->toArray($form, $locale), Response::HTTP_CREATED);
    }

    #[Route(path: '/forms/{id}', name: 'eekes_form_builder.put_form', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function put(int $id, Request $request): Response
    {
        $locale = self::locale($request);
        $form = $this->load($id);

        $before = $form->structure();
        $this->formAdminMapper->apply($form, self::payload($request), $locale);
        $this->fieldKeyRenamer->rename($form, $form->structure()->renamedFieldKeys($before));
        $this->domainEventCollector->collect(new FormModifiedEvent($form));
        $this->formRepository->save($form);

        return $this->json($this->formAdminMapper->toArray($form, $locale));
    }

    /**
     * Actions on an existing form, the way Sulu's own toolbar actions call them: "copy" is what
     * the "Create copy" toolbar action sends.
     */
    #[Route(path: '/forms/{id}', name: 'eekes_form_builder.post_form_trigger', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function postTrigger(int $id, Request $request): Response
    {
        return match ($request->query->getString('action')) {
            'copy' => $this->copy($this->load($id), $request->query->getString('locale') ?: 'en'),
            default => throw new BadRequestHttpException(\sprintf('Unknown action "%s".', $request->query->getString('action'))),
        };
    }

    /**
     * A copy with its notifications and integration flows, but without its submissions: those were
     * sent to the original.
     */
    private function copy(Form $original, string $locale): Response
    {
        $copy = clone $original;
        $copy->title = $original->title.' (2)';
        $copy->setKey($this->formRepository->nextFreeKey($original->key));
        $this->formRepository->save($copy, false);

        foreach ($this->notificationRepository->findByForm($original) as $notification) {
            $notificationCopy = clone $notification;
            $notificationCopy->form = $copy;
            $this->notificationRepository->save($notificationCopy, false);
        }

        foreach ($this->flowRepository->findByForm($original) as $flow) {
            $flowCopy = clone $flow;
            $flowCopy->form = $copy;
            $this->flowRepository->save($flowCopy, false);
        }

        $this->formRepository->save($copy);
        $this->domainEventCollector->collect(new FormCopiedEvent($copy));
        $this->formRepository->save($copy);

        return $this->json($this->formAdminMapper->toArray($copy, $locale), Response::HTTP_CREATED);
    }

    #[Route(path: '/forms/{id}', name: 'eekes_form_builder.delete_form', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->remove($this->load($id));

        return $this->noContent();
    }

    #[Route(path: '/forms', name: 'eekes_form_builder.delete_forms', methods: ['DELETE'])]
    public function deleteSelection(Request $request): Response
    {
        foreach (self::ids($request) as $id) {
            $form = $this->formRepository->findById($id);

            if ($form !== null) {
                $this->remove($form);
            }
        }

        return $this->noContent();
    }

    private function remove(Form $form): void
    {
        // The database removes the submissions with the form; their files are not in the database.
        foreach ($this->submissionRepository->findBy(['form' => $form]) as $submission) {
            foreach ($submission->files() as $file) {
                $this->uploadStorage->delete($file);
            }
        }

        $this->domainEventCollector->collect(new FormRemovedEvent($form));
        $this->formRepository->remove($form);
    }

    private function load(int $id): Form
    {
        return $this->formRepository->findById($id) ?? throw self::notFound('form', $id);
    }
}
