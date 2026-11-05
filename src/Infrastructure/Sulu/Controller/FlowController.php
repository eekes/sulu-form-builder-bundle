<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\FlowValidator;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldMapping;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FlowRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The integration flows of a form, listed in its "Integrations" tab and edited in a modal.
 */
final class FlowController extends AbstractAdminController
{
    public const string LIST_ROUTE = 'eekes_form_builder.get_flows';
    public const string DETAIL_ROUTE = 'eekes_form_builder.get_flow';

    public function __construct(
        private readonly FormRepository $formRepository,
        private readonly FlowRepository $flowRepository,
        private readonly FlowValidator $flowValidator,
        private readonly IntegrationRegistry $integrationRegistry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/form-flows', name: self::LIST_ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        $rows = array_map(fn (Flow $flow): array => [
            'id' => $flow->id,
            'name' => $flow->name,
            'integration' => $this->integrationTitle($flow),
            'enabled' => $flow->enabled,
            'conditional' => $flow->conditions() !== null,
        ], $this->flowRepository->findByForm($this->form($request)));

        return $this->listResponse(Flow::RESOURCE_KEY, $rows, 1, max(1, \count($rows)), \count($rows));
    }

    #[Route(path: '/form-flows/{id}', name: self::DETAIL_ROUTE, requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id): Response
    {
        return $this->json($this->toArray($this->load($id)));
    }

    #[Route(path: '/form-flows', name: 'eekes_form_builder.post_flow', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $form = $this->form($request);
        $flow = new Flow($form, '', '', '');
        $flow->position = \count($this->flowRepository->findByForm($form));

        return $this->save($flow, self::payload($request), Response::HTTP_CREATED);
    }

    #[Route(path: '/form-flows/{id}', name: 'eekes_form_builder.put_flow', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function put(int $id, Request $request): Response
    {
        return $this->save($this->load($id), self::payload($request), Response::HTTP_OK);
    }

    #[Route(path: '/form-flows/{id}', name: 'eekes_form_builder.delete_flow', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->flowRepository->remove($this->load($id));

        return $this->noContent();
    }

    #[Route(path: '/form-flows', name: 'eekes_form_builder.delete_flows', methods: ['DELETE'])]
    public function deleteSelection(Request $request): Response
    {
        foreach (self::ids($request) as $id) {
            $flow = $this->flowRepository->findById($id);

            if ($flow !== null) {
                $this->flowRepository->remove($flow);
            }
        }

        return $this->noContent();
    }

    /**
     * @param array<mixed> $data
     */
    private function save(Flow $flow, array $data, int $status): Response
    {
        $this->apply($flow, $data);
        $problems = $this->flowValidator->validate($flow);

        if ($problems !== []) {
            // The admin shows "detail" in its error snackbar.
            return $this->json(['title' => $problems[0], 'detail' => implode(' ', $problems), 'problems' => $problems], Response::HTTP_BAD_REQUEST);
        }

        $flow->form->touch();
        $this->flowRepository->save($flow);

        return $this->json($this->toArray($flow), $status);
    }

    /**
     * @param array<mixed> $data
     */
    private function apply(Flow $flow, array $data): void
    {
        $name = \is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $flow->name = $name !== '' ? $name : ($flow->name !== '' ? $flow->name : 'Flow');

        if (\array_key_exists('enabled', $data)) {
            $flow->enabled = (bool) $data['enabled'];
        }

        $config = \is_array($data['flow'] ?? null) ? $data['flow'] : [];

        if (\is_string($config['integration'] ?? null)) {
            $flow->integration = $config['integration'];
        }

        if (\is_string($config['action'] ?? null)) {
            $flow->action = $config['action'];
        }

        if (\is_array($config['settings'] ?? null)) {
            $flow->settings = array_filter($config['settings'], static fn (mixed $value, mixed $key): bool => \is_string($key) && ($value === null || \is_scalar($value)), \ARRAY_FILTER_USE_BOTH);
        }

        if (\is_array($config['mapping'] ?? null)) {
            $mapping = [];

            foreach ($config['mapping'] as $target => $source) {
                $fieldMapping = FieldMapping::fromArray($source);

                if (\is_string($target) && preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $target) === 1 && $fieldMapping !== null) {
                    $mapping[$target] = $fieldMapping;
                }
            }

            $flow->setMapping($mapping);
        }

        if (\array_key_exists('conditions', $data)) {
            $flow->setConditions(ConditionSet::fromArray($data['conditions']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Flow $flow): array
    {
        return [
            'id' => $flow->id,
            'formId' => $flow->form->id,
            'name' => $flow->name,
            'enabled' => $flow->enabled,
            'flow' => [
                'integration' => $flow->integration,
                'action' => $flow->action,
                // Objects even when empty, so the admin never has to tell [] and {} apart.
                'settings' => (object) $flow->settings,
                'mapping' => (object) array_map(static fn (FieldMapping $mapping): array => $mapping->toArray(), $flow->mapping()),
            ],
            'conditions' => $flow->conditions()?->toArray(),
            'problems' => $this->flowValidator->validate($flow),
        ];
    }

    private function integrationTitle(Flow $flow): string
    {
        foreach ($this->integrationRegistry->enabled() as $metadata) {
            if ($metadata->key === $flow->integration) {
                $action = $this->integrationRegistry->action($flow->integration, $flow->action);
                $title = $this->translator->trans($metadata->title, [], 'admin').($action !== null ? ': '.$this->translator->trans($action->title, [], 'admin') : '');

                // So the list shows which flows cannot run until something is configured or connected.
                return $this->integrationRegistry->status($flow->integration)->isReady()
                    ? $title
                    : $title.' ⚠ '.$this->translator->trans('eekes_form_builder.integration_not_ready', [], 'admin');
            }
        }

        return $flow->integration;
    }

    private function form(Request $request): Form
    {
        $formId = $request->query->getInt('formId');

        if ($formId === 0) {
            throw new BadRequestHttpException('The "formId" query parameter is required.');
        }

        return $this->formRepository->findById($formId) ?? throw self::notFound('form', $formId);
    }

    private function load(int $id): Flow
    {
        return $this->flowRepository->findById($id) ?? throw self::notFound('flow', $id);
    }
}
