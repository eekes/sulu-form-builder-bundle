<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRunner;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRunAttempt;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\IntegrationRunRepository;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\IntegrationRunPresenter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Retrying a run from the submission modal. The retry runs right away, in this request, so the
 * editor sees the outcome without waiting for a worker.
 */
final class IntegrationRunController extends AbstractAdminController
{
    public const string RESOURCE_KEY = 'eekes_form_integration_runs';
    public const string ROUTE = 'eekes_form_builder.post_integration_run_trigger';

    public function __construct(
        private readonly IntegrationRunRepository $integrationRunRepository,
        private readonly IntegrationRunner $integrationRunner,
        private readonly IntegrationRunPresenter $integrationRunPresenter,
    ) {
    }

    #[Route(path: '/form-integration-runs/{id}', name: self::ROUTE, requirements: ['id' => '\d+'], methods: ['POST'])]
    public function postTrigger(int $id, Request $request): Response
    {
        if ($request->query->getString('action') !== 'retry') {
            throw new BadRequestHttpException(\sprintf('Unknown action "%s".', $request->query->getString('action')));
        }

        $run = $this->integrationRunRepository->findById($id) ?? throw self::notFound('integration run', $id);

        return $this->json($this->integrationRunPresenter->toArray($this->integrationRunner->execute($run, IntegrationRunAttempt::TRIGGER_EDITOR, $this->getUser()?->getUserIdentifier())));
    }
}
