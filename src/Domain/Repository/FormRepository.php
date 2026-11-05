<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;

/**
 * @extends ServiceEntityRepository<Form>
 */
class FormRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Form::class);
    }

    public function save(Form $form, bool $flush = true): void
    {
        $this->getEntityManager()->persist($form);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Form $form, bool $flush = true): void
    {
        $this->getEntityManager()->remove($form);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findById(int $id): ?Form
    {
        return $this->find($id);
    }

    public function findByKey(string $key): ?Form
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * A key that is not taken yet, derived from the one asked for: "contact", "contact-2", ...
     */
    public function nextFreeKey(string $key, ?int $ignoreId = null): string
    {
        $candidate = $key;
        $suffix = 1;

        while (true) {
            $existing = $this->findByKey($candidate);

            if ($existing === null || $existing->id === $ignoreId) {
                return $candidate;
            }

            $candidate = substr($key, 0, 60).'-'.++$suffix;
        }
    }
}
