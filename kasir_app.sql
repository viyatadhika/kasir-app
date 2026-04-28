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
(1,'899100111001','Indomie Goreng','Makanan',2800,3500,98,10,'pcs','aktif','2026-04-28 08:43:37','2026-04-28 09:01:24'),
(2,'899200222002','Aqua 600ml','Minuman',3000,4000,77,10,'botol','aktif','2026-04-28 08:43:37','2026-04-28 09:01:24'),
(3,'899300333003','Teh Pucuk Harum','Minuman',4000,5000,75,10,'botol','aktif','2026-04-28 08:43:37',NULL),
(4,'899400444004','Roti Sari Roti','Makanan',6000,7500,50,8,'pcs','aktif','2026-04-28 08:43:37',NULL),
(5,'899500555005','Susu Ultra 250ml','Minuman',5200,6500,60,8,'kotak','aktif','2026-04-28 08:43:37',NULL);

/*Table structure for table `transaksi` */

DROP TABLE IF EXISTS `transaksi`;

CREATE TABLE `transaksi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice` varchar(80) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total` int(11) NOT NULL,
  `bayar` int(11) NOT NULL,
  `kembalian` int(11) NOT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice` (`invoice`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `transaksi_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `transaksi` */

insert  into `transaksi`(`id`,`invoice`,`user_id`,`total`,`bayar`,`kembalian`,`catatan`,`created_at`) values 
(1,'INV-20260428090124-902',1,19000,20000,1000,NULL,'2026-04-28 09:01:24');

/*Table structure for table `transaksi_detail` */

DROP TABLE IF EXISTS `transaksi_detail`;

CREATE TABLE `transaksi_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaksi_id` int(11) NOT NULL,
  `produk_id` int(11) NOT NULL,
  `kode` varchar(80) NOT NULL,
  `nama` varchar(160) NOT NULL,
  `harga` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `subtotal` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `transaksi_id` (`transaksi_id`),
  KEY `produk_id` (`produk_id`),
  CONSTRAINT `transaksi_detail_ibfk_1` FOREIGN KEY (`transaksi_id`) REFERENCES `transaksi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transaksi_detail_ibfk_2` FOREIGN KEY (`produk_id`) REFERENCES `produk` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*Data for the table `transaksi_detail` */

insert  into `transaksi_detail`(`id`,`transaksi_id`,`produk_id`,`kode`,`nama`,`harga`,`qty`,`subtotal`) values 
(1,1,2,'899200222002','Aqua 600ml',4000,3,12000),
(2,1,1,'899100111001','Indomie Goreng',3500,2,7000);

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
