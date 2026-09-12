SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS approval_requests;
DROP TABLE IF EXISTS inventory_movements;
DROP TABLE IF EXISTS refunds;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS order_item_modifiers;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS cash_movements;
DROP TABLE IF EXISTS cashier_shifts;
DROP TABLE IF EXISTS purchase_order_items;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS reservations;
DROP TABLE IF EXISTS recipes;
DROP TABLE IF EXISTS menu_item_modifier_groups;
DROP TABLE IF EXISTS modifier_options;
DROP TABLE IF EXISTS modifier_groups;
DROP TABLE IF EXISTS ingredients;
DROP TABLE IF EXISTS menu_items;
DROP TABLE IF EXISTS menu_categories;
DROP TABLE IF EXISTS promotions;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS restaurant_tables;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS settings;

CREATE TABLE roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  slug VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  phone VARCHAR(30) NULL,
  pin_hash VARCHAR(255) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY(role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE restaurant_tables (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_number VARCHAR(20) NOT NULL UNIQUE,
  qr_token CHAR(32) NOT NULL UNIQUE,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 2,
  zone VARCHAR(80) NOT NULL DEFAULT 'Indoor',
  status ENUM('available','reserved','occupied','cleaning','unavailable') NOT NULL DEFAULT 'available',
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(160) NULL,
  birthday DATE NULL,
  loyalty_points INT NOT NULL DEFAULT 0,
  total_spend DECIMAL(16,2) NOT NULL DEFAULT 0,
  visits INT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  status ENUM('active','blacklisted','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customer_phone(phone), INDEX idx_customer_name(name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promotions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  discount_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  min_spend DECIMAL(14,2) NOT NULL DEFAULT 0,
  max_discount DECIMAL(14,2) NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_no VARCHAR(30) NOT NULL UNIQUE,
  customer_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(160) NULL,
  reservation_date DATE NOT NULL,
  reservation_time TIME NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  guests SMALLINT UNSIGNED NOT NULL,
  table_id BIGINT UNSIGNED NULL,
  deposit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('pending','confirmed','checked_in','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_reservation_schedule(reservation_date,reservation_time,status),
  CONSTRAINT fk_reservation_customer FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_reservation_table FOREIGN KEY(table_id) REFERENCES restaurant_tables(id) ON DELETE SET NULL,
  CONSTRAINT fk_reservation_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  sku VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(140) NOT NULL,
  description VARCHAR(500) NULL,
  price DECIMAL(14,2) NOT NULL,
  cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_inclusive TINYINT(1) NOT NULL DEFAULT 0,
  preparation_station ENUM('kitchen','bar','dessert','other') NOT NULL DEFAULT 'kitchen',
  image_url VARCHAR(255) NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_menu_category FOREIGN KEY(category_id) REFERENCES menu_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE modifier_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  min_select SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_select SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE modifier_options (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  price_delta DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_modifier_group FOREIGN KEY(group_id) REFERENCES modifier_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_item_modifier_groups (
  menu_item_id BIGINT UNSIGNED NOT NULL,
  modifier_group_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(menu_item_id,modifier_group_id),
  CONSTRAINT fk_mimg_menu FOREIGN KEY(menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_mimg_group FOREIGN KEY(modifier_group_id) REFERENCES modifier_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ingredients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(140) NOT NULL,
  unit VARCHAR(30) NOT NULL,
  current_stock DECIMAL(14,3) NOT NULL DEFAULT 0,
  min_stock DECIMAL(14,3) NOT NULL DEFAULT 0,
  cost_per_unit DECIMAL(14,4) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_item_id BIGINT UNSIGNED NOT NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  UNIQUE KEY uq_recipe(menu_item_id,ingredient_id),
  CONSTRAINT fk_recipe_menu FOREIGN KEY(menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_recipe_ingredient FOREIGN KEY(ingredient_id) REFERENCES ingredients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(160) NULL,
  address VARCHAR(500) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_no VARCHAR(30) NOT NULL UNIQUE,
  supplier_id BIGINT UNSIGNED NOT NULL,
  status ENUM('draft','ordered','partial','received','cancelled') NOT NULL DEFAULT 'ordered',
  order_date DATE NOT NULL,
  expected_date DATE NULL,
  subtotal DECIMAL(16,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  received_by BIGINT UNSIGNED NULL,
  received_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_po_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_po_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_po_receiver FOREIGN KEY(received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  received_quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  subtotal DECIMAL(16,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_poi_po FOREIGN KEY(purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_ingredient FOREIGN KEY(ingredient_id) REFERENCES ingredients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cashier_shifts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  closed_at DATETIME NULL,
  closing_cash_actual DECIMAL(14,2) NULL,
  expected_cash DECIMAL(14,2) NULL,
  difference_amount DECIMAL(14,2) NULL,
  notes VARCHAR(500) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  CONSTRAINT fk_shift_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shift_id BIGINT UNSIGNED NOT NULL,
  type ENUM('in','out') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  category VARCHAR(80) NULL,
  notes VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cash_shift FOREIGN KEY(shift_id) REFERENCES cashier_shifts(id) ON DELETE CASCADE,
  CONSTRAINT fk_cash_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) NOT NULL UNIQUE,
  table_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(120) NULL,
  customer_phone VARCHAR(30) NULL,
  delivery_address VARCHAR(500) NULL,
  guest_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  order_type ENUM('dine_in','takeaway','delivery') NOT NULL DEFAULT 'dine_in',
  status ENUM('open','completed','cancelled','voided') NOT NULL DEFAULT 'open',
  kitchen_status ENUM('new','preparing','ready','served','completed','cancelled') NOT NULL DEFAULT 'new',
  payment_status ENUM('unpaid','partial','paid','refunded','partially_refunded') NOT NULL DEFAULT 'unpaid',
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  promotion_id BIGINT UNSIGNED NULL,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  service_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  source ENUM('cashier','waiter','customer','admin') NOT NULL DEFAULT 'cashier',
  waiter_id BIGINT UNSIGNED NULL,
  cashier_id BIGINT UNSIGNED NULL,
  shift_id BIGINT UNSIGNED NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_order_status(payment_status,kitchen_status,created_at),
  CONSTRAINT fk_order_table FOREIGN KEY(table_id) REFERENCES restaurant_tables(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_customer FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_promo FOREIGN KEY(promotion_id) REFERENCES promotions(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_waiter FOREIGN KEY(waiter_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_cashier FOREIGN KEY(cashier_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_shift FOREIGN KEY(shift_id) REFERENCES cashier_shifts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  menu_item_id BIGINT UNSIGNED NULL,
  item_name VARCHAR(140) NOT NULL,
  preparation_station ENUM('kitchen','bar','dessert','other') NOT NULL DEFAULT 'kitchen',
  unit_price DECIMAL(14,2) NOT NULL,
  modifier_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  quantity DECIMAL(10,2) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL,
  notes VARCHAR(300) NULL,
  status ENUM('new','preparing','ready','served','cancelled','voided') NOT NULL DEFAULT 'new',
  void_reason VARCHAR(255) NULL,
  CONSTRAINT fk_order_item_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_item_menu FOREIGN KEY(menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_item_modifiers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_item_id BIGINT UNSIGNED NOT NULL,
  modifier_option_id BIGINT UNSIGNED NULL,
  group_name VARCHAR(120) NOT NULL,
  option_name VARCHAR(120) NOT NULL,
  price_delta DECIMAL(14,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_oim_item FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_oim_option FOREIGN KEY(modifier_option_id) REFERENCES modifier_options(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_no VARCHAR(30) NOT NULL UNIQUE,
  order_id BIGINT UNSIGNED NOT NULL,
  shift_id BIGINT UNSIGNED NULL,
  method ENUM('cash','qris','transfer','card','other') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reference_no VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  received_by BIGINT UNSIGNED NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_shift FOREIGN KEY(shift_id) REFERENCES cashier_shifts(id) ON DELETE SET NULL,
  CONSTRAINT fk_payment_user FOREIGN KEY(received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  refund_no VARCHAR(30) NOT NULL UNIQUE,
  order_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('cash','qris','transfer','card','other') NOT NULL,
  reason VARCHAR(500) NOT NULL,
  processed_by BIGINT UNSIGNED NULL,
  processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_refund_order FOREIGN KEY(order_id) REFERENCES orders(id),
  CONSTRAINT fk_refund_payment FOREIGN KEY(payment_id) REFERENCES payments(id) ON DELETE SET NULL,
  CONSTRAINT fk_refund_user FOREIGN KEY(processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  movement_type ENUM('in','out','adjustment','waste','purchase','sale_restore') NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  balance_after DECIMAL(14,3) NOT NULL,
  unit_cost DECIMAL(14,4) NULL,
  reference_type VARCHAR(50) NULL,
  reference_id BIGINT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inventory_created(created_at),
  CONSTRAINT fk_inv_ingredient FOREIGN KEY(ingredient_id) REFERENCES ingredients(id),
  CONSTRAINT fk_inv_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_no VARCHAR(30) NOT NULL UNIQUE,
  request_type ENUM('void_order','void_item','refund','discount_override') NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL,
  payload_json JSON NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_by BIGINT UNSIGNED NULL,
  decision_notes VARCHAR(500) NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_approval_requester FOREIGN KEY(requested_by) REFERENCES users(id),
  CONSTRAINT fk_approval_decider FOREIGN KEY(decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  payload_json JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created(created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
