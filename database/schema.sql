-- ===========================================================================
--  ShopInnKart - shopinnkart.com
--  Electronics & Technology E-Commerce Platform
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
    `email`      VARCHAR(190) NULL,
    `discount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_usage_coupon` (`coupon_id`),
    KEY `idx_usage_user` (`user_id`),
    KEY `idx_usage_order` (`order_id`),
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
('general', 'store_tagline',         'Shop Smart. Live Better.',                 'text',     'Tagline', 2),
('general', 'store_description',     'ShopInnKart is your trusted destination for smartphones, laptops, audio, gaming and smart home technology at honest prices.', 'textarea', 'Store Description', 3),
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
('seo', 'meta_title',                'ShopInnKart - Buy Smartphones, Laptops, Audio & Smart Devices Online', 'text', 'Default Meta Title', 1),
('seo', 'meta_description',          'Shop the latest electronics at ShopInnKart. Smartphones, laptops, headphones, smart watches, gaming and more with genuine warranty, fast delivery and secure payments.', 'textarea', 'Default Meta Description', 2),
('seo', 'meta_keywords',             'electronics online, buy smartphone, laptop deals, headphones, smart watch, gaming console, ShopInnKart', 'textarea', 'Default Meta Keywords', 3),
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
INSERT INTO `seo_settings` (`page_key`, `page_label`, `meta_title`, `meta_description`, `robots`) VALUES
('home',         'Homepage',      'ShopInnKart - Buy Smartphones, Laptops, Audio & Smart Devices Online', 'Discover the latest electronics at unbeatable prices. Genuine products, fast delivery, easy returns and secure payments at ShopInnKart.', 'index, follow'),
('shop',         'Shop',          'Shop All Electronics Online | ShopInnKart', 'Browse thousands of smartphones, laptops, audio devices, wearables and accessories with filters for brand, price, rating and discount.', 'index, follow'),
('deals',        'Deals',         'Today''s Best Electronics Deals | ShopInnKart', 'Limited-time deals on smartphones, laptops, headphones and smart devices. Grab flash sale prices before they end.', 'index, follow'),
('new-arrivals', 'New Arrivals',  'New Arrivals in Electronics | ShopInnKart', 'The newest smartphones, laptops, wearables and audio gear, freshly added to ShopInnKart.', 'index, follow'),
('best-sellers', 'Best Sellers',  'Best Selling Electronics | ShopInnKart', 'The products our customers buy most - top rated smartphones, headphones, laptops and accessories.', 'index, follow'),
('brands',       'Brands',        'Shop by Brand | ShopInnKart', 'Explore Apple, Samsung, Sony, Dell, ASUS, JBL and more official brand stores on ShopInnKart.', 'index, follow'),
('blog',         'Blog',          'Tech Guides & Buying Advice | ShopInnKart', 'Buying guides, comparisons and tips to help you choose the right electronics.', 'index, follow'),
('contact',      'Contact',       'Contact ShopInnKart Customer Support', 'Reach the ShopInnKart support team by phone, email or the contact form. We reply within one business day.', 'index, follow'),
('track-order',  'Track Order',   'Track Your Order | ShopInnKart', 'Enter your order number to see the live status of your ShopInnKart delivery.', 'noindex, follow'),
('cart',         'Cart',          'Your Shopping Cart | ShopInnKart', 'Review the items in your ShopInnKart cart before checkout.', 'noindex, follow'),
('checkout',     'Checkout',      'Secure Checkout | ShopInnKart', 'Complete your ShopInnKart order securely.', 'noindex, nofollow');

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
--  Categories (12 top level + 18 sub categories)
-- ---------------------------------------------------------------------------
INSERT INTO `categories` (`id`, `parent_id`, `name`, `slug`, `description`, `image`, `icon`, `sort_order`, `is_featured`, `show_in_menu`, `status`, `meta_title`, `meta_description`) VALUES
(1,  NULL, 'Smartphones',           'smartphones',           'Flagship and value smartphones from every major brand, with genuine India warranty.', 'assets/images/categories/cat-smartphones.svg', 'smartphone', 1,  1, 1, 'active', 'Buy Smartphones Online | ShopInnKart', 'Compare and buy the latest 5G smartphones with official warranty, EMI options and fast delivery.'),
(2,  NULL, 'Laptops',               'laptops',               'Ultrabooks, creator machines and gaming laptops for work and play.', 'assets/images/categories/cat-laptops.svg', 'laptop', 2,  1, 1, 'active', 'Buy Laptops Online | ShopInnKart', 'Shop thin-and-light, business and gaming laptops with the newest processors and genuine warranty.'),
(3,  NULL, 'Tablets',               'tablets',               'Tablets for reading, drawing, streaming and studying.', 'assets/images/categories/cat-tablets.svg', 'tablet', 3,  1, 1, 'active', 'Buy Tablets Online | ShopInnKart', 'Android and iPad tablets with stylus support, long battery life and warranty.'),
(4,  NULL, 'Televisions',           'televisions',           'Smart TVs with 4K, QLED and OLED panels for every room size.', 'assets/images/categories/cat-tv.svg', 'tv', 4,  1, 1, 'active', 'Buy Smart TVs Online | ShopInnKart', '4K, QLED and OLED smart televisions with free installation and warranty.'),
(5,  NULL, 'Audio',                 'audio',                 'Headphones, earbuds, soundbars and speakers tuned for every listener.', 'assets/images/categories/cat-audio.svg', 'headphone', 5,  1, 1, 'active', 'Buy Headphones & Speakers | ShopInnKart', 'Noise cancelling headphones, TWS earbuds, soundbars and Bluetooth speakers.'),
(6,  NULL, 'Wearables',             'wearables',             'Smart watches and fitness bands that track everything that matters.', 'assets/images/categories/cat-wearables.svg', 'watch', 6,  1, 1, 'active', 'Buy Smart Watches Online | ShopInnKart', 'Smart watches and fitness bands with health tracking, GPS and long battery life.'),
(7,  NULL, 'Gaming',                'gaming',                'Consoles, controllers and gaming gear for serious players.', 'assets/images/categories/cat-gaming.svg', 'gamepad', 7,  1, 1, 'active', 'Gaming Consoles & Accessories | ShopInnKart', 'Latest consoles, controllers, headsets and gaming accessories.'),
(8,  NULL, 'Cameras',               'cameras',               'Mirrorless cameras, lenses and action cams for creators.', 'assets/images/categories/cat-cameras.svg', 'camera', 8,  1, 1, 'active', 'Buy Cameras Online | ShopInnKart', 'Mirrorless cameras, DSLRs, action cameras and lenses for every creator.'),
(9,  NULL, 'Computer Accessories',  'computer-accessories',  'Monitors, keyboards, mice and printers to complete your desk.', 'assets/images/categories/cat-accessories.svg', 'keyboard', 9,  1, 1, 'active', 'Computer Accessories Online | ShopInnKart', 'Monitors, mechanical keyboards, mice, webcams and printers.'),
(10, NULL, 'Smart Home',            'smart-home',            'Routers, smart lighting, cameras and speakers for a connected home.', 'assets/images/categories/cat-smarthome.svg', 'home', 10, 1, 1, 'active', 'Smart Home Devices | ShopInnKart', 'Wi-Fi routers, smart plugs, security cameras and voice assistants.'),
(11, NULL, 'Storage & Components',  'storage-components',    'SSDs, memory, graphics cards and everything inside the box.', 'assets/images/categories/cat-storage.svg', 'ssd', 11, 0, 1, 'active', 'SSD, RAM & PC Components | ShopInnKart', 'Internal and portable SSDs, memory kits, graphics cards and PC components.'),
(12, NULL, 'Power & Cables',        'power-cables',          'Power banks, fast chargers and durable cables.', 'assets/images/categories/cat-power.svg', 'battery', 12, 0, 1, 'active', 'Power Banks & Chargers | ShopInnKart', 'Fast chargers, GaN adapters, power banks and braided cables.'),
-- sub categories
(13, 1,  'Android Phones',      'android-phones',      NULL, 'assets/images/categories/cat-smartphones.svg', 'smartphone', 1, 0, 1, 'active', NULL, NULL),
(14, 1,  'iPhones',             'iphones',             NULL, 'assets/images/categories/cat-smartphones.svg', 'smartphone', 2, 0, 1, 'active', NULL, NULL),
(15, 1,  'Budget Phones',       'budget-phones',       NULL, 'assets/images/categories/cat-smartphones.svg', 'smartphone', 3, 0, 1, 'active', NULL, NULL),
(16, 2,  'Gaming Laptops',      'gaming-laptops',      NULL, 'assets/images/categories/cat-laptops.svg', 'laptop', 1, 0, 1, 'active', NULL, NULL),
(17, 2,  'Ultrabooks',          'ultrabooks',          NULL, 'assets/images/categories/cat-laptops.svg', 'laptop', 2, 0, 1, 'active', NULL, NULL),
(18, 2,  'Business Laptops',    'business-laptops',    NULL, 'assets/images/categories/cat-laptops.svg', 'laptop', 3, 0, 1, 'active', NULL, NULL),
(19, 5,  'Headphones',          'headphones',          NULL, 'assets/images/categories/cat-audio.svg', 'headphone', 1, 0, 1, 'active', NULL, NULL),
(20, 5,  'True Wireless Earbuds','earbuds',            NULL, 'assets/images/categories/cat-audio.svg', 'earbuds', 2, 0, 1, 'active', NULL, NULL),
(21, 5,  'Speakers & Soundbars','speakers',            NULL, 'assets/images/categories/cat-audio.svg', 'speaker', 3, 0, 1, 'active', NULL, NULL),
(22, 6,  'Smart Watches',       'smart-watches',       NULL, 'assets/images/categories/cat-wearables.svg', 'watch', 1, 0, 1, 'active', NULL, NULL),
(23, 6,  'Fitness Bands',       'fitness-bands',       NULL, 'assets/images/categories/cat-wearables.svg', 'watch', 2, 0, 1, 'active', NULL, NULL),
(24, 7,  'Consoles',            'consoles',            NULL, 'assets/images/categories/cat-gaming.svg', 'gamepad', 1, 0, 1, 'active', NULL, NULL),
(25, 7,  'Gaming Accessories',  'gaming-accessories',  NULL, 'assets/images/categories/cat-gaming.svg', 'gamepad', 2, 0, 1, 'active', NULL, NULL),
(26, 9,  'Monitors',            'monitors',            NULL, 'assets/images/categories/cat-accessories.svg', 'monitor', 1, 0, 1, 'active', NULL, NULL),
(27, 9,  'Keyboards',           'keyboards',           NULL, 'assets/images/categories/cat-accessories.svg', 'keyboard', 2, 0, 1, 'active', NULL, NULL),
(28, 9,  'Mice',                'mice',                NULL, 'assets/images/categories/cat-accessories.svg', 'mouse', 3, 0, 1, 'active', NULL, NULL),
(29, 9,  'Printers',            'printers',            NULL, 'assets/images/categories/cat-accessories.svg', 'printer', 4, 0, 1, 'active', NULL, NULL),
(30, 10, 'Routers & Networking','routers',             NULL, 'assets/images/categories/cat-smarthome.svg', 'router', 1, 0, 1, 'active', NULL, NULL);

-- ---------------------------------------------------------------------------
--  Brands
-- ---------------------------------------------------------------------------
INSERT INTO `brands` (`id`, `name`, `slug`, `logo`, `description`, `sort_order`, `is_featured`, `status`) VALUES
(1,  'Apple',    'apple',    'assets/images/brands/apple.svg',    'Premium smartphones, tablets, laptops and wearables.', 1,  1, 'active'),
(2,  'Samsung',  'samsung',  'assets/images/brands/samsung.svg',  'Smartphones, televisions, tablets and storage.', 2,  1, 'active'),
(3,  'OnePlus',  'oneplus',  'assets/images/brands/oneplus.svg',  'Fast, clean flagship smartphones and audio.', 3,  1, 'active'),
(4,  'Xiaomi',   'xiaomi',   'assets/images/brands/xiaomi.svg',   'Value-first phones, wearables and smart home devices.', 4,  1, 'active'),
(5,  'Google',   'google',   'assets/images/brands/google.svg',   'Pixel smartphones and smart home hardware.', 5,  1, 'active'),
(6,  'Dell',     'dell',     'assets/images/brands/dell.svg',     'Business laptops, monitors and workstations.', 6,  1, 'active'),
(7,  'HP',       'hp',       'assets/images/brands/hp.svg',       'Laptops, printers and everyday computing.', 7,  1, 'active'),
(8,  'Lenovo',   'lenovo',   'assets/images/brands/lenovo.svg',   'ThinkPad, IdeaPad and Legion computing.', 8,  1, 'active'),
(9,  'ASUS',     'asus',     'assets/images/brands/asus.svg',     'Gaming laptops, motherboards and monitors.', 9,  1, 'active'),
(10, 'Sony',     'sony',     'assets/images/brands/sony.svg',     'Audio, cameras and PlayStation gaming.', 10, 1, 'active'),
(11, 'JBL',      'jbl',      'assets/images/brands/jbl.svg',      'Speakers, headphones and party audio.', 11, 1, 'active'),
(12, 'boAt',     'boat',     'assets/images/brands/boat.svg',     'India''s everyday audio and wearables brand.', 12, 1, 'active'),
(13, 'Logitech', 'logitech', 'assets/images/brands/logitech.svg', 'Keyboards, mice, webcams and creator gear.', 13, 1, 'active'),
(14, 'LG',       'lg',       'assets/images/brands/lg.svg',       'OLED televisions, monitors and appliances.', 14, 1, 'active'),
(15, 'Canon',    'canon',    'assets/images/brands/canon.svg',    'Cameras, lenses and printers.', 15, 0, 'active'),
(16, 'Acer',     'acer',     'assets/images/brands/acer.svg',     'Affordable laptops, monitors and gaming.', 16, 0, 'active'),
(17, 'Anker',    'anker',    'assets/images/brands/anker.svg',    'Charging, power banks and cables.', 17, 0, 'active'),
(18, 'TP-Link',  'tp-link',  'assets/images/brands/tp-link.svg',  'Routers, mesh Wi-Fi and networking.', 18, 0, 'active');

-- ---------------------------------------------------------------------------
--  Attributes and values (drive variants and shop filters)
-- ---------------------------------------------------------------------------
INSERT INTO `attributes` (`id`, `name`, `slug`, `type`, `is_variant`, `is_filter`, `sort_order`, `status`) VALUES
(1, 'Colour',      'colour',      'color',  1, 1, 1, 'active'),
(2, 'Storage',     'storage',     'select', 1, 1, 2, 'active'),
(3, 'RAM',         'ram',         'select', 1, 1, 3, 'active'),
(4, 'Size',        'size',        'select', 1, 1, 4, 'active'),
(5, 'Model',       'model',       'select', 1, 0, 5, 'active'),
(6, 'Screen Size', 'screen-size', 'select', 0, 1, 6, 'active'),
(7, 'Processor',   'processor',   'select', 0, 1, 7, 'active'),
(8, 'Warranty',    'warranty',    'select', 0, 1, 8, 'active');

INSERT INTO `attribute_values` (`id`, `attribute_id`, `value`, `slug`, `color_code`, `sort_order`) VALUES
-- colours
(1,  1, 'Midnight Black',  'midnight-black',  '#111827', 1),
(2,  1, 'Titanium Grey',   'titanium-grey',   '#6B7280', 2),
(3,  1, 'Silver',          'silver',          '#D1D5DB', 3),
(4,  1, 'Ocean Blue',      'ocean-blue',      '#1D4ED8', 4),
(5,  1, 'Forest Green',    'forest-green',    '#15803D', 5),
(6,  1, 'Sunset Orange',   'sunset-orange',   '#F4511E', 6),
(7,  1, 'Pearl White',     'pearl-white',     '#F9FAFB', 7),
(8,  1, 'Rose Gold',       'rose-gold',       '#E8B4A0', 8),
-- storage
(9,  2, '64 GB',   '64gb',  NULL, 1),
(10, 2, '128 GB',  '128gb', NULL, 2),
(11, 2, '256 GB',  '256gb', NULL, 3),
(12, 2, '512 GB',  '512gb', NULL, 4),
(13, 2, '1 TB',    '1tb',   NULL, 5),
(14, 2, '2 TB',    '2tb',   NULL, 6),
-- ram
(15, 3, '4 GB',    '4gb',   NULL, 1),
(16, 3, '6 GB',    '6gb',   NULL, 2),
(17, 3, '8 GB',    '8gb',   NULL, 3),
(18, 3, '12 GB',   '12gb',  NULL, 4),
(19, 3, '16 GB',   '16gb',  NULL, 5),
(20, 3, '32 GB',   '32gb',  NULL, 6),
-- size
(21, 4, 'Small',   'small',  NULL, 1),
(22, 4, 'Medium',  'medium', NULL, 2),
(23, 4, 'Large',   'large',  NULL, 3),
(24, 4, '40 mm',   '40mm',   NULL, 4),
(25, 4, '44 mm',   '44mm',   NULL, 5),
(26, 4, '46 mm',   '46mm',   NULL, 6),
-- model
(27, 5, 'Standard',       'standard',       NULL, 1),
(28, 5, 'Pro',            'pro',            NULL, 2),
(29, 5, 'Pro Max',        'pro-max',        NULL, 3),
(30, 5, 'Wi-Fi',          'wifi',           NULL, 4),
(31, 5, 'Wi-Fi + 5G',     'wifi-5g',        NULL, 5),
-- screen size (filter only)
(32, 6, 'Under 6 inch',   'under-6',        NULL, 1),
(33, 6, '6 - 6.5 inch',   '6-to-6-5',       NULL, 2),
(34, 6, 'Above 6.5 inch', 'above-6-5',      NULL, 3),
(35, 6, '13 - 14 inch',   '13-to-14',       NULL, 4),
(36, 6, '15 - 16 inch',   '15-to-16',       NULL, 5),
(37, 6, '43 - 55 inch',   '43-to-55',       NULL, 6),
(38, 6, '55 inch & above','above-55',       NULL, 7),
-- processor (filter only)
(39, 7, 'Snapdragon',     'snapdragon',     NULL, 1),
(40, 7, 'MediaTek',       'mediatek',       NULL, 2),
(41, 7, 'Apple Silicon',  'apple-silicon',  NULL, 3),
(42, 7, 'Intel Core',     'intel-core',     NULL, 4),
(43, 7, 'AMD Ryzen',      'amd-ryzen',      NULL, 5),
-- warranty (filter only)
(44, 8, '6 Months',       '6-months',       NULL, 1),
(45, 8, '1 Year',         '1-year',         NULL, 2),
(46, 8, '2 Years',        '2-years',        NULL, 3),
(47, 8, '3 Years',        '3-years',        NULL, 4);

-- ---------------------------------------------------------------------------
--  Tags
-- ---------------------------------------------------------------------------
INSERT INTO `tags` (`id`, `name`, `slug`) VALUES
(1,'5G','5g'),(2,'Noise Cancelling','noise-cancelling'),(3,'Gaming','gaming'),(4,'4K','4k'),
(5,'Fast Charging','fast-charging'),(6,'Water Resistant','water-resistant'),(7,'Wireless','wireless'),
(8,'Budget Pick','budget-pick'),(9,'Premium','premium'),(10,'Creator','creator'),
(11,'Work From Home','work-from-home'),(12,'Student','student');

-- ---------------------------------------------------------------------------
--  Products (44 demo SKUs across every category)
--  Images are locally generated SVG placeholders - no third-party artwork.
-- ---------------------------------------------------------------------------
INSERT INTO `products`
(`id`,`name`,`slug`,`sku`,`brand_id`,`category_id`,`short_description`,`description`,`price`,`sale_price`,`cost_price`,`stock`,`weight`,`warranty`,`emi_text`,`manufacturer`,`model_number`,`main_image`,`hover_image`,`badge_text`,`status`,`is_featured`,`is_new_arrival`,`is_best_seller`,`is_trending`,`has_variants`,`views`,`sold_count`,`rating_avg`,`rating_count`,`meta_title`,`meta_description`) VALUES

-- ---- Smartphones -----------------------------------------------------------
(1,'Apple iPhone 16 Pro','apple-iphone-16-pro','SIK-PHN-1601',1,14,'6.3-inch Super Retina XDR, A18 Pro chip and a 48MP Fusion camera system.','The iPhone 16 Pro pairs a titanium frame with the A18 Pro chip for console-class gaming and all-day battery life. The 48MP Fusion camera captures 4K120 Dolby Vision video, while the Camera Control button puts framing, zoom and depth at your thumb. Ships with India warranty and supports 5G on every major network.',134900.00,124999.00,108000.00,42,0.199,'1 Year Apple India Warranty','EMI from ₹5,899/month','Apple Inc.','A3101','assets/images/placeholders/device-smartphone.svg','assets/images/placeholders/device-smartphone-alt.svg',NULL,'active',1,1,1,1,1,2480,318,4.80,164,'Apple iPhone 16 Pro Price in India | ShopInnKart','Buy the Apple iPhone 16 Pro with A18 Pro chip, 48MP Fusion camera and titanium design. Official warranty, EMI and fast delivery.'),

(2,'Apple iPhone 16','apple-iphone-16','SIK-PHN-1602',1,14,'6.1-inch display, A18 chip, dual 48MP camera and Camera Control.','iPhone 16 brings the A18 chip, a 48MP Fusion main camera and the new Camera Control to the standard model. Battery life stretches through a long day, and the aluminium body comes in five finishes. Supports fast charging and MagSafe accessories.',79900.00,74999.00,64000.00,68,0.170,'1 Year Apple India Warranty','EMI from ₹3,542/month','Apple Inc.','A3081','assets/images/placeholders/device-smartphone.svg','assets/images/placeholders/device-smartphone-alt.svg',NULL,'active',1,1,1,0,1,1930,402,4.70,221,'Apple iPhone 16 Price in India | ShopInnKart','Buy the Apple iPhone 16 with A18 chip and 48MP dual camera. Genuine warranty, no-cost EMI and free delivery.'),

(3,'Samsung Galaxy S25 Ultra','samsung-galaxy-s25-ultra','SIK-PHN-2501',2,13,'6.9-inch QHD+ AMOLED, 200MP quad camera and built-in S Pen.','The Galaxy S25 Ultra is Samsung''s most capable phone: a 6.9-inch QHD+ Dynamic AMOLED 2X panel at 120Hz, a 200MP wide camera with 5x optical telephoto, and the S Pen tucked into the frame. Galaxy AI handles live translation, note summaries and generative photo edits on device.',129999.00,114999.00,99000.00,35,0.232,'1 Year Samsung India Warranty','EMI from ₹5,428/month','Samsung Electronics','SM-S938B','assets/images/placeholders/device-smartphone.svg','assets/images/placeholders/device-smartphone-alt.svg',NULL,'active',1,1,1,1,1,3120,289,4.75,198,'Samsung Galaxy S25 Ultra Price in India | ShopInnKart','Buy the Galaxy S25 Ultra with 200MP camera, S Pen and Galaxy AI. Official warranty and fast delivery.'),

(4,'Samsung Galaxy S25','samsung-galaxy-s25','SIK-PHN-2502',2,13,'6.2-inch FHD+ AMOLED 120Hz with a 50MP triple camera.','A compact flagship that fits the hand without giving up performance. The Galaxy S25 runs the latest Snapdragon platform, shoots 8K video and charges to 65% in half an hour. Four generations of OS upgrades are included.',79999.00,69999.00,60000.00,54,0.168,'1 Year Samsung India Warranty','EMI from ₹3,304/month','Samsung Electronics','SM-S931B','assets/images/placeholders/device-smartphone.svg','assets/images/placeholders/device-smartphone-alt.svg',NULL,'active',0,1,1,0,1,1640,356,4.60,187,'Samsung Galaxy S25 Price in India | ShopInnKart','Buy the compact Samsung Galaxy S25 flagship with 120Hz AMOLED and 50MP triple camera.'),

(5,'OnePlus 13','oneplus-13','SIK-PHN-3001',3,13,'6.82-inch 2K LTPO AMOLED, 6000mAh battery and 100W SuperVOOC.','OnePlus 13 keeps the brand''s reputation for speed: a 2K 120Hz LTPO panel, the newest Snapdragon flagship silicon, and a 6000mAh battery that refills in about 36 minutes with the bundled 100W charger. Hasselblad tuning covers all three rear cameras.',69999.00,61999.00,53000.00,47,0.210,'1 Year OnePlus India Warranty','EMI from ₹2,927/month','OnePlus Technology','CPH2649','assets/images/placeholders/device-smartphone.svg','assets/images/placeholders/device-smartphone-alt.svg',NULL,'active',1,1,0,1,1,2210,241,4.65,143,'OnePlus 13 Price in India | ShopInnKart','Buy OnePlus 13 with 2K LTPO display, 6000mAh battery and 100W fast charging.'),

(6,'OnePlus Nord 4','oneplus-nord-4','SIK-PHN-3002',3,15,'Metal unibody, 5500mAh battery and 100W charging under ₹30,000.','Nord 4 puts a full metal unibody on a mid-range phone. The 6.74-inch 120Hz AMOLED is bright enough for direct sun, and the 5500mAh battery comfortably clears a day and a half of mixed use.',32999.00,27999.00,23500.00,96,0.199,'1 Year OnePlus India Warranty','EMI from ₹1,322/month','OnePlus Technology','CPH2607','assets/images/placeholders/device-smartphone.svg','assets/images/placeholders/device-smartphone-alt.svg','BUDGET PICK','active',0,0,1,1,1,1420,588,4.45,312,'OnePlus Nord 4 Price in India | ShopInnKart','Buy OnePlus Nord 4 with metal unibody, 120Hz AMOLED and 100W fast charging.'),

(7,'Google Pixel 9 Pro','google-pixel-9-pro','SIK-PHN-4001',5,13,'Tensor G4, 50MP triple camera and seven years of updates.','Pixel 9 Pro is the photography phone: a 50MP main sensor, 48MP 5x telephoto and Google''s computational stack for Night Sight, Magic Editor and Best Take. Seven years of OS and security updates are guaranteed.',109999.00,94999.00,82000.00,28,0.221,'1 Year Google India Warranty','EMI from ₹4,485/month','Google LLC','GA05556','assets/images/placeholders/device-smartphone.svg','assets/images/placeholders/device-smartphone-alt.svg',NULL,'active',1,1,0,0,1,1780,167,4.70,121,'Google Pixel 9 Pro Price in India | ShopInnKart','Buy Google Pixel 9 Pro with Tensor G4, 50MP triple camera and 7 years of updates.'),

(8,'Xiaomi 14 Ultra','xiaomi-14-ultra','SIK-PHN-5001',4,13,'Leica quad camera with a 1-inch main sensor and variable aperture.','Built for people who shoot everything. The 1-inch LYT-900 sensor has a stepless variable aperture from f/1.63 to f/4.0, and all four Leica lenses record 8K. A 5300mAh battery supports 90W wired and 80W wireless charging.',89999.00,79999.00,68000.00,22,0.224,'1 Year Xiaomi India Warranty','EMI from ₹3,777/month','Xiaomi Corporation','2405CPX3DI','assets/images/placeholders/device-smartphone.svg','assets/images/placeholders/device-smartphone-alt.svg',NULL,'active',0,1,0,1,1,1350,132,4.55,96,'Xiaomi 14 Ultra Price in India | ShopInnKart','Buy Xiaomi 14 Ultra with Leica quad camera, 1-inch sensor and 90W charging.'),

(9,'Redmi Note 14 Pro+','redmi-note-14-pro-plus','SIK-PHN-5002',4,15,'200MP OIS camera, curved AMOLED and 120W HyperCharge.','The Note series staple, upgraded. A 200MP main camera with optical stabilisation, a 1.5K curved AMOLED and 120W charging that fills the 5110mAh cell in around 20 minutes.',29999.00,24999.00,20500.00,140,0.205,'1 Year Xiaomi India Warranty','EMI from ₹1,180/month','Xiaomi Corporation','24090RA29I','assets/images/placeholders/device-smartphone.svg','assets/images/placeholders/device-smartphone-alt.svg','BEST VALUE','active',0,0,1,1,1,2640,921,4.40,547,'Redmi Note 14 Pro+ Price in India | ShopInnKart','Buy Redmi Note 14 Pro+ with 200MP OIS camera and 120W HyperCharge.'),

-- ---- Laptops ---------------------------------------------------------------
(10,'Apple MacBook Air 13-inch M3','apple-macbook-air-13-m3','SIK-LAP-1001',1,17,'M3 chip, 18-hour battery and a fanless 1.24kg chassis.','The MacBook Air with M3 is silent, cool and fast enough for photo editing, code compiles and a dozen browser tabs at once. The Liquid Retina display covers P3, and two external monitors are supported with the lid closed.',114900.00,99990.00,88000.00,31,1.240,'1 Year Apple India Warranty','EMI from ₹4,721/month','Apple Inc.','MRXN3HN/A','assets/images/placeholders/device-laptop.svg','assets/images/placeholders/device-laptop-alt.svg',NULL,'active',1,1,1,1,1,2890,214,4.85,178,'MacBook Air M3 13-inch Price in India | ShopInnKart','Buy the Apple MacBook Air 13-inch with M3 chip, 18-hour battery and fanless design.'),

(11,'Apple MacBook Pro 14-inch M4','apple-macbook-pro-14-m4','SIK-LAP-1002',1,17,'M4 chip, Liquid Retina XDR and 120Hz ProMotion.','For sustained heavy work: colour-managed XDR display at 1600 nits peak, three Thunderbolt 4 ports, HDMI, SDXC and a battery that still lasts most of a working day under load.',169900.00,159900.00,142000.00,18,1.550,'1 Year Apple India Warranty','EMI from ₹7,551/month','Apple Inc.','MW2U3HN/A','assets/images/placeholders/device-laptop.svg','assets/images/placeholders/device-laptop-alt.svg','PRO','active',1,1,0,0,1,1560,86,4.90,71,'MacBook Pro 14-inch M4 Price in India | ShopInnKart','Buy the Apple MacBook Pro 14-inch with M4 chip and Liquid Retina XDR display.'),

(12,'Dell XPS 14','dell-xps-14','SIK-LAP-2001',6,17,'14.5-inch OLED touch, Intel Core Ultra 7 and RTX 4050.','A creator laptop in a CNC aluminium shell. The 3.2K OLED touch panel is factory calibrated, and the Core Ultra 7 with RTX 4050 handles Premiere timelines and Blender scenes without throttling.',149990.00,132990.00,118000.00,16,1.680,'1 Year Dell Onsite Warranty','EMI from ₹6,281/month','Dell Technologies','XPS9440','assets/images/placeholders/device-laptop.svg','assets/images/placeholders/device-laptop-alt.svg',NULL,'active',1,0,0,1,1,1120,64,4.60,49,'Dell XPS 14 Price in India | ShopInnKart','Buy Dell XPS 14 with 3.2K OLED touch display, Core Ultra 7 and RTX 4050.'),

(13,'Dell Inspiron 15','dell-inspiron-15','SIK-LAP-2002',6,18,'15.6-inch FHD, Core i5 and a full-size keyboard with numpad.','A dependable everyday laptop for study and office work. Dual storage slots, a comfortable keyboard with numeric pad, and enough battery for a full lecture day.',64990.00,52990.00,45000.00,74,1.860,'1 Year Dell Onsite Warranty','EMI from ₹2,502/month','Dell Technologies','3530','assets/images/placeholders/device-laptop.svg','assets/images/placeholders/device-laptop-alt.svg',NULL,'active',0,0,1,0,1,980,287,4.30,212,'Dell Inspiron 15 Price in India | ShopInnKart','Buy Dell Inspiron 15 with Core i5, FHD display and full-size keyboard.'),

(14,'HP Pavilion Plus 14','hp-pavilion-plus-14','SIK-LAP-3001',7,17,'2.8K OLED, Core i5 and a 1.4kg travel weight.','A 2.8K OLED panel at this price is unusual. Add a backlit keyboard, Wi-Fi 6E and a 1.4kg chassis, and it makes a strong case as a daily driver for students and hybrid workers.',79999.00,66999.00,57000.00,45,1.410,'1 Year HP India Warranty','EMI from ₹3,163/month','HP Inc.','14-ew1015TU','assets/images/placeholders/device-laptop.svg','assets/images/placeholders/device-laptop-alt.svg',NULL,'active',0,1,0,0,1,860,143,4.45,98,'HP Pavilion Plus 14 Price in India | ShopInnKart','Buy HP Pavilion Plus 14 with 2.8K OLED display and Intel Core i5.'),

(15,'Lenovo IdeaPad Slim 5','lenovo-ideapad-slim-5','SIK-LAP-4001',8,18,'AMD Ryzen 7, 16GB RAM and a 14-inch WUXGA display.','Ryzen 7 performance with 16GB of memory as standard, in a metal-topped 1.46kg body. Rapid Charge takes the battery to 80% in an hour.',69990.00,54990.00,47000.00,58,1.460,'1 Year Lenovo Warranty','EMI from ₹2,596/month','Lenovo Group','14ABR8','assets/images/placeholders/device-laptop.svg','assets/images/placeholders/device-laptop-alt.svg',NULL,'active',0,0,1,0,1,740,196,4.35,151,'Lenovo IdeaPad Slim 5 Price in India | ShopInnKart','Buy Lenovo IdeaPad Slim 5 with Ryzen 7, 16GB RAM and WUXGA display.'),

(16,'ASUS ROG Strix G16','asus-rog-strix-g16','SIK-LAP-5001',9,16,'16-inch 165Hz, Core i7 and GeForce RTX 4060.','Built for frame rates. A 165Hz 2.5K panel, RTX 4060 with a 140W total graphics power, and a tri-fan cooling system with liquid metal on the CPU.',154990.00,134990.00,117000.00,21,2.500,'1 Year ASUS India Warranty','EMI from ₹6,374/month','ASUSTeK Computer','G614JVR','assets/images/placeholders/device-laptop.svg','assets/images/placeholders/device-laptop-alt.svg','GAMING','active',1,1,0,1,1,1980,97,4.65,83,'ASUS ROG Strix G16 Gaming Laptop | ShopInnKart','Buy ASUS ROG Strix G16 with 165Hz display, Core i7 and RTX 4060 graphics.'),

(17,'Acer Nitro V15','acer-nitro-v15','SIK-LAP-6001',16,16,'RTX 4050 gaming laptop with a 144Hz FHD display.','An entry point into 1080p gaming that does not feel like a compromise. RTX 4050 graphics, a 144Hz panel and dual-fan cooling with four exhaust vents.',79999.00,67999.00,58000.00,39,2.100,'1 Year Acer India Warranty','EMI from ₹3,211/month','Acer Inc.','ANV15-51','assets/images/placeholders/device-laptop.svg','assets/images/placeholders/device-laptop-alt.svg',NULL,'active',0,0,1,1,1,1240,178,4.25,134,'Acer Nitro V15 Gaming Laptop | ShopInnKart','Buy Acer Nitro V15 with RTX 4050 graphics and a 144Hz FHD display.'),

