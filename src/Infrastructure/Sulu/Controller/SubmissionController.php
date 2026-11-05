<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Application\Upload\UploadStorageInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRun;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\NotificationDelivery;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\SubmittedValue;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\IntegrationRunRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationDeliveryRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\SubmissionRepository;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\IntegrationRunPresenter;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\NotificationDeliveryPresenter;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\SubmissionPresenter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What visitors sent. Not built on Sulu's Doctrine list builder: the answers are a JSON snapshot
 * (see SubmittedValue), and the columns of the list are the fields of the form, so the rows are
 * put together here and the columns by SubmissionListMetadataLoader.
 */
final class SubmissionController extends AbstractAdminController
{
    public const string LIST_ROUTE = 'eekes_form_builder.get_submissions';
    public const string DETAIL_ROUTE = 'eekes_form_builder.get_submission';
    public const string FILE_ROUTE = 'eekes_form_builder.get_submission_file';

    public function __construct(
        private readonly SubmissionRepository $submissionRepository,
        private readonly UploadStorageInterface $uploadStorage,
        private readonly IntegrationRunRepository $integrationRunRepository,
        private readonly IntegrationRunPresenter $integrationRunPresenter,
        private readonly SubmissionPresenter $submissionPresenter,
        private readonly ?NotificationDeliveryRepository $deliveryRepository = null,
        private readonly ?NotificationDeliveryPresenter $deliveryPresenter = null,
    ) {
    }

    #[Route(path: '/form-submissions', name: self::LIST_ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        $formId = $request->query->getInt('formId');

        if ($formId === 0) {
            throw new BadRequestHttpException('The "formId" query parameter is required.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(500, $request->query->getInt('limit', 20)));
        $search = $request->query->getString('search') ?: null;
        $sortOrder = $request->query->getString('sortBy') === 'createdAt' ? $request->query->getString('sortOrder', 'desc') : 'desc';

        $locale = $request->query->getString('locale', 'en');
        $rows = array_map(
            fn (Submission $submission): array => $this->submissionPresenter->row($submission, $locale),
            $this->submissionRepository->findPage($formId, $page, $limit, $search, $sortOrder),
        );

        return $this->listResponse(Submission::RESOURCE_KEY, $rows, $page, $limit, $this->submissionRepository->countForForm($formId, $search));
    }

    #[Route(path: '/form-submissions/{id}', name: self::DETAIL_ROUTE, requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id, Request $request): Response
    {
        $submission = $this->load($id);

        return $this->json([
            ...$this->submissionPresenter->detail($submission, $request->query->getString('locale', 'en'), $this->fileUrl(...)),
            'runs' => array_map(
                fn (IntegrationRun $run): array => $this->integrationRunPresenter->toArray($run),
                $this->integrationRunRepository->findBySubmission($submission),
            ),
            'deliveries' => array_map(
                fn (NotificationDelivery $delivery): array => $this->deliveryPresenter?->toArray($delivery) ?? [],
                $this->deliveryRepository?->findBySubmission($submission) ?? [],
            ),
        ]);
    }

    /**
     * Uploads are personal data, so they are only reachable through the admin, with the same
     * permission as the submission itself.
     */
    #[Route(path: '/form-submissions/{id}/files/{key}/{index}', name: self::FILE_ROUTE, requirements: ['id' => '\d+', 'index' => '\d+'], methods: ['GET'])]
    public function download(int $id, string $key, int $index): Response
    {
        $file = $this->load($id)->get($key)?->files[$index] ?? throw self::notFound('file', $index);
        $path = $this->uploadStorage->absolutePath($file);

        if (!is_file($path)) {
            throw self::notFound('file', $index);
        }

        $response = new BinaryFileResponse($path);
        // Browsers that do not understand the UTF-8 name get the same name with the rest replaced.
        $asciiName = (string) preg_replace('/[^\x20-\x7e]|["\/\\\\%]/', '_', $file->originalName);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->originalName, $asciiName);

        return $response;
    }

    #[Route(path: '/form-submissions/{id}', name: 'eekes_form_builder.delete_submission', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->remove($this->load($id));

        return $this->noContent();
    }

    #[Route(path: '/form-submissions', name: 'eekes_form_builder.delete_submissions', methods: ['DELETE'])]
    public function deleteSelection(Request $request): Response
    {
        foreach ($this->submissionRepository->findByIds(self::ids($request)) as $submission) {
            if ($submission->isSpam()) {
                continue;
            }

            $this->remove($submission);
        }

        return $this->noContent();
    }

    private function fileUrl(Submission $submission, SubmittedValue $value, int $index): string
    {
        return $this->generateUrl(self::FILE_ROUTE, ['id' => $submission->id, 'key' => $value->key, 'index' => $index], UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    private function remove(Submission $submission): void
    {
        foreach ($submission->files() as $file) {
            $this->uploadStorage->delete($file);
        }

        $this->submissionRepository->remove($submission);
    }

    /**
     * Only real submissions: a blocked one has its own resource and permissions.
     */
    private function load(int $id): Submission
    {
        $submission = $this->submissionRepository->findById($id);

        return $submission !== null && !$submission->isSpam() ? $submission : throw self::notFound('submission', $id);
    }
}
