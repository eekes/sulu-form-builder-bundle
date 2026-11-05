<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;

/**
 * @extends ServiceEntityRepository<Submission>
 */
class SubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Submission::class);
    }

    public function save(Submission $submission, bool $flush = true): void
    {
        $this->getEntityManager()->persist($submission);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Submission $submission, bool $flush = true): void
    {
        $this->getEntityManager()->remove($submission);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findById(int $id): ?Submission
    {
        return $this->find($id);
    }

    /**
     * One page of the submissions of a form, newest first unless asked otherwise: the real ones,
     * or with $spam the blocked ones.
     *
     * @return list<Submission>
     */
    public function findPage(int $formId, int $page, int $limit, ?string $search = null, string $sortOrder = 'desc', bool $spam = false): array
    {
        /** @var list<Submission> $result */
        $result = $this->pageQuery($formId, $search, $spam)
            ->orderBy('submission.createdAt', strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC')
            ->addOrderBy('submission.id', strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC')
            ->setFirstResult(max(0, $page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countForForm(int $formId, ?string $search = null, bool $spam = false): int
    {
        return (int) $this->pageQuery($formId, $search, $spam)
            ->select('COUNT(submission.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Submission>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->findBy(['id' => $ids]);
    }

    /**
     * Every submission of a form, one at a time, so a form with many of them fits in memory.
     *
     * @return iterable<Submission>
     */
    public function iterateByForm(Form|int $form): iterable
    {
        /** @var iterable<Submission> $submissions */
        $submissions = $this->createQueryBuilder('submission')
            ->where('submission.form = :form')
            ->setParameter('form', $form)
            ->getQuery()
            ->toIterable();

        return $submissions;
    }

    /**
     * Deletes blocked submissions older than a moment. They never keep files.
     *
     * @return int how many there were
     */
    public function deleteSpamOlderThan(\DateTimeImmutable $before, bool $dryRun = false): int
    {
        $queryBuilder = $this->createQueryBuilder('submission')
            ->where('submission.spam = true')
            ->andWhere('submission.createdAt < :before')
            ->setParameter('before', $before);

        if ($dryRun) {
            return (int) $queryBuilder->select('COUNT(submission.id)')->getQuery()->getSingleScalarResult();
        }

        $deleted = $queryBuilder->delete()->getQuery()->execute();

        return \is_int($deleted) ? $deleted : 0;
    }

    private function pageQuery(int $formId, ?string $search, bool $spam = false): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('submission')
            ->where('IDENTITY(submission.form) = :formId')
            ->andWhere('submission.spam = :spam')
            ->setParameter('formId', $formId)
            ->setParameter('spam', $spam);

        if ($search !== null && trim($search) !== '') {
            $queryBuilder
                ->andWhere('submission.searchText LIKE :search')
                ->setParameter('search', '%'.addcslashes(mb_strtolower(trim($search)), '%_').'%');
        }

        return $queryBuilder;
    }
}
