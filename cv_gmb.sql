-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 25, 2025 at 07:29 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cv_gmb`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(200) NOT NULL,
  `type` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `contact` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL,
  `date` date DEFAULT curdate(),
  `description` text DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_items`
--

CREATE TABLE `journal_items` (
  `id` int(11) NOT NULL,
  `journal_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `debit` decimal(14,2) DEFAULT 0.00,
  `credit` decimal(14,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `related_type` varchar(20) DEFAULT NULL,
  `related_id` int(11) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `date` date DEFAULT curdate(),
  `method` varchar(50) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `penjualan`
--

CREATE TABLE `penjualan` (
  `id` int(11) NOT NULL,
  `tgl_penjualan` date DEFAULT NULL,
  `customer` varchar(200) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `qty` decimal(14,3) DEFAULT 0.000,
  `harga_satuan` decimal(14,2) DEFAULT 0.00,
  `total_harga` decimal(14,2) DEFAULT 0.00,
  `payment_status` varchar(20) DEFAULT 'unpaid',
  `payment_method` varchar(50) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `penjualan`
--

INSERT INTO `penjualan` (`id`, `tgl_penjualan`, `customer`, `product_id`, `warehouse_id`, `qty`, `harga_satuan`, `total_harga`, `payment_status`, `payment_method`, `created_by`, `created_at`) VALUES
(1, '2025-10-24', 'Toko Bunga Lestari', 3, 1, 20.000, 30000.00, 600000.00, 'paid', 'transfer', 9, '2025-10-24 17:09:07'),
(2, '2025-10-25', 'Toko Batik Panghegar', 6, 1, 15.000, 30000.00, 450000.00, 'paid', 'transfer', 9, '2025-10-25 16:34:27');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `code` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `code`, `description`) VALUES
(1, 'purchases.create', 'Create purchase'),
(2, 'purchases.receive', 'Receive purchase / mark received'),
(3, 'purchases.view', 'View purchases'),
(4, 'purchases.manage', 'Full management of purchases'),
(5, 'sales.create', 'Create sale'),
(6, 'sales.view', 'View sales'),
(7, 'sales.manage', 'Manage sales (complete/return)'),
(8, 'stock.view', 'View stock balances'),
(9, 'stock.manage', 'Adjust/manage stock (reconciliation, adjust)'),
(10, 'transactions.view', 'View transactions / journal'),
(11, 'transactions.manage', 'Manage transactions / journal entries'),
(12, 'payments.manage', 'Manage payments'),
(13, 'users.manage', 'Manage users & roles'),
(14, 'reports.view', 'View reports');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `unit` varchar(20) DEFAULT 'roll',
  `cost_price` decimal(14,2) DEFAULT 0.00,
  `selling_price` decimal(14,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `sku`, `name`, `description`, `unit`, `cost_price`, `selling_price`, `created_at`, `updated_at`, `image`) VALUES
(1, 'K-001', 'Kain A', NULL, 'meter', 10000.00, 15000.00, '2025-10-24 14:32:43', '2025-10-24 14:32:43', NULL),
(3, '', 'Batik Mega Mendung', '', 'gulung', 50000.00, 0.00, '2025-10-24 16:42:54', '2025-10-24 18:41:49', 'uploads/stok/87c2d405b371776a.jpg'),
(5, NULL, 'Batik Parang Rusak', '', 'gulung', 50000.00, 0.00, '2025-10-24 18:40:58', '2025-10-24 18:47:13', 'uploads/stok/88c8a1efbb257820.jpg'),
(6, NULL, 'Batik Floral', '', 'gulung', 50000.00, 30000.00, '2025-10-25 16:32:18', '2025-10-25 16:33:17', 'uploads/stok/c527302a1973a8ac.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `invoice_no` varchar(100) DEFAULT NULL,
  `date` date NOT NULL DEFAULT curdate(),
  `warehouse_id` int(11) DEFAULT NULL,
  `subtotal` decimal(14,2) DEFAULT 0.00,
  `tax` decimal(14,2) DEFAULT 0.00,
  `total` decimal(14,2) DEFAULT 0.00,
  `status` varchar(30) DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_status` varchar(20) DEFAULT 'unpaid',
  `payment_method` varchar(50) DEFAULT NULL,
  `total_paid` decimal(14,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchases`
--

INSERT INTO `purchases` (`id`, `supplier_id`, `invoice_no`, `date`, `warehouse_id`, `subtotal`, `tax`, `total`, `status`, `created_by`, `created_at`, `payment_status`, `payment_method`, `total_paid`) VALUES
(3, 3, NULL, '2025-10-24', 1, 1000000.00, 0.00, 1000000.00, 'received', 9, '2025-10-24 16:42:54', 'paid', 'transfer', 1000000.00),
(4, 3, NULL, '2025-10-24', 1, 250000.00, 0.00, 250000.00, 'received', 9, '2025-10-24 18:40:58', 'paid', 'transfer', 250000.00),
(5, 3, NULL, '2025-10-25', 1, 500000.00, 0.00, 500000.00, 'received', 9, '2025-10-25 16:32:18', 'paid', 'transfer', 500000.00);

--
-- Triggers `purchases`
--
DELIMITER $$
CREATE TRIGGER `trg_purchase_after_update_status` AFTER UPDATE ON `purchases` FOR EACH ROW BEGIN
  DECLARE cnt INT;

  IF NEW.status = 'received' AND OLD.status <> 'received' THEN
    -- only run if not already processed
    SELECT COUNT(*) INTO cnt FROM stock_movements WHERE reference_type='purchase' AND reference_id = NEW.id AND movement_type='purchase_in';
    IF cnt = 0 THEN
      -- aggregate items & insert stock_balances and stock_movements
      INSERT INTO stock_balances (product_id, warehouse_id, quantity, last_updated)
      SELECT pi.product_id, NEW.warehouse_id, SUM(pi.qty), NOW()
      FROM purchase_items pi WHERE pi.purchase_id = NEW.id
      GROUP BY pi.product_id
      ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), last_updated = NOW();

      INSERT INTO stock_movements (product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, created_at)
      SELECT pi.product_id, NEW.warehouse_id, SUM(pi.qty), 'purchase_in', 'purchase', NEW.id, 'receive purchase', NOW()
      FROM purchase_items pi WHERE pi.purchase_id = NEW.id
      GROUP BY pi.product_id;
    END IF;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `unit_price` decimal(14,2) NOT NULL,
  `subtotal` decimal(14,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_items`
--

INSERT INTO `purchase_items` (`id`, `purchase_id`, `product_id`, `qty`, `unit_price`, `subtotal`) VALUES
(3, 3, 3, 20.000, 50000.00, 1000000.00),
(4, 4, 5, 5.000, 50000.00, 250000.00),
(5, 5, 6, 10.000, 50000.00, 500000.00);

--
-- Triggers `purchase_items`
--
DELIMITER $$
CREATE TRIGGER `trg_purchase_item_after_delete` AFTER DELETE ON `purchase_items` FOR EACH ROW BEGIN
  DECLARE v_wh INT;
  DECLARE v_status VARCHAR(30);
  DECLARE v_qty DECIMAL(14,3);

  SELECT warehouse_id, status INTO v_wh, v_status FROM purchases WHERE id = OLD.purchase_id LIMIT 1;
  IF v_wh IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase has no warehouse assigned';
  END IF;

  IF v_status = 'received' THEN
    SELECT quantity INTO v_qty FROM stock_balances WHERE product_id = OLD.product_id AND warehouse_id = v_wh LIMIT 1;
    IF v_qty IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock not found for product in warehouse';
    END IF;
    IF v_qty < OLD.qty THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Not enough stock to remove after purchase_item delete';
    END IF;

    UPDATE stock_balances SET quantity = quantity - OLD.qty, last_updated = NOW()
      WHERE product_id = OLD.product_id AND warehouse_id = v_wh;

    INSERT INTO stock_movements (product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, created_at)
    VALUES (OLD.product_id, v_wh, -OLD.qty, 'purchase_out_deleted', 'purchase', OLD.purchase_id, 'purchase item deleted', NOW());
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_purchase_item_after_insert` AFTER INSERT ON `purchase_items` FOR EACH ROW BEGIN
  DECLARE v_wh INT;
  DECLARE v_status VARCHAR(30);

  SELECT warehouse_id, status INTO v_wh, v_status FROM purchases WHERE id = NEW.purchase_id LIMIT 1;

  IF v_wh IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase has no warehouse assigned';
  END IF;

  IF v_status = 'received' THEN
    -- upsert stock_balances (add qty)
    INSERT INTO stock_balances (product_id, warehouse_id, quantity, last_updated)
    VALUES (NEW.product_id, v_wh, NEW.qty, NOW())
    ON DUPLICATE KEY UPDATE quantity = quantity + NEW.qty, last_updated = NOW();

    -- record movement
    INSERT INTO stock_movements (product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, performed_by, created_at)
    VALUES (NEW.product_id, v_wh, NEW.qty, 'purchase_in', 'purchase', NEW.purchase_id, 'purchase item received (insert)', NULL, NOW());
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_purchase_item_after_update` AFTER UPDATE ON `purchase_items` FOR EACH ROW BEGIN
  DECLARE v_wh INT;
  DECLARE v_status VARCHAR(30);
  DECLARE diff DECIMAL(14,3);
  DECLARE v_qty DECIMAL(14,3);

  SELECT warehouse_id, status INTO v_wh, v_status FROM purchases WHERE id = NEW.purchase_id LIMIT 1;
  IF v_wh IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase has no warehouse assigned';
  END IF;

  SET diff = NEW.qty - OLD.qty;

  IF v_status = 'received' AND diff <> 0 THEN
    IF diff > 0 THEN
      -- increase stock
      INSERT INTO stock_balances (product_id, warehouse_id, quantity, last_updated)
      VALUES (NEW.product_id, v_wh, diff, NOW())
      ON DUPLICATE KEY UPDATE quantity = quantity + diff, last_updated = NOW();

      INSERT INTO stock_movements (product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, created_at)
      VALUES (NEW.product_id, v_wh, diff, 'purchase_in', 'purchase', NEW.purchase_id, 'purchase item qty increased', NOW());
    ELSE
      -- decrease stock: ensure enough exists
      SELECT quantity INTO v_qty FROM stock_balances WHERE product_id = NEW.product_id AND warehouse_id = v_wh LIMIT 1;
      IF v_qty IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock not found for product in warehouse';
      END IF;
      IF v_qty < ABS(diff) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Not enough stock to reduce after purchase_item update';
      END IF;

      UPDATE stock_balances SET quantity = quantity + diff, last_updated = NOW()
        WHERE product_id = NEW.product_id AND warehouse_id = v_wh; -- diff negative

      INSERT INTO stock_movements (product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, created_at)
      VALUES (NEW.product_id, v_wh, diff, 'purchase_out_adjust', 'purchase', NEW.purchase_id, 'purchase item qty decreased', NOW());
    END IF;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'direktur', 'Akses penuh ke semua modul'),
(2, 'keuangan', 'Akses modul keuangan, penjualan, transaksi'),
(3, 'operasional', 'Akses pembelian & stok (beli ke pabrik, kelola stok di gudang)');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5),
(1, 6),
(1, 7),
(1, 8),
(1, 9),
(1, 10),
(1, 11),
(1, 12),
(1, 13),
(1, 14),
(2, 6),
(2, 7),
(2, 10),
(2, 11),
(2, 12),
(2, 14),
(3, 1),
(3, 2),
(3, 3),
(3, 8),
(3, 9);

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `invoice_no` varchar(100) DEFAULT NULL,
  `date` date NOT NULL DEFAULT curdate(),
  `warehouse_id` int(11) DEFAULT NULL,
  `subtotal` decimal(14,2) DEFAULT 0.00,
  `tax` decimal(14,2) DEFAULT 0.00,
  `total` decimal(14,2) DEFAULT 0.00,
  `status` varchar(30) DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `customer_id`, `invoice_no`, `date`, `warehouse_id`, `subtotal`, `tax`, `total`, `status`, `created_by`, `created_at`) VALUES
