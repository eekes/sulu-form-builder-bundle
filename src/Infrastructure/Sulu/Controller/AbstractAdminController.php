<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Sulu\Component\Rest\ListBuilder\PaginatedRepresentation;
use Sulu\Component\Security\SecuredControllerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every endpoint of the bundle is guarded by the same security context as the views in FormAdmin:
 * Sulu's SecuredControllerInterface maps GET to view, POST to add, PUT to edit and DELETE to
 * delete.
 */
abstract class AbstractAdminController extends AbstractController implements SecuredControllerInterface
{
    public function getSecurityContext(): string
    {
        return Form::SECURITY_CONTEXT;
    }

    public function getLocale(Request $request): ?string
    {
        return $request->query->getString('locale') ?: null;
    }

    protected static function locale(Request $request): string
    {
        $locale = $request->query->getString('locale');

        if ($locale === '') {
            throw new BadRequestHttpException('The "locale" query parameter is required.');
        }

        return $locale;
    }

    /**
     * @return array<mixed>
     */
    protected static function payload(Request $request): array
    {
        $payload = $request->toArray();

        return $payload;
    }

    /**
     * @return list<int>
     */
    protected static function ids(Request $request): array
    {
        return array_values(array_map('intval', array_filter(explode(',', $request->query->getString('ids')), 'is_numeric')));
    }

    protected static function notFound(string $resource, int $id): NotFoundHttpException
    {
        return new NotFoundHttpException(\sprintf('The %s with the id "%d" does not exist.', $resource, $id));
    }

    /**
     * A page of a list in the shape the list store of the admin expects.
     *
     * @param list<array<string, mixed>> $rows
     */
    protected function listResponse(string $resourceKey, array $rows, int $page, int $limit, int $total): JsonResponse
    {
        return $this->json((new PaginatedRepresentation($rows, $resourceKey, $page, $limit, $total))->toArray());
    }

    protected function noContent(): Response
    {
        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
