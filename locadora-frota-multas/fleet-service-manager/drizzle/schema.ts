import {
  int,
  mysqlEnum,
  mysqlTable,
  text,
  timestamp,
  varchar,
  decimal,
  boolean,
  datetime,
  json,
  float,
} from "drizzle-orm/mysql-core";

/**
 * Core user table backing auth flow.
 * Supports both drivers (motoristas) and admins.
 */
export const users = mysqlTable("users", {
  id: int("id").autoincrement().primaryKey(),
  openId: varchar("openId", { length: 64 }).notNull().unique(),
  name: text("name"),
  email: varchar("email", { length: 320 }).unique(),
  phone: varchar("phone", { length: 20 }),
  userType: mysqlEnum("userType", ["driver", "admin", "supplier"]).default("driver").notNull(),
  role: mysqlEnum("role", ["user", "admin"]).default("user").notNull(),
  profilePicture: text("profilePicture"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
  lastSignedIn: timestamp("lastSignedIn").defaultNow().notNull(),
});

export type User = typeof users.$inferSelect;
export type InsertUser = typeof users.$inferInsert;

/**
 * Vehicles table - tracks fleet vehicles
 */
export const vehicles = mysqlTable("vehicles", {
  id: int("id").autoincrement().primaryKey(),
  driverId: int("driverId").notNull(),
  licensePlate: varchar("licensePlate", { length: 20 }).notNull().unique(),
  brand: varchar("brand", { length: 100 }).notNull(),
  model: varchar("model", { length: 100 }).notNull(),
  year: int("year").notNull(),
  color: varchar("color", { length: 50 }),
  mileage: int("mileage").default(0),
  lastServiceDate: timestamp("lastServiceDate"),
  nextServiceDate: timestamp("nextServiceDate"),
  status: mysqlEnum("status", ["active", "maintenance", "inactive"]).default("active"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export type Vehicle = typeof vehicles.$inferSelect;
export type InsertVehicle = typeof vehicles.$inferInsert;

/**
 * Service types - predefined service categories
 */
export const serviceTypes = mysqlTable("serviceTypes", {
  id: int("id").autoincrement().primaryKey(),
  name: varchar("name", { length: 100 }).notNull().unique(),
  description: text("description"),
  icon: varchar("icon", { length: 50 }),
  estimatedDuration: int("estimatedDuration"), // in minutes
  createdAt: timestamp("createdAt").defaultNow().notNull(),
});

export type ServiceType = typeof serviceTypes.$inferSelect;
export type InsertServiceType = typeof serviceTypes.$inferInsert;

/**
 * Suppliers/Workshops - credentialed service providers
 */
export const suppliers = mysqlTable("suppliers", {
  id: int("id").autoincrement().primaryKey(),
  userId: int("userId").notNull(),
  businessName: varchar("businessName", { length: 200 }).notNull(),
  cnpj: varchar("cnpj", { length: 20 }).unique(),
  address: text("address").notNull(),
  city: varchar("city", { length: 100 }).notNull(),
  state: varchar("state", { length: 2 }).notNull(),
  zipCode: varchar("zipCode", { length: 10 }),
  latitude: float("latitude"),
  longitude: float("longitude"),
  phone: varchar("phone", { length: 20 }).notNull(),
  email: varchar("email", { length: 320 }).notNull(),
  website: varchar("website", { length: 255 }),
  specialties: json("specialties"), // JSON array of service type IDs
  operatingHours: json("operatingHours"), // JSON with opening/closing times
  averageRating: decimal("averageRating", { precision: 3, scale: 2 }).default("0"),
  totalReviews: int("totalReviews").default(0),
  isVerified: boolean("isVerified").default(false),
  status: mysqlEnum("status", ["active", "inactive", "suspended"]).default("active"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export type Supplier = typeof suppliers.$inferSelect;
export type InsertSupplier = typeof suppliers.$inferInsert;

/**
 * Service requests - driver requests for maintenance
 */
export const serviceRequests = mysqlTable("serviceRequests", {
  id: int("id").autoincrement().primaryKey(),
  driverId: int("driverId").notNull(),
  vehicleId: int("vehicleId").notNull(),
  serviceTypeId: int("serviceTypeId").notNull(),
  description: text("description").notNull(),
  latitude: float("latitude"),
  longitude: float("longitude"),
  city: varchar("city", { length: 100 }),
  urgencyLevel: mysqlEnum("urgencyLevel", ["low", "medium", "high", "critical"]).default("medium"),
  status: mysqlEnum("status", ["pending", "assigned", "scheduled", "completed", "cancelled"]).default("pending"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export type ServiceRequest = typeof serviceRequests.$inferSelect;
export type InsertServiceRequest = typeof serviceRequests.$inferInsert;

/**
 * Appointments - scheduled service appointments
 */
export const appointments = mysqlTable("appointments", {
  id: int("id").autoincrement().primaryKey(),
  serviceRequestId: int("serviceRequestId").notNull(),
  supplierId: int("supplierId").notNull(),
  driverId: int("driverId").notNull(),
  vehicleId: int("vehicleId").notNull(),
  scheduledDate: datetime("scheduledDate").notNull(),
  estimatedDuration: int("estimatedDuration"), // in minutes
  actualDuration: int("actualDuration"),
  status: mysqlEnum("status", ["pending", "confirmed", "in_progress", "completed", "cancelled", "no_show"]).default("pending"),
  notes: text("notes"),
  reminderSent: boolean("reminderSent").default(false),
  reminderSentAt: timestamp("reminderSentAt"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export type Appointment = typeof appointments.$inferSelect;
export type InsertAppointment = typeof appointments.$inferInsert;

/**
 * Budgets/Quotes - cost estimates for services
 */
export const budgets = mysqlTable("budgets", {
  id: int("id").autoincrement().primaryKey(),
  appointmentId: int("appointmentId").notNull(),
  supplierId: int("supplierId").notNull(),
  driverId: int("driverId").notNull(),
  items: json("items"), // JSON array of {description, quantity, unitPrice, total}
  subtotal: decimal("subtotal", { precision: 10, scale: 2 }).notNull(),
  tax: decimal("tax", { precision: 10, scale: 2 }).default("0"),
  total: decimal("total", { precision: 10, scale: 2 }).notNull(),
  status: mysqlEnum("status", ["pending", "approved", "rejected", "expired"]).default("pending"),
  approvedAt: timestamp("approvedAt"),
  rejectionReason: text("rejectionReason"),
  expiresAt: timestamp("expiresAt"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export type Budget = typeof budgets.$inferSelect;
export type InsertBudget = typeof budgets.$inferInsert;

/**
 * Invoices - fiscal documents for completed services
 */
export const invoices = mysqlTable("invoices", {
  id: int("id").autoincrement().primaryKey(),
  appointmentId: int("appointmentId").notNull(),
  budgetId: int("budgetId"),
  supplierId: int("supplierId").notNull(),
  driverId: int("driverId").notNull(),
  invoiceNumber: varchar("invoiceNumber", { length: 50 }).unique(),
  issueDate: datetime("issueDate").notNull(),
  dueDate: datetime("dueDate"),
  amount: decimal("amount", { precision: 10, scale: 2 }).notNull(),
  status: mysqlEnum("status", ["pending", "paid", "overdue", "cancelled"]).default("pending"),
  documentUrl: text("documentUrl"),
  documentKey: varchar("documentKey", { length: 255 }),
  paymentMethod: mysqlEnum("paymentMethod", ["cash", "credit_card", "debit_card", "bank_transfer", "check"]),
  paidAt: timestamp("paidAt"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export type Invoice = typeof invoices.$inferSelect;
export type InsertInvoice = typeof invoices.$inferInsert;

/**
 * Payments - Stripe payment records
 */
export const payments = mysqlTable("payments", {
  id: int("id").autoincrement().primaryKey(),
  invoiceId: int("invoiceId").notNull(),
  stripePaymentIntentId: varchar("stripePaymentIntentId", { length: 255 }).unique(),
  amount: decimal("amount", { precision: 10, scale: 2 }).notNull(),
  currency: varchar("currency", { length: 3 }).default("BRL"),
  status: mysqlEnum("status", ["pending", "processing", "succeeded", "failed", "cancelled"]).default("pending"),
  paymentMethod: varchar("paymentMethod", { length: 50 }),
  failureReason: text("failureReason"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export type Payment = typeof payments.$inferSelect;
export type InsertPayment = typeof payments.$inferInsert;

/**
 * Reviews/Ratings - driver feedback on suppliers
 */
export const reviews = mysqlTable("reviews", {
  id: int("id").autoincrement().primaryKey(),
  appointmentId: int("appointmentId").notNull(),
  supplierId: int("supplierId").notNull(),
  driverId: int("driverId").notNull(),
  rating: int("rating").notNull(), // 1-5 stars
  title: varchar("title", { length: 200 }),
  comment: text("comment"),
  categories: json("categories"), // JSON with ratings for different aspects
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
});

export type Review = typeof reviews.$inferSelect;
export type InsertReview = typeof reviews.$inferInsert;

/**
 * Notifications - system notifications for users
 */
export const notifications = mysqlTable("notifications", {
  id: int("id").autoincrement().primaryKey(),
  userId: int("userId").notNull(),
  type: mysqlEnum("type", [
    "appointment_confirmed",
    "appointment_reminder",
    "budget_approved",
    "budget_rejected",
    "service_completed",
    "invoice_issued",
    "payment_received",
    "new_request",
    "system_alert"
  ]).notNull(),
  title: varchar("title", { length: 200 }).notNull(),
  message: text("message"),
  relatedEntityId: int("relatedEntityId"),
  relatedEntityType: varchar("relatedEntityType", { length: 50 }),
  isRead: boolean("isRead").default(false),
  readAt: timestamp("readAt"),
  emailSent: boolean("emailSent").default(false),
  emailSentAt: timestamp("emailSentAt"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
});

export type Notification = typeof notifications.$inferSelect;
export type InsertNotification = typeof notifications.$inferInsert;

/**
 * Email logs - track all sent emails
 */
export const emailLogs = mysqlTable("emailLogs", {
  id: int("id").autoincrement().primaryKey(),
  recipientEmail: varchar("recipientEmail", { length: 320 }).notNull(),
  subject: varchar("subject", { length: 255 }).notNull(),
  type: varchar("type", { length: 100 }).notNull(),
  relatedEntityId: int("relatedEntityId"),
  relatedEntityType: varchar("relatedEntityType", { length: 50 }),
  status: mysqlEnum("status", ["pending", "sent", "failed", "bounced"]).default("pending"),
  failureReason: text("failureReason"),
  sentAt: timestamp("sentAt"),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
});

export type EmailLog = typeof emailLogs.$inferSelect;
export type InsertEmailLog = typeof emailLogs.$inferInsert;

/**
 * Audit logs - track important system actions
 */
export const auditLogs = mysqlTable("auditLogs", {
  id: int("id").autoincrement().primaryKey(),
  userId: int("userId"),
  action: varchar("action", { length: 100 }).notNull(),
  entityType: varchar("entityType", { length: 50 }).notNull(),
  entityId: int("entityId"),
  changes: json("changes"),
  ipAddress: varchar("ipAddress", { length: 45 }),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
});

export type AuditLog = typeof auditLogs.$inferSelect;
export type InsertAuditLog = typeof auditLogs.$inferInsert;

// Módulo de multas
export * from "./multas";
