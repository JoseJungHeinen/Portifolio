import { useAuth } from "@/_core/hooks/useAuth";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { trpc } from "@/lib/trpc";
import { toast } from "sonner";
import { useState } from "react";
import { Loader2, MapPin } from "lucide-react";

export default function ServiceRequest() {
  const { user } = useAuth();
  const [selectedVehicle, setSelectedVehicle] = useState<string>("");
  const [selectedService, setSelectedService] = useState<string>("");
  const [description, setDescription] = useState("");
  const [urgency, setUrgency] = useState<"low" | "medium" | "high" | "critical">("medium");
  const [city, setCity] = useState("");

  const vehiclesQuery = trpc.vehicles.list.useQuery();
  const serviceTypesQuery = trpc.serviceTypes.list.useQuery();
  const createRequestMutation = trpc.serviceRequests.create.useMutation();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!selectedVehicle || !selectedService || !description || !city) {
      toast.error("Preencha todos os campos obrigatórios");
      return;
    }

    try {
      await createRequestMutation.mutateAsync({
        vehicleId: parseInt(selectedVehicle),
        serviceTypeId: parseInt(selectedService),
        description,
        urgencyLevel: urgency,
        city,
        latitude: undefined,
        longitude: undefined,
      });

      toast.success("Solicitação criada com sucesso!");
      setDescription("");
      setSelectedVehicle("");
      setSelectedService("");
      setCity("");
      setUrgency("medium");
    } catch (error) {
      toast.error("Erro ao criar solicitação");
    }
  };

  return (
    <div className="min-h-screen bg-background">
      <div className="container py-12">
        <div className="mb-8">
          <h1 className="text-4xl font-bold">Solicitar Serviço</h1>
          <p className="mt-2 text-muted-foreground">
            Preencha o formulário abaixo para solicitar um serviço de manutenção
          </p>
        </div>

        <div className="grid gap-8 lg:grid-cols-3">
          <div className="lg:col-span-2">
            <Card className="card-elegant">
              <form onSubmit={handleSubmit} className="space-y-6">
                <div className="space-y-2">
                  <Label htmlFor="vehicle">Veículo *</Label>
                  <Select value={selectedVehicle} onValueChange={setSelectedVehicle}>
                    <SelectTrigger>
                      <SelectValue placeholder="Selecione um veículo" />
                    </SelectTrigger>
                    <SelectContent>
                      {vehiclesQuery.data?.map((vehicle) => (
                        <SelectItem key={vehicle.id} value={vehicle.id.toString()}>
                          {vehicle.brand} {vehicle.model} - {vehicle.licensePlate}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="service">Tipo de Serviço *</Label>
                  <Select value={selectedService} onValueChange={setSelectedService}>
                    <SelectTrigger>
                      <SelectValue placeholder="Selecione o tipo de serviço" />
                    </SelectTrigger>
                    <SelectContent>
                      {serviceTypesQuery.data?.map((service) => (
                        <SelectItem key={service.id} value={service.id.toString()}>
                          {service.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="description">Descrição do Problema *</Label>
                  <Textarea
                    id="description"
                    placeholder="Descreva o problema ou o serviço necessário"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    className="min-h-32"
                  />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <Label htmlFor="city">Cidade *</Label>
                    <Input
                      id="city"
                      placeholder="Sua cidade"
                      value={city}
                      onChange={(e) => setCity(e.target.value)}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="urgency">Urgência *</Label>
                    <Select value={urgency} onValueChange={(v) => setUrgency(v as any)}>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="low">Baixa</SelectItem>
                        <SelectItem value="medium">Média</SelectItem>
                        <SelectItem value="high">Alta</SelectItem>
                        <SelectItem value="critical">Crítica</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                <Button
                  type="submit"
                  className="btn-primary w-full"
                  disabled={createRequestMutation.isPending}
                >
                  {createRequestMutation.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  Enviar Solicitação
                </Button>
              </form>
            </Card>
          </div>

          <div className="space-y-6">
            <Card className="card-elegant bg-accent/5">
              <h3 className="font-semibold text-accent">Dicas Importantes</h3>
              <ul className="mt-4 space-y-3 text-sm text-muted-foreground">
                <li className="flex gap-2">
                  <span className="text-accent">•</span>
                  <span>Descreva o problema com detalhes para melhor atendimento</span>
                </li>
                <li className="flex gap-2">
                  <span className="text-accent">•</span>
                  <span>Selecione a urgência correta para priorização</span>
                </li>
                <li className="flex gap-2">
                  <span className="text-accent">•</span>
                  <span>Você receberá notificações sobre o progresso</span>
                </li>
                <li className="flex gap-2">
                  <span className="text-accent">•</span>
                  <span>Oficinas próximas serão sugeridas automaticamente</span>
                </li>
              </ul>
            </Card>

            <Card className="card-elegant">
              <div className="flex gap-3">
                <MapPin className="h-5 w-5 flex-shrink-0 text-accent" />
                <div>
                  <h4 className="font-semibold">Localização</h4>
                  <p className="mt-1 text-sm text-muted-foreground">
                    Informamos sua cidade para encontrar as melhores oficinas credenciadas
                  </p>
                </div>
              </div>
            </Card>
          </div>
        </div>
      </div>
    </div>
  );
}
