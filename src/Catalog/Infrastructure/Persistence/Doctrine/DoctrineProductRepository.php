<?php

declare(strict_types=1);

namespace Siroko\Catalog\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Catalog\Domain\ProductStatus;

final class DoctrineProductRepository implements ProductRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findById(ProductId $id): ?Product
    {
        return $this->em->find(Product::class, $id->value());
    }

    /** @return list<Product> */
    public function findAllActive(): array
    {
        /** @var list<Product> */
        return $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.status = :status')
            ->setParameter('status', ProductStatus::ACTIVE->value)
            ->getQuery()
            ->getResult();
    }

    public function save(Product $product): void
    {
        $this->em->persist($product);
        $this->em->flush();
    }
}
