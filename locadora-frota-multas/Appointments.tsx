import { useAuth } from "@/_core/hooks/useAuth";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { trpc } from "@/lib/trpc";
import { Calendar, Clock, MapPin, User, AlertCircle } from "lucide-react";
import { format } from "date-fns";
import { ptBR } from "date-fns/locale";

const statusColors: Record<string, string> = {
  pending: "badge-warning",
  confirmed: "badge-info",
  in_progress: "badge-info",
  completed: "badge-success",
  cancelled: "badge-error",
  no_show: "badge-error",
};

const statusLabels: Record<string, string> = {
  pending: "Pendente",
  confirmed: "Confirmado",
  in_progress: "Em Andamento",
  completed: "Concluído",
  cancelled: "Cancelado",
  no_show: "Não Compareceu",
};

export default function Appointments() {
  const { user } = useAuth();
  const appointmentsQuery = trpc.appointments.list.useQuery({
    status: undefined,
    dateRange: undefined,
  });

  return (
    <div className="min-h-screen bg-background">
      <div className="container py-12">
        <div className="mb-8">
          <h1 className="text-4xl font-bold">Meus Agendamentos</h1>
          <p className="mt-2 text-muted-foreground">
            Acompanhe todos os seus agendamentos de serviço
          </p>
        </div>

        {appointmentsQuery.isLoading ? (
          <div className="flex justify-center py-12">
            <div className="text-muted-foreground">Carregando agendamentos...</div>
          </div>
        ) : appointmentsQuery.data && appointmentsQuery.data.length > 0 ? (
          <div className="space-y-4">
            {appointmentsQuery.data.map((appointment) => (
              <Card key={appointment.id} className="card-elegant">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div className="flex-1 space-y-3">
                    <div className="flex items-center gap-3">
                      <Badge className={statusColors[appointment.status as keyof typeof statusColors]}>
                        {statusLabels[appointment.status as keyof typeof statusLabels]}
                      </Badge>
                      {appointment.status === "pending" && (
                        <span className="flex items-center gap-1 text-sm text-yellow-600 dark:text-yellow-400">
                          <AlertCircle className="h-4 w-4" />
                          Aguardando confirmação
                        </span>
                      )}
                    </div>

                    <div className="space-y-2">
                      <div className="flex items-center gap-2 text-sm">
                        <Calendar className="h-4 w-4 text-accent" />
                        <span>
                          {format(new Date(appointment.scheduledDate), "dd 'de' MMMM 'de' yyyy", {
                            locale: ptBR,
                          })}
                        </span>
                      </div>

                      <div className="flex items-center gap-2 text-sm">
                        <Clock className="h-4 w-4 text-accent" />
                        <span>
                          {format(new Date(appointment.scheduledDate), "HH:mm", {
                            locale: ptBR,
                          })}
                        </span>
                        {appointment.estimatedDuration && (
                          <span className="text-muted-foreground">
                            (~{appointment.estimatedDuration} min)
                          </span>
                        )}
                      </div>

                      {appointment.notes && (
                        <div className="mt-2 rounded-lg bg-muted p-2 text-sm">
                          <p className="font-medium text-muted-foreground">Observações:</p>
                          <p className="mt-1">{appointment.notes}</p>
                        </div>
                      )}
                    </div>
                  </div>

                  <div className="flex gap-2 sm:flex-col">
                    <button className="btn-secondary text-sm">
                      Detalhes
                    </button>
                    {appointment.status === "pending" && (
                      <button className="btn-ghost text-sm text-destructive hover:bg-destructive/10">
                        Cancelar
                      </button>
                    )}
                  </div>
                </div>
              </Card>
            ))}
          </div>
        ) : (
          <Card className="card-elegant">
            <div className="flex flex-col items-center justify-center py-12 text-center">
              <Calendar className="mb-4 h-12 w-12 text-muted-foreground" />
              <h3 className="text-lg font-semibold">Nenhum agendamento</h3>
              <p className="mt-2 text-muted-foreground">
                Você ainda não tem agendamentos. Solicite um serviço para começar.
              </p>
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}
