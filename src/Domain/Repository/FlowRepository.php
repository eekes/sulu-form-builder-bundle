<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;

/**
 * @extends ServiceEntityRepository<Flow>
 */
class FlowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Flow::class);
    }

    public function save(Flow $flow, bool $flush = true): void
    {
        $this->getEntityManager()->persist($flow);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Flow $flow, bool $flush = true): void
    {
        $this->getEntityManager()->remove($flow);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findById(int $id): ?Flow
    {
        return $this->find($id);
    }

    /**
     * @return list<Flow>
     */
    public function findByForm(Form|int $form): array
    {
        return $this->findBy(['form' => $form], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<Flow>
     */
    public function findEnabledByForm(Form|int $form): array
    {
        return $this->findBy(['form' => $form, 'enabled' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