-- ---- Tablets ---------------------------------------------------------------
(18,'Apple iPad Air 11-inch M2','apple-ipad-air-11-m2','SIK-TAB-1001',1,3,'M2 chip, Liquid Retina display and Apple Pencil Pro support.','The iPad Air with M2 is fast enough for Procreate, Final Cut and split-screen work. Apple Pencil Pro adds squeeze gestures and barrel roll for illustration.',59900.00,54900.00,47000.00,44,0.462,'1 Year Apple India Warranty','EMI from ₹2,592/month','Apple Inc.','MUWC3HN/A','assets/images/placeholders/device-tablet.svg','assets/images/placeholders/device-tablet-alt.svg',NULL,'active',1,1,0,0,1,1340,158,4.75,112,'Apple iPad Air 11-inch M2 Price in India | ShopInnKart','Buy the iPad Air 11-inch with M2 chip and Apple Pencil Pro support.'),

(19,'Samsung Galaxy Tab S10','samsung-galaxy-tab-s10','SIK-TAB-2001',2,3,'11-inch 120Hz AMOLED with S Pen included.','A tablet that doubles as a notebook. The 120Hz AMOLED is easy on the eyes for long reading sessions, and the S Pen in the box has near-zero latency for handwriting.',74999.00,64999.00,55000.00,33,0.498,'1 Year Samsung India Warranty','EMI from ₹3,069/month','Samsung Electronics','SM-X820','assets/images/placeholders/device-tablet.svg','assets/images/placeholders/device-tablet-alt.svg',NULL,'active',0,1,0,0,1,910,121,4.55,88,'Samsung Galaxy Tab S10 Price in India | ShopInnKart','Buy Samsung Galaxy Tab S10 with 120Hz AMOLED display and bundled S Pen.'),

-- ---- Televisions -----------------------------------------------------------
(20,'Samsung 55-inch Crystal 4K Smart TV','samsung-55-crystal-4k','SIK-TV-2001',2,4,'55-inch 4K UHD with Crystal Processor and Tizen OS.','A well-rounded living room television. The Crystal Processor upscales HD sources cleanly, and Tizen carries every major streaming app in India. Includes free wall installation.',64990.00,44990.00,38000.00,26,15.200,'1 Year Comprehensive + 1 Year Panel','EMI from ₹2,124/month','Samsung Electronics','UA55DUE70AKLXL','assets/images/placeholders/device-tv.svg','assets/images/placeholders/device-tv-alt.svg','31% OFF','active',1,0,1,1,0,2140,264,4.40,196,'Samsung 55-inch Crystal 4K Smart TV | ShopInnKart','Buy the Samsung 55-inch Crystal 4K Smart TV with Tizen OS and free installation.'),

(21,'LG 55-inch OLED evo C4','lg-55-oled-evo-c4','SIK-TV-3001',14,4,'55-inch OLED evo, 144Hz and Dolby Vision for gaming and film.','Perfect blacks, per-pixel contrast and a 144Hz panel with four HDMI 2.1 ports make this the enthusiast pick for both film and console gaming.',159990.00,124990.00,108000.00,12,17.600,'1 Year Comprehensive + 3 Year Panel','EMI from ₹5,901/month','LG Electronics','OLED55C4PSA','assets/images/placeholders/device-tv.svg','assets/images/placeholders/device-tv-alt.svg','OLED','active',1,1,0,1,0,1670,74,4.85,61,'LG 55-inch OLED evo C4 Price in India | ShopInnKart','Buy the LG C4 55-inch OLED evo TV with 144Hz, Dolby Vision and HDMI 2.1.'),

(22,'Sony Bravia 65-inch 4K Google TV','sony-bravia-65-4k','SIK-TV-4001',10,4,'65-inch 4K with Cognitive Processor XR and Google TV.','Sony''s processing is the draw here - motion handling and skin tones look natural rather than over-sharpened. Google TV brings a clean interface with hands-free voice search.',129990.00,104990.00,91000.00,15,21.400,'1 Year Comprehensive Warranty','EMI from ₹4,957/month','Sony Corporation','K-65S30B','assets/images/placeholders/device-tv.svg','assets/images/placeholders/device-tv-alt.svg',NULL,'active',0,0,1,0,0,1290,89,4.60,72,'Sony Bravia 65-inch 4K Google TV | ShopInnKart','Buy the Sony Bravia 65-inch 4K Google TV with Cognitive Processor XR.'),

-- ---- Audio -----------------------------------------------------------------
(23,'Sony WH-1000XM5 Wireless Headphones','sony-wh-1000xm5','SIK-AUD-4001',10,19,'Industry-leading noise cancellation with 30-hour battery.','Eight microphones and two processors handle noise cancellation across the full frequency range. Speak-to-Chat pauses playback when you start talking, and a three-minute charge gives three hours of listening.',29990.00,24990.00,20500.00,88,0.250,'1 Year Sony India Warranty','EMI from ₹1,180/month','Sony Corporation','WH1000XM5','assets/images/placeholders/device-headphone.svg','assets/images/placeholders/device-headphone-alt.svg','TOP RATED','active',1,0,1,1,1,3480,612,4.80,489,'Sony WH-1000XM5 Price in India | ShopInnKart','Buy Sony WH-1000XM5 noise cancelling headphones with 30-hour battery life.'),

(24,'Apple AirPods Pro 2 (USB-C)','apple-airpods-pro-2','SIK-AUD-1001',1,20,'Adaptive Audio, Transparency mode and USB-C charging.','The H2 chip drives Adaptive Audio, which blends transparency and noise cancellation on the fly. Conversation Awareness lowers volume when you speak, and the case is IP54 rated.',24900.00,21900.00,18000.00,120,0.061,'1 Year Apple India Warranty','EMI from ₹1,034/month','Apple Inc.','MTJV3HN/A','assets/images/placeholders/device-earbuds.svg','assets/images/placeholders/device-earbuds-alt.svg',NULL,'active',1,0,1,1,0,3960,748,4.75,563,'Apple AirPods Pro 2 USB-C Price in India | ShopInnKart','Buy AirPods Pro 2 with USB-C, Adaptive Audio and Active Noise Cancellation.'),

(25,'JBL Tune 770NC Wireless Headphones','jbl-tune-770nc','SIK-AUD-5001',11,19,'Adaptive noise cancelling with 70 hours of playback.','Seventy hours without ANC, forty-four with it on. JBL Pure Bass tuning, multi-point pairing to two devices, and a folding design that survives a backpack.',9999.00,6999.00,5400.00,156,0.220,'1 Year JBL India Warranty',NULL,'Harman International','JBLT770NC','assets/images/placeholders/device-headphone.svg','assets/images/placeholders/device-headphone-alt.svg',NULL,'active',0,0,1,0,1,1820,834,4.35,612,'JBL Tune 770NC Price in India | ShopInnKart','Buy JBL Tune 770NC wireless headphones with adaptive ANC and 70-hour battery.'),

(26,'boAt Airdopes 191G TWS Earbuds','boat-airdopes-191g','SIK-AUD-6001',12,20,'Low-latency gaming mode with 45 hours of total playback.','A dependable budget pair with a 45-hour case, ENx clear calling and a 40ms low latency mode for gaming. IPX4 splash resistance covers workouts and commutes.',4490.00,1299.00,850.00,420,0.048,'1 Year boAt Warranty',NULL,'Imagine Marketing Ltd.','ADP191G','assets/images/placeholders/device-earbuds.svg','assets/images/placeholders/device-earbuds-alt.svg','BUDGET PICK','active',0,0,1,1,0,4210,1876,4.20,1342,'boAt Airdopes 191G Price in India | ShopInnKart','Buy boAt Airdopes 191G TWS earbuds with gaming mode and 45-hour playback.'),

(27,'JBL Flip 6 Portable Speaker','jbl-flip-6','SIK-AUD-5002',11,21,'IP67 waterproof Bluetooth speaker with racetrack driver.','A two-way system with a dedicated tweeter gives the Flip 6 clearer vocals than most speakers this size. IP67 means it survives rain, sand and a dunk in the pool.',11999.00,8999.00,7000.00,94,0.550,'1 Year JBL India Warranty',NULL,'Harman International','JBLFLIP6','assets/images/placeholders/device-speaker.svg','assets/images/placeholders/device-speaker-alt.svg',NULL,'active',0,0,1,0,1,1540,421,4.55,338,'JBL Flip 6 Price in India | ShopInnKart','Buy the JBL Flip 6 IP67 waterproof portable Bluetooth speaker.'),

(28,'Sony HT-S2000 Soundbar','sony-ht-s2000','SIK-AUD-4002',10,21,'3.1ch Dolby Atmos soundbar with a built-in subwoofer.','Dual built-in subwoofers mean no separate box under the sofa. Vertical Surround Engine and S-Force Pro Front Surround widen the stage well beyond the bar itself.',39990.00,29990.00,25000.00,37,3.200,'1 Year Sony India Warranty','EMI from ₹1,416/month','Sony Corporation','HT-S2000','assets/images/placeholders/device-soundbar.svg','assets/images/placeholders/device-soundbar-alt.svg',NULL,'active',0,1,0,0,0,720,112,4.50,84,'Sony HT-S2000 Soundbar Price in India | ShopInnKart','Buy the Sony HT-S2000 3.1ch Dolby Atmos soundbar with built-in subwoofer.'),

-- ---- Wearables -------------------------------------------------------------
(29,'Apple Watch Series 10','apple-watch-series-10','SIK-WER-1001',1,22,'Thinnest Apple Watch yet with a wide-angle OLED display.','Series 10 is noticeably thinner and lighter, with a wide-angle OLED that stays readable from an angle. Sleep apnoea notifications, depth and water temperature sensing are new this generation.',46900.00,42900.00,36000.00,52,0.042,'1 Year Apple India Warranty','EMI from ₹2,025/month','Apple Inc.','MWX53HN/A','assets/images/placeholders/device-watch.svg','assets/images/placeholders/device-watch-alt.svg',NULL,'active',1,1,1,1,1,2160,246,4.70,189,'Apple Watch Series 10 Price in India | ShopInnKart','Buy Apple Watch Series 10 with wide-angle OLED and sleep apnoea notifications.'),

(30,'Samsung Galaxy Watch 7','samsung-galaxy-watch-7','SIK-WER-2001',2,22,'BioActive sensor with energy score and dual-frequency GPS.','The upgraded BioActive sensor tracks advanced glycation end products alongside heart rate and body composition. Dual-frequency GPS keeps runs accurate between tall buildings.',31999.00,25999.00,21500.00,66,0.034,'1 Year Samsung India Warranty','EMI from ₹1,227/month','Samsung Electronics','SM-L305','assets/images/placeholders/device-watch.svg','assets/images/placeholders/device-watch-alt.svg',NULL,'active',0,1,1,0,1,1180,298,4.45,224,'Samsung Galaxy Watch 7 Price in India | ShopInnKart','Buy Samsung Galaxy Watch 7 with BioActive sensor and dual-frequency GPS.'),

(31,'boAt Wave Sigma 3 Smart Watch','boat-wave-sigma-3','SIK-WER-6001',12,23,'2.01-inch HD display with Bluetooth calling and 100+ sport modes.','A large, bright display with functional Bluetooth calling at an entry price. Seven days of battery on typical use, and over a hundred tracked activities.',4499.00,1799.00,1150.00,380,0.052,'1 Year boAt Warranty',NULL,'Imagine Marketing Ltd.','WVSIGMA3','assets/images/placeholders/device-watch.svg','assets/images/placeholders/device-watch-alt.svg',NULL,'active',0,0,1,1,0,2870,1493,4.10,1087,'boAt Wave Sigma 3 Price in India | ShopInnKart','Buy boAt Wave Sigma 3 smart watch with 2.01-inch display and Bluetooth calling.'),

-- ---- Gaming ----------------------------------------------------------------
(32,'Sony PlayStation 5 Slim (Disc Edition)','sony-playstation-5-slim','SIK-GAM-4001',10,24,'1TB PS5 Slim with the DualSense wireless controller.','The slimmer PS5 keeps the full disc drive and adds a 1TB SSD. DualSense haptics and adaptive triggers remain the standout - you feel the difference between a bowstring and a trigger pull.',54990.00,49990.00,43000.00,29,3.200,'1 Year Sony India Warranty','EMI from ₹2,360/month','Sony Interactive Entertainment','CFI-2008A','assets/images/placeholders/device-console.svg','assets/images/placeholders/device-console-alt.svg','HOT','active',1,0,1,1,0,4680,187,4.85,143,'Sony PlayStation 5 Slim Price in India | ShopInnKart','Buy the PlayStation 5 Slim Disc Edition with 1TB storage and DualSense controller.'),

(33,'Logitech G502 X Gaming Mouse','logitech-g502-x','SIK-GAM-7001',13,25,'HERO 25K sensor with LIGHTFORCE hybrid switches.','Thirteen programmable controls and a 25,600 DPI sensor, with hybrid optical-mechanical switches that click crisply and last far longer than a mechanical-only design.',8995.00,5995.00,4700.00,112,0.102,'2 Year Logitech Warranty',NULL,'Logitech International','910-006140','assets/images/placeholders/device-mouse.svg','assets/images/placeholders/device-mouse-alt.svg',NULL,'active',0,0,1,0,1,1360,384,4.60,271,'Logitech G502 X Gaming Mouse | ShopInnKart','Buy the Logitech G502 X gaming mouse with HERO 25K sensor and LIGHTFORCE switches.'),

-- ---- Cameras ---------------------------------------------------------------
(34,'Canon EOS R50 Mirrorless Camera','canon-eos-r50','SIK-CAM-8001',15,8,'24.2MP APS-C mirrorless with 4K30 and Dual Pixel AF II.','A small, light body that autofocuses on eyes, faces and animals with the same system Canon puts in its professional cameras. Uncropped 4K30 comes from the full 6K sensor readout.',74995.00,64995.00,55000.00,24,0.375,'2 Year Canon India Warranty','EMI from ₹3,069/month','Canon Inc.','EOSR50-RUK','assets/images/placeholders/device-camera.svg','assets/images/placeholders/device-camera-alt.svg',NULL,'active',1,0,0,0,1,890,76,4.65,58,'Canon EOS R50 Price in India | ShopInnKart','Buy the Canon EOS R50 mirrorless camera with 24.2MP sensor and 4K30 video.'),

(35,'Sony ZV-E10 II Vlogging Camera','sony-zv-e10-ii','SIK-CAM-4001',10,8,'26MP APS-C vlogging camera with 4K60 and a side-flip screen.','Purpose-built for creators: a large three-capsule microphone, Product Showcase mode, background defocus at a button press, and 4K60 from the full sensor width.',89990.00,79990.00,68000.00,19,0.377,'2 Year Sony India Warranty','EMI from ₹3,777/month','Sony Corporation','ZV-E10M2L','assets/images/placeholders/device-camera.svg','assets/images/placeholders/device-camera-alt.svg','CREATOR','active',1,1,0,1,1,1040,58,4.70,44,'Sony ZV-E10 II Price in India | ShopInnKart','Buy the Sony ZV-E10 II vlogging camera with 26MP sensor and 4K60 video.'),

-- ---- Computer accessories --------------------------------------------------
(36,'Dell 27-inch 4K USB-C Monitor','dell-27-4k-monitor','SIK-ACC-2001',6,26,'27-inch 4K IPS with 90W USB-C power delivery.','One cable carries video, data and 90W of charging to a laptop. The IPS Black panel doubles contrast over standard IPS, which shows in dark UI work and photo editing.',42999.00,33999.00,28500.00,41,6.400,'3 Year Dell Advanced Exchange','EMI from ₹1,605/month','Dell Technologies','U2724D','assets/images/placeholders/device-monitor.svg','assets/images/placeholders/device-monitor-alt.svg',NULL,'active',0,0,1,0,0,1120,164,4.55,126,'Dell 27-inch 4K USB-C Monitor | ShopInnKart','Buy the Dell 27-inch 4K IPS monitor with 90W USB-C power delivery.'),

(37,'Logitech MX Keys S Wireless Keyboard','logitech-mx-keys-s','SIK-ACC-7001',13,27,'Backlit low-profile keyboard with Smart Actions.','Spherically dished keys and proximity-sensing backlighting make long typing sessions comfortable. Pairs with three devices and switches between them with one key.',11995.00,9495.00,7800.00,78,0.810,'1 Year Logitech Warranty',NULL,'Logitech International','920-011588','assets/images/placeholders/device-keyboard.svg','assets/images/placeholders/device-keyboard-alt.svg',NULL,'active',0,0,1,0,1,940,241,4.65,183,'Logitech MX Keys S Price in India | ShopInnKart','Buy the Logitech MX Keys S wireless backlit keyboard with Smart Actions.'),

(38,'Logitech MX Master 3S Mouse','logitech-mx-master-3s','SIK-ACC-7002',13,28,'8K DPI sensor with near-silent clicks and MagSpeed scrolling.','MagSpeed scrolling covers a thousand lines a second and stops on a pixel. Quiet Clicks cut the noise by ninety percent, which matters in shared offices.',10995.00,8495.00,7000.00,86,0.141,'1 Year Logitech Warranty',NULL,'Logitech International','910-006561','assets/images/placeholders/device-mouse.svg','assets/images/placeholders/device-mouse-alt.svg','TOP RATED','active',1,0,1,1,1,1680,392,4.80,314,'Logitech MX Master 3S Price in India | ShopInnKart','Buy the Logitech MX Master 3S with 8K DPI sensor and quiet clicks.'),

(39,'HP Smart Tank 580 All-in-One Printer','hp-smart-tank-580','SIK-ACC-3001',7,29,'Refillable ink tank printer with wireless printing.','Ink tanks instead of cartridges bring the cost per page down dramatically - the bundled bottles cover roughly six thousand colour pages. Prints, scans and copies over Wi-Fi.',18999.00,14999.00,12500.00,48,5.200,'1 Year HP India Warranty',NULL,'HP Inc.','1F3Y2A','assets/images/placeholders/device-printer.svg','assets/images/placeholders/device-printer-alt.svg',NULL,'active',0,0,0,0,0,610,97,4.30,79,'HP Smart Tank 580 Printer Price in India | ShopInnKart','Buy the HP Smart Tank 580 all-in-one ink tank printer with wireless printing.'),

-- ---- Smart home ------------------------------------------------------------
(40,'TP-Link Deco X50 Mesh Wi-Fi 6 (2-pack)','tp-link-deco-x50','SIK-NET-8001',18,30,'AX3000 mesh system covering up to 4,000 sq ft.','Two units blanket a typical three-bedroom home with a single network name. HomeShield handles parental controls and device quarantine, and each unit has three gigabit ports.',18999.00,13999.00,11500.00,63,0.680,'3 Year TP-Link Warranty',NULL,'TP-Link Technologies','Deco X50','assets/images/placeholders/device-router.svg','assets/images/placeholders/device-router-alt.svg',NULL,'active',0,0,1,0,1,780,203,4.50,157,'TP-Link Deco X50 Mesh Wi-Fi 6 | ShopInnKart','Buy the TP-Link Deco X50 AX3000 mesh Wi-Fi 6 system covering 4,000 sq ft.'),

-- ---- Storage ---------------------------------------------------------------
(41,'Samsung 990 PRO 1TB NVMe SSD','samsung-990-pro-1tb','SIK-STO-2001',2,11,'PCIe 4.0 NVMe SSD reading at up to 7,450 MB/s.','Near the ceiling of what PCIe 4.0 allows. Sequential reads hit 7,450 MB/s, and the nickel-coated controller with a heat-spreader label keeps sustained writes from throttling.',14999.00,9999.00,8200.00,134,0.009,'5 Year Samsung Warranty',NULL,'Samsung Electronics','MZ-V9P1T0BW','assets/images/placeholders/device-ssd.svg','assets/images/placeholders/device-ssd-alt.svg',NULL,'active',0,0,1,1,1,1460,467,4.75,352,'Samsung 990 PRO 1TB SSD Price in India | ShopInnKart','Buy the Samsung 990 PRO 1TB PCIe 4.0 NVMe SSD with 7,450 MB/s read speed.'),

(42,'Samsung T7 Shield 2TB Portable SSD','samsung-t7-shield-2tb','SIK-STO-2002',2,11,'IP65 rated portable SSD at 1,050 MB/s.','A rubberised shell that shrugs off three-metre drops and IP65 dust and water exposure. Useful for shooting on location where a bare drive would not survive.',21999.00,15999.00,13200.00,72,0.098,'3 Year Samsung Warranty',NULL,'Samsung Electronics','MU-PE2T0S','assets/images/placeholders/device-ssd.svg','assets/images/placeholders/device-ssd-alt.svg',NULL,'active',0,1,0,0,1,680,148,4.65,112,'Samsung T7 Shield 2TB Price in India | ShopInnKart','Buy the Samsung T7 Shield 2TB rugged portable SSD with IP65 protection.'),

-- ---- Power -----------------------------------------------------------------
(43,'Anker 737 Power Bank 24,000mAh','anker-737-power-bank','SIK-PWR-9001',17,12,'140W output with a smart display, charges laptops.','Enough output to charge a MacBook Pro at full speed, and the display shows exact wattage in and out. Three ports let a laptop, phone and watch share the pack.',14999.00,10999.00,9000.00,91,0.630,'18 Month Anker Warranty',NULL,'Anker Innovations','A1289','assets/images/placeholders/device-powerbank.svg','assets/images/placeholders/device-powerbank-alt.svg',NULL,'active',0,0,1,0,0,830,214,4.60,168,'Anker 737 Power Bank Price in India | ShopInnKart','Buy the Anker 737 24,000mAh power bank with 140W output and smart display.'),

(44,'Anker 65W GaN Fast Charger','anker-65w-gan-charger','SIK-PWR-9002',17,12,'Compact 3-port GaN charger for laptop, phone and earbuds.','GaN lets this charger stay smaller than a stock laptop brick while delivering 65W. Two USB-C ports and one USB-A cover almost every device in a bag.',4999.00,2999.00,2300.00,215,0.115,'18 Month Anker Warranty',NULL,'Anker Innovations','A2668','assets/images/placeholders/device-charger.svg','assets/images/placeholders/device-charger-alt.svg',NULL,'active',0,0,1,1,0,1240,682,4.55,489,'Anker 65W GaN Charger Price in India | ShopInnKart','Buy the Anker 65W GaN 3-port fast charger for laptops and phones.');

-- ===========================================================================
--  ShopInnKart - Demo seed, Part A : PRODUCT DETAIL TABLES
--  product_images, product_specifications, product_features,
--  product_variants, product_variant_attributes, product_tags,
--  product_relations
--
--  Depends on: schema.sql seed (products 1-44, attributes 1-8,
--              attribute_values 1-47, tags 1-12)
--  All imagery is a locally generated SVG placeholder.
-- ===========================================================================


-- ---------------------------------------------------------------------------
--  1. PRODUCT IMAGES  (gallery, same device placeholder family as main_image)
-- ---------------------------------------------------------------------------
INSERT INTO `product_images` (`product_id`,`image`,`alt_text`,`sort_order`) VALUES
-- Smartphones (device-smartphone family)
(1,'assets/images/placeholders/device-smartphone.svg','Apple iPhone 16 Pro front view',0),
(1,'assets/images/placeholders/device-smartphone-alt.svg','Apple iPhone 16 Pro rear titanium finish',1),
(1,'assets/images/placeholders/device-smartphone-2.svg','Apple iPhone 16 Pro side profile with Camera Control',2),
(1,'assets/images/placeholders/device-smartphone-3.svg','Apple iPhone 16 Pro camera module close-up',3),
(1,'assets/images/placeholders/device-smartphone-alt.svg','Apple iPhone 16 Pro in-box contents',4),
(2,'assets/images/placeholders/device-smartphone.svg','Apple iPhone 16 front view',0),
(2,'assets/images/placeholders/device-smartphone-alt.svg','Apple iPhone 16 rear aluminium finish',1),
(2,'assets/images/placeholders/device-smartphone-2.svg','Apple iPhone 16 side profile',2),
(2,'assets/images/placeholders/device-smartphone-3.svg','Apple iPhone 16 dual camera close-up',3),
(2,'assets/images/placeholders/device-smartphone-alt.svg','Apple iPhone 16 in-box contents',4),
(3,'assets/images/placeholders/device-smartphone.svg','Samsung Galaxy S25 Ultra front view',0),
(3,'assets/images/placeholders/device-smartphone-alt.svg','Samsung Galaxy S25 Ultra rear quad camera',1),
(3,'assets/images/placeholders/device-smartphone-2.svg','Samsung Galaxy S25 Ultra with S Pen',2),
(3,'assets/images/placeholders/device-smartphone-3.svg','Samsung Galaxy S25 Ultra titanium frame detail',3),
(3,'assets/images/placeholders/device-smartphone-alt.svg','Samsung Galaxy S25 Ultra in-box contents',4),
(4,'assets/images/placeholders/device-smartphone.svg','Samsung Galaxy S25 front view',0),
(4,'assets/images/placeholders/device-smartphone-alt.svg','Samsung Galaxy S25 rear triple camera',1),
(4,'assets/images/placeholders/device-smartphone-2.svg','Samsung Galaxy S25 side profile',2),
(4,'assets/images/placeholders/device-smartphone-3.svg','Samsung Galaxy S25 in-box contents',3),
(5,'assets/images/placeholders/device-smartphone.svg','OnePlus 13 front view',0),
(5,'assets/images/placeholders/device-smartphone-alt.svg','OnePlus 13 rear Hasselblad camera island',1),
(5,'assets/images/placeholders/device-smartphone-2.svg','OnePlus 13 side profile with alert slider',2),
(5,'assets/images/placeholders/device-smartphone-3.svg','OnePlus 13 in-box contents with 100W charger',3),
(6,'assets/images/placeholders/device-smartphone.svg','OnePlus Nord 4 front view',0),
(6,'assets/images/placeholders/device-smartphone-alt.svg','OnePlus Nord 4 metal unibody rear',1),
(6,'assets/images/placeholders/device-smartphone-2.svg','OnePlus Nord 4 side profile',2),
(6,'assets/images/placeholders/device-smartphone-3.svg','OnePlus Nord 4 in-box contents',3),
(7,'assets/images/placeholders/device-smartphone.svg','Google Pixel 9 Pro front view',0),
(7,'assets/images/placeholders/device-smartphone-alt.svg','Google Pixel 9 Pro rear camera bar',1),
(7,'assets/images/placeholders/device-smartphone-2.svg','Google Pixel 9 Pro side profile',2),
(7,'assets/images/placeholders/device-smartphone-3.svg','Google Pixel 9 Pro in-box contents',3),
(8,'assets/images/placeholders/device-smartphone.svg','Xiaomi 14 Ultra front view',0),
(8,'assets/images/placeholders/device-smartphone-alt.svg','Xiaomi 14 Ultra Leica quad camera rear',1),
(8,'assets/images/placeholders/device-smartphone-2.svg','Xiaomi 14 Ultra side profile',2),
(8,'assets/images/placeholders/device-smartphone-3.svg','Xiaomi 14 Ultra in-box contents',3),
(9,'assets/images/placeholders/device-smartphone.svg','Redmi Note 14 Pro+ front view',0),
(9,'assets/images/placeholders/device-smartphone-alt.svg','Redmi Note 14 Pro+ rear 200MP camera',1),
(9,'assets/images/placeholders/device-smartphone-2.svg','Redmi Note 14 Pro+ curved display edge',2),
(9,'assets/images/placeholders/device-smartphone-3.svg','Redmi Note 14 Pro+ in-box contents',3),
-- Laptops (device-laptop family)
(10,'assets/images/placeholders/device-laptop.svg','Apple MacBook Air 13-inch M3 open front view',0),
(10,'assets/images/placeholders/device-laptop-alt.svg','Apple MacBook Air 13-inch M3 closed lid',1),
(10,'assets/images/placeholders/device-laptop-2.svg','Apple MacBook Air 13-inch M3 keyboard and trackpad',2),
(10,'assets/images/placeholders/device-laptop-3.svg','Apple MacBook Air 13-inch M3 port selection',3),
(10,'assets/images/placeholders/device-laptop-alt.svg','Apple MacBook Air 13-inch M3 in-box contents',4),
(11,'assets/images/placeholders/device-laptop.svg','Apple MacBook Pro 14-inch M4 open front view',0),
(11,'assets/images/placeholders/device-laptop-alt.svg','Apple MacBook Pro 14-inch M4 closed lid',1),
(11,'assets/images/placeholders/device-laptop-2.svg','Apple MacBook Pro 14-inch M4 Liquid Retina XDR display',2),
(11,'assets/images/placeholders/device-laptop-3.svg','Apple MacBook Pro 14-inch M4 Thunderbolt and HDMI ports',3),
(12,'assets/images/placeholders/device-laptop.svg','Dell XPS 14 open front view',0),
(12,'assets/images/placeholders/device-laptop-alt.svg','Dell XPS 14 closed aluminium lid',1),
(12,'assets/images/placeholders/device-laptop-2.svg','Dell XPS 14 capacitive function row',2),
(12,'assets/images/placeholders/device-laptop-3.svg','Dell XPS 14 3.2K OLED touch display',3),
(13,'assets/images/placeholders/device-laptop.svg','Dell Inspiron 15 open front view',0),
(13,'assets/images/placeholders/device-laptop-alt.svg','Dell Inspiron 15 closed lid',1),
(13,'assets/images/placeholders/device-laptop-2.svg','Dell Inspiron 15 full-size keyboard with numpad',2),
(13,'assets/images/placeholders/device-laptop-3.svg','Dell Inspiron 15 port selection',3),
(14,'assets/images/placeholders/device-laptop.svg','HP Pavilion Plus 14 open front view',0),
(14,'assets/images/placeholders/device-laptop-alt.svg','HP Pavilion Plus 14 closed lid',1),
(14,'assets/images/placeholders/device-laptop-2.svg','HP Pavilion Plus 14 2.8K OLED display',2),
(14,'assets/images/placeholders/device-laptop-3.svg','HP Pavilion Plus 14 backlit keyboard',3),
(15,'assets/images/placeholders/device-laptop.svg','Lenovo IdeaPad Slim 5 open front view',0),
(15,'assets/images/placeholders/device-laptop-alt.svg','Lenovo IdeaPad Slim 5 closed metal lid',1),
(15,'assets/images/placeholders/device-laptop-2.svg','Lenovo IdeaPad Slim 5 keyboard deck',2),
(15,'assets/images/placeholders/device-laptop-3.svg','Lenovo IdeaPad Slim 5 port selection',3),
(16,'assets/images/placeholders/device-laptop.svg','ASUS ROG Strix G16 open front view',0),
(16,'assets/images/placeholders/device-laptop-alt.svg','ASUS ROG Strix G16 closed lid',1),
(16,'assets/images/placeholders/device-laptop-2.svg','ASUS ROG Strix G16 per-key RGB keyboard',2),
(16,'assets/images/placeholders/device-laptop-3.svg','ASUS ROG Strix G16 Arc Flow cooling vents',3),
(16,'assets/images/placeholders/device-laptop-alt.svg','ASUS ROG Strix G16 in-box contents with 240W adapter',4),
(17,'assets/images/placeholders/device-laptop.svg','Acer Nitro V15 open front view',0),
(17,'assets/images/placeholders/device-laptop-alt.svg','Acer Nitro V15 closed lid',1),
(17,'assets/images/placeholders/device-laptop-2.svg','Acer Nitro V15 backlit gaming keyboard',2),
(17,'assets/images/placeholders/device-laptop-3.svg','Acer Nitro V15 exhaust vents and ports',3),
-- Tablets (device-tablet family)
(18,'assets/images/placeholders/device-tablet.svg','Apple iPad Air 11-inch M2 front view',0),
(18,'assets/images/placeholders/device-tablet-alt.svg','Apple iPad Air 11-inch M2 rear finish',1),
(18,'assets/images/placeholders/device-tablet-2.svg','Apple iPad Air 11-inch M2 with Apple Pencil Pro',2),
(18,'assets/images/placeholders/device-tablet-3.svg','Apple iPad Air 11-inch M2 with Magic Keyboard',3),
(19,'assets/images/placeholders/device-tablet.svg','Samsung Galaxy Tab S10 front view',0),
(19,'assets/images/placeholders/device-tablet-alt.svg','Samsung Galaxy Tab S10 rear finish',1),
(19,'assets/images/placeholders/device-tablet-2.svg','Samsung Galaxy Tab S10 with bundled S Pen',2),
(19,'assets/images/placeholders/device-tablet-3.svg','Samsung Galaxy Tab S10 in-box contents',3),
-- Televisions (device-tv family)
(20,'assets/images/placeholders/device-tv.svg','Samsung 55-inch Crystal 4K Smart TV front view',0),
(20,'assets/images/placeholders/device-tv-alt.svg','Samsung 55-inch Crystal 4K Smart TV angled view',1),
(20,'assets/images/placeholders/device-tv-2.svg','Samsung 55-inch Crystal 4K Smart TV rear ports',2),
(20,'assets/images/placeholders/device-tv-3.svg','Samsung 55-inch Crystal 4K Smart TV stand and remote',3),
(21,'assets/images/placeholders/device-tv.svg','LG 55-inch OLED evo C4 front view',0),
(21,'assets/images/placeholders/device-tv-alt.svg','LG 55-inch OLED evo C4 slim side profile',1),
(21,'assets/images/placeholders/device-tv-2.svg','LG 55-inch OLED evo C4 HDMI 2.1 port cluster',2),
(21,'assets/images/placeholders/device-tv-3.svg','LG 55-inch OLED evo C4 Magic Remote',3),
(21,'assets/images/placeholders/device-tv-alt.svg','LG 55-inch OLED evo C4 wall-mounted lifestyle view',4),
(22,'assets/images/placeholders/device-tv.svg','Sony Bravia 65-inch 4K Google TV front view',0),
(22,'assets/images/placeholders/device-tv-alt.svg','Sony Bravia 65-inch 4K Google TV angled view',1),
(22,'assets/images/placeholders/device-tv-2.svg','Sony Bravia 65-inch 4K Google TV rear connections',2),
(22,'assets/images/placeholders/device-tv-3.svg','Sony Bravia 65-inch 4K Google TV stand and remote',3),
-- Audio
(23,'assets/images/placeholders/device-headphone.svg','Sony WH-1000XM5 Wireless Headphones front view',0),
(23,'assets/images/placeholders/device-headphone-alt.svg','Sony WH-1000XM5 Wireless Headphones side profile',1),
(23,'assets/images/placeholders/device-headphone-2.svg','Sony WH-1000XM5 Wireless Headphones ear cup detail',2),
(23,'assets/images/placeholders/device-headphone-3.svg','Sony WH-1000XM5 Wireless Headphones folded in case',3),
(23,'assets/images/placeholders/device-headphone-alt.svg','Sony WH-1000XM5 Wireless Headphones in-box contents',4),
(24,'assets/images/placeholders/device-earbuds.svg','Apple AirPods Pro 2 (USB-C) with charging case',0),
(24,'assets/images/placeholders/device-earbuds-alt.svg','Apple AirPods Pro 2 (USB-C) earbuds close-up',1),
(24,'assets/images/placeholders/device-earbuds-2.svg','Apple AirPods Pro 2 (USB-C) case open',2),
(24,'assets/images/placeholders/device-earbuds-3.svg','Apple AirPods Pro 2 (USB-C) in-box contents with ear tips',3),
(25,'assets/images/placeholders/device-headphone.svg','JBL Tune 770NC Wireless Headphones front view',0),
(25,'assets/images/placeholders/device-headphone-alt.svg','JBL Tune 770NC Wireless Headphones side profile',1),
(25,'assets/images/placeholders/device-headphone-2.svg','JBL Tune 770NC Wireless Headphones folded',2),
(25,'assets/images/placeholders/device-headphone-3.svg','JBL Tune 770NC Wireless Headphones in-box contents',3),
(26,'assets/images/placeholders/device-earbuds.svg','boAt Airdopes 191G TWS Earbuds with case',0),
(26,'assets/images/placeholders/device-earbuds-alt.svg','boAt Airdopes 191G TWS Earbuds close-up',1),
(26,'assets/images/placeholders/device-earbuds-2.svg','boAt Airdopes 191G TWS Earbuds case open',2),
(26,'assets/images/placeholders/device-earbuds-3.svg','boAt Airdopes 191G TWS Earbuds in-box contents',3),
(27,'assets/images/placeholders/device-speaker.svg','JBL Flip 6 Portable Speaker front view',0),
(27,'assets/images/placeholders/device-speaker-alt.svg','JBL Flip 6 Portable Speaker side profile',1),
(27,'assets/images/placeholders/device-speaker-2.svg','JBL Flip 6 Portable Speaker passive radiator detail',2),
(27,'assets/images/placeholders/device-speaker-3.svg','JBL Flip 6 Portable Speaker poolside lifestyle shot',3),
(28,'assets/images/placeholders/device-soundbar.svg','Sony HT-S2000 Soundbar front view',0),
(28,'assets/images/placeholders/device-soundbar-alt.svg','Sony HT-S2000 Soundbar angled view',1),
(28,'assets/images/placeholders/device-soundbar-2.svg','Sony HT-S2000 Soundbar rear HDMI eARC connection',2),
(28,'assets/images/placeholders/device-soundbar-3.svg','Sony HT-S2000 Soundbar under a television',3),
-- Wearables (device-watch family)
(29,'assets/images/placeholders/device-watch.svg','Apple Watch Series 10 front view',0),
(29,'assets/images/placeholders/device-watch-alt.svg','Apple Watch Series 10 side profile with Digital Crown',1),
(29,'assets/images/placeholders/device-watch-2.svg','Apple Watch Series 10 rear health sensors',2),
(29,'assets/images/placeholders/device-watch-3.svg','Apple Watch Series 10 on wrist',3),
(29,'assets/images/placeholders/device-watch-alt.svg','Apple Watch Series 10 in-box contents',4),
(30,'assets/images/placeholders/device-watch.svg','Samsung Galaxy Watch 7 front view',0),
(30,'assets/images/placeholders/device-watch-alt.svg','Samsung Galaxy Watch 7 side profile',1),
(30,'assets/images/placeholders/device-watch-2.svg','Samsung Galaxy Watch 7 BioActive sensor rear',2),
(30,'assets/images/placeholders/device-watch-3.svg','Samsung Galaxy Watch 7 in-box contents',3),
(31,'assets/images/placeholders/device-watch.svg','boAt Wave Sigma 3 Smart Watch front view',0),
(31,'assets/images/placeholders/device-watch-alt.svg','boAt Wave Sigma 3 Smart Watch side profile',1),
(31,'assets/images/placeholders/device-watch-2.svg','boAt Wave Sigma 3 Smart Watch strap detail',2),
(31,'assets/images/placeholders/device-watch-3.svg','boAt Wave Sigma 3 Smart Watch in-box contents',3),
-- Gaming
(32,'assets/images/placeholders/device-console.svg','Sony PlayStation 5 Slim (Disc Edition) front view',0),
(32,'assets/images/placeholders/device-console-alt.svg','Sony PlayStation 5 Slim (Disc Edition) horizontal orientation',1),
(32,'assets/images/placeholders/device-console-2.svg','Sony PlayStation 5 Slim (Disc Edition) rear ports',2),
(32,'assets/images/placeholders/device-console-3.svg','Sony PlayStation 5 Slim (Disc Edition) DualSense controller',3),
(32,'assets/images/placeholders/device-console-alt.svg','Sony PlayStation 5 Slim (Disc Edition) in-box contents',4),
(33,'assets/images/placeholders/device-mouse.svg','Logitech G502 X Gaming Mouse top view',0),
(33,'assets/images/placeholders/device-mouse-alt.svg','Logitech G502 X Gaming Mouse side buttons',1),
(33,'assets/images/placeholders/device-mouse-2.svg','Logitech G502 X Gaming Mouse HERO sensor underside',2),
(33,'assets/images/placeholders/device-mouse-3.svg','Logitech G502 X Gaming Mouse in-box contents',3),
-- Cameras (device-camera family)
(34,'assets/images/placeholders/device-camera.svg','Canon EOS R50 Mirrorless Camera front view',0),
(34,'assets/images/placeholders/device-camera-alt.svg','Canon EOS R50 Mirrorless Camera with kit lens',1),
(34,'assets/images/placeholders/device-camera-2.svg','Canon EOS R50 Mirrorless Camera vari-angle screen',2),
(34,'assets/images/placeholders/device-camera-3.svg','Canon EOS R50 Mirrorless Camera top controls',3),
(35,'assets/images/placeholders/device-camera.svg','Sony ZV-E10 II Vlogging Camera front view',0),
(35,'assets/images/placeholders/device-camera-alt.svg','Sony ZV-E10 II Vlogging Camera with kit lens',1),
(35,'assets/images/placeholders/device-camera-2.svg','Sony ZV-E10 II Vlogging Camera side-flip screen',2),
(35,'assets/images/placeholders/device-camera-3.svg','Sony ZV-E10 II Vlogging Camera directional microphone',3),
-- Computer accessories
(36,'assets/images/placeholders/device-monitor.svg','Dell 27-inch 4K USB-C Monitor front view',0),
(36,'assets/images/placeholders/device-monitor-alt.svg','Dell 27-inch 4K USB-C Monitor angled view',1),
(36,'assets/images/placeholders/device-monitor-2.svg','Dell 27-inch 4K USB-C Monitor rear port cluster',2),
(36,'assets/images/placeholders/device-monitor-3.svg','Dell 27-inch 4K USB-C Monitor height adjustable stand',3),
(37,'assets/images/placeholders/device-keyboard.svg','Logitech MX Keys S Wireless Keyboard top view',0),
(37,'assets/images/placeholders/device-keyboard-alt.svg','Logitech MX Keys S Wireless Keyboard angled view',1),
(37,'assets/images/placeholders/device-keyboard-2.svg','Logitech MX Keys S Wireless Keyboard backlighting detail',2),
(37,'assets/images/placeholders/device-keyboard-3.svg','Logitech MX Keys S Wireless Keyboard on a desk setup',3),
(38,'assets/images/placeholders/device-mouse.svg','Logitech MX Master 3S Mouse top view',0),
(38,'assets/images/placeholders/device-mouse-alt.svg','Logitech MX Master 3S Mouse side thumb wheel',1),
(38,'assets/images/placeholders/device-mouse-2.svg','Logitech MX Master 3S Mouse MagSpeed scroll wheel',2),
(38,'assets/images/placeholders/device-mouse-3.svg','Logitech MX Master 3S Mouse in-box contents',3),
(39,'assets/images/placeholders/device-printer.svg','HP Smart Tank 580 All-in-One Printer front view',0),
(39,'assets/images/placeholders/device-printer-alt.svg','HP Smart Tank 580 All-in-One Printer ink tank window',1),
(39,'assets/images/placeholders/device-printer-2.svg','HP Smart Tank 580 All-in-One Printer scanner lid open',2),
(39,'assets/images/placeholders/device-printer-3.svg','HP Smart Tank 580 All-in-One Printer in-box ink bottles',3),
-- Smart home
(40,'assets/images/placeholders/device-router.svg','TP-Link Deco X50 Mesh Wi-Fi 6 (2-pack) front view',0),
(40,'assets/images/placeholders/device-router-alt.svg','TP-Link Deco X50 Mesh Wi-Fi 6 (2-pack) both units',1),
(40,'assets/images/placeholders/device-router-2.svg','TP-Link Deco X50 Mesh Wi-Fi 6 (2-pack) gigabit ports',2),
(40,'assets/images/placeholders/device-router-3.svg','TP-Link Deco X50 Mesh Wi-Fi 6 (2-pack) in a living room',3),
-- Storage
(41,'assets/images/placeholders/device-ssd.svg','Samsung 990 PRO 1TB NVMe SSD top view',0),
(41,'assets/images/placeholders/device-ssd-alt.svg','Samsung 990 PRO 1TB NVMe SSD label detail',1),
(41,'assets/images/placeholders/device-ssd-2.svg','Samsung 990 PRO 1TB NVMe SSD M.2 2280 form factor',2),
(41,'assets/images/placeholders/device-ssd-3.svg','Samsung 990 PRO 1TB NVMe SSD in retail packaging',3),
(42,'assets/images/placeholders/device-ssd.svg','Samsung T7 Shield 2TB Portable SSD top view',0),
(42,'assets/images/placeholders/device-ssd-alt.svg','Samsung T7 Shield 2TB Portable SSD rubberised shell',1),
(42,'assets/images/placeholders/device-ssd-2.svg','Samsung T7 Shield 2TB Portable SSD USB-C port',2),
(42,'assets/images/placeholders/device-ssd-3.svg','Samsung T7 Shield 2TB Portable SSD in-box cables',3),
-- Power
(43,'assets/images/placeholders/device-powerbank.svg','Anker 737 Power Bank 24,000mAh front view',0),
(43,'assets/images/placeholders/device-powerbank-alt.svg','Anker 737 Power Bank 24,000mAh smart display',1),
(43,'assets/images/placeholders/device-powerbank-2.svg','Anker 737 Power Bank 24,000mAh port layout',2),
(43,'assets/images/placeholders/device-powerbank-3.svg','Anker 737 Power Bank 24,000mAh in-box contents',3),
(44,'assets/images/placeholders/device-charger.svg','Anker 65W GaN Fast Charger front view',0),
(44,'assets/images/placeholders/device-charger-alt.svg','Anker 65W GaN Fast Charger folded plug',1),
(44,'assets/images/placeholders/device-charger-2.svg','Anker 65W GaN Fast Charger three port layout',2),
(44,'assets/images/placeholders/device-charger-3.svg','Anker 65W GaN Fast Charger size comparison',3);


