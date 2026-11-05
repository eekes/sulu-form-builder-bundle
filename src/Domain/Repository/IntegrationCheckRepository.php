<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationCheck;

/**
 * @extends ServiceEntityRepository<IntegrationCheck>
 */
class IntegrationCheckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IntegrationCheck::class);
    }

    public function findByIntegration(string $integration): ?IntegrationCheck
    {
        return $this->findOneBy(['integration' => $integration]);
    }

    public function save(IntegrationCheck $check, bool $flush = true): void
    {
        $this->getEntityManager()->persist($check);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