(1, NULL, 'S-001', '2025-10-24', 1, 0.00, 0.00, 150000.00, 'completed', NULL, '2025-10-24 14:33:47');

--
-- Triggers `sales`
--
DELIMITER $$
CREATE TRIGGER `trg_sale_after_update_status` AFTER UPDATE ON `sales` FOR EACH ROW BEGIN
  DECLARE cnt INT;

  IF NEW.status = 'completed' AND OLD.status <> 'completed' THEN
    -- check already processed
    SELECT COUNT(*) INTO cnt FROM stock_movements WHERE reference_type='sale' AND reference_id = NEW.id AND movement_type='sale_out';
    IF cnt = 0 THEN
      -- reduce stock aggregated (note: uses VALUES() in ON DUPLICATE KEY UPDATE)
      INSERT INTO stock_balances (product_id, warehouse_id, quantity, last_updated)
      SELECT si.product_id, NEW.warehouse_id, -SUM(si.qty), NOW()
      FROM sale_items si WHERE si.sale_id = NEW.id
      GROUP BY si.product_id
      ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), last_updated = NOW();

      INSERT INTO stock_movements (product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, created_at)
      SELECT si.product_id, NEW.warehouse_id, -SUM(si.qty), 'sale_out', 'sale', NEW.id, 'complete sale', NOW()
      FROM sale_items si WHERE si.sale_id = NEW.id
      GROUP BY si.product_id;
    END IF;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `unit_price` decimal(14,2) NOT NULL,
  `subtotal` decimal(14,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `qty`, `unit_price`, `subtotal`) VALUES