-- ---------------------------------------------------------------------------
--  2. PRODUCT SPECIFICATIONS
-- ---------------------------------------------------------------------------
INSERT INTO `product_specifications` (`product_id`,`spec_group`,`spec_key`,`spec_value`,`sort_order`) VALUES
-- 1 Apple iPhone 16 Pro
(1,'Display','Screen Size','6.3-inch Super Retina XDR OLED',1),
(1,'Display','Resolution','2622 x 1206 pixels (460 ppi)',2),
(1,'Display','Refresh Rate','ProMotion adaptive 1-120 Hz, 2000 nits peak',3),
(1,'Performance','Chipset','Apple A18 Pro (3 nm) with 6-core GPU',4),
(1,'Performance','RAM','8 GB',5),
(1,'Performance','Operating System','iOS 18 with Apple Intelligence',6),
(1,'Camera','Rear Camera','48MP Fusion + 48MP Ultra Wide + 12MP 5x Telephoto',7),
(1,'Camera','Front Camera','12MP TrueDepth with autofocus',8),
(1,'Camera','Video','4K Dolby Vision at 120 fps, Audio Mix',9),
(1,'Battery','Charging','20W wired, 25W MagSafe wireless',10),
(1,'Connectivity','Network','5G SA/NSA, Wi-Fi 7, Bluetooth 5.3',11),
(1,'Design','Build','Grade 5 titanium frame, Ceramic Shield front, IP68',12),
(1,'In The Box','Box Contents','iPhone 16 Pro, USB-C charge cable, documentation',13),
(1,'Warranty','Warranty','1 Year Apple India Warranty',14),
-- 2 Apple iPhone 16
(2,'Display','Screen Size','6.1-inch Super Retina XDR OLED',1),
(2,'Display','Resolution','2556 x 1179 pixels (460 ppi)',2),
(2,'Display','Peak Brightness','2000 nits outdoor, 1 nit minimum',3),
(2,'Performance','Chipset','Apple A18 (3 nm)',4),
(2,'Performance','RAM','8 GB',5),
(2,'Performance','Operating System','iOS 18 with Apple Intelligence',6),
(2,'Camera','Rear Camera','48MP Fusion + 12MP Ultra Wide with Macro',7),
(2,'Camera','Front Camera','12MP TrueDepth',8),
(2,'Battery','Charging','20W wired, 25W MagSafe wireless',9),
(2,'Connectivity','Network','5G SA/NSA, Wi-Fi 7, Bluetooth 5.3',10),
(2,'Design','Build','Aluminium frame, Ceramic Shield front, IP68',11),
(2,'In The Box','Box Contents','iPhone 16, USB-C charge cable, documentation',12),
(2,'Warranty','Warranty','1 Year Apple India Warranty',13),
-- 3 Samsung Galaxy S25 Ultra
(3,'Display','Screen Size','6.9-inch QHD+ Dynamic AMOLED 2X',1),
(3,'Display','Resolution','3120 x 1440 pixels, 1-120 Hz adaptive',2),
(3,'Display','Protection','Corning Gorilla Armor 2 anti-reflective glass',3),
(3,'Performance','Chipset','Snapdragon 8 Elite for Galaxy',4),
(3,'Performance','RAM','12 GB LPDDR5X',5),
(3,'Performance','Operating System','Android 15 with One UI 7 and Galaxy AI',6),
(3,'Camera','Rear Camera','200MP wide + 50MP ultra wide + 50MP 5x + 10MP 3x',7),
(3,'Camera','Front Camera','12MP autofocus',8),
(3,'Battery','Battery','5000 mAh, 45W wired and 15W wireless',9),
(3,'Connectivity','Network','5G, Wi-Fi 7, Bluetooth 5.4, UWB',10),
(3,'Design','Extras','Built-in S Pen, titanium frame, IP68',11),
(3,'In The Box','Box Contents','Galaxy S25 Ultra, S Pen, USB-C cable, ejection pin',12),
(3,'Warranty','Warranty','1 Year Samsung India Warranty',13),
-- 4 Samsung Galaxy S25
(4,'Display','Screen Size','6.2-inch FHD+ Dynamic AMOLED 2X',1),
(4,'Display','Resolution','2340 x 1080 pixels, 120 Hz adaptive',2),
(4,'Performance','Chipset','Snapdragon 8 Elite for Galaxy',3),
(4,'Performance','RAM','12 GB',4),
(4,'Performance','Operating System','Android 15 with One UI 7',5),
(4,'Performance','Software Support','4 OS upgrades and 5 years of security updates',6),
(4,'Camera','Rear Camera','50MP wide + 12MP ultra wide + 10MP 3x telephoto',7),
(4,'Camera','Video','8K at 30 fps, 4K HDR at 60 fps',8),
(4,'Battery','Battery','4000 mAh, 25W wired fast charge to 65% in 30 minutes',9),
(4,'Connectivity','Network','5G, Wi-Fi 7, Bluetooth 5.4',10),
(4,'Design','Build','Armor Aluminium frame, IP68',11),
(4,'Warranty','Warranty','1 Year Samsung India Warranty',12),
-- 5 OnePlus 13
(5,'Display','Screen Size','6.82-inch 2K LTPO AMOLED',1),
(5,'Display','Resolution','3168 x 1440 pixels, 1-120 Hz LTPO',2),
(5,'Display','Peak Brightness','4500 nits',3),
(5,'Performance','Chipset','Snapdragon 8 Elite',4),
(5,'Performance','RAM','12 GB LPDDR5X',5),
(5,'Performance','Operating System','OxygenOS 15 based on Android 15',6),
(5,'Camera','Rear Camera','50MP Hasselblad wide + 50MP ultra wide + 50MP 3x periscope',7),
(5,'Camera','Front Camera','32MP',8),
(5,'Battery','Battery','6000 mAh Silicon NanoStack',9),
(5,'Battery','Charging','100W SuperVOOC wired, 50W AIRVOOC wireless',10),
(5,'Design','Protection','IP68 and IP69 dust and water rating',11),
(5,'In The Box','Box Contents','OnePlus 13, 100W adapter, USB-C cable, case',12),
(5,'Warranty','Warranty','1 Year OnePlus India Warranty',13),
-- 6 OnePlus Nord 4
(6,'Display','Screen Size','6.74-inch FHD+ AMOLED',1),
(6,'Display','Refresh Rate','120 Hz adaptive, 2150 nits peak',2),
(6,'Performance','Chipset','Snapdragon 7+ Gen 3',3),
(6,'Performance','RAM','8 GB LPDDR5X',4),
(6,'Performance','Operating System','OxygenOS 14 based on Android 14',5),
(6,'Camera','Rear Camera','50MP Sony LYT-600 with OIS + 8MP ultra wide',6),
(6,'Camera','Front Camera','16MP',7),
(6,'Battery','Battery','5500 mAh',8),
(6,'Battery','Charging','100W SuperVOOC',9),
(6,'Connectivity','Network','5G dual SIM, Wi-Fi 6, Bluetooth 5.4',10),
(6,'Design','Build','Full metal unibody, IP65',11),
(6,'Warranty','Warranty','1 Year OnePlus India Warranty',12),
-- 7 Google Pixel 9 Pro
(7,'Display','Screen Size','6.3-inch Super Actua LTPO OLED',1),
(7,'Display','Resolution','2856 x 1280 pixels, 1-120 Hz',2),
(7,'Performance','Chipset','Google Tensor G4 with Titan M2 security',3),
(7,'Performance','RAM','16 GB LPDDR5X',4),
(7,'Performance','Operating System','Android 14 with 7 years of updates',5),
(7,'Camera','Rear Camera','50MP wide + 48MP ultra wide + 48MP 5x telephoto',6),
(7,'Camera','Front Camera','42MP autofocus',7),
(7,'Camera','AI Features','Magic Editor, Best Take, Add Me, Night Sight',8),
(7,'Battery','Battery','4700 mAh, 27W wired and 21W Qi wireless',9),
(7,'Connectivity','Network','5G, Wi-Fi 7, Bluetooth 5.3',10),
(7,'Design','Build','Polished aluminium frame, Gorilla Glass Victus 2, IP68',11),
(7,'Warranty','Warranty','1 Year Google India Warranty',12),
-- 8 Xiaomi 14 Ultra
(8,'Display','Screen Size','6.73-inch WQHD+ LTPO AMOLED',1),
(8,'Display','Refresh Rate','1-120 Hz adaptive, 3000 nits peak',2),
(8,'Performance','Chipset','Snapdragon 8 Gen 3',3),
(8,'Performance','RAM','16 GB LPDDR5X',4),
(8,'Performance','Operating System','HyperOS based on Android 14',5),
(8,'Camera','Main Sensor','50MP 1-inch Sony LYT-900 with f/1.63-f/4.0 variable aperture',6),
(8,'Camera','Other Lenses','50MP 3.2x + 50MP 5x periscope + 50MP ultra wide, Leica tuned',7),
(8,'Camera','Video','8K at 24 fps, 4K Dolby Vision',8),
(8,'Battery','Battery','5300 mAh',9),
(8,'Battery','Charging','90W wired, 80W wireless',10),
(8,'Design','Build','Titanium-reinforced frame, IP68',11),
(8,'Warranty','Warranty','1 Year Xiaomi India Warranty',12),
-- 9 Redmi Note 14 Pro+
(9,'Display','Screen Size','6.67-inch 1.5K curved AMOLED',1),
(9,'Display','Refresh Rate','120 Hz, 3000 nits peak brightness',2),
(9,'Performance','Chipset','Snapdragon 7s Gen 3',3),
(9,'Performance','RAM','8 GB LPDDR4X',4),
(9,'Performance','Operating System','HyperOS based on Android 14',5),
(9,'Camera','Rear Camera','200MP OIS main + 8MP ultra wide + 2MP macro',6),
(9,'Camera','Front Camera','20MP',7),
(9,'Battery','Battery','5110 mAh',8),
(9,'Battery','Charging','120W HyperCharge, full charge in about 20 minutes',9),
(9,'Connectivity','Network','5G dual SIM, Wi-Fi 6, NFC, IR blaster',10),
(9,'Design','Protection','IP68 rating with Gorilla Glass Victus 2',11),
(9,'Warranty','Warranty','1 Year Xiaomi India Warranty',12),
-- 10 Apple MacBook Air 13-inch M3
(10,'Display','Screen Size','13.6-inch Liquid Retina',1),
(10,'Display','Resolution','2560 x 1664 at 224 ppi, 500 nits, P3 wide colour',2),
(10,'Performance','Processor','Apple M3 with 8-core CPU',3),
(10,'Performance','Graphics','10-core GPU and 16-core Neural Engine',4),
(10,'Performance','Memory','8 GB / 16 GB / 24 GB unified memory',5),
(10,'Performance','Storage','256 GB to 2 TB SSD',6),
(10,'Battery','Battery Life','Up to 18 hours of Apple TV app movie playback',7),
(10,'Battery','Charging','35W dual USB-C or 70W fast charge adapter',8),
(10,'Connectivity','Ports','2x Thunderbolt / USB 4, MagSafe 3, 3.5 mm jack',9),
(10,'Connectivity','Wireless','Wi-Fi 6E and Bluetooth 5.3',10),
(10,'Design','Dimensions and Weight','1.13 cm thin, 1.24 kg, fanless chassis',11),
(10,'Warranty','Warranty','1 Year Apple India Warranty',12),
-- 11 Apple MacBook Pro 14-inch M4
(11,'Display','Screen Size','14.2-inch Liquid Retina XDR',1),
(11,'Display','Brightness','1000 nits sustained, 1600 nits peak HDR',2),
(11,'Display','Refresh Rate','ProMotion adaptive up to 120 Hz',3),
(11,'Performance','Processor','Apple M4 with 10-core CPU',4),
(11,'Performance','Graphics','10-core GPU with hardware-accelerated ray tracing',5),
(11,'Performance','Memory','16 GB / 24 GB / 32 GB unified memory',6),
(11,'Performance','Storage','512 GB to 2 TB SSD',7),
(11,'Battery','Battery Life','Up to 24 hours of video playback',8),
(11,'Connectivity','Ports','3x Thunderbolt 4, HDMI, SDXC, MagSafe 3',9),
(11,'Audio','Speakers','Six-speaker system with Spatial Audio',10),
(11,'Design','Weight','1.55 kg',11),
(11,'Warranty','Warranty','1 Year Apple India Warranty',12),
-- 12 Dell XPS 14
(12,'Display','Screen Size','14.5-inch 3.2K OLED touch',1),
(12,'Display','Resolution','3200 x 2000 at 120 Hz, 100% DCI-P3',2),
(12,'Performance','Processor','Intel Core Ultra 7 155H',3),
(12,'Performance','Graphics','NVIDIA GeForce RTX 4050 6 GB',4),
(12,'Performance','Memory','16 GB / 32 GB LPDDR5x',5),
(12,'Performance','Storage','512 GB / 1 TB PCIe 4.0 NVMe SSD',6),
(12,'Battery','Battery','69.5 Wh with 100W USB-C charging',7),
(12,'Connectivity','Ports','3x Thunderbolt 4, microSD reader, 3.5 mm jack',8),
(12,'Connectivity','Wireless','Wi-Fi 6E and Bluetooth 5.3',9),
(12,'Design','Build','CNC machined aluminium with capacitive function row',10),
(12,'Design','Weight','1.68 kg',11),
(12,'Warranty','Warranty','1 Year Dell Onsite Warranty',12),
-- 13 Dell Inspiron 15
(13,'Display','Screen Size','15.6-inch FHD anti-glare',1),
(13,'Display','Resolution','1920 x 1080 at 120 Hz, 250 nits',2),
(13,'Performance','Processor','Intel Core i5-1334U',3),
(13,'Performance','Graphics','Intel Iris Xe',4),
(13,'Performance','Memory','8 GB / 16 GB DDR4 across two upgradeable slots',5),
(13,'Performance','Storage','512 GB / 1 TB NVMe SSD',6),
(13,'Battery','Battery','54 Wh, up to 8 hours of mixed use',7),
(13,'Connectivity','Ports','2x USB-A 3.2, USB-C, HDMI 1.4, SD card reader',8),
(13,'Connectivity','Wireless','Wi-Fi 6 and Bluetooth 5.2',9),
(13,'Design','Keyboard','Full-size keyboard with numeric pad',10),
(13,'Design','Weight','1.86 kg',11),
(13,'Warranty','Warranty','1 Year Dell Onsite Warranty',12),
-- 14 HP Pavilion Plus 14
(14,'Display','Screen Size','14-inch 2.8K OLED',1),
(14,'Display','Resolution','2880 x 1800 at 120 Hz, 400 nits',2),
(14,'Performance','Processor','Intel Core i5-13500H',3),
(14,'Performance','Graphics','Intel Iris Xe',4),
(14,'Performance','Memory','16 GB LPDDR5',5),
(14,'Performance','Storage','512 GB / 1 TB PCIe 4.0 SSD',6),
(14,'Battery','Battery','68 Wh with 65W fast charge',7),
(14,'Connectivity','Ports','2x USB-C, 2x USB-A, HDMI 2.1',8),
(14,'Connectivity','Wireless','Wi-Fi 6E and Bluetooth 5.3',9),
(14,'Design','Keyboard','Backlit keyboard with fingerprint reader',10),
(14,'Design','Weight','1.41 kg',11),
(14,'Warranty','Warranty','1 Year HP India Warranty',12),
-- 15 Lenovo IdeaPad Slim 5
(15,'Display','Screen Size','14-inch WUXGA IPS',1),
(15,'Display','Resolution','1920 x 1200, 300 nits, 100% sRGB',2),
(15,'Performance','Processor','AMD Ryzen 7 7730U',3),
(15,'Performance','Graphics','AMD Radeon Graphics',4),
(15,'Performance','Memory','16 GB LPDDR4X',5),
(15,'Performance','Storage','512 GB / 1 TB NVMe SSD',6),
(15,'Battery','Battery','57 Wh with Rapid Charge to 80% in 60 minutes',7),
(15,'Connectivity','Ports','2x USB-C, 2x USB-A, HDMI, SD card reader',8),
(15,'Connectivity','Wireless','Wi-Fi 6 and Bluetooth 5.1',9),
(15,'Design','Build','Aluminium top cover with privacy shutter webcam',10),
(15,'Design','Weight','1.46 kg',11),
(15,'Warranty','Warranty','1 Year Lenovo Warranty',12),
-- 16 ASUS ROG Strix G16
(16,'Display','Screen Size','16-inch 2.5K ROG Nebula',1),
(16,'Display','Refresh Rate','165 Hz with 3 ms response and G-SYNC',2),
(16,'Performance','Processor','Intel Core i7-13650HX',3),
(16,'Performance','Graphics','NVIDIA GeForce RTX 4060 8 GB at 140W TGP',4),
(16,'Performance','Memory','16 GB / 32 GB DDR5-4800',5),
(16,'Performance','Storage','1 TB / 2 TB PCIe 4.0 NVMe SSD',6),
(16,'Performance','Cooling','Tri-fan Arc Flow with liquid metal on the CPU',7),
(16,'Battery','Battery','90 Wh with a 240W adapter',8),
(16,'Connectivity','Ports','USB-C with DisplayPort, 3x USB-A, HDMI 2.1, RJ45',9),
(16,'Design','Keyboard','Per-key RGB Aura Sync keyboard',10),
(16,'Design','Weight','2.50 kg',11),
(16,'Warranty','Warranty','1 Year ASUS India Warranty',12),
-- 17 Acer Nitro V15
(17,'Display','Screen Size','15.6-inch FHD IPS',1),
(17,'Display','Refresh Rate','144 Hz, 250 nits',2),
(17,'Performance','Processor','Intel Core i5-13420H',3),
(17,'Performance','Graphics','NVIDIA GeForce RTX 4050 6 GB',4),
(17,'Performance','Memory','8 GB / 16 GB DDR5, upgradeable to 32 GB',5),
(17,'Performance','Storage','512 GB / 1 TB PCIe 4.0 SSD',6),
(17,'Performance','Cooling','Dual-fan cooling with four exhaust vents',7),
(17,'Battery','Battery','57 Wh',8),
(17,'Connectivity','Ports','USB-C, 3x USB-A, HDMI 2.1, RJ45',9),
(17,'Design','Keyboard','Backlit keyboard with numeric pad',10),
(17,'Design','Weight','2.10 kg',11),
(17,'Warranty','Warranty','1 Year Acer India Warranty',12),
-- 18 Apple iPad Air 11-inch M2
(18,'Display','Screen Size','11-inch Liquid Retina',1),
(18,'Display','Resolution','2360 x 1640 at 264 ppi, 500 nits',2),
(18,'Performance','Chip','Apple M2 with 8-core CPU and 10-core GPU',3),
(18,'Performance','Memory','8 GB unified memory',4),
(18,'Performance','Storage','128 GB / 256 GB / 512 GB / 1 TB',5),
(18,'Camera','Cameras','12MP wide rear, 12MP landscape Centre Stage front',6),
(18,'Battery','Battery Life','Up to 10 hours of web browsing on Wi-Fi',7),
(18,'Connectivity','Ports and Wireless','USB-C, Wi-Fi 6E, optional 5G',8),
(18,'General','Accessories','Apple Pencil Pro and Magic Keyboard support',9),
(18,'Design','Weight','462 g (Wi-Fi model)',10),
(18,'Warranty','Warranty','1 Year Apple India Warranty',11),
-- 19 Samsung Galaxy Tab S10
(19,'Display','Screen Size','11-inch Dynamic AMOLED 2X',1),
(19,'Display','Resolution','2560 x 1600 at 120 Hz',2),
(19,'Performance','Chipset','MediaTek Dimensity 9300+',3),
(19,'Performance','Memory','8 GB / 12 GB RAM',4),
(19,'Performance','Storage','128 GB / 256 GB with microSD up to 1 TB',5),
(19,'Camera','Cameras','13MP rear, 12MP ultra wide front',6),
(19,'Battery','Battery','8400 mAh with 45W fast charging',7),
(19,'Connectivity','Ports and Wireless','USB-C 3.2, Wi-Fi 6E, optional 5G',8),
(19,'General','Accessories','S Pen included in the box',9),
(19,'Design','Build','Armour Aluminium, IP68 rated, 498 g',10),
(19,'Warranty','Warranty','1 Year Samsung India Warranty',11),
-- 20 Samsung 55-inch Crystal 4K Smart TV
(20,'Display','Screen Size','55-inch (139 cm)',1),
(20,'Display','Resolution','3840 x 2160 4K UHD',2),
(20,'Display','Panel','Crystal Display with Dynamic Crystal Colour',3),
(20,'Display','Refresh Rate','50 Hz native with Motion Xcelerator',4),
(20,'Performance','Processor','Crystal Processor 4K with 4K upscaling',5),
(20,'Performance','Operating System','Tizen Smart TV with Gaming Hub',6),
(20,'Audio','Sound Output','20W 2.0 channel with Q-Symphony and OTS Lite',7),
(20,'Connectivity','Ports','3x HDMI, 2x USB, Wi-Fi 5, Bluetooth',8),
(20,'Connectivity','Streaming','Netflix, Prime Video, Disney+ Hotstar, YouTube',9),
(20,'General','Installation','Free wall mount installation included',10),
(20,'Warranty','Warranty','1 Year Comprehensive + 1 Year Panel',11),
-- 21 LG 55-inch OLED evo C4
(21,'Display','Screen Size','55-inch (139 cm)',1),
(21,'Display','Panel','OLED evo with Brightness Booster',2),
(21,'Display','Resolution','3840 x 2160 4K',3),
(21,'Display','Refresh Rate','144 Hz with VRR, G-SYNC and FreeSync Premium',4),
(21,'Performance','Processor','Alpha 9 AI Processor Gen 7',5),
(21,'Performance','Operating System','webOS 24 with 5 years of platform upgrades',6),
(21,'Audio','Sound Output','40W 2.2 channel with Dolby Atmos',7),
(21,'Connectivity','Ports','4x HDMI 2.1 at 48 Gbps, 3x USB, Wi-Fi 6',8),
(21,'General','HDR Formats','Dolby Vision, HDR10, HLG, Filmmaker Mode',9),
(21,'General','Gaming','Game Optimiser with 0.1 ms response time',10),
(21,'Warranty','Warranty','1 Year Comprehensive + 3 Year Panel',11),
-- 22 Sony Bravia 65-inch 4K Google TV
(22,'Display','Screen Size','65-inch (164 cm)',1),
(22,'Display','Resolution','3840 x 2160 4K HDR',2),
(22,'Display','Panel','Direct LED with Live Colour technology',3),
(22,'Performance','Processor','Cognitive Processor XR',4),
(22,'Performance','Operating System','Google TV with hands-free voice search',5),
(22,'Audio','Sound Output','20W X-Balanced Open Baffle speaker system',6),
(22,'Audio','Formats','Dolby Atmos and Dolby Vision',7),
(22,'Connectivity','Ports','4x HDMI (2 are HDMI 2.1), 2x USB, Wi-Fi, Bluetooth',8),
(22,'General','Gaming','ALLM, VRR and 4K120 support for PlayStation 5',9),
(22,'General','Installation','Free wall mount installation included',10),
(22,'Warranty','Warranty','1 Year Comprehensive Warranty',11);

