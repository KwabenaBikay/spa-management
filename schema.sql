-- Database Schema for The Breeze Spa Management System
-- Database: `spa_db`

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Table structure for `appointments`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_name` varchar(255) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `service_type` varchar(100) NOT NULL,
  `staff_assigned` varchar(100) DEFAULT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `status` enum('Scheduled','Completed','Cancelled') DEFAULT 'Scheduled',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `audit_logs`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity` varchar(50) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `before_data` text DEFAULT NULL,
  `after_data` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `clients`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `service_type` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `duration` varchar(50) DEFAULT NULL,
  `payment_mode` varchar(20) DEFAULT NULL,
  `massage_type` varchar(50) DEFAULT NULL,
  `staff_name` varchar(100) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `client_code` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'Pending',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for `massage_types`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `massage_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `price_60` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_90` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_120` decimal(10,2) NOT NULL DEFAULT 0.00,
  `category` varchar(50) NOT NULL DEFAULT 'Full Body Massage',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Initial massage catalog
INSERT INTO `massage_types` (`id`, `name`, `price_60`, `price_90`, `price_120`, `category`) VALUES
(1, 'Thai Traditional Massage', 250.00, 370.00, 480.00, 'Full Body Massage'),
(2, 'Relaxation Massage', 250.00, 300.00, 420.00, 'Full Body Massage'),
(3, 'Reflexology', 250.00, 370.00, 480.00, 'Full Body Massage'),
(4, 'Royal Thai Massage', 300.00, 400.00, 480.00, 'Full Body Massage'),
(5, 'Aromatherapy', 250.00, 370.00, 480.00, 'Full Body Massage'),
(6, 'Therapeutic Massage', 300.00, 450.00, 580.00, 'Full Body Massage'),
(7, 'Deep Tissue Massage', 350.00, 450.00, 580.00, 'Full Body Massage'),
(8, 'Sports Massage', 250.00, 370.00, 480.00, 'Full Body Massage'),
(9, 'Hot Stones Massage', 300.00, 370.00, 480.00, 'Full Body Massage'),
(10, 'Herbal ball Massage', 300.00, 450.00, 580.00, 'Full Body Massage'),
(11, 'Body Scrub', 260.00, 0.00, 0.00, 'Full Body Massage'),
(12, 'Sauna', 150.00, 150.00, 0.00, 'Full Body Massage'),
(13, 'Head, Neck & Shoulder', 150.00, 0.00, 0.00, 'Express Massage'),
(14, 'Back Only', 150.00, 0.00, 0.00, 'Express Massage'),
(15, 'Foot & arm Massage', 150.00, 0.00, 0.00, 'Express Massage');

-- --------------------------------------------------------
-- Table structure for `services`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_name` varchar(100) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `duration` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Initial services catalog
INSERT INTO `services` (`id`, `service_name`, `section`, `price`, `duration`) VALUES
(12, 'Manicure for Men', 'Nails & Manicure', 70.00, NULL),
(13, 'Manicure for Ladies', 'Nails & Manicure', 50.00, NULL),
(14, 'Manicure (gel polish)', 'Nails & Manicure', 50.00, NULL),
(15, 'Manicure (normal polish)', 'Nails & Manicure', 30.00, NULL),
(16, 'Acrylic (short)', 'Nails & Manicure', 120.00, NULL),
(17, 'Acrylic (medium)', 'Nails & Manicure', 150.00, NULL),
(18, 'Acrylic (long)', 'Nails & Manicure', 200.00, NULL),
(19, 'Acrylic (extra long)', 'Nails & Manicure', 250.00, NULL),
(20, 'Acrylic Tip', 'Nails & Manicure', 60.00, NULL),
(21, 'Stick On', 'Nails & Manicure', 60.00, NULL),
(22, 'Nail Art (normal)', 'Nails & Manicure', 20.00, NULL),
(23, 'Nail Art (medium)', 'Nails & Manicure', 40.00, NULL),
(24, 'Nail Art (complicated)', 'Nails & Manicure', 80.00, NULL),
(25, 'Acrylic Dissolving', 'Nails & Manicure', 80.00, NULL),
(26, 'Stick-on Dissolving', 'Nails & Manicure', 50.00, NULL),
(27, 'Trimming Only', 'Nails & Manicure', 30.00, NULL),
(28, 'Trimming with Normal Polish', 'Nails & Manicure', 40.00, NULL),
(29, 'Trimming with Gel Polish', 'Nails & Manicure', 50.00, NULL),
(30, 'Spa Pedi', 'Nails & Manicure', 150.00, NULL),
(31, 'Spa Pedi with Polish', 'Nails & Manicure', 130.00, NULL),
(32, 'Spa Pedi with Gel Polish', 'Nails & Manicure', 150.00, NULL),
(33, 'Spa Pedi with Stick-on', 'Nails & Manicure', 200.00, NULL),
(34, 'Spa Pedi with Acrylic', 'Nails & Manicure', 300.00, NULL),
(35, 'Breeze Pedi Deluxe', 'Nails & Manicure', 400.00, NULL),
(36, 'Classical Facials', 'Facials', 250.00, NULL),
(37, 'Brightening & Hydrating', 'Facials', 350.00, NULL),
(38, 'Deluxe Facials', 'Facials', 400.00, NULL),
(39, 'Deep cleansing', 'Facials', 300.00, NULL),
(40, 'Adult cut', 'Hair Barbering', 40.00, NULL),
(41, 'Kids cut', 'Hair Barbering', 20.00, NULL),
(42, 'Perm cut', 'Hair Barbering', 100.00, NULL),
(43, 'Shape', 'Hair Barbering', 15.00, NULL),
(44, 'Shape & Shave', 'Hair Barbering', 20.00, NULL),
(45, 'Black hair dye', 'Hair Barbering', 20.00, NULL),
(46, 'Gold hair dye', 'Hair Barbering', 50.00, NULL),
(47, 'Other colour dye', 'Hair Barbering', 80.00, NULL),
(48, 'Simple style', 'Hair Barbering', 20.00, NULL),
(49, 'Complex style', 'Hair Barbering', 40.00, NULL),
(50, 'Simple cornrow (own hair)', 'Hair Salon', 40.00, NULL),
(51, 'Simple cornrow (children) own hair', 'Hair Salon', 50.00, NULL),
(52, 'Cornrow with wig (all back)', 'Hair Salon', 150.00, NULL),
(53, 'Cornrow with design', 'Hair Salon', 200.00, NULL),
(54, 'Cornrow Rasta', 'Hair Salon', 250.00, NULL),
(55, 'Knotless Braids', 'Hair Salon', 200.00, NULL),
(56, 'Knotless Braids (long)', 'Hair Salon', 300.00, NULL),
(57, 'Goddess braids/Boho', 'Hair Salon', 200.00, NULL),
(58, 'Goddess braids/Boho long', 'Hair Salon', 300.00, NULL),
(59, 'Butterfly braids', 'Hair Salon', 300.00, NULL),
(60, 'Butterfly braids long', 'Hair Salon', 400.00, NULL),
(61, 'Bob Braids', 'Hair Salon', 150.00, NULL),
(62, 'Afro twists', 'Hair Salon', 150.00, NULL),
(63, 'Natural twists', 'Hair Salon', 100.00, NULL),
(64, 'Afro twists long', 'Hair Salon', 200.00, NULL),
(65, 'Shoulder length', 'Hair Salon', 150.00, NULL),
(66, 'Medium length braids', 'Hair Salon', 180.00, NULL),
(67, 'Long braids', 'Hair Salon', 300.00, NULL),
(68, 'Jumbo Box braids', 'Hair Salon', 150.00, NULL),
(69, 'Crochet braids', 'Hair Salon', 250.00, NULL),
(70, 'Sew in-weave on frontal', 'Hair Salon', 250.00, NULL),
(71, 'Sew in-weave on closure', 'Hair Salon', 150.00, NULL),
(72, 'Sew in-weave on (360)', 'Hair Salon', 300.00, NULL),
(73, 'Wig cap making Frontal', 'Hair Salon', 250.00, NULL),
(74, 'Wig cap making Closure', 'Hair Salon', 200.00, NULL),
(75, 'Pony tail with washing', 'Hair Salon', 150.00, NULL),
(76, 'Pony tail with re-touch', 'Hair Salon', 250.00, NULL),
(77, 'Revamping', 'Hair Salon', 120.00, NULL),
(78, 'Wig colouring', 'Hair Salon', 200.00, NULL),
(79, 'Wig cap installation (Frontal)', 'Hair Salon', 150.00, NULL),
(80, 'Wig cap installation (Closure)', 'Hair Salon', 120.00, NULL),
(81, 'Wig styling', 'Hair Salon', 100.00, NULL),
(82, 'Bridal hair(sew-in)', 'Hair Salon', 500.00, NULL),
(83, 'Bridal Ponytail', 'Hair Salon', 350.00, NULL),
(84, 'Artificial locs (bring own hair)', 'Hair Salon', 350.00, NULL),
(85, 'Butterfly locks(medium length)', 'Hair Salon', 350.00, NULL),
(86, 'Butterfly locks(Long)', 'Hair Salon', 450.00, NULL),
(87, 'Faux locs/ dread cane', 'Hair Salon', 400.00, NULL),
(88, 'Faux locs/ dread cane long', 'Hair Salon', 500.00, NULL),
(89, 'Shampoo, Conditioner', 'Hair Salon', 40.00, NULL),
(90, 'Deep conditioning treatment', 'Hair Salon', 100.00, NULL),
(91, 'Keratin Treatment', 'Hair Salon', 150.00, NULL),
(92, 'Scalp treatment', 'Hair Salon', 200.00, NULL),
(93, 'Relaxer virgin', 'Hair Salon', 200.00, NULL),
(94, 'Relaxer with own cream( Virgin hair)', 'Hair Salon', 100.00, NULL),
(95, 'Relaxer without own cream', 'Hair Salon', 150.00, NULL),
(96, 'Eyebrow shaping', 'Make-up', 20.00, NULL),
(97, 'Eyelash (purchased)', 'Make-up', 50.00, NULL),
(98, 'Eyelash (own)', 'Make-up', 30.00, NULL),
(99, 'Natural Look', 'Make-up', 150.00, NULL),
(100, 'Soft Glam', 'Make-up', 200.00, NULL),
(101, 'Event', 'Make-up', 350.00, NULL),
(102, 'Bridal Engagement', 'Make-up', 1500.00, NULL),
(103, 'Bridal Wedding', 'Make-up', 2000.00, NULL),
(104, 'Bridal wedding & engagement', 'Make-up', 2500.00, NULL);

-- --------------------------------------------------------
-- Table structure for `staff`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Initial default administrator account:
-- Username: admin
-- Default Password: admin123 (Please change upon initial login)
INSERT INTO `staff` (`id`, `name`, `username`, `password`, `role`) VALUES
(1, 'Administrator', 'admin', '$2y$10$gwZtz4hq6F7iaeX8XzmrZe3CJBlDXTWiw9v.22q16gN0rtDEfMgeK', 'admin');

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
