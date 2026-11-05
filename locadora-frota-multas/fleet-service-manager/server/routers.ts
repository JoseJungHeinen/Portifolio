import { z } from "zod";
import { publicProcedure, protectedProcedure, router } from "./_core/trpc";
import * as db from "./db";
import { TRPCError } from "@trpc/server";

// Validation schemas
const vehicleSchema = z.object({
  licensePlate: z.string().min(1),
  brand: z.string().min(1),
  model: z.string().min(1),
  year: z.number().int(),
  color: z.string().optional(),
});

const serviceRequestSchema = z.object({
  vehicleId: z.number(),
  serviceTypeId: z.number(),
  description: z.string().min(10),
  latitude: z.number().optional(),
  longitude: z.number().optional(),
  city: z.string(),
  urgencyLevel: z.enum(["low", "medium", "high", "critical"]),
});

const appointmentSchema = z.object({
  serviceRequestId: z.number(),
  supplierId: z.number(),
  vehicleId: z.number(),
  scheduledDate: z.date(),
  estimatedDuration: z.number().optional(),
  notes: z.string().optional(),
});

const budgetSchema = z.object({
  appointmentId: z.number(),
  supplierId: z.number(),
  items: z.array(
    z.object({
      description: z.string(),
      quantity: z.number(),
      unitPrice: z.number(),
      total: z.number(),
    })
  ),
  subtotal: z.number(),
  tax: z.number().optional(),
  total: z.number(),
});

const reviewSchema = z.object({
  appointmentId: z.number(),
  supplierId: z.number(),
  rating: z.number().min(1).max(5),
  title: z.string().optional(),
  comment: z.string().optional(),
  categories: z.record(z.string(), z.number()).optional(),
});

// Vehicles router
const vehiclesRouter = router({
  list: protectedProcedure.query(async ({ ctx }) => {
    return db.getUserVehicles(ctx.user.id);
  }),

  create: protectedProcedure.input(vehicleSchema).mutation(async ({ ctx, input }) => {
    const result = await db.createVehicle({
      driverId: ctx.user.id,
      ...input,
    });
    return result;
  }),
});

// Service requests router
const serviceRequestsRouter = router({
  create: protectedProcedure.input(serviceRequestSchema).mutation(async ({ ctx, input }) => {
    const result = await db.createServiceRequest({
      driverId: ctx.user.id,
      ...input,
      status: "pending",
    });
    return result;
  }),

  list: protectedProcedure
    .input(
      z.object({
        status: z.string().optional(),
        city: z.string().optional(),
      })
    )
    .query(async ({ ctx, input }) => {
      // Drivers see only their requests, admins see all
      return db.getServiceRequests({
        status: input.status,
        city: input.city,
        urgencyLevel: undefined,
      });
    }),

  getById: protectedProcedure.input(z.number()).query(async ({ input }) => {
    return db.getServiceRequestById(input);
  }),
});

// Suppliers router
const suppliersRouter = router({
  list: publicProcedure
    .input(
      z.object({
        city: z.string(),
        serviceTypeId: z.number().optional(),
      })
    )
    .query(async ({ input }) => {
      return db.getSuppliersByCity(input.city, input.serviceTypeId);
    }),

  getById: publicProcedure.input(z.number()).query(async ({ input }) => {
    return db.getSupplierById(input);
  }),

  register: protectedProcedure
    .input(
      z.object({
        businessName: z.string().min(1),
        cnpj: z.string().optional(),
        address: z.string().min(1),
        city: z.string().min(1),
        state: z.string().length(2),
        zipCode: z.string().optional(),
        latitude: z.number().optional(),
        longitude: z.number().optional(),
        phone: z.string().min(1),
        email: z.string().email(),
        website: z.string().optional(),
        specialties: z.array(z.number()).optional(),
        operatingHours: z.record(z.string(), z.any()).optional(),
      })
    )
    .mutation(async ({ ctx, input }) => {
      if (ctx.user.role !== "admin" && ctx.user.userType !== "supplier") {
        throw new TRPCError({ code: "FORBIDDEN" });
      }

      const result = await db.createSupplier({
        userId: ctx.user.id,
        ...input,
        specialties: input.specialties ? JSON.stringify(input.specialties) : null,
        operatingHours: input.operatingHours ? JSON.stringify(input.operatingHours) : null,
      } as any);

      return result;
    }),
});

// Appointments router
const appointmentsRouter = router({
  create: protectedProcedure.input(appointmentSchema).mutation(async ({ ctx, input }) => {
    const result = await db.createAppointment({
      ...input,
      driverId: ctx.user.id,
      status: "pending",
    });
    return result;
  }),

  list: protectedProcedure
    .input(
      z.object({
        status: z.string().optional(),
        dateRange: z
          .object({
            start: z.date(),
            end: z.date(),
          })
          .optional(),
      })
    )
    .query(async ({ ctx, input }) => {
      return db.getAppointments({
        status: input.status,
        dateRange: input.dateRange,
        driverId: ctx.user.role === "admin" ? undefined : ctx.user.id,
      });
    }),

  getById: protectedProcedure.input(z.number()).query(async ({ input }) => {
    return db.getAppointmentById(input);
  }),

  updateStatus: protectedProcedure
    .input(
      z.object({
        id: z.number(),
        status: z.enum(["pending", "confirmed", "in_progress", "completed", "cancelled", "no_show"]),
      })
    )
    .mutation(async ({ ctx, input }) => {
      if (ctx.user.role !== "admin") {
        throw new TRPCError({ code: "FORBIDDEN" });
      }

      return db.updateAppointmentStatus(input.id, input.status);
    }),
});

