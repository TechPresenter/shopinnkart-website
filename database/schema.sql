-- ===========================================================================
--  ShopInnKart - shopinnkart.com
--  Festive & Decorative Lighting E-Commerce Platform
--  SINGLE SOURCE OF TRUTH for database structure + demo seed data.
--
--  Engine : InnoDB / utf8mb4_unicode_ci
--  Target : MySQL 8+ / MariaDB 10.4+
--
--  Import :  mysql -u root < database/schema.sql
--  or run  :  /install.php
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

CREATE DATABASE IF NOT EXISTS `shopinnkart`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `shopinnkart`;

-- ===========================================================================
--  SECTION 1 : SETTINGS & SEO
-- ===========================================================================

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_group` VARCHAR(50)  NOT NULL DEFAULT 'general',
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` LONGTEXT     NULL,
    `setting_type`  ENUM('text','textarea','number','boolean','select','color','image','json') NOT NULL DEFAULT 'text',
    `label`         VARCHAR(150) NULL,
    `sort_order`    INT NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_key` (`setting_key`),
    KEY `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `seo_settings`;
CREATE TABLE `seo_settings` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_key`         VARCHAR(100) NOT NULL,
    `page_label`       VARCHAR(150) NOT NULL,
    `meta_title`       VARCHAR(255) NULL,
    `meta_description` TEXT NULL,
    `meta_keywords`    TEXT NULL,
    `focus_keyword`    VARCHAR(120) NULL,
    `og_title`         VARCHAR(255) NULL,
    `og_description`   TEXT NULL,
    `og_image`         VARCHAR(255) NULL,
    `twitter_title`       VARCHAR(255) NULL,
    `twitter_description` TEXT NULL,
    `twitter_image`       VARCHAR(255) NULL,
    `robots`           VARCHAR(60) NOT NULL DEFAULT 'index, follow',
    `canonical`        VARCHAR(255) NULL,
    `schema_json`      TEXT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_seo_page` (`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `redirects`;
CREATE TABLE `redirects` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_path` VARCHAR(255) NOT NULL,
    `target_path` VARCHAR(255) NOT NULL,
    `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    `is_regex`    TINYINT(1) NOT NULL DEFAULT 0,
    `hits`        INT UNSIGNED NOT NULL DEFAULT 0,
    `last_hit_at` DATETIME NULL,
    `notes`       VARCHAR(255) NULL,
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_redirect_source` (`source_path`),
    KEY `idx_redirect_lookup` (`status`, `is_regex`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===========================================================================
--  SECTION 2 : ADMIN, ROLES & PERMISSIONS
-- ===========================================================================

DROP TABLE IF EXISTS `admin_roles`;
CREATE TABLE `admin_roles` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100) NOT NULL,
    `slug`        VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `permissions` LONGTEXT NULL COMMENT 'JSON array of "module.action" strings, or ["*"] for super admin',
    `is_system`   TINYINT(1) NOT NULL DEFAULT 0,
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admin_permissions`;
CREATE TABLE `admin_permissions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module`     VARCHAR(60) NOT NULL,
    `action`     VARCHAR(30) NOT NULL,
    `label`      VARCHAR(150) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_permission` (`module`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id`        INT UNSIGNED NOT NULL,
    `name`           VARCHAR(150) NOT NULL,
    `username`       VARCHAR(60)  NOT NULL,
    `email`          VARCHAR(190) NOT NULL,
    `password`       VARCHAR(255) NOT NULL,
    `phone`          VARCHAR(20) NULL,
    `avatar`         VARCHAR(255) NULL,
    `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `failed_logins`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`   DATETIME NULL,
    `last_login_at`  DATETIME NULL,
    `last_login_ip`  VARCHAR(45) NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_email` (`email`),
    UNIQUE KEY `uq_admin_username` (`username`),
    KEY `idx_admin_role` (`role_id`),
    KEY `idx_admin_status` (`status`),
    CONSTRAINT `fk_admin_role` FOREIGN KEY (`role_id`) REFERENCES `admin_roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Multi-vendor foundation.
--  The storefront runs single-vendor until settings.multivendor_enabled = 1;
--  every product/order row already carries vendor_id so enabling it later
--  needs no schema rebuild.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `vendors`;
CREATE TABLE `vendors` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NULL,
    `store_name`      VARCHAR(180) NOT NULL,
    `slug`            VARCHAR(200) NOT NULL,
    `owner_name`      VARCHAR(150) NULL,
    `email`           VARCHAR(190) NOT NULL,
    `phone`           VARCHAR(20) NULL,
    `password`        VARCHAR(255) NULL,
    `logo`            VARCHAR(255) NULL,
    `banner`          VARCHAR(255) NULL,
    `description`     TEXT NULL,
    `address`         VARCHAR(255) NULL,
    `city`            VARCHAR(100) NULL,
    `state`           VARCHAR(100) NULL,
    `pincode`         VARCHAR(10) NULL,
    `gst_number`      VARCHAR(30) NULL,
    `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `rating_avg`      DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    `rating_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `is_verified`     TINYINT(1) NOT NULL DEFAULT 0,
    `status`          ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
    `approved_at`     DATETIME NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_vendor_slug` (`slug`),
    UNIQUE KEY `uq_vendor_email` (`email`),
    KEY `idx_vendor_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `vendor_payouts`;
CREATE TABLE `vendor_payouts` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vendor_id`   INT UNSIGNED NOT NULL,
    `amount`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `commission`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `period_from` DATE NULL,
    `period_to`   DATE NULL,
    `reference`   VARCHAR(120) NULL,
    `status`      ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
    `paid_at`     DATETIME NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payout_vendor` (`vendor_id`),
    CONSTRAINT `fk_payout_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 3 : CUSTOMERS
-- ===========================================================================

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `first_name`        VARCHAR(100) NOT NULL,
    `last_name`         VARCHAR(100) NULL,
    `email`             VARCHAR(190) NOT NULL,
    `phone`             VARCHAR(20) NULL,
    `password`          VARCHAR(255) NOT NULL,
    `avatar`            VARCHAR(255) NULL,
    `gender`            ENUM('male','female','other') NULL,
    `date_of_birth`     DATE NULL,
    `status`            ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
    `email_verified_at` DATETIME NULL,
    `failed_logins`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`      DATETIME NULL,
    `last_login_at`     DATETIME NULL,
    `last_login_ip`     VARCHAR(45) NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_email` (`email`),
    KEY `idx_user_phone` (`phone`),
    KEY `idx_user_status` (`status`),
    KEY `idx_user_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_addresses`;
CREATE TABLE `user_addresses` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `label`         VARCHAR(50) NOT NULL DEFAULT 'Home',
    `full_name`     VARCHAR(150) NOT NULL,
    `phone`         VARCHAR(20) NOT NULL,
    `address_line1` VARCHAR(255) NOT NULL,
    `address_line2` VARCHAR(255) NULL,
    `landmark`      VARCHAR(150) NULL,
    `city`          VARCHAR(100) NOT NULL,
    `state`         VARCHAR(100) NOT NULL,
    `pincode`       VARCHAR(10) NOT NULL,
    `country`       VARCHAR(100) NOT NULL DEFAULT 'India',
    `address_type`  ENUM('home','work','other') NOT NULL DEFAULT 'home',
    `is_default`    TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_address_user` (`user_id`),
    CONSTRAINT `fk_address_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(190) NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `user_type`  ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    `expires_at` DATETIME NOT NULL,
    `used_at`    DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_reset_email` (`email`),
    KEY `idx_reset_token` (`token_hash`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 4 : CATALOG STRUCTURE
-- ===========================================================================

DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id`        INT UNSIGNED NULL,
    `name`             VARCHAR(150) NOT NULL,
    `slug`             VARCHAR(180) NOT NULL,
    `description`      TEXT NULL,
    `image`            VARCHAR(255) NULL,
    `icon`             VARCHAR(255) NULL,
    `banner`           VARCHAR(255) NULL,
    `sort_order`       INT NOT NULL DEFAULT 0,
    `is_featured`      TINYINT(1) NOT NULL DEFAULT 0,
    `show_in_menu`     TINYINT(1) NOT NULL DEFAULT 1,
    `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `meta_title`       VARCHAR(255) NULL,
    `meta_description` TEXT NULL,
    `focus_keyword`    VARCHAR(120) NULL,
    `canonical_url`    VARCHAR(255) NULL,
    `robots`           VARCHAR(60)  NULL,
    `og_title`         VARCHAR(255) NULL,
    `og_description`   TEXT NULL,
    `og_image`         VARCHAR(255) NULL,
    `schema_json`      TEXT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_category_slug` (`slug`),
    KEY `idx_category_parent` (`parent_id`),
    KEY `idx_category_status` (`status`),
    KEY `idx_category_featured` (`is_featured`),
    CONSTRAINT `fk_category_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `brands`;
CREATE TABLE `brands` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(150) NOT NULL,
    `slug`             VARCHAR(180) NOT NULL,
    `logo`             VARCHAR(255) NULL,
    `description`      TEXT NULL,
    `website`          VARCHAR(255) NULL,
    `sort_order`       INT NOT NULL DEFAULT 0,
    `is_featured`      TINYINT(1) NOT NULL DEFAULT 0,
    `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `meta_title`       VARCHAR(255) NULL,
    `meta_description` TEXT NULL,
    `focus_keyword`    VARCHAR(120) NULL,
    `canonical_url`    VARCHAR(255) NULL,
    `robots`           VARCHAR(60)  NULL,
    `og_title`         VARCHAR(255) NULL,
    `og_description`   TEXT NULL,
    `og_image`         VARCHAR(255) NULL,
    `schema_json`      TEXT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_brand_slug` (`slug`),
    KEY `idx_brand_status` (`status`),
    KEY `idx_brand_featured` (`is_featured`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `attributes`;
CREATE TABLE `attributes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(100) NOT NULL,
    `slug`       VARCHAR(120) NOT NULL,
    `type`       ENUM('select','color','text') NOT NULL DEFAULT 'select',
    `is_variant` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = can build product variants',
    `is_filter`  TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = show in shop sidebar filters',
    `sort_order` INT NOT NULL DEFAULT 0,
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_attribute_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `attribute_values`;
CREATE TABLE `attribute_values` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attribute_id` INT UNSIGNED NOT NULL,
    `value`        VARCHAR(150) NOT NULL,
    `slug`         VARCHAR(180) NOT NULL,
    `color_code`   VARCHAR(20) NULL,
    `sort_order`   INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_attr_value` (`attribute_id`, `slug`),
    KEY `idx_attrvalue_attribute` (`attribute_id`),
    CONSTRAINT `fk_attrvalue_attribute` FOREIGN KEY (`attribute_id`) REFERENCES `attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 5 : PRODUCTS
-- ===========================================================================

DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                VARCHAR(255) NOT NULL,
    `slug`                VARCHAR(280) NOT NULL,
    `sku`                 VARCHAR(80)  NOT NULL,
    `brand_id`            INT UNSIGNED NULL,
    `category_id`         INT UNSIGNED NULL,
    `vendor_id`           INT UNSIGNED NULL COMMENT 'NULL = house/store owned (single-vendor mode)',
    `short_description`   TEXT NULL,
    `description`         LONGTEXT NULL,
    `price`               DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'MRP / list price',
    `sale_price`          DECIMAL(12,2) NULL COMMENT 'Selling price when on offer',
    `cost_price`          DECIMAL(12,2) NULL,
    `stock`               INT NOT NULL DEFAULT 0,
    `low_stock_threshold` INT NOT NULL DEFAULT 5,
    `weight`              DECIMAL(10,3) NULL COMMENT 'kg',
    `tax_rate`            DECIMAL(5,2) NOT NULL DEFAULT 18.00 COMMENT 'GST %',
    `hsn_code`            VARCHAR(20) NULL,
    `warranty`            VARCHAR(150) NULL,
    `emi_text`            VARCHAR(150) NULL,
    `manufacturer`        VARCHAR(150) NULL,
    `model_number`        VARCHAR(100) NULL,
    `part_number`         VARCHAR(100) NULL,
    `compatibility`       VARCHAR(500) NULL COMMENT 'Free-text "works with" note',
    `main_image`          VARCHAR(255) NULL,
    `hover_image`         VARCHAR(255) NULL COMMENT 'Second image revealed on card hover',
    `video_url`           VARCHAR(255) NULL,
    `badge_text`          VARCHAR(40) NULL COMMENT 'Manual override badge, e.g. EXCLUSIVE',
    `badge_color`         VARCHAR(20) NULL,
    `min_order_qty`       INT UNSIGNED NOT NULL DEFAULT 1,
    `max_order_qty`       INT UNSIGNED NOT NULL DEFAULT 10,
    `cod_available`       TINYINT(1) NOT NULL DEFAULT 1,
    `free_shipping`       TINYINT(1) NOT NULL DEFAULT 0,
    `status`              ENUM('active','inactive','draft') NOT NULL DEFAULT 'active',
    `published_at`        DATETIME NULL COMMENT 'Scheduled go-live; NULL = live immediately',
    `is_featured`         TINYINT(1) NOT NULL DEFAULT 0,
    `is_new_arrival`      TINYINT(1) NOT NULL DEFAULT 0,
    `is_best_seller`      TINYINT(1) NOT NULL DEFAULT 0,
    `is_trending`         TINYINT(1) NOT NULL DEFAULT 0,
    `has_variants`        TINYINT(1) NOT NULL DEFAULT 0,
    `views`               INT UNSIGNED NOT NULL DEFAULT 0,
    `sold_count`          INT UNSIGNED NOT NULL DEFAULT 0,
    `rating_avg`          DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    `rating_count`        INT UNSIGNED NOT NULL DEFAULT 0,
    `meta_title`          VARCHAR(255) NULL,
    `meta_description`    TEXT NULL,
    `focus_keyword`    VARCHAR(120) NULL,
    `canonical_url`    VARCHAR(255) NULL,
    `robots`           VARCHAR(60)  NULL,
    `og_title`         VARCHAR(255) NULL,
    `og_description`   TEXT NULL,
    `og_image`         VARCHAR(255) NULL,
    `schema_json`      TEXT NULL,
    `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_slug` (`slug`),
    UNIQUE KEY `uq_product_sku` (`sku`),
    KEY `idx_product_brand` (`brand_id`),
    KEY `idx_product_category` (`category_id`),
    KEY `idx_product_vendor` (`vendor_id`),
    KEY `idx_product_status` (`status`),
    KEY `idx_product_created` (`created_at`),
    KEY `idx_product_price` (`sale_price`, `price`),
    KEY `idx_product_flags` (`is_featured`, `is_new_arrival`, `is_best_seller`, `is_trending`),
    KEY `idx_product_rating` (`rating_avg`),
    KEY `idx_product_stock` (`stock`),
    KEY `idx_product_sold` (`sold_count`),
    KEY `idx_product_views` (`views`),
    KEY `idx_product_published` (`status`, `published_at`),
    FULLTEXT KEY `ft_product_search` (`name`, `short_description`, `description`),
    CONSTRAINT `fk_product_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_product_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags` (
    `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(120) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tag_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_tags`;
CREATE TABLE `product_tags` (
    `product_id` INT UNSIGNED NOT NULL,
    `tag_id`     INT UNSIGNED NOT NULL,
    PRIMARY KEY (`product_id`, `tag_id`),
    KEY `idx_producttag_tag` (`tag_id`),
    CONSTRAINT `fk_producttag_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_producttag_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Related / upsell / cross-sell / frequently-bought-together links.
DROP TABLE IF EXISTS `product_relations`;
CREATE TABLE `product_relations` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`    INT UNSIGNED NOT NULL,
    `related_id`    INT UNSIGNED NOT NULL,
    `relation_type` ENUM('related','upsell','cross_sell','bought_together','bundle') NOT NULL DEFAULT 'related',
    `sort_order`    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_relation` (`product_id`, `related_id`, `relation_type`),
    KEY `idx_relation_related` (`related_id`),
    CONSTRAINT `fk_relation_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_relation_related` FOREIGN KEY (`related_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_videos`;
CREATE TABLE `product_videos` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `title`      VARCHAR(200) NULL,
    `provider`   ENUM('youtube','vimeo','file') NOT NULL DEFAULT 'youtube',
    `url`        VARCHAR(500) NOT NULL,
    `thumbnail`  VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_video_product` (`product_id`),
    CONSTRAINT `fk_video_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every stock change is journalled here (order, cancel, return, manual adjust).
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE `stock_movements` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`     INT UNSIGNED NOT NULL,
    `variant_id`     INT UNSIGNED NULL,
    `movement_type`  ENUM('order','cancel','return','adjust','restock','import') NOT NULL DEFAULT 'adjust',
    `quantity`       INT NOT NULL COMMENT 'Signed: negative removes stock',
    `stock_before`   INT NOT NULL DEFAULT 0,
    `stock_after`    INT NOT NULL DEFAULT 0,
    `reference_type` VARCHAR(40) NULL,
    `reference_id`   INT UNSIGNED NULL,
    `admin_id`       INT UNSIGNED NULL,
    `note`           VARCHAR(255) NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_movement_product` (`product_id`),
    KEY `idx_movement_created` (`created_at`),
    CONSTRAINT `fk_movement_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Notify me when back in stock"
DROP TABLE IF EXISTS `stock_alerts`;
CREATE TABLE `stock_alerts` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `variant_id` INT UNSIGNED NULL,
    `user_id`    INT UNSIGNED NULL,
    `email`      VARCHAR(190) NOT NULL,
    `notified_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_stock_alert` (`product_id`, `variant_id`, `email`),
    CONSTRAINT `fk_alert_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `recently_viewed`;
CREATE TABLE `recently_viewed` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NULL,
    `session_id` VARCHAR(128) NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `viewed_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_recent_user` (`user_id`, `product_id`),
    UNIQUE KEY `uq_recent_session` (`session_id`, `product_id`),
    KEY `idx_recent_viewed` (`viewed_at`),
    CONSTRAINT `fk_recent_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_images`;
CREATE TABLE `product_images` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `image`      VARCHAR(255) NOT NULL,
    `alt_text`   VARCHAR(200) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_image_product` (`product_id`),
    CONSTRAINT `fk_image_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_variants`;
CREATE TABLE `product_variants` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`   INT UNSIGNED NOT NULL,
    `sku`          VARCHAR(80) NOT NULL,
    `variant_name` VARCHAR(255) NOT NULL COMMENT 'e.g. "Black / 256GB"',
    `price`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `sale_price`   DECIMAL(12,2) NULL,
    `stock`        INT NOT NULL DEFAULT 0,
    `image`        VARCHAR(255) NULL,
    `is_default`   TINYINT(1) NOT NULL DEFAULT 0,
    `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_variant_sku` (`sku`),
    KEY `idx_variant_product` (`product_id`),
    CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_variant_attributes`;
CREATE TABLE `product_variant_attributes` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `variant_id`         INT UNSIGNED NOT NULL,
    `attribute_id`       INT UNSIGNED NOT NULL,
    `attribute_value_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_variant_attribute` (`variant_id`, `attribute_id`),
    KEY `idx_pva_attribute` (`attribute_id`),
    KEY `idx_pva_value` (`attribute_value_id`),
    CONSTRAINT `fk_pva_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pva_attribute` FOREIGN KEY (`attribute_id`) REFERENCES `attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pva_value` FOREIGN KEY (`attribute_value_id`) REFERENCES `attribute_values` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_specifications`;
CREATE TABLE `product_specifications` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `spec_group` VARCHAR(100) NOT NULL DEFAULT 'General',
    `spec_key`   VARCHAR(150) NOT NULL,
    `spec_value` VARCHAR(500) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_spec_product` (`product_id`),
    CONSTRAINT `fk_spec_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `product_features`;
CREATE TABLE `product_features` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `feature`    VARCHAR(300) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_feature_product` (`product_id`),
    CONSTRAINT `fk_feature_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 6 : WISHLIST / COMPARE / CART
-- ===========================================================================

DROP TABLE IF EXISTS `wishlists`;
CREATE TABLE `wishlists` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(100) NOT NULL DEFAULT 'My Wishlist',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wishlist_user` (`user_id`),
    CONSTRAINT `fk_wishlist_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `wishlist_items`;
CREATE TABLE `wishlist_items` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `wishlist_id` INT UNSIGNED NOT NULL,
    `product_id`  INT UNSIGNED NOT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wishlist_product` (`wishlist_id`, `product_id`),
    KEY `idx_wlitem_product` (`product_id`),
    CONSTRAINT `fk_wlitem_wishlist` FOREIGN KEY (`wishlist_id`) REFERENCES `wishlists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_wlitem_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `compare_items`;
CREATE TABLE `compare_items` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NULL,
    `session_id` VARCHAR(128) NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_compare_user` (`user_id`),
    KEY `idx_compare_session` (`session_id`),
    KEY `idx_compare_product` (`product_id`),
    CONSTRAINT `fk_compare_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_compare_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `carts`;
CREATE TABLE `carts` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NULL,
    `session_id`  VARCHAR(128) NULL,
    `coupon_id`   INT UNSIGNED NULL,
    `coupon_code` VARCHAR(60) NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cart_user` (`user_id`),
    KEY `idx_cart_session` (`session_id`),
    CONSTRAINT `fk_cart_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cart_items`;
CREATE TABLE `cart_items` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cart_id`    INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `variant_id` INT UNSIGNED NULL,
    `quantity`   INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cart_line` (`cart_id`, `product_id`, `variant_id`),
    KEY `idx_cartitem_product` (`product_id`),
    KEY `idx_cartitem_variant` (`variant_id`),
    CONSTRAINT `fk_cartitem_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cartitem_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cartitem_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 7 : COUPONS
-- ===========================================================================

DROP TABLE IF EXISTS `coupons`;
CREATE TABLE `coupons` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`             VARCHAR(60) NOT NULL,
    `description`      VARCHAR(255) NULL,
    `type`             ENUM('percentage','fixed','free_shipping') NOT NULL DEFAULT 'percentage',
    `value`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `minimum_order`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `maximum_discount` DECIMAL(12,2) NULL COMMENT 'Cap for percentage coupons',
    `start_date`       DATETIME NULL,
    `end_date`         DATETIME NULL,
    `usage_limit`      INT UNSIGNED NULL COMMENT 'Total redemptions allowed',
    `per_user_limit`   INT UNSIGNED NOT NULL DEFAULT 1,
    `used_count`       INT UNSIGNED NOT NULL DEFAULT 0,
    `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_coupon_code` (`code`),
    KEY `idx_coupon_status` (`status`),
    KEY `idx_coupon_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `coupon_usage`;
CREATE TABLE `coupon_usage` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `coupon_id`  INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NULL,
    `order_id`   INT UNSIGNED NULL,
    -- Identity, not session: the per-customer limit counts an account id
    -- when there is one and the email/phone on the order either way, so
    -- signing out and checking out as a guest is not a reset.
    `email`      VARCHAR(190) NULL,
    `phone`      VARCHAR(20) NULL,
    `discount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_usage_coupon` (`coupon_id`),
    KEY `idx_usage_user` (`user_id`),
    KEY `idx_usage_order` (`order_id`),
    KEY `idx_usage_identity_email` (`coupon_id`, `email`),
    KEY `idx_usage_identity_phone` (`coupon_id`, `phone`),
    CONSTRAINT `fk_usage_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional narrowing of a coupon to products / categories / brands / customers.
-- No rows for a coupon = applies to the whole catalogue.
DROP TABLE IF EXISTS `coupon_restrictions`;
CREATE TABLE `coupon_restrictions` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `coupon_id`        INT UNSIGNED NOT NULL,
    `restriction_type` ENUM('product','category','brand','user','first_order') NOT NULL,
    `reference_id`     INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_restriction_coupon` (`coupon_id`, `restriction_type`),
    CONSTRAINT `fk_restriction_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 8 : SHIPPING & DELIVERY
-- ===========================================================================

DROP TABLE IF EXISTS `shipping_methods`;
CREATE TABLE `shipping_methods` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`           VARCHAR(40) NOT NULL,
    `name`           VARCHAR(100) NOT NULL,
    `description`    VARCHAR(255) NULL,
    `cost`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `free_above`     DECIMAL(12,2) NULL COMMENT 'Order value above which this method is free',
    `min_days`       TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `max_days`       TINYINT UNSIGNED NOT NULL DEFAULT 7,
    `sort_order`     INT NOT NULL DEFAULT 0,
    `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_shipping_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `pincodes`;
CREATE TABLE `pincodes` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pincode`        VARCHAR(10) NOT NULL,
    `city`           VARCHAR(100) NOT NULL,
    `state`          VARCHAR(100) NOT NULL,
    `is_serviceable` TINYINT(1) NOT NULL DEFAULT 1,
    `cod_available`  TINYINT(1) NOT NULL DEFAULT 1,
    `delivery_days`  TINYINT UNSIGNED NOT NULL DEFAULT 4,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pincode` (`pincode`),
    KEY `idx_pincode_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 9 : ORDERS
-- ===========================================================================

DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_number`     VARCHAR(40) NOT NULL,
    `user_id`          INT UNSIGNED NULL COMMENT 'NULL for guest checkout',
    `customer_name`    VARCHAR(150) NOT NULL,
    `customer_email`   VARCHAR(190) NOT NULL,
    `customer_phone`   VARCHAR(20) NOT NULL,

    `shipping_name`    VARCHAR(150) NOT NULL,
    `shipping_phone`   VARCHAR(20) NOT NULL,
    `shipping_address` VARCHAR(255) NOT NULL,
    `shipping_address2` VARCHAR(255) NULL,
    `shipping_landmark` VARCHAR(150) NULL,
    `shipping_city`    VARCHAR(100) NOT NULL,
    `shipping_state`   VARCHAR(100) NOT NULL,
    `shipping_pincode` VARCHAR(10) NOT NULL,
    `shipping_country` VARCHAR(100) NOT NULL DEFAULT 'India',

    `billing_name`     VARCHAR(150) NULL,
    `billing_phone`    VARCHAR(20) NULL,
    `billing_address`  VARCHAR(255) NULL,
    `billing_city`     VARCHAR(100) NULL,
    `billing_state`    VARCHAR(100) NULL,
    `billing_pincode`  VARCHAR(10) NULL,
    `billing_country`  VARCHAR(100) NULL,

    `subtotal`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- The part of discount_amount a combo earned, kept apart from the
    -- coupon's so the invoice and the reports can name it.
    `combo_discount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `coupon_id`        INT UNSIGNED NULL,
    `coupon_code`      VARCHAR(60) NULL,
    `shipping_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `payment_charge`   DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'e.g. COD handling fee',
    `payment_discount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'e.g. prepaid discount',
    `total_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    `shipping_method`  VARCHAR(40) NOT NULL DEFAULT 'standard',
    `payment_method`   VARCHAR(40) NOT NULL DEFAULT 'cod',
    `payment_status`   ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    `status`           ENUM('pending','confirmed','processing','packed','shipped','out_for_delivery','delivered','cancelled','returned','refunded') NOT NULL DEFAULT 'pending',

    `customer_note`    TEXT NULL,
    `admin_note`       TEXT NULL,
    `cancel_reason`    VARCHAR(255) NULL,
    `return_reason`    VARCHAR(255) NULL,
    `tracking_number`  VARCHAR(100) NULL,
    `courier_name`     VARCHAR(100) NULL,
    `estimated_delivery` DATE NULL,

    `ip_address`       VARCHAR(45) NULL,
    `user_agent`       VARCHAR(255) NULL,

    `confirmed_at`     DATETIME NULL,
    `shipped_at`       DATETIME NULL,
    `delivered_at`     DATETIME NULL,
    `cancelled_at`     DATETIME NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_order_number` (`order_number`),
    KEY `idx_order_user` (`user_id`),
    KEY `idx_order_status` (`status`),
    KEY `idx_order_payment_status` (`payment_status`),
    KEY `idx_order_created` (`created_at`),
    KEY `idx_order_email` (`customer_email`),
    KEY `idx_order_phone` (`customer_phone`),
    CONSTRAINT `fk_order_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`      INT UNSIGNED NOT NULL,
    `product_id`    INT UNSIGNED NULL,
    `variant_id`    INT UNSIGNED NULL,
    `vendor_id`     INT UNSIGNED NULL COMMENT 'Snapshot for multi-vendor commission splits',
    `commission`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `product_name`  VARCHAR(255) NOT NULL,
    `product_sku`   VARCHAR(80) NOT NULL,
    `product_image` VARCHAR(255) NULL,
    `variant_name`  VARCHAR(255) NULL,
    `mrp`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `price`         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Unit price actually charged',
    `quantity`      INT UNSIGNED NOT NULL DEFAULT 1,
    `tax_rate`      DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `tax_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `subtotal`      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'price * quantity',
    `total`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_orderitem_order` (`order_id`),
    KEY `idx_orderitem_product` (`product_id`),
    CONSTRAINT `fk_orderitem_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_orderitem_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_orderitem_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `order_status_history`;
CREATE TABLE `order_status_history` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`   INT UNSIGNED NOT NULL,
    `status`     VARCHAR(40) NOT NULL,
    `note`       VARCHAR(500) NULL,
    `changed_by` ENUM('system','admin','customer') NOT NULL DEFAULT 'system',
    `admin_id`   INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_history_order` (`order_id`),
    CONSTRAINT `fk_history_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`       INT UNSIGNED NOT NULL,
    `gateway`        VARCHAR(40) NOT NULL DEFAULT 'cod',
    `amount`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency`       VARCHAR(10) NOT NULL DEFAULT 'INR',
    `status`         ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    `reference`      VARCHAR(190) NULL COMMENT 'Gateway payment id',
    `paid_at`        DATETIME NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payment_order` (`order_id`),
    KEY `idx_payment_status` (`status`),
    CONSTRAINT `fk_payment_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payment_transactions`;
CREATE TABLE `payment_transactions` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_id`   INT UNSIGNED NOT NULL,
    `order_id`     INT UNSIGNED NOT NULL,
    `event`        VARCHAR(60) NOT NULL,
    `amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `payload`      LONGTEXT NULL COMMENT 'JSON gateway payload',
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_txn_payment` (`payment_id`),
    KEY `idx_txn_order` (`order_id`),
    CONSTRAINT `fk_txn_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 10 : DEALS & FLASH SALES
-- ===========================================================================

DROP TABLE IF EXISTS `deals`;
CREATE TABLE `deals` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(200) NOT NULL,
    `subtitle`      VARCHAR(255) NULL,
    `description`   TEXT NULL,
    `product_id`    INT UNSIGNED NULL COMMENT 'Primary product for Deal of the Day',
    `discount_type` ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `stock_limit`   INT UNSIGNED NULL,
    `stock_sold`    INT UNSIGNED NOT NULL DEFAULT 0,
    `button_text`   VARCHAR(60) NOT NULL DEFAULT 'Shop The Deal',
    `button_url`    VARCHAR(255) NULL,
    `start_time`    DATETIME NOT NULL,
    `end_time`      DATETIME NOT NULL,
    `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_deal_status` (`status`),
    KEY `idx_deal_time` (`start_time`, `end_time`),
    KEY `idx_deal_product` (`product_id`),
    CONSTRAINT `fk_deal_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `deal_products`;
CREATE TABLE `deal_products` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `deal_id`    INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `deal_price` DECIMAL(12,2) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_deal_product` (`deal_id`, `product_id`),
    KEY `idx_dealproduct_product` (`product_id`),
    CONSTRAINT `fk_dealproduct_deal` FOREIGN KEY (`deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_dealproduct_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `flash_sales`;
CREATE TABLE `flash_sales` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(200) NOT NULL,
    `subtitle`       VARCHAR(255) NULL,
    `discount_type`  ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `stock_limit`    INT UNSIGNED NULL COMMENT 'Per-product cap during the sale',
    `start_time`     DATETIME NOT NULL,
    `end_time`       DATETIME NOT NULL,
    `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_flash_status` (`status`),
    KEY `idx_flash_time` (`start_time`, `end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `flash_sale_products`;
CREATE TABLE `flash_sale_products` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `flash_sale_id` INT UNSIGNED NOT NULL,
    `product_id`    INT UNSIGNED NOT NULL,
    `sale_price`    DECIMAL(12,2) NULL,
    `stock_limit`   INT UNSIGNED NULL,
    `stock_sold`    INT UNSIGNED NOT NULL DEFAULT 0,
    `sort_order`    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_flash_product` (`flash_sale_id`, `product_id`),
    KEY `idx_flashproduct_product` (`product_id`),
    CONSTRAINT `fk_flashproduct_sale` FOREIGN KEY (`flash_sale_id`) REFERENCES `flash_sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_flashproduct_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 11 : HOMEPAGE CMS
-- ===========================================================================

DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `text`       VARCHAR(255) NOT NULL,
    `subtext`    VARCHAR(255) NULL,
    `icon`       VARCHAR(60) NULL COMMENT 'Icon key rendered by the SVG sprite helper',
    `link`       VARCHAR(255) NULL,
    `bg_color`   VARCHAR(20) NULL,
    `text_color` VARCHAR(20) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `start_date` DATETIME NULL,
    `end_date`   DATETIME NULL,
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_announcement_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `banners`;
CREATE TABLE `banners` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `position`      ENUM('hero','promo','category','sidebar') NOT NULL DEFAULT 'hero',
    `title`         VARCHAR(200) NULL,
    `title_accent`  VARCHAR(200) NULL COMMENT 'Second heading line rendered in the accent colour',
    `subtitle`      VARCHAR(255) NULL,
    `description`   TEXT NULL,
    `badge`         VARCHAR(100) NULL,
    `desktop_image` VARCHAR(255) NULL,
    `mobile_image`  VARCHAR(255) NULL,
    `button_text`   VARCHAR(60) NULL,
    `button_url`    VARCHAR(255) NULL,
    `button2_text`  VARCHAR(60) NULL,
    `button2_url`   VARCHAR(255) NULL,
    `bg_color`      VARCHAR(20) NULL,
    `text_color`    VARCHAR(20) NULL,
    `sort_order`    INT NOT NULL DEFAULT 0,
    `start_date`    DATETIME NULL,
    `end_date`      DATETIME NULL,
    `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_banner_position` (`position`, `status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `trust_features`;
CREATE TABLE `trust_features` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `placement`  ENUM('hero','strip') NOT NULL DEFAULT 'strip',
    `title`      VARCHAR(120) NOT NULL,
    `subtitle`   VARCHAR(180) NULL,
    `icon`       VARCHAR(60) NOT NULL DEFAULT 'shield',
    `link`       VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_trust_placement` (`placement`, `status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `site_stats`;
CREATE TABLE `site_stats` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `value`      VARCHAR(40) NOT NULL,
    `label`      VARCHAR(120) NOT NULL,
    `icon`       VARCHAR(60) NOT NULL DEFAULT 'users',
    `sort_order` INT NOT NULL DEFAULT 0,
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_stat_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `testimonials`;
CREATE TABLE `testimonials` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_name` VARCHAR(120) NOT NULL,
    `designation`   VARCHAR(120) NULL DEFAULT 'Verified Buyer',
    `avatar`        VARCHAR(255) NULL,
    `rating`        TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `title`         VARCHAR(200) NULL,
    `message`       TEXT NOT NULL,
    `sort_order`    INT NOT NULL DEFAULT 0,
    `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_testimonial_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Widget instances.
--  One row = one widget placed in one zone. This single table powers the
--  Homepage Builder and every other builder zone; `widget_type` selects the
--  renderer in /includes/widgets/, `data_source` selects what it queries.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `homepage_sections`;
CREATE TABLE `homepage_sections` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `section_key`   VARCHAR(60) NOT NULL COMMENT 'Unique instance key',
    `zone`          VARCHAR(40) NOT NULL DEFAULT 'home' COMMENT 'home | shop_top | product_bottom | cart | checkout | footer_top',
    `widget_type`   VARCHAR(40) NOT NULL DEFAULT 'product_grid'
                    COMMENT 'hero | ticker | trust | category_grid | product_grid | product_carousel | deal_of_day | flash_sale | brand_slider | promo_banner | stats | testimonials | newsletter | offer_slider | recently_viewed | recommendations | blog_grid | html',

    `title`         VARCHAR(150) NULL,
    `title_accent`  VARCHAR(150) NULL COMMENT 'Trailing words rendered in the accent colour',
    `subtitle`      VARCHAR(255) NULL,
    `description`   TEXT NULL,
    `custom_html`   LONGTEXT NULL COMMENT 'Used by widget_type = html',
    `link_text`     VARCHAR(60) NULL,
    `link_url`      VARCHAR(255) NULL,
    `image`         VARCHAR(255) NULL,
    `mobile_image`  VARCHAR(255) NULL,

    `data_source`   VARCHAR(40) NOT NULL DEFAULT 'auto'
                    COMMENT 'auto | manual | category | brand | tag | featured | new | best | trending | deal | flash | most_viewed',
    `source_id`     INT UNSIGNED NULL COMMENT 'category/brand/tag id when data_source needs one',
    `item_limit`    TINYINT UNSIGNED NOT NULL DEFAULT 8,

    `layout`        VARCHAR(30) NOT NULL DEFAULT 'grid' COMMENT 'grid | carousel | list | masonry',
    `card_style`    VARCHAR(30) NOT NULL DEFAULT 'standard' COMMENT 'standard | minimal | premium | compact | horizontal',
    `cols_desktop`  TINYINT UNSIGNED NOT NULL DEFAULT 4,
    `cols_tablet`   TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `cols_mobile`   TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `autoplay`      TINYINT(1) NOT NULL DEFAULT 0,
    `autoplay_speed` SMALLINT UNSIGNED NOT NULL DEFAULT 4000,
    `show_arrows`   TINYINT(1) NOT NULL DEFAULT 1,
    `show_dots`     TINYINT(1) NOT NULL DEFAULT 1,
    `animation`     VARCHAR(30) NOT NULL DEFAULT 'fade-up',

    `bg_color`      VARCHAR(20) NULL,
    `text_color`    VARCHAR(20) NULL,
    `container`     ENUM('boxed','full') NOT NULL DEFAULT 'boxed',
    `padding`       ENUM('none','sm','md','lg') NOT NULL DEFAULT 'md',

    `device_visibility` ENUM('all','desktop','tablet','mobile','desktop_tablet','tablet_mobile') NOT NULL DEFAULT 'all',
    `auth_visibility`   ENUM('all','guest','user') NOT NULL DEFAULT 'all',
    `start_date`    DATETIME NULL,
    `end_date`      DATETIME NULL,
    `lazy_load`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = fetch contents over AJAX on scroll',

    `settings`      LONGTEXT NULL COMMENT 'JSON, widget specific extras',
    `sort_order`    INT NOT NULL DEFAULT 0,
    `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_section_key` (`section_key`),
    KEY `idx_section_zone` (`zone`, `status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `homepage_section_items`;
CREATE TABLE `homepage_section_items` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `section_id`  INT UNSIGNED NOT NULL,
    `item_type`   ENUM('product','category','brand','banner','custom') NOT NULL DEFAULT 'product',
    `item_id`     INT UNSIGNED NULL,
    `title`       VARCHAR(200) NULL,
    `subtitle`    VARCHAR(255) NULL,
    `image`       VARCHAR(255) NULL,
    `link`        VARCHAR(255) NULL,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`id`),
    KEY `idx_sectionitem_section` (`section_id`, `sort_order`),
    CONSTRAINT `fk_sectionitem_section` FOREIGN KEY (`section_id`) REFERENCES `homepage_sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 12 : REVIEWS
-- ===========================================================================

DROP TABLE IF EXISTS `reviews`;
CREATE TABLE `reviews` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id`        INT UNSIGNED NOT NULL,
    `user_id`           INT UNSIGNED NULL,
    `order_id`          INT UNSIGNED NULL,
    `customer_name`     VARCHAR(150) NOT NULL,
    `rating`            TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `title`             VARCHAR(200) NULL,
    `comment`           TEXT NULL,
    `verified_purchase` TINYINT(1) NOT NULL DEFAULT 0,
    `helpful_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `status`            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `admin_reply`       TEXT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_review_product` (`product_id`, `status`),
    KEY `idx_review_user` (`user_id`),
    KEY `idx_review_status` (`status`),
    CONSTRAINT `fk_review_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_review_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `review_images`;
CREATE TABLE `review_images` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `review_id` INT UNSIGNED NOT NULL,
    `image`     VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_reviewimage_review` (`review_id`),
    CONSTRAINT `fk_reviewimage_review` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 13 : CONTENT (PAGES / FAQ / BLOG / NEWSLETTER / CONTACT)
-- ===========================================================================

DROP TABLE IF EXISTS `newsletter_subscribers`;
CREATE TABLE `newsletter_subscribers` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(190) NOT NULL,
    `name`       VARCHAR(150) NULL,
    `source`     VARCHAR(60) NOT NULL DEFAULT 'homepage',
    `ip_address` VARCHAR(45) NULL,
    `status`     ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_subscriber_email` (`email`),
    KEY `idx_subscriber_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `pages`;
CREATE TABLE `pages` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`            VARCHAR(200) NOT NULL,
    `slug`             VARCHAR(220) NOT NULL,
    `content`          LONGTEXT NULL,
    `banner_image`     VARCHAR(255) NULL,
    `is_system`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = referenced by a fixed route, cannot be deleted',
    `show_in_footer`   TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`       INT NOT NULL DEFAULT 0,
    `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `meta_title`       VARCHAR(255) NULL,
    `meta_description` TEXT NULL,
    `focus_keyword`    VARCHAR(120) NULL,
    `canonical_url`    VARCHAR(255) NULL,
    `robots`           VARCHAR(60)  NULL,
    `og_title`         VARCHAR(255) NULL,
    `og_description`   TEXT NULL,
    `og_image`         VARCHAR(255) NULL,
    `schema_json`      TEXT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_page_slug` (`slug`),
    KEY `idx_page_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `faqs`;
CREATE TABLE `faqs` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category`   VARCHAR(100) NOT NULL DEFAULT 'General',
    `question`   VARCHAR(500) NOT NULL,
    `answer`     TEXT NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_faq_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `blog_categories`;
CREATE TABLE `blog_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(150) NOT NULL,
    `slug`       VARCHAR(180) NOT NULL,
    `description` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blogcat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `blog_posts`;
CREATE TABLE `blog_posts` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id`      INT UNSIGNED NULL,
    `admin_id`         INT UNSIGNED NULL,
    `title`            VARCHAR(255) NOT NULL,
    `slug`             VARCHAR(280) NOT NULL,
    `excerpt`          TEXT NULL,
    `content`          LONGTEXT NULL,
    `featured_image`   VARCHAR(255) NULL,
    `author_name`      VARCHAR(150) NULL,
    `views`            INT UNSIGNED NOT NULL DEFAULT 0,
    `is_featured`      TINYINT(1) NOT NULL DEFAULT 0,
    `status`           ENUM('published','draft') NOT NULL DEFAULT 'draft',
    `published_at`     DATETIME NULL,
    `meta_title`       VARCHAR(255) NULL,
    `meta_description` TEXT NULL,
    `focus_keyword`    VARCHAR(120) NULL,
    `canonical_url`    VARCHAR(255) NULL,
    `robots`           VARCHAR(60)  NULL,
    `og_title`         VARCHAR(255) NULL,
    `og_description`   TEXT NULL,
    `og_image`         VARCHAR(255) NULL,
    `schema_json`      TEXT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_post_slug` (`slug`),
    KEY `idx_post_category` (`category_id`),
    KEY `idx_post_status` (`status`, `published_at`),
    CONSTRAINT `fk_post_category` FOREIGN KEY (`category_id`) REFERENCES `blog_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `contact_messages`;
CREATE TABLE `contact_messages` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(150) NOT NULL,
    `email`      VARCHAR(190) NOT NULL,
    `phone`      VARCHAR(20) NULL,
    `subject`    VARCHAR(200) NULL,
    `message`    TEXT NOT NULL,
    `status`     ENUM('new','read','replied','closed') NOT NULL DEFAULT 'new',
    `admin_reply` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_contact_status` (`status`),
    KEY `idx_contact_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 14 : SYSTEM LOGS
-- ===========================================================================

DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id`    INT UNSIGNED NULL,
    `admin_name`  VARCHAR(150) NULL,
    `action`      VARCHAR(100) NOT NULL,
    `entity`      VARCHAR(60) NULL,
    `entity_id`   INT UNSIGNED NULL,
    `description` VARCHAR(500) NULL,
    `ip_address`  VARCHAR(45) NULL,
    `user_agent`  VARCHAR(255) NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_activity_admin` (`admin_id`),
    KEY `idx_activity_entity` (`entity`, `entity_id`),
    KEY `idx_activity_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `error_logs`;
CREATE TABLE `error_logs` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `level`      VARCHAR(20) NOT NULL DEFAULT 'error',
    `message`    TEXT NOT NULL,
    `file`       VARCHAR(255) NULL,
    `line`       INT UNSIGNED NULL,
    `url`        VARCHAR(500) NULL,
    `trace`      LONGTEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_error_level` (`level`),
    KEY `idx_error_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `login_history`;
CREATE TABLE `login_history` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_type`  ENUM('admin','customer') NOT NULL DEFAULT 'customer',
    `user_id`    INT UNSIGNED NULL,
    `identifier` VARCHAR(190) NOT NULL,
    `status`     ENUM('success','failed') NOT NULL DEFAULT 'success',
    `reason`     VARCHAR(150) NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_login_user` (`user_type`, `user_id`),
    KEY `idx_login_identifier` (`identifier`),
    KEY `idx_login_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 15 : MENU BUILDER (main nav, mega menu, mobile drawer, footer)
-- ===========================================================================

DROP TABLE IF EXISTS `menus`;
CREATE TABLE `menus` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(100) NOT NULL,
    `location`   VARCHAR(40) NOT NULL COMMENT 'main | mobile | footer_quick | footer_service | footer_about | topbar',
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_menu_location` (`location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `menu_items`;
CREATE TABLE `menu_items` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `menu_id`       INT UNSIGNED NOT NULL,
    `parent_id`     INT UNSIGNED NULL,
    `label`         VARCHAR(120) NOT NULL,
    `link_type`     ENUM('custom','category','brand','page','route') NOT NULL DEFAULT 'custom',
    `reference_id`  INT UNSIGNED NULL COMMENT 'category / brand / page id',
    `url`           VARCHAR(255) NULL,
    -- 160, not 60: an icon is either a key from icon_names() or the marker
    -- `upload:uploads/menu-icons/<file>.svg`, and that path alone is ~48 chars.
    `icon`          VARCHAR(160) NULL COMMENT 'icon_names() key, or upload:<path> for a custom SVG',
    -- Which surface draws the glyph. Separate from `device_visibility`, which
    -- gates the whole ITEM: this hides only the picture, and 'none' keeps the
    -- admin's choice on file so switching it back on costs one select.
    `icon_visibility` ENUM('all','desktop','mobile','none') NOT NULL DEFAULT 'all',
    `badge`         VARCHAR(30) NULL COMMENT 'e.g. NEW, HOT',
    `badge_color`   VARCHAR(20) NULL,
    `badge_style`   ENUM('solid','soft','outline') NOT NULL DEFAULT 'solid',
    `badge_text_color` VARCHAR(20) NULL,
    `badge_position` ENUM('after','before') NOT NULL DEFAULT 'after',
    `badge_animation` ENUM('none','pulse') NOT NULL DEFAULT 'none',
    `is_mega`       TINYINT(1) NOT NULL DEFAULT 0,
    `mega_columns`  TINYINT UNSIGNED NOT NULL DEFAULT 4,
    `mega_image`    VARCHAR(255) NULL COMMENT 'Promo image inside the mega panel',
    `mega_image_url` VARCHAR(255) NULL,
    `mega_products` VARCHAR(255) NULL COMMENT 'CSV of product ids featured in the panel',
    `mega_promo`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Draw the promo tile inside the mega panel',
    `open_new_tab`  TINYINT(1) NOT NULL DEFAULT 0,
    -- Deliberately NOT the six-value enum that `popups` and `homepage_sections`
    -- carry. A menu item is filtered by menu_surface_items(), which asks which
    -- surface is being drawn (the 1024px+ bar, or the drawer below it) and never
    -- looks at the User-Agent, so there is no third "tablet" surface to name.
    `device_visibility` ENUM('all','desktop','mobile') NOT NULL DEFAULT 'all',
    `auth_visibility`   ENUM('all','guest','user') NOT NULL DEFAULT 'all',
    `sort_order`    INT NOT NULL DEFAULT 0,
    `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_menuitem_menu` (`menu_id`, `parent_id`, `sort_order`),
    CONSTRAINT `fk_menuitem_menu` FOREIGN KEY (`menu_id`) REFERENCES `menus` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_menuitem_parent` FOREIGN KEY (`parent_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 16 : POPUP & POP-IN BUILDER
-- ===========================================================================

DROP TABLE IF EXISTS `popups`;
CREATE TABLE `popups` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(150) NOT NULL,
    `display_mode`   ENUM('popup','popin') NOT NULL DEFAULT 'popup',
    `popup_type`     VARCHAR(40) NOT NULL DEFAULT 'promo'
                     COMMENT 'newsletter | coupon | promo | product | image | html | video | cart_reminder | stock',
    `title`          VARCHAR(200) NULL,
    `subtitle`       VARCHAR(255) NULL,
    `content`        LONGTEXT NULL,
    `image`          VARCHAR(255) NULL,
    `mobile_image`   VARCHAR(255) NULL,
    `video_url`      VARCHAR(255) NULL,
    `coupon_code`    VARCHAR(60) NULL,
    `product_id`     INT UNSIGNED NULL,
    `button_text`    VARCHAR(60) NULL,
    `button_url`     VARCHAR(255) NULL,
    `position`       ENUM('center','top','bottom','bottom-left','bottom-right','top-right','left','right') NOT NULL DEFAULT 'center',
    `size`           ENUM('sm','md','lg') NOT NULL DEFAULT 'md',
    `bg_color`       VARCHAR(20) NULL,
    `text_color`     VARCHAR(20) NULL,
    `trigger_type`   ENUM('immediate','timed','scroll','exit','cart_value') NOT NULL DEFAULT 'timed',
    `trigger_value`  INT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'seconds / scroll % / cart amount',
    `frequency`      ENUM('always','session','daily','once') NOT NULL DEFAULT 'session',
    -- 512, not 255: this is a CSV of route keys from popup_page_options(), and
    -- that list now offers 41 of them. Selecting every page produces 381 chars.
    -- sql_mode here carries no STRICT_TRANS_TABLES, so the old 255 did not
    -- reject the overflow - it silently cut the string mid-key ("...login,re"),
    -- leaving a popup that targeted a route matching nothing and gave no sign.
    `display_pages`  VARCHAR(512) NOT NULL DEFAULT 'all' COMMENT 'all, or CSV of route keys from popup_page_options()',
    -- Matches homepage_sections: popups are filtered by visibility_allows(),
    -- which asks device_type() for a mobile | tablet | desktop answer. The old
    -- three-value enum could not express "tablet", so an iPad matched neither
    -- 'desktop' nor 'mobile' and was excluded from every device-targeted popup.
    `device_visibility` ENUM('all','desktop','tablet','mobile','desktop_tablet','tablet_mobile') NOT NULL DEFAULT 'all',
    `auth_visibility`   ENUM('all','guest','user') NOT NULL DEFAULT 'all',
    `show_close`     TINYINT(1) NOT NULL DEFAULT 1,
    `impressions`    INT UNSIGNED NOT NULL DEFAULT 0,
    `conversions`    INT UNSIGNED NOT NULL DEFAULT 0,
    `start_date`     DATETIME NULL,
    `end_date`       DATETIME NULL,
    `sort_order`     INT NOT NULL DEFAULT 0,
    `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_popup_status` (`status`, `display_mode`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Floating action buttons (WhatsApp, call, chat, support, back to top, custom)
--
--  A table rather than a JSON settings blob: every one of these is an
--  independently ordered, independently scheduled row with its own colour,
--  icon and per-device audience — the same shape as `banners`, `popups` and
--  `menu_items`, so it reuses their admin list/toggle/delete patterns and
--  visibility_allows() verbatim. A blob would need hand-rolled array
--  validation and could not be sorted or filtered in SQL.
--
--  `device_visibility` carries the full six-value enum that
--  visibility_allows() already understands, so a tablet is never excluded
--  from both "desktop" and "mobile" the way it is for popups.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `floating_buttons`;
CREATE TABLE `floating_buttons` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `button_key`       VARCHAR(40) NOT NULL COMMENT 'stable slug used by the seed and the admin',
    `label`            VARCHAR(60) NOT NULL COMMENT 'accessible name, and the visible pill when show_label is on',
    `tooltip`          VARCHAR(120) NULL COMMENT 'hover/focus chip; falls back to label',
    `icon`             VARCHAR(40) NOT NULL DEFAULT 'sparkle' COMMENT 'key from icon_names()',
    `action_type`      ENUM('whatsapp','call','link','scroll_top') NOT NULL DEFAULT 'link',
    `phone`            VARCHAR(30) NULL COMMENT 'action_type=call; empty falls back to settings.store_phone',
    `whatsapp_number`  VARCHAR(30) NULL COMMENT 'action_type=whatsapp; empty falls back to settings.store_whatsapp',
    `whatsapp_message` VARCHAR(255) NULL COMMENT 'prefilled wa.me text',
    `url`              VARCHAR(255) NULL COMMENT 'action_type=link; relative, absolute, mailto: or tel:',
    `open_new_tab`     TINYINT(1) NOT NULL DEFAULT 0,
    `position`         ENUM('bottom-right','bottom-left','middle-right','middle-left') NOT NULL DEFAULT 'bottom-right',
    `size`             ENUM('sm','md','lg') NOT NULL DEFAULT 'md',
    `bg_color`         VARCHAR(20) NULL COMMENT 'NULL = the theme default (--sik-navy)',
    `text_color`       VARCHAR(20) NULL,
    `animation`        ENUM('none','pulse','bounce','float') NOT NULL DEFAULT 'none',
    `show_label`       TINYINT(1) NOT NULL DEFAULT 0,
    `device_visibility` ENUM('all','desktop','tablet','mobile','desktop_tablet','tablet_mobile') NOT NULL DEFAULT 'all',
    `auth_visibility`   ENUM('all','guest','user') NOT NULL DEFAULT 'all',
    `sort_order`       INT NOT NULL DEFAULT 0,
    `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_floating_key` (`button_key`),
    KEY `idx_floating_live` (`status`, `position`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `floating_buttons`
    (`button_key`, `label`, `tooltip`, `icon`, `action_type`, `whatsapp_message`, `url`, `open_new_tab`,
     `position`, `size`, `bg_color`, `text_color`, `sort_order`, `status`) VALUES
('whatsapp',    'WhatsApp',      'Chat with us on WhatsApp',        'whatsapp', 'whatsapp',   'Hi! I have a question about a product on your store.', NULL, 1, 'bottom-right', 'md', '#25D366', '#FFFFFF', 10, 'active'),
('call',        'Call Us',       'Talk to our support team',        'phone',    'call',       NULL, NULL,           0, 'bottom-right', 'md', NULL, NULL, 20, 'inactive'),
('chat',        'Live Chat',     'Start a live chat',               'inbox',    'link',       NULL, 'contact.php',  0, 'bottom-right', 'md', NULL, NULL, 30, 'inactive'),
('support',     'Support',       'Help centre and contact options', 'headset',  'link',       NULL, 'faq.php',      0, 'bottom-right', 'md', NULL, NULL, 40, 'inactive'),
('custom',      'Custom Button', NULL,                              'sparkle',  'link',       NULL, NULL,           0, 'bottom-right', 'md', NULL, NULL, 50, 'inactive'),
('back_to_top', 'Back to top',   'Back to top',                     'arrow-up', 'scroll_top', NULL, NULL,           0, 'bottom-right', 'md', NULL, NULL, 90, 'active');

-- ===========================================================================
--  SECTION 17 : FOOTER BUILDER
-- ===========================================================================

DROP TABLE IF EXISTS `footer_columns`;
CREATE TABLE `footer_columns` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(120) NOT NULL,
    -- Exactly the five types admin/footer/_meta.php offers and the column loop
    -- in includes/footer.php draws. The enum used to carry a sixth, 'newsletter',
    -- which no admin option could select and no render branch handled: a row
    -- holding it fell through to the link-list branch, so a "Newsletter" column
    -- silently drew a list of links. It named a feature the footer did not have.
    -- The signup form the name promised already exists and is owned by the
    -- homepage builder (admin/homepage/newsletter.php), whose widget carries the
    -- admin-owned copy and offer and is the component account.js binds to. The
    -- footer once hard-coded a second signup beneath it and asked for the same
    -- address twice; that duplicate was removed for the same reason this value
    -- is. Dropped rather than implemented a third time. No row ever used it.
    `column_type` ENUM('links','about','contact','html','payment') NOT NULL DEFAULT 'links',
    `content`     TEXT NULL,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_footercol_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `footer_links`;
CREATE TABLE `footer_links` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `column_id`  INT UNSIGNED NOT NULL,
    `label`      VARCHAR(120) NOT NULL,
    `url`        VARCHAR(255) NOT NULL,
    `open_new_tab` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`id`),
    KEY `idx_footerlink_column` (`column_id`, `sort_order`),
    CONSTRAINT `fk_footerlink_column` FOREIGN KEY (`column_id`) REFERENCES `footer_columns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 18 : PAYMENT METHODS & OFFERS
-- ===========================================================================

DROP TABLE IF EXISTS `payment_methods`;
CREATE TABLE `payment_methods` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`         VARCHAR(40) NOT NULL,
    `name`         VARCHAR(100) NOT NULL,
    `description`  VARCHAR(255) NULL,
    `instructions` TEXT NULL,
    `logo`         VARCHAR(255) NULL,
    `is_online`    TINYINT(1) NOT NULL DEFAULT 0,
    `extra_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'e.g. COD handling fee',
    `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'e.g. prepaid discount',
    `min_amount`   DECIMAL(12,2) NULL,
    `max_amount`   DECIMAL(12,2) NULL,
    `config`       LONGTEXT NULL COMMENT 'JSON gateway credentials/settings',
    `sort_order`   INT NOT NULL DEFAULT 0,
    `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_paymethod_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `bank_offers`;
CREATE TABLE `bank_offers` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(200) NOT NULL,
    `description` VARCHAR(500) NULL,
    `offer_type`  ENUM('bank','card','upi','wallet','emi') NOT NULL DEFAULT 'bank',
    `logo`        VARCHAR(255) NULL,
    `code`        VARCHAR(60) NULL,
    `min_amount`  DECIMAL(12,2) NULL,
    `start_date`  DATETIME NULL,
    `end_date`    DATETIME NULL,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bankoffer_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 19 : LOCALISATION (multi-language / multi-currency ready)
-- ===========================================================================

DROP TABLE IF EXISTS `languages`;
CREATE TABLE `languages` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`        VARCHAR(10) NOT NULL,
    `name`        VARCHAR(80) NOT NULL,
    `native_name` VARCHAR(80) NULL,
    `direction`   ENUM('ltr','rtl') NOT NULL DEFAULT 'ltr',
    `flag`        VARCHAR(255) NULL,
    `is_default`  TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_language_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `translations`;
CREATE TABLE `translations` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `language_code` VARCHAR(10) NOT NULL,
    `entity_type`   VARCHAR(40) NOT NULL COMMENT 'product | category | brand | page | ui',
    `entity_id`     INT UNSIGNED NULL,
    `field`         VARCHAR(60) NOT NULL,
    `value`         LONGTEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_translation` (`language_code`, `entity_type`, `entity_id`, `field`),
    KEY `idx_translation_lookup` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `currencies`;
CREATE TABLE `currencies` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`          VARCHAR(10) NOT NULL,
    `name`          VARCHAR(80) NOT NULL,
    `symbol`        VARCHAR(10) NOT NULL,
    `exchange_rate` DECIMAL(14,6) NOT NULL DEFAULT 1.000000,
    `symbol_position` ENUM('before','after') NOT NULL DEFAULT 'before',
    `decimals`      TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `thousand_separator` VARCHAR(5) NOT NULL DEFAULT ',',
    `decimal_separator`  VARCHAR(5) NOT NULL DEFAULT '.',
    `grouping`      ENUM('western','indian') NOT NULL DEFAULT 'indian',
    `is_default`    TINYINT(1) NOT NULL DEFAULT 0,
    `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_currency_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 20 : NOTIFICATIONS (channel-agnostic queue)
-- ===========================================================================

DROP TABLE IF EXISTS `notification_templates`;
CREATE TABLE `notification_templates` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `template_key` VARCHAR(80) NOT NULL,
    `name`         VARCHAR(150) NOT NULL,
    `channel`      ENUM('email','sms','whatsapp','push') NOT NULL DEFAULT 'email',
    `subject`      VARCHAR(255) NULL,
    `body`         LONGTEXT NOT NULL,
    `variables`    VARCHAR(500) NULL COMMENT 'Comma list of {{placeholders}} available',
    `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_template` (`template_key`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notification_queue`;
CREATE TABLE `notification_queue` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel`        ENUM('email','sms','whatsapp','push') NOT NULL DEFAULT 'email',
    `template_key`   VARCHAR(80) NULL,
    `recipient`      VARCHAR(190) NOT NULL,
    `subject`        VARCHAR(255) NULL,
    `body`           LONGTEXT NULL,
    `reference_type` VARCHAR(40) NULL,
    `reference_id`   INT UNSIGNED NULL,
    `status`         ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    `attempts`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `error`          VARCHAR(500) NULL,
    `sent_at`        DATETIME NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_queue_status` (`status`, `created_at`),
    KEY `idx_queue_reference` (`reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 21 : ANALYTICS & MAINTENANCE
-- ===========================================================================

DROP TABLE IF EXISTS `search_logs`;
CREATE TABLE `search_logs` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `query`         VARCHAR(255) NOT NULL,
    `results_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `user_id`       INT UNSIGNED NULL,
    `session_id`    VARCHAR(128) NULL,
    `ip_address`    VARCHAR(45) NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_search_query` (`query`),
    KEY `idx_search_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SECTION 22 : CUSTOMER NOTIFICATIONS & PREFERENCES
-- ===========================================================================

-- The customer-facing notification feed (the bell in the header).
-- Distinct from `notification_queue`, which is outbound email/SMS delivery:
-- this is what the customer reads inside their account.
DROP TABLE IF EXISTS `user_notifications`;
CREATE TABLE `user_notifications` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED NOT NULL,
    `type`           ENUM('order','shipping','delivery','promotion','wishlist','product','account','system') NOT NULL DEFAULT 'system',
    `title`          VARCHAR(200) NOT NULL,
    `message`        VARCHAR(500) NULL,
    `icon`           VARCHAR(60) NULL COMMENT 'Icon key; falls back to one derived from `type`',
    `url`            VARCHAR(255) NULL COMMENT 'Where clicking the notification goes',
    `image`          VARCHAR(255) NULL,
    `reference_type` VARCHAR(40) NULL COMMENT 'order | product | review ...',
    `reference_id`   INT UNSIGNED NULL,
    `is_read`        TINYINT(1) NOT NULL DEFAULT 0,
    `read_at`        DATETIME NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notif_user` (`user_id`, `is_read`, `created_at`),
    KEY `idx_notif_reference` (`reference_type`, `reference_id`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per preference per customer. A key/value shape rather than columns
-- so a new preference is a settings change, not a migration.
DROP TABLE IF EXISTS `user_preferences`;
CREATE TABLE `user_preferences` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `pref_key`   VARCHAR(60) NOT NULL,
    `pref_value` VARCHAR(255) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_pref` (`user_id`, `pref_key`),
    CONSTRAINT `fk_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `backups`;
CREATE TABLE `backups` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename`   VARCHAR(255) NOT NULL,
    `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `admin_id`   INT UNSIGNED NULL,
    `admin_name` VARCHAR(150) NULL,
    `note`       VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_backup_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================================================
-- ===========================================================================
--                          S E E D   D A T A
--   Demo content only. Everything below is editable from the Admin Panel.
-- ===========================================================================
-- ===========================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
--  Settings
-- ---------------------------------------------------------------------------
INSERT INTO `settings` (`setting_group`, `setting_key`, `setting_value`, `setting_type`, `label`, `sort_order`) VALUES
-- general / store
('general', 'store_name',            'ShopInnKart',                              'text',     'Store Name', 1),
('general', 'store_tagline',         'Lights for a brighter you', 'text',     'Tagline', 2),
('general', 'store_description',     'ShopInnKart is your destination for festive and decorative lighting — string lights, curtain lights, diyas, LED candles, lamps and projectors at honest prices.', 'textarea', 'Store Description', 3),
('general', 'store_logo',            'assets/images/logo/shopinnkart-logo.png',              'image',    'Logo', 4),
('general', 'store_logo_light',      'assets/images/logo/shopinnkart-logo-light.png',        'image',    'Footer / Dark Logo', 5),
('general', 'store_favicon',         'assets/images/logo/favicon-32.png',           'image',    'Favicon', 6),
('general', 'store_email',           'support@shopinnkart.com',                  'text',     'Support Email', 7),
('general', 'store_phone',           '+91 98765 43210',                          'text',     'Support Phone', 8),
('general', 'store_whatsapp',        '919876543210',                             'text',     'WhatsApp Number', 9),
-- Deliberately empty. The policy documents render a registered address and a
-- GSTIN only when these are set, and seeding a plausible-looking one would put
-- a fabricated legal entity and registered office into legal text. Every
-- surface that uses them (footer contact column, contact page, invoices,
-- LocalBusiness schema, the policy bodies) already omits the block when the
-- value is blank. The operator fills these in from Admin > Settings > General.
('general', 'store_address',         '',                                         'textarea', 'Address', 10),
('general', 'business_hours',        'Mon - Sat: 9:00 AM to 8:00 PM IST',        'text',     'Business Hours', 11),
('general', 'maintenance_mode',      '0',                                        'boolean',  'Maintenance Mode', 12),
('general', 'maintenance_message',   'We are upgrading ShopInnKart. We will be back shortly.', 'textarea', 'Maintenance Message', 13),
('general', 'copyright_text',        '© 2026 ShopInnKart. All Rights Reserved.', 'text',     'Copyright Text', 14),
-- Also deliberately empty; see store_address above. A GSTIN is a real tax
-- registration and must never be invented.
('general', 'gst_number',            '',                                         'text',     'GSTIN', 15),

-- currency / locale
('store', 'currency_code',           'INR',                                      'text',     'Currency Code', 1),
('store', 'currency_symbol',         '₹',                                        'text',     'Currency Symbol', 2),
('store', 'currency_position',       'before',                                   'select',   'Symbol Position', 3),
('store', 'number_grouping',         'indian',                                   'select',   'Number Grouping', 4),
('store', 'timezone',                'Asia/Kolkata',                             'text',     'Timezone', 5),
('store', 'products_per_page',       '12',                                       'number',   'Products Per Page', 6),
('store', 'guest_checkout',          '1',                                        'boolean',  'Allow Guest Checkout', 7),
('store', 'reviews_require_purchase','1',                                        'boolean',  'Only Verified Buyers Can Review', 8),
('store', 'reviews_auto_approve',    '0',                                        'boolean',  'Auto Approve Reviews', 9),
('store', 'max_compare_items',       '4',                                        'number',   'Max Compare Items', 10),
('store', 'low_stock_threshold',     '5',                                        'number',   'Default Low Stock Threshold', 11),
('store', 'multivendor_enabled',     '0',                                        'boolean',  'Enable Multi-Vendor', 12),
('store', 'multilanguage_enabled',   '0',                                        'boolean',  'Enable Multi-Language', 13),
('store', 'multicurrency_enabled',   '0',                                        'boolean',  'Enable Multi-Currency', 14),

-- order
('order', 'order_prefix',            'SIK',                                      'text',     'Order Number Prefix', 1),
('order', 'invoice_prefix',          'INV',                                      'text',     'Invoice Prefix', 2),
('order', 'min_order_amount',        '0',                                        'number',   'Minimum Order Amount', 3),
('order', 'auto_confirm_cod',        '1',                                        'boolean',  'Auto-Confirm COD Orders', 4),
('order', 'cancel_window_hours',     '24',                                       'number',   'Customer Cancellation Window (hours)', 5),
('order', 'return_window_days',      '7',                                        'number',   'Return Window (days)', 6),

-- shipping
('shipping', 'free_shipping_enabled','1',                                        'boolean',  'Enable Free Shipping', 1),
('shipping', 'free_shipping_threshold','999',                                     'number',   'Free Shipping Above', 2),
('shipping', 'default_shipping_cost','79',                                       'number',   'Default Shipping Cost', 3),
('shipping', 'cod_enabled',          '1',                                        'boolean',  'Enable Cash on Delivery', 4),
('shipping', 'cod_charge',           '49',                                       'number',   'COD Handling Fee', 5),
('shipping', 'cod_max_amount',       '50000',                                    'number',   'Max Order Value for COD', 6),
('shipping', 'default_delivery_days','4',                                        'number',   'Default Delivery Days', 7),

-- tax
('tax', 'tax_enabled',               '1',                                        'boolean',  'Enable Tax', 1),
('tax', 'tax_inclusive',             '1',                                        'boolean',  'Prices Include Tax', 2),
('tax', 'default_tax_rate',          '18',                                       'number',   'Default GST Rate (%)', 3),
('tax', 'tax_label',                 'GST',                                      'text',     'Tax Label', 4),

-- theme
('theme', 'primary_color',           '#F4511E',                                  'color',    'Primary Colour', 1),
('theme', 'secondary_color',         '#0F2143',                                  'color',    'Secondary Colour', 2),
('theme', 'accent_color',            '#FF8A3D',                                  'color',    'Accent Colour', 3),
('theme', 'body_bg',                 '#FFFFFF',                                  'color',    'Page Background', 4),
('theme', 'soft_bg',                 '#F8F7F4',                                  'color',    'Soft Section Background', 5),
('theme', 'text_color',              '#111827',                                  'color',    'Body Text Colour', 6),
('theme', 'muted_color',             '#6B7280',                                  'color',    'Muted Text Colour', 7),
('theme', 'border_color',            '#E5E7EB',                                  'color',    'Border Colour', 8),
('theme', 'button_color',            '#F4511E',                                  'color',    'Button Colour', 9),
('theme', 'button_text_color',       '#FFFFFF',                                  'color',    'Button Text Colour', 10),
('theme', 'border_radius',           '12',                                       'number',   'Border Radius (px)', 11),
('theme', 'card_style',              'soft',                                      'select',   'Card Style', 12),
('theme', 'button_style',            'rounded',                                  'select',   'Button Style', 13),
('theme', 'product_card_style',      'standard',                                 'select',   'Product Card Style', 14),
('theme', 'container_width',         '1280',                                     'number',   'Container Width (px)', 15),
('theme', 'header_style',            'sticky',                                   'select',   'Header Style', 16),
('theme', 'footer_style',            'dark',                                     'select',   'Footer Style', 17),
('theme', 'font_family',             'Inter',                                    'select',   'Font Family', 18),
('theme', 'enable_animations',       '1',                                        'boolean',  'Enable Animations', 19),
('theme', 'custom_css',              '',                                         'textarea', 'Custom CSS', 20),
('theme', 'custom_js',               '',                                         'textarea', 'Custom JavaScript', 21),

-- admin theme
('admin_theme', 'admin_primary',     '#F4511E',                                  'color',    'Admin Accent Colour', 1),
('admin_theme', 'admin_sidebar_bg',  '#0F2143',                                  'color',    'Admin Sidebar Background', 2),
('admin_theme', 'admin_sidebar_collapsed', '0',                                  'boolean',  'Collapse Sidebar by Default', 3),

-- social
('social', 'social_facebook',        'https://facebook.com/shopinnkart',         'text',     'Facebook', 1),
('social', 'social_instagram',       'https://instagram.com/shopinnkart',        'text',     'Instagram', 2),
('social', 'social_twitter',         'https://x.com/shopinnkart',                'text',     'X (Twitter)', 3),
('social', 'social_youtube',         'https://youtube.com/@shopinnkart',         'text',     'YouTube', 4),
('social', 'social_linkedin',        'https://linkedin.com/company/shopinnkart', 'text',     'LinkedIn', 5),
('social', 'social_pinterest',       '',                                          'text',     'Pinterest', 6),

-- seo
('seo', 'meta_title',                'ShopInnKart — Festive & Decorative Lighting Online in India', 'text', 'Default Meta Title', 1),
('seo', 'meta_description',          'Shop festive and decorative lighting at ShopInnKart: string and curtain lights, diyas, LED candles, table lamps and projectors. Fast delivery, secure payments and GST invoices.', 'textarea', 'Default Meta Description', 2),
('seo', 'meta_keywords',             'festive lighting, string lights, curtain lights, fairy lights, diyas, LED candles, table lamp, projector lamp, ShopInnKart', 'textarea', 'Default Meta Keywords', 3),
('seo', 'og_image',                  'assets/images/banners/og-default.svg',     'image',    'Default OG Image', 4),
('seo', 'google_analytics_id',       '',                                          'text',     'Google Analytics ID', 5),
('seo', 'meta_pixel_id',             '',                                          'text',     'Meta Pixel ID', 6),
('seo', 'google_site_verification',  '',                                          'text',     'Google Site Verification', 7),
-- Every value below is a real account credential or a live container id, so all
-- of them ship empty. The operator pastes their own in Admin > Settings > SEO;
-- nothing here may be invented, and an empty value simply renders no tag.
('seo', 'google_tag_manager_id',     '',                                          'text',     'Google Tag Manager ID', 20),
('seo', 'bing_site_verification',    '',                                          'text',     'Bing Webmaster Verification', 21),
('seo', 'pinterest_site_verification','',                                         'text',     'Pinterest Verification', 22),
('seo', 'yandex_site_verification',  '',                                          'text',     'Yandex Verification', 23),
('seo', 'robots_txt_extra',          '',                                          'textarea', 'Extra robots.txt Rules', 24),
('seo', 'sitemap_include_blog',      '1',                                         'boolean',  'Include Blog in Sitemap', 25),
('seo', 'sitemap_include_pages',     '1',                                         'boolean',  'Include CMS Pages in Sitemap', 26),
('seo', 'default_og_image',          '',                                          'image',    'Default Social Share Image', 27),
('seo', 'twitter_site',              '',                                          'text',     'Twitter/X @username', 28),
('seo', 'twitter_card_type',         'summary_large_image',                       'select',   'Twitter/X Card Type', 29),

-- email
('email', 'mail_from_name',          'ShopInnKart',                              'text',     'From Name', 1),
('email', 'mail_from_email',         'no-reply@shopinnkart.com',                 'text',     'From Email', 2),
('email', 'mail_driver',             'mail',                                     'select',   'Mail Driver', 3),
('email', 'smtp_host',               '',                                          'text',     'SMTP Host', 4),
('email', 'smtp_port',               '587',                                      'number',   'SMTP Port', 5),
('email', 'smtp_user',               '',                                          'text',     'SMTP Username', 6),
('email', 'smtp_pass',               '',                                          'text',     'SMTP Password', 7),
('email', 'smtp_encryption',         'tls',                                      'select',   'SMTP Encryption', 8),
('email', 'admin_notify_email',      'orders@shopinnkart.com',                   'text',     'Order Notification Email', 9),

-- widgets / popups global switches
('widgets', 'popups_enabled',        '1',                                        'boolean',  'Enable Popups', 1),
('widgets', 'popins_enabled',        '1',                                        'boolean',  'Enable Pop-ins', 2),
('widgets', 'sales_notification_enabled', '1',                                   'boolean',  'Enable Sales Notifications', 3),
('widgets', 'sales_notification_interval', '18',                                 'number',   'Sales Notification Interval (sec)', 4),
('widgets', 'recently_viewed_enabled','1',                                       'boolean',  'Enable Recently Viewed', 5),
-- back_to_top_enabled and floating_whatsapp used to live here. They are now the
-- `status` of the matching `floating_buttons` row, which also owns that button's
-- icon, colour, position, order and per-device visibility. Two switches for one
-- button is exactly the duplicate this table exists to remove.
('widgets', 'mobile_bottom_nav',     '1',                                        'boolean',  'Enable Mobile Bottom Nav', 8),
('widgets', 'ticker_enabled',        '1',                                        'boolean',  'Enable Announcement Ticker', 9),
('widgets', 'ticker_speed',          '40',                                       'number',   'Ticker Speed (seconds per loop)', 10),

-- wishlist & compare controls (Admin > Settings > Widgets)
('widgets', 'wishlist_enabled',        '1',       'boolean', 'Wishlist enabled',                  20),
('widgets', 'wishlist_show_header',    '1',       'boolean', 'Wishlist icon in header',           21),
('widgets', 'wishlist_show_card',      '1',       'boolean', 'Wishlist button on product cards',  22),
('widgets', 'wishlist_show_pdp',       '1',       'boolean', 'Wishlist button on product detail', 23),
('widgets', 'wishlist_guest_mode',     'session', 'select',  'Wishlist guest behaviour',          24),
('widgets', 'compare_enabled',         '1',       'boolean', 'Compare enabled',                   30),
('widgets', 'compare_show_header',     '1',       'boolean', 'Compare icon in header',            31),
('widgets', 'compare_show_card',       '1',       'boolean', 'Compare button on product cards',   32),
('widgets', 'compare_show_pdp',        '1',       'boolean', 'Compare button on product detail',  33),
('widgets', 'compare_guest_mode',      'session', 'select',  'Compare guest behaviour',           34),
('widgets', 'floating_buttons_enabled','1',       'boolean', 'Floating action buttons',           40),

-- cache / performance
('performance', 'cache_enabled',     '1',                                        'boolean',  'Enable Server Cache', 1),
('performance', 'cache_ttl',         '600',                                      'number',   'Cache TTL (seconds)', 2),
('performance', 'lazy_load_images',  '1',                                        'boolean',  'Lazy Load Images', 3);

-- ---------------------------------------------------------------------------
--  Default per-page SEO
-- ---------------------------------------------------------------------------
INSERT INTO `seo_settings` (`page_key`,`page_label`,`meta_title`,`meta_description`,`robots`) VALUES
('home','Homepage','ShopInnKart — Festive & Decorative Lighting Online in India','String and curtain lights, diyas, LED candles, table lamps and projectors. Genuine products, fast delivery and secure payments across India.','index, follow'),
('shop','Shop','Shop Festive & Decorative Lighting | ShopInnKart','Browse string lights, curtain lights, diyas, LED candles, table lamps and projector lamps, with filters for category, brand, price and rating.','index, follow'),
('deals','Deals','Today''s Best Lighting Deals | ShopInnKart','Limited-time offers on festive string lights, curtain lights, diyas and decorative lamps. Grab them before the sale ends.','index, follow'),
('new-arrivals','New Arrivals','New Arrivals in Festive Lighting | ShopInnKart','The newest string lights, curtain lights, diyas and decorative lamps, freshly added to ShopInnKart.','index, follow'),
('best-sellers','Best Sellers','Best Selling Festive Lighting | ShopInnKart','The lights our customers buy most — top rated curtain lights, fairy lights, diyas and table lamps.','index, follow'),
('brands','Brands','Shop by Brand | ShopInnKart','Explore the lighting brands we stock, from fairy and curtain lights to decorative lamps and LED candles.','index, follow'),
('blog','Blog','Decor Guides & Lighting Ideas | ShopInnKart','Styling guides, festive decor ideas and buying advice to help you choose the right lights for your home.','index, follow'),
('contact','Contact','Contact ShopInnKart Customer Support','Reach the ShopInnKart support team by phone, email or the contact form. We reply within one business day.','index, follow'),
('track-order','Track Order','Track Your Order | ShopInnKart','Enter your order number to see the live status of your ShopInnKart delivery.','noindex, follow'),
('cart','Cart','Your Shopping Cart | ShopInnKart','Review the items in your ShopInnKart cart before checkout.','noindex, follow'),
('checkout','Checkout','Secure Checkout | ShopInnKart','Complete your ShopInnKart order securely.','noindex, nofollow');

-- ---------------------------------------------------------------------------
--  Admin roles, permissions and the initial super admin
--  Login: admin@shopinnkart.com  /  Admin@123   (change after first login)
-- ---------------------------------------------------------------------------
INSERT INTO `admin_roles` (`id`, `name`, `slug`, `description`, `permissions`, `is_system`, `status`) VALUES
(1, 'Super Admin',     'super-admin',     'Unrestricted access to every module.', '["*"]', 1, 'active'),
(2, 'Manager',         'manager',         'Runs the store day to day, without system settings or admin users.',
 '["dashboard.view","products.view","products.create","products.edit","products.delete","categories.view","categories.create","categories.edit","categories.delete","brands.view","brands.create","brands.edit","brands.delete","attributes.view","attributes.create","attributes.edit","attributes.delete","orders.view","orders.edit","customers.view","customers.edit","coupons.view","coupons.create","coupons.edit","coupons.delete","deals.view","deals.create","deals.edit","deals.delete","flash_sales.view","flash_sales.create","flash_sales.edit","flash_sales.delete","banners.view","banners.create","banners.edit","banners.delete","homepage.view","homepage.edit","reviews.view","reviews.edit","newsletter.view","reports.view"]', 1, 'active'),
(3, 'Order Manager',   'order-manager',   'Processes orders, returns and customer queries.',
 '["dashboard.view","orders.view","orders.edit","customers.view","products.view","reports.view"]', 1, 'active'),
(4, 'Product Manager', 'product-manager', 'Owns the catalogue: products, categories, brands and stock.',
 '["dashboard.view","products.view","products.create","products.edit","products.delete","categories.view","categories.create","categories.edit","categories.delete","brands.view","brands.create","brands.edit","brands.delete","attributes.view","attributes.create","attributes.edit","attributes.delete","reviews.view","reviews.edit","reports.view"]', 1, 'active'),
(5, 'Content Manager', 'content-manager', 'Owns the storefront content: homepage, pages, FAQ, blog and banners.',
 '["dashboard.view","homepage.view","homepage.edit","banners.view","banners.create","banners.edit","banners.delete","pages.view","pages.create","pages.edit","pages.delete","faq.view","faq.create","faq.edit","faq.delete","blog.view","blog.create","blog.edit","blog.delete","newsletter.view"]', 1, 'active'),
(6, 'Support Manager', 'support-manager', 'Handles customers, reviews and newsletter subscribers.',
 '["dashboard.view","customers.view","customers.edit","orders.view","reviews.view","reviews.edit","reviews.delete","newsletter.view","newsletter.edit","newsletter.delete"]', 1, 'active');

INSERT INTO `admin_permissions` (`module`, `action`, `label`, `sort_order`) VALUES
('dashboard','view','View Dashboard',1),
('products','view','View Products',10),('products','create','Create Products',11),('products','edit','Edit Products',12),('products','delete','Delete Products',13),
('categories','view','View Categories',20),('categories','create','Create Categories',21),('categories','edit','Edit Categories',22),('categories','delete','Delete Categories',23),
('brands','view','View Brands',30),('brands','create','Create Brands',31),('brands','edit','Edit Brands',32),('brands','delete','Delete Brands',33),
('attributes','view','View Attributes',40),('attributes','create','Create Attributes',41),('attributes','edit','Edit Attributes',42),('attributes','delete','Delete Attributes',43),
('orders','view','View Orders',50),('orders','edit','Manage Orders',51),('orders','delete','Delete Orders',52),
('customers','view','View Customers',60),('customers','create','Create Customers',61),('customers','edit','Edit Customers',62),('customers','delete','Delete Customers',63),
('coupons','view','View Coupons',70),('coupons','create','Create Coupons',71),('coupons','edit','Edit Coupons',72),('coupons','delete','Delete Coupons',73),
('deals','view','View Deals',80),('deals','create','Create Deals',81),('deals','edit','Edit Deals',82),('deals','delete','Delete Deals',83),
('flash_sales','view','View Flash Sales',90),('flash_sales','create','Create Flash Sales',91),('flash_sales','edit','Edit Flash Sales',92),('flash_sales','delete','Delete Flash Sales',93),
('banners','view','View Banners',100),('banners','create','Create Banners',101),('banners','edit','Edit Banners',102),('banners','delete','Delete Banners',103),
('homepage','view','View Homepage Builder',110),('homepage','edit','Edit Homepage Builder',111),
('reviews','view','View Reviews',120),('reviews','edit','Moderate Reviews',121),('reviews','delete','Delete Reviews',122),
('newsletter','view','View Subscribers',130),('newsletter','edit','Manage Subscribers',131),('newsletter','delete','Delete Subscribers',132),
('pages','view','View Pages',140),('pages','create','Create Pages',141),('pages','edit','Edit Pages',142),('pages','delete','Delete Pages',143),
('faq','view','View FAQs',150),('faq','create','Create FAQs',151),('faq','edit','Edit FAQs',152),('faq','delete','Delete FAQs',153),
('blog','view','View Blog',160),('blog','create','Create Posts',161),('blog','edit','Edit Posts',162),('blog','delete','Delete Posts',163),
('reports','view','View Reports',170),
('settings','view','View Settings',180),('settings','edit','Edit Settings',181),
('admins','view','View Admin Users',190),('admins','create','Create Admin Users',191),('admins','edit','Edit Admin Users',192),('admins','delete','Delete Admin Users',193),
('logs','view','View Logs',200),('logs','delete','Clear Logs',201);

INSERT INTO `admins` (`id`, `role_id`, `name`, `username`, `email`, `password`, `phone`, `status`) VALUES
(1, 1, 'ShopInnKart Admin', 'admin', 'admin@shopinnkart.com', '$2y$10$NefrwytUGIu.izPKOu7hUOxRxr9kXGNSASc/XAY/HTE0za0unW9C.', '+91 98765 43210', 'active');

-- ---------------------------------------------------------------------------
--  Localisation defaults
-- ---------------------------------------------------------------------------
INSERT INTO `languages` (`code`, `name`, `native_name`, `direction`, `is_default`, `sort_order`, `status`) VALUES
('en', 'English', 'English', 'ltr', 1, 1, 'active'),
('hi', 'Hindi',   'हिन्दी',   'ltr', 0, 2, 'inactive');

INSERT INTO `currencies` (`code`, `name`, `symbol`, `exchange_rate`, `symbol_position`, `decimals`, `grouping`, `is_default`, `status`) VALUES
('INR', 'Indian Rupee',   '₹', 1.000000, 'before', 2, 'indian',  1, 'active'),
('USD', 'US Dollar',      '$', 0.012000, 'before', 2, 'western', 0, 'inactive'),
('AED', 'UAE Dirham',     'د.إ', 0.044000, 'before', 2, 'western', 0, 'inactive');

-- ---------------------------------------------------------------------------
--  Categories (1 top level + 3 sub categories), the live store's tree
-- ---------------------------------------------------------------------------
INSERT INTO `categories` (`id`,`parent_id`,`name`,`slug`,`description`,`image`,`icon`,`sort_order`,`is_featured`,`show_in_menu`,`status`,`meta_title`,`meta_description`) VALUES
-- top level
(1,NULL,'Festive & Decor Lighting','festive-decor-lighting','String lights, curtain lights, diyas and decorative lamps for Diwali, Christmas, weddings and everyday room decor.','assets/images/placeholders/light-fairy.svg',NULL,90,1,1,'active',NULL,NULL),
-- sub categories
(2,1,'String & Curtain Lights','string-curtain-lights','Hanging curtain lights, fairy lights and LED strings for windows, walls and backdrops.','assets/images/placeholders/light-curtain.svg',NULL,0,1,1,'active',NULL,NULL),
(3,1,'Diyas & LED Candles','diyas-led-candles','Flameless, smokeless LED diyas and tea lights that are safe around children and pets.','assets/images/placeholders/light-diya.svg',NULL,0,1,1,'active',NULL,NULL),
(4,1,'Lamps & Projectors','lamps-projectors','Night lamps, mood lighting and star projectors for bedrooms and nurseries.','assets/images/placeholders/light-lamp.svg',NULL,0,1,1,'active',NULL,NULL);

-- ---------------------------------------------------------------------------
--  Brands
-- ---------------------------------------------------------------------------
INSERT INTO `brands` (`id`,`name`,`slug`,`logo`,`description`,`sort_order`,`is_featured`,`status`) VALUES
(1,'Lexton','lexton',NULL,'Decorative lighting for Indian homes and festivals.',1,0,'active'),
(2,'fizzytech','fizzytech',NULL,'Affordable fairy lights and festive decoration lighting.',2,0,'active'),
(3,'HUNCHA','huncha',NULL,'Flameless LED diyas and home decor accessories.',3,0,'active'),
(4,'luxentia','luxentia',NULL,'LED tea lights and festive decor essentials.',4,0,'active'),
(5,'The Purple Tree','the-purple-tree',NULL,'Diwali and festive decoration specialists.',5,0,'active'),
(6,'MIRADH','miradh',NULL,'Remote-controlled curtain lights and party backdrops.',6,0,'active'),
(7,'Toy Imagine','toy-imagine',NULL,'Night lights, projectors and room decor for children.',7,0,'active'),
(8,'TakshHaven','takshhaven',NULL,'Decorative lamps and gifting lighting.',8,0,'active');

-- ---------------------------------------------------------------------------
--  Attributes and values (drive variants and shop filters)
-- ---------------------------------------------------------------------------
INSERT INTO `attributes` (`id`,`name`,`slug`,`type`,`is_variant`,`is_filter`,`sort_order`,`status`) VALUES
(1,'Colour','colour','color',1,1,1,'active');

INSERT INTO `attribute_values` (`id`,`attribute_id`,`value`,`slug`,`color_code`,`sort_order`) VALUES
-- Colour: the one attribute lighting actually varies by
(1,1,'Warm White','warm-white','#FFD9A0',1),
(2,1,'Cool White','cool-white','#EEF4FF',2),
(3,1,'Golden Yellow','golden-yellow','#F5B700',3),
(4,1,'Blue','blue','#2F7DE1',4),
(5,1,'Green','green','#22A55B',5),
(6,1,'Multicolour','multicolour',NULL,6),
(7,1,'Pink','pink','#ED1857',7),
(8,1,'RGB Colour Changing','rgb-colour-changing',NULL,8);

-- ---------------------------------------------------------------------------
--  Tags
-- ---------------------------------------------------------------------------
INSERT INTO `tags` (`id`,`name`,`slug`) VALUES
(1,'Diwali','diwali'),
(2,'Christmas','christmas'),
(3,'Wedding Decor','wedding-decor'),
(4,'Warm White','warm-white'),
(5,'Multicolour','multicolour'),
(6,'Remote Control','remote-control'),
(7,'USB Powered','usb-powered'),
(8,'Budget Pick','budget-pick'),
(9,'Premium','premium'),
(10,'Kids Room','kids-room'),
(11,'Pooja Room','pooja-room'),
(12,'Gift Pick','gift-pick');

-- ---------------------------------------------------------------------------
--  Products (the 11 festive lighting SKUs the live store sells)
--  Images are locally generated SVG placeholders - no third-party artwork.
--  php database/seeds/festive-lighting-products.php --images swaps them for
--  the product photographs (it upserts by SKU, so run it after installing).
-- ---------------------------------------------------------------------------
INSERT INTO `products`
(`id`,`name`,`slug`,`sku`,`brand_id`,`category_id`,`short_description`,`description`,`price`,`sale_price`,`cost_price`,`stock`,`low_stock_threshold`,`weight`,`tax_rate`,`hsn_code`,`warranty`,`emi_text`,`manufacturer`,`model_number`,`main_image`,`hover_image`,`badge_text`,`cod_available`,`free_shipping`,`status`,`is_featured`,`is_new_arrival`,`is_best_seller`,`is_trending`,`has_variants`,`views`,`sold_count`,`rating_avg`,`rating_count`,`meta_title`,`meta_description`) VALUES
-- ---- String & Curtain Lights -------------------------------------
(1,'Lexton Artificial Leaf Curtain LED String Light — 180 LED, 8 Modes, 10x3 ft','lexton-artificial-leaf-curtain-led-string-light-180-led-8-modes-10x3-ft','SIK-FLT-1001',1,2,'A 10 x 3 ft leaf-vine curtain carrying 180 warm white LEDs, with eight lighting patterns and brightness control on an in-line button — no remote or batteries to lose.','<p>Artificial leaf vines threaded with 180 warm white LEDs, sized at 10 x 3 feet to cover a window, a headboard wall or a mandap backdrop in one go. The greenery reads as decor even in daylight, so the curtain earns its place on the wall before you switch it on.</p><p>Eight lighting patterns — steady, twinkle, fade, chase and the rest — cycle from a button on the wire itself, which also steps the brightness down for a quiet evening. There is no remote to hunt for and no batteries to replace: it runs straight off a wall socket.</p><p>Rated for indoor use, which makes it a natural fit for living rooms, bedrooms, pooja corners and party backdrops through Diwali, Christmas and wedding season.</p>',1999.00,279.00,NULL,117,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-leaf-curtain.svg','assets/images/placeholders/light-leaf-curtain-lit.svg',NULL,1,0,'active',0,1,0,0,0,36,3,3.90,5,'Lexton Artificial Leaf Curtain LED String Light — 180 LED, 8 Modes, 10x3 ft','A 10 x 3 ft leaf-vine curtain carrying 180 warm white LEDs, with eight lighting patterns and brightness control on an in-line button — no remote or batteries to lose.'),
(2,'Lexton Star Curtain Light — 12 Stars, 138 LED, 8 Flashing Modes, Warm White','lexton-star-curtain-light-12-stars-138-led-8-flashing-modes-warm-white','SIK-FLT-1002',1,2,'Twelve stars — six large, six small — on a 138 LED warm white curtain, with eight flashing modes and IP44 weatherproofing for balconies and porches.','<p>Six big stars and six small ones hang from a single 138 LED curtain, giving the display some depth instead of a flat row of identical shapes. The warm white tone sits closer to candlelight than to daylight, which is what keeps it flattering indoors.</p><p>Eight flashing modes cover the full range: combination, waves, sequential, slow-glo, chasing, slow fade, twinkle and steady-on. Steady-on is the one most people settle on; the rest are there for the party.</p><p>The wires and lamp housings are IP44 rated, so this is one of the few curtain lights here that is genuinely happy on a balcony railing or under a porch, not just behind glass.</p>',999.00,289.00,NULL,150,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-star-curtain.svg','assets/images/placeholders/light-star-curtain-lit.svg',NULL,1,0,'active',0,1,0,0,0,17,0,4.10,4981,'Lexton Star Curtain Light — 12 Stars, 138 LED, 8 Flashing Modes, Warm White','Twelve stars — six large, six small — on a 138 LED warm white curtain, with eight flashing modes and IP44 weatherproofing for balconies and porches.'),
(3,'fizzytech Star SMD Fairy Curtain String Lights — 138 LED, 8 Modes, Multicolour','fizzytech-star-smd-fairy-curtain-string-lights-138-led-8-modes-multicolour','SIK-FLT-1003',2,2,'A 138 LED multicolour star curtain with eight lighting modes — the loud, celebratory option for Diwali and birthday walls.','<p>Where the warm white curtains are about atmosphere, this one is about colour. 138 SMD LEDs in a star-studded curtain throw a full multicolour wash across a wall or window, which is exactly what you want behind a birthday table or a Diwali rangoli.</p><p>Eight modes let you dial it from a slow colour fade up to a full chase. SMD diodes sit flush in the strand rather than bulging out of it, so the curtain hangs flat and packs away without tangling into a ball.</p><p>Light enough at 100 g to hang from removable hooks, and rated for both indoor and sheltered outdoor use.</p>',559.00,395.00,NULL,200,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-star-curtain-multi.svg','assets/images/placeholders/light-star-curtain-multi-lit.svg',NULL,1,0,'active',0,1,0,0,0,3,0,4.00,2418,'fizzytech Star SMD Fairy Curtain String Lights — 138 LED, 8 Modes, Multicolour','A 138 LED multicolour star curtain with eight lighting modes — the loud, celebratory option for Diwali and birthday walls.'),
(4,'fizzytech Snowflake Fairy String Lights — 15 LED, 3 Metre, Warm White','fizzytech-snowflake-fairy-string-lights-15-led-3-metre-warm-white','SIK-FLT-1004',2,2,'Three metres of warm white string with 15 snowflake shades — a small accent light for a shelf, a mirror or a bedside frame.','<p>A short, deliberate string rather than a wall-filling curtain: 3 metres carrying 15 snowflake-shaped shades in warm white. It is the right scale for edging a mirror, running along a shelf or framing a headboard, where a 138 LED curtain would simply be too much.</p><p>The snowflake shades diffuse each LED into a soft shape instead of a hard point of light, so it photographs well and does not glare in a small room.</p><p>At 100 g the whole string is light enough for adhesive hooks or washi tape, and a button controller handles power without needing a wall switch within reach.</p>',599.00,249.00,NULL,239,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-fairy.svg','assets/images/placeholders/light-fairy-lit.svg',NULL,1,0,'active',0,1,0,0,0,10,1,3.90,3753,'fizzytech Snowflake Fairy String Lights — 15 LED, 3 Metre, Warm White','Three metres of warm white string with 15 snowflake shades — a small accent light for a shelf, a mirror or a bedside frame.'),
-- ---- Diyas & LED Candles -----------------------------------------
(5,'HUNCHA LED Tea Light Candles — Pack of 6 Flameless Diyas, Warm Yellow','huncha-led-tea-light-candles-pack-of-6-flameless-diyas-warm-yellow','SIK-FLT-2001',3,3,'Six battery-powered LED diyas with a flickering flame effect — no smoke, no wax and no open flame, so they are safe on a rangoli or around children.','<p>Six flameless tea lights that flicker like a real wick without any of the consequences: no smoke, no melted wax on the floor, and nothing hot to knock over. That combination is what makes them usable on a rangoli, along a staircase edge or on a low table where children and pets actually go.</p><p>Each candle runs on its own replaceable button cell and switches on underneath, so you can place them and forget them for the evening. The acrylic body picks up and scatters the light, which gives a softer glow than a bare LED.</p><p>Standard tea light dimensions, so they drop straight into holders you already own.</p>',149.00,NULL,NULL,300,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-tealight.svg','assets/images/placeholders/light-tealight-lit.svg',NULL,1,0,'active',0,1,0,0,0,5,0,0.00,0,'HUNCHA LED Tea Light Candles — Pack of 6 Flameless Diyas, Warm Yellow','Six battery-powered LED diyas with a flickering flame effect — no smoke, no wax and no open flame, so they are safe on a rangoli or around children.'),
(6,'luxentia LED Tea Light Candles — Pack of 6 Acrylic Diyas, 3 cm, Warm White','luxentia-led-tea-light-candles-pack-of-6-acrylic-diyas-3-cm-warm-white','SIK-FLT-2002',4,3,'Six 3 cm warm white LED diyas in acrylic bodies, with a simple on/off switch — sized to fit the tea light holders you already own.','<p>A compact 3 cm tea light, which matters more than it sounds: it is the standard size, so these sit properly in existing holders, lanterns and diya stands rather than rattling around or perching on top.</p><p>Warm white rather than yellow, giving a cleaner light that suits a temple shelf or a dinner table as readily as a festival display. The acrylic housing spreads the LED out instead of leaving a visible bright spot.</p><p>Flameless and smokeless throughout, with a single switch per candle. Nothing to trim, nothing to melt, and nothing that needs watching once the room empties.</p>',349.00,249.00,NULL,260,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-diya.svg','assets/images/placeholders/light-diya-lit.svg',NULL,1,0,'active',0,1,0,0,0,2,0,0.00,0,'luxentia LED Tea Light Candles — Pack of 6 Acrylic Diyas, 3 cm, Warm White','Six 3 cm warm white LED diyas in acrylic bodies, with a simple on/off switch — sized to fit the tea light holders you already own.'),
-- ---- String & Curtain Lights -------------------------------------
(7,'The Purple Tree Diya Curtain Light — 12 Hanging Diyas, 138 LED, 8 Modes, 2.5 m','the-purple-tree-diya-curtain-light-12-hanging-diyas-138-led-8-modes-2-5-m','SIK-FLT-1005',5,2,'Twelve hanging diya shades on a 2.5 m, 138 LED curtain — the traditional Diwali motif in a form you can hang and forget.','<p>Twelve diya-shaped shades hang from a 2.5 metre curtain lit by 138 LEDs, keeping the festival motif while skipping the oil, the wicks and the refilling. It is the most explicitly Diwali piece in this range, and the review count suggests it is the one people come back for.</p><p>Eight modes run from a button controller, with a warm yellow tone chosen to read as lamplight rather than as electric white. Hung in a window, the diya silhouettes are legible from the street, which a plain string never manages.</p><p>At 50 g it is the lightest curtain here — adhesive hooks or a curtain rod will hold it without complaint.</p>',1299.00,398.00,NULL,180,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-diya-curtain.svg','assets/images/placeholders/light-diya-curtain-lit.svg',NULL,1,0,'active',0,1,0,0,0,2,0,4.10,15192,'The Purple Tree Diya Curtain Light — 12 Hanging Diyas, 138 LED, 8 Modes, 2.5 m','Twelve hanging diya shades on a 2.5 m, 138 LED curtain — the traditional Diwali motif in a form you can hang and forget.'),
(8,'MIRADH 300 LED Fairy Curtain Lights — 9.8 x 9.8 ft, USB, Remote Controlled, Warm White','miradh-300-led-fairy-curtain-lights-9-8-x-9-8-ft-usb-remote-controlled-warm-white','SIK-FLT-1006',6,2,'A 3 x 3 metre wall of 300 warm white LEDs with a remote for brightness and mode, running off any USB port or power bank.','<p>Three metres square and 300 LEDs deep, this is a backdrop rather than an accent — the size photographers use behind a sweetheart table or a photo booth. Warm white keeps skin tones flattering in pictures, which the cooler multicolour version does not.</p><p>The remote is what separates it from cheaper curtains: brightness and mode change from across the room, so you can set it once the guests arrive rather than reaching behind the drape.</p><p>USB power is the other practical win. It runs from a phone adapter, a laptop or a power bank, which means the backdrop does not have to live next to a wall socket.</p>',399.00,NULL,NULL,140,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-curtain.svg','assets/images/placeholders/light-curtain-lit.svg',NULL,1,0,'active',0,1,0,0,0,3,0,3.90,234,'MIRADH 300 LED Fairy Curtain Lights — 9.8 x 9.8 ft, USB, Remote Controlled, Warm White','A 3 x 3 metre wall of 300 warm white LEDs with a remote for brightness and mode, running off any USB port or power bank.'),
(9,'MIRADH 300 LED Fairy Curtain Lights — USB, Remote Controlled, Multicolour','miradh-300-led-fairy-curtain-lights-usb-remote-controlled-multicolour','SIK-FLT-1007',6,2,'The multicolour version of the 300 LED USB curtain — eight modes, remote brightness control, and rated for sheltered outdoor use.','<p>Same 300 LED curtain and same remote as the warm white model, swapped to full multicolour. This is the one for a kids'' party, a Navratri setup or anywhere the colour is the point rather than the mood.</p><p>Eight modes cycle from a slow cross-fade through to a fast chase, all adjustable from the remote along with brightness. Copper-cored strands stay flexible in the cold and coil back into their box without kinking.</p><p>Rated for indoor and sheltered outdoor use, and USB powered, so a balcony railing or a garden gazebo is within reach of a power bank.</p>',1999.00,399.00,NULL,136,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-curtain-multi.svg','assets/images/placeholders/light-curtain-multi-lit.svg',NULL,1,0,'active',0,1,0,0,0,6,4,3.80,141,'MIRADH 300 LED Fairy Curtain Lights — USB, Remote Controlled, Multicolour','The multicolour version of the 300 LED USB curtain — eight modes, remote brightness control, and rated for sheltered outdoor use.'),
-- ---- Lamps & Projectors ------------------------------------------
(10,'Toy Imagine Galaxy Projector Lamp — Astronaut Star Night Light, Timer, 4 Modes','toy-imagine-galaxy-projector-lamp-astronaut-star-night-light-timer-4-modes','SIK-FLT-3001',7,4,'An astronaut-shaped star projector with four light modes, three brightness levels and an auto-off timer — built for a child''s bedtime rather than a party.','<p>A star projector in an astronaut housing that throws a galaxy across the ceiling. Four projection modes and three brightness levels mean it works as a full light show at bedtime and as a dim nightlight an hour later.</p><p>The timer is the feature parents actually buy it for: it shuts itself off after the child is asleep, so nobody has to creep back in. That single detail is what separates it from the projector toys that stay on until morning.</p><p>Suggested for ages 3 to 12, and it holds up as room decor when it is switched off — which is more than most character nightlights manage.</p>',699.00,351.00,NULL,97,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-projector.svg','assets/images/placeholders/light-projector-lit.svg',NULL,1,0,'active',0,1,0,0,0,5,3,3.90,269,'Toy Imagine Galaxy Projector Lamp — Astronaut Star Night Light, Timer, 4 Modes','An astronaut-shaped star projector with four light modes, three brightness levels and an auto-off timer — built for a child''s bedtime rather than a party.'),
(11,'TakshHaven Crystal Diamond Table Lamp — 16 Colour RGB, Touch & Remote, USB Rechargeable','takshhaven-crystal-diamond-table-lamp-16-colour-rgb-touch-remote-usb-rechargeable','SIK-FLT-3002',8,4,'A faceted crystal-effect bedside lamp cycling 16 RGB colours, controlled by touch or remote, and cordless once charged.','<p>A faceted diamond-cut body that scatters the LED into points of light across the surface it sits on. At 5 cm it is a bedside or shelf piece rather than a room light, and it is as much an object as it is a lamp.</p><p>Sixteen RGB colours and several effects switch from the touch base or the bundled remote. The rechargeable battery is what makes it genuinely portable — charge it over USB, then move it to a balcony, a dinner table or a restaurant setting with no cable trailing behind.</p><p>The packaging and price point make it a common gifting choice for birthdays, anniversaries and Diwali.</p>',1550.00,259.00,NULL,108,10,NULL,18.00,'9405','6 Month Seller Warranty',NULL,NULL,NULL,'assets/images/placeholders/light-lamp.svg','assets/images/placeholders/light-lamp-lit.svg',NULL,1,0,'active',0,1,0,0,0,4,2,4.00,198,'TakshHaven Crystal Diamond Table Lamp — 16 Colour RGB, Touch & Remote, USB Rechargeable','A faceted crystal-effect bedside lamp cycling 16 RGB colours, controlled by touch or remote, and cordless once charged.');

-- ===========================================================================
--  ShopInnKart - Demo seed, Part A : PRODUCT DETAIL TABLES
--  product_images, product_specifications, product_features,
--  product_variants, product_variant_attributes, product_tags,
--  product_relations
--
--  Depends on: schema.sql seed (products 1-11, attribute 1,
--              attribute_values 1-8, tags 1-12)
--  All imagery is a locally generated SVG placeholder.
-- ===========================================================================


-- ---------------------------------------------------------------------------
--  1. PRODUCT IMAGES  (gallery: the product's placeholder, then the same scene lit)
-- ---------------------------------------------------------------------------
INSERT INTO `product_images`
(`product_id`,`image`,`alt_text`,`sort_order`) VALUES
(1,'assets/images/placeholders/light-leaf-curtain.svg','Lexton Artificial Leaf Curtain LED String Light — 180 LED, 8 Modes, 10x3 ft',0),
(1,'assets/images/placeholders/light-leaf-curtain-lit.svg','Lexton Artificial Leaf Curtain LED String Light — 180 LED, 8 Modes, 10x3 ft, switched on',1),
(2,'assets/images/placeholders/light-star-curtain.svg','Lexton Star Curtain Light — 12 Stars, 138 LED, 8 Flashing Modes, Warm White',0),
(2,'assets/images/placeholders/light-star-curtain-lit.svg','Lexton Star Curtain Light — 12 Stars, 138 LED, 8 Flashing Modes, Warm White, switched on',1),
(3,'assets/images/placeholders/light-star-curtain-multi.svg','fizzytech Star SMD Fairy Curtain String Lights — 138 LED, 8 Modes, Multicolour',0),
(3,'assets/images/placeholders/light-star-curtain-multi-lit.svg','fizzytech Star SMD Fairy Curtain String Lights — 138 LED, 8 Modes, Multicolour, switched on',1),
(4,'assets/images/placeholders/light-fairy.svg','fizzytech Snowflake Fairy String Lights — 15 LED, 3 Metre, Warm White',0),
(4,'assets/images/placeholders/light-fairy-lit.svg','fizzytech Snowflake Fairy String Lights — 15 LED, 3 Metre, Warm White, switched on',1),
(5,'assets/images/placeholders/light-tealight.svg','HUNCHA LED Tea Light Candles — Pack of 6 Flameless Diyas, Warm Yellow',0),
(5,'assets/images/placeholders/light-tealight-lit.svg','HUNCHA LED Tea Light Candles — Pack of 6 Flameless Diyas, Warm Yellow, switched on',1),
(6,'assets/images/placeholders/light-diya.svg','luxentia LED Tea Light Candles — Pack of 6 Acrylic Diyas, 3 cm, Warm White',0),
(6,'assets/images/placeholders/light-diya-lit.svg','luxentia LED Tea Light Candles — Pack of 6 Acrylic Diyas, 3 cm, Warm White, switched on',1),
(7,'assets/images/placeholders/light-diya-curtain.svg','The Purple Tree Diya Curtain Light — 12 Hanging Diyas, 138 LED, 8 Modes, 2.5 m',0),
(7,'assets/images/placeholders/light-diya-curtain-lit.svg','The Purple Tree Diya Curtain Light — 12 Hanging Diyas, 138 LED, 8 Modes, 2.5 m, switched on',1),
(8,'assets/images/placeholders/light-curtain.svg','MIRADH 300 LED Fairy Curtain Lights — 9.8 x 9.8 ft, USB, Remote Controlled, Warm White',0),
(8,'assets/images/placeholders/light-curtain-lit.svg','MIRADH 300 LED Fairy Curtain Lights — 9.8 x 9.8 ft, USB, Remote Controlled, Warm White, switched on',1),
(9,'assets/images/placeholders/light-curtain-multi.svg','MIRADH 300 LED Fairy Curtain Lights — USB, Remote Controlled, Multicolour',0),
(9,'assets/images/placeholders/light-curtain-multi-lit.svg','MIRADH 300 LED Fairy Curtain Lights — USB, Remote Controlled, Multicolour, switched on',1),
(10,'assets/images/placeholders/light-projector.svg','Toy Imagine Galaxy Projector Lamp — Astronaut Star Night Light, Timer, 4 Modes',0),
(10,'assets/images/placeholders/light-projector-lit.svg','Toy Imagine Galaxy Projector Lamp — Astronaut Star Night Light, Timer, 4 Modes, switched on',1),
(11,'assets/images/placeholders/light-lamp.svg','TakshHaven Crystal Diamond Table Lamp — 16 Colour RGB, Touch & Remote, USB Rechargeable',0),
(11,'assets/images/placeholders/light-lamp-lit.svg','TakshHaven Crystal Diamond Table Lamp — 16 Colour RGB, Touch & Remote, USB Rechargeable, switched on',1);


-- ---------------------------------------------------------------------------
--  2. PRODUCT SPECIFICATIONS (the listing facts: LED count, length, power, modes)
-- ---------------------------------------------------------------------------
INSERT INTO `product_specifications`
(`product_id`,`spec_group`,`spec_key`,`spec_value`,`sort_order`) VALUES
-- 1 SIK-FLT-1001
(1,'General','Product Type','Leaf curtain string light',0),
(1,'General','Theme','Artificial leaf',1),
(1,'General','Country of Origin','India',2),
(1,'Lighting','Number of LEDs','180',3),
(1,'Lighting','Light Colour','Warm White',4),
(1,'Lighting','Light Source','LED',5),
(1,'Lighting','Lighting Modes','8',6),
(1,'Lighting','Brightness Control','Yes, in-line button',7),
(1,'Power','Power Source','Corded electric (plug powered)',8),
(1,'Power','Controller','In-line button on the wire',9),
(1,'Dimensions & Weight','Size','10 x 3 feet',10),
(1,'Dimensions & Weight','Weight','160 g',11),
(1,'Usage','Indoor / Outdoor','Indoor',12),
(1,'Usage','Occasions','Diwali, Christmas, weddings, anniversaries',13),
(1,'In The Box','Contents','1 x 180 LED leaf curtain string light',14),
-- 2 SIK-FLT-1002
(2,'General','Product Type','Star curtain light',0),
(2,'General','Theme','Stars',1),
(2,'General','Country of Origin','India',2),
(2,'Lighting','Number of LEDs','138',3),
(2,'Lighting','Light Colour','Warm White',4),
(2,'Lighting','Light Source','LED',5),
(2,'Lighting','Lighting Modes','8',6),
(2,'Power','Power Source','Corded electric',7),
(2,'Power','Wattage','5 W',8),
(2,'Power','Controller','Manual control',9),
(2,'Dimensions & Weight','Weight','400 g',10),
(2,'Usage','Indoor / Outdoor','Indoor and covered outdoor',11),
(2,'Usage','Weather Rating','IP44',12),
(2,'Usage','Occasions','Diwali, Christmas, weddings',13),
(2,'In The Box','Contents','1 x 12-star curtain light (6 big + 6 small)',14),
-- 3 SIK-FLT-1003
(3,'General','Product Type','Star fairy curtain light',0),
(3,'General','Theme','Stars',1),
(3,'General','Country of Origin','India',2),
(3,'Lighting','Number of LEDs','138',3),
(3,'Lighting','Light Colour','Multicolour',4),
(3,'Lighting','Light Source','LED (SMD)',5),
(3,'Lighting','Lighting Modes','8',6),
(3,'Power','Power Source','Corded electric',7),
(3,'Power','Wattage','5 W',8),
(3,'Power','Voltage','220 V',9),
(3,'Dimensions & Weight','Weight','100 g',10),
(3,'Usage','Indoor / Outdoor','Indoor and outdoor',11),
(3,'Usage','Occasions','Diwali, Christmas, birthdays, weddings, engagements',12),
(3,'In The Box','Contents','1 x 138 LED star fairy curtain light',13),
-- 4 SIK-FLT-1004
(4,'General','Product Type','Snowflake fairy string light',0),
(4,'General','Theme','Snowflake',1),
(4,'General','Country of Origin','India',2),
(4,'Lighting','Number of LEDs','15',3),
(4,'Lighting','Light Colour','Warm White',4),
(4,'Lighting','Light Source','LED',5),
(4,'Power','Power Source','Corded electric',6),
(4,'Power','Wattage','5 W',7),
(4,'Power','Voltage','220 V AC',8),
(4,'Power','Controller','Button control',9),
(4,'Dimensions & Weight','Length','3 metres',10),
(4,'Dimensions & Weight','Weight','100 g',11),
(4,'Usage','Indoor / Outdoor','Indoor and outdoor',12),
(4,'Usage','Occasions','Diwali, Christmas, birthdays, baby showers, anniversaries',13),
(4,'In The Box','Contents','1 x 3 m snowflake fairy string light',14),
-- 5 SIK-FLT-2001
(5,'General','Product Type','Flameless LED tea light',0),
(5,'General','Style','Modern',1),
(5,'General','Country of Origin','India',2),
(5,'Lighting','Number of Candles','6',3),
(5,'Lighting','Light Colour','Warm Yellow',4),
(5,'Lighting','Light Source','LED',5),
(5,'Lighting','Effect','Flickering flame',6),
(5,'Power','Power Source','Battery powered (button cell, included)',7),
(5,'Dimensions & Weight','Weight','7 g per candle',8),
(5,'Materials','Body','Acrylic / plastic',9),
(5,'In The Box','Contents','6 x LED tea light candles with batteries',10),
-- 6 SIK-FLT-2002
(6,'General','Product Type','Flameless LED tea light',0),
(6,'General','Country of Origin','India',1),
(6,'Lighting','Number of Candles','6',2),
(6,'Lighting','Light Colour','Warm White',3),
(6,'Lighting','Light Source','LED',4),
(6,'Power','Power Source','Battery powered',5),
(6,'Power','Controller','ON/OFF switch',6),
(6,'Dimensions & Weight','Diameter','3 cm',7),
(6,'Dimensions & Weight','Weight','130 g (pack)',8),
(6,'Materials','Body','Acrylic / plastic',9),
(6,'In The Box','Contents','6 x LED tea light candles, instruction manual',10),
-- 7 SIK-FLT-1005
(7,'General','Product Type','Diya curtain light',0),
(7,'General','Theme','Diya',1),
(7,'General','Country of Origin','India',2),
(7,'Lighting','Number of LEDs','138',3),
(7,'Lighting','Number of Diyas','12',4),
(7,'Lighting','Light Colour','Warm Yellow',5),
(7,'Lighting','Lighting Modes','8',6),
(7,'Power','Power Source','Corded electric',7),
(7,'Power','Wattage','5 W',8),
(7,'Power','Voltage','220 V',9),
(7,'Power','Controller','Button control',10),
(7,'Dimensions & Weight','Length','2.5 metres',11),
(7,'Dimensions & Weight','Weight','50 g',12),
(7,'Usage','Indoor / Outdoor','Indoor',13),
(7,'Usage','Occasions','Diwali, Christmas, weddings, home decoration',14),
(7,'In The Box','Contents','1 x diya curtain light (12 diyas)',15),
-- 8 SIK-FLT-1006
(8,'General','Product Type','Fairy curtain backdrop light',0),
(8,'General','Style','Modern',1),
(8,'Lighting','Number of LEDs','300',2),
(8,'Lighting','Light Colour','Warm White',3),
(8,'Lighting','Light Source','LED',4),
(8,'Power','Power Source','USB powered',5),
(8,'Power','Wattage','4 W',6),
(8,'Power','Controller','Remote control',7),
(8,'Dimensions & Weight','Size','9.8 x 9.8 feet (3 x 3 m)',8),
(8,'Dimensions & Weight','Weight','150 g',9),
(8,'Usage','Indoor / Outdoor','Indoor',10),
(8,'Usage','Occasions','Diwali, Navratri, Christmas, New Year, weddings',11),
(8,'In The Box','Contents','1 x 300 LED curtain light, 1 x remote control',12),
-- 9 SIK-FLT-1007
(9,'General','Product Type','Fairy curtain backdrop light',0),
(9,'General','Style','Festive decorative lighting',1),
(9,'Lighting','Number of LEDs','300',2),
(9,'Lighting','Light Colour','Multicolour',3),
(9,'Lighting','Lighting Modes','8',4),
(9,'Lighting','Light Source','LED',5),
(9,'Power','Power Source','USB powered',6),
(9,'Power','Controller','Remote control',7),
(9,'Power','Brightness','Adjustable',8),
(9,'Dimensions & Weight','Weight','200 g',9),
(9,'Materials','Strand Core','Copper',10),
(9,'Usage','Indoor / Outdoor','Indoor and sheltered outdoor',11),
(9,'Usage','Occasions','Diwali, Navratri, Christmas, birthdays, anniversaries',12),
(9,'In The Box','Contents','1 x 300 LED curtain light, 1 x remote control',13),
-- 10 SIK-FLT-3001
(10,'General','Product Type','Star projector night light',0),
(10,'General','Style','Astronaut / space',1),
(10,'General','Country of Origin','India',2),
(10,'Lighting','Light Source','LED',3),
(10,'Lighting','Projection Modes','4',4),
(10,'Lighting','Brightness Levels','3',5),
(10,'Power','Power Source','USB powered',6),
(10,'Power','Wattage','5 W',7),
(10,'Power','Controller','Touch control',8),
(10,'Power','Timer','Built-in auto shut-off',9),
(10,'Dimensions & Weight','Weight','300 g',10),
(10,'Materials','Body','ABS / plastic',11),
(10,'In The Box','Contents','Projector lamp, USB cable, user manual',12),
-- 11 SIK-FLT-3002
(11,'General','Product Type','Decorative table lamp',0),
(11,'General','Style','Modern',1),
(11,'General','Country of Origin','India',2),
(11,'Lighting','Light Source','LED',3),
(11,'Lighting','Colours','16 RGB',4),
(11,'Lighting','Effects','Multiple colour-changing modes',5),
(11,'Power','Power Source','USB rechargeable battery',6),
(11,'Power','Controller','Touch and remote control',7),
(11,'Dimensions & Weight','Dimensions','5 x 5 x 5 cm',8),
(11,'Dimensions & Weight','Weight','320 g',9),
(11,'Materials','Body','Plastic',10),
(11,'Materials','Shade','Silicone',11),
(11,'In The Box','Contents','Crystal diamond table lamp, remote control, USB charging cable, user manual',12);

-- (The previous seed's second specifications block is folded into the one above.)


-- ---------------------------------------------------------------------------
--  3. PRODUCT FEATURES
-- ---------------------------------------------------------------------------
INSERT INTO `product_features`
(`product_id`,`feature`,`sort_order`) VALUES
(1,'180 warm white LEDs across a 10 x 3 ft leaf-vine curtain',0),
(1,'8 lighting patterns including steady, twinkle and slow fade',1),
(1,'Brightness adjusts from the same in-line button controller',2),
(1,'Plug-powered — no remote and no batteries required',3),
(1,'Artificial leaf styling that decorates the wall even when switched off',4),
(2,'12 stars — 6 large and 6 small — on one curtain',0),
(2,'138 warm white LEDs',1),
(2,'8 flashing modes including twinkle, chase and steady-on',2),
(2,'IP44 rated wiring for covered outdoor use',3),
(2,'Plug in and go — no assembly needed',4),
(3,'138 multicolour SMD LEDs in a star curtain layout',0),
(3,'8 lighting modes from slow fade to full chase',1),
(3,'Flat-sitting SMD diodes that resist tangling',2),
(3,'Indoor and sheltered outdoor use',3),
(3,'Only 100 g — hangs from removable adhesive hooks',4),
(4,'15 snowflake-shaded LEDs across 3 metres',0),
(4,'Warm white tone that stays soft in small rooms',1),
(4,'Shaped diffusers instead of bare point-source LEDs',2),
(4,'Button controller on the wire',3),
(4,'Light enough for adhesive hooks — 100 g total',4),
(5,'Pack of 6 flameless LED tea lights',0),
(5,'Flickering flame effect in a warm yellow tone',1),
(5,'No smoke, no wax and nothing hot to touch',2),
(5,'Individual on/off switch and replaceable button cell',3),
(5,'Fits standard tea light holders and diya stands',4),
(6,'Pack of 6 LED tea lights in a 3 cm standard size',0),
(6,'Warm white glow suited to temple shelves and dinner tables',1),
(6,'Flameless and smokeless — safe around children and elders',2),
(6,'Simple ON/OFF switch on each candle',3),
(6,'Drops into existing tea light holders and lanterns',4),
(7,'12 hanging diya shades on a 2.5 m curtain',0),
(7,'138 LEDs in a warm yellow, lamplight-style tone',1),
(7,'8 lighting modes via button controller',2),
(7,'Diya silhouettes read clearly from outside a window',3),
(7,'Just 50 g — the lightest curtain in the range',4),
(8,'300 warm white LEDs across a 9.8 x 9.8 ft (3 x 3 m) curtain',0),
(8,'Remote control for brightness and lighting mode',1),
(8,'USB powered — runs from an adapter, laptop or power bank',2),
(8,'Photo-friendly warm tone for backdrops and booths',3),
(8,'Low-draw LEDs at roughly 4 W',4),
(9,'300 multicolour LEDs with 8 lighting modes',0),
(9,'Remote control for brightness and mode',1),
(9,'USB powered — adapter, laptop or power bank',2),
(9,'Flexible copper-cored strands that resist kinking',3),
(9,'Suitable for indoor and sheltered outdoor use',4),
(10,'4 projection modes with 3 dimmable brightness levels',0),
(10,'Auto-off timer so it does not run all night',1),
(10,'Astronaut housing that works as daytime room decor',2),
(10,'Touch control, USB powered',3),
(10,'Suggested for ages 3 to 12',4),
(11,'16 RGB colours with multiple lighting effects',0),
(11,'Touch base and bundled remote control',1),
(11,'USB rechargeable — runs cordless once charged',2),
(11,'Faceted crystal-effect body that scatters light',3),
(11,'Compact 5 cm footprint for a bedside or shelf',4);


-- ---------------------------------------------------------------------------
--  4a. PRODUCT VARIANTS
-- ---------------------------------------------------------------------------
-- No variants are seeded. Each colour of a light is listed as its own product
-- (warm white and multicolour MIRADH curtains are two SKUs), exactly as the
-- live catalogue does; the Colour attribute above is ready when that changes.


-- ---------------------------------------------------------------------------
--  4b. PRODUCT VARIANT ATTRIBUTES
-- ---------------------------------------------------------------------------
-- (none - see 4a)


-- ---------------------------------------------------------------------------
--  5. PRODUCT TAGS
--   1 Diwali  2 Christmas  3 Wedding Decor  4 Warm White  5 Multicolour
--   6 Remote Control  7 USB Powered  8 Budget Pick  9 Premium
--   10 Kids Room  11 Pooja Room  12 Gift Pick
-- ---------------------------------------------------------------------------
INSERT INTO `product_tags` (`product_id`,`tag_id`) VALUES
(1,1),
(1,3),
(1,4),
(1,8),
(2,1),
(2,2),
(2,4),
(2,8),
(3,2),
(3,5),
(3,10),
(4,2),
(4,4),
(4,8),
(5,1),
(5,11),
(5,8),
(6,1),
(6,11),
(6,12),
(7,1),
(7,3),
(7,11),
(8,3),
(8,4),
(8,6),
(8,7),
(9,2),
(9,5),
(9,6),
(9,7),
(10,10),
(10,7),
(10,12),
(11,5),
(11,6),
(11,12);


-- ---------------------------------------------------------------------------
--  6. PRODUCT RELATIONS
-- ---------------------------------------------------------------------------
-- No hand-made relations are seeded. With eleven products in three categories
-- the related and bought-together rails fill themselves from the category and
-- best-seller fallbacks, which is what the live store relies on too.

-- ===========================================================================
--  End of Part A
-- ===========================================================================


-- ===========================================================================
--  ShopInnKart - SEED B : STOREFRONT CMS
--  Announcements, banners, trust features, stats, testimonials, widgets,
--  menus, footer builder and popups.
--  MySQL / MariaDB 10.4 compatible. All dates are relative to import time.
-- ===========================================================================

-- ---------------------------------------------------------------------------
--  1. Top bar announcements
-- ---------------------------------------------------------------------------
INSERT INTO `announcements`
(`id`,`text`,`subtext`,`icon`,`link`,`bg_color`,`text_color`,`sort_order`,`start_date`,`end_date`,`status`) VALUES
(1,'Free delivery on orders over ₹999',NULL,'truck','shop.php','#0F2143','#FFFFFF',1,DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_ADD(NOW(), INTERVAL 180 DAY),'active'),
(2,'Extra 10% OFF on prepaid orders - pay by UPI, card or netbanking','Discount applied automatically at checkout','tag','deals.php','#0F2143','#FFFFFF',2,DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_ADD(NOW(), INTERVAL 180 DAY),'inactive'),
(3,'24/7 customer support on 1800-123-4567','Real humans, every day of the week','headset','contact.php','#0F2143','#FFFFFF',3,DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_ADD(NOW(), INTERVAL 180 DAY),'inactive');

-- ---------------------------------------------------------------------------
--  2. Banners - 3 hero slides + 3 navy promo bands
--
--  Where this artwork is actually seen: the admin only. Every path below is
--  under assets/images/, and banner_image_is_stock() in includes/widgets.php
--  treats any assets/images/ path as "no image uploaded", so the storefront
--  hero and promo widgets skip the <picture> block entirely and build the
--  slide from the store's own product photography instead. The files are the
--  thumbnails in Marketing > Banners and the artwork an admin sees while
--  editing a slide; they are not storefront art, and the *-mobile.svg files
--  are not reached at all until a real upload replaces desktop_image. Anything
--  uploaded through the admin lands under uploads/ and is rendered as it is.
-- ---------------------------------------------------------------------------
INSERT INTO `banners`
(`id`,`position`,`title`,`title_accent`,`subtitle`,`description`,`badge`,`desktop_image`,`mobile_image`,`button_text`,`button_url`,`button2_text`,`button2_url`,`bg_color`,`text_color`,`sort_order`,`start_date`,`end_date`,`status`) VALUES
(1,'hero','Light up','every celebration','String lights, curtain lights, LED diyas and lamps for Diwali, weddings and everyday warmth.','Fairy and curtain lights, flameless diyas, crystal table lamps and galaxy projectors — a small range, chosen carefully, all of it under ₹400. Free delivery over ₹999.','Welcome to ShopInnKart','assets/images/banners/hero-1.svg','assets/images/banners/hero-1-mobile.svg','Shop the range','shop.php','See offers','deals.php',NULL,NULL,1,DATE_SUB(NOW(), INTERVAL 20 DAY),DATE_ADD(NOW(), INTERVAL 120 DAY),'active'),
(2,'hero','Curtain lights','for the whole wall','Star, snowflake and diya curtains that turn a plain wall into a backdrop.','Star and snowflake curtains, 300 LED fairy curtains with a remote, and hanging diya strands. USB or mains, warm white or multicolour, eight modes as standard.','Backdrop ready','assets/images/banners/hero-2.svg','assets/images/banners/hero-2-mobile.svg','Shop curtain lights','shop.php?category=string-curtain-lights','All products','shop.php',NULL,NULL,2,DATE_SUB(NOW(), INTERVAL 14 DAY),DATE_ADD(NOW(), INTERVAL 120 DAY),'active'),
(3,'hero','Up to 80% off','this festive season','Nine of our eleven products are discounted right now.','Curtain lights, table lamps and projector lamps at their lowest of the season. Cash on Delivery available, and 7 days to send anything back.','Limited period offer','assets/images/banners/hero-3.svg','assets/images/banners/hero-3-mobile.svg','Shop the sale','deals.php','Browse all','shop.php',NULL,NULL,3,DATE_SUB(NOW(), INTERVAL 7 DAY),DATE_ADD(NOW(), INTERVAL 120 DAY),'active'),
(4,'promo','From','₹149','LED diyas and tea light candles.','Flameless diyas and acrylic tea lights — safe near children, pets and curtains, and they pack away for next year.','Diyas & candles','assets/images/banners/promo-1.svg','assets/images/banners/promo-1.svg','Shop diyas','shop.php?category=diyas-led-candles','','',NULL,NULL,1,DATE_SUB(NOW(), INTERVAL 5 DAY),DATE_ADD(NOW(), INTERVAL 45 DAY),'active'),
(5,'promo','Up to','83% off','Table lamps and galaxy projectors.','Crystal table lamps with touch and remote control, and astronaut galaxy projectors for a ceiling that does the decorating.','Lamps & projectors','assets/images/banners/promo-2.svg','assets/images/banners/promo-2.svg','Shop lamps','shop.php?category=lamps-projectors','','',NULL,NULL,2,DATE_SUB(NOW(), INTERVAL 5 DAY),DATE_ADD(NOW(), INTERVAL 45 DAY),'active'),
(6,'promo','Free delivery','over ₹999','On every order, anywhere we deliver.','Dispatched within one working day. Pay the courier on delivery if you prefer, and raise a return from your orders page within 7 days.','Delivery & returns','assets/images/banners/promo-3.svg','assets/images/banners/promo-3.svg','Start shopping','shop.php','','',NULL,NULL,3,DATE_SUB(NOW(), INTERVAL 2 DAY),DATE_ADD(NOW(), INTERVAL 30 DAY),'active');

-- ---------------------------------------------------------------------------
--  3. Trust features - hero overlay (4) + full width strip (6)
-- ---------------------------------------------------------------------------
INSERT INTO `trust_features`
(`id`,`placement`,`title`,`subtitle`,`icon`,`link`,`sort_order`,`status`) VALUES
(1,'hero','Handpicked Lighting','Festive and decorative lights, chosen and checked','badge',NULL,1,'active'),
(2,'hero','Cash on Delivery','Pay when your order reaches you','lock',NULL,2,'active'),
(3,'hero','Easy Returns','7 day return window on every order','refresh',NULL,3,'active'),
(4,'hero','Customer Support','Mon - Sat: 9:00 AM to 8:00 PM IST','headset','contact.php',4,'active'),
(5,'strip','Free Delivery','On orders above ₹999','truck','shop.php',1,'active'),
(6,'strip','7 Day Returns','Raise a request from your orders page','refresh',NULL,2,'active'),
(7,'strip','Cash on Delivery','Pay the courier when your order arrives','shield',NULL,3,'active'),
(8,'strip','Customer Support','Mon - Sat: 9:00 AM to 8:00 PM IST','headset','contact.php',4,'active'),
(9,'strip','100% Genuine','Official India warranty on every item','verified',NULL,5,'inactive'),
(10,'strip','Easy EMI','No-cost EMI from ₹999 per month','card',NULL,6,'inactive');

-- ---------------------------------------------------------------------------
--  4. Stats band
-- ---------------------------------------------------------------------------
INSERT INTO `site_stats`
(`id`,`value`,`label`,`icon`,`sort_order`,`status`) VALUES
(1,'50K+','Happy Customers','users',1,'active'),
(2,'4.8/5','Average Rating','star',2,'active'),
(3,'12K+','Products Listed','box',3,'active'),
(4,'100%','Secure Checkout','lock',4,'active');

-- ---------------------------------------------------------------------------
--  5. Testimonials
-- ---------------------------------------------------------------------------
-- None seeded. A testimonial is a named customer's own words; the previous
-- seed shipped six invented ones, which the live store has since switched
-- off. Add real ones from Admin > Testimonials.

-- ---------------------------------------------------------------------------
--  6. Widget instances (homepage builder)
--
--  A section is only seeded 'active' when this file also seeds the rows it
--  reads. The two review bands are the case that matters: this seed writes no
--  `reviews` and no `testimonials`, because a review is a customer's own words
--  and inventing one would put fabricated testimony on the homepage. So
--  home_product_reviews and home_customer_reviews both ship 'inactive' - the
--  owner switches them on from Appearance > Homepage once real reviews exist,
--  rather than a fresh install carrying a band that can never render.
-- ---------------------------------------------------------------------------
INSERT INTO `homepage_sections`
(`id`,`section_key`,`zone`,`widget_type`,`title`,`title_accent`,`subtitle`,`description`,`link_text`,`link_url`,`image`,`mobile_image`,`data_source`,`source_id`,`item_limit`,`layout`,`card_style`,`cols_desktop`,`cols_tablet`,`cols_mobile`,`autoplay`,`autoplay_speed`,`show_arrows`,`show_dots`,`animation`,`bg_color`,`text_color`,`container`,`padding`,`device_visibility`,`auth_visibility`,`lazy_load`,`settings`,`sort_order`,`status`) VALUES
(2,'home_announcement_ticker','home','ticker',NULL,NULL,NULL,'Scrolling offer ticker fed by the announcements table.',NULL,NULL,NULL,NULL,'auto',NULL,3,'list','minimal',1,1,1,1,4000,0,0,'none',NULL,NULL,'full','none','all','all',0,'{"source_table":"announcements","direction":"left","pause_on_hover":true}',2,'inactive'),
(1,'home_hero_slider','home','hero',NULL,NULL,NULL,'Full width hero slider driven by the banners table (position = hero).',NULL,NULL,NULL,NULL,'manual',NULL,3,'carousel','premium',1,1,1,1,6000,1,1,'fade',NULL,NULL,'full','none','all','all',0,'{"source_table":"banners","position":"hero","overlay":"navy-gradient","show_trust_row":true}',10,'active'),
(11,'home_trending_now','home','product_carousel','Trending now',NULL,'Most viewed in the last seven days',NULL,'View all','shop.php?sort=trending',NULL,NULL,'trending',NULL,12,'carousel','standard',5,3,2,1,4000,1,1,'fade-up',NULL,NULL,'boxed','md','all','all',0,'{"show_view_count":true,"show_add_to_cart":true,"cart_button_label":"ADD TO CART"}',11,'inactive'),
(12,'home_top_brands','home','brand_slider','Brands',NULL,'The lighting makers we stock',NULL,'All brands','brands.php',NULL,NULL,'auto',NULL,18,'carousel','minimal',8,5,3,1,3000,1,0,'fade-up',NULL,NULL,'boxed','md','all','all',0,'{"source_table":"brands","grayscale_until_hover":true}',12,'inactive'),
(13,'home_why_shop_with_us','home','stats','Why shop','with us','Numbers our customers put there',NULL,NULL,NULL,NULL,NULL,'auto',NULL,4,'grid','minimal',4,4,2,0,4000,0,0,'count-up',NULL,NULL,'full','lg','all','all',0,'{"source_table":"site_stats","counter_animation":true,"accent":"#F4511E"}',13,'inactive'),
(14,'home_customer_reviews','home','testimonials','Customer','reviews','Straight from verified buyers',NULL,NULL,NULL,NULL,NULL,'auto',NULL,6,'carousel','premium',3,2,1,1,5500,1,1,'fade-up',NULL,NULL,'boxed','lg','all','all',0,'{"source_table":"testimonials","show_rating_stars":true,"quote_mark":true}',14,'inactive'),
(4,'home_shop_by_category','home','category_grid','Shop by category',NULL,'String lights, diyas and lamps for every corner of the home',NULL,'View all','shop.php',NULL,NULL,'auto',NULL,8,'grid','circular',4,3,2,1,3500,1,0,'fade-up',NULL,NULL,'boxed','md','all','all',0,'{"source_table":"categories","featured_only":false,"shape":"circle","ring_color":"#F4511E","label_position":"below"}',20,'active'),
(9,'home_featured_picks','home','product_grid','Featured',NULL,'Handpicked pieces for the season',NULL,'View all','shop.php?featured=1',NULL,NULL,'featured',NULL,4,'grid','standard',4,3,2,0,4000,0,0,'fade-up',NULL,NULL,'boxed','md','all','all',0,'{"show_add_to_cart":true,"cart_button_label":"ADD TO CART"}',30,'inactive'),
(10,'home_promo_banner_band','home','promo_banner',NULL,NULL,NULL,NULL,NULL,'deals.php','assets/images/banners/promo-1.svg','assets/images/banners/promo-1.svg','manual',NULL,3,'carousel','premium',1,1,1,1,5000,1,1,'fade',NULL,NULL,'full','lg','all','all',0,'{"source_table":"banners","position":"promo","diagonal_split":true,"accent":"#F4511E"}',30,'active'),
(6,'home_flash_sale','home','flash_sale','Flash sale',NULL,'Live now - limited units at these prices',NULL,'See all','deals.php?type=flash',NULL,NULL,'flash',NULL,8,'carousel','premium',5,3,2,1,4500,1,1,'fade-up',NULL,NULL,'full','md','all','all',0,'{"show_countdown":true,"show_sold_bar":true,"accent":"#F4511E"}',40,'active'),
(23,'home_combo_offers','home','combo_grid','Combo offers',NULL,'Buy the set and save',NULL,'All combos','combos.php',NULL,NULL,'auto',NULL,6,'grid','standard',3,2,1,0,4000,1,1,'fade-up',NULL,NULL,'boxed','md','all','all',0,NULL,45,'active'),
(22,'home_offer_strip','home','offer_strip','Offers running',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'auto',NULL,3,'grid','standard',4,3,2,0,4000,1,1,'fade-up',NULL,NULL,'boxed','sm','all','all',0,NULL,50,'active'),
(3,'home_trust_strip','home','trust',NULL,NULL,NULL,'Six icon trust strip under the hero (trust_features, placement = strip).',NULL,NULL,NULL,NULL,'auto',NULL,6,'grid','minimal',6,3,2,0,4000,0,0,'fade-up',NULL,NULL,'boxed','sm','all','all',0,'{"source_table":"trust_features","placement":"strip","divider":true}',60,'active'),
(7,'home_new_arrivals','home','product_grid','New arrivals',NULL,'Just landed at ShopInnKart',NULL,'View all','new-arrivals.php',NULL,NULL,'new',NULL,8,'grid','standard',4,3,2,0,4000,1,1,'fade-up',NULL,NULL,'boxed','md','all','all',0,'{"badge":"NEW","show_add_to_cart":true,"cart_button_label":"ADD TO CART"}',70,'active'),
(8,'home_best_sellers','home','product_grid','Best sellers',NULL,'What shoppers are bringing home',NULL,'View all','best-sellers.php',NULL,NULL,'best',NULL,8,'grid','standard',4,3,2,0,4000,0,0,'fade-up',NULL,NULL,'boxed','md','all','all',0,'{"show_rank_badge":true,"show_add_to_cart":true,"cart_button_label":"ADD TO CART"}',80,'active'),
(5,'home_deal_of_the_day','home','deal_of_day','Deal of the day',NULL,'Prices drop at midnight - grab them before the timer runs out',NULL,'All deals','deals.php',NULL,NULL,'deal',NULL,4,'grid','premium',4,2,1,0,4000,1,0,'fade-up',NULL,NULL,'boxed','md','all','all',0,'{"show_countdown":true,"show_progress_bar":true,"timer_style":"boxed"}',90,'active'),
(15,'home_recently_viewed','home','recently_viewed','Recently viewed',NULL,'Pick up where you left off',NULL,'Clear','#clear-recently-viewed',NULL,NULL,'auto',NULL,10,'carousel','compact',5,3,2,0,4000,1,0,'fade-up',NULL,NULL,'boxed','md','all','all',1,'{"hide_when_empty":true,"storage":"cookie"}',100,'active'),
(20,'home_product_reviews','home','reviews','What customers say',NULL,'Verified reviews from recent orders',NULL,NULL,NULL,NULL,NULL,'auto',NULL,6,'grid','standard',3,2,1,0,4000,1,1,'fade-up',NULL,NULL,'boxed','md','all','all',0,NULL,110,'inactive'),
(21,'home_faq','home','faq','Frequently asked questions',NULL,'Delivery, returns and payments, answered briefly',NULL,'All FAQs','faq.php',NULL,NULL,'auto',NULL,6,'list','standard',1,1,1,0,4000,1,1,'fade-up',NULL,NULL,'boxed','md','all','all',0,NULL,120,'active'),
(16,'home_newsletter','home','newsletter','Stay in the loop',NULL,'New collections and offers, a couple of emails a month. Unsubscribe any time.',NULL,'Subscribe','#newsletter-form','assets/images/banners/newsletter-diagonal.svg','assets/images/banners/newsletter-diagonal.svg','auto',NULL,1,'grid','premium',1,1,1,0,4000,0,0,'fade-up',NULL,NULL,'full','lg','all','guest',0,'{"style":"diagonal","primary":"#F4511E","secondary":"#0F2143","placeholder":"Enter your email address","consent_text":"We never share your email."}',130,'active'),
(19,'cart_recommended_products','cart','recommendations','You might also like',NULL,'Accessories that pair well with your cart',NULL,'Continue shopping','shop.php',NULL,NULL,'trending',NULL,8,'carousel','compact',4,3,2,0,4000,1,0,'fade-up',NULL,NULL,'boxed','md','all','all',1,'{"exclude_cart_items":true,"max_price_ratio":0.5}',1,'active'),
(17,'product_related_products','product_bottom','product_carousel','Related products',NULL,'More from this category',NULL,'View category','shop.php',NULL,NULL,'category',NULL,10,'carousel','standard',5,3,2,0,4000,1,1,'fade-up',NULL,NULL,'boxed','md','all','all',1,'{"exclude_current":true,"fallback":"trending"}',1,'active'),
(18,'product_frequently_bought','product_bottom','recommendations','Frequently','bought together','Customers usually add these as well',NULL,NULL,NULL,NULL,NULL,'auto',NULL,4,'grid','horizontal',4,2,1,0,4000,0,0,'fade-up','#F7F8FA',NULL,'boxed','md','all','all',1,'{"bundle_mode":true,"show_bundle_total":true,"cart_button_label":"ADD BUNDLE TO CART"}',2,'active');

-- ---------------------------------------------------------------------------
--  7. Menus and menu items
-- ---------------------------------------------------------------------------
INSERT INTO `menus` (`id`,`name`,`location`,`status`) VALUES
(1,'Main Navigation','main','active'),
(2,'Mobile Navigation','mobile','active'),
(3,'Footer - Quick Links','footer_quick','active'),
(4,'Footer - Customer Service','footer_service','active'),
(5,'Footer - About','footer_about','active');

INSERT INTO `menu_items`
(`id`,`menu_id`,`parent_id`,`label`,`link_type`,`reference_id`,`url`,`icon`,`badge`,`badge_color`,`is_mega`,`mega_columns`,`mega_image`,`mega_image_url`,`mega_products`,`open_new_tab`,`device_visibility`,`auth_visibility`,`sort_order`,`status`) VALUES
-- ---- Main Navigation ----
(90,1,NULL,'Home','custom',NULL,'index.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',10,'inactive'),
(91,1,NULL,'Shop by Category','custom',NULL,'shop.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',20,'active'),
(96,1,NULL,'About','custom',NULL,'about.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',30,'inactive'),
(110,1,NULL,'New arrivals','custom',NULL,'new-arrivals.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',30,'active'),
(97,1,NULL,'Blog','custom',NULL,'blog.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',40,'inactive'),
(111,1,NULL,'Best sellers','custom',NULL,'best-sellers.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',40,'active'),
(114,1,NULL,'Festival Offers','category',1,'shop.php?category=festive-decor-lighting',NULL,'Sale',NULL,0,4,NULL,NULL,NULL,0,'all','all',120,'active'),
(115,1,NULL,'String & Curtain Lights','category',2,'shop.php?category=string-curtain-lights',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',130,'active'),
(116,1,NULL,'Diyas & LED Candles','category',3,'shop.php?category=diyas-led-candles',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',140,'active'),
(117,1,NULL,'Lamps & Projectors','category',4,'shop.php?category=lamps-projectors',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',150,'active'),
(98,1,NULL,'FAQs','custom',NULL,'faq.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',200,'active'),
(99,1,NULL,'Contact','custom',NULL,'contact.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',210,'active'),
(92,1,91,'All products','custom',NULL,'shop.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',10,'active'),
(93,1,91,'String & Curtain Lights','category',2,'shop.php?category=string-curtain-lights',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',20,'active'),
(94,1,91,'Diyas & LED Candles','category',3,'shop.php?category=diyas-led-candles',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',30,'active'),
(95,1,91,'Lamps & Projectors','category',4,'shop.php?category=lamps-projectors',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',40,'active'),
-- ---- Mobile Navigation ----
(100,2,NULL,'Home','custom',NULL,'index.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',10,'inactive'),
(101,2,NULL,'Shop by Category','custom',NULL,'shop.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',20,'active'),
(112,2,NULL,'New arrivals','custom',NULL,'new-arrivals.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',22,'active'),
(113,2,NULL,'Best sellers','custom',NULL,'best-sellers.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',24,'active'),
(106,2,NULL,'About','custom',NULL,'about.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',30,'active'),
(107,2,NULL,'Blog','custom',NULL,'blog.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',40,'active'),
(108,2,NULL,'FAQs','custom',NULL,'faq.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',50,'active'),
(109,2,NULL,'Contact','custom',NULL,'contact.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',60,'inactive'),
(102,2,101,'All products','custom',NULL,'shop.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',10,'active'),
(103,2,101,'String & Curtain Lights','category',2,'shop.php?category=string-curtain-lights',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',20,'active'),
(104,2,101,'Diyas & LED Candles','category',3,'shop.php?category=diyas-led-candles',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',30,'active'),
(105,2,101,'Lamps & Projectors','category',4,'shop.php?category=lamps-projectors',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',40,'active'),
-- ---- Footer - Quick Links ----
(42,3,NULL,'Home','custom',NULL,'index.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',1,'active'),
(43,3,NULL,'Shop by Category','custom',NULL,'shop.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',2,'active'),
(44,3,NULL,'Best Sellers','custom',NULL,'best-sellers.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',3,'active'),
(45,3,NULL,'Deals','custom',NULL,'deals.php',NULL,'HOT','#F4511E',0,4,NULL,NULL,NULL,0,'all','all',4,'active'),
(46,3,NULL,'New Arrivals','custom',NULL,'new-arrivals.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',5,'active'),
(47,3,NULL,'Track Order','custom',NULL,'track-order.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',6,'active'),
-- ---- Footer - Customer Service ----
(48,4,NULL,'FAQs','custom',NULL,'faq.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',1,'active'),
(49,4,NULL,'Shipping Policy','custom',NULL,'page.php?slug=shipping-policy',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',2,'active'),
(50,4,NULL,'Returns','custom',NULL,'page.php?slug=return-policy',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',3,'active'),
(51,4,NULL,'Refunds','custom',NULL,'page.php?slug=refund-policy',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',4,'active'),
(52,4,NULL,'Warranty','custom',NULL,'page.php?slug=warranty-policy',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',5,'active'),
(53,4,NULL,'Contact Us','custom',NULL,'contact.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',6,'active'),
-- ---- Footer - About ----
(54,5,NULL,'About Us','custom',NULL,'page.php?slug=about-us',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',1,'active'),
(55,5,NULL,'Blog','custom',NULL,'blog.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',2,'active'),
(56,5,NULL,'Privacy Policy','custom',NULL,'page.php?slug=privacy-policy',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',3,'active'),
(57,5,NULL,'Terms & Conditions','custom',NULL,'page.php?slug=terms-conditions',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',4,'active');

-- ---------------------------------------------------------------------------
--  8. Footer builder
-- ---------------------------------------------------------------------------
INSERT INTO `footer_columns` (`id`,`title`,`column_type`,`content`,`sort_order`,`status`) VALUES
(1,'About ShopInnKart','about','<p>Every light we list is picked for festive and everyday decorating — Diwali, weddings, Navratri or a room that needs warming up. Orders are dispatched within one working day, delivery is free above ₹999, and you have 7 days to send anything back from your orders page.</p>',1,'inactive'),
(2,'Shop','links',NULL,2,'active'),
(3,'Help','links',NULL,3,'active'),
(4,'Company','links',NULL,4,'active'),
(5,'Contact','contact',NULL,5,'active'),
(11,'We accept','payment',NULL,6,'active');

INSERT INTO `footer_links` (`id`,`column_id`,`label`,`url`,`open_new_tab`,`sort_order`,`status`) VALUES
(1,2,'Home','index.php',0,1,'inactive'),
(2,2,'Shop all','shop.php',0,2,'active'),
(3,2,'Best sellers','best-sellers.php',0,3,'active'),
(4,2,'Deals','deals.php',0,4,'inactive'),
(5,2,'New arrivals','new-arrivals.php',0,5,'active'),
(6,3,'Track order','track-order.php',0,2,'active'),
(7,3,'FAQs','faq.php',0,1,'active'),
(8,3,'Shipping Policy','page.php?slug=shipping-policy',0,2,'inactive'),
(9,3,'Returns','page.php?slug=return-policy',0,3,'inactive'),
(10,3,'Refunds','page.php?slug=refund-policy',0,4,'inactive'),
(11,3,'Warranty','page.php?slug=warranty-policy',0,5,'inactive'),
(12,3,'Contact us','contact.php',0,6,'active'),
(13,4,'About us','page.php?slug=about-us',0,1,'active'),
(14,4,'Blog','blog.php',0,2,'active'),
(15,4,'Privacy Policy','page.php?slug=privacy-policy',0,3,'inactive'),
(16,4,'Terms & Conditions','page.php?slug=terms-conditions',0,4,'inactive');
-- The categories are reachable from the mega menu, the category bar and the
-- shop filters, so a fourth link column repeating them only widened the footer.

-- ---------------------------------------------------------------------------
--  9. Popups and pop-ins
-- ---------------------------------------------------------------------------
INSERT INTO `popups`
(`id`,`name`,`display_mode`,`popup_type`,`title`,`subtitle`,`content`,`image`,`mobile_image`,`video_url`,`coupon_code`,`product_id`,`button_text`,`button_url`,`position`,`size`,`bg_color`,`text_color`,`trigger_type`,`trigger_value`,`frequency`,`display_pages`,`device_visibility`,`auth_visibility`,`show_close`,`impressions`,`conversions`,`start_date`,`end_date`,`sort_order`,`status`) VALUES
(1,'Welcome Newsletter Coupon','popup','newsletter','Get 10% off your first order','Be first to hear when new festive collections land','<p>Join our list and we will email your code — 10% off any order over ₹599.</p><ul><li>Early access to festive sales</li><li>Price drop alerts on your wishlist</li><li>No spam — two emails a week at most</li></ul>','assets/images/popups/welcome-coupon.svg','assets/images/popups/welcome-coupon-mobile.svg',NULL,'',NULL,'','','center','md','#0F2143','#FFFFFF','immediate',0,'always','home,shop','all','guest',1,0,0,DATE_SUB(NOW(), INTERVAL 10 DAY),DATE_ADD(NOW(), INTERVAL 90 DAY),1,'inactive'),
-- Off until a DONTGO10 coupon exists - see Admin > Marketing > Coupons.
(2,'Exit Intent Coupon','popup','coupon','WAIT - HERE IS 10% OFF','Leaving without your cart? Use this before you go','<p>Apply coupon <strong>DONTGO10</strong> at checkout for a flat 10% off, up to ₹3,000, on everything in your cart. Valid for the next 24 hours.</p>','assets/images/popups/exit-offer.svg','assets/images/popups/exit-offer-mobile.svg',NULL,'DONTGO10',NULL,'APPLY & CONTINUE','cart.php','center','md','#F4511E','#FFFFFF','exit',0,'daily','shop,product,cart','desktop','all',1,0,0,DATE_SUB(NOW(), INTERVAL 5 DAY),DATE_ADD(NOW(), INTERVAL 60 DAY),2,'inactive'),
(3,'Free Shipping Reminder','popin','cart_reminder','You are close to FREE shipping','Add ₹999 worth of items and we will drop the delivery fee','<p>Prepaid orders above ₹999 ship free anywhere in India, usually within 2 to 4 working days.</p>','assets/images/popups/free-shipping.svg','assets/images/popups/free-shipping.svg',NULL,NULL,NULL,'KEEP SHOPPING','shop.php','bottom-right','sm','#0F2143','#FFFFFF','immediate',0,'always','shop,product,cart','all','all',1,0,0,DATE_SUB(NOW(), INTERVAL 7 DAY),DATE_ADD(NOW(), INTERVAL 120 DAY),3,'inactive'),
(4,'Limited Stock Alert','popin','stock','Only a few units left','This model has been selling fast in the last 24 hours','<p>Fewer than 15 pieces remain in the warehouse at this price. Orders placed before 6 PM ship the same day.</p>','assets/images/popups/limited-stock.svg','assets/images/popups/limited-stock.svg',NULL,NULL,10,'VIEW PRODUCT','product.php?slug=toy-imagine-galaxy-projector-lamp-astronaut-star-night-light-timer-4-modes','bottom-left','sm','#F4511E','#FFFFFF','timed',20,'session','product','all','all',1,0,0,DATE_SUB(NOW(), INTERVAL 3 DAY),DATE_ADD(NOW(), INTERVAL 45 DAY),4,'inactive');

-- ---------------------------------------------------------------------------
--  10. seo_settings - already seeded in schema.sql, intentionally not touched.
-- ---------------------------------------------------------------------------


-- ===========================================================================
--  ShopInnKart - SEED PART C : COMMERCE + CONTENT
--  Deals, flash sales, coupons, shipping, pincodes, payment methods,
--  bank offers, CMS pages, FAQs, blog, notification templates and the
--  full demo customer / order / review data set.
--
--  All dates are relative to import time (NOW()) so the demo always
--  looks live. Money is INR.
--
--  TAX NOTE: settings.tax_inclusive = '1' - every catalogue price already
--  includes 18% GST, so nothing extra is added at checkout. Order maths is
--  therefore  total = subtotal - discount + shipping + tax  with tax = 0.00.
-- ===========================================================================

-- ---------------------------------------------------------------------------
--  SECTION 10 : DEALS
--  1 = live Deal of the Day, 2 = scheduled (upcoming), 3 = finished (expired)
-- ---------------------------------------------------------------------------
INSERT INTO `deals`
(`id`,`title`,`subtitle`,`description`,`product_id`,`discount_type`,`discount_value`,`stock_limit`,`stock_sold`,`button_text`,`button_url`,`start_time`,`end_time`,`status`) VALUES
(1,'Deal of the Day: The Purple Tree Diya Curtain Light','Flat 12% off - ends tonight','Twelve hanging diyas on a 2.5 m curtain with eight lighting modes, at its lowest price of the season. Limited to 40 units.',7,'percentage',12.00,40,17,'Grab This Deal','product.php?slug=the-purple-tree-diya-curtain-light-12-hanging-diyas-138-led-8-modes-2-5-m',DATE_SUB(NOW(), INTERVAL 2 HOUR),DATE_ADD(NOW(), INTERVAL 22 HOUR),'active'),
(2,'Weekend Curtain Light Fest','Star, leaf and fairy curtain lights for the weekend','Four hand-picked curtain lights at their lowest price of the season. Doors open this weekend.',1,'percentage',12.00,60,0,'Set A Reminder','shop.php?category=string-curtain-lights',DATE_ADD(NOW(), INTERVAL 2 DAY),DATE_ADD(NOW(), INTERVAL 5 DAY),'active'),
(3,'Lamps & Projectors Clearance','Table lamps and projector lamps - sale ended','A four day clearance on crystal table lamps and galaxy projector lamps. Stock sold out at 118 of 120 units.',11,'fixed',30.00,120,118,'Shop The Deal','shop.php?category=lamps-projectors',DATE_SUB(NOW(), INTERVAL 12 DAY),DATE_SUB(NOW(), INTERVAL 8 DAY),'inactive');

INSERT INTO `deal_products` (`deal_id`,`product_id`,`deal_price`,`sort_order`) VALUES
-- live deal of the day
(1,7,349.00,1),
(1,6,219.00,2),
(1,5,129.00,3),
-- upcoming weekend curtain light fest
(2,1,249.00,1),
(2,2,259.00,2),
(2,8,349.00,3),
(2,4,219.00,4),
-- expired lamps and projectors clearance
(3,11,229.00,1),
(3,10,319.00,2);

-- ---------------------------------------------------------------------------
--  FLASH SALES
--  1 = running right now (started 1 hour ago, runs for 2 more days)
--  2 = finished last week, kept for the admin history list
-- ---------------------------------------------------------------------------
INSERT INTO `flash_sales`
(`id`,`name`,`subtitle`,`discount_type`,`discount_value`,`stock_limit`,`start_time`,`end_time`,`status`) VALUES
(1,'Midnight Festive Flash Sale','Five festive favourites at flash prices - while stocks last','percentage',10.00,50,DATE_SUB(NOW(), INTERVAL 1 HOUR),DATE_ADD(NOW(), INTERVAL 2 DAY),'active'),
(2,'Weekend Diya Flash','Diyas and tea lights - sale closed','percentage',12.00,40,DATE_SUB(NOW(), INTERVAL 9 DAY),DATE_SUB(NOW(), INTERVAL 7 DAY),'inactive');

INSERT INTO `flash_sale_products` (`flash_sale_id`,`product_id`,`sale_price`,`stock_limit`,`stock_sold`,`sort_order`) VALUES
-- live flash sale: every price is below the current catalogue sale price
(1,3,349.00,40,23,1),
(1,9,349.00,30,14,2),
(1,10,299.00,60,31,3),
(1,11,229.00,45,27,4),
(1,6,219.00,35,12,5),
-- finished flash sale
(2,5,129.00,25,25,1),
(2,6,219.00,40,38,2),
(2,7,349.00,100,100,3);

-- ---------------------------------------------------------------------------
--  SECTION 7 : COUPONS
-- ---------------------------------------------------------------------------
INSERT INTO `coupons`
(`id`,`code`,`description`,`type`,`value`,`minimum_order`,`maximum_discount`,`start_date`,`end_date`,`usage_limit`,`per_user_limit`,`used_count`,`status`) VALUES
(1,'WELCOME10',NULL,'percentage',10.00,599.00,NULL,DATE_SUB(NOW(), INTERVAL 45 DAY),DATE_ADD(NOW(), INTERVAL 60 DAY),NULL,1,68,'active'),
(2,'SAVE500','₹150 off orders over ₹1,299','fixed',150.00,1299.00,NULL,DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_ADD(NOW(), INTERVAL 45 DAY),500,2,142,'active'),
(3,'FREESHIP','Free delivery on orders over ₹499','free_shipping',0.00,499.00,NULL,DATE_SUB(NOW(), INTERVAL 60 DAY),DATE_ADD(NOW(), INTERVAL 90 DAY),NULL,5,391,'active'),
(4,'FIRST15','15% off orders over ₹899','percentage',15.00,899.00,200.00,DATE_SUB(NOW(), INTERVAL 20 DAY),DATE_ADD(NOW(), INTERVAL 70 DAY),2000,1,214,'active'),
-- expired: kept so the admin list shows an ended offer
(5,'FESTIVE25','25% off festive orders over ₹1,499','percentage',25.00,1499.00,400.00,DATE_SUB(NOW(), INTERVAL 40 DAY),DATE_SUB(NOW(), INTERVAL 5 DAY),1500,1,1487,'active');

INSERT INTO `coupon_restrictions` (`coupon_id`,`restriction_type`,`reference_id`) VALUES
-- FIRST15 is only valid when the customer has no previous delivered order
(4,'first_order',NULL);

-- ---------------------------------------------------------------------------
--  SECTION 8 : SHIPPING METHODS
-- ---------------------------------------------------------------------------
INSERT INTO `shipping_methods`
(`id`,`code`,`name`,`description`,`cost`,`free_above`,`min_days`,`max_days`,`sort_order`,`status`) VALUES
(1,'standard','Standard Delivery','Delivered by our surface partners. Free on every order above ₹999.',79.00,999.00,3,7,1,'active'),
(2,'express','Express Delivery','Air shipped and prioritised at the hub. Free on orders above ₹4,999.',199.00,4999.00,1,3,2,'active');

-- ---------------------------------------------------------------------------
--  PINCODE SERVICEABILITY
--  37 serviceable metro / tier-1 codes + 3 remote codes we do not ship to.
-- ---------------------------------------------------------------------------
INSERT INTO `pincodes` (`id`,`pincode`,`city`,`state`,`is_serviceable`,`cod_available`,`delivery_days`) VALUES
-- Bengaluru
(1,'560001','Bengaluru','Karnataka',1,1,2),
(2,'560034','Bengaluru','Karnataka',1,1,2),
(3,'560066','Bengaluru','Karnataka',1,1,2),
(4,'560103','Bengaluru','Karnataka',1,1,2),
-- Mumbai
(5,'400001','Mumbai','Maharashtra',1,1,2),
(6,'400050','Mumbai','Maharashtra',1,1,2),
(7,'400058','Mumbai','Maharashtra',1,1,3),
(8,'400076','Mumbai','Maharashtra',1,1,3),
-- Delhi
(9,'110001','New Delhi','Delhi',1,1,2),
(10,'110016','New Delhi','Delhi',1,1,2),
(11,'110019','New Delhi','Delhi',1,1,3),
(12,'110092','Delhi','Delhi',1,1,3),
-- Chennai
(13,'600001','Chennai','Tamil Nadu',1,1,3),
(14,'600017','Chennai','Tamil Nadu',1,1,3),
(15,'600042','Chennai','Tamil Nadu',1,1,3),
(16,'600096','Chennai','Tamil Nadu',1,0,4),
-- Hyderabad
(17,'500001','Hyderabad','Telangana',1,1,3),
(18,'500016','Hyderabad','Telangana',1,1,3),
(19,'500032','Hyderabad','Telangana',1,1,3),
(20,'500081','Hyderabad','Telangana',1,1,3),
-- Pune
(21,'411001','Pune','Maharashtra',1,1,3),
(22,'411014','Pune','Maharashtra',1,1,3),
(23,'411045','Pune','Maharashtra',1,1,4),
(24,'411057','Pune','Maharashtra',1,1,4),
-- Kolkata
(25,'700001','Kolkata','West Bengal',1,1,4),
(26,'700019','Kolkata','West Bengal',1,1,4),
(27,'700064','Kolkata','West Bengal',1,1,4),
(28,'700091','Kolkata','West Bengal',1,0,4),
-- Ahmedabad
(29,'380001','Ahmedabad','Gujarat',1,1,4),
(30,'380009','Ahmedabad','Gujarat',1,1,4),
(31,'380015','Ahmedabad','Gujarat',1,1,5),
-- Jaipur
(32,'302001','Jaipur','Rajasthan',1,1,5),
(33,'302017','Jaipur','Rajasthan',1,1,5),
(34,'302020','Jaipur','Rajasthan',1,0,5),
-- Lucknow
(35,'226001','Lucknow','Uttar Pradesh',1,1,5),
(36,'226010','Lucknow','Uttar Pradesh',1,1,5),
(37,'226024','Lucknow','Uttar Pradesh',1,0,6),
-- Remote locations we currently do not deliver to
(38,'194101','Leh','Ladakh',0,0,7),
(39,'682555','Kavaratti','Lakshadweep',0,0,7),
(40,'744101','Port Blair','Andaman and Nicobar Islands',0,0,7);

-- ---------------------------------------------------------------------------
--  SECTION 18 : PAYMENT METHODS
--  Only Cash on Delivery is live. The four online gateways are seeded
--  inactive with empty credential shells so the gateway architecture,
--  the admin settings screen and the checkout selector all have data
--  to drive without exposing any real key.
-- ---------------------------------------------------------------------------
INSERT INTO `payment_methods`
(`id`,`code`,`name`,`description`,`instructions`,`logo`,`is_online`,`extra_charge`,`discount_percent`,`min_amount`,`max_amount`,`config`,`sort_order`,`status`) VALUES
(1,'cod','Cash on Delivery','Pay the courier in cash or by UPI when your order arrives.','A handling fee of ₹49 applies to orders below ₹999 and is waived above that. Please keep exact change ready. Cash on Delivery is not available on orders above ₹50,000 or on non serviceable pin codes.','assets/images/placeholders/payment-cod.svg',0,49.00,0.00,0.00,50000.00,NULL,1,'active'),
(2,'razorpay','Razorpay','UPI, cards, net banking and wallets through Razorpay.','Configure the key id and key secret in Admin, Settings, Payments before enabling this gateway.','assets/images/placeholders/payment-online.svg',1,0.00,0.00,1.00,NULL,'{"key_id":"","key_secret":"","webhook_secret":"","mode":"test"}',2,'inactive'),
(3,'cashfree','Cashfree Payments','UPI, cards and pay later options through Cashfree.','Configure the app id and secret key in Admin, Settings, Payments before enabling this gateway.','assets/images/placeholders/payment-online.svg',1,0.00,0.00,1.00,NULL,'{"app_id":"","secret_key":"","mode":"test"}',3,'inactive'),
(4,'stripe','Stripe','International cards and wallets through Stripe.','Configure the publishable key and secret key in Admin, Settings, Payments before enabling this gateway.','assets/images/placeholders/payment-online.svg',1,0.00,0.00,1.00,NULL,'{"publishable_key":"","secret_key":"","webhook_secret":"","mode":"test"}',4,'inactive'),
(5,'payu','PayU','Cards, UPI and EMI through PayU India.','Configure the merchant key and salt in Admin, Settings, Payments before enabling this gateway.','assets/images/placeholders/payment-online.svg',1,0.00,0.00,1.00,NULL,'{"merchant_key":"","merchant_salt":"","mode":"test"}',5,'inactive');

-- ---------------------------------------------------------------------------
--  BANK AND PAYMENT OFFERS (shown on product and checkout pages)
-- ---------------------------------------------------------------------------
INSERT INTO `bank_offers`
(`id`,`title`,`description`,`offer_type`,`logo`,`code`,`min_amount`,`start_date`,`end_date`,`sort_order`,`status`) VALUES
(1,'10% instant discount on HDFC Bank Credit Cards','Get 10% instant discount up to ₹3,000 on HDFC Bank Credit Cards and Credit Card EMI transactions. Minimum order value ₹29,999. Offer applies once per card per month.','card','assets/images/placeholders/offer-card.svg','HDFC10',29999.00,DATE_SUB(NOW(), INTERVAL 10 DAY),DATE_ADD(NOW(), INTERVAL 50 DAY),1,'inactive'),
(2,'5% cashback on every UPI payment','Pay using any UPI app and get 5% cashback up to ₹500 credited to the same UPI handle within 72 hours. Minimum order value ₹1,999. Valid twice per user per month.','upi','assets/images/placeholders/offer-upi.svg','UPI5',1999.00,DATE_SUB(NOW(), INTERVAL 15 DAY),DATE_ADD(NOW(), INTERVAL 60 DAY),2,'inactive'),
(3,'No-cost EMI from 3 to 12 months','Convert any order above ₹9,999 into no-cost EMI on leading bank credit cards and Debit Card EMI. Interest is discounted upfront and shown before you confirm the order.','emi','assets/images/placeholders/offer-emi.svg',NULL,9999.00,DATE_SUB(NOW(), INTERVAL 20 DAY),DATE_ADD(NOW(), INTERVAL 90 DAY),3,'inactive'),
(4,'₹250 wallet cashback on Paytm and Mobikwik','Get a flat ₹250 cashback in your wallet on orders above ₹4,999 paid through Paytm Wallet or Mobikwik. Cashback is credited within 24 hours of delivery.','wallet','assets/images/placeholders/offer-wallet.svg','WALLET250',4999.00,DATE_SUB(NOW(), INTERVAL 8 DAY),DATE_ADD(NOW(), INTERVAL 40 DAY),4,'inactive');

-- ===========================================================================
--  SECTION 13 : CMS PAGES (13 system pages, real copy)
--
--  Every store-specific fact in this copy is a token, never a literal. The
--  renderer (content_apply_tokens() in includes/content-functions.php) fills
--  {{store_email}}, {{gst_number}} and friends from the `settings` table at
--  request time, and a {{#key}}...{{/key}} section is dropped whole when the
--  administrator has not configured that key. That is deliberate: a policy
--  must never assert a registration, an address or a contact channel that the
--  store owner has not actually supplied.
--
--  The copy below is the POST-REWRITE wording. database/seeds/festive-policy-copy.php
--  patches an already-live store from the old electronics framing to exactly
--  this text; a fresh install gets it straight from here and does not need to
--  run that seed at all. The consequence to know about: install.php and a
--  plain `mysql < schema.sql` therefore apply the rewritten legal wording with
--  no owner review step in front of it. The store owner must read and ratify
--  it here, before the next install or export - not only before the live seed
--  run. Check against the seed's edit list if you need the before/after.
-- ===========================================================================
INSERT INTO `pages` (`id`,`title`,`slug`,`content`,`banner_image`,`is_system`,`show_in_footer`,`sort_order`,`status`,`meta_title`,`meta_description`) VALUES
(1,'About Us','about-us','<p>{{store_name}} is an online festive and decorative lighting store built for Indian homes. Buying lights on the internet should feel as informed as buying from a shop where you can see them lit — so we describe what a light actually does, not just what the box says.</p>

<h2>Who We Are</h2>
<p>A small team that sells one category and tries to know it properly. Every product on this site is lighting: nothing is listed because it happened to be available.</p>

<h2>What We Sell</h2>
<p>String and curtain lights, star and snowflake curtains, fairy and rice lights, LED diyas and tea light candles, crystal table lamps and galaxy projector lamps. Most of the range sits between ₹149 and ₹399, because festive lighting should not be a considered purchase.</p>

<h2>How We Work</h2>
<p>Listings carry the numbers that decide whether a light suits your room: LED count, total length, number of modes, power source (USB or mains), controller type, and whether it is rated for sheltered outdoor use. Where a photograph flatters a warm white that is really cool white, we say so.</p>

<h2>Our Fulfilment Network</h2>
<p>Orders are dispatched within one working day. Glass and acrylic lamps ship in double-corrugated cartons with the fragile items suspended away from the walls of the box, because a cracked diffuser is the most common way a lamp arrives dead.</p>

<h2>Service After The Sale</h2>
<p>If a strand arrives with a dead section or a controller fails, raise a request from your orders page within 7 days and we will arrange it. Delivery is free on orders above ₹999, and Cash on Delivery is available — you pay the courier when the order reaches you.</p>

<h2>Lights, Batteries and Waste</h2>
<p>LED strands last far longer than filament ones, but they do eventually fail, and the button cells in remotes and tea lights should not go into household waste. Indian cities have designated e-waste and battery collection points — please use them rather than the bin. If a product of ours has reached the end of its life and you are unsure where it should go, write to us and we will point you at the nearest facility.</p>

<h2>Talk To Us</h2>
<p>Email <a href="mailto:support@shopinnkart.com">support@shopinnkart.com</a> or call <a href="tel:+919876543210">+91 98765 43210</a>, Monday to Saturday, 9:00 AM to 8:00 PM IST. A real person reads every message.</p>','assets/images/banners/page-about.svg',1,1,1,'active','About {{store_name}} | Festive & Decorative Lighting in India','{{store_name}} sells festive and decorative lighting across India — string and curtain lights, diyas, LED candles, lamps and projectors, with GST invoices and fast delivery.'),
(2,'Contact Us','contact-us','<h2>We Are Here To Help</h2>
<p>Whether you are choosing between two curtain lights, tracking a parcel, or raising a warranty claim, our support team can help. Every ticket stays with one owner until it is closed.</p>

<h2>Customer Support</h2>
<ul>
{{#store_email}}<li><strong>Email:</strong> <a href="mailto:{{store_email}}">{{store_email}}</a></li>{{/store_email}}
{{#store_phone}}<li><strong>Phone:</strong> {{store_phone}}</li>{{/store_phone}}
{{#business_hours}}<li><strong>Hours:</strong> {{business_hours}}</li>{{/business_hours}}
<li><strong>Contact form:</strong> the form on this page reaches the same desk and creates a ticket automatically</li>
</ul>
{{#store_address}}
<h2>Address</h2>
<p>{{store_address}}</p>{{/store_address}}{{#gst_number}}
<p><strong>GSTIN:</strong> {{gst_number}}</p>{{/gst_number}}

<h2>Before You Write To Us</h2>
<p>You can resolve most requests yourself and save a round trip. Sign in and open <a href="{{url:orders}}">My Orders</a> to track a shipment, download a GST invoice, cancel an order inside the {{cancel_window_hours}} hour window, or start a return inside the {{return_window_days}} day window. The <a href="{{url:track-order}}">Track Order</a> page works without signing in if you have the order number and the registered phone number.</p>

<h2>What To Include In Your Message</h2>
<ul>
<li>Your order number, which begins with {{order_prefix}}</li>
<li>The registered email address or phone number on the order</li>
<li>A short description of what happened and what you would like us to do</li>
<li>Photographs of the product and the outer packaging for any damage or missing item claim</li>
</ul>

<h2>Escalation And Grievance Redressal</h2>
<p>If a reply has not reached you within two business days, or the resolution offered does not work for you, reply on the same ticket and ask for it to be escalated. Under the Consumer Protection (E-Commerce) Rules 2020 an e-commerce entity must appoint a grievance officer and acknowledge a complaint within forty eight hours.{{#store_email}} Send an escalation to <a href="mailto:{{store_email}}">{{store_email}}</a> with the subject line Grievance Officer and your existing ticket number.{{/store_email}} Unresolved complaints may also be filed on the National Consumer Helpline portal at <a href="https://consumerhelpline.gov.in" rel="nofollow noopener">consumerhelpline.gov.in</a>.</p>

<h2>Business, Bulk And GST Input Orders</h2>
<p>For larger quantities, GST input invoicing in your company name, deployment support or a formal quotation, use the contact form and choose the business enquiry subject. Include the quantity, the model, your delivery city and your GSTIN so the quote carries the correct tax treatment.</p>','assets/images/banners/page-contact.svg',1,1,2,'active','Contact {{store_name}} Customer Support','Reach {{store_name}} support by phone, email or the contact form. Support hours, escalation path, grievance redressal and the desk for bulk and GST input orders.'),
(3,'Privacy Policy','privacy-policy','<p class="sik-doc__stamp">Last updated {{last_updated}}</p>
<h2>1. Introduction</h2>
<p>This policy explains what personal information {{store_name}} collects when you use this website, why we collect it, how long we keep it and the choices you have. It is written to align with the Information Technology Act 2000 and the Reasonable Security Practices Rules made under it, the Digital Personal Data Protection Act 2023, and the disclosure duties placed on e-commerce entities by the Consumer Protection (E-Commerce) Rules 2020. By using this website you accept the practices described here.</p>

<h2>2. Who Is Responsible For Your Data</h2>
<p>{{store_name}} operates this website and decides why and how your personal data is processed, which makes us the data fiduciary for the purposes of the Digital Personal Data Protection Act 2023.{{#store_address}} Our address is {{store_address}}.{{/store_address}}{{#store_email}} Privacy questions and data principal requests can be sent to <a href="mailto:{{store_email}}">{{store_email}}</a>.{{/store_email}}</p>

<h2>3. What We Collect And Why</h2>
<p>We collect only what an order, an account or a legal obligation actually requires. Nothing in the table below is sold to anyone.</p>
<table>
<thead><tr><th>Category</th><th>Examples</th><th>Why we need it</th><th>How long we keep it</th></tr></thead>
<tbody>
<tr><td>Account data</td><td>Name, email address, mobile number, hashed password</td><td>To create your account, sign you in and recover access</td><td>Until you ask us to delete the account</td></tr>
<tr><td>Order and delivery data</td><td>Shipping and billing address, pin code, order contents, order status</td><td>To fulfil the order, hand it to a courier and handle returns</td><td>For the retention period that tax and company law require for sales records</td></tr>
<tr><td>Tax and invoice data</td><td>GST invoice, invoice number, GSTIN you supply for an input claim</td><td>To issue a valid tax invoice and file returns</td><td>As long as GST and income tax record keeping rules require</td></tr>
<tr><td>Payment data</td><td>Payment method, transaction reference, last four digits and status returned by the gateway</td><td>To confirm payment, reconcile and refund</td><td>For the life of the accounting record</td></tr>
<tr><td>Support data</td><td>Tickets, call notes, photographs you send for a damage claim</td><td>To investigate and resolve your complaint</td><td>Up to three years after the ticket is closed</td></tr>
<tr><td>Device and usage data</td><td>IP address, browser and device type, pages viewed, cookie identifiers</td><td>Security, fraud prevention, and keeping the cart and session working</td><td>Short lived, as set out in the Cookie Policy</td></tr>
<tr><td>Marketing preferences</td><td>Newsletter opt in, notification settings</td><td>To send only what you asked for</td><td>Until you withdraw consent</td></tr>
</tbody>
</table>

<h2>4. What We Never Collect</h2>
<ul>
<li>We never see or store your full card number, CVV, card PIN, UPI PIN, net banking password or the one time password sent by your bank. Those are entered on the payment gateway, not on this site.</li>
<li>We do not ask for Aadhaar, PAN or any government identifier to place a normal retail order.</li>
<li>No member of our team will ever call you and ask for an OTP, a PIN or a remote access application. Treat any such call as fraud and report it.</li>
</ul>

<h2>5. Legal Basis And Consent</h2>
<p>We process order, delivery, invoice and support data because it is necessary to perform the contract you entered into when you placed the order, and because tax law requires the record. We process marketing data only with your consent, which you may withdraw at any time from your account preferences or by using the unsubscribe link in any marketing message. Withdrawing marketing consent does not affect transactional messages such as a dispatch alert, which are part of the order.</p>

<h2>6. Who We Share Data With</h2>
<ul>
<li><strong>Logistics partners</strong> receive your name, delivery address and phone number so the parcel can be delivered.</li>
<li><strong>Payment gateways</strong> receive the amount, the order reference and the contact details needed to process and refund the transaction.</li>
<li><strong>Brands</strong> receive your invoice and product details only when you ask us to open a warranty claim on your behalf.</li>
<li><strong>Technology and communication providers</strong> that send transactional email and SMS on our behalf, under contract and only for that purpose.</li>
<li><strong>Government authorities</strong> where a law, a court order or a lawful investigation requires disclosure.</li>
</ul>
<p>We do not sell personal data, and we do not share it with data brokers or advertisers for their own purposes.</p>

<h2>7. Cookies</h2>
<p>Cookies and similar storage are described in full in our <a href="{{page:cookie-policy}}">Cookie Policy</a>, including which categories are strictly necessary and how to refuse the rest in your browser.</p>

<h2>8. Security</h2>
<p>The site is served over HTTPS. Passwords are stored only as a salted one way hash and are never recoverable in plain text. Access to order and customer records is restricted by role, administrative sessions time out, and every state changing form is protected against cross site request forgery. No system can be described as unbreakable, so we also keep the amount of data we hold to the minimum the order actually needs.</p>

<h2>9. Your Rights</h2>
<ol>
<li><strong>Access.</strong> Ask for a summary of the personal data we hold about you and who it has been shared with.</li>
<li><strong>Correction.</strong> Update your name, email, phone or addresses at any time from your account, or ask us to correct anything you cannot edit yourself.</li>
<li><strong>Erasure.</strong> Ask us to delete your account. We must still retain invoices and order records for the period tax law prescribes, so those are retained and the rest is removed.</li>
<li><strong>Withdraw consent.</strong> Turn off marketing at any time without losing your account.</li>
<li><strong>Grievance redressal.</strong> Raise a complaint about how your data was handled and receive a reasoned reply.</li>
<li><strong>Nominate.</strong> Nominate another person to exercise these rights on your behalf in the event of death or incapacity.</li>
</ol>
{{#store_email}}<p>To exercise any of these rights, write to <a href="mailto:{{store_email}}">{{store_email}}</a> from the email address registered on the account, so that we can verify the request without asking you for further identity documents.</p>{{/store_email}}

<h2>10. Children</h2>
<p>This website is not directed at children. We do not knowingly create accounts for anyone under eighteen, and a minor should transact only through a parent or guardian. If you believe a child has created an account, tell us and we will remove it.</p>

<h2>11. Changes To This Policy</h2>
<p>When this policy changes, the revised version is published on this page and the last updated date above changes with it. Material changes that affect how we use your data will also be notified in your account or by email where you have given us one.</p>

<h2>Frequently Asked Questions</h2>
<h3>Do you store my card details?</h3>
<p>No. Card data is captured by the payment gateway on a PCI DSS compliant page. We receive only a transaction reference and a status. Any card tokenisation for future payments is performed by the network and the gateway, not by us.</p>
<h3>Can I use the site without an account?</h3>
<p>Yes, guest checkout is available. We still need a name, address, phone number and email to deliver the order and send the invoice.</p>
<h3>How do I stop marketing email without closing my account?</h3>
<p>Use the unsubscribe link in any marketing email, or turn off marketing in your account preferences. Order and delivery notifications continue because they are part of the contract.</p>
<h3>Will you share my number with the courier?</h3>
<p>Yes. The delivery partner needs your phone number to call you at the door. That is the only purpose it is shared for.</p>
<h3>What happens to my data if I ask for my account to be deleted?</h3>
<p>Your profile, addresses, wishlist and preferences are removed. Invoices and the order ledger are retained because GST and income tax law require a seller to keep them, and they are then used for nothing else.</p>','assets/images/banners/page-policy.svg',1,1,3,'active','Privacy Policy | {{store_name}}','How {{store_name}} collects, uses, shares and retains your personal data, your rights under the Digital Personal Data Protection Act 2023, and how to raise a grievance.'),
(4,'Terms and Conditions','terms-conditions','<p class="sik-doc__stamp">Last updated {{last_updated}}</p>
<h2>1. Acceptance Of These Terms</h2>
<p>These terms govern your use of this website and every order you place on it. By browsing, creating an account or placing an order you agree to them. If you do not agree, please do not use the site. These terms are published as an electronic record under the Information Technology Act 2000 and do not require a physical signature.</p>

<h2>2. Who May Buy</h2>
<ul>
<li>You must be at least eighteen years old and able to enter into a binding contract under the Indian Contract Act 1872.</li>
<li>A minor may transact only through a parent or legal guardian, who accepts responsibility for the order.</li>
<li>We deliver only to addresses inside India, and only to pin codes shown as serviceable at checkout.</li>
<li>We may decline or cancel an order where we reasonably suspect fraud, resale abuse, a pricing error, or a breach of these terms.</li>
</ul>

<h2>3. Your Account</h2>
<p>You are responsible for the accuracy of the details on your account and for keeping your password confidential. Tell us immediately if you believe your account has been used without your permission. We may suspend an account that is being used for fraud, for abusing coupons or returns, or for scraping the catalogue.</p>

<h2>4. Product Listings, Pricing And GST</h2>
<ol>
<li>Every price shown on a product page is in Indian Rupees and is inclusive of GST at the rate applicable to that category, currently {{tax_rate}} per cent for most lighting products.</li>
<li>Delivery charges, cash on delivery charges, coupon savings and bank offers are shown separately in the order summary before you pay. Nothing is added after payment.</li>
<li>A tax invoice is issued for every order and is available for download from <a href="{{url:orders}}">My Orders</a>. Keep it, because a warranty claim needs it as proof of the purchase date.{{#gst_number}} Our GSTIN is {{gst_number}}.{{/gst_number}}</li>
<li>To claim GST input credit, enter your business name and GSTIN before you place the order. A GSTIN cannot be added to an invoice after it has been generated.</li>
<li>Specifications, images and box contents are published from manufacturer documentation. Where a brand changes a specification or the retail carton without notice, the manufacturer information prevails and we correct the listing.</li>
</ol>

<h2>5. How An Order Is Formed</h2>
<p>Adding an item to the cart and completing checkout is an offer to buy. Our confirmation email acknowledges that we received the offer. The contract is formed when we dispatch the item, and only for the items actually dispatched. If an item is unavailable, mispriced by an obvious error, or cannot be delivered to your pin code, we may cancel that line and refund it in full. Server side price and stock are authoritative at all times, so an amount shown by a stale browser tab or an altered page has no effect on the order.</p>

<h2>6. Payment</h2>
<p>Accepted payment methods, charges and refund routes are set out in the <a href="{{page:payment-policy}}">Payment Policy</a>. Payment must be completed before dispatch, except for orders placed with cash on delivery where the amount is collected at the door.</p>

<h2>7. Delivery</h2>
<p>Dispatch timelines, charges and packaging are covered by the <a href="{{page:shipping-policy}}">Shipping Policy</a>, and what happens at your door is covered by the <a href="{{page:delivery-policy}}">Delivery Policy</a>. Delivery estimates are working day estimates, not promises of a fixed hour, and they exclude delays caused by weather, strikes, restricted areas or an incorrect address.</p>

<h2>8. Cancellation, Returns, Refunds And Warranty</h2>
<ul>
<li><a href="{{page:cancellation-policy}}">Cancellation Policy</a> - cancelling before dispatch and what happens after.</li>
<li><a href="{{page:return-policy}}">Return Policy</a> - the {{return_window_days}} day window, category exceptions and pickup.</li>
<li><a href="{{page:refund-policy}}">Refund Policy</a> - refund routes and timelines by payment method.</li>
<li><a href="{{page:warranty-policy}}">Warranty Policy</a> - warranty cover, dead on arrival and how to raise a claim.</li>
</ul>

<h2>9. Disclosures Required Of An E-Commerce Entity</h2>
<p>The Consumer Protection (E-Commerce) Rules 2020 require certain information to be displayed clearly. This table shows where each item is published on this site.</p>
<table>
<thead><tr><th>Required disclosure</th><th>Where it is published</th></tr></thead>
<tbody>
<tr><td>Legal name and address of the e-commerce entity</td><td>Footer and <a href="{{url:contact}}">Contact</a> page, from the store details configured by the administrator</td></tr>
<tr><td>Customer care contact details</td><td><a href="{{url:contact}}">Contact</a> page and the site footer</td></tr>
<tr><td>Grievance officer and grievance redressal mechanism</td><td>Section 14 of this page and the <a href="{{page:privacy-policy}}">Privacy Policy</a></td></tr>
<tr><td>Total price with a break up of all charges</td><td>Cart and checkout order summary, before payment</td></tr>
<tr><td>Country of origin and seller details where required</td><td>Product page specification block</td></tr>
<tr><td>Return, refund, exchange, warranty and delivery terms</td><td>The policy pages linked in section 8</td></tr>
<tr><td>Method of payment and security of payment</td><td><a href="{{page:payment-policy}}">Payment Policy</a></td></tr>
<tr><td>Ticket number for every complaint</td><td>Issued when a support ticket is created</td></tr>
</tbody>
</table>

<h2>10. Reviews And Anything You Post</h2>
<p>Reviews must reflect genuine experience of a product you bought. Do not post another person contact details, unlawful content, abuse, or content you do not own. We may decline or remove a review that breaches this, and we do not remove a review merely because it is negative. By posting you grant us a non exclusive licence to display that content on the site.</p>

<h2>11. Intellectual Property</h2>
<p>The site layout, text, graphics and code are owned by us or licensed to us. Brand names, logos and product images belong to their respective owners and appear here to identify the products we sell. You may not copy the catalogue, scrape the site, or use any mark in a way that suggests endorsement.</p>

<h2>12. What We Are Not Liable For</h2>
<ul>
<li>Indirect or consequential loss, loss of profit, or loss of business arising from a delayed or defective order.</li>
<li>Third party websites linked from this site, including brand pages.</li>
<li>Delay or failure caused by an event beyond reasonable control, including natural disaster, civil unrest, strike, network failure or a change in law.</li>
</ul>
<p>Nothing in these terms limits any right you have under the Consumer Protection Act 2019 or any other law that cannot be excluded by agreement. To the extent permitted by law, our aggregate liability for any claim connected to an order is limited to the amount you paid for that order.</p>

<h2>13. Governing Law</h2>
<p>These terms are governed by the laws of India, and any dispute is subject to the jurisdiction of the competent courts in India. Consumer complaints may also be taken to the consumer commission having jurisdiction where you reside, as the Consumer Protection Act 2019 permits.</p>

<h2>14. Grievance Redressal</h2>
<p>Raise a complaint through the <a href="{{url:contact}}">contact page</a> and you will receive a ticket number. If the reply does not resolve the matter, ask for escalation on the same ticket.{{#store_email}} Escalations may also be sent to <a href="mailto:{{store_email}}">{{store_email}}</a> with the subject line Grievance Officer.{{/store_email}} We aim to acknowledge every complaint within forty eight hours and to resolve it within one month, in line with the Consumer Protection (E-Commerce) Rules 2020.</p>

<h2>15. Changes To These Terms</h2>
<p>We may update these terms. The version published on this page at the time you place an order is the version that governs that order, so the last updated date above matters. Continuing to use the site after a change means you accept the revised terms.</p>

<h2>Frequently Asked Questions</h2>
<h3>Is the price on the product page the final price?</h3>
<p>The product price includes GST. Delivery and any cash on delivery charge are added in the order summary and shown to you before payment.</p>
<h3>Can I add my company GSTIN after placing the order?</h3>
<p>No. The invoice is generated from the details on the order, and GST law does not allow a tax invoice to be re-issued with a different recipient afterwards. Enter the GSTIN at checkout.</p>
<h3>What if the price shown was wrong?</h3>
<p>An obvious pricing error does not create a binding contract. We will tell you, cancel the affected line and refund it in full rather than dispatch at an incorrect price.</p>
<h3>Do these terms take away my consumer rights?</h3>
<p>No. Statutory rights under the Consumer Protection Act 2019 apply regardless of what these terms say.</p>','assets/images/banners/page-policy.svg',1,1,4,'active','Terms and Conditions | {{store_name}}','The terms that govern buying on {{store_name}}: eligibility, pricing and GST, how an order is formed, liability, governing law, grievance redressal and e-commerce rule disclosures.'),
(5,'Shipping Policy','shipping-policy','<p class="sik-doc__stamp">Last updated {{last_updated}}</p>
<h2>1. What This Policy Covers</h2>
<p>This policy covers everything up to the moment your parcel leaves for your address: serviceability, dispatch timelines, shipping charges and packaging. What happens at your door - attempts, one time passwords, open box checks and installation - is covered by the <a href="{{page:delivery-policy}}">Delivery Policy</a>.</p>

<h2>2. Where We Deliver</h2>
<p>We deliver across India to pin codes shown as serviceable at checkout. Enter your pin code on any product page to see the estimate for your address before you order. Bulky or fragile items can be serviceable in fewer pin codes than small accessories, because they move on a different network. We do not ship outside India.</p>

<h2>3. Order Processing</h2>
<ol>
<li>Orders are picked and packed on working days. Sunday and public holidays are not working days for dispatch.</li>
<li>Prepaid orders placed before the daily cut off are usually handed to the courier the same or the next working day.</li>
<li>Cash on delivery orders may need a verification call or an SMS confirmation before dispatch, which can add a day.</li>
<li>A dispatch email and SMS carry the courier name and tracking number. Tracking can take a few hours to become active on the courier network.</li>
<li>The standard delivery estimate is about {{delivery_days}} working days from dispatch, and the exact estimate for your pin code is shown at checkout.</li>
</ol>

<h2>4. Shipping Charges</h2>
<p>Charges are calculated on the order value, not per item, and are always displayed in the order summary before payment.</p>
<table>
<thead><tr><th>Charge</th><th>Amount</th><th>When it applies</th></tr></thead>
<tbody>
<tr><td>Standard delivery</td><td>{{shipping_cost}}</td><td>Every order that does not qualify for free delivery</td></tr>
{{#free_shipping_enabled}}<tr><td>Free delivery</td><td>No charge</td><td>Order value of {{free_shipping_threshold}} and above</td></tr>
{{/free_shipping_enabled}}{{#cod_enabled}}<tr><td>Cash on delivery handling</td><td>{{cod_charge}}</td><td>Orders paid in cash at the door, up to {{cod_max_amount}}</td></tr>
{{/cod_enabled}}<tr><td>Bulky item handling</td><td>Shown on the product page</td><td>Bulky or fragile items in some pin codes</td></tr>
</tbody>
</table>
<p>Shipping charges are refunded in full when we cancel an order, when the item arrives damaged or wrong, or when a product is found defective. They are not refunded on a change of mind return, because the outbound leg was already performed.</p>

<h2>5. How We Pack</h2>
<ul>
<li>String lights, diyas and small lamps travel in a right sized corrugated box with air cushioning, never in a loose envelope.</li>
<li>Glass and crystal lamps are double walled with edge protectors and are marked fragile and this side up.</li>
<li>Every parcel carries a tamper evident seal. A broken or re-taped seal is your signal to refuse the parcel at the door.</li>
<li>Where the category allows it we use recycled board and paper tape rather than plastic strapping.</li>
<li>Products with lithium batteries are shipped in line with the carrier rules for such goods, which is why some pin codes are restricted for air movement.</li>
</ul>

<h2>6. Tracking Your Parcel</h2>
<p>Track from <a href="{{url:orders}}">My Orders</a> when signed in, or from the <a href="{{url:track-order}}">Track Order</a> page using the order number and the registered phone number. Order numbers begin with {{order_prefix}}. If a tracking page has not moved for more than forty eight hours, raise a ticket and we will open a trace with the courier rather than asking you to wait.</p>

<h2>7. Split Shipments</h2>
<p>An order with several items may arrive in more than one parcel, from more than one fulfilment point, on different days. You are charged shipping once for the order and never once per parcel. Each parcel has its own tracking number, and your order page lists them separately.</p>

<h2>8. Delays We Cannot Control</h2>
<p>Weather events, strikes, road closures, election restrictions, local containment orders and peak season volumes affect courier networks. When a shipment is delayed we update the order status and, where an estimate has clearly failed, we offer you the choice of continuing to wait or cancelling for a full refund.</p>

<h2>9. Address Accuracy</h2>
<p>A complete address with a landmark and a reachable phone number is the single biggest factor in on time delivery. An address can be corrected only before dispatch, from your order page or by raising a ticket quickly. After dispatch the courier controls the consignment and a change of address is not always possible.</p>

<h2>10. Undelivered And Returned To Origin</h2>
<p>If the courier cannot deliver after the attempts described in the <a href="{{page:delivery-policy}}">Delivery Policy</a>, the parcel returns to us. Prepaid orders are refunded in full once the parcel is received and checked. Repeated refusal of cash on delivery parcels may lead to cash on delivery being disabled on that account.</p>

<h2>Frequently Asked Questions</h2>
<h3>How do I know if you deliver to my pin code?</h3>
<p>Enter the pin code in the delivery box on any product page. It returns serviceability and an estimate for that specific product.</p>
{{#free_shipping_enabled}}<h3>When does free shipping apply?</h3>
<p>On orders of {{free_shipping_threshold}} and above, calculated on the order value after coupon discounts and before any cash on delivery charge.</p>
{{/free_shipping_enabled}}
<h3>Can I choose the courier?</h3>
<p>No. The courier is selected by pin code, product size and the service required, such as open box delivery or an appointment for a bulky item.</p>
<h3>My tracking has not updated for two days. What now?</h3>
<p>Raise a ticket with the order number. We open a trace with the courier and keep you informed until the parcel moves or is declared lost, in which case you get a replacement or a full refund.</p>
<h3>Do you ship outside India?</h3>
<p>No. We deliver only within India.</p>','assets/images/banners/page-policy.svg',1,1,5,'active','Shipping Policy | {{store_name}}','Serviceability, dispatch timelines, shipping and cash on delivery charges, packaging standards, tracking and delays for orders placed on {{store_name}}.'),
(6,'Return Policy','return-policy','<p class="sik-doc__stamp">Last updated {{last_updated}}</p>
<h2>1. The Return Window</h2>
<p>Most products can be returned within {{return_window_days}} days of delivery. The window starts on the delivery date recorded by the courier, not on the order date. Some categories have a shorter window or are not returnable at all, and the exact window for an item is shown on its product page and on your order page, which is always the authoritative figure for that order.</p>

<h2>2. Return Windows By Category</h2>
<table>
<thead><tr><th>Category</th><th>Change of mind</th><th>Defective or damaged</th><th>Notes</th></tr></thead>
<tbody>
<tr><td>String and curtain lights</td><td>{{return_window_days}} days</td><td>Replacement route, reported within 48 hours for dead on arrival</td><td>Every strand, controller and adapter must come back in the original packaging</td></tr>
<tr><td>LED diyas and tea light candles</td><td>{{return_window_days}} days</td><td>Replacement route, reported within 48 hours for dead on arrival</td><td>Sealed packs that have not been opened may be returned, with the batteries removed</td></tr>
<tr><td>Table lamps and night lights</td><td>{{return_window_days}} days</td><td>Replacement route, reported within 48 hours for dead on arrival</td><td>Glass and crystal parts are checked for chips when the parcel is collected</td></tr>
<tr><td>Projector lamps</td><td>{{return_window_days}} days</td><td>{{return_window_days}} days if defective</td><td>The remote, the adapter and any lens discs must come back with the unit</td></tr>


<tr><td>Adapters, controllers and spare cables</td><td>{{return_window_days}} days</td><td>{{return_window_days}} days if defective</td><td>Must be unused and in original packaging</td></tr>

<tr><td>Batteries supplied with diyas and tea lights</td><td>Not returnable once opened</td><td>Replaceable if faulty on first use</td><td>Safety restriction</td></tr>
</tbody>
</table>

<h2>3. Condition A Returned Item Must Be In</h2>
<ul>
<li>Unused, undamaged and free of scratches, with all protective film in place where it was supplied.</li>
<li>Complete: adapter, controller, remote, cable, batteries, manual, warranty card, freebies and promotional items all included.</li>
<li>In the original retail box, with the barcode or batch label intact and unpeeled.</li>
</ul>

<h2>4. What Cannot Be Returned</h2>
<ol>
<li>Any product outside its return window.</li>
<li>Products damaged by misuse, liquid, voltage surge, unauthorised repair or physical impact after delivery.</li>
<li>Items missing the box, a barcode label, an accessory or a bundled free item.</li>
<li>Products that were clearly listed as non returnable on the product page at the time of purchase.</li>
<li>Lights that have been fitted or wired into place, where the fault is cosmetic rather than functional.</li>
</ol>

<h2>5. Damaged, Wrong Or Missing On Arrival</h2>
<p>Report a damaged parcel, a wrong item or a missing item within 48 hours of delivery, with photographs of the outer carton, the seal, the packing material and the product. Do not discard the packaging until the case is closed, because the courier claim depends on it. Where the parcel was visibly tampered with, refuse it at the door; that single step turns a long investigation into a same day replacement.</p>

<h2>6. Defective And Dead On Arrival Units</h2>
<p>A unit that does not power on or shows a clear functional defect within 48 hours of delivery is treated as dead on arrival and is replaced or refunded rather than sent for repair. A defect that appears later is handled as a manufacturer warranty claim under the <a href="{{page:warranty-policy}}">Warranty Policy</a>, which usually means a repair or a replacement arranged with the brand.</p>

<h2>7. How To Raise A Return</h2>
<ol>
<li>Sign in and open <a href="{{url:orders}}">My Orders</a>, select the order and choose Return or Replace on the item.</li>
<li>Pick a reason and upload photographs where the reason is damage, a wrong item or a defect.</li>
<li>We confirm the request and schedule a reverse pickup, usually within two to four working days.</li>
<li>Hand the item over in its original box. Do not paste tape or write on the retail box; use an outer bag or the courier packaging.</li>
<li>The item reaches our quality check, where the product, completeness and condition are verified.</li>
<li>On a pass, a refund or a replacement is initiated and you are notified. On a fail, we send you the quality check reason with photographs and ship the item back at no charge to you.</li>
</ol>

<h2>8. Reverse Pickup And Self Ship</h2>
<p>Reverse pickup is free wherever the courier network supports it. If your pin code is not serviceable for pickup we will ask you to ship the item to the address we give you and we reimburse the actual courier charge against a receipt, up to the amount a standard service would have cost. Use a courier that provides tracking, because an untracked parcel that never arrives cannot be refunded.</p>

<h2>9. Replacement Instead Of Refund</h2>
<p>Where you prefer a replacement and stock of the same model, colour and configuration is available, we send the replacement after the returned unit passes quality check. If that variant is out of stock, we convert the request into a refund rather than leaving you waiting indefinitely.</p>

<h2>10. Refunds</h2>
<p>Refund routes, timelines and deductions are set out in the <a href="{{page:refund-policy}}">Refund Policy</a>. In short: the money goes back to the method you paid with, and a cash on delivery order is refunded to a bank account you provide.</p>

<h2>11. Returned Packaging And E-Waste</h2>
<p>Returned packaging is reused or sent to a recycler. If you want to dispose of old lights or adapters rather than return a new one, ask support to route it to an authorised recycler under the E-Waste (Management) Rules 2022. Never put a lithium battery in household waste.</p>

<h2>Frequently Asked Questions</h2>
<h3>Does the return window start from the order date or the delivery date?</h3>
<p>The delivery date recorded by the courier.</p>
<h3>I threw away the box. Can I still return the product?</h3>
<p>No. The original retail box with its barcode label is part of the product for a return, and some brands also need it for a warranty claim.</p>
<h3>Do I pay for reverse pickup?</h3>
<p>No, where pickup is serviceable. Where it is not, ship it yourself and we reimburse the standard courier charge against a receipt.</p>
<h3>What if quality check rejects my return?</h3>
<p>You receive the reason with photographs, and the item is shipped back to you at no cost. You can escalate on the same ticket if you disagree.</p>
<h3>Can I return part of an order?</h3>
<p>Yes. Returns are raised per item, and a bundle must be returned complete because it was priced as a bundle.</p>','assets/images/banners/page-policy.svg',1,1,6,'active','Return Policy | {{store_name}}','The {{return_window_days}} day return window at {{store_name}}, return windows by category, condition requirements, non returnable items, reverse pickup and quality check.'),
(7,'Refund Policy','refund-policy','<p class="sik-doc__stamp">Last updated {{last_updated}}</p>
<h2>1. When A Refund Is Issued</h2>
<ul>
<li>You cancelled the order before dispatch, under the <a href="{{page:cancellation-policy}}">Cancellation Policy</a>.</li>
<li>We cancelled an item because it was out of stock, undeliverable to your pin code, or incorrectly priced.</li>
<li>A returned item passed quality check under the <a href="{{page:return-policy}}">Return Policy</a>.</li>
<li>The parcel was lost in transit or declared undeliverable and returned to us.</li>
<li>You refused a visibly tampered or damaged parcel at the door.</li>
<li>A payment was debited but the order did not confirm because the gateway timed out.</li>
</ul>

<h2>2. The Original Payment Method Rule</h2>
<p>A refund is credited back to the instrument the payment came from. A card payment returns to the same card, a UPI payment returns to the same virtual payment address, a net banking payment returns to the same bank account and a wallet payment returns to the same wallet. This is how the Reserve Bank of India expects refunds to be handled, and it is also the only route that keeps your bank statement reconcilable. We cannot redirect a refund to a different card or a different person account. The single exception is cash on delivery, where there is no electronic instrument to reverse, so we ask you for a bank account number and IFSC in your own name.</p>

<h2>3. Refund Timelines By Payment Method</h2>
<p>We initiate the refund from our side within the timeline below. The time after that belongs to the payment network and your bank, which is why a card refund takes longer than a UPI refund.</p>
<table>
<thead><tr><th>Payment method</th><th>Refund route</th><th>We initiate within</th><th>Typically credited in</th></tr></thead>
<tbody>
<tr><td>UPI</td><td>Same UPI handle</td><td>24 hours of approval</td><td>1 to 3 working days</td></tr>
<tr><td>Debit or credit card</td><td>Same card</td><td>24 hours of approval</td><td>5 to 7 working days, set by the issuing bank</td></tr>
<tr><td>Net banking</td><td>Same bank account</td><td>24 hours of approval</td><td>3 to 5 working days</td></tr>
<tr><td>Wallet</td><td>Same wallet</td><td>24 hours of approval</td><td>Up to 24 hours</td></tr>
<tr><td>Card or debit EMI</td><td>Same card, EMI cancelled by the bank</td><td>24 hours of approval</td><td>7 to 10 working days, and interest already billed is reversed by the bank</td></tr>
<tr><td>Cash on delivery</td><td>Bank transfer to the account you provide</td><td>24 hours of receiving verified account details</td><td>3 to 7 working days</td></tr>
</tbody>
</table>
<p>Working days exclude Sundays and bank holidays. A refund is complete from our side once the payment partner returns a reference number, which we publish on your order page so you can quote it to your bank.</p>

<h2>4. Failed And Pending Transactions</h2>
<p>If money left your account but the order did not confirm, the transaction did not reach us in a completed state. In almost every case the payment gateway auto reverses it to the source within a few working days without any action from you. The Reserve Bank of India harmonised turn around time framework for failed transactions requires such reversals to be completed within a defined period and provides for compensation to the customer where the bank or the operator exceeds it. If the amount has not returned, raise a ticket with the bank reference number and the date, and we will pursue it with the gateway on your behalf.</p>

<h2>5. What Is Deducted</h2>
<ol>
<li><strong>Outbound shipping</strong> is not refunded on a change of mind return, because that service was already performed. It is refunded in full when the fault was ours.</li>
<li><strong>Cash on delivery handling charge</strong> is not refunded on a change of mind return, and is refunded when we cancelled the order or the item was defective.</li>
<li><strong>Coupon and promotional value</strong> is not returned as cash. A coupon that applied across several items is apportioned, and only the amount you actually paid for the returned item is refunded.</li>
<li><strong>Bank cashback and instant discounts</strong> are settled between you and your bank under that offer terms. Where a bank reverses an instant discount because the order was cancelled, that reversal is not something we control.</li>
<li><strong>Missing accessories</strong> found at quality check are handled by rejecting the return, not by a partial deduction, so that you keep the choice.</li>
</ol>

<h2>6. Refunds On Partly Returned Orders</h2>
<p>Where you return some items and keep others, the refund is calculated on the actual paid value of the returned items. If returning an item takes the order below a free shipping or coupon threshold that it originally met, the benefit that no longer applies is adjusted from the refund. The order page shows this calculation line by line before you confirm.</p>

<h2>7. Refunds And The GST Invoice</h2>
<p>A refund includes the GST you paid on the returned item, because the tax follows the supply. A credit note is issued against the original tax invoice, and both documents remain downloadable from <a href="{{url:orders}}">My Orders</a>. If you claimed input credit on the original invoice, reverse it in the return for the period in which the credit note is issued.</p>

<h2>8. Chargebacks</h2>
<p>If you raise a chargeback with your bank while a refund is already in progress, the two processes collide and the money can be held longer, not returned faster. Talk to us first with the ticket number. Where a chargeback is raised on an order that was delivered and accepted, we will contest it with the delivery record and the signature or one time password captured at the door.</p>

<h2>9. If A Refund Is Late</h2>
<p>Check the reference number on your order page and quote it to your bank, which can locate the credit even when it has not yet appeared on the statement. If the timeline in section 3 has passed, raise a ticket and ask for escalation.{{#store_email}} Escalations may also be sent to <a href="mailto:{{store_email}}">{{store_email}}</a>.{{/store_email}} We acknowledge complaints within forty eight hours as the Consumer Protection (E-Commerce) Rules 2020 require.</p>

<h2>Frequently Asked Questions</h2>
<h3>Can I get my refund in a different bank account?</h3>
<p>Only for a cash on delivery order, and only into an account in your own name. Electronic payments must reverse to the source instrument.</p>
<h3>Can I have store credit instead of a refund?</h3>
<p>The default and the guaranteed route is the original payment method. Where a credit option is offered on your order page you may choose it, but you are never required to.</p>
<h3>Money was debited but I have no order. What do I do?</h3>
<p>Wait for the gateway auto reversal, which usually completes within a few working days. If it does not, raise a ticket with the bank reference number and we will pursue it with the gateway.</p>
<h3>Will I get the GST amount back?</h3>
<p>Yes. The refund includes the tax charged on the returned item, and a credit note is issued against the original invoice.</p>
<h3>My EMI is still being billed after a refund.</h3>
<p>The bank cancels the EMI plan after it receives the reversal, and it can lag by one billing cycle. Interest already charged is reversed by the bank once the plan is closed.</p>','assets/images/banners/page-policy.svg',1,1,7,'active','Refund Policy | {{store_name}}','Refund routes and timelines by payment method at {{store_name}}, the original payment method rule, failed transaction reversals, deductions, GST credit notes and escalation.'),
(8,'Warranty Policy','warranty-policy','<p class="sik-doc__stamp">Last updated {{last_updated}}</p>
<h2>1. Manufacturer Warranty, Not A Seller Warranty</h2>
<p>The warranty on each product is the one stated on its product page, and it is provided by the brand. We are the seller. We do not repair products ourselves and we do not issue a warranty of our own on top of the brand warranty. What we do is make sure your paperwork is correct and that a genuine defect within the first days of delivery is resolved by us rather than sent to a queue. The distinction matters, so it is set out plainly below.</p>
<table>
<thead><tr><th>Question</th><th>Seller responsibility (us)</th><th>Manufacturer warranty (the brand)</th></tr></thead>
<tbody>
<tr><td>Wrong item, missing accessory, damaged parcel</td><td>Ours. Report within 48 hours of delivery</td><td>Not applicable</td></tr>
<tr><td>Dead on arrival within 48 hours of delivery</td><td>Ours. Replacement or refund</td><td>Not applicable</td></tr>
<tr><td>Defect after the return window, inside the warranty period</td><td>We help you open the claim with the brand</td><td>Repair or replacement of the defective product</td></tr>
<tr><td>Proof of purchase</td><td>GST invoice, downloadable any time from your order page</td><td>The brand accepts that invoice as the warranty start date</td></tr>
<tr><td>Physical or liquid damage</td><td>Not covered</td><td>Not covered, chargeable repair</td></tr>
<tr><td>Where the claim is handled</td><td>Not at our premises</td><td>By the brand, usually as a replacement</td></tr>
</tbody>
</table>

<h2>2. Warranty Period</h2>
<p>The period is set by the brand for that model and is printed on the product page and in the warranty card inside the box. It runs from the invoice date, which is why the invoice is the document a brand asks for first. Accessories bundled in the box, such as an adapter, a controller or a remote, can carry a shorter period than the light itself.</p>

<h2>3. What A Warranty Covers</h2>
<ul>
<li>Manufacturing defects in materials and workmanship.</li>
<li>Component failure under normal use inside the warranty period.</li>
<li>Free repair or replacement of the defective product or part, as the brand decides.</li>
<li>A built-in rechargeable battery that stops holding a charge, where the brand offers that cover.</li>
</ul>

<h2>4. What A Warranty Does Not Cover</h2>
<ul>
<li>Physical damage, such as crushed or cut wires, broken bulbs, cracked glass or crystal, and dents.</li>
<li>Liquid damage, including on lights with a water resistance (IP) rating, because that rating is a design specification and not a warranty.</li>
<li>Damage from voltage fluctuation, an adapter other than the one supplied, or repair by anyone other than the brand.</li>
<li>Normal wear such as scratches, faded colours and battery ageing.</li>
<li>Consumables such as replaceable batteries, fuses and hooks.</li>
</ul>

<h2>5. Dead On Arrival</h2>
<p>If a product does not power on, or shows a clear functional defect, within 48 hours of delivery, treat it as dead on arrival. Report it with photographs or a short video of the fault and, wherever possible, the unboxing. A confirmed dead on arrival unit is replaced or refunded under the <a href="{{page:return-policy}}">Return Policy</a> instead of being sent for repair. Outside that window the brand warranty process applies, and the outcome is normally a repair.</p>

<h2>6. How To Raise A Warranty Claim</h2>
<ol>
<li><strong>Get the invoice.</strong> Download it from <a href="{{url:orders}}">My Orders</a>. It carries the invoice number and date that establish the warranty start.</li>
<li><strong>Record the fault.</strong> A short video or a few photographs of the fault, with the adapter and controller that came in the box, lets the brand decide the claim without a site visit.</li>
<li><strong>Open the claim.</strong> Contact the brand directly using the details on the warranty card, or raise a ticket with us and we will pass it to the brand and give you a documented case reference.</li>
<li><strong>Keep the claim number.</strong> The claim or ticket number from the brand is your record of the reported fault and the promised turnaround.</li>
<li><strong>Track it.</strong> If the brand exceeds the turnaround it stated, come back to us with the claim number and we will follow it up with the brand.</li>
</ol>

<h2>7. Warranty On A Replaced Or Repaired Unit</h2>
<p>A replacement carries the remaining period of the original warranty. It does not restart, unless the brand policy for that model says otherwise. A replaced component is usually warranted for the remainder of the product warranty or for a short period specific to that part, whichever is longer under the brand terms.</p>

<h2>8. Extended Warranty And Protection Plans</h2>
<p>Where a brand or an insurer offers an extended warranty or an accidental damage plan for a product, it appears as an optional add on at checkout with its own terms, and it is a contract between you and that provider. An extended plan begins the day the standard warranty ends and does not run in parallel with it. Read what it excludes before you buy it, because accidental damage plans typically carry an excess and limit the number of claims.</p>

<h2>9. E-Waste And End Of Life</h2>
<p>When a light, an adapter or a rechargeable lamp reaches the end of its life, do not put it in household waste. LED strings and adapters contain recoverable metals, and rechargeable lamps carry lithium cells that are a fire risk in a landfill. The E-Waste (Management) Rules 2022 place take back and recycling obligations on producers and sellers, and we can route end of life lights to an authorised recycler. Raise a ticket and ask for e-waste disposal.</p>

<h2>10. What We Are Not</h2>
<p>We do not act as an agent of any brand, we do not extend the brand warranty period, and we cannot overrule the brand''s assessment of a claim. Where you believe an assessment is wrong, ask the brand for it in writing and escalate it with us; that written assessment is what makes an escalation possible.</p>

<h2>Frequently Asked Questions</h2>
<h3>Is the invoice enough for a warranty claim?</h3>
<p>Yes. The GST invoice from your order page is the proof of purchase date. Keep the warranty card too where the brand asks for it.</p>
<h3>The crystal on my lamp cracked in the first week. Is it covered?</h3>
<p>No. Physical damage is excluded from every manufacturer warranty. An accidental damage plan, if you bought one, is the route for that.</p>
<h3>The light is rated for outdoor use but it stopped after heavy rain.</h3>
<p>Water resistance ratings are tested in laboratory conditions and are not a warranty against water getting in. Such damage is not covered.</p>
<h3>Can you replace the unit instead of sending it for repair?</h3>
<p>Inside the dead on arrival window, yes. After that the brand process governs, and the brand decides between repair and replacement.</p>
<h3>How do I reach the brand?</h3>
<p>The brand contact details are on the warranty card in the box. If the card is missing, raise a ticket with the product name and we will send them to you.</p>','assets/images/banners/page-policy.svg',1,1,8,'active','Warranty Policy | {{store_name}}','Manufacturer warranty versus seller responsibility at {{store_name}}: dead on arrival, coverage and exclusions, how to raise a claim with the brand, and e-waste disposal.'),
(9,'Frequently Asked Questions','faq','<h2>Ordering</h2>
<p>You can order as a guest or from a registered account. A registered account keeps your addresses, order history, GST invoices, wishlist and return requests in one place, so we recommend it for anything above a low value accessory. After you place an order you receive an email and an SMS with the order number, which begins with {{order_prefix}}. Quote that number in every conversation with us.</p>

<h2>Payments</h2>
<p>{{#cod_enabled}}Cash on delivery is available on serviceable pin codes for orders up to {{cod_max_amount}}, with a {{cod_charge}} handling fee. {{/cod_enabled}}Online methods including UPI, cards, net banking, wallets and EMI appear at checkout as they become available in your region. We never see or store your full card number, CVV or UPI PIN, and no member of our team will ever ask you for a one time password. Full details are in the <a href="{{page:payment-policy}}">Payment Policy</a>.</p>

<h2>Delivery</h2>
<p>Standard delivery costs {{shipping_cost}}{{#free_shipping_enabled}} and is free on orders of {{free_shipping_threshold}} and above{{/free_shipping_enabled}}, arriving in about {{delivery_days}} working days. Enter your pin code on a product page for the exact estimate for your address. See the <a href="{{page:shipping-policy}}">Shipping Policy</a> for dispatch and charges and the <a href="{{page:delivery-policy}}">Delivery Policy</a> for what happens at your door.</p>

<h2>Cancellations, Returns And Refunds</h2>
<p>Orders can be cancelled free of charge within {{cancel_window_hours}} hours and before dispatch. Most products can be returned within {{return_window_days}} days of delivery if they are unused and complete with every accessory and the original box. Reverse pickup is free where serviceable. Refunds go back to the payment method you used. See the <a href="{{page:cancellation-policy}}">Cancellation</a>, <a href="{{page:return-policy}}">Return</a> and <a href="{{page:refund-policy}}">Refund</a> policies.</p>

<h2>Warranty</h2>
<p>Every product carries the warranty stated on its product page. The invoice in <a href="{{url:orders}}">My Orders</a> is your proof of purchase. If a product fails within 48 hours of delivery, report it as dead on arrival and we will replace or refund it rather than sending you to the brand. See the <a href="{{page:warranty-policy}}">Warranty Policy</a>.</p>

<h2>Accounts And Privacy</h2>
<p>You can reset your password from the sign in page using the registered email address. Address book entries, wishlist items and saved pin codes can be edited or deleted at any time from My Account. We do not sell personal data, and marketing emails always carry an unsubscribe link. The <a href="{{page:privacy-policy}}">Privacy Policy</a> explains exactly what we store and for how long, and the <a href="{{page:cookie-policy}}">Cookie Policy</a> covers cookies.</p>

<h2>Still Need Help?</h2>
<p>Use the <a href="{{url:contact}}">contact page</a>.{{#store_email}} You can also write to <a href="mailto:{{store_email}}">{{store_email}}</a>.{{/store_email}}{{#store_phone}} Phone: {{store_phone}}.{{/store_phone}}{{#business_hours}} Hours: {{business_hours}}.{{/business_hours}} Include your order number and a short description of the issue.</p>','assets/images/banners/page-faq.svg',1,1,9,'active','Frequently Asked Questions | {{store_name}}','Answers to the questions customers ask most about ordering, payment, delivery, cancellation, returns, refunds, warranty and account privacy at {{store_name}}.'),
(10,'Cancellation Policy','cancellation-policy','<p class="sik-doc__stamp">Last updated {{last_updated}}</p>
<h2>1. Cancelling Before Dispatch Is Always Free</h2>
<p>You may cancel an order, or a single item in it, free of charge at any time before the parcel is handed to the courier. Cancellation is instant and self service from <a href="{{url:orders}}">My Orders</a>. There is no cancellation fee, and no reason has to be given. The convenience window configured on this store is {{cancel_window_hours}} hours from the time the order is placed, but the practical rule is simpler: if the item has not been dispatched, you can still cancel it.</p>

<h2>2. What You Can Do At Each Stage</h2>
<table>
<thead><tr><th>Order status</th><th>Can you cancel?</th><th>How</th><th>What you get back</th></tr></thead>
<tbody>
<tr><td>Pending or Confirmed</td><td>Yes, immediately</td><td>Cancel from My Orders</td><td>Full amount including shipping and any cash on delivery charge</td></tr>
<tr><td>Processing, not yet packed</td><td>Yes, usually</td><td>Cancel from My Orders, or raise a ticket if the button is gone</td><td>Full amount</td></tr>
<tr><td>Packed, awaiting courier pickup</td><td>Request only</td><td>Raise a ticket at once</td><td>Full amount if we stop it in time</td></tr>
<tr><td>Shipped or Out for delivery</td><td>No</td><td>Refuse the parcel at the door, or return it after delivery</td><td>Refund under the Return Policy, outbound shipping may not be refunded on a change of mind</td></tr>
<tr><td>Delivered</td><td>No</td><td>Raise a return inside the {{return_window_days}} day window</td><td>Refund under the Return Policy</td></tr>
</tbody>
</table>

<h2>3. How To Cancel</h2>
<ol>
<li>Sign in and open <a href="{{url:orders}}">My Orders</a>.</li>
<li>Select the order and choose Cancel, either on the whole order or on individual items.</li>
<li>Pick a reason. It is optional for you and useful for us, because repeated reasons point at a real problem in a listing or a delivery lane.</li>
<li>You receive a cancellation confirmation, and the refund process starts automatically for a prepaid order.</li>
</ol>
<p>If you checked out as a guest, use the <a href="{{url:track-order}}">Track Order</a> page with your order number and registered phone number, or raise a ticket from the <a href="{{url:contact}}">contact page</a>.</p>

<h2>4. Partial Cancellation</h2>
<p>You can cancel one item and keep the rest. Two things adjust automatically when you do: a coupon whose minimum order value is no longer met is removed, and free shipping that the order no longer qualifies for is re-applied as a charge. The revised total is shown before you confirm, and the refund is calculated on the actual amount paid for the cancelled item.</p>

<h2>5. Cancellations We Initiate</h2>
<p>We may cancel an order or a line in it when:</p>
<ul>
<li>The item is out of stock despite showing as available, which can happen when two customers check out at the same moment.</li>
<li>The price was wrong by an obvious error. We will not dispatch at an incorrect price, and we will not ask you to pay the difference either.</li>
<li>Your pin code turns out to be non serviceable for that category.</li>
<li>Address or contact details are incomplete and we cannot reach you after repeated attempts.</li>
<li>The order fails a fraud or risk check, or breaches the purchase limits published on the product page.</li>
<li>A cash on delivery verification call is not answered or the order is not confirmed.</li>
</ul>
<p>When we cancel, you are told the reason and refunded in full including shipping and any cash on delivery charge. Nothing is deducted for a cancellation that we initiated.</p>

<h2>6. Refusing At The Door</h2>
<p>Refusing a parcel at delivery is not a cancellation, it is a return in transit. It is the right thing to do when the seal is broken or the carton is crushed, and it produces the fastest resolution because the parcel never needs a separate pickup. Prepaid orders refused at the door are refunded once the parcel comes back and is checked. Refusing repeatedly on cash on delivery orders may lead to cash on delivery being disabled on the account.</p>

<h2>7. Non Cancellable Situations</h2>
<ol>
<li>Digital keys and prepaid codes once the key has been revealed.</li>
<li>Items built, configured or engraved to your specification, once production has begun.</li>

<li>Any order already delivered, which moves to the return route instead.</li>
</ol>

<h2>8. Refund Of A Cancelled Order</h2>
<p>Refunds follow the <a href="{{page:refund-policy}}">Refund Policy</a>. A prepaid cancellation is initiated within 24 hours of the cancellation being confirmed and reaches you in the timeline for your payment method. A cash on delivery order cancelled before dispatch involves no money at all, because nothing was collected.</p>

<h2>Frequently Asked Questions</h2>
<h3>I cancelled but the courier still has my parcel.</h3>
<p>A parcel that was already handed over sometimes completes its trip. Refuse it at the door, or accept it and raise a return. Either way you are refunded.</p>
<h3>Is there a cancellation fee?</h3>
<p>No. Cancelling before dispatch costs nothing.</p>
<h3>Can I change an item instead of cancelling?</h3>
<p>Orders cannot be edited after they are placed. Cancel the item and order the one you want, so pricing, stock and the invoice stay consistent.</p>
<h3>The cancel button has disappeared. Why?</h3>
<p>The item has moved into packing or dispatch. Raise a ticket immediately and we will try to intercept it.</p>
<h3>Will my coupon come back?</h3>
<p>A single use coupon consumed on a cancelled order is normally reinstated. Raise a ticket if it does not appear in your account.</p>','assets/images/banners/page-policy.svg',1,1,10,'active','Cancellation Policy | {{store_name}}','How to cancel an order on {{store_name}} before dispatch, what you can do at each order stage, partial cancellation, seller initiated cancellations and refunds.'),
(11,'Cookie Policy','cookie-policy','<p class="sik-doc__stamp">Last updated {{last_updated}}</p>
<h2>1. What A Cookie Is</h2>
<p>A cookie is a small text file that this website asks your browser to store. On a later request the browser sends it back, which is how a site remembers that you are signed in or that your cart has three items. This policy also covers similar technologies such as local storage and session storage, which do the same job with a different mechanism. It sits alongside our <a href="{{page:privacy-policy}}">Privacy Policy</a>, which explains what we do with personal data generally.</p>

<h2>2. Categories We Use</h2>
<table>
<thead><tr><th>Category</th><th>What it does</th><th>Examples on this site</th><th>Typical lifetime</th><th>Can you refuse it?</th></tr></thead>
<tbody>
<tr><td>Strictly necessary</td><td>Makes the site function at all</td><td>Session identifier, sign in state, cart contents, cross site request forgery token</td><td>Session, or until you sign out</td><td>No. Without these the site cannot keep you signed in or take an order</td></tr>
<tr><td>Functional</td><td>Remembers a choice you made</td><td>Saved pin code, recently viewed products, compare list, layout preference</td><td>Days to months</td><td>Yes, by clearing or blocking cookies in your browser</td></tr>
<tr><td>Analytics</td><td>Counts visits and shows which pages fail</td><td>Enabled only if the administrator has configured an analytics identifier in the store settings</td><td>Set by the analytics provider</td><td>Yes</td></tr>
<tr><td>Marketing</td><td>Measures advertising and shows relevant offers</td><td>Enabled only if the administrator has configured an advertising pixel in the store settings</td><td>Set by the advertising provider</td><td>Yes</td></tr>
</tbody>
</table>
<p>Analytics and marketing scripts are not part of the application. They load only when the store administrator has entered the corresponding identifier in the settings, and where none is configured no such script and no such cookie is present.</p>

<h2>3. First Party And Third Party</h2>
<p>First party cookies are set by this website and are the ones that keep your session, cart and preferences working. Third party cookies are set by another domain whose script the site loads, such as an analytics provider or a payment gateway page. We do not control what a third party stores, so read their own policy; the payment gateway in particular runs its own session on its own domain while you are paying.</p>

<h2>4. What We Do Not Do With Cookies</h2>
<ul>
<li>We do not store your name, address, card number or password inside a cookie.</li>
<li>We do not sell cookie data or the browsing profile it could build.</li>
<li>We do not use cookies to read anything else on your device.</li>
</ul>

<h2>5. Managing Cookies</h2>
<ol>
<li><strong>Browser settings.</strong> Every modern browser can block all cookies, block only third party cookies, or clear what is already stored. The controls sit under Privacy or Site settings.</li>
<li><strong>Per site controls.</strong> You can block cookies for this site alone without changing your settings everywhere else.</li>
<li><strong>Private browsing.</strong> An incognito or private window discards cookies when you close it, so your cart and sign in do not persist.</li>
<li><strong>Consequences.</strong> Blocking strictly necessary cookies breaks sign in and checkout. Blocking functional cookies means you re-enter your pin code and lose the compare list, but you can still buy.</li>
</ol>

<h2>6. Do Not Track And Global Privacy Signals</h2>
<p>Browsers send these signals inconsistently and there is no settled standard for honouring them. We do not rely on tracking cookies for the site to work, so the practical effect of such a signal here is limited to whichever optional analytics the administrator has enabled.</p>

<h2>7. Changes</h2>
<p>If we add a category of cookie, this page is updated before it goes live and the last updated date changes with it.</p>

<h2>Frequently Asked Questions</h2>
<h3>Will the site work if I block all cookies?</h3>
<p>You can browse, but you cannot sign in, keep a cart or complete checkout, because those depend on a session cookie.</p>
<h3>Why does my cart empty itself?</h3>
<p>Usually because cookies were cleared, the session expired, or a private window was closed. Sign in and the cart is stored against your account instead.</p>
<h3>Do you use cookies to advertise to me elsewhere?</h3>
<p>Only if the store administrator has configured an advertising pixel. If none is configured, no advertising cookie is set by this site.</p>
<h3>Is local storage different from a cookie?</h3>
<p>It stays on your device and is not sent with every request, which makes it better for things like a recently viewed list. Clearing site data removes both.</p>','assets/images/banners/page-policy.svg',1,1,11,'active','Cookie Policy | {{store_name}}','Which cookies {{store_name}} sets, what each category does, how long they last, which are strictly necessary and how to control or block them in your browser.'),
(12,'Payment Policy','payment-policy','<p class="sik-doc__stamp">Last updated {{last_updated}}</p>
<h2>1. Prices, GST And What You Actually Pay</h2>
<ul>
<li>Every price is in Indian Rupees and includes GST at the rate applicable to that product, currently {{tax_rate}} per cent for most lighting products.</li>
<li>Shipping, cash on delivery charges, coupon savings and bank offers appear as separate lines in the order summary before you pay.</li>
<li>The amount shown on the last screen before payment is the amount charged. Nothing is added afterwards.</li>
<li>Server side price and stock are authoritative. An amount submitted by a browser is never trusted, so a stale tab or an edited page cannot change what you are charged.</li>
</ul>

<h2>2. Accepted Payment Methods</h2>
<p>Availability depends on the payment partners enabled for this store and on your pin code. The methods actually live are the ones shown to you at checkout.</p>
<table>
<thead><tr><th>Method</th><th>Extra charge</th><th>Refund route</th><th>Notes</th></tr></thead>
<tbody>
<tr><td>UPI</td><td>None</td><td>Same UPI handle</td><td>Fastest refund route</td></tr>
<tr><td>Credit and debit cards</td><td>None</td><td>Same card</td><td>Processed on the gateway page, never on ours</td></tr>
<tr><td>Net banking</td><td>None</td><td>Same bank account</td><td>Subject to your bank per transaction limit</td></tr>
<tr><td>Wallets</td><td>None</td><td>Same wallet</td><td>Balance must cover the full order value</td></tr>
<tr><td>Card and debit card EMI</td><td>Bank interest, or discounted upfront on a no cost plan</td><td>Same card, plan cancelled by the bank</td><td>Minimum order value set by the bank</td></tr>
{{#cod_enabled}}<tr><td>Cash on delivery</td><td>{{cod_charge}}</td><td>Bank transfer to an account in your name</td><td>Serviceable pin codes only, up to {{cod_max_amount}}</td></tr>
{{/cod_enabled}}</tbody>
</table>
<h2>3. Payment Security</h2>
<ul>
<li>The site runs over HTTPS end to end, and card details are entered on a PCI DSS compliant gateway page, not on this website.</li>
<li>We never receive or store your full card number, CVV, card PIN, UPI PIN or net banking password.</li>
<li>Card on file tokenisation, where offered, is performed by the card network and the gateway. We hold a token, not a card number.</li>
<li>Two factor authentication applies as your bank requires it.</li>
<li>No member of our team will ever ask you for an OTP, a PIN, a screen sharing session or a remote access application. Any such call is fraud. Do not act on it and report it to us.</li>
</ul>

<h2>4. Authorisation, Capture And Failed Payments</h2>
<p>A successful payment is authorised by your bank and then captured by the gateway. If the connection drops in between, your bank may show a debit while the order stays unconfirmed. That transaction did not complete and is auto reversed by the gateway, usually within a few working days. The Reserve Bank of India harmonised turn around time framework for failed transactions sets the outer limit for such reversals and provides for compensation where an operator exceeds it. Do not pay twice for the same order; check <a href="{{url:orders}}">My Orders</a> first and raise a ticket with the bank reference number if the amount has not returned.</p>

<h2>5. GST Invoice</h2>
<p>A tax invoice is generated for every order and is available from <a href="{{url:orders}}">My Orders</a> as soon as the order is dispatched. It shows the taxable value, the tax split and the invoice number, and it is the document a warranty claim uses to establish the purchase date.{{#gst_number}} Our GSTIN is {{gst_number}}.{{/gst_number}} To claim input credit, enter your business name and GSTIN before placing the order; a tax invoice cannot be reissued to a different recipient afterwards.</p>

<h2>6. EMI</h2>
<p>Where EMI is offered, the tenure, the interest and the total repayment are shown before you confirm. On a no cost plan the interest the bank will charge is discounted from the product price upfront, so the sum of the instalments is close to the listed price; the bank still shows interest on your statement, and the discount already compensated for it. Processing fees, if the bank levies one, are charged by the bank and are not refundable by us. If an EMI order is cancelled or returned, the bank cancels the plan after the reversal reaches it, which can take a billing cycle.</p>

<h2>7. Coupons, Bank Offers And Cashback</h2>
<ul>
<li>Coupon terms, minimum order value and expiry are shown on the coupon itself, and only one coupon applies per order unless stated otherwise.</li>
<li>Bank instant discounts are funded by the bank under its own terms, including a maximum discount and a limit on uses per card.</li>
<li>Cashback credited by a bank or a wallet is settled by that provider on its own timeline and is outside our control.</li>
<li>If an order is cancelled or returned, a bank may reverse an instant discount it funded. That reversal is between you and the bank.</li>
</ul>

<h2>8. Pricing Errors And Fraud Checks</h2>
<p>An obvious pricing error does not create a binding contract. Where one occurs we cancel the affected line, tell you why and refund in full rather than dispatch at the wrong price. Orders may also be held for a risk check where the payment, address and contact details do not match, and we may ask you to confirm the order before it is dispatched.</p>

<h2>9. Refunds</h2>
<p>Refund routes and timelines by payment method are set out in the <a href="{{page:refund-policy}}">Refund Policy</a>. Refunds always return to the original instrument, except for cash on delivery which is transferred to a bank account in your name.</p>

{{#cod_enabled}}<h2>10. Cash On Delivery</h2>
<ol>
<li>Available on serviceable pin codes for orders up to {{cod_max_amount}}, with a {{cod_charge}} handling charge shown in the order summary.</li>
<li>Pay the exact amount printed on the invoice. Ask for a receipt or confirm the digital collection entry with the delivery agent.</li>
<li>Many delivery partners accept a card or UPI at the door. That is a courtesy of the partner, not a guarantee, so keep the cash ready.</li>
<li>An order may need a verification call or an SMS confirmation before dispatch.</li>
<li>Repeatedly refusing cash on delivery parcels may lead to the method being disabled on the account.</li>
</ol>
{{/cod_enabled}}

<h2>Frequently Asked Questions</h2>
<h3>Is it safe to pay on this site?</h3>
<p>Card and UPI details are entered on the payment gateway, which is PCI DSS compliant, and the whole site runs over HTTPS. We never receive your card number or PIN.</p>
<h3>Money was deducted twice. What do I do?</h3>
<p>Check My Orders. If only one order exists, the second debit is an uncaptured authorisation and reverses automatically. Raise a ticket with both bank reference numbers if it does not.</p>
<h3>Can I pay part cash and part online?</h3>
<p>No. One order uses one payment method.</p>
{{#cod_enabled}}<h3>Why is cash on delivery unavailable for my order?</h3>
<p>Either the pin code is not enabled for it, or the order value exceeds {{cod_max_amount}}, or the category is excluded for high value handling reasons.</p>
{{/cod_enabled}}
<h3>What does no cost EMI actually mean?</h3>
<p>The interest the bank will charge over the tenure is discounted from the price before you pay. Your statement still shows interest; the upfront discount offsets it.</p>','assets/images/banners/page-policy.svg',1,1,12,'active','Payment Policy | {{store_name}}','Accepted payment methods at {{store_name}}, GST inclusive pricing and invoicing, cash on delivery rules, EMI, payment security, failed transaction reversals and refund routes.'),
(13,'Delivery Policy','delivery-policy','<p class="sik-doc__stamp">Last updated {{last_updated}}</p>
<h2>1. What This Policy Covers</h2>
<p>This policy is about the last mile: what happens between the courier arriving in your area and the parcel being in your hands. Dispatch timelines, shipping charges and packaging are in the <a href="{{page:shipping-policy}}">Shipping Policy</a>. Read both before raising a delivery complaint, because the answer is usually in one of them.</p>

<h2>2. Delivery Estimates</h2>
<p>The estimate shown at checkout is calculated from your pin code, the product category and the current dispatch queue, and is typically about {{delivery_days}} working days. It is a working day estimate and not an appointment for a particular hour. Sundays and public holidays are not counted. Bulky or fragile items are scheduled by the delivery partner, who will call to agree a slot rather than simply arriving.</p>

<h2>3. Delivery Attempts</h2>
<table>
<thead><tr><th>Attempt</th><th>What happens</th><th>What you should do</th></tr></thead>
<tbody>
<tr><td>First</td><td>The agent calls the number on the order before arriving</td><td>Answer the call, or reschedule from the tracking link</td></tr>
<tr><td>Second</td><td>Attempted on the next working day</td><td>Confirm the address and a landmark if the first attempt failed to find it</td></tr>
<tr><td>Third</td><td>Final attempt</td><td>Collect from the courier branch if the partner offers that option</td></tr>
<tr><td>After the final attempt</td><td>The parcel returns to us</td><td>Prepaid orders are refunded in full once the parcel is received and checked</td></tr>
</tbody>
</table>

<h2>4. Who Can Receive The Parcel</h2>
<ul>
<li>Anyone present at the delivery address can receive the parcel, provided they can complete the verification the order requires.</li>
<li>High value orders carry a one time password sent to the registered number. Share it with the agent only when the parcel is physically in front of you, never in advance and never over a call that you did not initiate from the tracking page.</li>
<li>Some categories require a photo identity check at the door, which the tracking page tells you in advance.</li>
<li>A cash on delivery order needs the exact amount ready, or a card or UPI if the delivery partner supports payment at the door.</li>
</ul>

<h2>5. Open Box Delivery</h2>
<p>Where a category is enabled for open box delivery, the agent opens the parcel in front of you and you check the model, the colour, the accessories and the physical condition before accepting. Do this properly; it is the strongest protection you have. Once you accept an open box delivery, a claim of a wrong or damaged item is much harder to establish for both of us. Open box delivery is not available on every product or in every pin code.</p>

<h2>6. Check Before You Accept</h2>
<ol>
<li>Is the tamper evident seal intact and the carton free of crush marks, punctures or moisture?</li>
<li>Does the shipping label match your order number, which begins with {{order_prefix}}?</li>
<li>On an open box delivery, do the product, colour and pack size match the invoice?</li>
<li>Are the accessories listed on the box actually inside it?</li>
</ol>
<p>If any answer is no, refuse the parcel. Refusal at the door is the fastest resolution route and costs you nothing. If you have already accepted it, report the problem within 48 hours with photographs of the carton, the seal and the product, and keep all the packaging until the case is closed.</p>

<h2>7. Installation And Demonstration</h2>
<p>String lights, curtain lights, diyas and lamps are supplied ready to plug in, and nothing in this catalogue is installed for you. Anything that has to be fixed to a wall or a ceiling should be fitted by a qualified electrician; work done by an unauthorised person can void the manufacturer warranty. Hooks, brackets, extension cables and outdoor rated fittings are not supplied with the product.</p>

<h2>8. Rescheduling And Address Changes</h2>
<p>Reschedule from the courier tracking link, which is the only system the delivery agent actually sees. An address can be changed only before dispatch, from your order page. After dispatch, a change is at the discretion of the courier and is often limited to a nearby location within the same city.</p>

<h2>9. Marked Delivered But Not Received</h2>
<p>Report it within 48 hours. We ask the courier for the proof of delivery, which is the signature, the photograph or the one time password captured at the door, and we share the outcome with you. Where the delivery cannot be substantiated we replace the item or refund it in full. Check with family, neighbours and the building security desk first, because that resolves a large share of these cases immediately.</p>

<h2>10. Old Lights And E-Waste</h2>
<p>If you want to hand over old lights or adapters for recycling, arrange it with support before the delivery date rather than at the door, because a delivery agent is not authorised to accept an unlisted item. End of life lights and adapters are routed to an authorised recycler in line with the E-Waste (Management) Rules 2022.</p>

<h2>Frequently Asked Questions</h2>
<h3>Can I get delivery at a specific time?</h3>
<p>Standard parcels are delivered in the courier normal working window. Only bulky or fragile items are slot scheduled by appointment.</p>
<h3>The agent asked for the OTP before showing the parcel.</h3>
<p>Do not share it. The one time password confirms that the parcel was handed to you. Report the incident with the tracking number.</p>
<h3>Can I open the parcel before paying on a cash on delivery order?</h3>
<p>Only where open box delivery is enabled for that product. Otherwise payment is collected first, and you still have the {{return_window_days}} day return window afterwards.</p>
<h3>Nobody was home. What now?</h3>
<p>The courier attempts again on the next working day. Use the tracking link to reschedule or to ask for a branch pickup where that is offered.</p>
<h3>Do you install the lights?</h3>
<p>No. Everything in the catalogue is supplied ready to plug in, as section 7 explains. The delivery agent only hands over the carton.</p>','assets/images/banners/page-policy.svg',1,1,13,'active','Delivery Policy | {{store_name}}','What happens at your door when a {{store_name}} order arrives: delivery attempts, one time password and identity checks, open box delivery, installation, rescheduling and missing parcel claims.');

-- ---------------------------------------------------------------------------
--  FAQ ENTRIES (accordion on the FAQ page and in the help widget)
-- ---------------------------------------------------------------------------
INSERT INTO `faqs` (`id`,`category`,`question`,`answer`,`sort_order`,`status`) VALUES
(1,'Orders','How do I place an order on ShopInnKart?','Add the products you want to the cart, open the cart and apply a coupon if you have one, then click Checkout. Enter or pick a delivery address, choose a shipping method, select a payment method and confirm. You can check out as a guest, but a registered account keeps your invoices, order history and returns in one place. A confirmation email and SMS with your order number follow within a few minutes.',1,'active'),
(2,'Orders','Can I cancel or change my order after placing it?','You can cancel free of charge from My Orders within 24 hours of placing the order, as long as it has not been dispatched. Once a parcel leaves the warehouse, cancellation is no longer possible, but you can refuse the delivery or raise a return within 7 days of receiving it. Item and quantity changes are not possible after checkout - cancel and place a fresh order instead.',2,'active'),
(3,'Orders','How do I download my GST invoice?','Sign in, open My Orders, select the order and click Download Invoice. The PDF carries our GSTIN, the HSN code for each item and the tax breakup, so it is valid for input credit and for business expense claims. Invoices become available once the order is dispatched and stay in your account for eight years.',3,'active'),
(4,'Shipping','How long will my order take to arrive?','Standard delivery takes 3 to 7 working days and express delivery takes 1 to 3 working days on serviceable metro pin codes. Enter your pin code on any product page for an estimate specific to your address. Orders placed before 2:00 PM IST on a working day are usually packed the same day.',1,'active'),
(5,'Shipping','What are the delivery charges?','Standard delivery costs ₹79 and is free on orders above ₹999. Express delivery costs ₹199 and is free above ₹4,999. Cash on Delivery adds a ₹49 handling fee on orders below ₹999, which is waived above that value. Every charge is shown on the checkout page before you confirm.',2,'active'),
(6,'Shipping','Do you deliver to my pin code?','We deliver to more than 19,000 pin codes across India. Enter your pin code in the delivery box on any product page to check serviceability, Cash on Delivery availability and the expected number of days. A few remote locations, including parts of Ladakh, Lakshadweep and the Andaman and Nicobar Islands, are outside our courier network today.',3,'active'),
(7,'Returns','What is your return window?','Most products can be returned within 7 days of delivery. The item must be unused and undamaged, and must come back with every accessory, the manual, the warranty card, any free gift and the original brand box with its barcode label intact. Raise the request from My Orders and we arrange a free reverse pickup.',1,'active'),
(8,'Returns','Which products cannot be returned?','Batteries supplied with diyas and tea lights cannot be returned once opened, unless they are faulty on first use. We also cannot accept products damaged after delivery, lights fitted or wired into place where the fault is cosmetic, and items marked final sale.',2,'active'),
(9,'Returns','My product arrived damaged. What should I do?','Report it within 48 hours of delivery with photographs of the product, the accessories and the outer carton. Damage and dead on arrival cases are prioritised, and we send a replacement or a full refund without any shipping or restocking charge. If the outer box looks torn, wet or resealed at the door, refuse the parcel and tell us the same day.',3,'active'),
(10,'Payments','Which payment methods can I use?','Cash on Delivery is live on serviceable pin codes for orders up to ₹50,000. Online options including UPI, credit and debit cards, net banking, wallets and no-cost EMI are enabled gateway by gateway and appear at checkout as soon as they are available in your region.',1,'active'),
(11,'Payments','Is it safe to pay online on ShopInnKart?','Yes. The site runs entirely over HTTPS and online payments are processed by PCI DSS compliant gateways. Card numbers, CVV and UPI PINs are entered on the gateway and never reach our servers. We only store the payment reference and the amount. No one from ShopInnKart will ever ask you for a one time password, a PIN or a screen sharing session.',2,'inactive'),
(12,'Payments','When will I get my refund?','Refunds start within 24 hours of a cancellation, or within 48 hours of a returned item passing our quality check. UPI, net banking and wallet refunds reach you in 3 to 5 working days, card refunds in 5 to 7 working days, and Cash on Delivery refunds are sent by NEFT to a bank account in your name within 5 to 7 working days.',3,'active'),
(13,'Products','Are the products on ShopInnKart genuine and covered by India warranty?','Every product is sourced through an authorised national distributor or directly from the brand, so the serial number is registered for India warranty and can be serviced at any authorised centre in the country. We do not sell grey market imports, and we do not list refurbished units as new.',1,'inactive'),
(14,'Products','What does no-cost EMI actually mean?','No-cost EMI means the interest that your bank charges over the tenure is discounted from the product price upfront, so the total you repay equals the price shown on the product page. The discount and the exact monthly instalment are displayed before you confirm the order. Processing fees charged by some banks are not part of the product price.',2,'inactive'),
(15,'Account','How do I reset my password?','Click Forgot Password on the sign in page and enter your registered email address. We send a reset link that stays valid for 60 minutes and can be used once. If the email does not arrive within a few minutes, check the spam folder and confirm you are using the address the account was created with.',1,'active'),
(16,'Account','How do I stop marketing emails without closing my account?','Use the unsubscribe link at the bottom of any marketing email, or turn off communication preferences in My Account. Unsubscribing stops offers and newsletters only - you will still receive transactional messages such as order confirmations, dispatch updates and refund notices, because those are part of the service you asked for.',2,'active');

-- ---------------------------------------------------------------------------
--  BLOG
-- ---------------------------------------------------------------------------
INSERT INTO `blog_categories` (`id`,`name`,`slug`,`description`,`sort_order`,`status`) VALUES
(1,'Buying Guides','buying-guides','Practical guides that help you match the right lights to your room and the occasion.',1,'active'),
(2,'Comparisons','comparisons','Two products side by side, and which one suits which space.',2,'active'),
(3,'How To','how-to','Hanging, powering and styling walkthroughs for festive lighting.',3,'active'),
(4,'Lighting Explained','lighting-explained','Jargon decoded, so a fairy light box stops being a wall of numbers.',4,'active');

INSERT INTO `blog_posts`
(`id`,`category_id`,`admin_id`,`title`,`slug`,`excerpt`,`content`,`featured_image`,`author_name`,`views`,`is_featured`,`status`,`published_at`,`meta_title`,`meta_description`) VALUES
(1,1,1,'How to Choose Festive String Lights: Length, LED Count and Modes','how-to-choose-festive-string-lights','Length, LED density, warm white versus multicolour, and how many metres a room actually needs — the four numbers that decide whether a strand looks right.','<p>Most string light listings lead with a mode count. Modes are the least important number on the box. Four other things decide whether a strand looks the way you pictured it.</p><h2>Length, measured against the wall</h2><p>Measure the run before you order. A 5 metre strand around a standard door frame is generous; the same 5 metres across a 3 metre wall reads as sparse unless you drape it in swags. For a full wall backdrop, plan on the wall width multiplied by the number of vertical drops you want.</p><h2>LED count tells you density, not brightness</h2><p>Two 5 metre strands with 50 and 100 LEDs put out different looks, not different amounts of light. The 100 LED version reads as a continuous line; the 50 LED one reads as distinct points. Points photograph better against a plain wall. A continuous line suits an outline — a doorway, a railing, a headboard.</p><h2>Warm white, cool white and multicolour</h2><p>Warm white sits around 2700K and flatters skin and wood. Cool white is closer to 6000K and reads clinical indoors, though it cuts through daylight better on a balcony. Multicolour is festive but hard to photograph — the colour shifts between exposures. If you are lighting a space for photographs, warm white is the safer default.</p><h2>Power source</h2><p>USB strands run from a power bank, which matters if the nearest socket is across the room. Mains strands are brighter and do not need recharging. Battery strands are the most flexible and the shortest-lived — plan on replacing cells during a long festival.</p><h2>Then look at modes</h2><p>Eight modes is standard. In practice most people use two: steady on, and a slow fade. A remote is worth more than the mode count, because it saves reaching behind furniture for the controller.</p>','assets/images/placeholders/blog-1.svg','Ananya Iyer',4831,1,'published',DATE_SUB(NOW(), INTERVAL 3 DAY),'How to Choose Festive String Lights | ShopInnKart','Length, LED count, colour temperature and power source — the four numbers that decide whether a string light looks right in your room.'),
(2,1,1,'Curtain Lights vs Fairy Lights: Which Suits Your Room','curtain-lights-vs-fairy-lights','One makes a backdrop, the other makes an outline. Picking the wrong one is the most common festive lighting mistake.','<p>They are sold side by side and they do different jobs. A curtain light fills a plane; a fairy light traces a line. Almost every disappointing setup is one used where the other belonged.</p><h2>Curtain lights fill a wall</h2><p>A curtain is a horizontal wire carrying vertical drops. It is designed to be seen as a field of light — behind a bed, across a photo corner, over a window. Sizing is width by drop: a 3 metre by 3 metre curtain covers a standard bedroom wall. Hang it too narrow and the drops bunch; too wide and you see the gaps.</p><h2>Fairy lights trace an edge</h2><p>A single strand follows something: a banister, a door frame, the rim of a shelf, the inside of a jar. On a bare wall a fairy light has nothing to describe and looks like a stray cable. Give it an edge and it does the work of a much larger installation.</p><h2>Star and snowflake curtains are a third thing</h2><p>These carry moulded shapes at intervals rather than bare points. The shapes read clearly from across a room and disappear in a close-up photograph, which is the opposite of how bare LEDs behave. They suit a window seen from outside, or a wall seen from the far side of a room.</p><h2>Controllers</h2><p>A curtain has more LEDs, so the controller matters more. A remote is close to essential on a curtain mounted above head height. On a short fairy strand within arm''s reach, an inline button is fine.</p><h2>If you only buy one</h2><p>For a first purchase, a curtain does more visible work. A fairy strand is the better second buy, because by then you know which edges in the room want tracing.</p>','assets/images/placeholders/blog-2.svg','Rohan Kapoor',3681,1,'published',DATE_SUB(NOW(), INTERVAL 9 DAY),'Curtain Lights vs Fairy Lights | ShopInnKart','A curtain light fills a wall, a fairy light traces an edge. How to pick the right one for your room, with sizing guidance for both.'),
(3,1,1,'LED Diyas or Wax Diyas? How to Decide','led-diyas-or-wax-diyas','Safety around children and pets, reusability, and the one situation where a real flame still wins.','<p>This is not a question with one answer. Both belong in a Diwali setup, and the choice is usually about where in the house a particular diya sits.</p><h2>Where LED wins</h2><p>Anywhere a flame is a risk or a nuisance: a low shelf a toddler can reach, a balcony rail in wind, a corridor where a dupatta passes, near curtains, or in a home with a cat. LED diyas also survive being knocked over, which is the failure mode that actually happens.</p><h2>Where a flame wins</h2><p>The puja thali. For many families the lit wick is the point of the ritual, not a lighting effect, and no LED substitutes for it. Use real diyas where the ceremony calls for them and LED everywhere else — that is what most households end up doing.</p><h2>Reusability</h2><p>An LED diya lasts several festivals. The failure point is the button cell, not the LED, so store them with the cells removed. A cell left in over a year can leak and corrode the contacts, which is what usually kills a set that seemed fine when packed away.</p><h2>Colour</h2><p>Look for a warm yellow flicker rather than a steady white. A flicker that varies slightly in brightness reads as a flame from a few feet away; a perfectly regular pulse does not. Cool white LED diyas look like appliances.</p><h2>Disposal</h2><p>Button cells do not belong in household waste. Indian cities have battery collection points — use them when a set finally dies.</p>','assets/images/placeholders/blog-3.svg','Meera Raghavan',2934,0,'published',DATE_SUB(NOW(), INTERVAL 15 DAY),'LED Diyas or Wax Diyas? | ShopInnKart','Safety around children and pets, reusability across festivals, and the one place a real flame still belongs.'),
(4,2,1,'Decorative Table Lamps and Projectors Compared: Which Fits Your Space','table-lamps-and-projectors-compared','A crystal table lamp and a galaxy projector do very different things to a room. What each is actually for.','<p>Both are sold as "mood lighting" and they are not interchangeable. One is an object you look at; the other disappears and changes the surfaces around it.</p><h2>A crystal or diamond table lamp is an object</h2><p>It sits on a surface and is meant to be seen — the facets throw small points of colour onto the immediate area, but the lamp itself is the decoration. That means placement is about sightlines: a side table, a console, a shelf at eye level. On the floor or behind furniture it stops doing anything.</p><p>Look for touch or remote control if it will live on a bedside table, and check whether it is USB rechargeable — a lamp with a trailing mains cable limits where it can go.</p><h2>A galaxy projector is an effect</h2><p>It projects onto walls and ceiling, so the room does the work and the unit should be inconspicuous. It needs throw distance and a reasonably plain surface: a textured or dark ceiling absorbs the effect. Projectors are the better choice for a child''s room or a party wall, and the worse choice for a room you want to read in.</p><h2>Ambient light is the deciding factor</h2><p>A projector only reads in a dim room. If the space has streetlight through thin curtains, the effect washes out and you will stop using it. A table lamp works at any ambient level because it is a light source rather than a projected image.</p><h2>Buying for a gift</h2><p>A table lamp is the safer gift: it works in any room, at any time of day, without the recipient having to darken the space.</p>','assets/images/placeholders/blog-4.svg','Aditya Sen',5235,1,'published',DATE_SUB(NOW(), INTERVAL 21 DAY),'Table Lamps vs Galaxy Projectors | ShopInnKart','A crystal table lamp is an object you look at; a galaxy projector changes the room around it. How to choose between them.'),
(5,3,1,'How to Hang a Curtain Light Backdrop Without Damaging the Wall','hang-curtain-lights-without-damaging-wall','Adhesive hooks, tension methods and planning around the socket — how to put up a light wall in a rented flat and take it down cleanly.','<p>The lights are the easy part. Getting them onto a wall you are not allowed to drill, and off it again without taking the paint, is where most of the effort goes.</p><h2>Plan from the socket outward</h2><p>Decide where the plug goes before anything else. The controller usually sits 20 to 30 cm from the plug end, and it needs to be reachable if there is no remote. Running the strand the wrong way and discovering the cable is a metre short is the most common setup mistake.</p><h2>Adhesive hooks, applied properly</h2><p>Removable adhesive hooks hold a curtain light easily — the whole assembly weighs very little. What matters is preparation: wipe the wall, let it dry fully, press the hook for thirty seconds, then wait an hour before hanging anything. Most failures are hooks loaded immediately after sticking.</p><p>On a distempered or limewashed wall no adhesive will hold, and pulling one off takes a patch of finish with it. Use a tension rod across a window recess, or hang from an existing curtain rail instead.</p><h2>Spacing the top wire</h2><p>Hooks every 50 to 60 cm along the top wire keep the drops vertical. Fewer than that and the wire sags between them, which makes the drops splay outward and the whole backdrop look uneven — visible immediately in photographs even when it looks fine to the eye.</p><h2>Removal</h2><p>Pull adhesive strips slowly, straight down along the wall rather than outward. Outward is what lifts paint.</p><h2>Do not run strands under rugs or through doorways</h2><p>Compression damages the cable and a doorway crossing becomes a trip hazard with a live cable in it. Route along the skirting and use a socket on the same wall wherever possible.</p>','assets/images/placeholders/blog-5.svg','Nikhil Rao',2173,0,'published',DATE_SUB(NOW(), INTERVAL 30 DAY),'How to Hang Curtain Lights Without Damaging Walls | ShopInnKart','Adhesive hooks, tension rods and hook spacing — how to put up a light backdrop in a rented flat and remove it cleanly.'),
(6,4,1,'SMD, Rice LED and IP Ratings: Lighting Jargon Decoded','lighting-jargon-decoded','What the abbreviations on a fairy light box actually mean for brightness, colour and whether it survives a balcony.','<p>Lighting listings carry a handful of terms that are rarely explained. Here is what each one changes about the product in your hand.</p><h2>Rice LED vs SMD LED</h2><p>Rice LEDs are the small bullet-shaped bulbs moulded into the wire — cheap, warm, and directional, meaning they look brightest viewed end-on. SMD LEDs are flat chips mounted on the strand; they spread light more evenly and are usually brighter for the same power. A rice strand sparkles, an SMD strand glows. For a backdrop seen from one angle, SMD is more even. For a strand wound through a jar or a plant, rice reads better.</p><h2>Copper wire vs PVC wire</h2><p>Copper strands are hair-thin and bendable, so they hold a shape when wrapped — good in jars, around stems, along a frame. PVC strands are thicker and spring back to straight, which is what you want for a long run that should hang cleanly.</p><h2>IP ratings</h2><p>Two digits: solids, then water. IP20 means no water protection at all — indoors only. IP44 survives splashing from any direction, which covers a sheltered balcony but not direct monsoon rain. IP65 handles low-pressure jets and is the first rating genuinely suitable for exposed outdoor use. If a listing does not state a rating, assume indoor.</p><h2>Colour temperature in Kelvin</h2><p>Lower is warmer. 2700K is the yellow of an incandescent bulb; 4000K is neutral; 6000K is the blue-white of daylight. Festive lighting is almost always 2700K to 3000K. A strand described only as "white" without a number is usually cool white.</p><h2>Modes</h2><p>The standard eight are combination, in-wave, sequential, slo-glo, chasing/flash, slow fade, twinkle/flash and steady on. They are the same eight on nearly every controller, so the count is not a differentiator.</p>','assets/images/placeholders/blog-6.svg','Sanjana Bhat',1907,0,'published',DATE_SUB(NOW(), INTERVAL 42 DAY),'Lighting Jargon Decoded: SMD, Rice LED, IP Ratings | ShopInnKart','What SMD, rice LED, copper wire, IP ratings and Kelvin values actually mean for the lights you are buying.');

-- ---------------------------------------------------------------------------
--  SECTION 20 : NOTIFICATION TEMPLATES (email channel)
-- ---------------------------------------------------------------------------
INSERT INTO `notification_templates` (`id`,`template_key`,`name`,`channel`,`subject`,`body`,`variables`,`status`) VALUES

(1,'order_placed','Order Placed','email','Order {{order_number}} received - thank you for shopping with {{store_name}}','<p>Hi {{customer_name}},</p>
<p>Thank you for your order. We have received <strong>{{order_number}}</strong> placed on {{order_date}} and it is now being reviewed by our warehouse team.</p>
<p><strong>Order total:</strong> {{order_total}}<br><strong>Payment method:</strong> {{payment_method}}<br><strong>Delivery address:</strong> {{shipping_address}}</p>
<p>You can follow the progress of this order any time at {{order_url}}. We will email you again the moment it is confirmed and packed.</p>
<p>Estimated delivery: {{estimated_delivery}}</p>
<p>Warm regards,<br>Team {{store_name}}</p>','{{customer_name}}, {{order_number}}, {{order_date}}, {{order_total}}, {{payment_method}}, {{shipping_address}}, {{estimated_delivery}}, {{order_url}}, {{store_name}}','active'),

(2,'order_confirmed','Order Confirmed','email','Your order {{order_number}} is confirmed','<p>Hi {{customer_name}},</p>
<p>Good news - order <strong>{{order_number}}</strong> is confirmed and moving to packing.</p>
<p><strong>Items:</strong> {{order_items}}<br><strong>Order total:</strong> {{order_total}}<br><strong>Estimated delivery:</strong> {{estimated_delivery}}</p>
<p>Nothing further is needed from you. If you want to cancel, you can still do so from {{order_url}} until the parcel is dispatched.</p>
<p>Warm regards,<br>Team {{store_name}}</p>','{{customer_name}}, {{order_number}}, {{order_items}}, {{order_total}}, {{estimated_delivery}}, {{order_url}}, {{store_name}}','active'),

(3,'order_shipped','Order Shipped','email','Order {{order_number}} has been shipped','<p>Hi {{customer_name}},</p>
<p>Your order <strong>{{order_number}}</strong> left our warehouse today and is on its way to {{shipping_city}}.</p>
<p><strong>Courier:</strong> {{courier_name}}<br><strong>Tracking number:</strong> {{tracking_number}}<br><strong>Expected delivery:</strong> {{estimated_delivery}}</p>
<p>Track the parcel live here: {{tracking_url}}</p>
<p>Please note that the first courier scan can take up to 12 hours to appear. Keep your phone reachable, as high value orders need a one time password at the door.</p>
<p>Warm regards,<br>Team {{store_name}}</p>','{{customer_name}}, {{order_number}}, {{courier_name}}, {{tracking_number}}, {{tracking_url}}, {{shipping_city}}, {{estimated_delivery}}, {{store_name}}','active'),

(4,'order_delivered','Order Delivered','email','Order {{order_number}} has been delivered','<p>Hi {{customer_name}},</p>
<p>Your order <strong>{{order_number}}</strong> was delivered on {{delivered_date}}. We hope it is everything you expected.</p>
<p>A few things worth doing now:</p>
<ul>
<li>Download your GST invoice from {{order_url}} and keep it for warranty</li>
<li>Register the product with the brand if the box asks you to</li>
<li>Tell other shoppers what you think by leaving a review at {{review_url}}</li>
</ul>
<p>If anything is wrong with the product, report it within 48 hours and we will replace or refund it. Returns stay open for 7 days from today.</p>
<p>Warm regards,<br>Team {{store_name}}</p>','{{customer_name}}, {{order_number}}, {{delivered_date}}, {{order_url}}, {{review_url}}, {{store_name}}','active'),

(5,'order_cancelled','Order Cancelled','email','Order {{order_number}} has been cancelled','<p>Hi {{customer_name}},</p>
<p>Order <strong>{{order_number}}</strong> has been cancelled as requested.</p>
<p><strong>Reason:</strong> {{cancel_reason}}<br><strong>Order total:</strong> {{order_total}}</p>
<p>{{refund_note}}</p>
<p>If this cancellation was not made by you, or you would like the same items again, please contact us at {{support_email}} and we will help right away.</p>
<p>Warm regards,<br>Team {{store_name}}</p>','{{customer_name}}, {{order_number}}, {{cancel_reason}}, {{order_total}}, {{refund_note}}, {{support_email}}, {{store_name}}','active'),

(6,'password_reset','Password Reset','email','Reset your {{store_name}} password','<p>Hi {{customer_name}},</p>
<p>We received a request to reset the password for the account registered with {{customer_email}}.</p>
<p>Use this link to set a new password: {{reset_url}}</p>
<p>The link is valid for {{expiry_minutes}} minutes and can be used only once. If you did not ask for a reset, ignore this email - your current password stays active and no change is made.</p>
<p>For your safety, never share this link or any one time password with anyone, including someone claiming to be from {{store_name}}.</p>
<p>Warm regards,<br>Team {{store_name}}</p>','{{customer_name}}, {{customer_email}}, {{reset_url}}, {{expiry_minutes}}, {{store_name}}','active'),

(7,'welcome','Welcome Email','email','Welcome to {{store_name}}, {{customer_name}}','<p>Hi {{customer_name}},</p>
<p>Welcome to {{store_name}}. Your account with {{customer_email}} is ready to use.</p>
<p>From your account you can track every order, download GST invoices, save multiple delivery addresses, build a wishlist and start a return in a couple of clicks.</p>
<p>Here is a gift to begin with: use coupon <strong>{{coupon_code}}</strong> for {{coupon_value}} off your first order.</p>
<p>Start browsing here: {{shop_url}}</p>
<p>Warm regards,<br>Team {{store_name}}</p>','{{customer_name}}, {{customer_email}}, {{coupon_code}}, {{coupon_value}}, {{shop_url}}, {{store_name}}','active'),

(8,'newsletter_welcome','Newsletter Welcome','email','You are subscribed to the {{store_name}} newsletter','<p>Hi there,</p>
<p>Thank you for subscribing with {{subscriber_email}}. You will now get our weekly note covering genuine price drops, new launches worth knowing about and buying guides written by people who actually use this gear.</p>
<p>We send one email a week, sometimes two during a big sale. Never more than that.</p>
<p>Use coupon <strong>{{coupon_code}}</strong> for {{coupon_value}} off your next order.</p>
<p>You can unsubscribe in one click at {{unsubscribe_url}} at any time.</p>
<p>Warm regards,<br>Team {{store_name}}</p>','{{subscriber_email}}, {{coupon_code}}, {{coupon_value}}, {{unsubscribe_url}}, {{store_name}}','active'),

(9,'review_approved','Review Approved','email','Your review of {{product_name}} is now live','<p>Hi {{customer_name}},</p>
<p>Thank you for reviewing <strong>{{product_name}}</strong>. Your {{rating}} star review has passed moderation and is now visible to every shopper on the product page.</p>
<p>See it here: {{product_url}}</p>
<p>Honest reviews from real buyers are the most useful thing on a product page, so thank you for taking the time to write one.</p>
<p>Warm regards,<br>Team {{store_name}}</p>','{{customer_name}}, {{product_name}}, {{rating}}, {{product_url}}, {{store_name}}','active'),

(10,'back_in_stock','Back In Stock Alert','email','{{product_name}} is back in stock','<p>Hi there,</p>
<p>The product you asked us to watch is available again.</p>
<p><strong>{{product_name}}</strong><br>Price: {{product_price}}</p>
<p>Buy it here: {{product_url}}</p>
<p>Restocks on popular products move quickly and we cannot hold stock against an alert, so we suggest ordering soon if you still want it. This alert is sent once - request a new one from the product page if it sells out again.</p>
<p>Warm regards,<br>Team {{store_name}}</p>','{{product_name}}, {{product_price}}, {{product_url}}, {{store_name}}','active');

-- ===========================================================================
--  SECTION 3 : DEMO CUSTOMERS
--  Password for all three demo accounts is  Test@123
-- ===========================================================================
INSERT INTO `users`
(`id`,`first_name`,`last_name`,`email`,`phone`,`password`,`gender`,`date_of_birth`,`status`,`email_verified_at`,`last_login_at`,`last_login_ip`,`created_at`) VALUES
(1,'Rahul','Sharma','rahul.sharma@example.com','9845012345','$2y$10$PP2pd1H.cxqUGLie8fGpIubKyP4VqbjFYbxyRRyku39fXOnv.3Hhi','male','1992-04-18','active',DATE_SUB(NOW(), INTERVAL 120 DAY),DATE_SUB(NOW(), INTERVAL 2 DAY),'103.21.58.14',DATE_SUB(NOW(), INTERVAL 120 DAY)),
(2,'Priya','Nair','priya.nair@example.com','9820045678','$2y$10$PP2pd1H.cxqUGLie8fGpIubKyP4VqbjFYbxyRRyku39fXOnv.3Hhi','female','1995-11-02','active',DATE_SUB(NOW(), INTERVAL 85 DAY),DATE_SUB(NOW(), INTERVAL 1 DAY),'49.36.180.77',DATE_SUB(NOW(), INTERVAL 85 DAY)),
(3,'Arjun','Mehta','arjun.mehta@example.com','9900112233','$2y$10$PP2pd1H.cxqUGLie8fGpIubKyP4VqbjFYbxyRRyku39fXOnv.3Hhi','male','1989-07-26','active',DATE_SUB(NOW(), INTERVAL 54 DAY),DATE_SUB(NOW(), INTERVAL 4 DAY),'182.71.29.203',DATE_SUB(NOW(), INTERVAL 54 DAY));

INSERT INTO `user_addresses`
(`id`,`user_id`,`label`,`full_name`,`phone`,`address_line1`,`address_line2`,`landmark`,`city`,`state`,`pincode`,`country`,`address_type`,`is_default`) VALUES
(1,1,'Home','Rahul Sharma','9845012345','No. 214, 5th Cross, 4th Block','Koramangala','Opposite Jyoti Nivas College','Bengaluru','Karnataka','560034','India','home',1),
(2,1,'Office','Rahul Sharma','9845012345','Cessna Business Park, Tower 2, 6th Floor','Kadubeesanahalli, Outer Ring Road','Next to Ecospace','Bengaluru','Karnataka','560103','India','work',0),
(3,2,'Home','Priya Nair','9820045678','Flat 703, Sea Breeze Apartments, Perry Cross Road','Bandra West','Near Mount Mary Church','Mumbai','Maharashtra','400050','India','home',1),
(4,3,'Home','Arjun Mehta','9900112233','B-1204, Rohan Abhilasha, Nagar Road','Viman Nagar','Behind Phoenix Marketcity','Pune','Maharashtra','411014','India','home',1);

-- ---------------------------------------------------------------------------
--  WISHLISTS
-- ---------------------------------------------------------------------------
INSERT INTO `wishlists` (`id`,`user_id`,`name`,`created_at`) VALUES
(1,1,'My Wishlist',DATE_SUB(NOW(), INTERVAL 60 DAY)),
(2,2,'Gift Ideas',DATE_SUB(NOW(), INTERVAL 40 DAY));

INSERT INTO `wishlist_items` (`wishlist_id`,`product_id`,`created_at`) VALUES
(1,8,DATE_SUB(NOW(), INTERVAL 58 DAY)),
(1,7,DATE_SUB(NOW(), INTERVAL 44 DAY)),
(1,11,DATE_SUB(NOW(), INTERVAL 27 DAY)),
(1,10,DATE_SUB(NOW(), INTERVAL 6 DAY)),
(2,3,DATE_SUB(NOW(), INTERVAL 38 DAY)),
(2,6,DATE_SUB(NOW(), INTERVAL 22 DAY)),
(2,9,DATE_SUB(NOW(), INTERVAL 9 DAY));

-- ===========================================================================
--  SECTION 9 : DEMO ORDERS
--  order_number = SIK + YYYYMMDD (built from the real created_at) + 4 digits.
--  Money check for every row:  total = subtotal - discount + shipping + tax
--  Catalogue prices include GST (settings.tax_inclusive = 1), so tax = 0.00.
-- ===========================================================================
INSERT INTO `orders`
(`id`,`order_number`,`user_id`,`customer_name`,`customer_email`,`customer_phone`,
 `shipping_name`,`shipping_phone`,`shipping_address`,`shipping_address2`,`shipping_landmark`,`shipping_city`,`shipping_state`,`shipping_pincode`,`shipping_country`,
 `billing_name`,`billing_phone`,`billing_address`,`billing_city`,`billing_state`,`billing_pincode`,`billing_country`,
 `subtotal`,`discount_amount`,`coupon_id`,`coupon_code`,`shipping_amount`,`tax_amount`,`total_amount`,
 `shipping_method`,`payment_method`,`payment_status`,`status`,
 `customer_note`,`admin_note`,`cancel_reason`,`tracking_number`,`courier_name`,`estimated_delivery`,
 `ip_address`,`user_agent`,`confirmed_at`,`shipped_at`,`delivered_at`,`cancelled_at`,`created_at`) VALUES

-- 1. DELIVERED - 42 days ago - leaf and diya curtains, tea lights and a snowflake string, WELCOME10 applied
(1,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 42 DAY), '%Y%m%d'), '1042'),1,'Rahul Sharma','rahul.sharma@example.com','9845012345',
 'Rahul Sharma','9845012345','No. 214, 5th Cross, 4th Block','Koramangala','Opposite Jyoti Nivas College','Bengaluru','Karnataka','560034','India',
 'Rahul Sharma','9845012345','No. 214, 5th Cross, 4th Block, Koramangala','Bengaluru','Karnataka','560034','India',
 1354.00,135.40,1,'WELCOME10',0.00,0.00,1218.60,
 'standard','cod','paid','delivered',
 'Please call before delivery, the gate closes at 9 PM.','Delivered on the first attempt. Customer verified OTP.',NULL,'SIKBLR4471290388','Bluedart Express',DATE_SUB(NOW(), INTERVAL 36 DAY),
 '103.21.58.14','Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36',DATE_SUB(NOW(), INTERVAL 42 DAY),DATE_SUB(NOW(), INTERVAL 40 DAY),DATE_SUB(NOW(), INTERVAL 37 DAY),NULL,DATE_SUB(NOW(), INTERVAL 42 DAY)),

-- 2. DELIVERED - 30 days ago - warm white fairy curtains and star curtains, SAVE500 applied
(2,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 30 DAY), '%Y%m%d'), '1067'),2,'Priya Nair','priya.nair@example.com','9820045678',
 'Priya Nair','9820045678','Flat 703, Sea Breeze Apartments, Perry Cross Road','Bandra West','Near Mount Mary Church','Mumbai','Maharashtra','400050','India',
 'Priya Nair','9820045678','Flat 703, Sea Breeze Apartments, Perry Cross Road, Bandra West','Mumbai','Maharashtra','400050','India',
 1376.00,150.00,2,'SAVE500',0.00,0.00,1226.00,
 'standard','cod','paid','delivered',
 'Weekend delivery preferred - the lights are for a housewarming.','Delivered on the first attempt.',NULL,'SIKMUM8830142907','Delhivery',DATE_SUB(NOW(), INTERVAL 24 DAY),
 '49.36.180.77','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_SUB(NOW(), INTERVAL 28 DAY),DATE_SUB(NOW(), INTERVAL 25 DAY),NULL,DATE_SUB(NOW(), INTERVAL 30 DAY)),

-- 3. SHIPPED - 8 days ago - two crystal table lamps and two galaxy projectors
(3,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 8 DAY), '%Y%m%d'), '1088'),1,'Rahul Sharma','rahul.sharma@example.com','9845012345',
 'Rahul Sharma','9845012345','Cessna Business Park, Tower 2, 6th Floor','Kadubeesanahalli, Outer Ring Road','Next to Ecospace','Bengaluru','Karnataka','560103','India',
 'Rahul Sharma','9845012345','Cessna Business Park, Tower 2, 6th Floor, Kadubeesanahalli','Bengaluru','Karnataka','560103','India',
 1220.00,0.00,NULL,NULL,0.00,0.00,1220.00,
 'standard','cod','pending','shipped',
 'Office address - please deliver between 10 AM and 6 PM on a weekday.','Dispatched from the Bengaluru hub.',NULL,'SIKBLR9021774635','Ekart Logistics',DATE_ADD(NOW(), INTERVAL 2 DAY),
 '103.21.58.14','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0 Safari/537.36',DATE_SUB(NOW(), INTERVAL 8 DAY),DATE_SUB(NOW(), INTERVAL 3 DAY),NULL,NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),

-- 4. PROCESSING - 4 days ago - three diya curtains and two packs of acrylic diyas
(4,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 4 DAY), '%Y%m%d'), '1103'),3,'Arjun Mehta','arjun.mehta@example.com','9900112233',
 'Arjun Mehta','9900112233','B-1204, Rohan Abhilasha, Nagar Road','Viman Nagar','Behind Phoenix Marketcity','Pune','Maharashtra','411014','India',
 'Arjun Mehta','9900112233','B-1204, Rohan Abhilasha, Nagar Road, Viman Nagar','Pune','Maharashtra','411014','India',
 1692.00,0.00,NULL,NULL,0.00,0.00,1692.00,
 'standard','cod','pending','processing',
 NULL,'COD order confirmed on a call, moved to packing.',NULL,NULL,NULL,DATE_ADD(NOW(), INTERVAL 3 DAY),
 '182.71.29.203','Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/123.0 Mobile Safari/537.36',DATE_SUB(NOW(), INTERVAL 4 DAY),NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 4 DAY)),

-- 5. PENDING - 1 day ago - star fairy curtains and tea lights on express delivery (below the free threshold)
(5,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), '%Y%m%d'), '1119'),2,'Priya Nair','priya.nair@example.com','9820045678',
 'Priya Nair','9820045678','Flat 703, Sea Breeze Apartments, Perry Cross Road','Bandra West','Near Mount Mary Church','Mumbai','Maharashtra','400050','India',
 'Priya Nair','9820045678','Flat 703, Sea Breeze Apartments, Perry Cross Road, Bandra West','Mumbai','Maharashtra','400050','India',
 1088.00,0.00,NULL,NULL,199.00,0.00,1287.00,
 'express','cod','pending','pending',
 'Needed before Diwali, hence express.',NULL,NULL,NULL,NULL,DATE_ADD(NOW(), INTERVAL 2 DAY),
 '49.36.180.77','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',NULL,NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 1 DAY)),

-- 6. CANCELLED - 18 days ago - snowflake strings and warm white fairy curtains, cancelled by the customer
(6,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 18 DAY), '%Y%m%d'), '1075'),3,'Arjun Mehta','arjun.mehta@example.com','9900112233',
 'Arjun Mehta','9900112233','B-1204, Rohan Abhilasha, Nagar Road','Viman Nagar','Behind Phoenix Marketcity','Pune','Maharashtra','411014','India',
 'Arjun Mehta','9900112233','B-1204, Rohan Abhilasha, Nagar Road, Viman Nagar','Pune','Maharashtra','411014','India',
 1296.00,0.00,NULL,NULL,199.00,0.00,1495.00,
 'express','cod','failed','cancelled',
 NULL,'Cancelled by the customer within the 24 hour window. No stock impact.','Ordered warm white by mistake, will reorder the multicolour curtain',NULL,NULL,NULL,
 '182.71.29.203','Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/123.0 Mobile Safari/537.36',DATE_SUB(NOW(), INTERVAL 18 DAY),NULL,NULL,DATE_SUB(NOW(), INTERVAL 17 DAY),DATE_SUB(NOW(), INTERVAL 18 DAY));

-- ---------------------------------------------------------------------------
--  ORDER ITEMS
--  SUM(subtotal) per order matches orders.subtotal exactly.
--  price = the live catalogue sale price, mrp = the catalogue list price.
-- ---------------------------------------------------------------------------
INSERT INTO `order_items`
(`id`,`order_id`,`product_id`,`variant_id`,`vendor_id`,`commission`,`product_name`,`product_sku`,`product_image`,`variant_name`,`mrp`,`price`,`quantity`,`tax_rate`,`tax_amount`,`subtotal`,`total`,`created_at`) VALUES
-- Order 1 : 2 x 279 + 398 + 149 + 249 = 1354.00
(1,1,1,NULL,NULL,0.00,'Lexton Artificial Leaf Curtain LED String Light — 180 LED, 8 Modes, 10x3 ft','SIK-FLT-1001','assets/images/placeholders/light-leaf-curtain.svg',NULL,1999.00,279.00,2,18.00,0.00,558.00,558.00,DATE_SUB(NOW(), INTERVAL 42 DAY)),
(2,1,7,NULL,NULL,0.00,'The Purple Tree Diya Curtain Light — 12 Hanging Diyas, 138 LED, 8 Modes, 2.5 m','SIK-FLT-1005','assets/images/placeholders/light-diya-curtain.svg',NULL,1299.00,398.00,1,18.00,0.00,398.00,398.00,DATE_SUB(NOW(), INTERVAL 42 DAY)),
(3,1,5,NULL,NULL,0.00,'HUNCHA LED Tea Light Candles — Pack of 6 Flameless Diyas, Warm Yellow','SIK-FLT-2001','assets/images/placeholders/light-tealight.svg',NULL,149.00,149.00,1,18.00,0.00,149.00,149.00,DATE_SUB(NOW(), INTERVAL 42 DAY)),
(4,1,4,NULL,NULL,0.00,'fizzytech Snowflake Fairy String Lights — 15 LED, 3 Metre, Warm White','SIK-FLT-1004','assets/images/placeholders/light-fairy.svg',NULL,599.00,249.00,1,18.00,0.00,249.00,249.00,DATE_SUB(NOW(), INTERVAL 42 DAY)),
-- Order 2 : 2 x 399 + 2 x 289 = 1376.00
(5,2,8,NULL,NULL,0.00,'MIRADH 300 LED Fairy Curtain Lights — 9.8 x 9.8 ft, USB, Remote Controlled, Warm White','SIK-FLT-1006','assets/images/placeholders/light-curtain.svg',NULL,399.00,399.00,2,18.00,0.00,798.00,798.00,DATE_SUB(NOW(), INTERVAL 30 DAY)),
(6,2,2,NULL,NULL,0.00,'Lexton Star Curtain Light — 12 Stars, 138 LED, 8 Flashing Modes, Warm White','SIK-FLT-1002','assets/images/placeholders/light-star-curtain.svg',NULL,999.00,289.00,2,18.00,0.00,578.00,578.00,DATE_SUB(NOW(), INTERVAL 30 DAY)),
-- Order 3 : 2 x 259 + 2 x 351 = 1220.00
(7,3,11,NULL,NULL,0.00,'TakshHaven Crystal Diamond Table Lamp — 16 Colour RGB, Touch & Remote, USB Rechargeable','SIK-FLT-3002','assets/images/placeholders/light-lamp.svg',NULL,1550.00,259.00,2,18.00,0.00,518.00,518.00,DATE_SUB(NOW(), INTERVAL 8 DAY)),
(8,3,10,NULL,NULL,0.00,'Toy Imagine Galaxy Projector Lamp — Astronaut Star Night Light, Timer, 4 Modes','SIK-FLT-3001','assets/images/placeholders/light-projector.svg',NULL,699.00,351.00,2,18.00,0.00,702.00,702.00,DATE_SUB(NOW(), INTERVAL 8 DAY)),
-- Order 4 : 3 x 398 + 2 x 249 = 1692.00
(9,4,7,NULL,NULL,0.00,'The Purple Tree Diya Curtain Light — 12 Hanging Diyas, 138 LED, 8 Modes, 2.5 m','SIK-FLT-1005','assets/images/placeholders/light-diya-curtain.svg',NULL,1299.00,398.00,3,18.00,0.00,1194.00,1194.00,DATE_SUB(NOW(), INTERVAL 4 DAY)),
(10,4,6,NULL,NULL,0.00,'luxentia LED Tea Light Candles — Pack of 6 Acrylic Diyas, 3 cm, Warm White','SIK-FLT-2002','assets/images/placeholders/light-diya.svg',NULL,349.00,249.00,2,18.00,0.00,498.00,498.00,DATE_SUB(NOW(), INTERVAL 4 DAY)),
-- Order 5 : 2 x 395 + 2 x 149 = 1088.00
(11,5,3,NULL,NULL,0.00,'fizzytech Star SMD Fairy Curtain String Lights — 138 LED, 8 Modes, Multicolour','SIK-FLT-1003','assets/images/placeholders/light-star-curtain-multi.svg',NULL,559.00,395.00,2,18.00,0.00,790.00,790.00,DATE_SUB(NOW(), INTERVAL 1 DAY)),
(12,5,5,NULL,NULL,0.00,'HUNCHA LED Tea Light Candles — Pack of 6 Flameless Diyas, Warm Yellow','SIK-FLT-2001','assets/images/placeholders/light-tealight.svg',NULL,149.00,149.00,2,18.00,0.00,298.00,298.00,DATE_SUB(NOW(), INTERVAL 1 DAY)),
-- Order 6 : 2 x 249 + 2 x 399 = 1296.00
(13,6,4,NULL,NULL,0.00,'fizzytech Snowflake Fairy String Lights — 15 LED, 3 Metre, Warm White','SIK-FLT-1004','assets/images/placeholders/light-fairy.svg',NULL,599.00,249.00,2,18.00,0.00,498.00,498.00,DATE_SUB(NOW(), INTERVAL 18 DAY)),
(14,6,8,NULL,NULL,0.00,'MIRADH 300 LED Fairy Curtain Lights — 9.8 x 9.8 ft, USB, Remote Controlled, Warm White','SIK-FLT-1006','assets/images/placeholders/light-curtain.svg',NULL,399.00,399.00,2,18.00,0.00,798.00,798.00,DATE_SUB(NOW(), INTERVAL 18 DAY));

-- ---------------------------------------------------------------------------
--  ORDER STATUS HISTORY
-- ---------------------------------------------------------------------------
INSERT INTO `order_status_history` (`order_id`,`status`,`note`,`changed_by`,`admin_id`,`created_at`) VALUES
-- Order 1 : full journey to delivered
(1,'pending','Order placed on the website. Awaiting confirmation.','customer',NULL,DATE_SUB(NOW(), INTERVAL 42 DAY)),
(1,'confirmed','COD order auto-confirmed by the system.','system',NULL,DATE_SUB(NOW(), INTERVAL 42 DAY)),
(1,'processing','Items picked from the Bengaluru fulfilment centre.','admin',1,DATE_SUB(NOW(), INTERVAL 41 DAY)),
(1,'packed','Packed and sealed. Invoice generated.','admin',1,DATE_SUB(NOW(), INTERVAL 41 DAY)),
(1,'shipped','Handed to Bluedart Express. AWB SIKBLR4471290388.','admin',1,DATE_SUB(NOW(), INTERVAL 40 DAY)),
(1,'out_for_delivery','Out for delivery from the Koramangala hub.','system',NULL,DATE_SUB(NOW(), INTERVAL 37 DAY)),
(1,'delivered','Delivered and OTP verified. Cash collected in full.','system',NULL,DATE_SUB(NOW(), INTERVAL 37 DAY)),
-- Order 2 : full journey to delivered
(2,'pending','Order placed on the website. Awaiting confirmation.','customer',NULL,DATE_SUB(NOW(), INTERVAL 30 DAY)),
(2,'confirmed','COD order auto-confirmed by the system.','system',NULL,DATE_SUB(NOW(), INTERVAL 30 DAY)),
(2,'processing','Items picked from the Mumbai fulfilment centre.','admin',1,DATE_SUB(NOW(), INTERVAL 29 DAY)),
(2,'packed','Packed with air cushioning around each curtain.','admin',1,DATE_SUB(NOW(), INTERVAL 29 DAY)),
(2,'shipped','Handed to Delhivery. AWB SIKMUM8830142907.','admin',1,DATE_SUB(NOW(), INTERVAL 28 DAY)),
(2,'out_for_delivery','Out for delivery from the Bandra hub.','system',NULL,DATE_SUB(NOW(), INTERVAL 25 DAY)),
(2,'delivered','Delivered and OTP verified. Cash collected in full.','system',NULL,DATE_SUB(NOW(), INTERVAL 25 DAY)),
-- Order 3 : currently in transit
(3,'pending','Order placed on the website. Awaiting confirmation.','customer',NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),
(3,'confirmed','COD order auto-confirmed by the system.','system',NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),
(3,'processing','Items picked and quality checked.','admin',1,DATE_SUB(NOW(), INTERVAL 6 DAY)),
(3,'packed','Packed for the office address. Weekday delivery flagged.','admin',1,DATE_SUB(NOW(), INTERVAL 4 DAY)),
(3,'shipped','Handed to Ekart Logistics. AWB SIKBLR9021774635.','admin',1,DATE_SUB(NOW(), INTERVAL 3 DAY)),
-- Order 4 : in the warehouse
(4,'pending','Order placed on the website. Awaiting confirmation.','customer',NULL,DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4,'confirmed','COD order confirmed over the phone.','admin',1,DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4,'processing','Allocated to the Pune fulfilment centre for picking.','admin',1,DATE_SUB(NOW(), INTERVAL 2 DAY)),
-- Order 5 : brand new
(5,'pending','Order placed on the website. Awaiting confirmation.','customer',NULL,DATE_SUB(NOW(), INTERVAL 1 DAY)),
-- Order 6 : cancelled by the customer
(6,'pending','Order placed on the website. Awaiting confirmation.','customer',NULL,DATE_SUB(NOW(), INTERVAL 18 DAY)),
(6,'confirmed','COD order auto-confirmed by the system.','system',NULL,DATE_SUB(NOW(), INTERVAL 18 DAY)),
(6,'cancelled','Cancelled by the customer inside the 24 hour window. Wrong colour ordered.','customer',NULL,DATE_SUB(NOW(), INTERVAL 17 DAY));

-- ---------------------------------------------------------------------------
--  PAYMENTS (all Cash on Delivery, status mirrors orders.payment_status)
-- ---------------------------------------------------------------------------
INSERT INTO `payments` (`id`,`order_id`,`gateway`,`amount`,`currency`,`status`,`reference`,`paid_at`,`created_at`) VALUES
(1,1,'cod',1218.60,'INR','paid','COD-BLR-4471290388',DATE_SUB(NOW(), INTERVAL 37 DAY),DATE_SUB(NOW(), INTERVAL 42 DAY)),
(2,2,'cod',1226.00,'INR','paid','COD-MUM-8830142907',DATE_SUB(NOW(), INTERVAL 25 DAY),DATE_SUB(NOW(), INTERVAL 30 DAY)),
(3,3,'cod',1220.00,'INR','pending',NULL,NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),
(4,4,'cod',1692.00,'INR','pending',NULL,NULL,DATE_SUB(NOW(), INTERVAL 4 DAY)),
(5,5,'cod',1287.00,'INR','pending',NULL,NULL,DATE_SUB(NOW(), INTERVAL 1 DAY)),
(6,6,'cod',1495.00,'INR','failed','COD-CANCELLED-PUN-1075',NULL,DATE_SUB(NOW(), INTERVAL 18 DAY));

-- ---------------------------------------------------------------------------
--  COUPON USAGE (keeps coupons.used_count honest for the two orders above)
-- ---------------------------------------------------------------------------
INSERT INTO `coupon_usage` (`coupon_id`,`user_id`,`order_id`,`email`,`discount`,`created_at`) VALUES
(1,1,1,'rahul.sharma@example.com',135.40,DATE_SUB(NOW(), INTERVAL 42 DAY)),
(2,2,2,'priya.nair@example.com',150.00,DATE_SUB(NOW(), INTERVAL 30 DAY));

-- ===========================================================================
--  SECTION 12 : REVIEWS
-- ===========================================================================
-- None seeded. Every review in the previous seed was invented, and the
-- live store carries no reviews at all; a review is a verified buyer's words,
-- so it arrives through the storefront, not through the seed. The rating_avg
-- and rating_count on each product above are what the live catalogue shows.

-- ---------------------------------------------------------------------------
--  NEWSLETTER SUBSCRIBERS
-- ---------------------------------------------------------------------------
INSERT INTO `newsletter_subscribers` (`id`,`email`,`name`,`source`,`ip_address`,`status`,`created_at`) VALUES
(1,'rahul.sharma@example.com','Rahul Sharma','checkout','103.21.58.14','active',DATE_SUB(NOW(), INTERVAL 118 DAY)),
(2,'priya.nair@example.com','Priya Nair','homepage','49.36.180.77','active',DATE_SUB(NOW(), INTERVAL 84 DAY)),
(3,'arjun.mehta@example.com','Arjun Mehta','popup','182.71.29.203','active',DATE_SUB(NOW(), INTERVAL 53 DAY)),
(4,'sneha.pawar@example.com','Sneha Pawar','footer','106.51.12.90','active',DATE_SUB(NOW(), INTERVAL 47 DAY)),
(5,'vikram.iyer@example.com','Vikram Iyer','blog','117.202.44.6','active',DATE_SUB(NOW(), INTERVAL 39 DAY)),
(6,'meera.krishnan@example.com','Meera Krishnan','homepage','157.32.88.121','active',DATE_SUB(NOW(), INTERVAL 33 DAY)),
(7,'faisal.khan@example.com','Faisal Khan','popup','103.87.56.19','active',DATE_SUB(NOW(), INTERVAL 28 DAY)),
(8,'kavita.sharma@example.com','Kavita Sharma','footer','122.161.7.244','unsubscribed',DATE_SUB(NOW(), INTERVAL 25 DAY)),
(9,'harish.kumar@example.com','Harish Kumar','checkout','49.207.190.33','active',DATE_SUB(NOW(), INTERVAL 20 DAY)),
(10,'tarun.bhatia@example.com','Tarun Bhatia','blog','203.192.240.15','active',DATE_SUB(NOW(), INTERVAL 14 DAY)),
(11,'zoya.fernandes@example.com','Zoya Fernandes','homepage','171.61.128.72','unsubscribed',DATE_SUB(NOW(), INTERVAL 9 DAY)),
(12,'gaurav.sethi@example.com','Gaurav Sethi','popup','59.144.85.201','active',DATE_SUB(NOW(), INTERVAL 4 DAY));

-- ---------------------------------------------------------------------------
--  CONTACT MESSAGES (mixed states for the admin inbox)
-- ---------------------------------------------------------------------------
INSERT INTO `contact_messages` (`id`,`name`,`email`,`phone`,`subject`,`message`,`status`,`admin_reply`,`ip_address`,`created_at`) VALUES
(1,'Sandeep Rao','sandeep.rao@example.com','9741025896','Bulk order of curtain lights for a wedding','We are decorating a wedding venue in HSR Layout next month and need 40 warm white curtain lights and 60 packs of LED diyas. Could you share a quotation with GST, the expected lead time and whether you can deliver everything in one consignment? We can pay by NEFT against a proforma invoice.','new',NULL,'106.51.12.90',DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2,'Nandini Shetty','nandini.shetty@example.com','9886734512','Do the curtain lights need a socket nearby?','I am about to order the 300 LED fairy curtain for our balcony. The page says USB powered. Will it run from a USB wall adapter or a power bank, and how long is the cable from the curtain to the USB plug?','read',NULL,'117.202.44.6',DATE_SUB(NOW(), INTERVAL 4 DAY)),
(3,'Imran Sheikh','imran.sheikh@example.com','9004561230','Invoice needed with company GSTIN','I placed an order last week using my personal account but I need the invoice raised against my company GSTIN for input credit. The order has already been delivered. Is it possible to have the invoice reissued, and what details do you need from me?','replied','We can reissue the invoice against a company GSTIN only if the order was placed with the GSTIN entered at checkout. For this order we have shared a signed delivery certificate that your accounts team can use for the expense claim. For future orders, please add the GSTIN in the billing step and the invoice will carry it automatically.','103.87.56.19',DATE_SUB(NOW(), INTERVAL 9 DAY)),
(4,'Lakshmi Venkatesh','lakshmi.venkatesh@example.com','9448120765','Delivery to Port Blair','Your site says my pin code 744101 is not serviceable. Do you have any plan to deliver to the Andaman and Nicobar Islands, or is there a partner courier I can arrange myself if I pay the freight?','closed','Thank you for writing in. We do not deliver to 744101 at present because our courier partners do not offer insured delivery of fragile parcels to the islands. We are unable to release goods to a customer arranged courier as that would void the transit insurance. We have added your pin code to our expansion request list and will email you if that changes.','203.192.240.15',DATE_SUB(NOW(), INTERVAL 16 DAY));

-- ===========================================================================
--  END OF SEED PART C
-- ===========================================================================


-- ---------------------------------------------------------------------------
--  Seed post-processing
--  Prices are stored GST-inclusive (settings.tax_inclusive = 1), so the demo
--  orders need their extracted GST filled in for the invoice to add up.
-- ---------------------------------------------------------------------------
-- The line tax is taken on the value AFTER the order's coupon discount is
-- spread proportionally, so SUM(order_items.tax_amount) reconciles with
-- orders.tax_amount and the GST invoice adds up.
UPDATE `order_items` oi
  JOIN `orders` o ON o.`id` = oi.`order_id`
   SET oi.`tax_amount` = ROUND(
           (oi.`subtotal` * IF(o.`subtotal` > 0, (o.`subtotal` - o.`discount_amount`) / o.`subtotal`, 1))
         - (oi.`subtotal` * IF(o.`subtotal` > 0, (o.`subtotal` - o.`discount_amount`) / o.`subtotal`, 1))
           * 100 / (100 + oi.`tax_rate`),
       2),
       oi.`total` = oi.`subtotal`
 WHERE oi.`tax_rate` > 0;

UPDATE `orders` o
   SET o.`tax_amount` = (
        SELECT ROUND(COALESCE(SUM(oi.`tax_amount`), 0), 2)
          FROM `order_items` oi
         WHERE oi.`order_id` = o.`id`
   );

-- Keep the demo product ratings consistent with the seeded reviews.
UPDATE `products` p
   SET p.`rating_avg` = COALESCE((
        SELECT ROUND(AVG(r.`rating`), 2) FROM `reviews` r
         WHERE r.`product_id` = p.`id` AND r.`status` = 'approved'
   ), p.`rating_avg`),
       p.`rating_count` = COALESCE((
        SELECT COUNT(*) FROM `reviews` r
         WHERE r.`product_id` = p.`id` AND r.`status` = 'approved'
   ), 0)
 WHERE EXISTS (
        SELECT 1 FROM `reviews` r
         WHERE r.`product_id` = p.`id` AND r.`status` = 'approved'
   );

-- Parent product stock always mirrors the sum of its active variants.
UPDATE `products` p
   SET p.`stock` = COALESCE((
        SELECT SUM(pv.`stock`) FROM `product_variants` pv
         WHERE pv.`product_id` = p.`id` AND pv.`status` = 'active'
   ), p.`stock`)
 WHERE p.`has_variants` = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================================================
--  End of ShopInnKart schema + seed
--
--  Admin login : admin@shopinnkart.com / Admin@123
--  Demo customer passwords : Test@123
--  Change both immediately after installing.
-- ===========================================================================

