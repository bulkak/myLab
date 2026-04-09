<?php

namespace App\Repository;

use App\Entity\Metric;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Metric>
 */
class MetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Metric::class);
    }

    public function save(Metric $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Metric $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Search metrics by name for a user
     * @return Metric[]
     */
    public function searchByName(User $user, string $searchTerm): array
    {
        // We can't use groupBy directly on entities in PostgreSQL without aggregating all other columns.
        // Instead, we fetch all matching metrics and group them in PHP, or just return them ordered.
        return $this->createQueryBuilder('m')
            ->join('m.analysis', 'a')
            ->andWhere('a.user = :user')
            ->andWhere('a.isConfirmed = true')
            ->andWhere('LOWER(m.name) LIKE LOWER(:term) OR LOWER(m.canonicalName) LIKE LOWER(:term)')
            ->setParameter('user', $user)
            ->setParameter('term', '%' . $searchTerm . '%')
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get metric history for a specific metric name
     * @return Metric[]
     */
    public function getMetricHistory(User $user, string $metricName): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.analysis', 'a')
            ->andWhere('a.user = :user')
            ->andWhere('LOWER(m.name) = LOWER(:name) OR LOWER(m.canonicalName) = LOWER(:name)')
            ->setParameter('user', $user)
            ->setParameter('name', $metricName)
            ->orderBy('a.analysisDate', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all unique metric names for a user (for autocomplete)
     *
     * @return array<int, array{name: string}>
     */
    public function getUniqueMetricNames(User $user): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT DISTINCT COALESCE(NULLIF(m.canonical_name, \'\'), m.name) as name
            FROM metrics m
            JOIN analyses a ON m.analysis_id = a.id
            WHERE a.user_id = :user_id
            ORDER BY name ASC
        ';

        $result = $conn->executeQuery($sql, ['user_id' => $user->getId()]);
        $rows = $result->fetchAllAssociative();

        // Ensure proper typing
        return array_map(fn ($row) => ['name' => (string) $row['name']], $rows);
    }

    /**
     * Get metrics for a specific analysis
     * @return Metric[]
     */
    public function findByAnalysisId(int $analysisId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.analysis = :analysisId')
            ->setParameter('analysisId', $analysisId)
            ->getQuery()
            ->getResult();
    }
}