INSERT INTO `product_specifications` (`product_id`,`spec_group`,`spec_key`,`spec_value`,`sort_order`) VALUES
-- 23 Sony WH-1000XM5
(23,'Audio','Driver','30 mm carbon fibre composite dome',1),
(23,'Audio','Frequency Response','4 Hz - 40,000 Hz over LDAC at 990 kbps',2),
(23,'Audio','Codecs','SBC, AAC and LDAC with DSEE Extreme upscaling',3),
(23,'Performance','Noise Cancellation','Dual processors with 8 microphones and Auto NC Optimiser',4),
(23,'Battery','Battery Life','30 hours with ANC, 3 hours from a 3 minute charge',5),
(23,'Connectivity','Bluetooth','Bluetooth 5.2 with multipoint pairing',6),
(23,'Design','Weight','250 g with a lightweight folding headband',7),
(23,'Warranty','Warranty','1 Year Sony India Warranty',8),
-- 24 Apple AirPods Pro 2 (USB-C)
(24,'Audio','Driver','Custom high-excursion Apple driver and amplifier',1),
(24,'Audio','Audio Modes','Adaptive Audio, Transparency and Active Noise Cancellation',2),
(24,'Performance','Chip','Apple H2 with Personalised Spatial Audio',3),
(24,'Battery','Battery Life','Up to 6 hours per charge, 30 hours with the case',4),
(24,'Battery','Charging','USB-C, MagSafe, Qi wireless or Apple Watch charger',5),
(24,'Connectivity','Bluetooth','Bluetooth 5.3',6),
(24,'Design','Water Resistance','IP54 rated earbuds and charging case',7),
(24,'Warranty','Warranty','1 Year Apple India Warranty',8),
-- 25 JBL Tune 770NC
(25,'Audio','Driver','40 mm dynamic driver with JBL Pure Bass',1),
(25,'Audio','Codecs','SBC and AAC',2),
(25,'Performance','Noise Cancellation','Adaptive Noise Cancelling with Smart Ambient',3),
(25,'Battery','Battery Life','70 hours without ANC, 44 hours with ANC',4),
(25,'Battery','Charging','2 hours to full, 5 minutes for 3 hours of playback',5),
(25,'Connectivity','Bluetooth','Bluetooth 5.3 with multi-point to two devices',6),
(25,'Design','Build','Foldable design with swivel ear cups, 220 g',7),
(25,'Warranty','Warranty','1 Year JBL India Warranty',8),
-- 26 boAt Airdopes 191G
(26,'Audio','Driver','13 mm dynamic drivers',1),
(26,'Audio','Modes','BEAST low latency 40 ms gaming mode',2),
(26,'Performance','Microphone','ENx environmental noise cancellation for calls',3),
(26,'Battery','Battery Life','Up to 45 hours total with the charging case',4),
(26,'Battery','Charging','ASAP Charge - 10 minutes for 120 minutes of playback',5),
(26,'Connectivity','Bluetooth','Bluetooth 5.3 with instant pairing',6),
(26,'Design','Water Resistance','IPX4 splash and sweat resistant',7),
(26,'Warranty','Warranty','1 Year boAt Warranty',8),
-- 27 JBL Flip 6
(27,'Audio','Speaker System','Two-way with racetrack woofer and separate tweeter',1),
(27,'Audio','Output Power','20W RMS woofer plus 10W RMS tweeter',2),
(27,'Audio','Frequency Response','63 Hz - 20 kHz',3),
(27,'Battery','Battery Life','Up to 12 hours of playtime',4),
(27,'Battery','Charging','USB-C, 2.5 hours to full',5),
(27,'Connectivity','Bluetooth','Bluetooth 5.1 with PartyBoost stereo pairing',6),
(27,'Design','Water Resistance','IP67 waterproof and dustproof',7),
(27,'Warranty','Warranty','1 Year JBL India Warranty',8),
-- 28 Sony HT-S2000
(28,'Audio','Channels','3.1 channel with dual built-in subwoofers',1),
(28,'Audio','Output Power','250W total system power',2),
(28,'Audio','Formats','Dolby Atmos, DTS:X and Vertical Surround Engine',3),
(28,'Performance','Sound Modes','Cinema, Music, Standard, Auto Sound and Night',4),
(28,'Connectivity','Ports','HDMI eARC, optical input and USB',5),
(28,'Connectivity','Wireless','Bluetooth 5.2 audio streaming',6),
(28,'Design','Dimensions','800 x 64 x 124 mm, 3.2 kg',7),
(28,'Warranty','Warranty','1 Year Sony India Warranty',8),
-- 29 Apple Watch Series 10
(29,'Display','Display','Wide-angle LTPO3 OLED at up to 2000 nits',1),
(29,'Display','Case Size','42 mm and 46 mm aluminium or titanium',2),
(29,'Performance','Chip','Apple S10 SiP with 64-bit dual-core processor',3),
(29,'Performance','Health Sensors','ECG, blood oxygen, temperature and sleep apnoea notifications',4),
(29,'Battery','Battery Life','Up to 18 hours, fast charge to 80% in 30 minutes',5),
(29,'Connectivity','Wireless','Wi-Fi, Bluetooth 5.3, optional cellular',6),
(29,'Design','Water Resistance','50 m water resistant, depth to 6 m, IP6X',7),
(29,'Warranty','Warranty','1 Year Apple India Warranty',8),
-- 30 Samsung Galaxy Watch 7
(30,'Display','Display','1.5-inch Super AMOLED at 480 x 480 with sapphire crystal',1),
(30,'Display','Case Size','40 mm and 44 mm',2),
(30,'Performance','Chipset','Exynos W1000 with 32 GB storage',3),
(30,'Performance','Health Sensors','BioActive sensor with AGEs Index, ECG and body composition',4),
(30,'Battery','Battery Life','425 mAh, up to 40 hours with always-on display off',5),
(30,'Connectivity','Positioning','Dual-frequency GPS (L1 and L5)',6),
(30,'Design','Durability','5ATM, IP68 and MIL-STD-810H',7),
(30,'Warranty','Warranty','1 Year Samsung India Warranty',8),
-- 31 boAt Wave Sigma 3
(31,'Display','Display','2.01-inch HD display at 600 nits',1),
(31,'Performance','Sport Modes','100+ sport modes with auto activity detection',2),
(31,'Performance','Health Sensors','Heart rate, SpO2 and sleep tracking',3),
(31,'Battery','Battery Life','Up to 7 days of typical use',4),
(31,'Connectivity','Calling','Bluetooth v5.3 calling with built-in speaker and mic',5),
(31,'General','Watch Faces','150+ cloud watch faces',6),
(31,'Design','Water Resistance','IP68 dust and water resistance',7),
(31,'Warranty','Warranty','1 Year boAt Warranty',8),
-- 32 Sony PlayStation 5 Slim (Disc Edition)
(32,'Performance','CPU','8-core AMD Zen 2 at up to 3.5 GHz',1),
(32,'Performance','GPU','AMD RDNA 2, 10.28 TFLOPS with hardware ray tracing',2),
(32,'Performance','Memory','16 GB GDDR6',3),
(32,'General','Storage','1 TB custom NVMe SSD with M.2 expansion slot',4),
(32,'General','Optical Drive','Ultra HD Blu-ray disc drive',5),
(32,'General','Video Output','4K at 120 Hz, 8K support, HDR',6),
(32,'Connectivity','Ports','HDMI 2.1, 2x USB-C, 2x USB-A, Wi-Fi 6, Gigabit Ethernet',7),
(32,'In The Box','Box Contents','PS5 Slim console, DualSense controller, HDMI cable, stand',8),
(32,'Warranty','Warranty','1 Year Sony India Warranty',9),
-- 33 Logitech G502 X
(33,'Performance','Sensor','HERO 25K, 100 to 25,600 DPI',1),
(33,'Performance','Switches','LIGHTFORCE hybrid optical-mechanical',2),
(33,'Performance','Buttons','13 programmable controls with dual-mode scroll wheel',3),
(33,'Connectivity','Connection','Wired USB-C with 1000 Hz polling rate',4),
(33,'Design','Weight','89 g',5),
(33,'Warranty','Warranty','2 Year Logitech Warranty',6),
-- 34 Canon EOS R50
(34,'General','Sensor','24.2MP APS-C CMOS',1),
(34,'General','Processor','DIGIC X',2),
(34,'Performance','Autofocus','Dual Pixel CMOS AF II with 651 zones and subject detection',3),
(34,'Performance','ISO Range','100-32,000, expandable to 51,200',4),
(34,'Performance','Continuous Shooting','Up to 15 fps with the electronic shutter',5),
(34,'Camera','Video','Uncropped 4K30 oversampled from 6K, FHD120',6),
(34,'Display','Screen','2.36-inch vari-angle touchscreen LCD with 1.62M dots',7),
(34,'Display','Viewfinder','2.36M dot OLED electronic viewfinder',8),
(34,'Connectivity','Wireless','Wi-Fi, Bluetooth and USB-C webcam mode',9),
(34,'Design','Weight','375 g body with battery and card',10),
(34,'Warranty','Warranty','2 Year Canon India Warranty',11),
-- 35 Sony ZV-E10 II
(35,'General','Sensor','26MP APS-C Exmor R BSI CMOS',1),
(35,'General','Processor','BIONZ XR',2),
(35,'Performance','Autofocus','759-point phase detection with Real-time Recognition AF',3),
(35,'Performance','ISO Range','100-32,000, expandable to 102,400',4),
(35,'Camera','Video','4K60 from full pixel readout, 10-bit 4:2:2',5),
(35,'Camera','Creator Tools','Product Showcase, Background Defocus and Cinematic Vlog',6),
(35,'Audio','Microphone','Built-in three-capsule directional mic with windscreen',7),
(35,'Display','Screen','3-inch side-flip vari-angle touchscreen',8),
(35,'Battery','Battery','NP-FZ100 for approximately 610 shots',9),
(35,'Design','Weight','377 g body with battery and card',10),
(35,'Warranty','Warranty','2 Year Sony India Warranty',11),
-- 36 Dell 27-inch 4K USB-C Monitor
(36,'Display','Screen Size','27-inch IPS Black panel',1),
(36,'Display','Resolution','3840 x 2160 4K UHD at 120 Hz',2),
(36,'Display','Colour','98% DCI-P3 with Delta E under 2, factory calibrated',3),
(36,'Display','Contrast','2000:1 contrast at 400 nits',4),
(36,'Connectivity','Ports','USB-C with 90W power delivery, DisplayPort 1.4, HDMI 2.1, 4x USB',5),
(36,'Connectivity','Ethernet','Built-in RJ45 for network passthrough',6),
(36,'Design','Stand','Height, tilt, swivel and pivot adjustable',7),
(36,'Warranty','Warranty','3 Year Dell Advanced Exchange',8),
-- 37 Logitech MX Keys S
(37,'Performance','Key Type','Low profile scissor keys with spherically dished caps',1),
(37,'Performance','Backlight','Proximity-sensing smart backlighting',2),
(37,'Connectivity','Connection','Logi Bolt USB receiver or Bluetooth Low Energy',3),
(37,'Connectivity','Multi-Device','Easy-Switch across three paired devices',4),
(37,'Battery','Battery Life','10 days with backlighting, 5 months without',5),
(37,'Warranty','Warranty','1 Year Logitech Warranty',6),
-- 38 Logitech MX Master 3S
(38,'Performance','Sensor','Darkfield 8000 DPI that tracks on glass',1),
(38,'Performance','Scrolling','MagSpeed electromagnetic wheel at 1000 lines per second',2),
(38,'Performance','Clicks','Quiet Clicks with 90% less click noise',3),
(38,'Connectivity','Connection','Logi Bolt receiver or Bluetooth, up to 3 devices',4),
(38,'Battery','Battery Life','70 days per full charge, 3 hours from a 1 minute charge',5),
(38,'Warranty','Warranty','1 Year Logitech Warranty',6),
-- 39 HP Smart Tank 580
(39,'Performance','Functions','Print, scan and copy',1),
(39,'Performance','Print Speed','Up to 12 ppm black and 5 ppm colour',2),
(39,'Performance','Print Resolution','4800 x 1200 dpi colour',3),
(39,'General','Page Yield','Up to 6,000 colour and 6,000 black pages with included bottles',4),
(39,'Connectivity','Connectivity','Wi-Fi, Bluetooth setup, USB and the HP Smart app',5),
(39,'Design','Paper Handling','100-sheet input tray with A4 flatbed scanner',6),
(39,'Warranty','Warranty','1 Year HP India Warranty',7),
-- 40 TP-Link Deco X50
(40,'Performance','Wi-Fi Standard','Wi-Fi 6 AX3000 - 574 Mbps on 2.4 GHz and 2402 Mbps on 5 GHz',1),
(40,'Performance','Coverage','Up to 4,000 sq ft with the 2-pack',2),
(40,'Performance','Devices','Supports up to 150 connected devices',3),
(40,'Connectivity','Ports','3x Gigabit Ethernet per unit with WAN/LAN auto sensing',4),
(40,'General','Security','HomeShield with parental controls and IoT protection',5),
(40,'General','Features','Seamless roaming under one network name, AI-driven mesh',6),
(40,'Warranty','Warranty','3 Year TP-Link Warranty',7),
-- 41 Samsung 990 PRO 1TB
(41,'Performance','Interface','PCIe 4.0 x4, NVMe 2.0',1),
(41,'Performance','Sequential Read','Up to 7,450 MB/s',2),
(41,'Performance','Sequential Write','Up to 6,900 MB/s',3),
(41,'Performance','Endurance','600 TBW with 1.5 million hours MTBF',4),
(41,'Design','Form Factor','M.2 2280 with nickel-coated controller and heat spreader label',5),
(41,'Warranty','Warranty','5 Year Samsung Warranty',6),
-- 42 Samsung T7 Shield 2TB
(42,'Performance','Interface','USB 3.2 Gen 2 at 10 Gbps over USB-C',1),
(42,'Performance','Sequential Read','Up to 1,050 MB/s',2),
(42,'Performance','Sequential Write','Up to 1,000 MB/s',3),
(42,'Design','Durability','IP65 rated, survives 3 m drops, rubberised shell',4),
(42,'Design','Dimensions','88 x 59 x 13 mm, 98 g',5),
(42,'Warranty','Warranty','3 Year Samsung Warranty',6),
-- 43 Anker 737 Power Bank
(43,'Performance','Capacity','24,000 mAh / 86.4 Wh',1),
(43,'Performance','Output','140W maximum total output',2),
(43,'Performance','Ports','2x USB-C and 1x USB-A',3),
(43,'General','Display','Smart digital display with real-time wattage and battery health',4),
(43,'General','Recharge','Recharges to 100% in about 60 minutes at 140W',5),
(43,'Warranty','Warranty','18 Month Anker Warranty',6),
-- 44 Anker 65W GaN Fast Charger
(44,'Performance','Output','65W total using GaN II technology',1),
(44,'Performance','Ports','2x USB-C and 1x USB-A',2),
(44,'Performance','Compatibility','MacBook Air, iPhone, Galaxy, iPad and earbuds',3),
(44,'Design','Size','Around 50% smaller than a standard 65W laptop charger',4),
(44,'Design','Safety','ActiveShield 2.0 temperature monitoring',5),
(44,'Warranty','Warranty','18 Month Anker Warranty',6);


-- ---------------------------------------------------------------------------
--  3. PRODUCT FEATURES
-- ---------------------------------------------------------------------------
INSERT INTO `product_features` (`product_id`,`feature`,`sort_order`) VALUES
(1,'A18 Pro chip delivers console-class gaming performance',1),
(1,'48MP Fusion camera records 4K Dolby Vision at 120 fps',2),
(1,'Camera Control button for one-thumb framing and zoom',3),
(1,'Grade 5 titanium frame with IP68 water resistance',4),
(1,'5G support on every major Indian network',5),
(2,'A18 chip with full Apple Intelligence support',1),
(2,'Dual 48MP Fusion camera with 2x optical-quality zoom',2),
(2,'Camera Control button on the standard model',3),
(2,'All-day battery with MagSafe fast wireless charging',4),
(2,'Five aluminium finishes with a Ceramic Shield front',5),
(3,'200MP wide camera with 5x optical telephoto',1),
(3,'S Pen tucked into the frame for notes and edits',2),
(3,'6.9-inch QHD+ 120Hz Dynamic AMOLED 2X panel',3),
(3,'Galaxy AI live translate and generative photo editing',4),
(3,'Titanium frame with Corning Gorilla Armor 2',5),
(4,'Compact 6.2-inch flagship that fits one hand',1),
(4,'Snapdragon 8 Elite for Galaxy performance',2),
(4,'50MP triple camera with 8K video recording',3),
(4,'Charges to 65% in about 30 minutes',4),
(4,'Four generations of Android upgrades included',5),
(5,'6000mAh battery refills in about 36 minutes',1),
(5,'2K 120Hz LTPO AMOLED peaking at 4500 nits',2),
(5,'Hasselblad colour tuning across all three cameras',3),
(5,'IP68 and IP69 dust and water protection',4),
(5,'100W SuperVOOC charger included in the box',5),
(6,'Full metal unibody at a mid-range price',1),
(6,'5500mAh battery clears a day and a half',2),
(6,'100W SuperVOOC charging in the box',3),
(6,'6.74-inch 120Hz AMOLED readable in direct sun',4),
(6,'Snapdragon 7+ Gen 3 for smooth everyday gaming',5),
(7,'Tensor G4 runs Google AI features on device',1),
(7,'50MP triple camera with 5x optical telephoto',2),
(7,'Seven years of OS and security updates',3),
(7,'Magic Editor, Best Take and Night Sight photo tools',4),
(7,'Super Actua LTPO display with a 1-120Hz range',5),
(8,'1-inch Sony LYT-900 sensor with variable aperture',1),
(8,'Four Leica-tuned lenses that all record 8K',2),
(8,'90W wired and 80W wireless charging',3),
(8,'Snapdragon 8 Gen 3 with 16GB LPDDR5X memory',4),
(8,'Titanium-reinforced frame with IP68 rating',5),
(9,'200MP main camera with optical stabilisation',1),
(9,'1.5K curved AMOLED running at 120Hz',2),
(9,'120W HyperCharge fills the battery in about 20 minutes',3),
(9,'IP68 rating on a mid-range phone',4),
(9,'Gorilla Glass Victus 2 front and back',5),
(10,'M3 chip stays silent with a fanless design',1),
(10,'Up to 18 hours of battery life',2),
(10,'Drives two external displays with the lid closed',3),
(10,'Liquid Retina display with P3 wide colour',4),
(10,'1.24kg chassis that fits any bag',5),
(11,'M4 chip with hardware-accelerated ray tracing',1),
(11,'Liquid Retina XDR at 1600 nits peak HDR',2),
(11,'ProMotion adaptive refresh up to 120Hz',3),
(11,'Three Thunderbolt 4 ports plus HDMI and SDXC',4),
(11,'Six-speaker sound system with Spatial Audio',5),
(12,'3.2K OLED touch panel, factory calibrated',1),
(12,'Core Ultra 7 paired with RTX 4050 graphics',2),
(12,'CNC machined aluminium chassis',3),
(12,'Capacitive function row and edge-to-edge keyboard',4),
(12,'Three Thunderbolt 4 ports with 100W charging',5),
(13,'Full-size keyboard with a numeric keypad',1),
(13,'Dual storage slots for easy upgrades',2),
(13,'120Hz FHD anti-glare display',3),
(13,'Complete port selection including HDMI and SD reader',4),
(13,'Battery that lasts a full lecture day',5),
(14,'2.8K OLED display running at 120Hz',1),
(14,'Backlit keyboard with a fingerprint reader',2),
(14,'1.41kg travel weight',3),
(14,'Wi-Fi 6E and Bluetooth 5.3 connectivity',4),
(14,'65W fast charge tops up between classes',5),
(15,'Ryzen 7 with 16GB of RAM as standard',1),
(15,'Rapid Charge reaches 80% in about an hour',2),
(15,'14-inch WUXGA panel with 100% sRGB coverage',3),
(15,'Aluminium top cover in a 1.46kg body',4),
(15,'Privacy shutter on the webcam',5),
(16,'165Hz 2.5K ROG Nebula display with G-SYNC',1),
(16,'RTX 4060 running at 140W total graphics power',2),
(16,'Tri-fan Arc Flow cooling with liquid metal',3),
(16,'Per-key RGB Aura Sync keyboard',4),
(16,'DDR5 memory and PCIe 4.0 storage',5),
(17,'RTX 4050 graphics for 1080p high settings',1),
(17,'144Hz FHD IPS display',2),
(17,'Dual-fan cooling with four exhaust vents',3),
(17,'Memory upgradeable to 32GB',4),
(17,'Backlit keyboard with a numeric pad',5),
(18,'M2 chip handles Procreate and Final Cut',1),
(18,'Apple Pencil Pro with squeeze and barrel roll',2),
(18,'Landscape 12MP Centre Stage front camera',3),
(18,'Magic Keyboard and Smart Folio support',4),
(18,'All-day 10-hour battery life',5),
(19,'120Hz AMOLED that is easy on the eyes',1),
(19,'S Pen included in the box with near-zero latency',2),
(19,'8400mAh battery with 45W charging',3),
(19,'IP68 rated Armour Aluminium build',4),
(19,'microSD expansion up to 1TB',5),
(20,'4K UHD panel with Crystal Processor upscaling',1),
(20,'Tizen carries every major Indian streaming app',2),
(20,'Q-Symphony and Object Tracking Sound Lite',3),
(20,'Gaming Hub with cloud game streaming',4),
(20,'Free wall mount installation included',5),
(21,'Self-lit OLED evo panel with perfect blacks',1),
(21,'144Hz with VRR, G-SYNC and FreeSync Premium',2),
(21,'Four full-bandwidth HDMI 2.1 ports',3),
(21,'Alpha 9 Gen 7 processor with AI upscaling',4),
(21,'Dolby Vision and Dolby Atmos support',5),
(22,'Cognitive Processor XR keeps motion and skin tones natural',1),
(22,'Google TV with hands-free voice search',2),
(22,'4K120 and VRR make it a strong PlayStation 5 partner',3),
(22,'X-Balanced speaker with Dolby Atmos',4),
(22,'Free wall mount installation included',5),
(23,'Eight microphones across two noise cancelling processors',1),
(23,'Thirty hours of playback with ANC switched on',2),
(23,'Speak-to-Chat pauses music the moment you talk',3),
(23,'Three minutes of charging gives three hours of listening',4),
(23,'LDAC hi-res wireless audio support',5),
(24,'H2 chip powers Adaptive Audio',1),
(24,'Conversation Awareness lowers volume when you speak',2),
(24,'Personalised Spatial Audio with dynamic head tracking',3),
(24,'IP54 rated earbuds and charging case',4),
(24,'USB-C, MagSafe and Qi wireless charging',5),
(25,'Seventy hours of playback without ANC',1),
(25,'Adaptive noise cancelling with Smart Ambient',2),
(25,'Multi-point pairing to two devices at once',3),
(25,'Five minutes of charge gives three hours of music',4),
(25,'Folding design that survives a backpack',5),
(26,'45 hours of total playback with the case',1),
(26,'BEAST 40ms low latency gaming mode',2),
(26,'ENx clear calling cuts background noise',3),
(26,'ASAP Charge - 10 minutes for 120 minutes of play',4),
(26,'IPX4 splash resistance for workouts',5),
(27,'Two-way system with a dedicated tweeter',1),
(27,'IP67 waterproof and dustproof',2),
(27,'Twelve hours of playtime per charge',3),
(27,'PartyBoost pairs two speakers for stereo',4),
(27,'USB-C charging with a durable fabric wrap',5),
(28,'Dual built-in subwoofers, no separate box needed',1),
(28,'3.1 channel Dolby Atmos and DTS:X',2),
(28,'Vertical Surround Engine widens the soundstage',3),
(28,'Single-cable HDMI eARC connection',4),
(28,'Bluetooth streaming from a phone or tablet',5),
(29,'Thinnest and lightest Apple Watch yet',1),
(29,'Wide-angle OLED stays readable off-axis',2),
(29,'Sleep apnoea notifications and depth sensing',3),
(29,'Fast charge to 80% in about 30 minutes',4),
(29,'50m water resistance with an IP6X dust rating',5),
(30,'BioActive sensor adds the AGEs Index metric',1),
(30,'Dual-frequency GPS keeps city runs accurate',2),
(30,'Energy Score summarises daily readiness',3),
(30,'Sapphire crystal display with a 5ATM rating',4),
(30,'Exynos W1000 keeps Wear OS responsive',5),
(31,'2.01-inch HD display at 600 nits',1),
(31,'Bluetooth calling with built-in speaker and mic',2),
(31,'100+ sport modes with auto detection',3),
(31,'Seven days of battery on typical use',4),
(31,'IP68 dust and water resistance',5),
(32,'1TB SSD with an M.2 expansion slot',1),
(32,'DualSense haptics and adaptive triggers',2),
(32,'Ultra HD Blu-ray disc drive included',3),
(32,'Ray tracing with 4K120 and 8K output support',4),
(32,'Slimmer chassis with detachable side panels',5),
(33,'HERO 25K sensor tracking to 25,600 DPI',1),
(33,'LIGHTFORCE hybrid optical-mechanical switches',2),
(33,'Thirteen programmable controls',3),
(33,'Dual-mode scroll wheel switches to free spin',4),
(33,'89g weight with an adjustable DPI shift button',5),
(34,'24.2MP APS-C sensor with DIGIC X processing',1),
(34,'Dual Pixel CMOS AF II tracks eyes, faces and animals',2),
(34,'Uncropped 4K30 oversampled from 6K',3),
(34,'Vari-angle touchscreen for vlogging and low angles',4),
(34,'Only 375g with battery and card',5),
(35,'26MP APS-C sensor with BIONZ XR processing',1),
(35,'4K60 recorded from the full sensor width',2),
(35,'Three-capsule directional microphone built in',3),
(35,'Product Showcase and Background Defocus buttons',4),
(35,'Side-flip screen clears the way for a mic or cage',5),
(36,'One USB-C cable carries video, data and 90W charging',1),
(36,'IPS Black panel doubles contrast over standard IPS',2),
(36,'4K resolution at a 120Hz refresh rate',3),
(36,'Built-in RJ45 Ethernet passthrough',4),
(36,'Height, tilt, swivel and pivot adjustable stand',5),
(37,'Spherically dished keys for comfortable typing',1),
(37,'Proximity-sensing smart backlighting',2),
(37,'Easy-Switch across three paired devices',3),
(37,'Smart Actions automate repetitive workflows',4),
(37,'Five months of battery with backlighting off',5),
(38,'MagSpeed wheel scrolls 1000 lines a second',1),
(38,'Quiet Clicks cut click noise by 90 percent',2),
(38,'Darkfield 8000 DPI tracks even on glass',3),
(38,'Flow lets one mouse control three computers',4),
(38,'Seventy days of battery per charge',5),
(39,'Refillable ink tanks instead of cartridges',1),
(39,'Bundled bottles cover about 6,000 colour pages',2),
(39,'Print, scan and copy over Wi-Fi',3),
(39,'HP Smart app setup and mobile printing',4),
(39,'Spill-free refill system',5),
(40,'AX3000 mesh covering up to 4,000 sq ft',1),
(40,'Single network name with seamless roaming',2),
(40,'Three gigabit ports on every unit',3),
(40,'HomeShield parental controls and IoT protection',4),
(40,'Works with existing Deco units to extend coverage',5),
(41,'PCIe 4.0 reads at up to 7,450 MB/s',1),
(41,'Nickel-coated controller with heat spreader label',2),
(41,'600 TBW endurance rating',3),
(41,'Samsung Magician software for health monitoring',4),
(41,'M.2 2280 fits laptops, desktops and consoles',5),
(42,'IP65 rated against dust and water',1),
(42,'Survives drops from up to three metres',2),
(42,'1,050 MB/s reads over USB 3.2 Gen 2',3),
(42,'Rubberised shell that resists fingerprints',4),
(42,'Hardware AES 256-bit encryption',5),
(43,'24,000mAh capacity with 140W maximum output',1),
(43,'Charges a MacBook Pro at full speed',2),
(43,'Smart display shows real-time wattage',3),
(43,'Three ports charge a laptop, phone and watch together',4),
(43,'Recharges itself to full in about an hour',5),
(44,'GaN II keeps it half the size of a stock brick',1),
(44,'65W across two USB-C and one USB-A port',2),
(44,'Charges a MacBook Air, iPhone and earbuds together',3),
(44,'ActiveShield 2.0 temperature monitoring',4),
(44,'Foldable plug for travel',5);


