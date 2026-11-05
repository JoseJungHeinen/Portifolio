import { eq, and, gte, lte, desc, asc, inArray } from "drizzle-orm";
import { drizzle } from "drizzle-orm/mysql2";
import {
  InsertUser,
  users,
  vehicles,
  suppliers,
  serviceRequests,
  appointments,
  budgets,
  invoices,
  payments,
  reviews,
  notifications,
  emailLogs,
  serviceTypes,
} from "../drizzle/schema";
import { ENV } from "./_core/env";

let _db: ReturnType<typeof drizzle> | null = null;

export async function getDb() {
  if (!_db && process.env.DATABASE_URL) {
    try {
      _db = drizzle(process.env.DATABASE_URL);
    } catch (error) {
      console.warn("[Database] Failed to connect:", error);
      _db = null;
    }
  }
  return _db;
}

export async function upsertUser(user: InsertUser): Promise<void> {
  if (!user.openId) {
    throw new Error("User openId is required for upsert");
  }

  const db = await getDb();
  if (!db) {
    console.warn("[Database] Cannot upsert user: database not available");
    return;
  }

  try {
    const values: InsertUser = {
      openId: user.openId,
    };
    const updateSet: Record<string, unknown> = {};

    const textFields = ["name", "email"] as const;
    type TextField = (typeof textFields)[number];

    const assignNullable = (field: TextField) => {
      const value = user[field];
      if (value === undefined) return;
      const normalized = value ?? null;
      values[field] = normalized;
      updateSet[field] = normalized;
    };

    textFields.forEach(assignNullable);

    if (user.lastSignedIn !== undefined) {
      values.lastSignedIn = user.lastSignedIn;
      updateSet.lastSignedIn = user.lastSignedIn;
    }
    if (user.role !== undefined) {
      values.role = user.role;
      updateSet.role = user.role;
    } else if (user.openId === ENV.ownerOpenId) {
      values.role = "admin";
      updateSet.role = "admin";
    }

    if (!values.lastSignedIn) {
      values.lastSignedIn = new Date();
    }

    if (Object.keys(updateSet).length === 0) {
      updateSet.lastSignedIn = new Date();
    }

    await db.insert(users).values(values).onDuplicateKeyUpdate({
      set: updateSet,
    });
  } catch (error) {
    console.error("[Database] Failed to upsert user:", error);
    throw error;
  }
}

export async function getUserByOpenId(openId: string) {
  const db = await getDb();
  if (!db) {
    console.warn("[Database] Cannot get user: database not available");
    return undefined;
  }

  const result = await db
    .select()
    .from(users)
    .where(eq(users.openId, openId))
    .limit(1);

  return result.length > 0 ? result[0] : undefined;
}

// Vehicle queries
export async function getUserVehicles(userId: number) {
  const db = await getDb();
  if (!db) return [];

  return db.select().from(vehicles).where(eq(vehicles.driverId, userId));
}

export async function createVehicle(data: typeof vehicles.$inferInsert) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const result = await db.insert(vehicles).values(data);
  return result;
}

// Service request queries
export async function createServiceRequest(data: typeof serviceRequests.$inferInsert) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const result = await db.insert(serviceRequests).values(data);
  return result;
}

export async function getServiceRequests(filters?: {
  status?: string;
  city?: string;
  urgencyLevel?: string;
}) {
  const db = await getDb();
  if (!db) return [];

  const conditions = [];

  if (filters?.status) {
    conditions.push(eq(serviceRequests.status, filters.status as any));
  }
  if (filters?.city) {
    conditions.push(eq(serviceRequests.city, filters.city));
  }
  if (filters?.urgencyLevel) {
    conditions.push(eq(serviceRequests.urgencyLevel, filters.urgencyLevel as any));
  }

  if (conditions.length > 0) {
    return db
      .select()
      .from(serviceRequests)
      .where(and(...conditions))
      .orderBy(desc(serviceRequests.createdAt));
  }

  return db.select().from(serviceRequests).orderBy(desc(serviceRequests.createdAt));
}

export async function getServiceRequestById(id: number) {
  const db = await getDb();
  if (!db) return null;

  const result = await db
    .select()
    .from(serviceRequests)
    .where(eq(serviceRequests.id, id))
    .limit(1);

  return result.length > 0 ? result[0] : null;
}

// Supplier queries
export async function getSuppliersByCity(city: string, specialtyId?: number) {
  const db = await getDb();
  if (!db) return [];

  const conditions = [eq(suppliers.city, city), eq(suppliers.status, "active")];

  let query = db.select().from(suppliers).where(and(...conditions));

  return query.orderBy(desc(suppliers.averageRating));
}

export async function getSupplierById(id: number) {
  const db = await getDb();
  if (!db) return null;

  const result = await db
    .select()
    .from(suppliers)
    .where(eq(suppliers.id, id))
    .limit(1);

  return result.length > 0 ? result[0] : null;
}

export async function createSupplier(data: typeof suppliers.$inferInsert) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const result = await db.insert(suppliers).values(data);
  return result;
}

// Appointment queries
export async function createAppointment(data: typeof appointments.$inferInsert) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const result = await db.insert(appointments).values(data);
  return result;
}

