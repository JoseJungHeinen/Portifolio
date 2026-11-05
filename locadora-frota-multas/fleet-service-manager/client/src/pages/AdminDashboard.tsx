import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { trpc } from "@/lib/trpc";
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
} from "recharts";
import { Calendar, Truck, Users, TrendingUp, AlertCircle, CheckCircle } from "lucide-react";

const COLORS = ["#3b82f6", "#10b981", "#f59e0b", "#ef4444", "#8b5cf6"];

const mockAppointmentData = [
  { month: "Jan", completed: 45, pending: 12, cancelled: 3 },
  { month: "Fev", completed: 52, pending: 15, cancelled: 2 },
  { month: "Mar", completed: 48, pending: 10, cancelled: 4 },
  { month: "Abr", completed: 61, pending: 8, cancelled: 1 },
  { month: "Mai", completed: 55, pending: 14, cancelled: 3 },
  { month: "Jun", completed: 67, pending: 11, cancelled: 2 },
];

const mockStatusData = [
  { name: "Concluído", value: 328, color: "#10b981" },
  { name: "Pendente", value: 70, color: "#f59e0b" },
  { name: "Cancelado", value: 15, color: "#ef4444" },
  { name: "Em Andamento", value: 32, color: "#3b82f6" },
];

export default function AdminDashboard() {
  const appointmentsQuery = trpc.appointments.list.useQuery({
    status: undefined,
    dateRange: undefined,
  });

  const stats = [
    {
      title: "Total de Agendamentos",
      value: "445",
      icon: Calendar,
      color: "text-blue-600",
      bgColor: "bg-blue-100 dark:bg-blue-900/20",
    },
    {
      title: "Motoristas Ativos",
      value: "128",
      icon: Users,
      color: "text-green-600",
      bgColor: "bg-green-100 dark:bg-green-900/20",
    },
    {
      title: "Fornecedores",
      value: "42",
      icon: Truck,
      color: "text-purple-600",
      bgColor: "bg-purple-100 dark:bg-purple-900/20",
    },
    {
      title: "Taxa de Conclusão",
      value: "94%",
      icon: TrendingUp,
      color: "text-orange-600",
      bgColor: "bg-orange-100 dark:bg-orange-900/20",
    },
  ];

  return (
    <div className="min-h-screen bg-background">
      <div className="container py-12">
        <div className="mb-8">
          <h1 className="text-4xl font-bold">Dashboard Administrativo</h1>
          <p className="mt-2 text-muted-foreground">
            Acompanhe as métricas principais da sua frota
          </p>
        </div>

        {/* Stats Grid */}
        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-4 mb-8">
          {stats.map((stat) => {
            const Icon = stat.icon;
            return (
              <Card key={stat.title} className="card-elegant">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">{stat.title}</p>
                    <p className="mt-2 text-3xl font-bold">{stat.value}</p>
                  </div>
                  <div className={`${stat.bgColor} rounded-lg p-3`}>
                    <Icon className={`h-6 w-6 ${stat.color}`} />
                  </div>
                </div>
              </Card>
            );
          })}
        </div>

        {/* Charts */}
        <div className="grid gap-8 lg:grid-cols-3">
          {/* Line Chart */}
          <Card className="card-elegant lg:col-span-2">
            <h3 className="font-semibold mb-4">Agendamentos por Mês</h3>
            <ResponsiveContainer width="100%" height={300}>
              <LineChart data={mockAppointmentData}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                <XAxis stroke="var(--muted-foreground)" />
                <YAxis stroke="var(--muted-foreground)" />
                <Tooltip
                  contentStyle={{
                    backgroundColor: "var(--card)",
                    border: "1px solid var(--border)",
                  }}
                />
                <Legend />
                <Line
                  type="monotone"
                  dataKey="completed"
                  stroke="#10b981"
                  name="Concluído"
                />
                <Line
                  type="monotone"
                  dataKey="pending"
                  stroke="#f59e0b"
                  name="Pendente"
                />
                <Line
                  type="monotone"
                  dataKey="cancelled"
                  stroke="#ef4444"
                  name="Cancelado"
                />
              </LineChart>
            </ResponsiveContainer>
          </Card>

          {/* Pie Chart */}
          <Card className="card-elegant">
            <h3 className="font-semibold mb-4">Status dos Agendamentos</h3>
            <ResponsiveContainer width="100%" height={300}>
              <PieChart>
                <Pie
                  data={mockStatusData}
                  cx="50%"
                  cy="50%"
                  labelLine={false}
                  label={({ name, value }) => `${name}: ${value}`}
                  outerRadius={80}
                  fill="#8884d8"
                  dataKey="value"
                >
                  {mockStatusData.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={entry.color} />
                  ))}
                </Pie>
                <Tooltip />
              </PieChart>
            </ResponsiveContainer>
          </Card>
        </div>

        {/* Recent Appointments */}
        <Card className="card-elegant mt-8">
          <h3 className="font-semibold mb-4">Agendamentos Recentes</h3>
          {appointmentsQuery.isLoading ? (
            <div className="text-center py-8 text-muted-foreground">
              Carregando agendamentos...
            </div>
          ) : appointmentsQuery.data && appointmentsQuery.data.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b border-border">
                  <tr>
                    <th className="text-left py-3 px-4 font-semibold">ID</th>
                    <th className="text-left py-3 px-4 font-semibold">Data</th>
                    <th className="text-left py-3 px-4 font-semibold">Status</th>
                    <th className="text-left py-3 px-4 font-semibold">Ações</th>
                  </tr>
                </thead>
                <tbody>
                  {appointmentsQuery.data.slice(0, 5).map((appointment) => (
                    <tr key={appointment.id} className="border-b border-border hover:bg-muted/50">
                      <td className="py-3 px-4">#{appointment.id}</td>
                      <td className="py-3 px-4">
                        {new Date(appointment.scheduledDate).toLocaleDateString("pt-BR")}
                      </td>
                      <td className="py-3 px-4">
                        <Badge
                          className={
                            {
                              pending: "badge-warning",
                              confirmed: "badge-info",
                              in_progress: "badge-info",
                              completed: "badge-success",
                              cancelled: "badge-error",
                              no_show: "badge-error",
                            }[appointment.status as string] || "badge-info"
                          }
                        >
                          {appointment.status}
                        </Badge>
                      </td>
                      <td className="py-3 px-4">
                        <button className="text-accent hover:underline text-sm">
                          Detalhes
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="text-center py-8 text-muted-foreground">
              Nenhum agendamento encontrado
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}