-- ---------------------------------------------------------------------------
--  4a. PRODUCT VARIANTS  (only products with has_variants = 1)
-- ---------------------------------------------------------------------------
INSERT INTO `product_variants` (`id`,`product_id`,`sku`,`variant_name`,`price`,`sale_price`,`stock`,`image`,`is_default`,`status`) VALUES
-- Product 1 : Apple iPhone 16 Pro  (Colour + Storage)
(1,1,'SIK-PHN-1601-BLK-256','Midnight Black / 256 GB',134900.00,124999.00,18,'assets/images/placeholders/device-smartphone.svg',1,'active'),
(2,1,'SIK-PHN-1601-GRY-512','Titanium Grey / 512 GB',154900.00,144999.00,11,'assets/images/placeholders/device-smartphone.svg',0,'active'),
(3,1,'SIK-PHN-1601-WHT-1TB','Pearl White / 1 TB',174900.00,164999.00,7,'assets/images/placeholders/device-smartphone.svg',0,'active'),
-- Product 2 : Apple iPhone 16
(4,2,'SIK-PHN-1602-BLK-128','Midnight Black / 128 GB',79900.00,74999.00,26,'assets/images/placeholders/device-smartphone.svg',1,'active'),
(5,2,'SIK-PHN-1602-BLU-256','Ocean Blue / 256 GB',89900.00,84999.00,19,'assets/images/placeholders/device-smartphone.svg',0,'active'),
(6,2,'SIK-PHN-1602-WHT-512','Pearl White / 512 GB',109900.00,104999.00,9,'assets/images/placeholders/device-smartphone.svg',0,'active'),
-- Product 3 : Samsung Galaxy S25 Ultra
(7,3,'SIK-PHN-2501-GRY-256','Titanium Grey / 256 GB',129999.00,114999.00,15,'assets/images/placeholders/device-smartphone.svg',1,'active'),
(8,3,'SIK-PHN-2501-BLK-512','Midnight Black / 512 GB',141999.00,126999.00,10,'assets/images/placeholders/device-smartphone.svg',0,'active'),
(9,3,'SIK-PHN-2501-SLV-1TB','Silver / 1 TB',165999.00,149999.00,5,'assets/images/placeholders/device-smartphone.svg',0,'active'),
-- Product 4 : Samsung Galaxy S25
(10,4,'SIK-PHN-2502-BLK-128','Midnight Black / 128 GB',79999.00,69999.00,22,'assets/images/placeholders/device-smartphone.svg',1,'active'),
(11,4,'SIK-PHN-2502-BLU-256','Ocean Blue / 256 GB',85999.00,75999.00,17,'assets/images/placeholders/device-smartphone.svg',0,'active'),
(12,4,'SIK-PHN-2502-GRN-512','Forest Green / 512 GB',97999.00,87999.00,8,'assets/images/placeholders/device-smartphone.svg',0,'active'),
-- Product 5 : OnePlus 13
(13,5,'SIK-PHN-3001-BLK-256','Midnight Black / 256 GB',69999.00,61999.00,20,'assets/images/placeholders/device-smartphone.svg',1,'active'),
(14,5,'SIK-PHN-3001-BLU-512','Ocean Blue / 512 GB',76999.00,68999.00,14,'assets/images/placeholders/device-smartphone.svg',0,'active'),
(15,5,'SIK-PHN-3001-GRN-256','Forest Green / 256 GB',69999.00,62499.00,12,'assets/images/placeholders/device-smartphone.svg',0,'active'),
-- Product 6 : OnePlus Nord 4
(16,6,'SIK-PHN-3002-BLK-128','Midnight Black / 128 GB',32999.00,27999.00,30,'assets/images/placeholders/device-smartphone.svg',1,'active'),
(17,6,'SIK-PHN-3002-BLU-256','Ocean Blue / 256 GB',35999.00,30999.00,24,'assets/images/placeholders/device-smartphone.svg',0,'active'),
(18,6,'SIK-PHN-3002-SLV-512','Silver / 512 GB',38999.00,33999.00,13,'assets/images/placeholders/device-smartphone.svg',0,'active'),
-- Product 7 : Google Pixel 9 Pro
(19,7,'SIK-PHN-4001-BLK-128','Midnight Black / 128 GB',109999.00,94999.00,12,'assets/images/placeholders/device-smartphone.svg',1,'active'),
(20,7,'SIK-PHN-4001-WHT-256','Pearl White / 256 GB',119999.00,104999.00,9,'assets/images/placeholders/device-smartphone.svg',0,'active'),
(21,7,'SIK-PHN-4001-GRN-512','Forest Green / 512 GB',133999.00,118999.00,5,'assets/images/placeholders/device-smartphone.svg',0,'active'),
-- Product 8 : Xiaomi 14 Ultra
(22,8,'SIK-PHN-5001-BLK-256','Midnight Black / 256 GB',89999.00,79999.00,13,'assets/images/placeholders/device-smartphone.svg',1,'active'),
(23,8,'SIK-PHN-5001-WHT-512','Pearl White / 512 GB',99999.00,89999.00,7,'assets/images/placeholders/device-smartphone.svg',0,'active'),
-- Product 9 : Redmi Note 14 Pro+
(24,9,'SIK-PHN-5002-BLK-128','Midnight Black / 128 GB',29999.00,24999.00,38,'assets/images/placeholders/device-smartphone.svg',1,'active'),
(25,9,'SIK-PHN-5002-BLU-256','Ocean Blue / 256 GB',32999.00,27499.00,28,'assets/images/placeholders/device-smartphone.svg',0,'active'),
(26,9,'SIK-PHN-5002-ORG-512','Sunset Orange / 512 GB',35999.00,30499.00,16,'assets/images/placeholders/device-smartphone.svg',0,'active'),
-- Product 10 : Apple MacBook Air 13-inch M3  (RAM + Storage)
(27,10,'SIK-LAP-1001-8-256','8 GB RAM / 256 GB SSD',114900.00,99990.00,14,'assets/images/placeholders/device-laptop.svg',1,'active'),
(28,10,'SIK-LAP-1001-16-512','16 GB RAM / 512 GB SSD',134900.00,122990.00,9,'assets/images/placeholders/device-laptop.svg',0,'active'),
(29,10,'SIK-LAP-1001-16-1TB','16 GB RAM / 1 TB SSD',154900.00,141990.00,5,'assets/images/placeholders/device-laptop.svg',0,'active'),
-- Product 11 : Apple MacBook Pro 14-inch M4
(30,11,'SIK-LAP-1002-16-512','16 GB RAM / 512 GB SSD',169900.00,159900.00,8,'assets/images/placeholders/device-laptop.svg',1,'active'),
(31,11,'SIK-LAP-1002-16-1TB','16 GB RAM / 1 TB SSD',189900.00,179900.00,6,'assets/images/placeholders/device-laptop.svg',0,'active'),
(32,11,'SIK-LAP-1002-32-1TB','32 GB RAM / 1 TB SSD',219900.00,209900.00,3,'assets/images/placeholders/device-laptop.svg',0,'active'),
-- Product 12 : Dell XPS 14
(33,12,'SIK-LAP-2001-16-512','16 GB RAM / 512 GB SSD',149990.00,132990.00,7,'assets/images/placeholders/device-laptop.svg',1,'active'),
(34,12,'SIK-LAP-2001-16-1TB','16 GB RAM / 1 TB SSD',164990.00,146990.00,5,'assets/images/placeholders/device-laptop.svg',0,'active'),
(35,12,'SIK-LAP-2001-32-1TB','32 GB RAM / 1 TB SSD',184990.00,166990.00,4,'assets/images/placeholders/device-laptop.svg',0,'active'),
-- Product 13 : Dell Inspiron 15
(36,13,'SIK-LAP-2002-8-512','8 GB RAM / 512 GB SSD',64990.00,52990.00,30,'assets/images/placeholders/device-laptop.svg',1,'active'),
(37,13,'SIK-LAP-2002-16-512','16 GB RAM / 512 GB SSD',71990.00,58990.00,22,'assets/images/placeholders/device-laptop.svg',0,'active'),
(38,13,'SIK-LAP-2002-16-1TB','16 GB RAM / 1 TB SSD',79990.00,65990.00,14,'assets/images/placeholders/device-laptop.svg',0,'active'),
-- Product 14 : HP Pavilion Plus 14
(39,14,'SIK-LAP-3001-16-512','16 GB RAM / 512 GB SSD',79999.00,66999.00,25,'assets/images/placeholders/device-laptop.svg',1,'active'),
(40,14,'SIK-LAP-3001-16-1TB','16 GB RAM / 1 TB SSD',87999.00,74999.00,12,'assets/images/placeholders/device-laptop.svg',0,'active'),
-- Product 15 : Lenovo IdeaPad Slim 5
(41,15,'SIK-LAP-4001-16-512','16 GB RAM / 512 GB SSD',69990.00,54990.00,28,'assets/images/placeholders/device-laptop.svg',1,'active'),
(42,15,'SIK-LAP-4001-16-1TB','16 GB RAM / 1 TB SSD',76990.00,60990.00,18,'assets/images/placeholders/device-laptop.svg',0,'active'),
-- Product 16 : ASUS ROG Strix G16
(43,16,'SIK-LAP-5001-16-1TB','16 GB RAM / 1 TB SSD',154990.00,134990.00,9,'assets/images/placeholders/device-laptop.svg',1,'active'),
(44,16,'SIK-LAP-5001-32-1TB','32 GB RAM / 1 TB SSD',174990.00,152990.00,6,'assets/images/placeholders/device-laptop.svg',0,'active'),
(45,16,'SIK-LAP-5001-32-2TB','32 GB RAM / 2 TB SSD',194990.00,171990.00,3,'assets/images/placeholders/device-laptop.svg',0,'active'),
-- Product 17 : Acer Nitro V15
(46,17,'SIK-LAP-6001-8-512','8 GB RAM / 512 GB SSD',79999.00,67999.00,18,'assets/images/placeholders/device-laptop.svg',1,'active'),
(47,17,'SIK-LAP-6001-16-512','16 GB RAM / 512 GB SSD',87999.00,74999.00,13,'assets/images/placeholders/device-laptop.svg',0,'active'),
(48,17,'SIK-LAP-6001-16-1TB','16 GB RAM / 1 TB SSD',94999.00,81999.00,8,'assets/images/placeholders/device-laptop.svg',0,'active'),
-- Product 18 : Apple iPad Air 11-inch M2  (Model + Storage)
(49,18,'SIK-TAB-1001-WIFI-128','Wi-Fi / 128 GB',59900.00,54900.00,20,'assets/images/placeholders/device-tablet.svg',1,'active'),
(50,18,'SIK-TAB-1001-WIFI-256','Wi-Fi / 256 GB',69900.00,64900.00,13,'assets/images/placeholders/device-tablet.svg',0,'active'),
(51,18,'SIK-TAB-1001-5G-256','Wi-Fi + 5G / 256 GB',84900.00,79900.00,7,'assets/images/placeholders/device-tablet.svg',0,'active'),
-- Product 19 : Samsung Galaxy Tab S10
(52,19,'SIK-TAB-2001-WIFI-128','Wi-Fi / 128 GB',74999.00,64999.00,15,'assets/images/placeholders/device-tablet.svg',1,'active'),
(53,19,'SIK-TAB-2001-WIFI-256','Wi-Fi / 256 GB',82999.00,71999.00,10,'assets/images/placeholders/device-tablet.svg',0,'active'),
(54,19,'SIK-TAB-2001-5G-256','Wi-Fi + 5G / 256 GB',94999.00,83999.00,6,'assets/images/placeholders/device-tablet.svg',0,'active'),
-- Product 23 : Sony WH-1000XM5  (Colour only)
(55,23,'SIK-AUD-4001-BLK','Midnight Black',29990.00,24990.00,34,'assets/images/placeholders/device-headphone.svg',1,'active'),
(56,23,'SIK-AUD-4001-SLV','Silver',29990.00,24990.00,27,'assets/images/placeholders/device-headphone.svg',0,'active'),
(57,23,'SIK-AUD-4001-BLU','Ocean Blue',29990.00,25490.00,18,'assets/images/placeholders/device-headphone.svg',0,'active'),
-- Product 25 : JBL Tune 770NC
(58,25,'SIK-AUD-5001-BLK','Midnight Black',9999.00,6999.00,40,'assets/images/placeholders/device-headphone.svg',1,'active'),
(59,25,'SIK-AUD-5001-WHT','Pearl White',9999.00,6999.00,33,'assets/images/placeholders/device-headphone.svg',0,'active'),
(60,25,'SIK-AUD-5001-BLU','Ocean Blue',9999.00,7199.00,21,'assets/images/placeholders/device-headphone.svg',0,'active'),
-- Product 27 : JBL Flip 6
(61,27,'SIK-AUD-5002-BLK','Midnight Black',11999.00,8999.00,35,'assets/images/placeholders/device-speaker.svg',1,'active'),
(62,27,'SIK-AUD-5002-BLU','Ocean Blue',11999.00,8999.00,26,'assets/images/placeholders/device-speaker.svg',0,'active'),
(63,27,'SIK-AUD-5002-ORG','Sunset Orange',11999.00,9299.00,17,'assets/images/placeholders/device-speaker.svg',0,'active'),
-- Product 29 : Apple Watch Series 10  (Colour + Size)
(64,29,'SIK-WER-1001-BLK-44','Midnight Black / 44 mm',46900.00,42900.00,22,'assets/images/placeholders/device-watch.svg',1,'active'),
(65,29,'SIK-WER-1001-SLV-44','Silver / 44 mm',46900.00,42900.00,16,'assets/images/placeholders/device-watch.svg',0,'active'),
(66,29,'SIK-WER-1001-RGD-46','Rose Gold / 46 mm',49900.00,45900.00,9,'assets/images/placeholders/device-watch.svg',0,'active'),
-- Product 30 : Samsung Galaxy Watch 7
(67,30,'SIK-WER-2001-BLK-40','Midnight Black / 40 mm',31999.00,25999.00,25,'assets/images/placeholders/device-watch.svg',1,'active'),
(68,30,'SIK-WER-2001-SLV-44','Silver / 44 mm',34999.00,28999.00,18,'assets/images/placeholders/device-watch.svg',0,'active'),
(69,30,'SIK-WER-2001-GRN-44','Forest Green / 44 mm',34999.00,29499.00,11,'assets/images/placeholders/device-watch.svg',0,'active'),
-- Product 33 : Logitech G502 X  (Colour only)
(70,33,'SIK-GAM-7001-BLK','Midnight Black',8995.00,5995.00,40,'assets/images/placeholders/device-mouse.svg',1,'active'),
(71,33,'SIK-GAM-7001-WHT','Pearl White',8995.00,6295.00,28,'assets/images/placeholders/device-mouse.svg',0,'active'),
-- Product 34 : Canon EOS R50  (Colour + Kit model)
(72,34,'SIK-CAM-8001-BLK-STD','Midnight Black / Standard Kit',74995.00,64995.00,12,'assets/images/placeholders/device-camera.svg',1,'active'),
(73,34,'SIK-CAM-8001-WHT-STD','Pearl White / Standard Kit',74995.00,64995.00,8,'assets/images/placeholders/device-camera.svg',0,'active'),
(74,34,'SIK-CAM-8001-BLK-PRO','Midnight Black / Pro Twin-Lens Kit',94995.00,84995.00,4,'assets/images/placeholders/device-camera.svg',0,'active'),
-- Product 35 : Sony ZV-E10 II
(75,35,'SIK-CAM-4001-BLK-STD','Midnight Black / Standard Kit',89990.00,79990.00,10,'assets/images/placeholders/device-camera.svg',1,'active'),
(76,35,'SIK-CAM-4001-WHT-STD','Pearl White / Standard Kit',89990.00,79990.00,6,'assets/images/placeholders/device-camera.svg',0,'active'),
(77,35,'SIK-CAM-4001-BLK-PRO','Midnight Black / Creator Kit',104990.00,94990.00,3,'assets/images/placeholders/device-camera.svg',0,'active'),
-- Product 37 : Logitech MX Keys S  (Colour only)
(78,37,'SIK-ACC-7001-GRY','Titanium Grey',11995.00,9495.00,34,'assets/images/placeholders/device-keyboard.svg',1,'active'),
(79,37,'SIK-ACC-7001-WHT','Pearl White',11995.00,9495.00,22,'assets/images/placeholders/device-keyboard.svg',0,'active'),
-- Product 38 : Logitech MX Master 3S  (Colour only)
(80,38,'SIK-ACC-7002-GRY','Titanium Grey',10995.00,8495.00,36,'assets/images/placeholders/device-mouse.svg',1,'active'),
(81,38,'SIK-ACC-7002-BLK','Midnight Black',10995.00,8495.00,29,'assets/images/placeholders/device-mouse.svg',0,'active'),
(82,38,'SIK-ACC-7002-WHT','Pearl White',10995.00,8795.00,18,'assets/images/placeholders/device-mouse.svg',0,'active'),
-- Product 40 : TP-Link Deco X50  (Colour + Pack size)
(83,40,'SIK-NET-8001-2PK','Pearl White / 2-Pack Mesh',18999.00,13999.00,30,'assets/images/placeholders/device-router.svg',1,'active'),
(84,40,'SIK-NET-8001-3PK','Pearl White / 3-Pack Mesh',26999.00,20999.00,15,'assets/images/placeholders/device-router.svg',0,'active'),
-- Product 41 : Samsung 990 PRO  (Storage + Model)
(85,41,'SIK-STO-2001-1TB-STD','1 TB / Standard',14999.00,9999.00,40,'assets/images/placeholders/device-ssd.svg',1,'active'),
(86,41,'SIK-STO-2001-1TB-HS','1 TB / With Heatsink',16999.00,11999.00,26,'assets/images/placeholders/device-ssd.svg',0,'active'),
(87,41,'SIK-STO-2001-2TB-STD','2 TB / Standard',24999.00,17999.00,14,'assets/images/placeholders/device-ssd.svg',0,'active'),
-- Product 42 : Samsung T7 Shield  (Colour + Storage)
(88,42,'SIK-STO-2002-BLK-2TB','Midnight Black / 2 TB',21999.00,15999.00,24,'assets/images/placeholders/device-ssd.svg',1,'active'),
(89,42,'SIK-STO-2002-GRY-2TB','Titanium Grey / 2 TB',21999.00,16299.00,15,'assets/images/placeholders/device-ssd.svg',0,'active'),
(90,42,'SIK-STO-2002-BLK-1TB','Midnight Black / 1 TB',13999.00,10999.00,30,'assets/images/placeholders/device-ssd.svg',0,'active');


-- ---------------------------------------------------------------------------
--  4b. PRODUCT VARIANT ATTRIBUTES
--      attribute 1 = Colour, 2 = Storage, 3 = RAM, 4 = Size, 5 = Model
--      One row per (variant_id, attribute_id) - never repeated.
-- ---------------------------------------------------------------------------
INSERT INTO `product_variant_attributes` (`variant_id`,`attribute_id`,`attribute_value_id`) VALUES
-- Phones : Colour + Storage
(1,1,1),(1,2,11),
(2,1,2),(2,2,12),
(3,1,7),(3,2,13),
(4,1,1),(4,2,10),
(5,1,4),(5,2,11),
(6,1,7),(6,2,12),
(7,1,2),(7,2,11),
(8,1,1),(8,2,12),
(9,1,3),(9,2,13),
(10,1,1),(10,2,10),
(11,1,4),(11,2,11),
(12,1,5),(12,2,12),
(13,1,1),(13,2,11),
(14,1,4),(14,2,12),
(15,1,5),(15,2,11),
(16,1,1),(16,2,10),
(17,1,4),(17,2,11),
(18,1,3),(18,2,12),
(19,1,1),(19,2,10),
(20,1,7),(20,2,11),
(21,1,5),(21,2,12),
(22,1,1),(22,2,11),
(23,1,7),(23,2,12),
(24,1,1),(24,2,10),
(25,1,4),(25,2,11),
(26,1,6),(26,2,12),
-- Laptops : RAM + Storage
(27,3,17),(27,2,11),
(28,3,19),(28,2,12),
(29,3,19),(29,2,13),
(30,3,19),(30,2,12),
(31,3,19),(31,2,13),
(32,3,20),(32,2,13),
(33,3,19),(33,2,12),
(34,3,19),(34,2,13),
(35,3,20),(35,2,13),
(36,3,17),(36,2,12),
(37,3,19),(37,2,12),
(38,3,19),(38,2,13),
(39,3,19),(39,2,12),
(40,3,19),(40,2,13),
(41,3,19),(41,2,12),
(42,3,19),(42,2,13),
(43,3,19),(43,2,13),
(44,3,20),(44,2,13),
(45,3,20),(45,2,14),
(46,3,17),(46,2,12),
(47,3,19),(47,2,12),
(48,3,19),(48,2,13),
-- Tablets : Model + Storage
(49,5,30),(49,2,10),
(50,5,30),(50,2,11),
(51,5,31),(51,2,11),
(52,5,30),(52,2,10),
(53,5,30),(53,2,11),
(54,5,31),(54,2,11),
-- Headphones and speaker : Colour only
(55,1,1),
(56,1,3),
(57,1,4),
(58,1,1),
(59,1,7),
(60,1,4),
(61,1,1),
(62,1,4),
(63,1,6),
-- Watches : Colour + Size
(64,1,1),(64,4,25),
(65,1,3),(65,4,25),
(66,1,8),(66,4,26),
(67,1,1),(67,4,24),
(68,1,3),(68,4,25),
(69,1,5),(69,4,25),
-- Gaming mouse : Colour only
(70,1,1),
(71,1,7),
-- Cameras : Colour + Kit model
(72,1,1),(72,5,27),
(73,1,7),(73,5,27),
(74,1,1),(74,5,28),
(75,1,1),(75,5,27),
(76,1,7),(76,5,27),
(77,1,1),(77,5,28),
-- Keyboard and mouse : Colour only
(78,1,2),
(79,1,7),
(80,1,2),
(81,1,1),
(82,1,7),
-- Mesh router : Colour + Pack size
(83,1,7),(83,4,22),
(84,1,7),(84,4,23),
-- Internal SSD : Storage + Model
(85,2,13),(85,5,27),
(86,2,13),(86,5,28),
(87,2,14),(87,5,27),
-- Portable SSD : Colour + Storage
(88,1,1),(88,2,14),
(89,1,2),(89,2,14),
(90,1,1),(90,2,13);


-- ---------------------------------------------------------------------------
--  5. PRODUCT TAGS
--   1 5G  2 Noise Cancelling  3 Gaming  4 4K  5 Fast Charging
--   6 Water Resistant  7 Wireless  8 Budget Pick  9 Premium
--   10 Creator  11 Work From Home  12 Student
-- ---------------------------------------------------------------------------
INSERT INTO `product_tags` (`product_id`,`tag_id`) VALUES
(1,1),(1,5),(1,9),
(2,1),(2,5),(2,9),
(3,1),(3,5),(3,9),(3,10),
(4,1),(4,5),(4,9),
(5,1),(5,5),(5,9),
(6,1),(6,5),(6,8),
(7,1),(7,9),(7,10),
(8,1),(8,9),(8,10),
(9,1),(9,5),(9,8),
(10,9),(10,11),(10,12),
(11,9),(11,10),(11,11),
(12,9),(12,10),(12,11),
(13,8),(13,11),(13,12),
(14,10),(14,11),(14,12),
(15,8),(15,11),(15,12),
(16,3),(16,9),(16,11),
(17,3),(17,8),(17,12),
(18,9),(18,10),(18,12),
(19,1),(19,10),(19,12),
(20,4),(20,11),
(21,3),(21,4),(21,9),
(22,4),(22,9),(22,11),
(23,2),(23,7),(23,9),
(24,2),(24,7),(24,9),
(25,2),(25,7),(25,8),
(26,3),(26,7),(26,8),
(27,6),(27,7),(27,8),
(28,7),(28,9),(28,11),
(29,6),(29,7),(29,9),
(30,6),(30,7),(30,9),
(31,6),(31,7),(31,8),
(32,3),(32,4),(32,9),
(33,3),(33,11),
(34,9),(34,10),
(35,4),(35,9),(35,10),
(36,4),(36,9),(36,11),
(37,7),(37,9),(37,11),
(38,7),(38,9),(38,11),
(39,8),(39,11),(39,12),
(40,7),(40,9),(40,11),
(41,3),(41,9),(41,11),
(42,6),(42,9),(42,10),
(43,5),(43,7),(43,11),
(44,5),(44,8),(44,11);


-- ---------------------------------------------------------------------------
--  6. PRODUCT RELATIONS
--     4 'related' rows per product, plus cross_sell / bought_together for the
--     flagship phones, laptops and the console.
--     UNIQUE (product_id, related_id, relation_type) is respected and no
--     product is ever related to itself.
-- ---------------------------------------------------------------------------
INSERT INTO `product_relations` (`product_id`,`related_id`,`relation_type`,`sort_order`) VALUES
-- Smartphones
(1,2,'related',1),(1,3,'related',2),(1,7,'related',3),(1,24,'related',4),
(2,1,'related',1),(2,4,'related',2),(2,18,'related',3),(2,24,'related',4),
(3,1,'related',1),(3,4,'related',2),(3,5,'related',3),(3,30,'related',4),
(4,3,'related',1),(4,2,'related',2),(4,5,'related',3),(4,30,'related',4),
(5,3,'related',1),(5,7,'related',2),(5,6,'related',3),(5,9,'related',4),
(6,9,'related',1),(6,5,'related',2),(6,4,'related',3),(6,44,'related',4),
(7,1,'related',1),(7,3,'related',2),(7,8,'related',3),(7,5,'related',4),
(8,3,'related',1),(8,7,'related',2),(8,5,'related',3),(8,35,'related',4),
(9,6,'related',1),(9,4,'related',2),(9,5,'related',3),(9,44,'related',4),
-- Laptops
(10,11,'related',1),(10,18,'related',2),(10,36,'related',3),(10,37,'related',4),
(11,10,'related',1),(11,12,'related',2),(11,36,'related',3),(11,38,'related',4),
(12,11,'related',1),(12,14,'related',2),(12,36,'related',3),(12,16,'related',4),
(13,15,'related',1),(13,14,'related',2),(13,17,'related',3),(13,37,'related',4),
(14,13,'related',1),(14,15,'related',2),(14,12,'related',3),(14,36,'related',4),
(15,13,'related',1),(15,14,'related',2),(15,17,'related',3),(15,38,'related',4),
(16,17,'related',1),(16,12,'related',2),(16,32,'related',3),(16,33,'related',4),
(17,16,'related',1),(17,13,'related',2),(17,33,'related',3),(17,36,'related',4),
-- Tablets
(18,19,'related',1),(18,10,'related',2),(18,24,'related',3),(18,29,'related',4),
(19,18,'related',1),(19,3,'related',2),(19,30,'related',3),(19,4,'related',4),
-- Televisions
(20,21,'related',1),(20,22,'related',2),(20,28,'related',3),(20,32,'related',4),
(21,22,'related',1),(21,20,'related',2),(21,28,'related',3),(21,32,'related',4),
(22,21,'related',1),(22,20,'related',2),(22,28,'related',3),(22,36,'related',4),
-- Audio
(23,24,'related',1),(23,25,'related',2),(23,28,'related',3),(23,10,'related',4),
(24,23,'related',1),(24,26,'related',2),(24,1,'related',3),(24,29,'related',4),
(25,23,'related',1),(25,26,'related',2),(25,27,'related',3),(25,44,'related',4),
(26,25,'related',1),(26,31,'related',2),(26,44,'related',3),(26,9,'related',4),
(27,28,'related',1),(27,26,'related',2),(27,25,'related',3),(27,43,'related',4),
(28,20,'related',1),(28,21,'related',2),(28,27,'related',3),(28,22,'related',4),
-- Wearables
(29,30,'related',1),(29,24,'related',2),(29,1,'related',3),(29,31,'related',4),
(30,29,'related',1),(30,3,'related',2),(30,31,'related',3),(30,4,'related',4),
(31,30,'related',1),(31,26,'related',2),(31,44,'related',3),(31,9,'related',4),
-- Gaming
(32,21,'related',1),(32,16,'related',2),(32,33,'related',3),(32,22,'related',4),
(33,38,'related',1),(33,16,'related',2),(33,17,'related',3),(33,37,'related',4),
-- Cameras
(34,35,'related',1),(34,42,'related',2),(34,43,'related',3),(34,18,'related',4),
(35,34,'related',1),(35,42,'related',2),(35,43,'related',3),(35,36,'related',4),
-- Computer accessories
(36,37,'related',1),(36,38,'related',2),(36,10,'related',3),(36,12,'related',4),
(37,38,'related',1),(37,36,'related',2),(37,10,'related',3),(37,13,'related',4),
(38,37,'related',1),(38,36,'related',2),(38,33,'related',3),(38,11,'related',4),
(39,36,'related',1),(39,13,'related',2),(39,15,'related',3),(39,37,'related',4),
-- Smart home
(40,36,'related',1),(40,39,'related',2),(40,43,'related',3),(40,32,'related',4),
-- Storage
(41,42,'related',1),(41,16,'related',2),(41,32,'related',3),(41,12,'related',4),
(42,41,'related',1),(42,34,'related',2),(42,35,'related',3),(42,43,'related',4),
-- Power
(43,44,'related',1),(43,10,'related',2),(43,1,'related',3),(43,42,'related',4),
(44,43,'related',1),(44,1,'related',2),(44,26,'related',3),(44,9,'related',4),

-- Cross sell : flagship phones, laptops and the console
(1,24,'cross_sell',1),(1,29,'cross_sell',2),(1,44,'cross_sell',3),(1,43,'cross_sell',4),
(2,24,'cross_sell',1),(2,29,'cross_sell',2),(2,44,'cross_sell',3),
(3,30,'cross_sell',1),(3,23,'cross_sell',2),(3,44,'cross_sell',3),
(5,23,'cross_sell',1),(5,44,'cross_sell',2),(5,43,'cross_sell',3),
(7,24,'cross_sell',1),(7,43,'cross_sell',2),
(10,38,'cross_sell',1),(10,36,'cross_sell',2),(10,42,'cross_sell',3),(10,44,'cross_sell',4),
(11,38,'cross_sell',1),(11,36,'cross_sell',2),(11,42,'cross_sell',3),
(12,37,'cross_sell',1),(12,36,'cross_sell',2),(12,41,'cross_sell',3),
(16,33,'cross_sell',1),(16,36,'cross_sell',2),(16,41,'cross_sell',3),
(32,21,'cross_sell',1),(32,23,'cross_sell',2),(32,41,'cross_sell',3),

-- Frequently bought together
(1,44,'bought_together',1),(1,24,'bought_together',2),
(2,44,'bought_together',1),(2,26,'bought_together',2),
(3,44,'bought_together',1),(3,30,'bought_together',2),
(5,44,'bought_together',1),(5,25,'bought_together',2),
(7,44,'bought_together',1),(7,24,'bought_together',2),
(10,44,'bought_together',1),(10,38,'bought_together',2),
(11,44,'bought_together',1),(11,38,'bought_together',2),
(12,44,'bought_together',1),(12,37,'bought_together',2),
(16,33,'bought_together',1),(16,44,'bought_together',2),
(32,33,'bought_together',1),(32,23,'bought_together',2);

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
(1,'FREE SHIPPING on every prepaid order above ₹999','No hidden charges, delivered pan India','truck','shop.php','#0F2143','#FFFFFF',1,DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_ADD(NOW(), INTERVAL 180 DAY),'active'),
(2,'Extra 10% OFF on prepaid orders - pay by UPI, card or netbanking','Discount applied automatically at checkout','tag','deals.php','#0F2143','#FFFFFF',2,DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_ADD(NOW(), INTERVAL 180 DAY),'active'),
(3,'24/7 customer support on 1800-123-4567','Real humans, every day of the week','headset','contact.php','#0F2143','#FFFFFF',3,DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_ADD(NOW(), INTERVAL 180 DAY),'active');

-- ---------------------------------------------------------------------------
--  2. Banners - 3 hero slides + 3 navy promo bands
-- ---------------------------------------------------------------------------
INSERT INTO `banners`
(`id`,`position`,`title`,`title_accent`,`subtitle`,`description`,`badge`,`desktop_image`,`mobile_image`,`button_text`,`button_url`,`button2_text`,`button2_url`,`bg_color`,`text_color`,`sort_order`,`start_date`,`end_date`,`status`) VALUES
(1,'hero','PREMIUM ELECTRONICS AT','UNBEATABLE PRICES','Smartphones, laptops, audio and smart devices - all genuine, all warrantied.','Over 12,000 hand-picked products from Apple, Samsung, Sony, Dell, ASUS and more. Every order ships with an official India warranty, no-cost EMI on select cards and free delivery above ₹999.','WELCOME TO SHOPINNKART','assets/images/banners/hero-1.svg','assets/images/banners/hero-1-mobile.svg','SHOP NOW','shop.php','EXPLORE DEALS','deals.php','#0F2143','#FFFFFF',1,DATE_SUB(NOW(), INTERVAL 20 DAY),DATE_ADD(NOW(), INTERVAL 120 DAY),'active'),
(2,'hero','THE LATEST 5G','SMARTPHONES','Flagship cameras, all-day batteries and blazing fast charging.','The newest 5G phones from Apple, Samsung, OnePlus, Google and Xiaomi, with launch-day pricing, exchange bonuses up to ₹8,000 and 24-month no-cost EMI on leading bank cards.','NEW ARRIVALS ARE HERE','assets/images/banners/hero-2.svg','assets/images/banners/hero-2-mobile.svg','SHOP NOW','shop.php','EXPLORE DEALS','deals.php','#0F2143','#FFFFFF',2,DATE_SUB(NOW(), INTERVAL 14 DAY),DATE_ADD(NOW(), INTERVAL 120 DAY),'active'),
(3,'hero','BIG SAVINGS ON','LAPTOPS & AUDIO','Save up to ₹35,000 on ultrabooks, gaming rigs and noise cancelling audio.','Work, create and play for less. Creator laptops, 165Hz gaming machines and industry-leading noise cancelling headphones, all with genuine warranty and free installation where applicable.','LIMITED PERIOD OFFER','assets/images/banners/hero-3.svg','assets/images/banners/hero-3-mobile.svg','SHOP NOW','shop.php','EXPLORE DEALS','deals.php','#0F2143','#FFFFFF',3,DATE_SUB(NOW(), INTERVAL 7 DAY),DATE_ADD(NOW(), INTERVAL 120 DAY),'active'),
(4,'promo','UP TO','40% OFF','On headphones, earbuds and soundbars','Tune out the commute. Adaptive noise cancellation, 70-hour batteries and Dolby Atmos soundbars at their lowest prices this season.','SPECIAL OFFER','assets/images/banners/promo-1.svg','assets/images/banners/promo-1.svg','GRAB THE OFFER','shop.php?category=audio',NULL,NULL,'#0F2143','#FFFFFF',1,DATE_SUB(NOW(), INTERVAL 5 DAY),DATE_ADD(NOW(), INTERVAL 45 DAY),'active'),
(5,'promo','SAVE UP TO','₹35,000','On ultrabooks, creator and gaming laptops','Core Ultra, Ryzen 7 and Apple silicon machines with instant bank discounts, exchange offers and 24-month no-cost EMI.','SPECIAL OFFER','assets/images/banners/promo-2.svg','assets/images/banners/promo-2.svg','SHOP LAPTOPS','shop.php?category=laptops',NULL,NULL,'#0F2143','#FFFFFF',2,DATE_SUB(NOW(), INTERVAL 5 DAY),DATE_ADD(NOW(), INTERVAL 45 DAY),'active'),
(6,'promo','FLAT','30% OFF','On smart watches and fitness bands','Track sleep, stress, SpO2 and a hundred sport modes. Bluetooth calling watches start at just ₹1,799 this weekend.','WEEKEND DEAL','assets/images/banners/promo-3.svg','assets/images/banners/promo-3.svg','SHOP WEARABLES','shop.php?category=wearables',NULL,NULL,'#0F2143','#FFFFFF',3,DATE_SUB(NOW(), INTERVAL 2 DAY),DATE_ADD(NOW(), INTERVAL 30 DAY),'active');

-- ---------------------------------------------------------------------------
--  3. Trust features - hero overlay (4) + full width strip (6)
-- ---------------------------------------------------------------------------
INSERT INTO `trust_features`
(`id`,`placement`,`title`,`subtitle`,`icon`,`link`,`sort_order`,`status`) VALUES
(1,'hero','Premium Quality','100% genuine, brand sealed stock','badge',NULL,1,'active'),
(2,'hero','Secure Payments','256-bit encrypted checkout','lock',NULL,2,'active'),
(3,'hero','Easy Returns','30 day no-questions returns','refresh',NULL,3,'active'),
(4,'hero','Customer Support','Talk to a real person, any time','headset','contact.php',4,'active'),
(5,'strip','Free Shipping','On prepaid orders above ₹999','truck','shop.php',1,'active'),
(6,'strip','30 Day Returns','Pickup arranged from your door','refresh',NULL,2,'active'),
(7,'strip','Secure Payment','UPI, cards, netbanking and EMI','shield',NULL,3,'active'),
(8,'strip','24/7 Support','Call, chat or email us anytime','headset','contact.php',4,'active'),
(9,'strip','100% Genuine','Official India warranty on every item','verified',NULL,5,'active'),
(10,'strip','Easy EMI','No-cost EMI from ₹999 per month','card',NULL,6,'active');

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
INSERT INTO `testimonials`
(`id`,`customer_name`,`designation`,`avatar`,`rating`,`title`,`message`,`sort_order`,`status`) VALUES
(1,'Aarav Sharma','Verified Buyer','assets/images/placeholders/avatar-1.svg',5,'Delivered a day early, sealed box','Ordered the iPhone 16 Pro on a Tuesday night and it reached Pune on Thursday morning, brand sealed with the India warranty card inside. The no-cost EMI option on my HDFC card worked exactly as shown at checkout.',1,'active'),
(2,'Priya Nair','Verified Buyer','assets/images/placeholders/avatar-2.svg',5,'Best price I found for the XM5','I tracked the Sony WH-1000XM5 for two months across three sites and ShopInnKart had the lowest landed price with the prepaid discount. Noise cancellation on the Kochi metro is genuinely unreal.',2,'active'),
(3,'Rohan Mehta','Verified Buyer','assets/images/placeholders/avatar-3.svg',4,'Great laptop, packaging could be better','The ROG Strix G16 performs exactly as described and runs cool under load. Only reason for four stars is that the outer carton had a dent, though the laptop box inside was untouched. Support responded within an hour when I raised it.',3,'active'),
(4,'Sneha Iyer','Verified Buyer','assets/images/placeholders/avatar-4.svg',5,'Return process was painless','My Galaxy Watch 7 strap did not fit well, so I raised a return on day nine. Pickup was scheduled the next morning and the refund hit my account in four days. That alone made me a repeat customer.',4,'active'),
(5,'Vikram Singh','Verified Buyer','assets/images/placeholders/avatar-5.svg',5,'Free TV installation actually happened','Bought the 55-inch Crystal 4K for the living room. The installation technician called within 24 hours of delivery and set up the wall mount and streaming apps. Zero extra charges, exactly as promised on the product page.',5,'active'),
(6,'Ananya Deshpande','Verified Buyer','assets/images/placeholders/avatar-6.svg',4,'Solid value, quick support','The Redmi Note 14 Pro+ at this price is hard to beat and the 120W charger fills it in about twenty minutes. Had a question about the invoice for warranty purposes and the support team mailed a fresh copy the same day.',6,'active');

-- ---------------------------------------------------------------------------
--  6. Widget instances (homepage builder)
-- ---------------------------------------------------------------------------
INSERT INTO `homepage_sections`
(`id`,`section_key`,`zone`,`widget_type`,`title`,`title_accent`,`subtitle`,`description`,`link_text`,`link_url`,`image`,`mobile_image`,
 `data_source`,`source_id`,`item_limit`,`layout`,`card_style`,`cols_desktop`,`cols_tablet`,`cols_mobile`,
 `autoplay`,`autoplay_speed`,`show_arrows`,`show_dots`,`animation`,`bg_color`,`text_color`,`container`,`padding`,
 `device_visibility`,`auth_visibility`,`lazy_load`,`settings`,`sort_order`,`status`) VALUES

-- zone: home
(1,'home_hero_slider','home','hero',NULL,NULL,NULL,'Full width hero slider driven by the banners table (position = hero).',NULL,NULL,NULL,NULL,
 'manual',NULL,3,'carousel','premium',1,1,1,
 1,6000,1,1,'fade','#0F2143','#FFFFFF','full','none',
 'all','all',0,'{"source_table":"banners","position":"hero","overlay":"navy-gradient","show_trust_row":true}',1,'active'),

(2,'home_announcement_ticker','home','ticker',NULL,NULL,NULL,'Scrolling offer ticker fed by the announcements table.',NULL,NULL,NULL,NULL,
 'auto',NULL,3,'list','minimal',1,1,1,
 1,4000,0,0,'none','#F4511E','#FFFFFF','full','none',
 'all','all',0,'{"source_table":"announcements","direction":"left","pause_on_hover":true}',2,'active'),

(3,'home_trust_strip','home','trust',NULL,NULL,NULL,'Six icon trust strip under the hero (trust_features, placement = strip).',NULL,NULL,NULL,NULL,
 'auto',NULL,6,'grid','minimal',6,3,2,
 0,4000,0,0,'fade-up','#FFFFFF','#0F2143','boxed','sm',
 'all','all',0,'{"source_table":"trust_features","placement":"strip","divider":true}',3,'active'),

(4,'home_shop_by_category','home','category_grid','SHOP BY','CATEGORY','Find what you need in a couple of taps',NULL,'VIEW ALL','shop.php',NULL,NULL,
 'auto',NULL,12,'carousel','circular',8,5,3,
 1,3500,1,0,'fade-up',NULL,NULL,'boxed','md',
 'all','all',0,'{"source_table":"categories","featured_only":false,"shape":"circle","ring_color":"#F4511E","label_position":"below"}',4,'active'),

