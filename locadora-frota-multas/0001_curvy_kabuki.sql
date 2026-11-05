CREATE TABLE `appointments` (
	`id` int AUTO_INCREMENT NOT NULL,
	`serviceRequestId` int NOT NULL,
	`supplierId` int NOT NULL,
	`driverId` int NOT NULL,
	`vehicleId` int NOT NULL,
	`scheduledDate` datetime NOT NULL,
	`estimatedDuration` int,
	`actualDuration` int,
	`status` enum('pending','confirmed','in_progress','completed','cancelled','no_show') DEFAULT 'pending',
	`notes` text,
	`reminderSent` boolean DEFAULT false,
	`reminderSentAt` timestamp,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `appointments_id` PRIMARY KEY(`id`)
);
--> statement-breakpoint
CREATE TABLE `auditLogs` (
	`id` int AUTO_INCREMENT NOT NULL,
	`userId` int,
	`action` varchar(100) NOT NULL,
	`entityType` varchar(50) NOT NULL,
	`entityId` int,
	`changes` json,
	`ipAddress` varchar(45),
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	CONSTRAINT `auditLogs_id` PRIMARY KEY(`id`)
);
--> statement-breakpoint
CREATE TABLE `budgets` (
	`id` int AUTO_INCREMENT NOT NULL,
	`appointmentId` int NOT NULL,
	`supplierId` int NOT NULL,
	`driverId` int NOT NULL,
	`items` json,
	`subtotal` decimal(10,2) NOT NULL,
	`tax` decimal(10,2) DEFAULT '0',
	`total` decimal(10,2) NOT NULL,
	`status` enum('pending','approved','rejected','expired') DEFAULT 'pending',
	`approvedAt` timestamp,
	`rejectionReason` text,
	`expiresAt` timestamp,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `budgets_id` PRIMARY KEY(`id`)
);
--> statement-breakpoint
CREATE TABLE `emailLogs` (
	`id` int AUTO_INCREMENT NOT NULL,
	`recipientEmail` varchar(320) NOT NULL,
	`subject` varchar(255) NOT NULL,
	`type` varchar(100) NOT NULL,
	`relatedEntityId` int,
	`relatedEntityType` varchar(50),
	`status` enum('pending','sent','failed','bounced') DEFAULT 'pending',
	`failureReason` text,
	`sentAt` timestamp,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	CONSTRAINT `emailLogs_id` PRIMARY KEY(`id`)
);
--> statement-breakpoint
CREATE TABLE `invoices` (
	`id` int AUTO_INCREMENT NOT NULL,
	`appointmentId` int NOT NULL,
	`budgetId` int,
	`supplierId` int NOT NULL,
	`driverId` int NOT NULL,
	`invoiceNumber` varchar(50),
	`issueDate` datetime NOT NULL,
	`dueDate` datetime,
	`amount` decimal(10,2) NOT NULL,
	`status` enum('pending','paid','overdue','cancelled') DEFAULT 'pending',
	`documentUrl` text,
	`documentKey` varchar(255),
	`paymentMethod` enum('cash','credit_card','debit_card','bank_transfer','check'),
	`paidAt` timestamp,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `invoices_id` PRIMARY KEY(`id`),
	CONSTRAINT `invoices_invoiceNumber_unique` UNIQUE(`invoiceNumber`)
);
--> statement-breakpoint
CREATE TABLE `notifications` (
	`id` int AUTO_INCREMENT NOT NULL,
	`userId` int NOT NULL,
	`type` enum('appointment_confirmed','appointment_reminder','budget_approved','budget_rejected','service_completed','invoice_issued','payment_received','new_request','system_alert') NOT NULL,
	`title` varchar(200) NOT NULL,
	`message` text,
	`relatedEntityId` int,
	`relatedEntityType` varchar(50),
	`isRead` boolean DEFAULT false,
	`readAt` timestamp,
	`emailSent` boolean DEFAULT false,
	`emailSentAt` timestamp,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	CONSTRAINT `notifications_id` PRIMARY KEY(`id`)
);
--> statement-breakpoint
CREATE TABLE `payments` (
	`id` int AUTO_INCREMENT NOT NULL,
	`invoiceId` int NOT NULL,
	`stripePaymentIntentId` varchar(255),
	`amount` decimal(10,2) NOT NULL,
	`currency` varchar(3) DEFAULT 'BRL',
	`status` enum('pending','processing','succeeded','failed','cancelled') DEFAULT 'pending',
	`paymentMethod` varchar(50),
	`failureReason` text,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `payments_id` PRIMARY KEY(`id`),
	CONSTRAINT `payments_stripePaymentIntentId_unique` UNIQUE(`stripePaymentIntentId`)
);
--> statement-breakpoint
CREATE TABLE `reviews` (
	`id` int AUTO_INCREMENT NOT NULL,
	`appointmentId` int NOT NULL,
	`supplierId` int NOT NULL,
	`driverId` int NOT NULL,
	`rating` int NOT NULL,
	`title` varchar(200),
	`comment` text,
	`categories` json,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `reviews_id` PRIMARY KEY(`id`)
);
--> statement-breakpoint
CREATE TABLE `serviceRequests` (
	`id` int AUTO_INCREMENT NOT NULL,
	`driverId` int NOT NULL,
	`vehicleId` int NOT NULL,
	`serviceTypeId` int NOT NULL,
	`description` text NOT NULL,
	`latitude` float,
	`longitude` float,
	`city` varchar(100),
	`urgencyLevel` enum('low','medium','high','critical') DEFAULT 'medium',
	`status` enum('pending','assigned','scheduled','completed','cancelled') DEFAULT 'pending',
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `serviceRequests_id` PRIMARY KEY(`id`)
);
--> statement-breakpoint
CREATE TABLE `serviceTypes` (
	`id` int AUTO_INCREMENT NOT NULL,
	`name` varchar(100) NOT NULL,
	`description` text,
	`icon` varchar(50),
	`estimatedDuration` int,
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	CONSTRAINT `serviceTypes_id` PRIMARY KEY(`id`),
	CONSTRAINT `serviceTypes_name_unique` UNIQUE(`name`)
);
--> statement-breakpoint
CREATE TABLE `suppliers` (
	`id` int AUTO_INCREMENT NOT NULL,
	`userId` int NOT NULL,
	`businessName` varchar(200) NOT NULL,
	`cnpj` varchar(20),
	`address` text NOT NULL,
	`city` varchar(100) NOT NULL,
	`state` varchar(2) NOT NULL,
	`zipCode` varchar(10),
	`latitude` float,
	`longitude` float,
	`phone` varchar(20) NOT NULL,
	`email` varchar(320) NOT NULL,
	`website` varchar(255),
	`specialties` json,
	`operatingHours` json,
	`averageRating` decimal(3,2) DEFAULT '0',
	`totalReviews` int DEFAULT 0,
	`isVerified` boolean DEFAULT false,
	`status` enum('active','inactive','suspended') DEFAULT 'active',
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `suppliers_id` PRIMARY KEY(`id`),
	CONSTRAINT `suppliers_cnpj_unique` UNIQUE(`cnpj`)
);
--> statement-breakpoint
CREATE TABLE `vehicles` (
	`id` int AUTO_INCREMENT NOT NULL,
	`driverId` int NOT NULL,
	`licensePlate` varchar(20) NOT NULL,
	`brand` varchar(100) NOT NULL,
	`model` varchar(100) NOT NULL,
	`year` int NOT NULL,
	`color` varchar(50),
	`mileage` int DEFAULT 0,
	`lastServiceDate` timestamp,
	`nextServiceDate` timestamp,
	`status` enum('active','maintenance','inactive') DEFAULT 'active',
	`createdAt` timestamp NOT NULL DEFAULT (now()),
	`updatedAt` timestamp NOT NULL DEFAULT (now()) ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT `vehicles_id` PRIMARY KEY(`id`),
	CONSTRAINT `vehicles_licensePlate_unique` UNIQUE(`licensePlate`)
);
--> statement-breakpoint
ALTER TABLE `users` ADD `phone` varchar(20);--> statement-breakpoint
ALTER TABLE `users` ADD `userType` enum('driver','admin','supplier') DEFAULT 'driver' NOT NULL;--> statement-breakpoint
ALTER TABLE `users` ADD `profilePicture` text;--> statement-breakpoint
ALTER TABLE `users` ADD CONSTRAINT `users_email_unique` UNIQUE(`email`);--> statement-breakpoint
ALTER TABLE `users` DROP COLUMN `loginMethod`;