<?php

namespace App\Repository;

use App\Entity\MetricAlias;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MetricAlias>
 */
class MetricAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetricAlias::class);
    }

    public function save(MetricAlias $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(MetricAlias $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find alias by user and original name
     */
    public function findByUserAndOriginalName(User $user, string $originalName): ?MetricAlias
    {
        return $this->createQueryBuilder('ma')
            ->andWhere('ma.user = :user')
            ->andWhere('LOWER(ma.originalName) = LOWER(:originalName)')
            ->setParameter('user', $user)
            ->setParameter('originalName', $originalName)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find canonical name for an original name
     */
    public function findCanonicalName(User $user, string $originalName): ?string
    {
        $alias = $this->findByUserAndOriginalName($user, $originalName);
        return $alias?->getCanonicalName();
    }

    /**
     * Get all aliases for a user
     * @return MetricAlias[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('ma')
            ->andWhere('ma.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ma.canonicalName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all canonical names for a user (for autocomplete)
     *
     * @return array<int, string>
     */
    public function getCanonicalNames(User $user): array
    {
        return $this->createQueryBuilder('ma')
            ->select('DISTINCT ma.canonicalName')
            ->andWhere('ma.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ma.canonicalName', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Search canonical names for autocomplete
     * @return string[]
     */
    public function searchCanonicalNames(User $user, string $searchTerm, int $limit = 20): array
    {
        return $this->createQueryBuilder('ma')
            ->select('DISTINCT ma.canonicalName')
            ->andWhere('ma.user = :user')
            ->andWhere('LOWER(ma.canonicalName) LIKE LOWER(:term)')
            ->setParameter('user', $user)
            ->setParameter('term', '%' . $searchTerm . '%')
            ->setMaxResults($limit)
            ->orderBy('ma.canonicalName', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }
}
