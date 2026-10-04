-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: sari
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `accounts_payable`
--

DROP TABLE IF EXISTS `accounts_payable`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounts_payable` (
  `ap_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `invoice_no` varchar(30) NOT NULL,
  `po_number` varchar(30) NOT NULL,
  `supplier` varchar(150) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `due_date` date NOT NULL,
  `status` enum('Pending','Partial','Paid') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`ap_id`),
  UNIQUE KEY `uniq_ap_item` (`item_id`),
  KEY `idx_ap_request` (`request_id`),
  KEY `idx_ap_status` (`status`),
  KEY `idx_accounts_payable_company` (`company_id`),
  CONSTRAINT `fk_accounts_payable_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ap_item` FOREIGN KEY (`item_id`) REFERENCES `stock_request_items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ap_request` FOREIGN KEY (`request_id`) REFERENCES `stock_requests` (`request_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1848 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts_payable`
--

/*!40000 ALTER TABLE `accounts_payable` DISABLE KEYS */;
/*!40000 ALTER TABLE `accounts_payable` ENABLE KEYS */;

--
-- Table structure for table `ap_payments`
--

DROP TABLE IF EXISTS `ap_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ap_payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `ap_id` int(11) NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL,
  `payment_method` enum('Cash','GCash','Check','Bank Transfer') NOT NULL DEFAULT 'Cash',
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `receipt_id` int(11) DEFAULT NULL,
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`payment_id`),
  KEY `idx_appay_ap` (`ap_id`),
  KEY `fk_appay_receipt` (`receipt_id`),
  KEY `idx_ap_payments_company` (`company_id`),
  CONSTRAINT `fk_ap_payments_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_appay_ap` FOREIGN KEY (`ap_id`) REFERENCES `accounts_payable` (`ap_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appay_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `ap_receipts` (`receipt_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ap_payments`
--

/*!40000 ALTER TABLE `ap_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `ap_payments` ENABLE KEYS */;

--
-- Table structure for table `ap_receipts`
--

DROP TABLE IF EXISTS `ap_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ap_receipts` (
  `receipt_id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_code` varchar(30) NOT NULL,
  `ap_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`receipt_id`),
  UNIQUE KEY `uniq_receipt_code` (`receipt_code`),
  KEY `idx_receipt_ap` (`ap_id`),
  KEY `idx_receipt_payment` (`payment_id`),
  KEY `idx_ap_receipts_company` (`company_id`),
  CONSTRAINT `fk_ap_receipts_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_receipt_ap` FOREIGN KEY (`ap_id`) REFERENCES `accounts_payable` (`ap_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_receipt_payment` FOREIGN KEY (`payment_id`) REFERENCES `ap_payments` (`payment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ap_receipts`
--

/*!40000 ALTER TABLE `ap_receipts` DISABLE KEYS */;
/*!40000 ALTER TABLE `ap_receipts` ENABLE KEYS */;

--
-- Table structure for table `applications`
--

DROP TABLE IF EXISTS `applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `applications` (
  `application_id` int(11) NOT NULL AUTO_INCREMENT,
  `job_id` int(11) NOT NULL,
  `applicant_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `location` varchar(150) NOT NULL,
  `cover_note` text DEFAULT NULL,
  `resume` varchar(255) NOT NULL,
  `status` varchar(255) DEFAULT 'Pending',
  `rejected_by` varchar(50) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `email_verified` enum('0','1') DEFAULT '0',
  `verification_token` varchar(64) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`application_id`),
  KEY `job_id` (`job_id`),
  KEY `applicant_id` (`applicant_id`),
  KEY `idx_applications_company` (`company_id`),
  CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `job` (`job_id`),
  CONSTRAINT `applications_ibfk_2` FOREIGN KEY (`applicant_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_applications_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=99900105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `applications`
--

/*!40000 ALTER TABLE `applications` DISABLE KEYS */;
/*!40000 ALTER TABLE `applications` ENABLE KEYS */;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `latitude_in` decimal(10,8) DEFAULT NULL,
  `longitude_in` decimal(11,8) DEFAULT NULL,
  `latitude_out` decimal(10,8) DEFAULT NULL,
  `longitude_out` decimal(11,8) DEFAULT NULL,
  `photo_in` varchar(255) DEFAULT NULL,
  `photo_out` varchar(255) DEFAULT NULL,
  `late_minutes` int(11) DEFAULT 0,
  `working_hours` decimal(5,2) DEFAULT 0.00,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `status` enum('Present','Late','Absent','Half Day') DEFAULT 'Present',
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `undertime_minutes` int(11) DEFAULT 0,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`attendance_id`),
  KEY `idx_employee_date` (`employee_id`,`attendance_date`),
  KEY `idx_attendance_company` (`company_id`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1723 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;

--
-- Table structure for table `attendance_corrections`
--

DROP TABLE IF EXISTS `attendance_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_corrections` (
  `correction_id` int(11) NOT NULL AUTO_INCREMENT,
  `attendance_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `reason` text NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`correction_id`),
  KEY `attendance_id` (`attendance_id`),
  KEY `employee_id` (`employee_id`),
  KEY `idx_attendance_corrections_company` (`company_id`),
  CONSTRAINT `attendance_corrections_ibfk_1` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`attendance_id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_corrections_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_corrections_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_corrections`
--

/*!40000 ALTER TABLE `attendance_corrections` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_corrections` ENABLE KEYS */;

--
-- Table structure for table `attendance_policy`
--

DROP TABLE IF EXISTS `attendance_policy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_policy` (
  `policy_id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `shift_name` varchar(100) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `grace_minutes` int(11) DEFAULT 15,
  `overtime_after` int(11) DEFAULT 30,
  `working_hours` decimal(4,2) DEFAULT 8.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`policy_id`),
  KEY `branch_id` (`branch_id`),
  KEY `idx_attendance_policy_company` (`company_id`),
  CONSTRAINT `attendance_policy_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_policy_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_policy`
--

/*!40000 ALTER TABLE `attendance_policy` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_policy` ENABLE KEYS */;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(150) NOT NULL DEFAULT 'System',
  `action` varchar(100) NOT NULL,
  `entity` varchar(60) NOT NULL,
  `entity_id` varchar(60) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `entity` (`entity`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES (34,21,'Super Admin','Application approved','company','5946','NCST PUREMART','::1','2026-10-03 05:40:00'),(35,21,'System','Agreement template updated','settings','1','RetailCore_Service_Agreement.pdf','::1','2026-10-03 05:54:33'),(36,21,'Super Admin','Agreement approved','contract','3','AGR-20261003-05946 - NCST PUREMART','::1','2026-10-03 06:21:53');
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;

--
-- Table structure for table `branch`
--

DROP TABLE IF EXISTS `branch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branch` (
  `branch_id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `branch_name` varchar(255) NOT NULL,
  `complete_address` varchar(255) NOT NULL,
  `province` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `allowed_radius` int(11) DEFAULT 3000,
  `barangay` varchar(255) NOT NULL,
  `contact` varchar(11) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `operating_hours` decimal(4,2) DEFAULT NULL,
  `opening_time` time DEFAULT NULL,
  `closing_time` time DEFAULT NULL,
  `opening_date` date NOT NULL,
  `status` varchar(255) NOT NULL,
  `branch_manager` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`branch_id`),
  KEY `idx_branch_company` (`company_id`),
  CONSTRAINT `fk_branch_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9990022 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branch`
--

/*!40000 ALTER TABLE `branch` DISABLE KEYS */;
INSERT INTO `branch` VALUES (9990013,5946,'Gentri','Pozzuoli Street, Bella Vista Homes, Santiago, General Trias, Cavite, Calabarzon, 4107, Philippines','Cavite','General Trias',14.33589398,120.91565788,3000,'Bella Vista Homes',NULL,NULL,9.00,'08:00:00','17:00:00','2026-10-04','Active',NULL);
/*!40000 ALTER TABLE `branch` ENABLE KEYS */;

--
-- Table structure for table `capital_ledger`
--

DROP TABLE IF EXISTS `capital_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `capital_ledger` (
  `ledger_id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('Sale Income','Stock Purchase','Capital Added') NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_code` varchar(50) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `balance_after` decimal(12,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`ledger_id`),
  KEY `idx_type` (`type`),
  KEY `idx_reference` (`reference_id`),
  KEY `idx_capital_ledger_company` (`company_id`),
  CONSTRAINT `fk_capital_ledger_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1926 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `capital_ledger`
--

/*!40000 ALTER TABLE `capital_ledger` DISABLE KEYS */;
/*!40000 ALTER TABLE `capital_ledger` ENABLE KEYS */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`category_id`),
  KEY `idx_categories_company` (`company_id`),
  CONSTRAINT `fk_categories_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9990014 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;

--
-- Table structure for table `chatbot_messages`
--

DROP TABLE IF EXISTS `chatbot_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chatbot_messages` (
  `message_id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL,
  `question` varchar(500) NOT NULL,
  `intent_id` varchar(60) DEFAULT NULL,
  `matched_by` enum('keyword','ai','ai_chat') DEFAULT NULL,
  `tools_used` varchar(255) DEFAULT NULL,
  `outcome` enum('answered','no_match','denied_role','denied_plan') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`message_id`),
  KEY `idx_chatbot_messages_user` (`company_id`,`user_id`,`created_at`),
  CONSTRAINT `fk_chatbot_messages_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3040 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chatbot_messages`
--

/*!40000 ALTER TABLE `chatbot_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `chatbot_messages` ENABLE KEYS */;

--
-- Table structure for table `chatbot_topic_plans`
--

DROP TABLE IF EXISTS `chatbot_topic_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chatbot_topic_plans` (
  `topic` varchar(40) NOT NULL,
  `plan_id` int(11) NOT NULL,
  PRIMARY KEY (`topic`,`plan_id`),
  KEY `fk_chatbot_topic_plans_plan` (`plan_id`),
  CONSTRAINT `fk_chatbot_topic_plans_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chatbot_topic_plans`
--

/*!40000 ALTER TABLE `chatbot_topic_plans` DISABLE KEYS */;
INSERT INTO `chatbot_topic_plans` VALUES ('attendance',2),('attendance',3),('branch',2),('branch',3),('cross_branch',3),('finance',2),('finance',3),('hrms',2),('hrms',3),('inventory',1),('inventory',2),('inventory',3),('leave',2),('leave',3),('payroll',2),('payroll',3),('pos',1),('pos',2),('pos',3),('recruitment',2),('recruitment',3),('reports',1),('reports',2),('reports',3),('staff',1),('staff',2),('staff',3);
/*!40000 ALTER TABLE `chatbot_topic_plans` ENABLE KEYS */;

--
-- Table structure for table `company`
--

DROP TABLE IF EXISTS `company`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company` (
  `company_id` int(11) NOT NULL AUTO_INCREMENT,
  `company_code` varchar(30) DEFAULT NULL,
  `company_name` varchar(150) NOT NULL,
  `business_type` enum('Retail Store','Wholesale','Supermarket','Convenience Store','Franchise','Other') DEFAULT 'Retail Store',
  `owner_name` varchar(150) NOT NULL,
  `owner_first_name` varchar(40) DEFAULT NULL,
  `owner_middle_name` varchar(40) DEFAULT NULL,
  `owner_last_name` varchar(40) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `tin_number` varchar(30) DEFAULT NULL,
  `tin_document` varchar(255) DEFAULT NULL,
  `bir_registration_date` date DEFAULT NULL,
  `bir_rdo_code` varchar(10) DEFAULT NULL,
  `bir_ocn` varchar(40) DEFAULT NULL,
  `business_reg_number` varchar(60) DEFAULT NULL,
  `business_reg_document` varchar(255) DEFAULT NULL,
  `dti_sec_registration` varchar(60) DEFAULT NULL,
  `dti_sec_document` varchar(255) DEFAULT NULL,
  `dti_registration_date` date DEFAULT NULL,
  `dti_expiry_date` date DEFAULT NULL,
  `business_permit` varchar(60) DEFAULT NULL,
  `business_permit_document` varchar(255) DEFAULT NULL,
  `supporting_document` varchar(255) DEFAULT NULL,
  `number_of_branches` int(11) DEFAULT NULL,
  `estimated_employees` int(11) DEFAULT NULL,
  `business_asset_range` varchar(60) DEFAULT NULL,
  `business_size` enum('Micro','Small','Medium','Large') DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','Active','Suspended','Inactive') DEFAULT 'Pending',
  `review_reason` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approval_token` varchar(64) DEFAULT NULL,
  `approval_token_expires_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`company_id`),
  UNIQUE KEY `company_code` (`company_code`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_company_approval_token` (`approval_token`),
  KEY `dti_expiry_date` (`dti_expiry_date`)
) ENGINE=InnoDB AUTO_INCREMENT=999003 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company`
--

/*!40000 ALTER TABLE `company` DISABLE KEYS */;
INSERT INTO `company` VALUES (5946,'COMP-1001','NCST PUREMART',NULL,'cleint mesias salarda','cleint','mesias','salarda','cleintsalarda02@gmail.com','09123456789','707-046-841-00000','uploads/business_documents/COMP-1001_tin_document_a7064420bb69bd1c.jpg','2023-09-11',NULL,'036RC20240000002730',NULL,NULL,'4955922','uploads/business_documents/COMP-1001_dti_sec_document_81cd6d61f4bc928e.jpg','2023-05-18','2028-05-18',NULL,NULL,'uploads/business_documents/COMP-1001_dti_sec_document_81cd6d61f4bc928e.jpg',NULL,NULL,NULL,'Large','blk 43 lot 3 bella vista subdivision, General Trias, Cavite','General Trias','Cavite','4107',NULL,'Active','',21,'2026-10-03 13:39:54','7ee152de77d636d759ab7c22febe4f1ae5b3cd8e19f83b0984fc5aa90a950b5c','2026-10-10 13:39:54','2026-10-03 13:38:23','2026-10-03 05:38:23','2026-10-03 06:22:32');
/*!40000 ALTER TABLE `company` ENABLE KEYS */;

--
-- Table structure for table `company_contracts`
--

DROP TABLE IF EXISTS `company_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company_contracts` (
  `contract_id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `contract_number` varchar(30) NOT NULL,
  `issued_template` varchar(255) DEFAULT NULL,
  `signed_contract` varchar(255) DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `status` enum('Issued','Signed') NOT NULL DEFAULT 'Issued',
  `review` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `review_remarks` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`contract_id`),
  UNIQUE KEY `uniq_company_contract_number` (`contract_number`),
  UNIQUE KEY `uniq_company_contract_subscription` (`subscription_id`),
  KEY `idx_company_contract_company` (`company_id`),
  CONSTRAINT `fk_company_contract_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_contracts`
--

/*!40000 ALTER TABLE `company_contracts` DISABLE KEYS */;
INSERT INTO `company_contracts` VALUES (3,5946,5927,'AGR-20261003-05946','uploads/company_contracts/template_97ebdb2681d76599.pdf','uploads/company_contracts/AGR-20261003-05946_signed_7d1367a480721ee0.pdf','2026-10-03 14:21:05','Signed','Approved',NULL,21,'2026-10-03 14:21:53','2026-10-03 05:40:58','2026-10-03 06:21:53');
/*!40000 ALTER TABLE `company_contracts` ENABLE KEYS */;

--
-- Table structure for table `company_review_history`
--

DROP TABLE IF EXISTS `company_review_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company_review_history` (
  `review_id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `action` enum('Submitted','Approved','Rejected','Resubmitted') NOT NULL,
  `reason` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`review_id`),
  KEY `idx_review_company` (`company_id`),
  CONSTRAINT `fk_company_review_history_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_review_history`
--

/*!40000 ALTER TABLE `company_review_history` DISABLE KEYS */;
INSERT INTO `company_review_history` VALUES (58,5946,'Submitted','Application submitted by cleint mesias salarda (cleintsalarda02@gmail.com).',NULL,'2026-10-03 05:38:23'),(59,5946,'Approved','',21,'2026-10-03 05:39:54');
/*!40000 ALTER TABLE `company_review_history` ENABLE KEYS */;

--
-- Table structure for table `company_subscriptions`
--

DROP TABLE IF EXISTS `company_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company_subscriptions` (
  `subscription_id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `billing_cycle` enum('Monthly','Yearly') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `start_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `renewal_date` date DEFAULT NULL,
  `payment_status` enum('Pending','Paid','Failed') DEFAULT 'Pending',
  `status` enum('Pending','Trial','Active','Expired','Cancelled') DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`subscription_id`),
  KEY `plan_id` (`plan_id`),
  KEY `fk_company` (`company_id`),
  CONSTRAINT `company_subscriptions_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`plan_id`),
  CONSTRAINT `fk_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_company_subscriptions_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5934 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_subscriptions`
--

/*!40000 ALTER TABLE `company_subscriptions` DISABLE KEYS */;
INSERT INTO `company_subscriptions` VALUES (5927,5946,2,'Monthly',8999.00,'2026-10-03','2026-11-03','2026-11-03','Paid','Active','Plan chosen by the owner. Awaiting payment. | Paid online via PayMongo.','2026-10-03 05:38:23');
/*!40000 ALTER TABLE `company_subscriptions` ENABLE KEYS */;

--
-- Table structure for table `department`
--

DROP TABLE IF EXISTS `department`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `department` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(100) NOT NULL,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `uq_department_per_company` (`company_id`,`department_name`),
  KEY `idx_department_company` (`company_id`),
  CONSTRAINT `fk_department_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `department`
--

/*!40000 ALTER TABLE `department` DISABLE KEYS */;
/*!40000 ALTER TABLE `department` ENABLE KEYS */;

--
-- Table structure for table `employee_biometrics`
--

DROP TABLE IF EXISTS `employee_biometrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_biometrics` (
  `biometric_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `biometric_type` varchar(50) DEFAULT 'Face',
  `front_image` varchar(255) DEFAULT NULL,
  `left_image` varchar(255) DEFAULT NULL,
  `right_image` varchar(255) DEFAULT NULL,
  `up_image` varchar(255) DEFAULT NULL,
  `down_image` varchar(255) DEFAULT NULL,
  `face_descriptor` longtext DEFAULT NULL,
  `captured_angles` int(11) DEFAULT 0,
  `quality_score` decimal(5,2) DEFAULT NULL,
  `status` enum('Pending','Captured','Verified','Rejected') DEFAULT 'Pending',
  `captured_by` int(11) DEFAULT NULL,
  `captured_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`biometric_id`),
  KEY `employee_id` (`employee_id`),
  KEY `idx_employee_biometrics_company` (`company_id`),
  CONSTRAINT `employee_biometrics_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_employee_biometrics_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_biometrics`
--

/*!40000 ALTER TABLE `employee_biometrics` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_biometrics` ENABLE KEYS */;

--
-- Table structure for table `employee_contracts`
--

DROP TABLE IF EXISTS `employee_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_contracts` (
  `contract_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `contract_number` varchar(30) DEFAULT NULL,
  `contract_title` varchar(150) DEFAULT NULL,
  `company_contract` varchar(255) DEFAULT NULL,
  `signed_contract` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Sent','Signed') DEFAULT 'Pending',
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `hr_review` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `hr_remarks` text DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`contract_id`),
  UNIQUE KEY `contract_number` (`contract_number`),
  KEY `employee_id` (`employee_id`),
  KEY `idx_employee_contracts_company` (`company_id`),
  CONSTRAINT `employee_contracts_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_employee_contracts_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_contracts`
--

/*!40000 ALTER TABLE `employee_contracts` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_contracts` ENABLE KEYS */;

--
-- Table structure for table `employee_documents`
--

DROP TABLE IF EXISTS `employee_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `document_name` varchar(150) NOT NULL,
  `document_type` varchar(100) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `status` enum('Missing','Uploaded','Verified','Rejected') DEFAULT 'Missing',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `uploaded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`document_id`),
  KEY `fk_employee_documents_employee` (`employee_id`),
  KEY `idx_employee_documents_company` (`company_id`),
  CONSTRAINT `fk_employee_documents_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_employee_documents_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_documents`
--

/*!40000 ALTER TABLE `employee_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_documents` ENABLE KEYS */;

--
-- Table structure for table `employee_government_ids`
--

DROP TABLE IF EXISTS `employee_government_ids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_government_ids` (
  `government_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `id_type` enum('SSS','PhilHealth','Pag-IBIG','TIN','National ID','Passport','Driver License') NOT NULL,
  `id_number` varchar(100) DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `status` enum('Missing','Submitted','Verified','Rejected') DEFAULT 'Missing',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`government_id`),
  KEY `employee_id` (`employee_id`),
  KEY `idx_employee_government_ids_company` (`company_id`),
  CONSTRAINT `employee_government_ids_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_employee_government_ids_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_government_ids`
--

/*!40000 ALTER TABLE `employee_government_ids` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_government_ids` ENABLE KEYS */;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `employee_id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `employee_code` varchar(20) DEFAULT NULL,
  `application_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `location` text DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(30) DEFAULT NULL,
  `civil_status` varchar(30) DEFAULT NULL,
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_number` varchar(30) DEFAULT NULL,
  `emergency_contact_relationship` varchar(50) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `employment_status` varchar(30) NOT NULL DEFAULT 'Pre-Employee',
  `archived_at` datetime DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT 'default.png',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `employee_code` (`employee_code`),
  UNIQUE KEY `application_id` (`application_id`),
  KEY `branch_id` (`branch_id`),
  KEY `job_id` (`job_id`),
  KEY `idx_employees_company` (`company_id`),
  CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`application_id`),
  CONSTRAINT `employees_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`),
  CONSTRAINT `employees_ibfk_3` FOREIGN KEY (`job_id`) REFERENCES `job` (`job_id`),
  CONSTRAINT `fk_employees_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=99900110 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` VALUES (99900107,5946,NULL,NULL,'Daizy',NULL,'Magpantay',NULL,'daizymagpantay0@gmail.com','09123456789',NULL,NULL,NULL,NULL,NULL,NULL,NULL,9990013,NULL,'Active',NULL,'default.png','2026-10-03 07:15:18'),(99900108,5946,NULL,NULL,'cleint',NULL,'',NULL,'cleintraymund@gmail.com','09123456789',NULL,NULL,NULL,NULL,NULL,NULL,NULL,9990013,NULL,'Active',NULL,'default.png','2026-10-03 07:15:52'),(99900109,5946,NULL,NULL,'cleint',NULL,'',NULL,'cleintraymundsalarda@gmail.com','09123456789',NULL,NULL,NULL,NULL,NULL,NULL,NULL,9990013,NULL,'Active',NULL,'default.png','2026-10-03 07:16:44');
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;

--
-- Table structure for table `employment`
--

DROP TABLE IF EXISTS `employment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employment` (
  `employment_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `employment_type` enum('Probationary','Regular','Contractual','Part-Time') DEFAULT 'Probationary',
  `job_level` enum('Rank & File','Senior Staff','Supervisor','Manager') DEFAULT 'Rank & File',
  `supervisor_id` int(11) DEFAULT NULL,
  `shift` varchar(50) DEFAULT NULL,
  `working_days` varchar(100) DEFAULT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `break_duration` int(11) DEFAULT NULL,
  `rest_day` varchar(30) DEFAULT NULL,
  `employment_status` enum('Pre-Employee','Official Employee','Resigned','Terminated') DEFAULT 'Pre-Employee',
  `salary` decimal(10,2) DEFAULT NULL,
  `salary_type` enum('Monthly','Daily','Hourly') DEFAULT 'Monthly',
  `pay_frequency` enum('Semi-Monthly','Weekly','Monthly') DEFAULT 'Semi-Monthly',
  `hire_date` date DEFAULT NULL,
  `official_start_date` date DEFAULT NULL,
  `regularization_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`employment_id`),
  UNIQUE KEY `uq_employment_employee` (`employee_id`),
  KEY `employee_id` (`employee_id`),
  KEY `idx_employment_company` (`company_id`),
  CONSTRAINT `employment_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `fk_employment_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employment`
--

/*!40000 ALTER TABLE `employment` DISABLE KEYS */;
/*!40000 ALTER TABLE `employment` ENABLE KEYS */;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expenses` (
  `expense_id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_code` varchar(20) NOT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL,
  `vendor` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('Cash','GCash','Check','Bank Transfer') NOT NULL DEFAULT 'Cash',
  `receipt_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`expense_id`),
  UNIQUE KEY `uq_expense_code_per_company` (`company_id`,`expense_code`),
  KEY `idx_category` (`category`),
  KEY `idx_expense_date` (`expense_date`),
  KEY `idx_expenses_company` (`company_id`),
  CONSTRAINT `fk_expenses_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1871 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;

--
-- Table structure for table `finance_capital`
--

DROP TABLE IF EXISTS `finance_capital`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_capital` (
  `capital_id` int(11) NOT NULL AUTO_INCREMENT,
  `current_capital` decimal(12,2) NOT NULL DEFAULT 10000.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`capital_id`),
  KEY `idx_finance_capital_company` (`company_id`),
  CONSTRAINT `fk_finance_capital_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1340 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_capital`
--

/*!40000 ALTER TABLE `finance_capital` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_capital` ENABLE KEYS */;

--
-- Table structure for table `government_contributions`
--

DROP TABLE IF EXISTS `government_contributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `government_contributions` (
  `contribution_id` int(11) NOT NULL AUTO_INCREMENT,
  `contribution_name` varchar(50) NOT NULL,
  `deduction_rate` decimal(5,2) NOT NULL,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`contribution_id`),
  UNIQUE KEY `uq_contribution_per_company` (`company_id`,`contribution_name`),
  KEY `idx_government_contributions_company` (`company_id`),
  CONSTRAINT `fk_government_contributions_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `government_contributions`
--

/*!40000 ALTER TABLE `government_contributions` DISABLE KEYS */;
/*!40000 ALTER TABLE `government_contributions` ENABLE KEYS */;

--
-- Table structure for table `hiring_recommendations`
--

DROP TABLE IF EXISTS `hiring_recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hiring_recommendations` (
  `recommendation_id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `interview_result_id` int(11) NOT NULL,
  `hr_comments` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `recommended_by` int(11) NOT NULL,
  `recommended_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`recommendation_id`),
  KEY `application_id` (`application_id`),
  KEY `job_id` (`job_id`),
  KEY `interview_result_id` (`interview_result_id`),
  KEY `recommended_by` (`recommended_by`),
  KEY `idx_hiring_recommendations_company` (`company_id`),
  CONSTRAINT `fk_hiring_recommendations_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `hiring_recommendations_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`application_id`),
  CONSTRAINT `hiring_recommendations_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `job` (`job_id`),
  CONSTRAINT `hiring_recommendations_ibfk_3` FOREIGN KEY (`interview_result_id`) REFERENCES `interview_results` (`result_id`),
  CONSTRAINT `hiring_recommendations_ibfk_4` FOREIGN KEY (`recommended_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hiring_recommendations`
--

/*!40000 ALTER TABLE `hiring_recommendations` DISABLE KEYS */;
/*!40000 ALTER TABLE `hiring_recommendations` ENABLE KEYS */;

--
-- Table structure for table `interview_results`
--

DROP TABLE IF EXISTS `interview_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `interview_results` (
  `result_id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `interview_id` int(11) DEFAULT NULL,
  `score` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `recommendation` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`result_id`),
  UNIQUE KEY `unique_interview_result` (`interview_id`),
  KEY `application_id` (`application_id`),
  KEY `idx_interview_id` (`interview_id`),
  KEY `idx_interview_results_company` (`company_id`),
  CONSTRAINT `fk_interview_results_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_result_interview` FOREIGN KEY (`interview_id`) REFERENCES `interviews` (`interview_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `interview_results_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`application_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `interview_results`
--

/*!40000 ALTER TABLE `interview_results` DISABLE KEYS */;
/*!40000 ALTER TABLE `interview_results` ENABLE KEYS */;

--
-- Table structure for table `interviews`
--

DROP TABLE IF EXISTS `interviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `interviews` (
  `interview_id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `interviewer_id` int(11) NOT NULL,
  `interview_date` date NOT NULL,
  `interview_time` time NOT NULL,
  `interview_type` enum('Face-to-Face','Online','Phone') NOT NULL,
  `meeting_link` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('Scheduled','Completed','Cancelled') DEFAULT 'Scheduled',
  `invitation_sent` enum('No','Yes') DEFAULT 'No',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`interview_id`),
  KEY `application_id` (`application_id`),
  KEY `job_id` (`job_id`),
  KEY `interviewer_id` (`interviewer_id`),
  KEY `idx_interviews_company` (`company_id`),
  CONSTRAINT `fk_interviews_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `interviews_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`application_id`) ON DELETE CASCADE,
  CONSTRAINT `interviews_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `job` (`job_id`),
  CONSTRAINT `interviews_ibfk_3` FOREIGN KEY (`interviewer_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `interviews`
--

/*!40000 ALTER TABLE `interviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `interviews` ENABLE KEYS */;

--
-- Table structure for table `inventory`
--

DROP TABLE IF EXISTS `inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory` (
  `inventory_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `purchase_cost` decimal(10,2) NOT NULL,
  `profit_markup` decimal(5,2) DEFAULT 20.00,
  `selling_price` decimal(10,2) NOT NULL,
  `reorder_level` int(11) DEFAULT 5,
  `purchase_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`inventory_id`),
  KEY `fk_inventory_product` (`product_id`),
  KEY `idx_inventory_company` (`company_id`),
  CONSTRAINT `fk_inventory_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13993 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory`
--

/*!40000 ALTER TABLE `inventory` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory` ENABLE KEYS */;

--
-- Table structure for table `job`
--

DROP TABLE IF EXISTS `job`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job` (
  `job_id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `job_title` varchar(100) NOT NULL,
  `department` varchar(100) NOT NULL,
  `vacancies` int(11) NOT NULL,
  `employment_type` enum('Full-time','Part-time','Contract','Temporary','Internship') NOT NULL,
  `applications` int(11) NOT NULL DEFAULT 0,
  `status` varchar(50) NOT NULL,
  `interviews` int(11) NOT NULL DEFAULT 0,
  `salary_min` decimal(10,2) DEFAULT NULL,
  `salary_max` decimal(10,2) DEFAULT NULL,
  `application_deadline` date DEFAULT NULL,
  `job_description` text DEFAULT NULL,
  `responsibilities` text DEFAULT NULL,
  `qualifications` text DEFAULT NULL,
  `created_at` date NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`job_id`),
  KEY `branch_id` (`branch_id`),
  KEY `idx_job_company` (`company_id`),
  CONSTRAINT `fk_job_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `job_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9990013 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job`
--

/*!40000 ALTER TABLE `job` DISABLE KEYS */;
/*!40000 ALTER TABLE `job` ENABLE KEYS */;

--
-- Table structure for table `landing_chat_messages`
--

DROP TABLE IF EXISTS `landing_chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `landing_chat_messages` (
  `message_id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` int(11) NOT NULL,
  `role` enum('visitor','assistant') NOT NULL,
  `body` text NOT NULL,
  `ai_model` varchar(60) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`message_id`),
  KEY `idx_landing_chat_message` (`chat_id`,`message_id`),
  CONSTRAINT `fk_landing_chat_message_chat` FOREIGN KEY (`chat_id`) REFERENCES `landing_chats` (`chat_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `landing_chat_messages`
--

/*!40000 ALTER TABLE `landing_chat_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `landing_chat_messages` ENABLE KEYS */;

--
-- Table structure for table `landing_chats`
--

DROP TABLE IF EXISTS `landing_chats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `landing_chats` (
  `chat_id` int(11) NOT NULL AUTO_INCREMENT,
  `session_token` char(40) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `visitor_ip` varchar(45) DEFAULT NULL,
  `message_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`chat_id`),
  UNIQUE KEY `uniq_landing_chat_token` (`session_token`),
  KEY `idx_landing_chat_lead` (`lead_id`),
  KEY `idx_landing_chat_ip` (`visitor_ip`,`created_at`),
  CONSTRAINT `fk_landing_chat_lead` FOREIGN KEY (`lead_id`) REFERENCES `marketing_leads` (`lead_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `landing_chats`
--

/*!40000 ALTER TABLE `landing_chats` DISABLE KEYS */;
/*!40000 ALTER TABLE `landing_chats` ENABLE KEYS */;

--
-- Table structure for table `leave_balances`
--

DROP TABLE IF EXISTS `leave_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_balances` (
  `balance_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) DEFAULT NULL,
  `vacation_leave` decimal(5,1) DEFAULT 12.0,
  `sick_leave` decimal(5,1) DEFAULT 8.0,
  `emergency_leave` decimal(5,1) DEFAULT 3.0,
  `maternity_leave` decimal(5,1) DEFAULT 45.0,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`balance_id`),
  KEY `idx_leave_balances_company` (`company_id`),
  CONSTRAINT `fk_leave_balances_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_balances`
--

/*!40000 ALTER TABLE `leave_balances` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_balances` ENABLE KEYS */;

--
-- Table structure for table `leave_requests`
--

DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_requests` (
  `leave_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type` varchar(100) DEFAULT NULL,
  `duration` varchar(50) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `hr_status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `admin_status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `hr_remarks` text DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL,
  `hr_approved_by` int(11) DEFAULT NULL,
  `admin_approved_by` int(11) DEFAULT NULL,
  `hr_approved_at` datetime DEFAULT NULL,
  `admin_approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`leave_id`),
  KEY `idx_leave_requests_company` (`company_id`),
  CONSTRAINT `fk_leave_requests_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=954 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_requests`
--

/*!40000 ALTER TABLE `leave_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_requests` ENABLE KEYS */;

--
-- Table structure for table `marketing_leads`
--

DROP TABLE IF EXISTS `marketing_leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `marketing_leads` (
  `lead_id` int(11) NOT NULL AUTO_INCREMENT,
  `business_name` varchar(190) NOT NULL,
  `contact_name` varchar(150) NOT NULL,
  `contact_email` varchar(150) NOT NULL,
  `contact_phone` varchar(60) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `branches` int(11) NOT NULL DEFAULT 1,
  `source` enum('Website','Referral','Walk-in','Phone','Event','Social Media','Other') NOT NULL DEFAULT 'Website',
  `interest` enum('Retail Starter','Retail Professional','Retail Enterprise','Not Sure') NOT NULL DEFAULT 'Not Sure',
  `stage` enum('New','Contacted','Demo Booked','Proposal Sent','Won','Lost') NOT NULL DEFAULT 'New',
  `owner_id` int(11) DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  `lost_reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `next_action` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`lead_id`),
  KEY `stage` (`stage`),
  KEY `owner_id` (`owner_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `marketing_leads`
--

/*!40000 ALTER TABLE `marketing_leads` DISABLE KEYS */;
/*!40000 ALTER TABLE `marketing_leads` ENABLE KEYS */;

--
-- Table structure for table `marketplace_apps`
--

DROP TABLE IF EXISTS `marketplace_apps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `marketplace_apps` (
  `app_id` int(11) NOT NULL AUTO_INCREMENT,
  `app_order` int(11) NOT NULL DEFAULT 1,
  `app_name` varchar(150) NOT NULL,
  `category` varchar(80) NOT NULL DEFAULT 'Operations',
  `short_description` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(100) NOT NULL DEFAULT 'bi bi-puzzle',
  `icon_bg` varchar(60) NOT NULL DEFAULT 'bg-sky-100',
  `icon_color` varchar(60) NOT NULL DEFAULT 'text-sky-600',
  `price_per_branch` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` enum('Monthly','Yearly','One-time') NOT NULL DEFAULT 'Monthly',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Inactive',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`app_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `marketplace_apps`
--

/*!40000 ALTER TABLE `marketplace_apps` DISABLE KEYS */;
INSERT INTO `marketplace_apps` VALUES (1,1,'Advanced Analytics','Insights','Forecasting and trend reports on top of the standard dashboards.',NULL,'bi bi-graph-up-arrow','bg-sky-100','text-sky-600',0.00,'Monthly','Inactive','2026-09-30 21:45:34','2026-09-30 21:45:34'),(2,2,'Customer Loyalty','Sales','Points, tiers and reward redemption at the point of sale.',NULL,'bi bi-award','bg-amber-100','text-amber-600',0.00,'Monthly','Inactive','2026-09-30 21:45:34','2026-09-30 21:45:34'),(4,4,'E-Commerce Sync','Sales','Keep an online storefront in step with branch inventory.',NULL,'bi bi-bag-check','bg-violet-100','text-violet-600',0.00,'Monthly','Inactive','2026-09-30 21:45:34','2026-09-30 21:45:34');
/*!40000 ALTER TABLE `marketplace_apps` ENABLE KEYS */;

--
-- Table structure for table `media_files`
--

DROP TABLE IF EXISTS `media_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `media_files` (
  `media_id` int(11) NOT NULL AUTO_INCREMENT,
  `file_name` varchar(190) NOT NULL,
  `original_name` varchar(190) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `category` varchar(60) NOT NULL DEFAULT 'General',
  `caption` varchar(255) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`media_id`),
  UNIQUE KEY `file_name` (`file_name`),
  KEY `category` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `media_files`
--

/*!40000 ALTER TABLE `media_files` DISABLE KEYS */;
INSERT INTO `media_files` VALUES (2,'general-12f9f8438fbc20fc.jpg','35f1f977-5b73-4368-a593-f36574237997.jpg','image/jpeg',107050,'Logos','jajajajjaja',21,'2026-10-01 12:05:58');
/*!40000 ALTER TABLE `media_files` ENABLE KEYS */;

--
-- Table structure for table `overtime_requests`
--

DROP TABLE IF EXISTS `overtime_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `overtime_requests` (
  `overtime_id` int(11) NOT NULL AUTO_INCREMENT,
  `attendance_id` int(11) DEFAULT NULL,
  `employee_id` int(11) NOT NULL,
  `requested_hours` decimal(4,2) DEFAULT NULL,
  `approved_hours` decimal(4,2) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`overtime_id`),
  KEY `employee_id` (`employee_id`),
  KEY `idx_overtime_requests_company` (`company_id`),
  CONSTRAINT `fk_overtime_requests_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `overtime_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `overtime_requests`
--

/*!40000 ALTER TABLE `overtime_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `overtime_requests` ENABLE KEYS */;

--
-- Table structure for table `paymongo_sessions`
--

DROP TABLE IF EXISTS `paymongo_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paymongo_sessions` (
  `token` varchar(64) NOT NULL,
  `session_id` varchar(64) DEFAULT NULL,
  `checkout_url` text DEFAULT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`token`),
  KEY `idx_paymongo_sessions_company` (`company_id`),
  CONSTRAINT `fk_paymongo_sessions_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `paymongo_sessions`
--

/*!40000 ALTER TABLE `paymongo_sessions` DISABLE KEYS */;
INSERT INTO `paymongo_sessions` VALUES ('17f1fb094ec5a83533887957522ce3e94b1c972b','cs_0e2efee9d12ea31df7135c35','https://checkout.paymongo.com/0e2efee9d12ea31df7135c35','2026-10-03 14:22:32','2026-10-03 14:22:17',5946);
/*!40000 ALTER TABLE `paymongo_sessions` ENABLE KEYS */;

--
-- Table structure for table `payroll`
--

DROP TABLE IF EXISTS `payroll`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll` (
  `payroll_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `payroll_period_start` date NOT NULL,
  `payroll_period_end` date NOT NULL,
  `working_days` int(11) DEFAULT 0,
  `basic_pay` decimal(10,2) DEFAULT 0.00,
  `overtime_pay` decimal(10,2) DEFAULT 0.00,
  `gross_pay` decimal(10,2) DEFAULT 0.00,
  `late_deduction` decimal(10,2) DEFAULT 0.00,
  `undertime_deduction` decimal(10,2) DEFAULT 0.00,
  `absent_deduction` decimal(10,2) DEFAULT 0.00,
  `sss` decimal(10,2) DEFAULT 0.00,
  `philhealth` decimal(10,2) DEFAULT 0.00,
  `pagibig` decimal(10,2) DEFAULT 0.00,
  `total_deduction` decimal(10,2) DEFAULT 0.00,
  `net_pay` decimal(10,2) DEFAULT 0.00,
  `status` enum('Draft','Pending Approval','Approved','Returned','Released') DEFAULT 'Draft',
  `is_edited` tinyint(1) DEFAULT 0,
  `edit_reason` text DEFAULT NULL,
  `edited_by` int(11) DEFAULT NULL,
  `edited_at` datetime DEFAULT NULL,
  `prepared_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`payroll_id`),
  KEY `employee_id` (`employee_id`),
  KEY `prepared_by` (`prepared_by`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_payroll_company` (`company_id`),
  CONSTRAINT `fk_payroll_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `payroll_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `payroll_ibfk_2` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `payroll_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=459 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll`
--

/*!40000 ALTER TABLE `payroll` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll` ENABLE KEYS */;

--
-- Table structure for table `platform_employee_contracts`
--

DROP TABLE IF EXISTS `platform_employee_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `platform_employee_contracts` (
  `contract_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `contract_number` varchar(30) NOT NULL,
  `contract_title` varchar(150) NOT NULL,
  `company_contract` varchar(255) DEFAULT NULL,
  `signed_contract` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Sent','Signed') NOT NULL DEFAULT 'Pending',
  `hr_review` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `hr_remarks` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`contract_id`),
  UNIQUE KEY `uniq_platform_contract_number` (`contract_number`),
  KEY `idx_platform_contract_employee` (`employee_id`),
  CONSTRAINT `fk_platform_contract_employee` FOREIGN KEY (`employee_id`) REFERENCES `platform_employees` (`employee_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_employee_contracts`
--

/*!40000 ALTER TABLE `platform_employee_contracts` DISABLE KEYS */;
/*!40000 ALTER TABLE `platform_employee_contracts` ENABLE KEYS */;

--
-- Table structure for table `platform_employees`
--

DROP TABLE IF EXISTS `platform_employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `platform_employees` (
  `employee_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_code` varchar(20) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `first_name` varchar(40) DEFAULT NULL,
  `middle_name` varchar(40) DEFAULT NULL,
  `last_name` varchar(40) DEFAULT NULL,
  `work_email` varchar(150) NOT NULL,
  `contact_number` varchar(60) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('Female','Male','Prefer not to say') DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `department` enum('Engineering','Marketing','Sales','Support','Finance','People','Operations') NOT NULL DEFAULT 'Operations',
  `position` varchar(120) NOT NULL,
  `employment_type` enum('Full-time','Part-time','Contract','Intern') NOT NULL DEFAULT 'Full-time',
  `salary` decimal(12,2) DEFAULT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `status` enum('Active','On Leave','Resigned','Terminated') NOT NULL DEFAULT 'Active',
  `date_hired` date NOT NULL,
  `date_left` date DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `employee_code` (`employee_code`),
  KEY `department` (`department`),
  KEY `status` (`status`),
  KEY `fk_platform_employee_supervisor` (`supervisor_id`),
  CONSTRAINT `fk_platform_employee_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `platform_employees` (`employee_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_employees`
--

/*!40000 ALTER TABLE `platform_employees` DISABLE KEYS */;
/*!40000 ALTER TABLE `platform_employees` ENABLE KEYS */;

--
-- Table structure for table `platform_notifications`
--

DROP TABLE IF EXISTS `platform_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `platform_notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `audience` enum('All Companies','Single Company','By Plan') NOT NULL DEFAULT 'All Companies',
  `company_id` int(11) DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `severity` enum('Info','Warning','Critical') NOT NULL DEFAULT 'Info',
  `status` enum('Draft','Published','Archived') NOT NULL DEFAULT 'Draft',
  `publish_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `status` (`status`),
  KEY `audience` (`audience`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_notifications`
--

/*!40000 ALTER TABLE `platform_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `platform_notifications` ENABLE KEYS */;

--
-- Table structure for table `platform_settings`
--

DROP TABLE IF EXISTS `platform_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `platform_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `platform_name` varchar(120) NOT NULL DEFAULT 'RetailCore',
  `support_email` varchar(150) NOT NULL DEFAULT 'hello@retailcore.ph',
  `support_phone` varchar(60) NOT NULL DEFAULT '',
  `registration_open` tinyint(1) NOT NULL DEFAULT 1,
  `maintenance_mode` tinyint(1) NOT NULL DEFAULT 0,
  `maintenance_message` text DEFAULT NULL,
  `default_plan_id` int(11) DEFAULT NULL,
  `trial_days` int(11) NOT NULL DEFAULT 14,
  `contract_template` varchar(255) DEFAULT NULL,
  `contract_template_name` varchar(190) DEFAULT NULL,
  `records_per_page` int(11) NOT NULL DEFAULT 25,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_settings`
--

/*!40000 ALTER TABLE `platform_settings` DISABLE KEYS */;
INSERT INTO `platform_settings` VALUES (1,'RetailCore','hello@retailcore.ph','+63 2 8123 4567',1,0,'We are carrying out scheduled maintenance. Please try again shortly.',NULL,14,'uploads/company_contracts/template_97ebdb2681d76599.pdf','RetailCore_Service_Agreement.pdf',25,'2026-10-03 05:55:54');
/*!40000 ALTER TABLE `platform_settings` ENABLE KEYS */;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `product_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(150) NOT NULL,
  `category_id` int(11) NOT NULL,
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`product_id`),
  KEY `fk_product_category` (`category_id`),
  KEY `fk_products_supplier` (`supplier_id`),
  KEY `idx_products_company` (`company_id`),
  CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_products_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_products_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=99900107 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

/*!40000 ALTER TABLE `products` DISABLE KEYS */;
/*!40000 ALTER TABLE `products` ENABLE KEYS */;

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_items` (
  `sale_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`sale_item_id`),
  KEY `fk_saleitems_sale` (`sale_id`),
  KEY `fk_saleitems_product` (`product_id`),
  KEY `idx_sale_items_company` (`company_id`),
  CONSTRAINT `fk_sale_items_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_saleitems_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_saleitems_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1229 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_items`
--

/*!40000 ALTER TABLE `sale_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_items` ENABLE KEYS */;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `sale_id` int(11) NOT NULL AUTO_INCREMENT,
  `total_amount` decimal(10,2) NOT NULL,
  `tax_amount` decimal(10,2) NOT NULL,
  `cash_received` decimal(10,2) NOT NULL,
  `change_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('Cash','GCash') NOT NULL DEFAULT 'Cash',
  `payment_reference` varchar(100) DEFAULT NULL,
  `sale_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`sale_id`),
  KEY `idx_sales_company` (`company_id`),
  KEY `fk_sales_created_by` (`created_by`),
  CONSTRAINT `fk_sales_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sales_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=999001904 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales`
--

/*!40000 ALTER TABLE `sales` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales` ENABLE KEYS */;

--
-- Table structure for table `stock_request_items`
--

DROP TABLE IF EXISTS `stock_request_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_request_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `item_description` varchar(255) DEFAULT NULL,
  `vendor` varchar(150) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `profit_markup` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_stock_request_items_company` (`company_id`),
  CONSTRAINT `fk_sri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `fk_sri_request` FOREIGN KEY (`request_id`) REFERENCES `stock_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_request_items_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1856 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_request_items`
--

/*!40000 ALTER TABLE `stock_request_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_request_items` ENABLE KEYS */;

--
-- Table structure for table `stock_requests`
--

DROP TABLE IF EXISTS `stock_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_code` varchar(20) NOT NULL,
  `total_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(500) NOT NULL,
  `expense_category` varchar(100) NOT NULL DEFAULT 'Restocking',
  `category_other` varchar(150) DEFAULT NULL,
  `payment_type` enum('Capital','Payable') NOT NULL DEFAULT 'Capital',
  `status` enum('Pending Finance','Finance Approved','Finance Rejected','Pending Admin','Admin Approved','Admin Rejected','Received','Cancelled') NOT NULL DEFAULT 'Pending Finance',
  `finance_approved_by` int(11) DEFAULT NULL,
  `finance_approved_at` datetime DEFAULT NULL,
  `finance_remarks` varchar(500) DEFAULT NULL,
  `admin_approved_by` int(11) DEFAULT NULL,
  `admin_approved_at` datetime DEFAULT NULL,
  `admin_remarks` varchar(500) DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `uq_request_code_per_company` (`company_id`,`request_code`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_stock_requests_company` (`company_id`),
  CONSTRAINT `fk_stock_requests_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=99900105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_requests`
--

/*!40000 ALTER TABLE `stock_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_requests` ENABLE KEYS */;

--
-- Table structure for table `subscription_history`
--

DROP TABLE IF EXISTS `subscription_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `subscription_id` int(11) NOT NULL,
  `action` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `subscription_id` (`subscription_id`),
  CONSTRAINT `subscription_history_ibfk_1` FOREIGN KEY (`subscription_id`) REFERENCES `company_subscriptions` (`subscription_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_history`
--

/*!40000 ALTER TABLE `subscription_history` DISABLE KEYS */;
INSERT INTO `subscription_history` VALUES (42,5927,'Plan Selected','Retail Professional (Monthly) chosen by . Awaiting payment.',NULL,'2026-10-03 05:40:58'),(43,5927,'Plan Selected','Retail Professional (Monthly) chosen by . Awaiting payment.',NULL,'2026-10-03 05:56:37'),(44,5927,'Plan Selected','Retail Professional (Monthly) chosen by . Awaiting payment.',NULL,'2026-10-03 06:07:06'),(45,5927,'Activated','Payment confirmed by PayMongo (session cs_0e2efee9d12ea31df7135c35).',NULL,'2026-10-03 06:22:32');
/*!40000 ALTER TABLE `subscription_history` ENABLE KEYS */;

--
-- Table structure for table `subscription_plan_features`
--

DROP TABLE IF EXISTS `subscription_plan_features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_plan_features` (
  `feature_id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `feature_name` varchar(150) NOT NULL,
  `system_module` varchar(40) DEFAULT NULL,
  `feature_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`feature_id`),
  KEY `plan_id` (`plan_id`),
  KEY `idx_plan_features_system_module` (`system_module`),
  CONSTRAINT `subscription_plan_features_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`plan_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=145 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_plan_features`
--

/*!40000 ALTER TABLE `subscription_plan_features` DISABLE KEYS */;
INSERT INTO `subscription_plan_features` VALUES (82,1,'1 Branch',NULL,1),(83,1,'Up to 10 Users',NULL,2),(84,1,'Point of Sale',NULL,3),(85,1,'Inventory Management',NULL,4),(86,1,'Product Management',NULL,5),(87,1,'Staff Management',NULL,6),(88,1,'Promotions',NULL,7),(89,1,'Basic Reports',NULL,8),(90,1,'Supplier Management',NULL,9),(91,1,'Sales Tracking',NULL,10),(92,1,'Low Stock Alerts',NULL,11),(93,1,'Email Support',NULL,12),(111,3,'Unlimited Branches',NULL,1),(112,3,'Unlimited Users',NULL,2),(113,3,'Unlimited POS Terminals',NULL,3),(114,3,'Centralized Company Management',NULL,4),(115,3,'Advanced Analytics',NULL,5),(116,3,'Consolidated Financial Reports',NULL,6),(117,3,'Advanced HR & Payroll',NULL,7),(118,3,'Recruitment Management','hiring',8),(119,3,'Custom Approval Workflows',NULL,9),(120,3,'Advanced Inventory Controls',NULL,10),(121,3,'API Access',NULL,11),(122,3,'Custom Integrations',NULL,12),(123,3,'Dedicated Account Manager',NULL,13),(124,3,'Priority Support',NULL,14),(125,3,'Custom Branding',NULL,15),(126,3,'Multi-Company Support',NULL,16),(127,2,'Up to 10 Branches',NULL,0),(128,2,'Unlimited Store Users',NULL,0),(129,2,'Multi-Branch Management',NULL,0),(130,2,'Centralized Inventory',NULL,0),(131,2,'Branch-to-Branch Transfers',NULL,0),(132,2,'HR Management',NULL,0),(133,2,'Face Recognition Attendance',NULL,0),(134,2,'Time & Request Management',NULL,0),(135,2,'Payroll Management',NULL,0),(136,2,'Leave Management',NULL,0),(137,2,'Recruitment / Job Posting','hiring',0),(138,2,'Finance Management','finance_approval',0),(139,2,'Advanced Reports',NULL,0),(140,2,'Business Analytics',NULL,0),(141,2,'Advanced Promotions',NULL,0),(142,2,'Supplier Management',NULL,0),(143,2,'Priority Support',NULL,0);
/*!40000 ALTER TABLE `subscription_plan_features` ENABLE KEYS */;

--
-- Table structure for table `subscription_plan_roles`
--

DROP TABLE IF EXISTS `subscription_plan_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_plan_roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `role_order` int(11) NOT NULL,
  `role_name` varchar(100) NOT NULL,
  `system_role` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`role_id`),
  KEY `plan_id` (`plan_id`),
  KEY `idx_plan_roles_system_role` (`system_role`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_plan_roles`
--

/*!40000 ALTER TABLE `subscription_plan_roles` DISABLE KEYS */;
INSERT INTO `subscription_plan_roles` VALUES (9,1,1,'Owner / Admin','admin'),(10,1,2,'Cashier','cashier'),(11,1,3,'Inventory Staff','inventory'),(12,2,1,'Owner / Admin','admin'),(13,2,2,'HR Officer','hr'),(14,2,3,'Inventory Staff','inventory'),(15,2,4,'Finance Staff','finance'),(16,2,5,'Cashier','cashier');
/*!40000 ALTER TABLE `subscription_plan_roles` ENABLE KEYS */;

--
-- Table structure for table `subscription_plans`
--

DROP TABLE IF EXISTS `subscription_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_plans` (
  `plan_id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_name` varchar(100) NOT NULL,
  `tagline` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `inherits_text` varchar(150) DEFAULT NULL,
  `monthly_price` decimal(10,2) NOT NULL,
  `yearly_price` decimal(10,2) DEFAULT NULL,
  `price_label` varchar(60) DEFAULT NULL,
  `max_branches` int(11) DEFAULT 1,
  `max_users` int(11) DEFAULT 10,
  `business_size` enum('Micro','Small','Medium','Large') DEFAULT NULL,
  `storage_limit` varchar(30) DEFAULT '10 GB',
  `trial_days` int(11) DEFAULT 30,
  `badge` enum('None','Most Popular','Recommended','Best Value') DEFAULT 'None',
  `button_text` varchar(50) DEFAULT 'Start Free Trial',
  `button_color` varchar(20) DEFAULT '#0d6efd',
  `status` enum('Active','Inactive','Archived') DEFAULT 'Active',
  `plan_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`plan_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_plans`
--

/*!40000 ALTER TABLE `subscription_plans` DISABLE KEYS */;
INSERT INTO `subscription_plans` VALUES (1,'Retail Starter','For Small Convenience Stores','For independent convenience stores ready to move beyond manual operations.',NULL,2499.00,24990.00,NULL,1,10,'Small','10 GB',0,'None','Start Free Trial','#0d6efd','Active',1,'2026-08-03 16:32:43'),(2,'Retail Professional','For Growing Convenience Stores','For growing retailers managing multiple branches, employees, and daily operations.','Includes everything in Starter PLUS:',8999.00,89990.00,NULL,10,9999,'Large','100 GB',0,'Most Popular','Start Free Trial','#0d6efd','Active',2,'2026-08-03 16:32:43'),(3,'Retail Enterprise','For Large Retail Networks & Franchises','For businesses managing large-scale retail operations across multiple locations.','Includes everything in Professional PLUS:',0.00,0.00,'Custom Pricing',999999,999999,'Large','Unlimited',0,'None','Talk to Sales','#ff8a2b','Active',3,'2026-08-03 16:32:43');
/*!40000 ALTER TABLE `subscription_plans` ENABLE KEYS */;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `supplier_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(150) NOT NULL,
  `contact_email` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`supplier_id`),
  UNIQUE KEY `unique_supplier_per_company` (`company_id`,`supplier_name`),
  KEY `idx_suppliers_company` (`company_id`),
  CONSTRAINT `fk_suppliers_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9990013 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;

--
-- Table structure for table `support_ticket_replies`
--

DROP TABLE IF EXISTS `support_ticket_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_ticket_replies` (
  `reply_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `author_id` int(11) DEFAULT NULL,
  `author_name` varchar(150) NOT NULL,
  `body` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reply_id`),
  KEY `ticket_id` (`ticket_id`),
  CONSTRAINT `support_ticket_replies_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_ticket_replies`
--

/*!40000 ALTER TABLE `support_ticket_replies` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_ticket_replies` ENABLE KEYS */;

--
-- Table structure for table `support_tickets`
--

DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_tickets` (
  `ticket_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_code` varchar(20) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `contact_name` varchar(150) NOT NULL,
  `contact_email` varchar(150) NOT NULL,
  `subject` varchar(190) NOT NULL,
  `message` text NOT NULL,
  `category` enum('Billing','Technical','Account','Feature Request','Other') NOT NULL DEFAULT 'Other',
  `priority` enum('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
  `status` enum('Open','In Progress','Waiting on Customer','Resolved','Closed') NOT NULL DEFAULT 'Open',
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  UNIQUE KEY `ticket_code` (`ticket_code`),
  KEY `company_id` (`company_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_tickets`
--

/*!40000 ALTER TABLE `support_tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_tickets` ENABLE KEYS */;

--
-- Table structure for table `tax`
--

DROP TABLE IF EXISTS `tax`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tax` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tax_rate` decimal(5,2) NOT NULL,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tax_company` (`company_id`),
  CONSTRAINT `fk_tax_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tax`
--

/*!40000 ALTER TABLE `tax` DISABLE KEYS */;
INSERT INTO `tax` VALUES (12,0.00,5946);
/*!40000 ALTER TABLE `tax` ENABLE KEYS */;

--
-- Table structure for table `tax_history`
--

DROP TABLE IF EXISTS `tax_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tax_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `old_rate` decimal(5,2) DEFAULT NULL,
  `new_rate` decimal(5,2) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `idx_tax_history_company` (`company_id`),
  CONSTRAINT `fk_tax_history_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tax_history`
--

/*!40000 ALTER TABLE `tax_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `tax_history` ENABLE KEYS */;

--
-- Table structure for table `undertime_requests`
--

DROP TABLE IF EXISTS `undertime_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `undertime_requests` (
  `undertime_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `request_date` date NOT NULL,
  `hours` decimal(4,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`undertime_id`),
  KEY `employee_id` (`employee_id`),
  KEY `idx_undertime_requests_company` (`company_id`),
  CONSTRAINT `fk_undertime_requests_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `undertime_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `undertime_requests`
--

/*!40000 ALTER TABLE `undertime_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `undertime_requests` ENABLE KEYS */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `fullname` varchar(100) NOT NULL,
  `first_name` varchar(40) DEFAULT NULL,
  `middle_name` varchar(40) DEFAULT NULL,
  `last_name` varchar(40) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `contact` varchar(15) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL,
  `join_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(100) DEFAULT 'active',
  `notifications_seen_at` datetime DEFAULT NULL,
  `deactivation_reason` text DEFAULT NULL,
  `deactivated_at` datetime DEFAULT NULL,
  `reset_token_hash` varchar(64) DEFAULT NULL,
  `reset_token_expires_at` datetime DEFAULT NULL,
  `verification_token` varchar(64) DEFAULT NULL,
  `verification_expires_at` datetime DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_users_company` (`company_id`),
  KEY `idx_users_verification` (`verification_token`)
) ENGINE=InnoDB AUTO_INCREMENT=999110 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (21,NULL,NULL,'superadmin','Super Admin',NULL,NULL,NULL,'superadmin@gmail.com','09123456789','$2y$10$4UEdgf6XFjRbiL4MRnF3VuuwFF0gHcV/1W89/l1wo46KPFiGJI3yi','Super Admin','2026-09-03 06:23:55','active','2026-10-01 22:08:25',NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-03 14:23:55'),(789,5946,NULL,'cleintsalarda02@gmail.com','cleint mesias salarda','cleint','mesias','salarda','cleintsalarda02@gmail.com','09123456789','$2y$10$rgUWqVlFLdcDyKQOl84noeykKEqoAttl8qiWExw2FP9CKG01OXPkO','admin','2026-10-03 05:38:23','active',NULL,NULL,NULL,'8447d62101d31a6d129a697ae316bdf815da77c36440b13433c51ab6181b6280','2026-10-04 14:16:42',NULL,NULL,'2026-10-03 13:39:54'),(999107,5946,99900108,'cleint111','cleint',NULL,NULL,NULL,'cleintraymund@gmail.com','09123456789','$2y$10$d.yxLH6sPREdnxm9bJXK2OAAAaUIxaxqn7Fv/kPlnINPXmSe4GjRK','finance','2026-10-03 07:15:52','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(999108,5946,99900109,'cleint222','cleint',NULL,NULL,NULL,'cleintraymundsalarda@gmail.com','09123456789','$2y$10$fRQud5FdnQIsWFfrtrj.WOArKSn71P3I2WKayHBGT/MC4QyuqMGdm','cashier','2026-10-03 07:16:44','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(999109,5946,NULL,'dassss','Daizy Magpantay',NULL,NULL,NULL,'magpantaydaizy3@gmail.com','09123456789','$2y$10$PNhj2srsRv4BMejLC1U8au4Vx1Sqjl4NRUrtK6zkZ7kccMfgiktYG','hr','2026-10-03 07:17:07','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;

--
-- Table structure for table `utang`
--

DROP TABLE IF EXISTS `utang`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `utang` (
  `utang_id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) NOT NULL,
  `contact` varchar(30) DEFAULT NULL,
  `id_type` varchar(50) DEFAULT NULL,
  `id_number` varchar(100) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) NOT NULL,
  `date_created` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('Unpaid','Partial','Paid','Overdue') DEFAULT 'Unpaid',
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`utang_id`),
  KEY `fk_utang_sale` (`sale_id`),
  KEY `idx_utang_company` (`company_id`),
  CONSTRAINT `fk_utang_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_utang_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `utang`
--

/*!40000 ALTER TABLE `utang` DISABLE KEYS */;
/*!40000 ALTER TABLE `utang` ENABLE KEYS */;

--
-- Table structure for table `utang_payments`
--

DROP TABLE IF EXISTS `utang_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `utang_payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `utang_id` int(11) NOT NULL,
  `payment_amount` decimal(10,2) NOT NULL,
  `payment_date` datetime DEFAULT current_timestamp(),
  `remarks` varchar(255) DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  PRIMARY KEY (`payment_id`),
  KEY `fk_payment_utang` (`utang_id`),
  KEY `idx_utang_payments_company` (`company_id`),
  CONSTRAINT `fk_payment_utang` FOREIGN KEY (`utang_id`) REFERENCES `utang` (`utang_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_utang_payments_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`company_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `utang_payments`
--

/*!40000 ALTER TABLE `utang_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `utang_payments` ENABLE KEYS */;

--
-- Table structure for table `website_careers_section`
--

DROP TABLE IF EXISTS `website_careers_section`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_careers_section` (
  `section_id` int(11) NOT NULL AUTO_INCREMENT,
  `hero_badge` varchar(120) NOT NULL,
  `hero_title` varchar(255) NOT NULL,
  `hero_description` text NOT NULL,
  `jobs_badge` varchar(120) NOT NULL,
  `jobs_title` varchar(255) NOT NULL,
  `jobs_description` text NOT NULL,
  `empty_title` varchar(255) NOT NULL,
  `empty_description` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_careers_section`
--

/*!40000 ALTER TABLE `website_careers_section` DISABLE KEYS */;
INSERT INTO `website_careers_section` VALUES (1,'CAREERS','Software for people who work a counter, not a keyboard','We build for cashiers, stock clerks, HR officers and store owners. If that sounds like work worth doing, come build it with us.','OPEN JOB','Open Positions','Explore our current job openings and find the right opportunity for you.','No Open Positions','There are currently no available job openings. Please check again later.','2026-09-30 15:18:03');
/*!40000 ALTER TABLE `website_careers_section` ENABLE KEYS */;

--
-- Table structure for table `website_footer`
--

DROP TABLE IF EXISTS `website_footer`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_footer` (
  `footer_id` int(11) NOT NULL AUTO_INCREMENT,
  `cta_badge` varchar(120) NOT NULL,
  `cta_title` varchar(255) NOT NULL,
  `cta_title_highlight` varchar(255) NOT NULL,
  `cta_description` text NOT NULL,
  `cta_button_text` varchar(60) NOT NULL,
  `cta_button_link` varchar(255) NOT NULL,
  `brand_name` varchar(120) NOT NULL,
  `brand_description` text NOT NULL,
  `contact_email` varchar(150) NOT NULL,
  `contact_phone` varchar(60) NOT NULL,
  `copyright_text` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`footer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_footer`
--

/*!40000 ALTER TABLE `website_footer` DISABLE KEYS */;
INSERT INTO `website_footer` VALUES (1,'Ready to Get Started?','Transform your retail business','with RetailCore today.','Join hundreds of retailers using RetailCore to simplify operations, improve inventory accuracy, manage employees, and increase profitability.','Get Started','pricing.php','RetailCore','Cloud-based enterprise retail management for convenience chains, mini marts, groceries, supermarkets and wholesalers.','hello@retailcore.ph','+63 2 8123 4567','Copyright {year} RetailCore Retail OS. All rights reserved.','2026-09-30 16:09:54');
/*!40000 ALTER TABLE `website_footer` ENABLE KEYS */;

--
-- Table structure for table `website_footer_columns`
--

DROP TABLE IF EXISTS `website_footer_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_footer_columns` (
  `column_id` int(11) NOT NULL AUTO_INCREMENT,
  `column_order` int(11) NOT NULL DEFAULT 1,
  `heading` varchar(120) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`column_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_footer_columns`
--

/*!40000 ALTER TABLE `website_footer_columns` DISABLE KEYS */;
INSERT INTO `website_footer_columns` VALUES (1,1,'Platform','Active'),(2,2,'Solutions','Active'),(3,3,'Company','Active'),(4,4,'Support','Active');
/*!40000 ALTER TABLE `website_footer_columns` ENABLE KEYS */;

--
-- Table structure for table `website_footer_legal_links`
--

DROP TABLE IF EXISTS `website_footer_legal_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_footer_legal_links` (
  `legal_id` int(11) NOT NULL AUTO_INCREMENT,
  `link_order` int(11) NOT NULL DEFAULT 1,
  `label` varchar(120) NOT NULL,
  `url` varchar(255) NOT NULL DEFAULT '#',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`legal_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_footer_legal_links`
--

/*!40000 ALTER TABLE `website_footer_legal_links` DISABLE KEYS */;
INSERT INTO `website_footer_legal_links` VALUES (1,1,'Privacy Policy','#','Active'),(2,2,'Terms of Service','#','Active'),(3,3,'Data Processing','#','Active');
/*!40000 ALTER TABLE `website_footer_legal_links` ENABLE KEYS */;

--
-- Table structure for table `website_footer_links`
--

DROP TABLE IF EXISTS `website_footer_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_footer_links` (
  `link_id` int(11) NOT NULL AUTO_INCREMENT,
  `column_id` int(11) NOT NULL,
  `link_order` int(11) NOT NULL DEFAULT 1,
  `label` varchar(120) NOT NULL,
  `url` varchar(255) NOT NULL DEFAULT '#',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`link_id`),
  KEY `column_id` (`column_id`),
  CONSTRAINT `website_footer_links_column` FOREIGN KEY (`column_id`) REFERENCES `website_footer_columns` (`column_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_footer_links`
--

/*!40000 ALTER TABLE `website_footer_links` DISABLE KEYS */;
INSERT INTO `website_footer_links` VALUES (1,1,1,'Platform Overview','platform.php','Active'),(2,1,2,'Features','features.php','Active'),(3,1,3,'Marketplace','marketPlace.php','Active'),(4,1,4,'Pricing','pricing.php','Active'),(5,2,1,'Convenience Stores','marketPlace.php','Active'),(6,2,2,'Mini Mart Chains','marketPlace.php','Active'),(7,2,3,'Supermarkets','marketPlace.php','Active'),(8,2,4,'Wholesale Retail','marketPlace.php','Active'),(9,3,1,'About Us','aboutUs.php','Active'),(10,3,2,'Careers','careers.php','Active'),(11,3,3,'Resources','#','Active'),(12,3,4,'Contact Us','contactUs.php','Active'),(13,4,1,'Support Center','contactUs.php','Active'),(14,4,2,'Book a Demo','bookDemo.php','Active'),(15,4,3,'Client Login','#','Active'),(16,4,4,'System Status','#','Active');
/*!40000 ALTER TABLE `website_footer_links` ENABLE KEYS */;

--
-- Table structure for table `website_hero`
--

DROP TABLE IF EXISTS `website_hero`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_hero` (
  `hero_id` int(11) NOT NULL AUTO_INCREMENT,
  `badge` varchar(150) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `primary_btn_text` varchar(60) NOT NULL DEFAULT 'Start Free Trial',
  `primary_btn_link` varchar(255) NOT NULL DEFAULT 'pricing.php',
  `secondary_btn_text` varchar(60) NOT NULL DEFAULT 'Book a Demo',
  `secondary_btn_link` varchar(255) NOT NULL DEFAULT 'bookDemo.php',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`hero_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_hero`
--

/*!40000 ALTER TABLE `website_hero` DISABLE KEYS */;
INSERT INTO `website_hero` VALUES (1,'INTEGRATED RETAIL OPERATIONS PLATFORM','Run your entire retail business from one platform.','Manage sales, inventory, branches, employees, payroll, finance, and daily operations - all in one connected retail platform.','Start Free Trial','pricing.php','Book a Demo','bookDemo.php','2026-09-30 23:47:41');
/*!40000 ALTER TABLE `website_hero` ENABLE KEYS */;

--
-- Table structure for table `website_hero_badges`
--

DROP TABLE IF EXISTS `website_hero_badges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_hero_badges` (
  `badge_id` int(11) NOT NULL AUTO_INCREMENT,
  `badge_order` int(11) NOT NULL,
  `icon` varchar(100) NOT NULL,
  `label` varchar(120) NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`badge_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_hero_badges`
--

/*!40000 ALTER TABLE `website_hero_badges` DISABLE KEYS */;
INSERT INTO `website_hero_badges` VALUES (5,1,'bi bi-calendar-check','14-Day Free Trial','Active'),(6,2,'bi bi-grid-1x2','All-in-One Retail Platform','Active'),(7,3,'bi bi-diagram-3','Multi-Branch Ready','Active'),(8,4,'bi bi-clock-history','24/7 System Access','Active');
/*!40000 ALTER TABLE `website_hero_badges` ENABLE KEYS */;

--
-- Table structure for table `website_included_cards`
--

DROP TABLE IF EXISTS `website_included_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_included_cards` (
  `card_id` int(11) NOT NULL AUTO_INCREMENT,
  `card_order` int(11) NOT NULL,
  `icon` varchar(100) NOT NULL,
  `icon_bg` varchar(60) NOT NULL DEFAULT 'bg-sky-100',
  `icon_color` varchar(60) NOT NULL DEFAULT 'text-sky-600',
  `title` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`card_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_included_cards`
--

/*!40000 ALTER TABLE `website_included_cards` DISABLE KEYS */;
INSERT INTO `website_included_cards` VALUES (1,1,'bi bi-cart-check','bg-blue-100','text-blue-600','Point of Sale','Process transactions quickly across every branch.','Active'),(2,2,'bi bi-box-seam','bg-yellow-100','text-yellow-600','Inventory Management','Keep track of your products across every location.','Active'),(3,3,'bi bi-person-badge','bg-purple-100','text-purple-600','Staff Management','Manage employees and access across your branches.','Active'),(4,4,'bi bi-people','bg-indigo-100','text-indigo-600','HR Management','Manage your workforce from hiring to employee records.','Active'),(5,5,'bi bi-cash-coin','bg-emerald-100','text-emerald-600','Finance & Payroll','Simplify payroll and financial monitoring.','Active'),(6,6,'bi bi-graph-up-arrow','bg-orange-100','text-orange-600','Business Analytics','Turn your business data into useful insights.','Active'),(7,7,'bi bi-shop','bg-sky-100','text-sky-600','Multi-Branch Management','Control multiple stores from one centralized platform.','Active'),(8,8,'bi bi-tags','bg-rose-100','text-rose-600','Promotions','Create promotions based on your subscription and business needs.','Active');
/*!40000 ALTER TABLE `website_included_cards` ENABLE KEYS */;

--
-- Table structure for table `website_included_features`
--

DROP TABLE IF EXISTS `website_included_features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_included_features` (
  `feature_id` int(11) NOT NULL AUTO_INCREMENT,
  `card_id` int(11) NOT NULL,
  `feature_order` int(11) NOT NULL,
  `feature_name` varchar(150) NOT NULL,
  PRIMARY KEY (`feature_id`),
  KEY `card_id` (`card_id`),
  CONSTRAINT `fk_included_features_card` FOREIGN KEY (`card_id`) REFERENCES `website_included_cards` (`card_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_included_features`
--

/*!40000 ALTER TABLE `website_included_features` DISABLE KEYS */;
INSERT INTO `website_included_features` VALUES (54,1,1,'Product Search'),(55,1,2,'Barcode Scanning'),(56,1,3,'Cash / E-Wallet Payments'),(57,1,4,'Discounts'),(58,1,5,'Receipt Printing'),(59,1,6,'Sales Tracking'),(60,2,1,'Stock In / Stock Out'),(61,2,2,'Low Stock Alerts'),(62,2,3,'Inventory Adjustments'),(63,2,4,'Branch Transfers'),(64,2,5,'Supplier Management'),(65,2,6,'Stock History'),(66,3,1,'User Accounts'),(67,3,2,'Role-Based Access'),(68,3,3,'Staff Records'),(69,3,4,'Account Deactivation'),(70,3,5,'Branch Assignment'),(71,3,6,'Employee Status'),(72,4,1,'Job Posting'),(73,4,2,'Recruitment'),(74,4,3,'Employee Records'),(75,4,4,'Leave Requests'),(76,4,5,'Time & Requests'),(77,4,6,'Attendance'),(78,5,1,'Payroll Management'),(79,5,2,'Salary by Department'),(80,5,3,'Employee Salary Details'),(81,5,4,'Payroll Approval'),(82,5,5,'Leave History'),(83,5,6,'Financial Reports'),(84,6,1,'Sales Analytics'),(85,6,2,'Profit Analysis'),(86,6,3,'Inventory Reports'),(87,6,4,'Payroll Reports'),(88,6,5,'Branch Performance'),(89,6,6,'Business Trends'),(90,7,1,'Branch Management'),(91,7,2,'Centralized Data'),(92,7,3,'Branch Performance'),(93,7,4,'Inventory Transfers'),(94,7,5,'Branch Staff'),(95,7,6,'Consolidated Reports'),(96,8,1,'Discounts'),(97,8,2,'Product Promotions'),(98,8,3,'Branch-Based Promotions'),(99,8,4,'POS Promotions'),(100,8,5,'Inventory Promotions'),(101,8,6,'HRMS / Finance features for Professional');
/*!40000 ALTER TABLE `website_included_features` ENABLE KEYS */;

--
-- Table structure for table `website_included_section`
--

DROP TABLE IF EXISTS `website_included_section`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_included_section` (
  `section_id` int(11) NOT NULL AUTO_INCREMENT,
  `badge` varchar(120) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_included_section`
--

/*!40000 ALTER TABLE `website_included_section` DISABLE KEYS */;
INSERT INTO `website_included_section` VALUES (1,'WHAT\'S INCLUDED','One Platform. Every Part of Your Business.',NULL,'2026-09-07 17:25:03');
/*!40000 ALTER TABLE `website_included_section` ENABLE KEYS */;

--
-- Table structure for table `website_modules`
--

DROP TABLE IF EXISTS `website_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_modules` (
  `module_id` int(11) NOT NULL AUTO_INCREMENT,
  `module_name` varchar(150) NOT NULL,
  `module_description` text DEFAULT NULL,
  `icon` varchar(100) NOT NULL,
  `icon_bg` varchar(100) DEFAULT 'bg-sky-100',
  `icon_color` varchar(100) DEFAULT 'text-sky-600',
  `module_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`module_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_modules`
--

/*!40000 ALTER TABLE `website_modules` DISABLE KEYS */;
INSERT INTO `website_modules` VALUES (1,'Point of Sale','Sales, payments, receipts, discounts and returns.','bi bi-cart-check','bg-blue-100','text-blue-600',1,'Active','2026-09-30 23:47:41','2026-09-30 23:47:41'),(2,'Inventory','Stock-in, stock-out, adjustments, transfers and warehouse monitoring.','bi bi-box-seam','bg-yellow-100','text-yellow-600',2,'Active','2026-09-30 23:47:41','2026-09-30 23:47:41'),(3,'Purchasing','Purchase requests, purchase orders, supplier management and receiving.','bi bi-clipboard-check','bg-green-100','text-green-600',3,'Active','2026-09-30 23:47:41','2026-09-30 23:47:41'),(4,'Human Resources','Employee records, recruitment, time tracking and requests.','bi bi-people','bg-purple-100','text-purple-600',4,'Active','2026-09-30 23:47:41','2026-09-30 23:47:41'),(5,'Finance','Payroll, financial records, approvals and financial reporting.','bi bi-cash-coin','bg-emerald-100','text-emerald-600',5,'Active','2026-09-30 23:47:41','2026-09-30 23:47:41'),(6,'Branch Management','Monitor and manage multiple retail branches from one platform.','bi bi-shop','bg-sky-100','text-sky-600',6,'Active','2026-09-30 23:47:41','2026-09-30 23:47:41'),(7,'Reports & Analytics','Business insights, sales reports, inventory reports and workforce reports.','bi bi-bar-chart-line','bg-orange-100','text-orange-600',7,'Active','2026-09-30 23:47:41','2026-09-30 23:47:41'),(8,'Promotions','Manage promotions based on business and subscription access.','bi bi-tags','bg-rose-100','text-rose-600',8,'Active','2026-09-30 23:47:41','2026-09-30 23:47:41');
/*!40000 ALTER TABLE `website_modules` ENABLE KEYS */;

--
-- Table structure for table `website_modules_section`
--

DROP TABLE IF EXISTS `website_modules_section`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_modules_section` (
  `section_id` int(11) NOT NULL AUTO_INCREMENT,
  `badge` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_modules_section`
--

/*!40000 ALTER TABLE `website_modules_section` DISABLE KEYS */;
INSERT INTO `website_modules_section` VALUES (1,'8 CORE MODULES','One system, every part of the business','Activate what you need today and switch on the rest as you grow - no re-implementation.','2026-09-30 16:07:49');
/*!40000 ALTER TABLE `website_modules_section` ENABLE KEYS */;

--
-- Table structure for table `website_platform_cards`
--

DROP TABLE IF EXISTS `website_platform_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_platform_cards` (
  `card_id` int(11) NOT NULL AUTO_INCREMENT,
  `card_order` int(11) NOT NULL,
  `icon` varchar(100) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`card_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_platform_cards`
--

/*!40000 ALTER TABLE `website_platform_cards` DISABLE KEYS */;
INSERT INTO `website_platform_cards` VALUES (1,1,'bi bi-upc-scan','POS','A cashier scans adn item. The sale posts instantly.','Active'),(2,2,'bi bi-box-seam','Inventory','Stock decrements across the branch and warehouse ledger.','Active'),(3,3,'bi bi-truck','Purchasing','Reorder points fire a purchase request to the supplier.','Active'),(4,4,'bi bi-buildings','Branches','Regional managers see performance ranked live.','Active');
/*!40000 ALTER TABLE `website_platform_cards` ENABLE KEYS */;

--
-- Table structure for table `website_platform_section`
--

DROP TABLE IF EXISTS `website_platform_section`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_platform_section` (
  `section_id` int(11) NOT NULL AUTO_INCREMENT,
  `hero_title` varchar(255) NOT NULL,
  `hero_description` text NOT NULL,
  `section_badge` varchar(100) NOT NULL,
  `section_title` varchar(255) NOT NULL,
  `section_description` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_platform_section`
--

/*!40000 ALTER TABLE `website_platform_section` DISABLE KEYS */;
INSERT INTO `website_platform_section` VALUES (1,'One data layer behind every counter, stockroom and payslip','A sale at a terminal in Cebu updates stock, triggers reordering, credits the cashier\'s shift and lands in tonight\'s regional report - without a single export.','HOW IT CONNECTS','Everything your business needs in one platform','Complete business management modules working together in a single system.','2026-09-30 16:07:49');
/*!40000 ALTER TABLE `website_platform_section` ENABLE KEYS */;

--
-- Table structure for table `website_pricing_compare`
--

DROP TABLE IF EXISTS `website_pricing_compare`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_pricing_compare` (
  `row_id` int(11) NOT NULL AUTO_INCREMENT,
  `row_order` int(11) NOT NULL DEFAULT 1,
  `feature_label` varchar(190) NOT NULL,
  `col1_value` varchar(120) NOT NULL DEFAULT 'no',
  `col2_value` varchar(120) NOT NULL DEFAULT 'no',
  `col3_value` varchar(120) NOT NULL DEFAULT 'no',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`row_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_pricing_compare`
--

/*!40000 ALTER TABLE `website_pricing_compare` DISABLE KEYS */;
INSERT INTO `website_pricing_compare` VALUES (1,1,'Branches included','1','Up to 10','Unlimited','Active'),(2,2,'Users','10','Unlimited','Unlimited','Active'),(3,3,'Point of Sale','yes','yes','yes','Active'),(4,4,'Inventory & Warehouse','yes','yes','yes','Active'),(5,5,'Purchasing & Suppliers','no','yes','yes','Active'),(6,6,'Promotions Engine','no','yes','yes','Active'),(7,7,'HR & Attendance','no','yes','yes','Active'),(8,8,'Payroll & Payslips','no','yes','yes','Active'),(9,9,'Recruitment Portal','no','yes','yes','Active'),(10,10,'Accounting','no','yes','yes','Active'),(11,11,'Analytics & Forecasting','no','Standard','AI Included','Active'),(12,12,'API Access','no','no','yes','Active'),(13,13,'White Label','no','no','yes','Active'),(14,14,'Support','Email','Priority','Dedicated AM','Active');
/*!40000 ALTER TABLE `website_pricing_compare` ENABLE KEYS */;

--
-- Table structure for table `website_pricing_faq`
--

DROP TABLE IF EXISTS `website_pricing_faq`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_pricing_faq` (
  `faq_id` int(11) NOT NULL AUTO_INCREMENT,
  `faq_order` int(11) NOT NULL DEFAULT 1,
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`faq_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_pricing_faq`
--

/*!40000 ALTER TABLE `website_pricing_faq` DISABLE KEYS */;
INSERT INTO `website_pricing_faq` VALUES (1,1,'How long does implementation take?','Most stores are fully operational within one to two weeks depending on the number of branches.','Active'),(2,2,'Does the POS work without internet?','Yes. Transactions continue offline and automatically sync once the connection is restored.','Active'),(3,3,'Can we start with POS only and add HR later?','Yes. Additional modules can be enabled whenever your business is ready.','Active'),(4,4,'Is our business data secure?','Yes. All information is encrypted and securely stored with regular backups.','Active'),(5,5,'Do you support payroll and government contributions?','Yes. Payroll supports SSS, PhilHealth, Pag-IBIG and other payroll deductions.','Active'),(6,6,'What hardware do we need?','RetailCore works with most barcode scanners, receipt printers, cash drawers and POS terminals.','Active');
/*!40000 ALTER TABLE `website_pricing_faq` ENABLE KEYS */;

--
-- Table structure for table `website_pricing_section`
--

DROP TABLE IF EXISTS `website_pricing_section`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_pricing_section` (
  `section_id` int(11) NOT NULL AUTO_INCREMENT,
  `hero_badge` varchar(120) NOT NULL,
  `hero_title` varchar(255) NOT NULL,
  `hero_description` text NOT NULL,
  `compare_badge` varchar(120) NOT NULL,
  `compare_title` varchar(255) NOT NULL,
  `compare_col1` varchar(120) NOT NULL,
  `compare_col2` varchar(120) NOT NULL,
  `compare_col3` varchar(120) NOT NULL,
  `compare_note` text NOT NULL,
  `faq_badge` varchar(120) NOT NULL,
  `faq_title` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_pricing_section`
--

/*!40000 ALTER TABLE `website_pricing_section` DISABLE KEYS */;
INSERT INTO `website_pricing_section` VALUES (1,'PRICING','One Platform. Built for Every Retail Business.','Whether you run one convenience store or a growing retail network, choose the plan that fits your operation.','COMPARE','What is included in each plan','Retail Starter','Retail Professional','Retail Enterprise','Prices in PHP, excluding VAT. Marketplace apps are billed separately per branch.','FAQ','Billing and rollout questions','2026-09-30 14:52:12');
/*!40000 ALTER TABLE `website_pricing_section` ENABLE KEYS */;

--
-- Table structure for table `website_usp_section`
--

DROP TABLE IF EXISTS `website_usp_section`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_usp_section` (
  `section_id` int(11) NOT NULL AUTO_INCREMENT,
  `badge` varchar(120) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_usp_section`
--

/*!40000 ALTER TABLE `website_usp_section` DISABLE KEYS */;
INSERT INTO `website_usp_section` VALUES (1,'BUILT FOR MULTI-BRANCH RETAIL','One Business. Multiple Branches. One Control Center.','Manage every branch, employee, transaction, and inventory movement from a centralized platform.','2026-09-30 23:47:41');
/*!40000 ALTER TABLE `website_usp_section` ENABLE KEYS */;

--
-- Table structure for table `website_why_cards`
--

DROP TABLE IF EXISTS `website_why_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_why_cards` (
  `card_id` int(11) NOT NULL AUTO_INCREMENT,
  `card_order` int(11) NOT NULL,
  `icon` varchar(100) NOT NULL,
  `title` varchar(150) NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`card_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_why_cards`
--

/*!40000 ALTER TABLE `website_why_cards` DISABLE KEYS */;
INSERT INTO `website_why_cards` VALUES (5,1,'bi bi-boxes','All-in-One Platform','Active'),(6,2,'bi bi-graph-up','Real-Time Analytics','Active'),(7,3,'bi bi-diagram-3','Multi-Branch Ready','Active'),(8,4,'bi bi-shield-check','Secure Cloud System','Active');
/*!40000 ALTER TABLE `website_why_cards` ENABLE KEYS */;

--
-- Table structure for table `website_why_section`
--

DROP TABLE IF EXISTS `website_why_section`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `website_why_section` (
  `section_id` int(11) NOT NULL AUTO_INCREMENT,
  `badge` varchar(120) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `website_why_section`
--

/*!40000 ALTER TABLE `website_why_section` DISABLE KEYS */;
INSERT INTO `website_why_section` VALUES (1,'WHY RETAILCORE','Everything your retail business needs, connected in one platform.','RetailCore connects sales, inventory, purchasing, workforce management, finance, and business analytics into one centralized retail operating system.','2026-09-30 23:47:41');
/*!40000 ALTER TABLE `website_why_section` ENABLE KEYS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-03 16:32:30
