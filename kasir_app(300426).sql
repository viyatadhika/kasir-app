/*
SQLyog Ultimate v13.1.1 (64 bit)
MySQL - 10.4.32-MariaDB : Database - kasir_app
*********************************************************************
*/

/*!40101 SET NAMES utf8 */;

/*!40101 SET SQL_MODE=''*/;

/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
CREATE DATABASE /*!32312 IF NOT EXISTS*/`kasir_app` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `kasir_app`;

/*Table structure for table `diskon` */

DROP TABLE IF EXISTS `diskon`;

CREATE TABLE `diskon` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `target` enum('semua','member') DEFAULT 'semua',
  `cakupan` enum('transaksi','produk','kategori') DEFAULT 'transaksi',
  `produk_id` int(11) DEFAULT NULL,
  `kategori` varchar(100) DEFAULT NULL,
  `jenis` enum('persen','nominal') NOT NULL,
  `nilai` int(11) NOT NULL,
  `minimal_belanja` int(11) DEFAULT 0,
  `tanggal_mulai` date DEFAULT NULL,
  `tanggal_selesai` date DEFAULT NULL,
  `status` enum('aktif','nonaktif') DEFAULT 'aktif',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `diskon` */

insert  into `diskon`(`id`,`nama`,`target`,`cakupan`,`produk_id`,`kategori`,`jenis`,`nilai`,`minimal_belanja`,`tanggal_mulai`,`tanggal_selesai`,`status`,`created_at`,`updated_at`) values 
(1,'Diskon Member 5%','member','transaksi',NULL,NULL,'persen',5,0,NULL,NULL,'nonaktif','2026-04-29 10:53:49','2026-04-29 11:44:12'),
(2,'Promo Belanja 100rb','semua','transaksi',NULL,NULL,'nominal',10000,100000,NULL,NULL,'aktif','2026-04-29 10:53:49',NULL),
(3,'PROMO INDOMIE','semua','produk',1,NULL,'nominal',500,0,NULL,NULL,'aktif','2026-04-29 11:23:46',NULL);

/*Table structure for table `member` */

DROP TABLE IF EXISTS `member`;

