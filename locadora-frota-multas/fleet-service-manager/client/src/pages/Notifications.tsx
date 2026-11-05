import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { trpc } from "@/lib/trpc";
import { Bell, CheckCircle, AlertCircle, Info } from "lucide-react";
import { format } from "date-fns";
import { ptBR } from "date-fns/locale";

const notificationIcons: Record<string, React.ReactNode> = {
  appointment_confirmed: <CheckCircle className="h-5 w-5 text-green-600" />,
  appointment_reminder: <AlertCircle className="h-5 w-5 text-yellow-600" />,
  budget_approved: <CheckCircle className="h-5 w-5 text-green-600" />,
  budget_rejected: <AlertCircle className="h-5 w-5 text-red-600" />,
  service_completed: <CheckCircle className="h-5 w-5 text-green-600" />,
  invoice_issued: <Info className="h-5 w-5 text-blue-600" />,
  payment_received: <CheckCircle className="h-5 w-5 text-green-600" />,
  new_request: <Info className="h-5 w-5 text-blue-600" />,
  system_alert: <AlertCircle className="h-5 w-5 text-red-600" />,
};

export default function Notifications() {
  const notificationsQuery = trpc.notifications.list.useQuery();
  const markAsReadMutation = trpc.notifications.markAsRead.useMutation();

  const handleMarkAsRead = async (id: number) => {
    try {
      await markAsReadMutation.mutateAsync(id);
      notificationsQuery.refetch();
    } catch (error) {
      console.error("Erro ao marcar notificação como lida");
    }
  };

  return (
    <div className="min-h-screen bg-background">
      <div className="container py-12">
        <div className="mb-8">
          <h1 className="text-4xl font-bold">Notificações</h1>
          <p className="mt-2 text-muted-foreground">
            Acompanhe todas as atualizações sobre seus agendamentos e serviços
          </p>
        </div>

        {notificationsQuery.isLoading ? (
          <div className="flex justify-center py-12">
            <div className="text-muted-foreground">Carregando notificações...</div>
          </div>
        ) : notificationsQuery.data && notificationsQuery.data.length > 0 ? (
          <div className="space-y-3">
            {notificationsQuery.data.map((notification) => (
              <Card
                key={notification.id}
                className={`card-elegant cursor-pointer transition-all ${
                  !notification.isRead ? "border-accent/50 bg-accent/5" : ""
                }`}
                onClick={() => !notification.isRead && handleMarkAsRead(notification.id)}
              >
                <div className="flex gap-4">
                  <div className="flex-shrink-0">
                    {notificationIcons[notification.type] || (
                      <Bell className="h-5 w-5 text-muted-foreground" />
                    )}
                  </div>

                  <div className="flex-1 min-w-0">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <h3 className="font-semibold">{notification.title}</h3>
                        {notification.message && (
                          <p className="mt-1 text-sm text-muted-foreground">
                            {notification.message}
                          </p>
                        )}
                      </div>
                      {!notification.isRead && (
                        <Badge className="badge-info flex-shrink-0">Novo</Badge>
                      )}
                    </div>

                    <div className="mt-2 text-xs text-muted-foreground">
                      {format(new Date(notification.createdAt), "dd 'de' MMMM 'às' HH:mm", {
                        locale: ptBR,
                      })}
                    </div>
                  </div>
                </div>
              </Card>
            ))}
          </div>
        ) : (
          <Card className="card-elegant">
            <div className="flex flex-col items-center justify-center py-12 text-center">
              <Bell className="mb-4 h-12 w-12 text-muted-foreground" />
              <h3 className="text-lg font-semibold">Nenhuma notificação</h3>
              <p className="mt-2 text-muted-foreground">
                Você está em dia! Nenhuma notificação pendente.
              </p>
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}
