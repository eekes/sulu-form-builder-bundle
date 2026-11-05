<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function save(Notification $notification, bool $flush = true): void
    {
        $this->getEntityManager()->persist($notification);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Notification $notification, bool $flush = true): void
    {
        $this->getEntityManager()->remove($notification);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findById(int $id): ?Notification
    {
        return $this->find($id);
    }

    /**
     * @return list<Notification>
     */
    public function findByForm(Form|int $form): array
    {
        return $this->findBy(['form' => $form], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<Notification>
     */
    public function findEnabledByForm(Form|int $form): array
    {
        return $this->findBy(['form' => $form, 'enabled' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