(1, 1, 1, 20.000, 15000.00, 300000.00);

--
-- Triggers `sale_items`
--
DELIMITER $$
CREATE TRIGGER `trg_sale_item_after_delete` AFTER DELETE ON `sale_items` FOR EACH ROW BEGIN
  DECLARE v_wh INT;
  DECLARE s_status VARCHAR(30);

  SELECT warehouse_id, status INTO v_wh, s_status FROM sales WHERE id = OLD.sale_id LIMIT 1;
  IF s_status = 'completed' THEN
    -- add back qty
    INSERT INTO stock_balances (product_id, warehouse_id, quantity, last_updated)
    VALUES (OLD.product_id, v_wh, OLD.qty, NOW())
    ON DUPLICATE KEY UPDATE quantity = quantity + OLD.qty, last_updated = NOW();

    INSERT INTO stock_movements(product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, created_at)
    VALUES (OLD.product_id, v_wh, OLD.qty, 'sale_return_deleted', 'sale', OLD.sale_id, 'sale item deleted, stock restored', NOW());
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sale_item_after_update` AFTER UPDATE ON `sale_items` FOR EACH ROW BEGIN
  DECLARE v_wh INT;
  DECLARE s_status VARCHAR(30);
  DECLARE diff DECIMAL(14,3);
  DECLARE v_qty DECIMAL(14,3);

  SELECT warehouse_id, status INTO v_wh, s_status FROM sales WHERE id = NEW.sale_id LIMIT 1;
  SET diff = NEW.qty - OLD.qty;

  IF s_status = 'completed' AND diff <> 0 THEN
    IF diff > 0 THEN
      -- Perlu mengurangi stok tambahan
      SELECT quantity INTO v_qty
        FROM stock_balances
        WHERE product_id = NEW.product_id AND warehouse_id = v_wh LIMIT 1;

      IF v_qty IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock not found for product in warehouse';
      END IF;

      IF v_qty < diff THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Not enough stock to increase sale qty';
      END IF;

      UPDATE stock_balances
        SET quantity = quantity - diff, last_updated = NOW()
        WHERE product_id = NEW.product_id AND warehouse_id = v_wh;

      INSERT INTO stock_movements(product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, created_at)
      VALUES (NEW.product_id, v_wh, -diff, 'sale_out_adjust', 'sale', NEW.sale_id, 'sale item qty increased', NOW());
    ELSE
      -- diff < 0 : kembalikan stok (abs(diff))
      UPDATE stock_balances
        SET quantity = quantity + ABS(diff), last_updated = NOW()
        WHERE product_id = NEW.product_id AND warehouse_id = v_wh;

      INSERT INTO stock_movements(product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, created_at)
      VALUES (NEW.product_id, v_wh, ABS(diff), 'sale_return_adjust', 'sale', NEW.sale_id, 'sale item qty decreased - stock restored', NOW());
    END IF;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sale_item_before_insert` BEFORE INSERT ON `sale_items` FOR EACH ROW BEGIN
  DECLARE v_wh INT;
  DECLARE s_status VARCHAR(30);
  DECLARE v_qty DECIMAL(14,3);

  SELECT warehouse_id, status INTO v_wh, s_status FROM sales WHERE id = NEW.sale_id LIMIT 1;
  IF v_wh IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sale has no warehouse assigned';
  END IF;

  IF s_status = 'completed' THEN
    SELECT quantity INTO v_qty FROM stock_balances WHERE product_id = NEW.product_id AND warehouse_id = v_wh LIMIT 1;
    IF v_qty IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock not found for product in warehouse';
    END IF;
    IF v_qty < NEW.qty THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Not enough stock for sale';
    END IF;

    UPDATE stock_balances SET quantity = quantity - NEW.qty, last_updated = NOW()
      WHERE product_id = NEW.product_id AND warehouse_id = v_wh;

    INSERT INTO stock_movements(product_id, warehouse_id, change_qty, movement_type, reference_type, reference_id, note, created_at)
      VALUES (NEW.product_id, v_wh, -NEW.qty, 'sale_out', 'sale', NEW.sale_id, 'sale item inserted while completed', NOW());
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `stock_balances`
--