(5,'home_deal_of_the_day','home','deal_of_day','DEAL OF THE','DAY','Prices drop at midnight - grab them before the timer runs out',NULL,'ALL DEALS','deals.php',NULL,NULL,
 'deal',NULL,4,'grid','premium',4,2,1,
 0,4000,1,0,'fade-up','#FFF6F2',NULL,'boxed','md',
 'all','all',0,'{"show_countdown":true,"show_progress_bar":true,"timer_style":"boxed"}',5,'active'),

(6,'home_flash_sale','home','flash_sale','FLASH','SALE','Live now - limited units at these prices',NULL,'SEE ALL','deals.php?type=flash',NULL,NULL,
 'flash',NULL,8,'carousel','premium',5,3,2,
 1,4500,1,1,'fade-up','#0F2143','#FFFFFF','full','md',
 'all','all',0,'{"show_countdown":true,"show_sold_bar":true,"accent":"#F4511E"}',6,'active'),

(7,'home_new_arrivals','home','product_carousel','NEW','ARRIVALS','Just landed at ShopInnKart',NULL,'VIEW ALL','new-arrivals.php',NULL,NULL,
 'new',NULL,12,'carousel','standard',5,3,2,
 0,4000,1,1,'fade-up',NULL,NULL,'boxed','md',
 'all','all',0,'{"badge":"NEW","show_add_to_cart":true,"cart_button_label":"ADD TO CART"}',7,'active'),

(8,'home_best_sellers','home','product_grid','BEST','SELLERS','What our customers buy most',NULL,'VIEW ALL','best-sellers.php',NULL,NULL,
 'best',NULL,8,'grid','standard',4,3,2,
 0,4000,0,0,'fade-up',NULL,NULL,'boxed','md',
 'all','all',0,'{"show_rank_badge":true,"show_add_to_cart":true,"cart_button_label":"ADD TO CART"}',8,'active'),

(9,'home_featured_picks','home','product_grid','FEATURED','PICKS','Editor approved gear worth your money',NULL,'VIEW ALL','shop.php?featured=1',NULL,NULL,
 'featured',NULL,8,'grid','standard',4,3,2,
 0,4000,0,0,'fade-up',NULL,NULL,'boxed','md',
 'all','all',1,'{"show_add_to_cart":true,"cart_button_label":"ADD TO CART"}',9,'active'),

(10,'home_promo_banner_band','home','promo_banner','SPECIAL','OFFER','Up to 40% off across audio, laptops and wearables',NULL,'GRAB THE OFFER','deals.php','assets/images/banners/promo-1.svg','assets/images/banners/promo-1.svg',
 'manual',NULL,3,'carousel','premium',1,1,1,
 1,5000,1,1,'fade','#0F2143','#FFFFFF','full','lg',
 'all','all',0,'{"source_table":"banners","position":"promo","diagonal_split":true,"accent":"#F4511E"}',10,'active'),

(11,'home_trending_now','home','product_carousel','TRENDING','NOW','Most viewed in the last seven days',NULL,'VIEW ALL','shop.php?sort=trending',NULL,NULL,
 'trending',NULL,12,'carousel','standard',5,3,2,
 1,4000,1,1,'fade-up',NULL,NULL,'boxed','md',
 'all','all',0,'{"show_view_count":true,"show_add_to_cart":true,"cart_button_label":"ADD TO CART"}',11,'active'),

(12,'home_top_brands','home','brand_slider','TOP','BRANDS','Official brand stores, genuine warranty',NULL,'ALL BRANDS','brands.php',NULL,NULL,
 'auto',NULL,18,'carousel','minimal',8,5,3,
 1,3000,1,0,'fade-up','#F7F8FA',NULL,'boxed','md',
 'all','all',0,'{"source_table":"brands","grayscale_until_hover":true}',12,'active'),

(13,'home_why_shop_with_us','home','stats','WHY SHOP','WITH US','Numbers our customers put there',NULL,NULL,NULL,NULL,NULL,
 'auto',NULL,4,'grid','minimal',4,4,2,
 0,4000,0,0,'count-up','#0F2143','#FFFFFF','full','lg',
 'all','all',0,'{"source_table":"site_stats","counter_animation":true,"accent":"#F4511E"}',13,'active'),

(14,'home_customer_reviews','home','testimonials','CUSTOMER','REVIEWS','Straight from verified buyers',NULL,NULL,NULL,NULL,NULL,
 'auto',NULL,6,'carousel','premium',3,2,1,
 1,5500,1,1,'fade-up',NULL,NULL,'boxed','lg',
 'all','all',0,'{"source_table":"testimonials","show_rating_stars":true,"quote_mark":true}',14,'active'),

(15,'home_recently_viewed','home','recently_viewed','RECENTLY','VIEWED','Pick up where you left off',NULL,'CLEAR','#clear-recently-viewed',NULL,NULL,
 'auto',NULL,10,'carousel','compact',5,3,2,
 0,4000,1,0,'fade-up',NULL,NULL,'boxed','md',
 'all','all',1,'{"hide_when_empty":true,"storage":"cookie"}',15,'active'),

(16,'home_newsletter','home','newsletter','STAY IN THE','LOOP','Get ₹500 off your first order plus early access to every flash sale',NULL,'SUBSCRIBE','#newsletter-form','assets/images/banners/newsletter-diagonal.svg','assets/images/banners/newsletter-diagonal.svg',
 'auto',NULL,1,'grid','premium',1,1,1,
 0,4000,0,0,'fade-up','#F4511E','#FFFFFF','full','lg',
 'all','guest',0,'{"style":"diagonal","primary":"#F4511E","secondary":"#0F2143","placeholder":"Enter your email address","consent_text":"We never share your email."}',16,'active'),

-- zone: product_bottom
(17,'product_related_products','product_bottom','product_carousel','RELATED','PRODUCTS','More from this category',NULL,'VIEW CATEGORY','shop.php',NULL,NULL,
 'category',NULL,10,'carousel','standard',5,3,2,
 0,4000,1,1,'fade-up',NULL,NULL,'boxed','md',
 'all','all',1,'{"exclude_current":true,"fallback":"trending"}',1,'active'),

(18,'product_frequently_bought','product_bottom','recommendations','FREQUENTLY','BOUGHT TOGETHER','Customers usually add these as well',NULL,NULL,NULL,NULL,NULL,
 'auto',NULL,4,'grid','horizontal',4,2,1,
 0,4000,0,0,'fade-up','#F7F8FA',NULL,'boxed','md',
 'all','all',1,'{"bundle_mode":true,"show_bundle_total":true,"cart_button_label":"ADD BUNDLE TO CART"}',2,'active'),

-- zone: cart
(19,'cart_recommended_products','cart','recommendations','YOU MIGHT ALSO','LIKE','Accessories that pair well with your cart',NULL,'CONTINUE SHOPPING','shop.php',NULL,NULL,
 'trending',NULL,8,'carousel','compact',4,3,2,
 0,4000,1,0,'fade-up',NULL,NULL,'boxed','md',
 'all','all',1,'{"exclude_cart_items":true,"max_price_ratio":0.5}',1,'active');

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