// Budgets router
const budgetsRouter = router({
  create: protectedProcedure.input(budgetSchema).mutation(async ({ ctx, input }) => {
    const result = await db.createBudget({
      ...input,
      items: JSON.stringify(input.items),
      status: "pending",
    } as any);
    return result;
  }),

  getByAppointment: protectedProcedure.input(z.number()).query(async ({ input }) => {
    return db.getBudgetsByAppointment(input);
  }),

  getById: protectedProcedure.input(z.number()).query(async ({ input }) => {
    return db.getBudgetById(input);
  }),

  approve: protectedProcedure
    .input(z.number())
    .mutation(async ({ ctx, input }) => {
      if (ctx.user.role !== "admin") {
        throw new TRPCError({ code: "FORBIDDEN" });
      }

      return db.updateBudgetStatus(input, "approved", new Date());
    }),

  reject: protectedProcedure
    .input(
      z.object({
        id: z.number(),
        reason: z.string(),
      })
    )
    .mutation(async ({ ctx, input }) => {
      if (ctx.user.role !== "admin") {
        throw new TRPCError({ code: "FORBIDDEN" });
      }

      const budget = await db.getBudgetById(input.id);
      if (!budget) {
        throw new TRPCError({ code: "NOT_FOUND" });
      }

      return db.updateBudgetStatus(input.id, "rejected");
    }),
});

// Invoices router
const invoicesRouter = router({
  getBySupplier: protectedProcedure.input(z.number()).query(async ({ input }) => {
    return db.getInvoicesBySupplier(input);
  }),

  getById: protectedProcedure.input(z.number()).query(async ({ input }) => {
    return db.getInvoiceById(input);
  }),

  updateStatus: protectedProcedure
    .input(
      z.object({
        id: z.number(),
        status: z.enum(["pending", "paid", "overdue", "cancelled"]),
      })
    )
    .mutation(async ({ ctx, input }) => {
      if (ctx.user.role !== "admin") {
        throw new TRPCError({ code: "FORBIDDEN" });
      }

      const paidAt = input.status === "paid" ? new Date() : undefined;
      return db.updateInvoiceStatus(input.id, input.status, paidAt);
    }),
});

// Payments router
const paymentsRouter = router({
  create: protectedProcedure
    .input(
      z.object({
        invoiceId: z.number(),
        amount: z.number(),
        paymentMethod: z.string().optional(),
      })
    )
    .mutation(async ({ ctx, input }) => {
      const result = await db.createPayment({
        invoiceId: input.invoiceId,
        amount: input.amount,
        paymentMethod: input.paymentMethod,
        status: "pending",
        currency: "BRL",
      } as any);
      return result;
    }),

  updateStatus: protectedProcedure
    .input(
      z.object({
        id: z.number(),
        status: z.enum(["pending", "processing", "succeeded", "failed", "cancelled"]),
      })
    )
    .mutation(async ({ ctx, input }) => {
      if (ctx.user.role !== "admin") {
        throw new TRPCError({ code: "FORBIDDEN" });
      }

      return db.updatePaymentStatus(input.id, input.status);
    }),
});

// Reviews router
const reviewsRouter = router({
  create: protectedProcedure.input(reviewSchema).mutation(async ({ ctx, input }) => {
    const result = await db.createReview({
      ...input,
      driverId: ctx.user.id,
      categories: input.categories ? JSON.stringify(input.categories) : null,
    } as any);
    return result;
  }),

  getSupplierReviews: publicProcedure.input(z.number()).query(async ({ input }) => {
    return db.getSupplierReviews(input);
  }),
});

// Notifications router
const notificationsRouter = router({
  list: protectedProcedure.query(async ({ ctx }) => {
    return db.getUserNotifications(ctx.user.id);
  }),

  markAsRead: protectedProcedure.input(z.number()).mutation(async ({ input }) => {
    return db.markNotificationAsRead(input);
  }),
});

// Service types router
const serviceTypesRouter = router({
  list: publicProcedure.query(async () => {
    return db.getServiceTypes();
  }),

  getById: publicProcedure.input(z.number()).query(async ({ input }) => {
    return db.getServiceTypeById(input);
  }),
});

// Main app router
export const appRouter = router({
  system: router({}), // Placeholder for system router
  auth: router({
    me: publicProcedure.query((opts) => opts.ctx.user),
    logout: publicProcedure.mutation(({ ctx }) => {
      const { COOKIE_NAME } = require("@shared/const");
      const { getSessionCookieOptions } = require("./_core/cookies");
      const cookieOptions = getSessionCookieOptions(ctx.req);
      ctx.res.clearCookie(COOKIE_NAME, { ...cookieOptions, maxAge: -1 });
      return { success: true } as const;
    }),
  }),

  vehicles: vehiclesRouter,
  serviceRequests: serviceRequestsRouter,
  suppliers: suppliersRouter,
  appointments: appointmentsRouter,
  budgets: budgetsRouter,
  invoices: invoicesRouter,
  payments: paymentsRouter,
  reviews: reviewsRouter,
  notifications: notificationsRouter,
  serviceTypes: serviceTypesRouter,
});

export type AppRouter = typeof appRouter;