CREATE TABLE `stock_balances` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `quantity` decimal(14,3) NOT NULL DEFAULT 0.000,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_balances`
--

INSERT INTO `stock_balances` (`id`, `product_id`, `warehouse_id`, `quantity`, `last_updated`) VALUES
(3, 3, 1, 20.000, '2025-10-24 17:20:25'),
(4, 5, 1, 10.000, '2025-10-24 18:40:58'),
(5, 6, 1, 5.000, '2025-10-25 16:40:10');

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `change_qty` decimal(14,3) NOT NULL,
  `movement_type` varchar(50) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_movements`
--

INSERT INTO `stock_movements` (`id`, `product_id`, `warehouse_id`, `change_qty`, `movement_type`, `reference_type`, `reference_id`, `note`, `performed_by`, `created_at`) VALUES
(1, 1, 1, 50.000, 'purchase_in', 'purchase', 1, 'purchase item received (insert)', NULL, '2025-10-24 14:33:16'),
(2, 1, 1, -20.000, 'sale_out', 'sale', 1, 'sale item inserted while completed', NULL, '2025-10-24 14:33:47'),
(5, 3, 1, 20.000, 'purchase_in', 'purchase', 3, 'purchase item received (insert)', NULL, '2025-10-24 16:42:54'),
(6, 3, 1, 20.000, 'purchase_in', 'purchase', 3, 'Pembelian #3 - PT Mekar Abadi', 9, '2025-10-24 16:42:54'),
(7, 3, 1, -20.000, 'sale_out', 'sale', 1, 'Penjualan #1 ke Toko Bunga Lestari', 9, '2025-10-24 17:09:07'),
(8, 3, 1, 0.000, 'sale_edit', 'sale', 1, 'Edit Penjualan #1', 9, '2025-10-24 17:15:23'),
(9, 3, 1, 0.000, 'sale_edit', 'sale', 1, 'Edit Penjualan #1', 9, '2025-10-24 17:19:35'),
(10, 3, 1, 0.000, 'sale_edit', 'sale', 1, 'Edit Penjualan #1', 9, '2025-10-24 17:20:26'),
(11, 5, 1, 5.000, 'purchase_in', 'purchase', 4, 'purchase item received (insert)', NULL, '2025-10-24 18:40:58'),
(12, 5, 1, 5.000, 'purchase_in', 'purchase', 4, 'Pembelian #4 - PT Mekar Abadi', 9, '2025-10-24 18:40:58'),
(13, 6, 1, 10.000, 'purchase_in', 'purchase', 5, 'purchase item received (insert)', NULL, '2025-10-25 16:32:18'),
(14, 6, 1, 10.000, 'purchase_in', 'purchase', 5, 'Pembelian #5 - PT Mekar Abadi', 9, '2025-10-25 16:32:18'),
(15, 6, 1, -1.000, 'sale_out', 'sale', 2, 'Penjualan #2 ke Toko Batik Panghegar', 9, '2025-10-25 16:34:27'),
(16, 6, 1, -14.000, 'sale_edit', 'sale', 2, 'Edit Penjualan #2', 9, '2025-10-25 16:40:04'),
(17, 6, 1, 0.000, 'sale_edit', 'sale', 2, 'Edit Penjualan #2', 9, '2025-10-25 16:40:10');

