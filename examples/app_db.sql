-- app_db.sql
-- MySQL schema for the "app_db" database used by DBLayer examples.

CREATE
DATABASE IF NOT EXISTS `app_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE
`app_db`;

-- 1) users
CREATE TABLE `users`
(
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(255) NOT NULL,
    `email`         VARCHAR(191) NOT NULL,
    `role`          VARCHAR(50)  NOT NULL DEFAULT 'user',
    `active`        TINYINT(1)       NOT NULL DEFAULT 1,
    `last_order_at` DATETIME NULL,
    `created_at`    DATETIME     NOT NULL,
    `updated_at`    DATETIME NULL,
    `deleted_at`    DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_email_unique` (`email`),
    KEY             `users_active_id_index` (`active`, `id`),
    KEY             `users_role_index` (`role`)
) ENGINE=InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- 2) orders
CREATE TABLE `orders`
(
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `status`     VARCHAR(50) NOT NULL DEFAULT 'pending',
    `total`      BIGINT      NOT NULL,
    `created_at` DATETIME    NOT NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY          `orders_user_id_created_at_index` (`user_id`, `created_at`),
    CONSTRAINT `orders_user_id_fk`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- 3) order_items
CREATE TABLE `order_items`
(
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`   BIGINT UNSIGNED NOT NULL,
    `sku`        VARCHAR(64) NOT NULL,
    `qty`        INT UNSIGNED     NOT NULL,
    `unit_price` BIGINT      NOT NULL,
    `created_at` DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    KEY          `order_items_order_id_index` (`order_id`),
    KEY          `order_items_sku_index` (`sku`),
    CONSTRAINT `order_items_order_id_fk`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
            ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- 4) audit_logs
CREATE TABLE `audit_logs`
(
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NULL,
    `event_type` VARCHAR(100) NOT NULL,
    `message`    TEXT         NOT NULL,
    `metadata`   JSON NULL,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY          `audit_logs_created_at_index` (`created_at`),
    KEY          `audit_logs_user_id_index` (`user_id`),
    CONSTRAINT `audit_logs_user_id_fk`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- 5) wallets
CREATE TABLE `wallets`
(
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `balance`    BIGINT   NOT NULL DEFAULT 0,
    `currency`   CHAR(3)  NOT NULL DEFAULT 'BDT',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `wallets_user_id_unique` (`user_id`),
    CONSTRAINT `wallets_user_id_fk`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- 6) wallet_movements
CREATE TABLE `wallet_movements`
(
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `amount`     BIGINT       NOT NULL,
    `reason`     VARCHAR(100) NOT NULL,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY          `wallet_movements_user_id_created_at_index` (`user_id`, `created_at`),
    CONSTRAINT `wallet_movements_user_id_fk`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