CREATE TABLE `member` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kode` varchar(30) NOT NULL COMMENT 'Kode/barcode member',
  `nama` varchar(120) NOT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `point` int(11) NOT NULL DEFAULT 0,
  `total_belanja` bigint(20) NOT NULL DEFAULT 0,
  `status` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode` (`kode`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `member` */

insert  into `member`(`id`,`kode`,`nama`,`no_hp`,`point`,`total_belanja`,`status`,`created_at`,`updated_at`) values 
(1,'MBR001','Andi Wijaya','08111000001',159,119150,'aktif','2026-04-29 08:42:22','2026-04-29 14:09:19'),
(2,'MBR002','Siti Rahayu','08111000002',83,41500,'aktif','2026-04-29 08:42:22','2026-04-29 13:46:58'),
(3,'MBR003','Budi Hartono','08111000003',323,36500,'aktif','2026-04-29 08:42:22','2026-04-29 13:03:43');

/*Table structure for table `produk` */

DROP TABLE IF EXISTS `produk`;

CREATE TABLE `produk` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kode` varchar(80) NOT NULL,
  `nama` varchar(160) NOT NULL,
  `kategori` varchar(100) DEFAULT '-',
  `harga_beli` int(11) NOT NULL DEFAULT 0,
  `harga_jual` int(11) NOT NULL,
  `stok` int(11) NOT NULL DEFAULT 0,
  `stok_minimum` int(11) NOT NULL DEFAULT 5,
  `satuan` varchar(30) DEFAULT 'pcs',
  `status` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode` (`kode`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `produk` */

insert  into `produk`(`id`,`kode`,`nama`,`kategori`,`harga_beli`,`harga_jual`,`stok`,`stok_minimum`,`satuan`,`status`,`created_at`,`updated_at`) values 
(1,'899100111001','Indomie Goreng','Makanan',2800,3500,60,10,'pcs','aktif','2026-04-28 08:43:37','2026-04-29 14:09:19'),
(2,'899200222002','Aqua 600ml','Minuman',3000,4000,69,10,'botol','aktif','2026-04-28 08:43:37','2026-04-29 13:46:58'),
(3,'899300333003','Teh Pucuk Harum','Minuman',4000,5000,68,10,'botol','aktif','2026-04-28 08:43:37','2026-04-29 14:03:48'),
(4,'899400444004','Roti Sari Roti','Makanan',6000,8000,39,8,'pcs','aktif','2026-04-28 08:43:37','2026-04-29 14:03:48'),
(5,'899500555005','Susu Ultra 250ml','Minuman',5200,6500,53,8,'kotak','aktif','2026-04-28 08:43:37','2026-04-29 13:46:58');

/*Table structure for table `transaksi` */

DROP TABLE IF EXISTS `transaksi`;

CREATE TABLE `transaksi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice` varchar(80) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `total` int(11) NOT NULL,
  `diskon` int(11) DEFAULT 0,
  `diskon_id` int(11) DEFAULT NULL,
  `bayar` int(11) NOT NULL,
  `kembalian` int(11) NOT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `point_dapat` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `metode_pembayaran` varchar(30) DEFAULT 'tunai',
  `payment_status` varchar(30) DEFAULT 'pending',
  `payment_reference` varchar(100) DEFAULT NULL,
  `qr_url` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice` (`invoice`),
  KEY `user_id` (`user_id`),
  KEY `fk_transaksi_member` (`member_id`),
  CONSTRAINT `fk_transaksi_member` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaksi_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `transaksi` */

insert  into `transaksi`(`id`,`invoice`,`user_id`,`member_id`,`total`,`diskon`,`diskon_id`,`bayar`,`kembalian`,`catatan`,`point_dapat`,`created_at`,`metode_pembayaran`,`payment_status`,`payment_reference`,`qr_url`) values 
(1,'INV-20260428090124-902',1,NULL,19000,0,NULL,20000,1000,NULL,0,'2026-04-28 09:01:24','tunai','pending',NULL,NULL),
(2,'INV-20260428135156-159',1,NULL,17205,0,NULL,20000,2795,NULL,0,'2026-04-28 13:51:56','tunai','pending',NULL,NULL),
(3,'INV-20260428151627-751',1,NULL,29970,0,NULL,50000,20030,NULL,0,'2026-04-28 15:16:27','tunai','pending',NULL,NULL),
(4,'INV-20260428154517-310',1,NULL,24420,0,NULL,24420,0,NULL,0,'2026-04-28 15:45:17','tunai','pending',NULL,NULL),
(5,'INV-20260428155232-115',1,NULL,17205,0,NULL,17205,0,NULL,0,'2026-04-28 15:52:32','tunai','pending',NULL,NULL),
(6,'INV-20260429090337-516',1,1,13000,0,NULL,15000,2000,NULL,1,'2026-04-29 09:03:37','tunai','pending',NULL,NULL),
(7,'INV-20260429090800-924',1,1,7500,0,NULL,10000,2500,NULL,0,'2026-04-29 09:08:00','tunai','pending',NULL,NULL),
(8,'INV-20260429111159-499',1,1,25650,1350,1,28000,2350,NULL,2,'2026-04-29 11:11:59','tunai','pending',NULL,NULL),
(9,'INV-20260429114944-794',1,1,15000,2500,3,15000,0,NULL,1,'2026-04-29 11:49:45','tunai','pending',NULL,NULL),
(10,'INV-20260429122635-428',1,NULL,6000,1000,3,10000,4000,NULL,0,'2026-04-29 12:26:35','tunai','pending',NULL,NULL),
(11,'INV-20260429122721-704',1,NULL,15000,2500,3,15000,0,NULL,0,'2026-04-29 12:27:21','tunai','pending',NULL,NULL),
(12,'INV-20260429124246-428',1,3,21500,2500,3,22000,500,NULL,2,'2026-04-29 12:42:46','tunai','pending',NULL,NULL),
(13,'INV-20260429125429-128',1,2,15000,2500,3,15000,0,NULL,1,'2026-04-29 12:54:29','tunai','pending',NULL,NULL),
(14,'INV-20260429130343-323',1,3,15000,500,3,15000,0,NULL,1,'2026-04-29 13:03:43','tunai','pending',NULL,NULL),
(15,'INV-20260429130845-464',1,1,11000,500,3,12000,1000,NULL,1,'2026-04-29 13:08:45','tunai','pending',NULL,NULL),
(16,'INV-20260429132528-353',1,1,11000,500,3,11000,0,NULL,1,'2026-04-29 13:25:28','tunai','pending',NULL,NULL),
(17,'INV-20260429132715-913',1,NULL,17500,500,3,17500,0,NULL,0,'2026-04-29 13:27:16','tunai','pending',NULL,NULL),
(18,'INV-20260429134658-322',1,2,26500,500,3,26500,0,NULL,2,'2026-04-29 13:46:58','tunai','pending',NULL,NULL),
(19,'INV-20260429140348-637',1,1,21000,500,3,21000,0,NULL,2,'2026-04-29 14:03:48','tunai','pending',NULL,NULL),
(20,'INV-20260429140918-272',1,1,15000,2500,3,15000,0,NULL,1,'2026-04-29 14:09:18','tunai','pending',NULL,NULL);

/*Table structure for table `transaksi_detail` */

DROP TABLE IF EXISTS `transaksi_detail`;

CREATE TABLE `transaksi_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaksi_id` int(11) NOT NULL,
  `produk_id` int(11) NOT NULL,
  `kode` varchar(80) NOT NULL,
  `nama` varchar(160) NOT NULL,
  `harga_normal` int(11) DEFAULT NULL,
  `harga` int(11) NOT NULL,
  `diskon` int(11) DEFAULT 0,
  `diskon_id` int(11) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `subtotal` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `transaksi_id` (`transaksi_id`),
  KEY `produk_id` (`produk_id`),
  CONSTRAINT `transaksi_detail_ibfk_1` FOREIGN KEY (`transaksi_id`) REFERENCES `transaksi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transaksi_detail_ibfk_2` FOREIGN KEY (`produk_id`) REFERENCES `produk` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `transaksi_detail` */

insert  into `transaksi_detail`(`id`,`transaksi_id`,`produk_id`,`kode`,`nama`,`harga_normal`,`harga`,`diskon`,`diskon_id`,`qty`,`subtotal`) values 
(1,1,2,'899200222002','Aqua 600ml',NULL,4000,0,NULL,3,12000),
(2,1,1,'899100111001','Indomie Goreng',NULL,3500,0,NULL,2,7000),
(3,2,1,'1','Indomie Goreng',NULL,3500,0,NULL,1,3500),
(4,2,4,'4','Roti Sari Roti',NULL,8000,0,NULL,1,8000),
(5,2,2,'2','Aqua 600ml',NULL,4000,0,NULL,1,4000),
(6,3,1,'899100111001','Indomie Goreng',NULL,3500,0,NULL,1,3500),
(7,3,4,'899400444004','Roti Sari Roti',NULL,8000,0,NULL,1,8000),
(8,3,2,'899200222002','Aqua 600ml',NULL,4000,0,NULL,1,4000),
(9,3,5,'899500555005','Susu Ultra 250ml',NULL,6500,0,NULL,1,6500),
(10,3,3,'899300333003','Teh Pucuk Harum',NULL,5000,0,NULL,1,5000),
(11,4,1,'899100111001','Indomie Goreng',NULL,3500,0,NULL,1,3500),
(12,4,4,'899400444004','Roti Sari Roti',NULL,8000,0,NULL,1,8000),
(13,4,2,'899200222002','Aqua 600ml',NULL,4000,0,NULL,1,4000),
(14,4,5,'899500555005','Susu Ultra 250ml',NULL,6500,0,NULL,1,6500),
(15,5,2,'899200222002','Aqua 600ml',NULL,4000,0,NULL,1,4000),
(16,5,5,'899500555005','Susu Ultra 250ml',NULL,6500,0,NULL,1,6500),
(17,5,3,'899300333003','Teh Pucuk Harum',NULL,5000,0,NULL,1,5000),
(18,6,4,'899400444004','Roti Sari Roti',NULL,8000,0,NULL,1,8000),
(19,6,3,'899300333003','Teh Pucuk Harum',NULL,5000,0,NULL,1,5000),
(20,7,1,'899100111001','Indomie Goreng',NULL,3500,0,NULL,1,3500),
(21,7,2,'899200222002','Aqua 600ml',NULL,4000,0,NULL,1,4000),
(22,8,1,'899100111001','Indomie Goreng',NULL,3500,0,NULL,1,3500),
(23,8,4,'899400444004','Roti Sari Roti',NULL,8000,0,NULL,1,8000),
(24,8,2,'899200222002','Aqua 600ml',NULL,4000,0,NULL,1,4000),
(25,8,5,'899500555005','Susu Ultra 250ml',NULL,6500,0,NULL,1,6500),
(26,8,3,'899300333003','Teh Pucuk Harum',NULL,5000,0,NULL,1,5000),
(27,9,1,'899100111001','Indomie Goreng',NULL,3000,0,NULL,5,15000),
(28,10,1,'899100111001','Indomie Goreng',NULL,3000,0,NULL,2,6000),
(29,11,1,'899100111001','Indomie Goreng',NULL,3000,0,NULL,5,15000),
(30,12,5,'899500555005','Susu Ultra 250ml',NULL,6500,0,NULL,1,6500),
(31,12,1,'899100111001','Indomie Goreng',NULL,3000,0,NULL,5,15000),
(32,13,1,'899100111001','Indomie Goreng',NULL,3000,0,NULL,5,15000),
(33,14,4,'899400444004','Roti Sari Roti',NULL,8000,0,NULL,1,8000),
(34,14,2,'899200222002','Aqua 600ml',NULL,4000,0,NULL,1,4000),
(35,14,1,'899100111001','Indomie Goreng',NULL,3000,0,NULL,1,3000),
(36,15,1,'899100111001','Indomie Goreng',NULL,3000,0,NULL,1,3000),
(37,15,4,'899400444004','Roti Sari Roti',NULL,8000,0,NULL,1,8000),
(38,16,1,'899100111001','Indomie Goreng',NULL,3000,0,NULL,1,3000),
(39,16,4,'899400444004','Roti Sari Roti',NULL,8000,0,NULL,1,8000),
(40,17,1,'899100111001','Indomie Goreng',NULL,3000,0,NULL,1,3000),
(41,17,4,'899400444004','Roti Sari Roti',NULL,8000,0,NULL,1,8000),
(42,17,5,'899500555005','Susu Ultra 250ml',NULL,6500,0,NULL,1,6500),
(43,18,1,'899100111001','Indomie Goreng',3500,3000,500,3,1,3000),
(44,18,4,'899400444004','Roti Sari Roti',8000,8000,0,NULL,1,8000),
(45,18,2,'899200222002','Aqua 600ml',4000,4000,0,NULL,1,4000),
(46,18,5,'899500555005','Susu Ultra 250ml',6500,6500,0,NULL,1,6500),
(47,18,3,'899300333003','Teh Pucuk Harum',5000,5000,0,NULL,1,5000),
(48,19,1,'899100111001','Indomie Goreng',3500,3000,500,3,1,3000),
(49,19,4,'899400444004','Roti Sari Roti',8000,8000,0,NULL,1,8000),
(50,19,3,'899300333003','Teh Pucuk Harum',5000,5000,0,NULL,2,10000),
(51,20,1,'899100111001','Indomie Goreng',3500,3000,2500,3,5,15000);

/*Table structure for table `users` */

DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','kasir') NOT NULL DEFAULT 'kasir',
  `status` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `users` */

insert  into `users`(`id`,`nama`,`username`,`password`,`role`,`status`,`created_at`) values 
(1,'Administrator','admin','$2y$10$lBliD8JcZjs7VtOA8UW9jO3RLbf1jpIVn7m3gZtskTonHvsfTxGJ6','admin','aktif','2026-04-28 08:43:37'),
(2,'Kasir Utama','kasir','$2y$10$lBliD8JcZjs7VtOA8UW9jO3RLbf1jpIVn7m3gZtskTonHvsfTxGJ6','kasir','aktif','2026-04-28 08:43:37');

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