-- --------------------------------------------------------

--
-- Table structure for table `stock_reconciliations`
--

CREATE TABLE `stock_reconciliations` (
  `id` int(11) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `date` date DEFAULT curdate(),
  `performed_by` int(11) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_reconciliation_items`
--

CREATE TABLE `stock_reconciliation_items` (
  `id` int(11) NOT NULL,
  `reconciliation_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `counted_qty` decimal(14,3) NOT NULL,
  `system_qty` decimal(14,3) NOT NULL,
  `difference` decimal(14,3) NOT NULL,
  `note` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `contact` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `contact`, `phone`, `address`, `created_at`) VALUES
(1, 'Pabrik Utama', NULL, NULL, NULL, '2025-10-24 14:32:43'),
(2, 'Pt Mekar Jaya', NULL, NULL, NULL, '2025-10-24 15:53:30'),
(3, 'PT Mekar Abadi', NULL, NULL, NULL, '2025-10-24 16:42:54');

-- --------------------------------------------------------

--
-- Table structure for table `transaksi_keuangan`
--

CREATE TABLE `transaksi_keuangan` (
  `id` int(11) NOT NULL,
  `tgl_transaksi` date NOT NULL,
  `tipe` enum('pendapatan','pengeluaran') NOT NULL,
  `nominal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaksi_keuangan`
--

INSERT INTO `transaksi_keuangan` (`id`, `tgl_transaksi`, `tipe`, `nominal`, `keterangan`, `created_at`) VALUES
(1, '2025-10-24', 'pengeluaran', 1000000.00, 'Pembelian #3 - PT Mekar Abadi', '2025-10-24 16:42:54'),
(3, '2025-10-24', 'pengeluaran', 250000.00, 'Pembelian #4 - PT Mekar Abadi', '2025-10-24 18:40:58'),
(4, '2025-10-25', 'pengeluaran', 500000.00, 'Pembelian #5 - PT Mekar Abadi', '2025-10-25 16:32:18'),
(5, '2025-10-25', 'pendapatan', 450000.00, 'Penjualan #2 ke Toko Batik Panghegar', '2025-10-25 16:34:27');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `role_name` varchar(100) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `nama` varchar(100) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `update_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `theme` varchar(10) NOT NULL DEFAULT 'light' CHECK (`theme` in ('light','dark','system'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `email`, `username`, `password`, `password_hash`, `role_id`, `role_name`, `avatar_path`, `nama`, `profile_picture`, `created_at`, `update_at`, `last_login`, `theme`) VALUES
(8, 'chad123@gmail.com', 'chad123', '', '$2y$10$GjUe71FgsdlWSMAuFxP2I.7sNsGWqzEYJA0m8UjSI/o04es/.1HNu', NULL, 'staff_keuangan', 'uploads/profile/e387349bbf531fcd.png', 'Richard', NULL, '2025-10-23 00:00:00', '2025-10-25 23:45:07', '2025-10-25 23:45:07', 'light'),
(9, 'fems789@gmail.com', 'fems38753', '', '$2y$10$ae72dt0lPswRAPc2F7mR1OxToxUVmA8rBwrJva4dwUdm435ida5Gu', 1, 'direktur', 'uploads/profile/dab0809f01ce5579.jpg', 'Felix', NULL, '2025-10-23 03:07:33', '2025-10-25 23:46:43', '2025-10-25 23:46:22', 'dark'),
(10, 'steve123@gmail.com', 'steve123', '', '$2y$10$Rh5ckSVMXVYjmKP4hPBVUeYP4veNXsXf3Hkf9xS2/w1.5lTeFICaa', NULL, 'staff_operasional', 'uploads/profile/b9853933106290c5.png', 'Steven', NULL, '2025-10-23 03:58:28', '2025-10-25 23:45:39', '2025-10-25 23:45:39', 'light');

-- --------------------------------------------------------

--
-- Table structure for table `user_warehouses`
--

CREATE TABLE `user_warehouses` (
  `user_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warehouses`
--

CREATE TABLE `warehouses` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `warehouses`
--

INSERT INTO `warehouses` (`id`, `name`, `address`, `created_at`) VALUES
(1, 'Gudang Utama', NULL, '2025-10-24 14:32:43');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `journal_items`
--
ALTER TABLE `journal_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ji_journal` (`journal_id`),
  ADD KEY `fk_ji_account` (`account_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `penjualan`
--
ALTER TABLE `penjualan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_purchases_supplier` (`supplier_id`),
  ADD KEY `fk_purchases_warehouse` (`warehouse_id`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pi_product` (`product_id`),
  ADD KEY `idx_pi_purchase` (`purchase_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `fk_rp_perm` (`permission_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sales_customer` (`customer_id`),
  ADD KEY `fk_sales_warehouse` (`warehouse_id`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_si_product` (`product_id`),
  ADD KEY `idx_si_sale` (`sale_id`);

--
-- Indexes for table `stock_balances`
--
ALTER TABLE `stock_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_sb_product_warehouse` (`product_id`,`warehouse_id`),
  ADD KEY `fk_sb_warehouse` (`warehouse_id`),
  ADD KEY `idx_sb_product` (`product_id`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sm_warehouse` (`warehouse_id`),
  ADD KEY `idx_sm_product_wh` (`product_id`,`warehouse_id`);

--
-- Indexes for table `stock_reconciliations`
--
ALTER TABLE `stock_reconciliations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sr_warehouse` (`warehouse_id`);

--
-- Indexes for table `stock_reconciliation_items`
--
ALTER TABLE `stock_reconciliation_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sri_recon` (`reconciliation_id`),
  ADD KEY `fk_sri_product` (`product_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transaksi_keuangan`
--
ALTER TABLE `transaksi_keuangan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role_id` (`role_id`);

--
-- Indexes for table `user_warehouses`
--
ALTER TABLE `user_warehouses`
  ADD PRIMARY KEY (`user_id`,`warehouse_id`),
  ADD KEY `fk_uw_wh` (`warehouse_id`);

--
-- Indexes for table `warehouses`
--
ALTER TABLE `warehouses`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_items`
--
ALTER TABLE `journal_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `penjualan`
--
ALTER TABLE `penjualan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `stock_balances`
--
ALTER TABLE `stock_balances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `stock_reconciliations`
--
ALTER TABLE `stock_reconciliations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_reconciliation_items`
--
ALTER TABLE `stock_reconciliation_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `transaksi_keuangan`
--
ALTER TABLE `transaksi_keuangan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `journal_items`
--
ALTER TABLE `journal_items`
  ADD CONSTRAINT `fk_ji_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `fk_ji_journal` FOREIGN KEY (`journal_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `fk_purchases_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `fk_purchases_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`);

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `fk_pi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_pi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_sales_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`);

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `fk_si_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_si_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_balances`
--
ALTER TABLE `stock_balances`
  ADD CONSTRAINT `fk_sb_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sb_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `fk_sm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sm_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_reconciliations`
--
ALTER TABLE `stock_reconciliations`
  ADD CONSTRAINT `fk_sr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`);

--
-- Constraints for table `stock_reconciliation_items`
--
ALTER TABLE `stock_reconciliation_items`
  ADD CONSTRAINT `fk_sri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `fk_sri_recon` FOREIGN KEY (`reconciliation_id`) REFERENCES `stock_reconciliations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- Constraints for table `user_warehouses`
--
ALTER TABLE `user_warehouses`
  ADD CONSTRAINT `fk_uw_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_uw_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
