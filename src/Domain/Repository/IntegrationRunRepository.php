<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRun;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;

/**
 * @extends ServiceEntityRepository<IntegrationRun>
 */
class IntegrationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IntegrationRun::class);
    }

    public function save(IntegrationRun $run, bool $flush = true): void
    {
        $this->getEntityManager()->persist($run);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findById(int $id): ?IntegrationRun
    {
        return $this->find($id);
    }

    /**
     * @return list<IntegrationRun>
     */
    public function findBySubmission(Submission|int $submission): array
    {
        return $this->findBy(['submission' => $submission], ['id' => 'ASC']);
    }
}
