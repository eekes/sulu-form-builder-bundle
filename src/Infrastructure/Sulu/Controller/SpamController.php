<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionProvider;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionProcessor;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\SubmissionRepository;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\SubmissionPresenter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The submissions a spam check caught, per form: read them, delete them, or release one that a
 * person sent after all. Guarded by a security context of its own (view, edit to release, delete),
 * because what they hold - answers and the request they came with - is not for everybody who
 * edits forms.
 */
final class SpamController extends AbstractAdminController
{
    public const string LIST_ROUTE = 'eekes_form_builder.get_spam_submissions';
    public const string DETAIL_ROUTE = 'eekes_form_builder.get_spam_submission';

    public function __construct(
        private readonly SubmissionRepository $submissionRepository,
        private readonly SubmissionPresenter $submissionPresenter,
        private readonly SubmissionProcessor $submissionProcessor,
        private readonly FormDefinitionProvider $formDefinitionProvider,
    ) {
    }

    public function getSecurityContext(): string
    {
        return Submission::SPAM_SECURITY_CONTEXT;
    }

    #[Route(path: '/form-spam', name: self::LIST_ROUTE, methods: ['GET'])]
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
            $this->submissionRepository->findPage($formId, $page, $limit, $search, $sortOrder, true),
        );

        return $this->listResponse(Submission::SPAM_RESOURCE_KEY, $rows, $page, $limit, $this->submissionRepository->countForForm($formId, $search, true));
    }

    #[Route(path: '/form-spam/{id}', name: self::DETAIL_ROUTE, requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id, Request $request): Response
    {
        // A blocked submission never keeps files, so there is nothing to link to.
        return $this->json($this->submissionPresenter->detail($this->load($id), $request->query->getString('locale', 'en'), static fn (): string => ''));
    }

    /**
     * "Not spam": the submission becomes a real one, and its notifications and flows run.
     */
    #[Route(path: '/form-spam/{id}', name: 'eekes_form_builder.post_spam_submission_trigger', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function postTrigger(int $id, Request $request): Response
    {
        if ($request->query->getString('action') !== 'release') {
            throw new BadRequestHttpException(\sprintf('Unknown action "%s".', $request->query->getString('action')));
        }

        $submission = $this->load($id);

        if ($submission->values() === []) {
            throw new BadRequestHttpException('This blocked submission was stored without its answers, so there is nothing to release.');
        }

        $definition = $this->formDefinitionProvider->getById($submission->form->id, $submission->locale)
            ?? throw self::notFound('form', $submission->form->id);

        $this->submissionProcessor->release($definition, $submission);

        return $this->json(['id' => $id, 'spam' => false]);
    }

    #[Route(path: '/form-spam/{id}', name: 'eekes_form_builder.delete_spam_submission', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->submissionRepository->remove($this->load($id));

        return $this->noContent();
    }

    #[Route(path: '/form-spam', name: 'eekes_form_builder.delete_spam_submissions', methods: ['DELETE'])]
    public function deleteSelection(Request $request): Response
    {
        foreach ($this->submissionRepository->findByIds(self::ids($request)) as $submission) {
            if ($submission->isSpam()) {
                $this->submissionRepository->remove($submission);
            }
        }

        return $this->noContent();
    }

    /**
     * Only blocked submissions: a real one is reached through the submissions resource.
     */
    private function load(int $id): Submission
    {
        $submission = $this->submissionRepository->findById($id);

        return $submission !== null && $submission->isSpam() ? $submission : throw self::notFound('blocked submission', $id);
    }
}