-- ---- main menu : top level ----
(1,1,NULL,'Home','custom',NULL,'index.php','home',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',1,'active'),
(2,1,NULL,'Smartphones','category',1,'shop.php?category=smartphones','smartphone',NULL,NULL,1,4,'assets/images/banners/mega-phones.svg','shop.php?category=smartphones','1,3,5,7,9',0,'all','all',2,'active'),
(3,1,NULL,'Laptops','category',2,'shop.php?category=laptops','laptop',NULL,NULL,1,4,'assets/images/banners/mega-laptops.svg','shop.php?category=laptops','10,11,12,16,17',0,'all','all',3,'active'),
(4,1,NULL,'Audio','category',5,'shop.php?category=audio','headphone',NULL,NULL,1,4,'assets/images/banners/mega-audio.svg','shop.php?category=audio','23,24,25,27,28',0,'all','all',4,'active'),
(5,1,NULL,'Gaming','category',7,'shop.php?category=gaming','gamepad',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',5,'active'),
(6,1,NULL,'Deals','custom',NULL,'deals.php','tag','HOT','#F4511E',0,4,NULL,NULL,NULL,0,'all','all',6,'active'),
(7,1,NULL,'New Arrivals','custom',NULL,'new-arrivals.php','sparkle','NEW','#0F2143',0,4,NULL,NULL,NULL,0,'all','all',7,'active'),
(8,1,NULL,'Best Sellers','custom',NULL,'best-sellers.php','star',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',8,'active'),
(9,1,NULL,'Brands','custom',NULL,'brands.php','badge',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',9,'active'),
(10,1,NULL,'Contact','custom',NULL,'contact.php','headset',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',10,'active'),

-- ---- main menu : Smartphones mega panel ----
(11,1,2,'Android Phones','category',13,'shop.php?category=android-phones','smartphone',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',1,'active'),
(12,1,2,'iPhones','category',14,'shop.php?category=iphones','smartphone',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',2,'active'),
(13,1,2,'Budget Phones','category',15,'shop.php?category=budget-phones','smartphone','VALUE','#F4511E',0,4,NULL,NULL,NULL,0,'all','all',3,'active'),
(14,1,2,'Smart Watches','category',22,'shop.php?category=smart-watches','watch',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',4,'active'),
(15,1,2,'Fitness Bands','category',23,'shop.php?category=fitness-bands','watch',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',5,'active'),
(16,1,2,'Wireless Earbuds','category',20,'shop.php?category=earbuds','earbuds',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',6,'active'),
(17,1,2,'Chargers & Power Banks','category',12,'shop.php?category=power-cables','battery',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',7,'active'),
(18,1,2,'View All Smartphones','category',1,'shop.php?category=smartphones','arrow',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',8,'active'),

-- ---- main menu : Laptops mega panel ----
(19,1,3,'Gaming Laptops','category',16,'shop.php?category=gaming-laptops','laptop','HOT','#F4511E',0,4,NULL,NULL,NULL,0,'all','all',1,'active'),
(20,1,3,'Ultrabooks','category',17,'shop.php?category=ultrabooks','laptop',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',2,'active'),
(21,1,3,'Business Laptops','category',18,'shop.php?category=business-laptops','laptop',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',3,'active'),
(22,1,3,'Monitors','category',26,'shop.php?category=monitors','monitor',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',4,'active'),
(23,1,3,'Keyboards','category',27,'shop.php?category=keyboards','keyboard',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',5,'active'),
(24,1,3,'Mice','category',28,'shop.php?category=mice','mouse',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',6,'active'),
(25,1,3,'SSD & Components','category',11,'shop.php?category=storage-components','ssd',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',7,'active'),
(26,1,3,'View All Laptops','category',2,'shop.php?category=laptops','arrow',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',8,'active'),

-- ---- main menu : Audio mega panel ----
(27,1,4,'Headphones','category',19,'shop.php?category=headphones','headphone',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',1,'active'),
(28,1,4,'True Wireless Earbuds','category',20,'shop.php?category=earbuds','earbuds',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',2,'active'),
(29,1,4,'Speakers & Soundbars','category',21,'shop.php?category=speakers','speaker',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',3,'active'),
(30,1,4,'Gaming Audio Gear','category',25,'shop.php?category=gaming-accessories','gamepad',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',4,'active'),
(31,1,4,'View All Audio','category',5,'shop.php?category=audio','arrow',NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',5,'active'),

-- ---- mobile menu ----
(32,2,NULL,'Home','custom',NULL,'index.php','home',NULL,NULL,0,4,NULL,NULL,NULL,0,'mobile','all',1,'active'),
(33,2,NULL,'Smartphones','category',1,'shop.php?category=smartphones','smartphone',NULL,NULL,0,4,NULL,NULL,NULL,0,'mobile','all',2,'active'),
(34,2,NULL,'Laptops','category',2,'shop.php?category=laptops','laptop',NULL,NULL,0,4,NULL,NULL,NULL,0,'mobile','all',3,'active'),
(35,2,NULL,'Audio','category',5,'shop.php?category=audio','headphone',NULL,NULL,0,4,NULL,NULL,NULL,0,'mobile','all',4,'active'),
(36,2,NULL,'Gaming','category',7,'shop.php?category=gaming','gamepad',NULL,NULL,0,4,NULL,NULL,NULL,0,'mobile','all',5,'active'),
(37,2,NULL,'Deals','custom',NULL,'deals.php','tag','HOT','#F4511E',0,4,NULL,NULL,NULL,0,'mobile','all',6,'active'),
(38,2,NULL,'New Arrivals','custom',NULL,'new-arrivals.php','sparkle','NEW','#0F2143',0,4,NULL,NULL,NULL,0,'mobile','all',7,'active'),
(39,2,NULL,'Best Sellers','custom',NULL,'best-sellers.php','star',NULL,NULL,0,4,NULL,NULL,NULL,0,'mobile','all',8,'active'),
(40,2,NULL,'Brands','custom',NULL,'brands.php','badge',NULL,NULL,0,4,NULL,NULL,NULL,0,'mobile','all',9,'active'),
(41,2,NULL,'Contact','custom',NULL,'contact.php','headset',NULL,NULL,0,4,NULL,NULL,NULL,0,'mobile','all',10,'active'),

-- ---- footer : quick links ----
(42,3,NULL,'Home','custom',NULL,'index.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',1,'active'),
(43,3,NULL,'Shop','custom',NULL,'shop.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',2,'active'),
(44,3,NULL,'Best Sellers','custom',NULL,'best-sellers.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',3,'active'),
(45,3,NULL,'Deals','custom',NULL,'deals.php',NULL,'HOT','#F4511E',0,4,NULL,NULL,NULL,0,'all','all',4,'active'),
(46,3,NULL,'New Arrivals','custom',NULL,'new-arrivals.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',5,'active'),
(47,3,NULL,'Track Order','custom',NULL,'track-order.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',6,'active'),

-- ---- footer : customer service ----
(48,4,NULL,'FAQs','custom',NULL,'faq.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',1,'active'),
(49,4,NULL,'Shipping Policy','custom',NULL,'page.php?slug=shipping-policy',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',2,'active'),
(50,4,NULL,'Returns','custom',NULL,'page.php?slug=return-policy',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',3,'active'),
(51,4,NULL,'Refunds','custom',NULL,'page.php?slug=refund-policy',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',4,'active'),
(52,4,NULL,'Warranty','custom',NULL,'page.php?slug=warranty-policy',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',5,'active'),
(53,4,NULL,'Contact Us','custom',NULL,'contact.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',6,'active'),

-- ---- footer : about ----
(54,5,NULL,'About Us','custom',NULL,'page.php?slug=about-us',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',1,'active'),
(55,5,NULL,'Blog','custom',NULL,'blog.php',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',2,'active'),
(56,5,NULL,'Privacy Policy','custom',NULL,'page.php?slug=privacy-policy',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',3,'active'),
(57,5,NULL,'Terms & Conditions','custom',NULL,'page.php?slug=terms-conditions',NULL,NULL,NULL,0,4,NULL,NULL,NULL,0,'all','all',4,'active');

-- ---------------------------------------------------------------------------
--  8. Footer builder
-- ---------------------------------------------------------------------------
INSERT INTO `footer_columns` (`id`,`title`,`column_type`,`content`,`sort_order`,`status`) VALUES
(1,'About ShopInnKart','about','ShopInnKart is an India-first electronics marketplace built around one promise: genuine products at a fair price. Every smartphone, laptop, headphone and smart device we list is brand sealed and carries an official India warranty, backed by free shipping above ₹999, 30 day returns and support that answers on the first ring.',1,'active'),
(2,'Quick Links','links',NULL,2,'active'),
(3,'Customer Service','links',NULL,3,'active'),
(4,'Company','links',NULL,4,'active'),
(5,'Contact Us','contact',NULL,5,'active');

INSERT INTO `footer_links` (`id`,`column_id`,`label`,`url`,`open_new_tab`,`sort_order`,`status`) VALUES
-- Quick Links
(1,2,'Home','index.php',0,1,'active'),
(2,2,'Shop','shop.php',0,2,'active'),
(3,2,'Best Sellers','best-sellers.php',0,3,'active'),
(4,2,'Deals','deals.php',0,4,'active'),
(5,2,'New Arrivals','new-arrivals.php',0,5,'active'),
(6,2,'Track Order','track-order.php',0,6,'active'),
-- Customer Service
(7,3,'FAQs','faq.php',0,1,'active'),
(8,3,'Shipping Policy','page.php?slug=shipping-policy',0,2,'active'),
(9,3,'Returns','page.php?slug=return-policy',0,3,'active'),
(10,3,'Refunds','page.php?slug=refund-policy',0,4,'active'),
(11,3,'Warranty','page.php?slug=warranty-policy',0,5,'active'),
(12,3,'Contact Us','contact.php',0,6,'active'),
-- Company
(13,4,'About Us','page.php?slug=about-us',0,1,'active'),
(14,4,'Blog','blog.php',0,2,'active'),
(15,4,'Privacy Policy','page.php?slug=privacy-policy',0,3,'active'),
(16,4,'Terms & Conditions','page.php?slug=terms-conditions',0,4,'active');
-- The categories are reachable from the mega menu, the category bar and the
-- shop filters, so a fourth link column repeating them only widened the footer.

-- ---------------------------------------------------------------------------
--  9. Popups and pop-ins
-- ---------------------------------------------------------------------------
INSERT INTO `popups`
(`id`,`name`,`display_mode`,`popup_type`,`title`,`subtitle`,`content`,`image`,`mobile_image`,`video_url`,`coupon_code`,`product_id`,
 `button_text`,`button_url`,`position`,`size`,`bg_color`,`text_color`,`trigger_type`,`trigger_value`,`frequency`,
 `display_pages`,`device_visibility`,`auth_visibility`,`show_close`,`impressions`,`conversions`,`start_date`,`end_date`,`sort_order`,`status`) VALUES

(1,'Welcome Newsletter Coupon','popup','newsletter','GET ₹500 OFF YOUR FIRST ORDER','Join 50,000+ shoppers who hear about every flash sale first',
 '<p>Subscribe to the ShopInnKart newsletter and we will mail you a ₹500 welcome coupon straight away, valid on any order above ₹4,999.</p><ul><li>Early access to flash sales</li><li>Price drop alerts on your wishlist</li><li>No spam - two mails a week at most</li></ul>',
 'assets/images/popups/welcome-coupon.svg','assets/images/popups/welcome-coupon-mobile.svg',NULL,'WELCOME500',NULL,
 'GET MY COUPON','shop.php','center','md','#0F2143','#FFFFFF','timed',8,'session',
 'home,shop','all','guest',1,0,0,DATE_SUB(NOW(), INTERVAL 10 DAY),DATE_ADD(NOW(), INTERVAL 90 DAY),1,'active'),

(2,'Exit Intent Coupon','popup','coupon','WAIT - HERE IS 10% OFF','Leaving without your cart? Use this before you go',
 '<p>Apply coupon <strong>DONTGO10</strong> at checkout for a flat 10% off, up to ₹3,000, on everything in your cart. Valid for the next 24 hours.</p>',
 'assets/images/popups/exit-offer.svg','assets/images/popups/exit-offer-mobile.svg',NULL,'DONTGO10',NULL,
 'APPLY & CONTINUE','cart.php','center','md','#F4511E','#FFFFFF','exit',0,'daily',
 'shop,product,cart','desktop','all',1,0,0,DATE_SUB(NOW(), INTERVAL 5 DAY),DATE_ADD(NOW(), INTERVAL 60 DAY),2,'active'),

(3,'Free Shipping Reminder','popin','cart_reminder','You are close to FREE shipping','Add ₹999 worth of items and we will drop the delivery fee',
 '<p>Prepaid orders above ₹999 ship free anywhere in India, usually within 2 to 4 working days.</p>',
 'assets/images/popups/free-shipping.svg','assets/images/popups/free-shipping.svg',NULL,NULL,NULL,
 'KEEP SHOPPING','shop.php','bottom-left','sm','#0F2143','#FFFFFF','scroll',35,'session',
 'shop,product,cart','all','all',1,0,0,DATE_SUB(NOW(), INTERVAL 7 DAY),DATE_ADD(NOW(), INTERVAL 120 DAY),3,'active'),

(4,'Limited Stock Alert','popin','stock','Only a few units left','This model has been selling fast in the last 24 hours',
 '<p>Fewer than 15 pieces remain in the warehouse at this price. Orders placed before 6 PM ship the same day.</p>',
 'assets/images/popups/limited-stock.svg','assets/images/popups/limited-stock.svg',NULL,NULL,21,
 'VIEW PRODUCT','product.php?slug=lg-55-oled-evo-c4','bottom-left','sm','#F4511E','#FFFFFF','timed',20,'session',
 'product','all','all',1,0,0,DATE_SUB(NOW(), INTERVAL 3 DAY),DATE_ADD(NOW(), INTERVAL 45 DAY),4,'active');

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
(1,'Deal of the Day: Samsung 55-inch Crystal 4K Smart TV','Flat 31% off - ends tonight','MRP ₹64,990. Today only ₹44,990 with free wall installation and a 1 year comprehensive plus 1 year panel warranty. Limited to 40 units.',20,'percentage',31.00,40,17,'Grab This Deal','product/samsung-55-crystal-4k',DATE_SUB(NOW(), INTERVAL 2 HOUR),DATE_ADD(NOW(), INTERVAL 22 HOUR),'active'),
(2,'Weekend Laptop Fest','Creator and gaming laptops from ₹51,990','Four hand-picked laptops at their lowest price of the season. Doors open this weekend with no-cost EMI on 6 and 9 month tenures.',12,'percentage',12.00,60,0,'Set A Reminder','shop?category=laptops',DATE_ADD(NOW(), INTERVAL 2 DAY),DATE_ADD(NOW(), INTERVAL 5 DAY),'active'),
(3,'Audio Days Clearance','Headphones, earbuds and speakers - sale ended','A four day audio clearance across JBL, Sony and boAt. Stock sold out at 118 of 120 units.',25,'fixed',1000.00,120,118,'Shop The Deal','shop?category=audio',DATE_SUB(NOW(), INTERVAL 12 DAY),DATE_SUB(NOW(), INTERVAL 8 DAY),'inactive');

INSERT INTO `deal_products` (`deal_id`,`product_id`,`deal_price`,`sort_order`) VALUES
-- live deal of the day
(1,20,44990.00,1),
(1,28,28490.00,2),
(1,21,119990.00,3),
-- upcoming weekend laptop fest
(2,12,126990.00,1),
(2,16,128990.00,2),
(2,15,51990.00,3),
(2,14,63990.00,4),
-- expired audio days clearance
(3,25,5999.00,1),
(3,27,7999.00,2),
(3,26,1149.00,3),
(3,23,23490.00,4);

-- ---------------------------------------------------------------------------
--  FLASH SALES
--  1 = running right now (started 1 hour ago, runs for 2 more days)
--  2 = finished last week, kept for the admin history list
-- ---------------------------------------------------------------------------
INSERT INTO `flash_sales`
(`id`,`name`,`subtitle`,`discount_type`,`discount_value`,`stock_limit`,`start_time`,`end_time`,`status`) VALUES
(1,'Midnight Tech Flash Sale','Eight best sellers at flash prices - while stocks last','percentage',15.00,50,DATE_SUB(NOW(), INTERVAL 1 HOUR),DATE_ADD(NOW(), INTERVAL 2 DAY),'active'),
(2,'Weekend Wearables Flash','Smart watches and bands - sale closed','percentage',12.00,40,DATE_SUB(NOW(), INTERVAL 9 DAY),DATE_SUB(NOW(), INTERVAL 7 DAY),'inactive');

INSERT INTO `flash_sale_products` (`flash_sale_id`,`product_id`,`sale_price`,`stock_limit`,`stock_sold`,`sort_order`) VALUES
-- live flash sale: every price is below the current catalogue sale price
(1,24,19999.00,40,23,1),
(1,23,21990.00,30,14,2),
(1,41,8499.00,60,31,3),
(1,9,22999.00,45,27,4),
(1,33,4999.00,35,12,5),
(1,44,2499.00,100,58,6),
(1,26,1099.00,150,96,7),
(1,31,1499.00,120,74,8),
-- finished flash sale
(2,29,39990.00,25,25,1),
(2,30,23999.00,40,38,2),
(2,31,1599.00,100,100,3);

-- ---------------------------------------------------------------------------
--  SECTION 7 : COUPONS
-- ---------------------------------------------------------------------------
INSERT INTO `coupons`
(`id`,`code`,`description`,`type`,`value`,`minimum_order`,`maximum_discount`,`start_date`,`end_date`,`usage_limit`,`per_user_limit`,`used_count`,`status`) VALUES
(1,'WELCOME10','10% off your order, capped at ₹1,500. Valid on orders above ₹2,000.','percentage',10.00,2000.00,1500.00,DATE_SUB(NOW(), INTERVAL 45 DAY),DATE_ADD(NOW(), INTERVAL 60 DAY),1000,1,68,'active'),
(2,'SAVE500','Flat ₹500 off on orders above ₹4,999.','fixed',500.00,4999.00,NULL,DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_ADD(NOW(), INTERVAL 45 DAY),500,2,142,'active'),
(3,'FREESHIP','Free standard delivery on any order above ₹499.','free_shipping',0.00,499.00,NULL,DATE_SUB(NOW(), INTERVAL 60 DAY),DATE_ADD(NOW(), INTERVAL 90 DAY),NULL,5,391,'active'),
(4,'FIRST15','15% off for first time buyers, capped at ₹2,500.','percentage',15.00,1999.00,2500.00,DATE_SUB(NOW(), INTERVAL 20 DAY),DATE_ADD(NOW(), INTERVAL 70 DAY),2000,1,214,'active'),
(5,'AUDIO20','20% off headphones, earbuds, speakers and soundbars. Max ₹3,000.','percentage',20.00,2999.00,3000.00,DATE_SUB(NOW(), INTERVAL 10 DAY),DATE_ADD(NOW(), INTERVAL 25 DAY),300,1,47,'active'),
(6,'APPLE5','Extra 5% off Apple products, capped at ₹5,000.','percentage',5.00,19999.00,5000.00,DATE_SUB(NOW(), INTERVAL 7 DAY),DATE_ADD(NOW(), INTERVAL 30 DAY),250,1,33,'active'),
(7,'LAPTOP2000','Flat ₹2,000 off every laptop above ₹49,999.','fixed',2000.00,49999.00,NULL,DATE_SUB(NOW(), INTERVAL 5 DAY),DATE_ADD(NOW(), INTERVAL 35 DAY),150,1,12,'active'),
(8,'FESTIVE25','Festive week offer - 25% off, capped at ₹5,000. This coupon has expired.','percentage',25.00,9999.00,5000.00,DATE_SUB(NOW(), INTERVAL 40 DAY),DATE_SUB(NOW(), INTERVAL 5 DAY),1500,1,1487,'active');

INSERT INTO `coupon_restrictions` (`coupon_id`,`restriction_type`,`reference_id`) VALUES
-- FIRST15 is only valid when the customer has no previous delivered order
(4,'first_order',NULL),
-- AUDIO20 is limited to the Audio tree (parent 5 plus its three child categories)
(5,'category',5),
(5,'category',19),
(5,'category',20),
(5,'category',21),
-- APPLE5 is limited to the Apple brand
(6,'brand',1),
-- LAPTOP2000 is limited to the Laptops tree
(7,'category',2),
(7,'category',16),
(7,'category',17),
(7,'category',18);

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
(1,'10% instant discount on HDFC Bank Credit Cards','Get 10% instant discount up to ₹3,000 on HDFC Bank Credit Cards and Credit Card EMI transactions. Minimum order value ₹29,999. Offer applies once per card per month.','card','assets/images/placeholders/offer-card.svg','HDFC10',29999.00,DATE_SUB(NOW(), INTERVAL 10 DAY),DATE_ADD(NOW(), INTERVAL 50 DAY),1,'active'),
(2,'5% cashback on every UPI payment','Pay using any UPI app and get 5% cashback up to ₹500 credited to the same UPI handle within 72 hours. Minimum order value ₹1,999. Valid twice per user per month.','upi','assets/images/placeholders/offer-upi.svg','UPI5',1999.00,DATE_SUB(NOW(), INTERVAL 15 DAY),DATE_ADD(NOW(), INTERVAL 60 DAY),2,'active'),
(3,'No-cost EMI from 3 to 12 months','Convert any order above ₹9,999 into no-cost EMI on leading bank credit cards and Debit Card EMI. Interest is discounted upfront and shown before you confirm the order.','emi','assets/images/placeholders/offer-emi.svg',NULL,9999.00,DATE_SUB(NOW(), INTERVAL 20 DAY),DATE_ADD(NOW(), INTERVAL 90 DAY),3,'active'),
(4,'₹250 wallet cashback on Paytm and Mobikwik','Get a flat ₹250 cashback in your wallet on orders above ₹4,999 paid through Paytm Wallet or Mobikwik. Cashback is credited within 24 hours of delivery.','wallet','assets/images/placeholders/offer-wallet.svg','WALLET250',4999.00,DATE_SUB(NOW(), INTERVAL 8 DAY),DATE_ADD(NOW(), INTERVAL 40 DAY),4,'active'),
(5,'Up to ₹4,000 extra exchange bonus','Exchange your old smartphone or laptop and get up to ₹4,000 over and above the quoted exchange value. Pickup happens at delivery and the bonus is adjusted in the order total.','bank','assets/images/placeholders/offer-exchange.svg',NULL,14999.00,DATE_SUB(NOW(), INTERVAL 12 DAY),DATE_ADD(NOW(), INTERVAL 45 DAY),5,'active');

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
-- ===========================================================================
INSERT INTO `pages` (`id`,`title`,`slug`,`content`,`banner_image`,`is_system`,`show_in_footer`,`sort_order`,`status`,`meta_title`,`meta_description`) VALUES

(1,'About Us','about-us','<h2>Who We Are</h2>
<p>{{store_name}} is an online electronics and technology retailer built for Indian shoppers who want the right device at an honest price. Buying a phone, a laptop or a pair of headphones on the internet should feel as informed and as safe as buying from a trusted neighbourhood store, and every process on this site is built around that idea.</p>

<h2>What We Sell</h2>
<p>Our catalogue covers smartphones, laptops, tablets, televisions, audio, wearables, gaming, cameras, computer accessories, smart home devices, storage components and charging gear. Every product listed on {{store_name}} is sourced through authorised national distributors or directly from the brand. That means the serial number on your box is registered for India warranty, the charger inside the carton is the one the manufacturer intended, and the GST invoice we send is valid for a warranty claim or a business expense filing.</p>

<h2>How We Work</h2>
<ul>
<li><strong>Authorised stock only.</strong> We do not sell grey market imports, refurbished units described as new, or unlabelled open box returns.</li>
<li><strong>Honest pricing.</strong> The price on the product page includes GST. Delivery charges, bank offers and coupon savings are all shown before you pay, never after.</li>
<li><strong>Verified specifications.</strong> Our catalogue team checks model numbers, chipsets, panel types, battery capacities and box contents against manufacturer documentation before a product goes live.</li>
<li><strong>Support that answers.</strong> Every ticket has a named owner until it is closed.</li>
</ul>

<h2>Our Fulfilment Network</h2>
<p>Fragile categories such as televisions and monitors are packed with double corrugation, edge guards and a tamper evident seal, and are moved only through partners who accept open box delivery. High value orders travel with a one time password that the courier collects at your door. Serviceability and the delivery estimate for your address are shown on the product page once you enter your pin code.</p>

<h2>Service After The Sale</h2>
<p>A purchase is not finished when the parcel arrives. Our team helps you register warranties, locate the nearest authorised service centre, raise a replacement for a dead on arrival unit, and understand what a manufacturer warranty does and does not cover. Where a brand runs its own service network we hand you a documented case reference rather than a phone number and a shrug.</p>

<h2>Responsible Electronics</h2>
<p>Electronic waste is a real problem in Indian cities. We support the take back and recycling obligations placed on producers and sellers by the E-Waste (Management) Rules 2022, and our shipping cartons use recycled board and paper tape rather than plastic strapping wherever the category allows it. Ask our support desk if you want an old device routed to an authorised recycler.</p>

<h2>Talk To Us</h2>
<p>Use the <a href="{{url:contact}}">contact page</a> to reach the support desk.{{#store_email}} You can also write to <a href="mailto:{{store_email}}">{{store_email}}</a>.{{/store_email}}{{#store_phone}} Phone: {{store_phone}}.{{/store_phone}}{{#business_hours}} Support hours: {{business_hours}}.{{/business_hours}}</p>{{#store_address}}
<p><strong>Registered address:</strong> {{store_address}}</p>{{/store_address}}{{#gst_number}}
<p><strong>GSTIN:</strong> {{gst_number}}</p>{{/gst_number}}','assets/images/banners/page-about.svg',1,1,1,'active','About {{store_name}} | Genuine Electronics Online in India','{{store_name}} is an Indian electronics retailer selling authorised stock with genuine manufacturer warranty, GST invoicing and support that answers. Learn how we work.'),

(2,'Contact Us','contact-us','<h2>We Are Here To Help</h2>
<p>Whether you are choosing between two laptops, tracking a parcel, or raising a warranty claim, our support team can help. Every ticket stays with one owner until it is closed.</p>

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
<li><strong>Brand authorised service centres</strong> receive your invoice and device details only when you ask us to open a warranty case on your behalf.</li>
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
<li>Every price shown on a product page is in Indian Rupees and is inclusive of GST at the rate applicable to that category, currently {{tax_rate}} per cent for most electronics.</li>
<li>Delivery charges, cash on delivery charges, coupon savings and bank offers are shown separately in the order summary before you pay. Nothing is added after payment.</li>
<li>A tax invoice is issued for every order and is available for download from <a href="{{url:orders}}">My Orders</a>. Keep it, because a service centre will ask for it as proof of purchase date.{{#gst_number}} Our GSTIN is {{gst_number}}.{{/gst_number}}</li>
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
<li><a href="{{page:warranty-policy}}">Warranty Policy</a> - manufacturer warranty, dead on arrival and service centres.</li>
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
<li>Loss of data on a device you sent for service or returned to us. Back up your data first and remove any account lock.</li>
<li>Indirect or consequential loss, loss of profit, or loss of business arising from a delayed or defective order.</li>
<li>Third party websites linked from this site, including brand and service centre pages.</li>
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
<p>We deliver across India to pin codes shown as serviceable at checkout. Enter your pin code on any product page to see the estimate for your address before you order. Some categories, in particular large televisions and appliances, are serviceable in fewer pin codes than small accessories because they move on a different network. We do not ship outside India.</p>

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
{{/cod_enabled}}<tr><td>Bulky item handling</td><td>Shown on the product page</td><td>Large televisions and appliances in some pin codes</td></tr>
</tbody>
</table>
<p>Shipping charges are refunded in full when we cancel an order, when the item arrives damaged or wrong, or when a product is found defective. They are not refunded on a change of mind return, because the outbound leg was already performed.</p>

<h2>5. How We Pack</h2>
<ul>
<li>Small electronics travel in a right sized corrugated box with air cushioning, never in a loose envelope.</li>
<li>Televisions, monitors and glass panels are double walled with edge protectors and are marked fragile and this side up.</li>
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
<p>No. The courier is selected by pin code, product size and the service required, such as open box delivery or an appointment for a large television.</p>
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
<tr><td>Smartphones and tablets</td><td>{{return_window_days}} days</td><td>Replacement route, reported within 48 hours for dead on arrival</td><td>Device must be reset and every account lock removed</td></tr>
<tr><td>Laptops and desktops</td><td>{{return_window_days}} days</td><td>Replacement route, reported within 48 hours for dead on arrival</td><td>Activation of bundled software may make a unit non returnable</td></tr>
<tr><td>Televisions and large screens</td><td>Open box refusal at the door only</td><td>Reported within 48 hours with unboxing evidence</td><td>Panel damage after acceptance is not a manufacturing defect</td></tr>
<tr><td>Headphones and earbuds</td><td>Not returnable once the hygiene seal is broken</td><td>{{return_window_days}} days if defective</td><td>Sealed units unopened may be returned</td></tr>
<tr><td>Wearables and smart watches</td><td>{{return_window_days}} days if unworn with the film intact</td><td>{{return_window_days}} days if defective</td><td>Straps that show wear are treated as used</td></tr>
<tr><td>Cameras and lenses</td><td>{{return_window_days}} days</td><td>{{return_window_days}} days if defective</td><td>Shutter count and sensor marks are checked</td></tr>
<tr><td>Accessories, cables and chargers</td><td>{{return_window_days}} days</td><td>{{return_window_days}} days if defective</td><td>Must be unused and in original packaging</td></tr>
<tr><td>Software, licence keys and prepaid codes</td><td>Not returnable</td><td>Replacement only if the key does not activate</td><td>A revealed key cannot be resold</td></tr>
<tr><td>Consumables such as ink and ear tips</td><td>Not returnable once opened</td><td>Replaceable if faulty on first use</td><td>Hygiene and safety restriction</td></tr>
</tbody>
</table>

<h2>3. Condition A Returned Item Must Be In</h2>
<ul>
<li>Unused, undamaged and free of scratches, with all protective film in place where it was supplied.</li>
<li>Complete: charger, cable, adapter, remote, stylus, manual, warranty card, freebies and promotional items all included.</li>
<li>In the original retail box, with the barcode, IMEI or serial label intact and unpeeled.</li>
<li>Reset to factory settings, with Find My, Google account, Mi account or any similar activation lock removed. A locked device cannot be accepted or refunded.</li>
<li>Free of personal data. Back up anything you need before you hand the device over, because we cannot recover it afterwards.</li>
</ul>

<h2>4. What Cannot Be Returned</h2>
<ol>
<li>Any product outside its return window.</li>
<li>Products damaged by misuse, liquid, voltage surge, unauthorised repair or physical impact after delivery.</li>
<li>Items missing the box, a serial label, an accessory or a bundled free item.</li>
<li>Sealed audio products where the hygiene seal has been broken, unless the unit is defective.</li>
<li>Products that were clearly listed as non returnable on the product page at the time of purchase.</li>
<li>Installed or wall mounted televisions and appliances where the fault is cosmetic rather than functional.</li>
</ol>

<h2>5. Damaged, Wrong Or Missing On Arrival</h2>
<p>Report a damaged parcel, a wrong item or a missing item within 48 hours of delivery, with photographs of the outer carton, the seal, the packing material and the product. Do not discard the packaging until the case is closed, because the courier claim depends on it. Where the parcel was visibly tampered with, refuse it at the door; that single step turns a long investigation into a same day replacement.</p>

<h2>6. Defective And Dead On Arrival Units</h2>
<p>A unit that does not power on or shows a clear functional defect within 48 hours of delivery is treated as dead on arrival and is replaced or refunded rather than sent for repair. A defect that appears later is handled as a manufacturer warranty claim under the <a href="{{page:warranty-policy}}">Warranty Policy</a>, which usually means a repair or a brand authorised replacement at a service centre.</p>

<h2>7. How To Raise A Return</h2>
<ol>
<li>Sign in and open <a href="{{url:orders}}">My Orders</a>, select the order and choose Return or Replace on the item.</li>
<li>Pick a reason and upload photographs where the reason is damage, a wrong item or a defect.</li>
<li>We confirm the request and schedule a reverse pickup, usually within two to four working days.</li>
<li>Hand the item over in its original box. Do not paste tape or write on the retail box; use an outer bag or the courier packaging.</li>
<li>The item reaches our quality check, where the serial number, completeness and condition are verified.</li>
<li>On a pass, a refund or a replacement is initiated and you are notified. On a fail, we send you the quality check reason with photographs and ship the item back at no charge to you.</li>
</ol>

<h2>8. Reverse Pickup And Self Ship</h2>
<p>Reverse pickup is free wherever the courier network supports it. If your pin code is not serviceable for pickup we will ask you to ship the item to the address we give you and we reimburse the actual courier charge against a receipt, up to the amount a standard service would have cost. Use a courier that provides tracking, because an untracked parcel that never arrives cannot be refunded.</p>

<h2>9. Replacement Instead Of Refund</h2>
<p>Where you prefer a replacement and stock of the same model, colour and configuration is available, we send the replacement after the returned unit passes quality check. If that variant is out of stock, we convert the request into a refund rather than leaving you waiting indefinitely.</p>

<h2>10. Refunds</h2>
<p>Refund routes, timelines and deductions are set out in the <a href="{{page:refund-policy}}">Refund Policy</a>. In short: the money goes back to the method you paid with, and a cash on delivery order is refunded to a bank account you provide.</p>

<h2>11. Returned Packaging And E-Waste</h2>
<p>Returned packaging is reused or sent to a recycler. If you want to dispose of an old device rather than return a new one, ask support to route it to an authorised recycler under the E-Waste (Management) Rules 2022. Never put a lithium battery in household waste.</p>

<h2>Frequently Asked Questions</h2>
<h3>Does the return window start from the order date or the delivery date?</h3>
<p>The delivery date recorded by the courier.</p>
<h3>I threw away the box. Can I still return the product?</h3>
<p>No. The original retail box with its serial and barcode label is part of the product for a return, and a service centre also needs it for some brands.</p>
<h3>My phone is still linked to my account. What happens?</h3>
<p>Quality check will fail the return and the unit will be sent back to you. Remove the activation lock and factory reset the device before handover.</p>
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
<p>Everything sold here is authorised India stock, which means the warranty is provided by the brand and honoured by its authorised service network anywhere in India. We are the seller. We do not repair devices ourselves and we do not issue a warranty of our own on top of the brand warranty. What we do is make sure your paperwork is correct, that the unit is registered for India service, and that a genuine defect within the first days of delivery is resolved by us rather than sent to a queue. The distinction matters, so it is set out plainly below.</p>
<table>
<thead><tr><th>Question</th><th>Seller responsibility (us)</th><th>Manufacturer warranty (the brand)</th></tr></thead>
<tbody>
<tr><td>Wrong item, missing accessory, damaged parcel</td><td>Ours. Report within 48 hours of delivery</td><td>Not applicable</td></tr>
<tr><td>Dead on arrival within 48 hours of delivery</td><td>Ours. Replacement or refund</td><td>Not applicable</td></tr>
<tr><td>Defect after the return window, inside the warranty period</td><td>We help you open the case and locate a service centre</td><td>Repair or replacement of the defective part</td></tr>
<tr><td>Proof of purchase</td><td>GST invoice, downloadable any time from your order page</td><td>The brand accepts that invoice as the warranty start date</td></tr>
<tr><td>Physical or liquid damage</td><td>Not covered</td><td>Not covered, chargeable repair</td></tr>
<tr><td>Where the repair happens</td><td>Not at our premises</td><td>At a brand authorised service centre</td></tr>
</tbody>
</table>

<h2>2. Warranty Period</h2>
<p>The period is set by the brand for that model and is printed on the product page and in the warranty card inside the box. It runs from the invoice date, which is why the invoice is the document a service centre asks for first. Accessories bundled in the box usually carry a shorter period than the main device, and a battery or an adapter is frequently six months where the device itself is twelve.</p>

<h2>3. What A Warranty Covers</h2>
<ul>
<li>Manufacturing defects in materials and workmanship.</li>
<li>Component failure under normal use inside the warranty period.</li>
<li>Free repair or replacement of the defective part at an authorised service centre.</li>
<li>Battery capacity falling below the threshold the brand defines, where the brand offers that cover.</li>
</ul>

<h2>4. What A Warranty Does Not Cover</h2>
<ul>
<li>Physical damage, cracked screens, bent frames and dents.</li>
<li>Liquid damage, including on devices with a water resistance rating, because that rating is a design specification and not a warranty.</li>
<li>Damage from voltage fluctuation, a non certified charger, or repair by anyone other than an authorised centre.</li>
<li>Normal wear such as scratches, faded printing and battery ageing inside the rated cycle count.</li>
<li>Software problems caused by rooting, jailbreaking, unlocking the bootloader or installing unofficial firmware.</li>
<li>Consumables such as ear tips, cables, printer ink and stylus nibs.</li>
<li>Loss of data. Back it up before any service visit.</li>
</ul>

<h2>5. Dead On Arrival</h2>
<p>If a product does not power on, or shows a clear functional defect, within 48 hours of delivery, treat it as dead on arrival. Report it with photographs or a short video of the fault and, wherever possible, the unboxing. A confirmed dead on arrival unit is replaced or refunded under the <a href="{{page:return-policy}}">Return Policy</a> instead of being sent for repair. Outside that window the brand warranty process applies, and the outcome is normally a repair.</p>

<h2>6. How To Raise A Warranty Claim</h2>
<ol>
<li><strong>Get the invoice.</strong> Download it from <a href="{{url:orders}}">My Orders</a>. It carries the invoice number and date that establish the warranty start.</li>
<li><strong>Prepare the device.</strong> Back up your data and remove every account lock, such as Find My or a linked Google account. A service centre cannot accept a locked device.</li>
<li><strong>Open the case.</strong> Contact the brand authorised service centre directly, or raise a ticket with us and we will locate the nearest authorised centre for your pin code and give you a documented case reference.</li>
<li><strong>Collect the job sheet.</strong> The job sheet is your record of the deposit, the reported fault and the promised turnaround. Do not leave without it.</li>
<li><strong>Track it.</strong> If the service centre exceeds the turnaround it stated, come back to us with the job sheet number and we will follow it up with the brand.</li>
</ol>

<h2>7. Warranty On A Replaced Or Repaired Unit</h2>
<p>A replacement carries the remaining period of the original warranty. It does not restart, unless the brand policy for that model says otherwise. A replaced component is usually warranted for the remainder of the device warranty or for a short period specific to that part, whichever is longer under the brand terms.</p>

<h2>8. Extended Warranty And Protection Plans</h2>
<p>Where a brand or an insurer offers an extended warranty or an accidental damage plan for a product, it appears as an optional add on at checkout with its own terms, and it is a contract between you and that provider. An extended plan begins the day the standard warranty ends and does not run in parallel with it. Read what it excludes before you buy it, because accidental damage plans typically carry an excess and limit the number of claims.</p>

<h2>9. E-Waste And End Of Life</h2>
<p>When a device cannot be repaired economically, do not put it in household waste. Electronics contain recoverable metals and lithium cells that are a fire risk in a landfill. The E-Waste (Management) Rules 2022 place take back and recycling obligations on producers and sellers, and we can route an end of life device to an authorised recycler. Raise a ticket and ask for e-waste disposal.</p>

<h2>10. What We Are Not</h2>
<p>We do not act as an agent of any brand, we do not extend the brand warranty period, and we cannot overrule the technical assessment of an authorised service centre. Where you believe an assessment is wrong, ask for it in writing on the job sheet and escalate it with us; that written assessment is what makes an escalation possible.</p>

<h2>Frequently Asked Questions</h2>
<h3>Is the invoice enough for a warranty claim?</h3>
<p>Yes. The GST invoice from your order page is the proof of purchase date. Keep the warranty card too where the brand asks for it.</p>
<h3>My screen cracked in the first week. Is it covered?</h3>
<p>No. Physical damage is excluded from every manufacturer warranty. An accidental damage plan, if you bought one, is the route for that.</p>
<h3>The device is water resistant but it stopped after a spill.</h3>
<p>Water resistance ratings are tested in laboratory conditions and are not a warranty against liquid ingress. Such damage is a chargeable repair.</p>
<h3>Can you replace the unit instead of sending it for repair?</h3>
<p>Inside the dead on arrival window, yes. After that the brand process governs, and the brand decides between repair and replacement.</p>
<h3>Where is my nearest service centre?</h3>
<p>Raise a ticket with the model and your pin code and we will send you the authorised centre details. Brand websites also publish centre locators.</p>','assets/images/banners/page-policy.svg',1,1,8,'active','Warranty Policy | {{store_name}}','Manufacturer warranty versus seller responsibility at {{store_name}}: dead on arrival, coverage and exclusions, how to raise a claim at a brand service centre, and e-waste disposal.'),

(9,'Frequently Asked Questions','faq','<h2>Ordering</h2>
<p>You can order as a guest or from a registered account. A registered account keeps your addresses, order history, GST invoices, wishlist and return requests in one place, so we recommend it for anything above a low value accessory. After you place an order you receive an email and an SMS with the order number, which begins with {{order_prefix}}. Quote that number in every conversation with us.</p>

<h2>Payments</h2>
<p>{{#cod_enabled}}Cash on delivery is available on serviceable pin codes for orders up to {{cod_max_amount}}, with a {{cod_charge}} handling fee. {{/cod_enabled}}Online methods including UPI, cards, net banking, wallets and EMI appear at checkout as they become available in your region. We never see or store your full card number, CVV or UPI PIN, and no member of our team will ever ask you for a one time password. Full details are in the <a href="{{page:payment-policy}}">Payment Policy</a>.</p>

<h2>Delivery</h2>
<p>Standard delivery costs {{shipping_cost}}{{#free_shipping_enabled}} and is free on orders of {{free_shipping_threshold}} and above{{/free_shipping_enabled}}, arriving in about {{delivery_days}} working days. Enter your pin code on a product page for the exact estimate for your address. See the <a href="{{page:shipping-policy}}">Shipping Policy</a> for dispatch and charges and the <a href="{{page:delivery-policy}}">Delivery Policy</a> for what happens at your door.</p>

<h2>Cancellations, Returns And Refunds</h2>
<p>Orders can be cancelled free of charge within {{cancel_window_hours}} hours and before dispatch. Most products can be returned within {{return_window_days}} days of delivery if they are unused and complete with every accessory and the original box. Reverse pickup is free where serviceable. Refunds go back to the payment method you used. See the <a href="{{page:cancellation-policy}}">Cancellation</a>, <a href="{{page:return-policy}}">Return</a> and <a href="{{page:refund-policy}}">Refund</a> policies.</p>

<h2>Warranty</h2>
<p>Every product is authorised India stock with a manufacturer warranty serviced by the brand across the country. The invoice in <a href="{{url:orders}}">My Orders</a> is your proof of purchase. If a product fails within 48 hours of delivery, report it as dead on arrival and we will replace or refund it rather than sending you to a service centre. See the <a href="{{page:warranty-policy}}">Warranty Policy</a>.</p>

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
<li>Large appliance orders where installation has already been performed at your address.</li>
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
<li>Every price is in Indian Rupees and includes GST at the rate applicable to that product, currently {{tax_rate}} per cent for most electronics categories.</li>
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
<p>A tax invoice is generated for every order and is available from <a href="{{url:orders}}">My Orders</a> as soon as the order is dispatched. It shows the taxable value, the tax split and the invoice number, and it is the document a service centre uses to establish the warranty start date.{{#gst_number}} Our GSTIN is {{gst_number}}.{{/gst_number}} To claim input credit, enter your business name and GSTIN before placing the order; a tax invoice cannot be reissued to a different recipient afterwards.</p>

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
<p>The estimate shown at checkout is calculated from your pin code, the product category and the current dispatch queue, and is typically about {{delivery_days}} working days. It is a working day estimate and not an appointment for a particular hour. Sundays and public holidays are not counted. Bulky items such as large televisions are scheduled by the delivery partner, who will call to agree a slot rather than simply arriving.</p>

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
<li>On an open box delivery, do the model, colour, storage variant and serial number match the invoice?</li>
<li>Are the accessories listed on the box actually inside it?</li>
</ol>
<p>If any answer is no, refuse the parcel. Refusal at the door is the fastest resolution route and costs you nothing. If you have already accepted it, report the problem within 48 hours with photographs of the carton, the seal and the product, and keep all the packaging until the case is closed.</p>

<h2>7. Installation And Demonstration</h2>
<p>Large televisions and appliances that need installation are installed by the brand authorised technician, not by the delivery agent. Installation is normally scheduled within a few working days of delivery, and the brand contacts you to arrange it. Do not let an unauthorised person install the unit, because that can void the manufacturer warranty. Wall brackets, stabilisers and additional cabling are usually chargeable extras quoted by the technician.</p>

<h2>8. Rescheduling And Address Changes</h2>
<p>Reschedule from the courier tracking link, which is the only system the delivery agent actually sees. An address can be changed only before dispatch, from your order page. After dispatch, a change is at the discretion of the courier and is often limited to a nearby location within the same city.</p>

<h2>9. Marked Delivered But Not Received</h2>
<p>Report it within 48 hours. We ask the courier for the proof of delivery, which is the signature, the photograph or the one time password captured at the door, and we share the outcome with you. Where the delivery cannot be substantiated we replace the item or refund it in full. Check with family, neighbours and the building security desk first, because that resolves a large share of these cases immediately.</p>

<h2>10. Delivery Of Old Devices And E-Waste</h2>
<p>If you want to hand over an old device for recycling, arrange it with support before the delivery date rather than at the door, because a delivery agent is not authorised to accept an unlisted item. End of life electronics are routed to an authorised recycler in line with the E-Waste (Management) Rules 2022.</p>

<h2>Frequently Asked Questions</h2>
<h3>Can I get delivery at a specific time?</h3>
<p>Standard parcels are delivered in the courier normal working window. Only large appliances and televisions are slot scheduled by appointment.</p>
<h3>The agent asked for the OTP before showing the parcel.</h3>
<p>Do not share it. The one time password confirms that the parcel was handed to you. Report the incident with the tracking number.</p>
<h3>Can I open the parcel before paying on a cash on delivery order?</h3>
<p>Only where open box delivery is enabled for that product. Otherwise payment is collected first, and you still have the {{return_window_days}} day return window afterwards.</p>
<h3>Nobody was home. What now?</h3>
<p>The courier attempts again on the next working day. Use the tracking link to reschedule or to ask for a branch pickup where that is offered.</p>
<h3>Who installs my television?</h3>
<p>A brand authorised technician, arranged after delivery. The delivery agent only hands over the carton.</p>','assets/images/banners/page-policy.svg',1,1,13,'active','Delivery Policy | {{store_name}}','What happens at your door when a {{store_name}} order arrives: delivery attempts, one time password and identity checks, open box delivery, installation, rescheduling and missing parcel claims.');

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
(7,'Returns','What is your return window?','Most products can be returned within 7 days of delivery. The item must be unused and undamaged, and must come back with every accessory, the manual, the warranty card, any free gift and the original brand box with its serial number sticker intact. Raise the request from My Orders and we arrange a free reverse pickup.',1,'active'),
(8,'Returns','Which products cannot be returned?','Sealed in-ear earphones and earbuds cannot be returned once the seal is broken, unless the unit is defective. We also cannot accept activated software licences, used consumables such as printer ink, products damaged after delivery, items marked final sale, and devices still locked to an account such as Find My or a Google account.',2,'active'),
(9,'Returns','My product arrived damaged. What should I do?','Report it within 48 hours of delivery with photographs of the product, the accessories and the outer carton. Damage and dead on arrival cases are prioritised, and we send a replacement or a full refund without any shipping or restocking charge. If the outer box looks torn, wet or resealed at the door, refuse the parcel and tell us the same day.',3,'active'),
(10,'Payments','Which payment methods can I use?','Cash on Delivery is live on serviceable pin codes for orders up to ₹50,000. Online options including UPI, credit and debit cards, net banking, wallets and no-cost EMI are enabled gateway by gateway and appear at checkout as soon as they are available in your region.',1,'active'),
(11,'Payments','Is it safe to pay online on ShopInnKart?','Yes. The site runs entirely over HTTPS and online payments are processed by PCI DSS compliant gateways. Card numbers, CVV and UPI PINs are entered on the gateway and never reach our servers. We only store the payment reference and the amount. No one from ShopInnKart will ever ask you for a one time password, a PIN or a screen sharing session.',2,'active'),
(12,'Payments','When will I get my refund?','Refunds start within 24 hours of a cancellation, or within 48 hours of a returned item passing our quality check. UPI, net banking and wallet refunds reach you in 3 to 5 working days, card refunds in 5 to 7 working days, and Cash on Delivery refunds are sent by NEFT to a bank account in your name within 5 to 7 working days.',3,'active'),
(13,'Products','Are the products on ShopInnKart genuine and covered by India warranty?','Every product is sourced through an authorised national distributor or directly from the brand, so the serial number is registered for India warranty and can be serviced at any authorised centre in the country. We do not sell grey market imports, and we do not list refurbished units as new.',1,'active'),
(14,'Products','What does no-cost EMI actually mean?','No-cost EMI means the interest that your bank charges over the tenure is discounted from the product price upfront, so the total you repay equals the price shown on the product page. The discount and the exact monthly instalment are displayed before you confirm the order. Processing fees charged by some banks are not part of the product price.',2,'active'),
(15,'Account','How do I reset my password?','Click Forgot Password on the sign in page and enter your registered email address. We send a reset link that stays valid for 60 minutes and can be used once. If the email does not arrive within a few minutes, check the spam folder and confirm you are using the address the account was created with.',1,'active'),
(16,'Account','How do I stop marketing emails without closing my account?','Use the unsubscribe link at the bottom of any marketing email, or turn off communication preferences in My Account. Unsubscribing stops offers and newsletters only - you will still receive transactional messages such as order confirmations, dispatch updates and refund notices, because those are part of the service you asked for.',2,'active');

-- ---------------------------------------------------------------------------
--  BLOG
-- ---------------------------------------------------------------------------
INSERT INTO `blog_categories` (`id`,`name`,`slug`,`description`,`sort_order`,`status`) VALUES
(1,'Buying Guides','buying-guides','Practical guides that help you match a device to how you actually use it.',1,'active'),
(2,'Reviews','reviews','Long term hands-on impressions of the products we sell.',2,'active'),
(3,'How To','how-to','Setup walkthroughs, troubleshooting and getting more out of your gear.',3,'active'),
(4,'Tech Explained','tech-explained','Jargon decoded, so a spec sheet stops being a wall of acronyms.',4,'active');

INSERT INTO `blog_posts`
(`id`,`category_id`,`admin_id`,`title`,`slug`,`excerpt`,`content`,`featured_image`,`author_name`,`views`,`is_featured`,`status`,`published_at`,`meta_title`,`meta_description`) VALUES

(1,1,1,'How to Choose a Smartphone in India: A Practical Buying Guide','how-to-choose-a-smartphone-buying-guide','Chipset, display, battery, camera and update policy - the five things that decide whether a phone still feels good two years from now, and the marketing numbers you can safely ignore.','<p>Most phone comparisons drown you in numbers. Very few of those numbers change how the phone feels after six months. This guide narrows the decision to five things that genuinely matter, and names the specifications you can ignore without regret.</p>

<h2>1. Start With Budget Bands, Not Brands</h2>
<p>The Indian market splits cleanly into bands, and each band has a different weak point. Under ₹20,000 the compromise is usually the camera in low light and the speed of software updates. Between ₹20,000 and ₹40,000 you get flagship grade displays and fast charging, but the main camera sensor is often the previous generation. Above ₹60,000 the differences are about polish - build materials, video quality, haptics and how long the phone stays supported. Decide your band first, then compare only within it.</p>

<h2>2. The Chipset Decides How Long The Phone Lasts</h2>
<p>A processor two generations old will run today apps fine and struggle in three years. Look at the manufacturing node and the efficiency cores rather than raw benchmark scores, because sustained performance and battery life depend far more on heat than on peak numbers. A phone that scores slightly lower but holds its clock speed through a 30 minute gaming session is the better buy.</p>

<h2>3. Display: Refresh Rate Matters Less Than Brightness</h2>
<p>Almost every phone above ₹15,000 now has a 120Hz panel, so refresh rate is no longer a differentiator. Peak brightness in direct sunlight is. Look for a rated high brightness mode above 1,000 nits if you use your phone outdoors, and check whether the panel uses high frequency PWM dimming if you are sensitive to flicker at night.</p>

<h2>4. Battery And Charging Are A Trade Off</h2>
<p>A 5,000mAh cell with an efficient chipset comfortably clears a day. Very fast charging above 100W is convenient, but it adds heat and, on some designs, splits the battery into two smaller cells. If you charge overnight, a 45W phone with a larger single cell will usually age better. If you charge in short bursts between meetings, fast charging is worth the trade.</p>

<h2>5. The Camera Question</h2>
<p>Megapixels stopped being useful years ago. What separates a good camera phone is the main sensor size, optical stabilisation, and the processing pipeline. A 50MP sensor with a large 1/1.3 inch format and stabilisation will beat a 200MP sensor in a smaller format almost every time in low light. Ignore the 2MP macro and depth cameras entirely - they exist to lengthen the spec sheet.</p>

<h2>What To Ignore</h2>
<ul>
<li>Any camera below 8MP on the rear panel</li>
<li>Benchmark scores quoted without a sustained load test</li>
<li>Water resistance ratings as a reason to be careless, since liquid damage is never covered by warranty</li>
<li>Bundled cases and screen guards, which cost very little to replace</li>
</ul>

<h2>Before You Pay</h2>
<p>Check the update policy in writing, confirm the India warranty on the product page, and compare the effective price after bank offers and exchange bonus rather than the sticker price. A phone that is ₹3,000 more expensive but supported for three extra years is the cheaper phone.</p>','assets/images/placeholders/blog-1.svg','Ananya Iyer',4820,1,'published',DATE_SUB(NOW(), INTERVAL 3 DAY),'How to Choose a Smartphone in India 2026 | ShopInnKart Buying Guide','A practical smartphone buying guide for Indian buyers: how to weigh chipset, display brightness, battery, camera and update policy, and which specs to ignore.'),

(2,1,1,'Laptop Buying Guide: Match the Processor, RAM and Storage to Your Actual Work','laptop-buying-guide-processor-ram-storage','Students, developers, editors and spreadsheet users need very different machines. Here is how to translate what you do all day into a configuration, without overpaying for headroom you will never use.','<p>The fastest way to waste money on a laptop is to buy a configuration meant for someone else. A video editor and a commerce student can look at the same 14 inch chassis and need completely different silicon inside it. Start with your workload.</p>

<h2>Work Out Your Category First</h2>
<ul>
<li><strong>Study and office:</strong> browser tabs, documents, video calls. A current generation mid range processor with 16GB of memory and a 512GB SSD is plenty, and battery life matters more than raw speed.</li>
<li><strong>Software development:</strong> containers, virtual machines and compilers punish low memory. 16GB is the floor, 32GB is comfortable, and core count helps more than clock speed.</li>
<li><strong>Photo and video:</strong> a colour accurate display and a discrete GPU with at least 6GB of video memory. Storage fills fast, so plan for 1TB or an external drive from day one.</li>
<li><strong>Gaming:</strong> the GPU and its total graphics power rating decide frame rates. Two laptops with the same GPU name can differ by 30% if one runs at 140W and the other at 60W.</li>
</ul>

<h2>RAM: How Much Is Actually Enough</h2>
<p>8GB is now the bare minimum and it will feel tight within two years, especially since many thin laptops solder the memory and cannot be upgraded later. 16GB is the sensible default for almost everyone. Go to 32GB only if you run virtual machines, large datasets or heavy timelines. Also check whether the memory is dual channel - single channel memory can cost you a noticeable amount of integrated graphics performance.</p>

<h2>Storage: Speed And Headroom</h2>
<p>Insist on an NVMe solid state drive. A SATA drive or, worse, a mechanical hard drive will make an otherwise fast laptop feel sluggish at every boot and every file save. 512GB suits most people, 1TB suits creators and gamers. Check whether there is a second M.2 slot, because adding a drive later is the cheapest meaningful upgrade a laptop can take.</p>

<h2>Display: The Part You Stare At</h2>
<p>Resolution matters less than panel quality. A well calibrated 1920 by 1200 IPS panel beats a dim 4K panel for daily work. Look for a matte finish if you work near windows, 300 nits or more of brightness, and full sRGB coverage. Creators should look for 100% DCI-P3 and a factory calibration report. OLED panels look spectacular and cost battery life, which is a fair trade for editing and a poor one for eight hour meeting days.</p>

<h2>The Details That Decide Daily Comfort</h2>
<ul>
<li>Port selection - one USB-C that also charges the machine saves carrying a brick</li>
<li>Keyboard travel and a trackpad large enough for gestures</li>
<li>Weight under 1.5kg if you commute with it daily</li>
<li>Fan behaviour under load, since a laptop that whines during video calls gets left at home</li>
<li>Service coverage in your city, especially for onsite warranty</li>
</ul>

<h2>A Simple Rule</h2>
<p>Spend on the parts you cannot change later - the display, the keyboard, the chassis and the memory if it is soldered. Save on storage, which you can nearly always add, and on bundled software you will uninstall in the first week.</p>','assets/images/placeholders/blog-2.svg','Rohan Kapoor',3675,1,'published',DATE_SUB(NOW(), INTERVAL 9 DAY),'Laptop Buying Guide 2026: Processor, RAM and Storage | ShopInnKart','How to pick a laptop configuration that matches your real workload - memory, storage, display quality and the specifications worth paying extra for.'),

(3,1,1,'Noise Cancelling Headphones or True Wireless Earbuds? How to Decide','headphones-vs-true-wireless-earbuds','Over-ear cans and tiny earbuds solve the same problem in opposite ways. Commute length, call quality, comfort in Indian weather and battery anxiety should decide which one you buy.','<p>Both categories promise silence and good sound. They deliver it very differently, and the right answer depends far more on your day than on a frequency response graph.</p>

<h2>Noise Cancellation Is Not One Thing</h2>
<p>Over-ear headphones cancel low frequency rumble better because the ear cup physically blocks sound before the electronics get involved. That makes them the clear winner on flights, in metro coaches and near a running air conditioner. Earbuds rely on a tight seal in the ear canal, so their performance swings wildly with tip size. If you have never tried the medium tips against the small ones, you have probably never heard what your earbuds can actually do.</p>

<h2>Comfort In Indian Conditions</h2>
<p>An over-ear pair traps heat. In a Chennai or Mumbai summer, or on a two wheeler commute, that becomes uncomfortable within twenty minutes. Earbuds are far more forgiving in humidity and take no space in a bag. On the other hand, some people cannot tolerate in-canal pressure for long, and for them a lightweight on-ear or over-ear pair is the only comfortable option.</p>

<h2>Call Quality</h2>
<p>Earbuds put the microphone closer to your mouth and usually win on voice clarity in a quiet room. In wind or heavy traffic, headphones with beamforming microphone arrays hold up better. If most of your calls happen from a desk, either works. If you take calls while walking on a main road, test with a real call before you commit.</p>

<h2>Battery And Charging Behaviour</h2>
<ul>
<li>Over-ear pairs run 30 to 70 hours per charge and a three minute top up can add several hours</li>
<li>Earbuds run 5 to 8 hours per bud, with the case adding three to five refills</li>
<li>Earbud cases are easy to lose and easy to forget to charge, which is a real cost of ownership</li>
</ul>

<h2>Sound And Codecs</h2>
<p>Larger drivers in an over-ear pair move more air, which gives a broader soundstage and better bass texture. Good earbuds close the gap on detail but rarely on scale. Codec support matters less than most people think - a well tuned pair on AAC will beat a badly tuned pair on a high resolution codec every time.</p>

<h2>Quick Recommendation</h2>
<p>Choose over-ear if your commute is long, you work in a noisy shared space, you take many long calls, or you listen for pleasure rather than as background. Choose true wireless if you move around a lot, carry a small bag, exercise while listening, or live somewhere hot and humid. Plenty of people end up owning both, using headphones at the desk and earbuds on the move.</p>','assets/images/placeholders/blog-3.svg','Meera Raghavan',2914,0,'published',DATE_SUB(NOW(), INTERVAL 15 DAY),'Noise Cancelling Headphones vs True Wireless Earbuds | ShopInnKart','Which suits you better - over-ear ANC headphones or true wireless earbuds? Compare noise cancellation, comfort, call quality, battery and sound.'),

(4,2,1,'Six Months With the Sony WH-1000XM5: What the Reviews Do Not Tell You','sony-wh-1000xm5-six-month-review','Battery behaviour after 180 charge cycles, how the fabric headband survives an Indian summer, and whether multipoint pairing has actually settled down. A long term look at a best seller.','<p>Launch reviews measure a product in week one. What matters is week twenty six, when the novelty is gone and the small annoyances become permanent. Here is what six months of daily use with the WH-1000XM5 looks like.</p>

<h2>Noise Cancellation Still Leads, With One Exception</h2>
<p>On flights and in metro coaches the cancellation remains exceptional - engine drone effectively disappears and cabin announcements come through Speak-to-Chat without lifting a cup. The exception is wind. Walking on an open road on a windy evening produces a low roar that competitors with better wind detection handle more gracefully. Turning on ambient mode fixes it, at the cost of the silence you paid for.</p>

<h2>Comfort And The Headband</h2>
<p>The soft fabric headband is the reason this pair can sit on your head for a six hour work session without pressure points. It is also the part that shows wear first. After six months of Bengaluru weather ours has picked up a slight sheen where it meets the head. It cleans up with a damp cloth, but the fabric will not stay pristine the way a leatherette band would.</p>

<h2>Battery After 180 Cycles</h2>
<p>The rated 30 hours with cancellation on is honest. After roughly 180 partial charge cycles we measure about 27 hours at the same volume level, which is normal ageing and still far beyond a typical week of commuting. The three minute quick charge remains the single most useful feature on a morning you forgot to plug in.</p>

<h2>Multipoint Pairing</h2>
<p>Connecting to a laptop and a phone at the same time worked reliably for us after the firmware updates that arrived in the first few months. Switching is not instant - expect a pause of a second or two when a call arrives on the phone while music plays on the laptop. It is far better than it was at launch.</p>

<h2>The Case Is A Compromise</h2>
<p>The XM5 no longer folds flat, so the case is larger than the one that came with the previous generation. In a slim laptop sleeve it is a genuine inconvenience. If you commute with a small bag, hold this against it before buying.</p>

<h2>Would We Buy It Again</h2>
<ul>
<li><strong>Yes if:</strong> you fly often, work in an open office, or take long calls</li>
<li><strong>Yes if:</strong> long session comfort is your first priority</li>
<li><strong>Think twice if:</strong> you need a compact folding design for a small bag</li>
<li><strong>Think twice if:</strong> you spend most of your listening time outdoors in wind</li>
</ul>

<p>Six months in, it remains the pair we reach for first, and the one we recommend most often to people who ask for headphones that make a noisy day quieter.</p>','assets/images/placeholders/blog-4.svg','Aditya Sen',5231,1,'published',DATE_SUB(NOW(), INTERVAL 21 DAY),'Sony WH-1000XM5 Long Term Review After Six Months | ShopInnKart','A six month review of the Sony WH-1000XM5: noise cancellation in real conditions, headband wear, battery after 180 cycles and multipoint pairing.'),

(5,3,1,'How to Set Up Mesh Wi-Fi in an Indian Apartment Without Dead Zones','how-to-set-up-mesh-wifi-apartment','Placement beats hardware. A step by step walkthrough for covering a 2BHK or 3BHK with mesh Wi-Fi, including where not to put a node and how to test whether it worked.','<p>Most people who buy a mesh system and still get a weak signal in the bedroom have a placement problem, not a hardware problem. Indian apartments are built with dense brick and reinforced concrete walls that absorb 5GHz signal far more aggressively than the drywall these products are designed around. Here is how to work with that.</p>

<h2>Step 1: Find Where Your Internet Enters</h2>
<p>The main node has to sit near the fibre or cable termination point, since it needs a wired connection to the modem or ONT. That is usually a corner of the living room or a utility shaft, which is the worst possible position for coverage. Accept it, and plan the satellite nodes to compensate rather than fighting it.</p>

<h2>Step 2: Place Nodes At Two Thirds Distance</h2>
<p>A satellite node should sit roughly two thirds of the way between the main node and the dead zone, not inside the dead zone. A node that can barely hear the main unit will faithfully rebroadcast a weak signal. Keep every node at waist height or higher, in the open, and never inside a TV cabinet or behind a wardrobe.</p>

<h2>Step 3: Avoid These Positions</h2>
<ul>
<li>On the floor, where signal is absorbed by furniture and people</li>
<li>Inside a metal or glass cabinet, which acts as a shield</li>
<li>Next to a microwave, cordless phone base or a large mirror</li>
<li>Directly against an exterior wall, where half the coverage is wasted outdoors</li>
<li>Beside the inverter or the meter box, which is electrically noisy</li>
</ul>

<h2>Step 4: Use A Wired Backhaul Where You Can</h2>
<p>If a network cable already runs between rooms, or you can run one along the skirting, use it. A wired backhaul frees the whole radio capacity for your devices and typically doubles real world throughput on the far node. Many apartments built after 2015 already have unused LAN points in the bedrooms - check behind the switchboards before assuming you need to drill.</p>

<h2>Step 5: Configure It Properly</h2>
<p>Use one network name for both bands and let the system steer devices. Set the channel to automatic first, then check the app for interference. In a dense apartment block, manually selecting a less crowded 5GHz channel often helps more than moving the hardware. Turn on WPA3 if all your devices support it, and give the guest network its own name so smart plugs and cameras stay off your main network.</p>

<h2>Step 6: Test Like A Scientist</h2>
<p>Run a speed test standing in the same three spots before and after each change, at the same time of day, with the same device. Note the numbers. Without a baseline you will chase your own placebo effect for a whole weekend. A good result in a 3BHK is at least half your plan speed in the farthest bedroom.</p>

<h2>When Two Nodes Are Not Enough</h2>
<p>Long apartments with a corridor, duplexes and homes with a kitchen wall carrying an embedded steel mesh often need a third node. Adding one is far cheaper and far more effective than upgrading to a more expensive two pack.</p>','assets/images/placeholders/blog-5.svg','Nikhil Rao',2168,0,'published',DATE_SUB(NOW(), INTERVAL 30 DAY),'How to Set Up Mesh Wi-Fi in an Indian Apartment | ShopInnKart','Step by step mesh Wi-Fi setup for Indian homes: node placement, wired backhaul, channel settings and how to test coverage properly.'),

(6,4,1,'Wi-Fi 7, USB4 and Bluetooth LE Audio: What the New Acronyms Actually Change','wifi-7-usb4-bluetooth-le-audio-explained','Three standards are appearing on spec sheets across phones, laptops and audio gear. Here is what each one really does, what you need on both ends, and whether it should change what you buy this year.','<p>Every few years a batch of new standards lands on spec sheets at once, and the marketing arrives well before the benefit does. Three are worth understanding right now, because they affect what you should buy today and what you can safely wait on.</p>

<h2>Wi-Fi 7: Useful Mostly For Congestion</h2>
<p>The headline of Wi-Fi 7 is not raw speed, since almost no home connection saturates Wi-Fi 6 already. The real change is Multi-Link Operation, which lets a device use two bands at once and switch instantly when one gets busy. In a dense apartment block with forty neighbouring networks, that translates into far steadier latency for video calls and gaming, even if the peak number barely moves. You need a Wi-Fi 7 router and a Wi-Fi 7 client to see any of it, and the 6GHz band it depends on has only recently been opened for unlicensed use in India.</p>

<h2>USB4 And Thunderbolt: Read The Small Print</h2>
<p>USB naming remains a mess. USB4 guarantees a floor of 20Gbps and, on certified 40Gbps ports, adds tunnelled PCIe and DisplayPort. What that means in practice is one cable carrying an external SSD, two monitors and 100W of charging. The catch is that the port, the cable and the dock all have to support the same tier. A cable rated only for charging will silently cap your data rate. Look for the number printed on the port or in the specification sheet, not the shape of the connector.</p>

<h2>Bluetooth LE Audio And Auracast</h2>
<p>LE Audio replaces the old SBC and AAC pipeline with the LC3 codec, which sounds better at lower bitrates. Lower bitrate means longer battery life on earbuds, and enough headroom to run two independent streams for true stereo without a relay between buds. Auracast is the interesting part: a single transmitter can broadcast audio to unlimited nearby receivers, which is how airports, gyms and cinemas will eventually send audio directly to your earbuds.</p>

<h2>Should You Wait?</h2>
<ul>
<li><strong>Wi-Fi 7:</strong> worth it in a new router if you live in a crowded building, otherwise Wi-Fi 6E remains excellent value</li>
<li><strong>USB4:</strong> worth insisting on in a laptop you plan to keep for four years, since docks outlive machines</li>
<li><strong>LE Audio:</strong> nice to have, not a reason to replace working earbuds, and the ecosystem is still filling in</li>
</ul>

<h2>The Practical Rule</h2>
<p>Buy the new standard when it lives in the device you will keep longest. Routers and laptops stay for years, so future proofing there pays off. Earbuds and phones turn over faster, so pay for what works today rather than for a feature waiting on the rest of the world to catch up.</p>','assets/images/placeholders/blog-6.svg','Sanjana Bhat',1893,0,'published',DATE_SUB(NOW(), INTERVAL 42 DAY),'Wi-Fi 7, USB4 and Bluetooth LE Audio Explained | ShopInnKart','What Wi-Fi 7, USB4 and Bluetooth LE Audio actually change for everyday users, what you need on both ends, and which one is worth paying for now.');

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
(1,11,DATE_SUB(NOW(), INTERVAL 58 DAY)),
(1,21,DATE_SUB(NOW(), INTERVAL 44 DAY)),
(1,32,DATE_SUB(NOW(), INTERVAL 27 DAY)),
(1,35,DATE_SUB(NOW(), INTERVAL 6 DAY)),
(2,18,DATE_SUB(NOW(), INTERVAL 38 DAY)),
(2,29,DATE_SUB(NOW(), INTERVAL 22 DAY)),
(2,42,DATE_SUB(NOW(), INTERVAL 9 DAY));

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

-- 1. DELIVERED - 42 days ago - AirPods Pro 2 + Anker charger, WELCOME10 applied
(1,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 42 DAY), '%Y%m%d'), '1042'),1,'Rahul Sharma','rahul.sharma@example.com','9845012345',
 'Rahul Sharma','9845012345','No. 214, 5th Cross, 4th Block','Koramangala','Opposite Jyoti Nivas College','Bengaluru','Karnataka','560034','India',
 'Rahul Sharma','9845012345','No. 214, 5th Cross, 4th Block, Koramangala','Bengaluru','Karnataka','560034','India',
 24899.00,1500.00,1,'WELCOME10',0.00,0.00,23399.00,
 'standard','cod','paid','delivered',
 'Please call before delivery, the gate closes at 9 PM.','Delivered on the first attempt. Customer verified OTP.',NULL,'SIKBLR4471290388','Bluedart Express',DATE_SUB(NOW(), INTERVAL 36 DAY),
 '103.21.58.14','Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36',DATE_SUB(NOW(), INTERVAL 42 DAY),DATE_SUB(NOW(), INTERVAL 40 DAY),DATE_SUB(NOW(), INTERVAL 37 DAY),NULL,DATE_SUB(NOW(), INTERVAL 42 DAY)),

-- 2. DELIVERED - 30 days ago - 55-inch TV + soundbar, SAVE500 applied
(2,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 30 DAY), '%Y%m%d'), '1067'),2,'Priya Nair','priya.nair@example.com','9820045678',
 'Priya Nair','9820045678','Flat 703, Sea Breeze Apartments, Perry Cross Road','Bandra West','Near Mount Mary Church','Mumbai','Maharashtra','400050','India',
 'Priya Nair','9820045678','Flat 703, Sea Breeze Apartments, Perry Cross Road, Bandra West','Mumbai','Maharashtra','400050','India',
 74980.00,500.00,2,'SAVE500',0.00,0.00,74480.00,
 'standard','cod','paid','delivered',
 'Wall installation needed for the TV. Weekend slot preferred.','Installation completed by the brand technician on the same day as delivery.',NULL,'SIKMUM8830142907','Delhivery',DATE_SUB(NOW(), INTERVAL 24 DAY),
 '49.36.180.77','Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Safari/17.5',DATE_SUB(NOW(), INTERVAL 30 DAY),DATE_SUB(NOW(), INTERVAL 28 DAY),DATE_SUB(NOW(), INTERVAL 25 DAY),NULL,DATE_SUB(NOW(), INTERVAL 30 DAY)),

-- 3. SHIPPED - 8 days ago - two 1TB NVMe SSDs
(3,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 8 DAY), '%Y%m%d'), '1088'),1,'Rahul Sharma','rahul.sharma@example.com','9845012345',
 'Rahul Sharma','9845012345','Cessna Business Park, Tower 2, 6th Floor','Kadubeesanahalli, Outer Ring Road','Next to Ecospace','Bengaluru','Karnataka','560103','India',
 'Rahul Sharma','9845012345','Cessna Business Park, Tower 2, 6th Floor, Kadubeesanahalli','Bengaluru','Karnataka','560103','India',
 19998.00,0.00,NULL,NULL,0.00,0.00,19998.00,
 'standard','cod','pending','shipped',
 'Office address - please deliver between 10 AM and 6 PM on a weekday.','Dispatched from the Bengaluru hub.',NULL,'SIKBLR9021774635','Ekart Logistics',DATE_ADD(NOW(), INTERVAL 2 DAY),
 '103.21.58.14','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0 Safari/537.36',DATE_SUB(NOW(), INTERVAL 8 DAY),DATE_SUB(NOW(), INTERVAL 3 DAY),NULL,NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),

-- 4. PROCESSING - 4 days ago - OnePlus 13 + Anker 737 power bank
(4,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 4 DAY), '%Y%m%d'), '1103'),3,'Arjun Mehta','arjun.mehta@example.com','9900112233',
 'Arjun Mehta','9900112233','B-1204, Rohan Abhilasha, Nagar Road','Viman Nagar','Behind Phoenix Marketcity','Pune','Maharashtra','411014','India',
 'Arjun Mehta','9900112233','B-1204, Rohan Abhilasha, Nagar Road, Viman Nagar','Pune','Maharashtra','411014','India',
 72998.00,0.00,NULL,NULL,0.00,0.00,72998.00,
 'standard','cod','pending','processing',
 NULL,'High value COD - phone verification completed, moved to packing.',NULL,NULL,NULL,DATE_ADD(NOW(), INTERVAL 3 DAY),
 '182.71.29.203','Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',DATE_SUB(NOW(), INTERVAL 4 DAY),NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 4 DAY)),

-- 5. PENDING - 1 day ago - boAt earbuds on express delivery (below the free threshold)
(5,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), '%Y%m%d'), '1119'),2,'Priya Nair','priya.nair@example.com','9820045678',
 'Priya Nair','9820045678','Flat 703, Sea Breeze Apartments, Perry Cross Road','Bandra West','Near Mount Mary Church','Mumbai','Maharashtra','400050','India',
 'Priya Nair','9820045678','Flat 703, Sea Breeze Apartments, Perry Cross Road, Bandra West','Mumbai','Maharashtra','400050','India',
 1299.00,0.00,NULL,NULL,199.00,0.00,1498.00,
 'express','cod','pending','pending',
 'Needed before the weekend, hence express.',NULL,NULL,NULL,NULL,DATE_ADD(NOW(), INTERVAL 2 DAY),
 '49.36.180.77','Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Safari/17.5',NULL,NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 1 DAY)),

-- 6. CANCELLED - 18 days ago - charger + smart watch, cancelled by the customer
(6,CONCAT('SIK', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 18 DAY), '%Y%m%d'), '1075'),3,'Arjun Mehta','arjun.mehta@example.com','9900112233',
 'Arjun Mehta','9900112233','B-1204, Rohan Abhilasha, Nagar Road','Viman Nagar','Behind Phoenix Marketcity','Pune','Maharashtra','411014','India',
 'Arjun Mehta','9900112233','B-1204, Rohan Abhilasha, Nagar Road, Viman Nagar','Pune','Maharashtra','411014','India',
 4798.00,0.00,NULL,NULL,199.00,0.00,4997.00,
 'express','cod','failed','cancelled',
 NULL,'Cancelled by the customer within the 24 hour window. No stock impact.','Ordered the wrong watch size, will reorder the 46mm variant',NULL,NULL,NULL,
 '182.71.29.203','Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',DATE_SUB(NOW(), INTERVAL 18 DAY),NULL,NULL,DATE_SUB(NOW(), INTERVAL 17 DAY),DATE_SUB(NOW(), INTERVAL 18 DAY));

-- ---------------------------------------------------------------------------
--  ORDER ITEMS
--  SUM(subtotal) per order matches orders.subtotal exactly.
--  price = the live catalogue sale price, mrp = the catalogue list price.
-- ---------------------------------------------------------------------------
INSERT INTO `order_items`
(`id`,`order_id`,`product_id`,`variant_id`,`vendor_id`,`commission`,`product_name`,`product_sku`,`product_image`,`variant_name`,`mrp`,`price`,`quantity`,`tax_rate`,`tax_amount`,`subtotal`,`total`,`created_at`) VALUES
-- Order 1 : 21900 + 2999 = 24899
(1,1,24,NULL,NULL,0.00,'Apple AirPods Pro 2 (USB-C)','SIK-AUD-1001','assets/images/placeholders/device-earbuds.svg',NULL,24900.00,21900.00,1,18.00,0.00,21900.00,21900.00,DATE_SUB(NOW(), INTERVAL 42 DAY)),
(2,1,44,NULL,NULL,0.00,'Anker 65W GaN Fast Charger','SIK-PWR-9002','assets/images/placeholders/device-charger.svg',NULL,4999.00,2999.00,1,18.00,0.00,2999.00,2999.00,DATE_SUB(NOW(), INTERVAL 42 DAY)),
-- Order 2 : 44990 + 29990 = 74980
(3,2,20,NULL,NULL,0.00,'Samsung 55-inch Crystal 4K Smart TV','SIK-TV-2001','assets/images/placeholders/device-tv.svg',NULL,64990.00,44990.00,1,18.00,0.00,44990.00,44990.00,DATE_SUB(NOW(), INTERVAL 30 DAY)),
(4,2,28,NULL,NULL,0.00,'Sony HT-S2000 Soundbar','SIK-AUD-4002','assets/images/placeholders/device-soundbar.svg',NULL,39990.00,29990.00,1,18.00,0.00,29990.00,29990.00,DATE_SUB(NOW(), INTERVAL 30 DAY)),
-- Order 3 : 9999 x 2 = 19998
(5,3,41,NULL,NULL,0.00,'Samsung 990 PRO 1TB NVMe SSD','SIK-STO-2001','assets/images/placeholders/device-ssd.svg',NULL,14999.00,9999.00,2,18.00,0.00,19998.00,19998.00,DATE_SUB(NOW(), INTERVAL 8 DAY)),
-- Order 4 : 61999 + 10999 = 72998
(6,4,5,NULL,NULL,0.00,'OnePlus 13','SIK-PHN-3001','assets/images/placeholders/device-smartphone.svg',NULL,69999.00,61999.00,1,18.00,0.00,61999.00,61999.00,DATE_SUB(NOW(), INTERVAL 4 DAY)),
(7,4,43,NULL,NULL,0.00,'Anker 737 Power Bank 24,000mAh','SIK-PWR-9001','assets/images/placeholders/device-powerbank.svg',NULL,14999.00,10999.00,1,18.00,0.00,10999.00,10999.00,DATE_SUB(NOW(), INTERVAL 4 DAY)),
-- Order 5 : 1299
(8,5,26,NULL,NULL,0.00,'boAt Airdopes 191G TWS Earbuds','SIK-AUD-6001','assets/images/placeholders/device-earbuds.svg',NULL,4490.00,1299.00,1,18.00,0.00,1299.00,1299.00,DATE_SUB(NOW(), INTERVAL 1 DAY)),
-- Order 6 : 2999 + 1799 = 4798
(9,6,44,NULL,NULL,0.00,'Anker 65W GaN Fast Charger','SIK-PWR-9002','assets/images/placeholders/device-charger.svg',NULL,4999.00,2999.00,1,18.00,0.00,2999.00,2999.00,DATE_SUB(NOW(), INTERVAL 18 DAY)),
(10,6,31,NULL,NULL,0.00,'boAt Wave Sigma 3 Smart Watch','SIK-WER-6001','assets/images/placeholders/device-watch.svg',NULL,4499.00,1799.00,1,18.00,0.00,1799.00,1799.00,DATE_SUB(NOW(), INTERVAL 18 DAY));

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
(2,'processing','Large item consolidated for the specialist partner.','admin',1,DATE_SUB(NOW(), INTERVAL 29 DAY)),
(2,'packed','Double corrugated packing with edge guards applied.','admin',1,DATE_SUB(NOW(), INTERVAL 29 DAY)),
(2,'shipped','Handed to Delhivery. AWB SIKMUM8830142907.','admin',1,DATE_SUB(NOW(), INTERVAL 28 DAY)),
(2,'out_for_delivery','Out for delivery with the installation technician.','system',NULL,DATE_SUB(NOW(), INTERVAL 25 DAY)),
(2,'delivered','Open box delivery accepted. Wall installation completed.','system',NULL,DATE_SUB(NOW(), INTERVAL 25 DAY)),
-- Order 3 : currently in transit
(3,'pending','Order placed on the website. Awaiting confirmation.','customer',NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),
(3,'confirmed','COD order auto-confirmed by the system.','system',NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),
(3,'processing','Items picked and serial numbers recorded.','admin',1,DATE_SUB(NOW(), INTERVAL 6 DAY)),
(3,'packed','Packed for the office address. Weekday delivery flagged.','admin',1,DATE_SUB(NOW(), INTERVAL 4 DAY)),
(3,'shipped','Handed to Ekart Logistics. AWB SIKBLR9021774635.','admin',1,DATE_SUB(NOW(), INTERVAL 3 DAY)),
-- Order 4 : in the warehouse
(4,'pending','Order placed on the website. Awaiting confirmation.','customer',NULL,DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4,'confirmed','High value COD verified over the phone.','admin',1,DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4,'processing','Allocated to the Pune fulfilment centre for picking.','admin',1,DATE_SUB(NOW(), INTERVAL 2 DAY)),
-- Order 5 : brand new
(5,'pending','Order placed on the website. Awaiting confirmation.','customer',NULL,DATE_SUB(NOW(), INTERVAL 1 DAY)),
-- Order 6 : cancelled by the customer
(6,'pending','Order placed on the website. Awaiting confirmation.','customer',NULL,DATE_SUB(NOW(), INTERVAL 18 DAY)),
(6,'confirmed','COD order auto-confirmed by the system.','system',NULL,DATE_SUB(NOW(), INTERVAL 18 DAY)),
(6,'cancelled','Cancelled by the customer inside the 24 hour window. Wrong watch size ordered.','customer',NULL,DATE_SUB(NOW(), INTERVAL 17 DAY));

-- ---------------------------------------------------------------------------
--  PAYMENTS (all Cash on Delivery, status mirrors orders.payment_status)
-- ---------------------------------------------------------------------------
INSERT INTO `payments` (`id`,`order_id`,`gateway`,`amount`,`currency`,`status`,`reference`,`paid_at`,`created_at`) VALUES
(1,1,'cod',23399.00,'INR','paid','COD-BLR-4471290388',DATE_SUB(NOW(), INTERVAL 37 DAY),DATE_SUB(NOW(), INTERVAL 42 DAY)),
(2,2,'cod',74480.00,'INR','paid','COD-MUM-8830142907',DATE_SUB(NOW(), INTERVAL 25 DAY),DATE_SUB(NOW(), INTERVAL 30 DAY)),
(3,3,'cod',19998.00,'INR','pending',NULL,NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),
(4,4,'cod',72998.00,'INR','pending',NULL,NULL,DATE_SUB(NOW(), INTERVAL 4 DAY)),
(5,5,'cod',1498.00,'INR','pending',NULL,NULL,DATE_SUB(NOW(), INTERVAL 1 DAY)),
(6,6,'cod',4997.00,'INR','failed','COD-CANCELLED-PUN-1075',NULL,DATE_SUB(NOW(), INTERVAL 18 DAY));

-- ---------------------------------------------------------------------------
--  COUPON USAGE (keeps coupons.used_count honest for the two orders above)
-- ---------------------------------------------------------------------------
INSERT INTO `coupon_usage` (`coupon_id`,`user_id`,`order_id`,`email`,`discount`,`created_at`) VALUES
(1,1,1,'rahul.sharma@example.com',1500.00,DATE_SUB(NOW(), INTERVAL 42 DAY)),
(2,2,2,'priya.nair@example.com',500.00,DATE_SUB(NOW(), INTERVAL 30 DAY));

-- ===========================================================================
--  SECTION 12 : REVIEWS (24 approved + 3 waiting in the moderation queue)
--  verified_purchase = 1 only where a real order for that product exists.
-- ===========================================================================
INSERT INTO `reviews`
(`id`,`product_id`,`user_id`,`order_id`,`customer_name`,`rating`,`title`,`comment`,`verified_purchase`,`helpful_count`,`status`,`admin_reply`,`created_at`) VALUES

(1,1,NULL,NULL,'Vikram Iyer',5,'The camera control button is genuinely useful','Upgraded from a three year old phone and the difference in low light photos is not subtle. Battery comfortably lasts a full working day with about two hours of navigation. The titanium frame stays cooler than my old phone during long calls.',0,42,'approved',NULL,DATE_SUB(NOW(), INTERVAL 26 DAY)),
(2,1,NULL,NULL,'Ananya Desai',5,'Worth the price if you shoot a lot of video','4K120 in Dolby Vision is overkill for most people, but for anyone shooting content it is a real tool. Build quality is excellent and the delivery arrived a day earlier than promised.',0,18,'approved',NULL,DATE_SUB(NOW(), INTERVAL 19 DAY)),
(3,1,NULL,NULL,'Karthik Menon',4,'Excellent phone, expensive accessories','No complaints about the phone itself. Half a star off because everything you add to it costs a lot. The 5x zoom is sharp enough that I stopped carrying a compact camera.',0,9,'approved',NULL,DATE_SUB(NOW(), INTERVAL 12 DAY)),
(4,2,NULL,NULL,'Rohit Bansal',5,'Right size, right price for this generation','Compact enough to use one handed, which is rare now. The A18 handles everything I throw at it and the battery lasts noticeably longer than the model it replaced. Genuine India stock, warranty registered without any issue.',0,27,'approved',NULL,DATE_SUB(NOW(), INTERVAL 22 DAY)),
(5,3,NULL,NULL,'Suresh Pillai',5,'The S Pen is the reason I keep buying this line','Nothing else at this size gives you a pen in the body. Note taking in meetings has replaced my paper diary completely. The 200MP camera is excellent in daylight and the 5x telephoto is sharper than I expected.',0,35,'approved',NULL,DATE_SUB(NOW(), INTERVAL 31 DAY)),
(6,3,NULL,NULL,'Deepa Chandran',5,'Battery easily lasts a day and a half','Heavy user here, two email accounts, lots of video calls, and it still ends the day around 30 percent. Galaxy AI call translation actually worked when I tested it with a Tamil speaker.',0,21,'approved',NULL,DATE_SUB(NOW(), INTERVAL 17 DAY)),
(7,5,NULL,NULL,'Nikhil Joshi',5,'Charges fully in the time it takes to shower','The 100W charger is not a gimmick. Zero to full in about 35 minutes measured on my own phone. Display is bright enough to read outdoors in Pune summer sun and the software is clean without bloatware.',0,29,'approved',NULL,DATE_SUB(NOW(), INTERVAL 20 DAY)),
(8,6,NULL,NULL,'Pooja Reddy',4,'Metal body at this price is unusual','Feels far more expensive than it is. Battery genuinely lasts a day and a half for me. Camera is good in daylight and average at night, which is fair at this price. Software updates have been on time so far.',0,16,'approved',NULL,DATE_SUB(NOW(), INTERVAL 15 DAY)),
(9,9,NULL,NULL,'Manish Gupta',4,'Great value, the curved screen takes adjusting to','The 200MP camera with stabilisation is a big step up from the previous Note I owned. Charging is ridiculously fast. The curved display causes occasional accidental touches, which is my only real complaint.',0,54,'approved',NULL,DATE_SUB(NOW(), INTERVAL 28 DAY)),
(10,9,NULL,NULL,'Swati Verma',5,'Best phone I have owned under ₹25,000','Bought it for my daughter for college and she loves it. Screen is lovely, battery lasts her a full day of classes and video, and it charges over lunch. Delivery was on the third day to Lucknow.',0,31,'approved',NULL,DATE_SUB(NOW(), INTERVAL 11 DAY)),
(11,10,NULL,NULL,'Aditya Kulkarni',5,'Silent, cool and lasts all day','No fan means no noise, which I did not think I would care about until I had it. I run Lightroom, a browser with 30 tabs and Slack, and it does not get warm. Real world battery is close to 15 hours of actual work.',0,63,'approved',NULL,DATE_SUB(NOW(), INTERVAL 24 DAY)),
(12,10,NULL,NULL,'Meera Krishnan',5,'Perfect student and work from home machine','Light enough to carry every day, and the display is sharp and colour accurate. It handles my statistics coursework and video calls without breaking a sweat. Packaging from ShopInnKart was excellent, sealed and untouched.',0,38,'approved',NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),
(13,20,2,2,'Priya Nair',4,'Good picture, installation was smooth','Colours are natural out of the box after switching off the demo mode. Upscaling of older HD content is decent. Free wall installation happened on the same day as delivery, which I did not expect. Half a star off because the built in speakers are only average.',1,47,'approved','Thank you for the detailed review, Priya. A soundbar pairs very well with this panel if you ever want to upgrade the audio.',DATE_SUB(NOW(), INTERVAL 21 DAY)),
(14,20,NULL,NULL,'Harish Kumar',5,'Excellent 55 inch for a living room','Watched a full cricket season on it and motion handling is clean. Tizen has every app I need including the Indian streaming services. At this price after the deal discount it is very hard to beat.',0,26,'approved',NULL,DATE_SUB(NOW(), INTERVAL 13 DAY)),
(15,23,NULL,NULL,'Ritu Agarwal',5,'The best purchase I made this year','I fly twice a month and these have changed how tiring a flight feels. Engine noise simply disappears. Comfortable for six hours straight with glasses, which was my main worry before buying.',0,71,'approved',NULL,DATE_SUB(NOW(), INTERVAL 27 DAY)),
(16,23,NULL,NULL,'Faisal Khan',5,'Call quality is superb, ANC is class leading','Work from home saviour. Colleagues say I sound clearer than I did on my laptop microphone. Battery easily gives a full week of eight hour days before I need to charge it. Speak-to-Chat is a genuinely clever feature.',0,44,'approved',NULL,DATE_SUB(NOW(), INTERVAL 10 DAY)),
(17,24,1,1,'Rahul Sharma',5,'Adaptive Audio is the feature that sold me','Switching between the office and the road without touching a setting is brilliant. Fit is secure enough for a jog. Case charging over USB-C finally means one cable for everything in my bag. Arrived sealed with the India warranty registered.',1,58,'approved',NULL,DATE_SUB(NOW(), INTERVAL 33 DAY)),
(18,24,NULL,NULL,'Sneha Pawar',4,'Great sound, be careful with the tips','Sound and cancellation are excellent, but I had to switch to the small tips to get a proper seal. Once I did, the bass improved dramatically. Worth trying all three sizes before you judge them.',0,22,'approved',NULL,DATE_SUB(NOW(), INTERVAL 16 DAY)),
(19,26,NULL,NULL,'Amit Tiwari',4,'Unbeatable at this price for gaming','Bought these mainly for mobile gaming and the low latency mode really does help. Battery life across the case is genuinely close to what is claimed. Call quality in traffic is average, which is expected at this price.',0,89,'approved',NULL,DATE_SUB(NOW(), INTERVAL 14 DAY)),
(20,28,2,2,'Priya Nair',5,'No separate subwoofer and still plenty of bass','Paired it with the TV from the same order and the difference is night and day. Dialogue is clear, which was my main problem with TV speakers. Setup took five minutes with one HDMI cable.',1,33,'approved',NULL,DATE_SUB(NOW(), INTERVAL 20 DAY)),
(21,29,NULL,NULL,'Kavita Sharma',5,'Noticeably lighter than my previous watch','The thinner case makes a real difference over a full day and while sleeping. Sleep tracking has been accurate against my own notes. Charges from flat to full while I get ready in the morning.',0,25,'approved','Glad it is working well for you, Kavita. Do enable the sleep apnoea notification in the Health app if you have not already.',DATE_SUB(NOW(), INTERVAL 18 DAY)),
(22,32,NULL,NULL,'Yash Malhotra',5,'The adaptive triggers still impress people','Two years in on the platform and the DualSense is still the best controller I have used. The slim version runs quieter than the launch model. Delivery was well packed with an outer carton over the brand box.',0,49,'approved',NULL,DATE_SUB(NOW(), INTERVAL 7 DAY)),
(23,38,NULL,NULL,'Gaurav Sethi',5,'The scroll wheel spoils you for other mice','MagSpeed scrolling through long spreadsheets is genuinely a productivity feature, not marketing. Quiet clicks mean nobody in a meeting hears me working. Switching between my laptop and desktop with one button is seamless.',0,37,'approved',NULL,DATE_SUB(NOW(), INTERVAL 23 DAY)),
(24,44,1,1,'Rahul Sharma',5,'One charger replaced three in my bag','Charges my laptop at full speed and still has two ports free for the phone and earbuds. Smaller than the brick that came with my laptop. Has not got uncomfortably warm even during a long charge.',1,30,'approved',NULL,DATE_SUB(NOW(), INTERVAL 34 DAY)),

-- --- moderation queue -------------------------------------------------------
(25,41,NULL,NULL,'Tarun Bhatia',4,'Fast, but runs warm without a heatsink','Copy speeds are exactly as advertised in short bursts. In a laptop without any heatsink it does throttle during very long transfers, which is true of every drive in this class. Still an easy recommendation.',0,0,'pending',NULL,DATE_SUB(NOW(), INTERVAL 3 DAY)),
(26,16,NULL,NULL,'Zoya Fernandes',5,'Handles everything I play at high settings','The 165Hz panel is lovely and the cooling holds up through long sessions. It is heavy, so this is a desk machine rather than something to carry daily. Keyboard is comfortable for typing as well as gaming.',0,0,'pending',NULL,DATE_SUB(NOW(), INTERVAL 2 DAY)),
(27,21,NULL,NULL,'Rajesh Nambiar',3,'Beautiful panel, remote is fiddly','Picture quality is everything people say about OLED and gaming at 144Hz is superb. My complaint is the remote and the amount of promoted content on the home screen. Wall mount is sold separately, which was not clear to me.',0,0,'pending',NULL,DATE_SUB(NOW(), INTERVAL 1 DAY));

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
(1,'Sandeep Rao','sandeep.rao@example.com','9741025896','Bulk order for 15 laptops','We are a 40 person startup in HSR Layout and need 15 thin and light laptops with 16GB memory for a new team joining next month. Could you share a quotation with GST, expected lead time and whether onsite warranty is available in Bengaluru? We can pay by NEFT against a proforma invoice.','new',NULL,'106.51.12.90',DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2,'Nandini Shetty','nandini.shetty@example.com','9886734512','Is the 55-inch TV wall mount included?','I am about to order the 55 inch Crystal 4K TV and the page says free installation. Does that include the wall mount bracket itself or only the labour? Also, can the installation be scheduled for a Sunday morning in Mangaluru?','read',NULL,'117.202.44.6',DATE_SUB(NOW(), INTERVAL 4 DAY)),
(3,'Imran Sheikh','imran.sheikh@example.com','9004561230','Invoice needed with company GSTIN','I placed an order last week using my personal account but I need the invoice raised against my company GSTIN for input credit. The order has already been delivered. Is it possible to have the invoice reissued, and what details do you need from me?','replied','We can reissue the invoice against a company GSTIN only if the order was placed with the GSTIN entered at checkout. For this order we have shared a signed delivery certificate that your accounts team can use for the expense claim. For future orders, please add the GSTIN in the billing step and the invoice will carry it automatically.','103.87.56.19',DATE_SUB(NOW(), INTERVAL 9 DAY)),
(4,'Lakshmi Venkatesh','lakshmi.venkatesh@example.com','9448120765','Delivery to Port Blair','Your site says my pin code 744101 is not serviceable. Do you have any plan to deliver to the Andaman and Nicobar Islands, or is there a partner courier I can arrange myself if I pay the freight?','closed','Thank you for writing in. We do not deliver to 744101 at present because our courier partners do not offer insured electronics transport to the islands. We are unable to release goods to a customer arranged courier as that would void the warranty and insurance cover. We have added your pin code to our expansion request list and will email you if that changes.','203.192.240.15',DATE_SUB(NOW(), INTERVAL 16 DAY));

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

