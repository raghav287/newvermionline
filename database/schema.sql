-- Vermi Compost store: database schema (MySQL 5.7+ / MariaDB 10.3+).
-- No foreign keys by design: tables are linked by plain ID columns, each indexed.
-- install.php runs this file. You can also import it in phpMyAdmin.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(140) NOT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_category_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per sellable item. kind 'loose' is the per-kg loose compost sold through the calculator.
-- stock_qty NULL means stock is not tracked. specs and gallery hold JSON arrays.
CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'standard',
  name VARCHAR(200) NOT NULL,
  slug VARCHAR(220) NOT NULL,
  sku VARCHAR(60) NOT NULL,
  size_label VARCHAR(60) NOT NULL DEFAULT '',
  price DECIMAL(12,2) NOT NULL,
  mrp DECIMAL(12,2) NULL,
  stock_qty INT NULL,
  description MEDIUMTEXT NULL,
  specs TEXT NULL,
  image VARCHAR(255) NULL,
  gallery TEXT NULL,
  match_aliases TEXT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'active',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_sku (sku),
  UNIQUE KEY uq_product_slug (slug),
  KEY ix_product_category (category_id),
  KEY ix_product_status (status, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per email address. password_hash stays NULL for guests and for customers created by an import.
CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NULL,
  address_line1 VARCHAR(255) NOT NULL DEFAULT '',
  address_line2 VARCHAR(255) NOT NULL DEFAULT '',
  city VARCHAR(80) NOT NULL DEFAULT '',
  state VARCHAR(80) NOT NULL DEFAULT '',
  pincode VARCHAR(12) NOT NULL DEFAULT '',
  source VARCHAR(20) NOT NULL DEFAULT 'website',
  import_batch_id INT UNSIGNED NULL,
  notes TEXT NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customer_email (email),
  KEY ix_customer_phone (phone),
  KEY ix_customer_batch (import_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- source 'website' = checkout order, 'payu_import' = historical order built from a PayU file.
-- Imported orders keep NULL in every amount the PayU file did not contain (subtotal, discount, shipping).
CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(40) NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'website',
  customer_id INT UNSIGNED NULL,
  customer_name VARCHAR(120) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  address_line1 VARCHAR(255) NOT NULL DEFAULT '',
  address_line2 VARCHAR(255) NOT NULL DEFAULT '',
  city VARCHAR(80) NOT NULL DEFAULT '',
  state VARCHAR(80) NOT NULL DEFAULT '',
  pincode VARCHAR(12) NOT NULL DEFAULT '',
  country VARCHAR(60) NOT NULL DEFAULT '',
  subtotal DECIMAL(12,2) NULL,
  discount DECIMAL(12,2) NULL,
  shipping_fee DECIMAL(12,2) NULL,
  total DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(20) NOT NULL DEFAULT 'payu',
  payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  order_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  offer_note VARCHAR(120) NOT NULL DEFAULT '',
  customer_note TEXT NULL,
  admin_note TEXT NULL,
  import_note TEXT NULL,
  external_ref VARCHAR(100) NULL,
  import_batch_id INT UNSIGNED NULL,
  stock_deducted TINYINT(1) NOT NULL DEFAULT 0,
  placed_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_number (order_number),
  KEY ix_order_customer (customer_id),
  KEY ix_order_source (source, placed_at),
  KEY ix_order_status (payment_status, order_status),
  KEY ix_order_email (email),
  KEY ix_order_batch (import_batch_id),
  KEY ix_order_ref (external_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- quantity and prices stay NULL on imported items when the PayU file did not state them.
CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  sku VARCHAR(60) NOT NULL DEFAULT '',
  product_name VARCHAR(255) NOT NULL,
  size_label VARCHAR(60) NOT NULL DEFAULT '',
  unit VARCHAR(10) NOT NULL DEFAULT 'pc',
  quantity INT NULL,
  unit_price DECIMAL(12,2) NULL,
  list_price DECIMAL(12,2) NULL,
  line_total DECIMAL(12,2) NULL,
  source_text VARCHAR(255) NULL,
  match_status VARCHAR(12) NOT NULL DEFAULT 'catalog',
  KEY ix_item_order (order_id),
  KEY ix_item_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every PayU payment attempt, from website checkout or from an imported PayU file.
-- txnid and mihpayid (the PayU ID) are unique and case-sensitive: this is what blocks duplicates.
CREATE TABLE IF NOT EXISTS payu_transactions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(10) NOT NULL,
  txnid VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  mihpayid VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  order_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  import_batch_id INT UNSIGNED NULL,
  status VARCHAR(40) NOT NULL DEFAULT '',
  status_detail VARCHAR(40) NOT NULL DEFAULT '',
  result VARCHAR(10) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  discount DECIMAL(12,2) NULL,
  additional_charges DECIMAL(12,2) NULL,
  mode VARCHAR(30) NOT NULL DEFAULT '',
  bank_name VARCHAR(100) NOT NULL DEFAULT '',
  bank_ref_no VARCHAR(100) NOT NULL DEFAULT '',
  upi_app VARCHAR(60) NOT NULL DEFAULT '',
  card_type VARCHAR(40) NOT NULL DEFAULT '',
  card_last4 VARCHAR(4) NOT NULL DEFAULT '',
  error_code VARCHAR(20) NOT NULL DEFAULT '',
  error_message VARCHAR(255) NOT NULL DEFAULT '',
  productinfo VARCHAR(255) NOT NULL DEFAULT '',
  firstname VARCHAR(100) NOT NULL DEFAULT '',
  lastname VARCHAR(100) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  address_line1 VARCHAR(255) NOT NULL DEFAULT '',
  address_line2 VARCHAR(255) NOT NULL DEFAULT '',
  city VARCHAR(80) NOT NULL DEFAULT '',
  state VARCHAR(80) NOT NULL DEFAULT '',
  country VARCHAR(60) NOT NULL DEFAULT '',
  zipcode VARCHAR(12) NOT NULL DEFAULT '',
  udf1 VARCHAR(255) NOT NULL DEFAULT '',
  udf2 VARCHAR(255) NOT NULL DEFAULT '',
  udf3 VARCHAR(255) NOT NULL DEFAULT '',
  udf4 VARCHAR(255) NOT NULL DEFAULT '',
  udf5 VARCHAR(255) NOT NULL DEFAULT '',
  added_on DATETIME NOT NULL,
  success_at DATETIME NULL,
  settlement_amount DECIMAL(12,2) NULL,
  settlement_date DATETIME NULL,
  utr VARCHAR(60) NOT NULL DEFAULT '',
  raw_data MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_txn_txnid (txnid),
  UNIQUE KEY uq_txn_mihpayid (mihpayid),
  KEY ix_txn_order (order_id),
  KEY ix_txn_customer (customer_id),
  KEY ix_txn_batch (import_batch_id),
  KEY ix_txn_email (email),
  KEY ix_txn_added (added_on),
  KEY ix_txn_result (result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refunds made in the PayU dashboard, recorded here by an admin (or from a refund column in an import).
CREATE TABLE IF NOT EXISTS payu_refunds (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  transaction_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  refund_ref VARCHAR(100) NOT NULL DEFAULT '',
  refunded_on DATE NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  source VARCHAR(10) NOT NULL DEFAULT 'admin',
  import_batch_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_refund_order (order_id),
  KEY ix_refund_txn (transaction_id),
  KEY ix_refund_batch (import_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_name VARCHAR(255) NOT NULL,
  stored_file VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'uploaded',
  header_row INT NOT NULL DEFAULT 1,
  headers MEDIUMTEXT NULL,
  mapping TEXT NULL,
  options TEXT NULL,
  total_rows INT NOT NULL DEFAULT 0,
  imported_rows INT NOT NULL DEFAULT 0,
  updated_rows INT NOT NULL DEFAULT 0,
  duplicate_rows INT NOT NULL DEFAULT 0,
  failed_rows INT NOT NULL DEFAULT 0,
  orders_created INT NOT NULL DEFAULT 0,
  customers_created INT NOT NULL DEFAULT 0,
  admin_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  KEY ix_batch_hash (file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The outcome of every row of every import. raw_data keeps failed and duplicate rows for the error report.
CREATE TABLE IF NOT EXISTS import_rows (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  batch_id INT UNSIGNED NOT NULL,
  line_no INT NOT NULL,
  txnid VARCHAR(100) NOT NULL DEFAULT '',
  outcome VARCHAR(12) NOT NULL,
  message TEXT NULL,
  order_id INT UNSIGNED NULL,
  transaction_id INT UNSIGNED NULL,
  raw_data MEDIUMTEXT NULL,
  KEY ix_row_batch (batch_id, outcome),
  KEY ix_row_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  subject VARCHAR(160) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_message_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  slug VARCHAR(80) NOT NULL PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  intro VARCHAR(255) NOT NULL DEFAULT '',
  body MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(10) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(190) NOT NULL DEFAULT '',
  attempted_at DATETIME NOT NULL,
  KEY ix_attempt_ip (scope, ip, attempted_at),
  KEY ix_attempt_email (scope, email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
