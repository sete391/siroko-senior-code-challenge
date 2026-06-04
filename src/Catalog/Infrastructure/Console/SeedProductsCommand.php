<?php

declare(strict_types=1);

namespace Siroko\Catalog\Infrastructure\Console;

use Siroko\Catalog\Domain\Product;
use Siroko\Catalog\Domain\ProductId;
use Siroko\Catalog\Domain\ProductRepository;
use Siroko\Catalog\Domain\ProductStatus;
use Siroko\Shared\Application\Identity\IdGenerator;
use Siroko\Shared\Domain\ValueObject\Money;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-products',
    description: 'Seed the database with sample Siroko cycling products.',
)]
final class SeedProductsCommand extends Command
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly IdGenerator $idGenerator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $products = $this->catalog();

        foreach ($products as $data) {
            $product = new Product(
                id:          new ProductId($this->idGenerator->generate()),
                name:        $data['name'],
                description: $data['description'],
                taxAmount:   $data['taxAmount'],
                unitPrice:   new Money($data['unitPriceAmount']),
                quantity:    $data['quantity'],
                status:      ProductStatus::ACTIVE,
                createdAt:   new \DateTimeImmutable(),
                updatedAt:   new \DateTimeImmutable(),
            );

            $this->productRepository->save($product);
            $io->writeln(sprintf('  Created: %s (%d units @ %d cents)', $data['name'], $data['quantity'], $data['unitPriceAmount']));
        }

        $io->success(sprintf('Seeded %d products.', count($products)));

        return Command::SUCCESS;
    }

    /** @return list<array{name: string, description: string, taxAmount: int, unitPriceAmount: int, quantity: int}> */
    private function catalog(): array
    {
        return [
            [
                'name'            => 'Siroko K3s Cycling Jersey',
                'description'     => 'Lightweight, breathable road cycling jersey with aero fit.',
                'taxAmount'       => 945,    // 21% VAT on 4500 cents = 945 cents
                'unitPriceAmount' => 4500,   // €45.00
                'quantity'        => 50,
            ],
            [
                'name'            => 'Siroko R3 Cycling Bib Shorts',
                'description'     => 'High-performance bib shorts with ergonomic chamois.',
                'taxAmount'       => 1260,   // 21% VAT on 6000 cents
                'unitPriceAmount' => 6000,   // €60.00
                'quantity'        => 40,
            ],
            [
                'name'            => 'Siroko Tech Cycling Socks',
                'description'     => 'Compression socks with ventilation zones for long rides.',
                'taxAmount'       => 168,    // 21% VAT on 800 cents
                'unitPriceAmount' => 800,    // €8.00
                'quantity'        => 200,
            ],
            [
                'name'            => 'Siroko G3 Cycling Gloves',
                'description'     => 'Summer half-finger gloves with gel padding.',
                'taxAmount'       => 441,    // 21% VAT on 2100 cents
                'unitPriceAmount' => 2100,   // €21.00
                'quantity'        => 75,
            ],
            [
                'name'            => 'Siroko AERO Cycling Helmet',
                'description'     => 'UCI-approved aero road helmet with integrated visor.',
                'taxAmount'       => 2520,   // 21% VAT on 12000 cents
                'unitPriceAmount' => 12000,  // €120.00
                'quantity'        => 25,
            ],
        ];
    }
}
