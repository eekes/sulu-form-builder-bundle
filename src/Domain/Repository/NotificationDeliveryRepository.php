<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\NotificationDelivery;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;

/**
 * @extends ServiceEntityRepository<NotificationDelivery>
 */
class NotificationDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationDelivery::class);
    }

    public function save(NotificationDelivery $delivery, bool $flush = true): void
    {
        $this->getEntityManager()->persist($delivery);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findById(int $id): ?NotificationDelivery
    {
        return $this->find($id);
    }

    /**
     * @return list<NotificationDelivery> oldest first
     */
    public function findBySubmission(Submission $submission): array
    {
        return $this->findBy(['submission' => $submission], ['id' => 'ASC']);
    }
}