export async function getAppointments(filters?: {
  status?: string;
  supplierId?: number;
  driverId?: number;
  dateRange?: { start: Date; end: Date };
}) {
  const db = await getDb();
  if (!db) return [];

  const conditions = [];

  if (filters?.status) {
    conditions.push(eq(appointments.status, filters.status as any));
  }
  if (filters?.supplierId) {
    conditions.push(eq(appointments.supplierId, filters.supplierId));
  }
  if (filters?.driverId) {
    conditions.push(eq(appointments.driverId, filters.driverId));
  }
  if (filters?.dateRange) {
    conditions.push(gte(appointments.scheduledDate, filters.dateRange.start));
    conditions.push(lte(appointments.scheduledDate, filters.dateRange.end));
  }

  if (conditions.length > 0) {
    return db
      .select()
      .from(appointments)
      .where(and(...conditions))
      .orderBy(asc(appointments.scheduledDate));
  }

  return db.select().from(appointments).orderBy(asc(appointments.scheduledDate));
}

export async function getAppointmentById(id: number) {
  const db = await getDb();
  if (!db) return null;

  const result = await db
    .select()
    .from(appointments)
    .where(eq(appointments.id, id))
    .limit(1);

  return result.length > 0 ? result[0] : null;
}

export async function updateAppointmentStatus(id: number, status: string) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  return db.update(appointments).set({ status: status as any }).where(eq(appointments.id, id));
}

// Budget queries
export async function createBudget(data: typeof budgets.$inferInsert) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const result = await db.insert(budgets).values(data);
  return result;
}

export async function getBudgetsByAppointment(appointmentId: number) {
  const db = await getDb();
  if (!db) return [];

  return db.select().from(budgets).where(eq(budgets.appointmentId, appointmentId));
}

export async function getBudgetById(id: number) {
  const db = await getDb();
  if (!db) return null;

  const result = await db.select().from(budgets).where(eq(budgets.id, id)).limit(1);

  return result.length > 0 ? result[0] : null;
}

export async function updateBudgetStatus(id: number, status: string, approvedAt?: Date) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const updateData: any = { status: status as any };
  if (approvedAt) {
    updateData.approvedAt = approvedAt;
  }

  return db.update(budgets).set(updateData).where(eq(budgets.id, id));
}

// Invoice queries
export async function createInvoice(data: typeof invoices.$inferInsert) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const result = await db.insert(invoices).values(data);
  return result;
}

export async function getInvoicesBySupplier(supplierId: number) {
  const db = await getDb();
  if (!db) return [];

  return db
    .select()
    .from(invoices)
    .where(eq(invoices.supplierId, supplierId))
    .orderBy(desc(invoices.createdAt));
}

export async function getInvoiceById(id: number) {
  const db = await getDb();
  if (!db) return null;

  const result = await db.select().from(invoices).where(eq(invoices.id, id)).limit(1);

  return result.length > 0 ? result[0] : null;
}

export async function updateInvoiceStatus(id: number, status: string, paidAt?: Date) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const updateData: any = { status: status as any };
  if (paidAt) {
    updateData.paidAt = paidAt;
  }

  return db.update(invoices).set(updateData).where(eq(invoices.id, id));
}

// Payment queries
export async function createPayment(data: typeof payments.$inferInsert) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const result = await db.insert(payments).values(data);
  return result;
}

export async function getPaymentByStripeId(stripeId: string) {
  const db = await getDb();
  if (!db) return null;

  const result = await db
    .select()
    .from(payments)
    .where(eq(payments.stripePaymentIntentId, stripeId))
    .limit(1);

  return result.length > 0 ? result[0] : null;
}

export async function updatePaymentStatus(id: number, status: string) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  return db.update(payments).set({ status: status as any }).where(eq(payments.id, id));
}

// Review queries
export async function createReview(data: typeof reviews.$inferInsert) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const result = await db.insert(reviews).values(data);
  return result;
}

export async function getSupplierReviews(supplierId: number) {
  const db = await getDb();
  if (!db) return [];

  return db
    .select()
    .from(reviews)
    .where(eq(reviews.supplierId, supplierId))
    .orderBy(desc(reviews.createdAt));
}

// Notification queries
export async function createNotification(data: typeof notifications.$inferInsert) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const result = await db.insert(notifications).values(data);
  return result;
}

export async function getUserNotifications(userId: number) {
  const db = await getDb();
  if (!db) return [];

  return db
    .select()
    .from(notifications)
    .where(eq(notifications.userId, userId))
    .orderBy(desc(notifications.createdAt));
}

export async function markNotificationAsRead(id: number) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  return db
    .update(notifications)
    .set({ isRead: true, readAt: new Date() })
    .where(eq(notifications.id, id));
}

// Email log queries
export async function logEmail(data: typeof emailLogs.$inferInsert) {
  const db = await getDb();
  if (!db) throw new Error("Database not available");

  const result = await db.insert(emailLogs).values(data);
  return result;
}

// Service types queries
export async function getServiceTypes() {
  const db = await getDb();
  if (!db) return [];

  return db.select().from(serviceTypes).orderBy(asc(serviceTypes.name));
}

export async function getServiceTypeById(id: number) {
  const db = await getDb();
  if (!db) return null;

  const result = await db
    .select()
    .from(serviceTypes)
    .where(eq(serviceTypes.id, id))
    .limit(1);

  return result.length > 0 ? result[0] : null;
}
