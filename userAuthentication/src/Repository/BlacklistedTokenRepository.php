<?php

namespace App\Repository;

use App\Entity\BlacklistedToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlacklistedToken>
 *
 * @method BlacklistedToken|null find($id, $lockMode = null, $lockVersion = null)
 * @method BlacklistedToken|null findOneBy(array $criteria, array $orderBy = null)
 * @method BlacklistedToken[]    findAll()
 * @method BlacklistedToken[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BlacklistedTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlacklistedToken::class);
    }

//    /**
//     * @return BlacklistedToken[] Returns an array of BlacklistedToken objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('b')
//            ->andWhere('b.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('b.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?BlacklistedToken
//    {
//        return $this->createQueryBuilder('b')
//            ->andWhere('b.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
