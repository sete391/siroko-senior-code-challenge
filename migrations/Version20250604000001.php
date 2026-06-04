<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250604000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create initial schema: products, carts, cart_items, orders, order_items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE products (
                id                   CHAR(36)     NOT NULL,
                name                 VARCHAR(255) NOT NULL,
                description          TEXT         NOT NULL,
                tax_amount           INT UNSIGNED NOT NULL,
                unit_price_amount    INT UNSIGNED NOT NULL,
                unit_price_currency  CHAR(3)      NOT NULL,
                quantity             INT UNSIGNED NOT NULL,
                status               VARCHAR(16)  NOT NULL,
                created_at           DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at           DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE carts (
                id          CHAR(36)    NOT NULL,
                customer_id CHAR(36)    NULL,
                status      VARCHAR(16) NOT NULL,
                created_at  DATETIME    NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at  DATETIME    NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_cart_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE cart_items (
                id                   CHAR(36)     NOT NULL,
                cart_id              CHAR(36)     NOT NULL,
                product_id           CHAR(36)     NOT NULL,
                unit_price_amount    INT UNSIGNED NOT NULL,
                unit_price_currency  CHAR(3)      NOT NULL,
                tax_amount           INT UNSIGNED NOT NULL,
                quantity             INT UNSIGNED NOT NULL CHECK (quantity >= 1),
                PRIMARY KEY (id),
                UNIQUE KEY uniq_cart_product (cart_id, product_id),
                CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE orders (
                id                        CHAR(36)     NOT NULL,
                cart_id                   CHAR(36)     NOT NULL,
                customer_id               CHAR(36)     NULL,
                total_products_amount     INT UNSIGNED NOT NULL,
                total_products_currency   CHAR(3)      NOT NULL,
                total_tax_amount          INT UNSIGNED NOT NULL,
                total_tax_currency        CHAR(3)      NOT NULL,
                total_order_amount        INT UNSIGNED NOT NULL,
                total_order_currency      CHAR(3)      NOT NULL,
                shipping_first_name       VARCHAR(255) NOT NULL,
                shipping_last_name        VARCHAR(255) NOT NULL,
                shipping_vat_number       VARCHAR(64)  NOT NULL,
                shipping_street           VARCHAR(255) NOT NULL,
                shipping_city             VARCHAR(255) NOT NULL,
                shipping_state            VARCHAR(255) NOT NULL,
                shipping_zip_code         VARCHAR(16)  NOT NULL,
                shipping_country          VARCHAR(8)   NOT NULL,
                status                    VARCHAR(24)  NOT NULL,
                created_at                DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at                DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE order_items (
                id                   CHAR(36)     NOT NULL,
                order_id             CHAR(36)     NOT NULL,
                product_id           CHAR(36)     NOT NULL,
                unit_price_amount    INT UNSIGNED NOT NULL,
                unit_price_currency  CHAR(3)      NOT NULL,
                tax_amount           INT UNSIGNED NOT NULL,
                quantity             INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE order_items');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE cart_items');
        $this->addSql('DROP TABLE carts');
        $this->addSql('DROP TABLE products');
    }
}
