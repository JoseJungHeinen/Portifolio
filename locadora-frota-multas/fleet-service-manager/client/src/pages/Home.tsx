import { useAuth } from "@/_core/hooks/useAuth";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { getLoginUrl } from "@/const";
import { Truck, MapPin, Clock, CheckCircle, BarChart3, Shield } from "lucide-react";
import { Link } from "wouter";

export default function Home() {
  const { user, isAuthenticated } = useAuth();

  if (isAuthenticated) {
    return (
      <div className="min-h-screen bg-background">
        <nav className="border-b border-border bg-card shadow-sm">
          <div className="container flex items-center justify-between py-4">
            <div className="flex items-center gap-2">
              <Truck className="h-8 w-8 text-accent" />
              <h1 className="text-2xl font-bold gradient-text">FleetFlow</h1>
            </div>
            <div className="flex items-center gap-4">
              <span className="text-sm text-muted-foreground">Bem-vindo, {user?.name}</span>
              <Button variant="outline" size="sm">
                Sair
              </Button>
            </div>
          </div>
        </nav>

        <main className="container py-12">
          <div className="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
            <Link href="/service-request">
              <Card className="card-elegant cursor-pointer">
                <div className="flex items-start justify-between">
                  <div>
                    <h3 className="text-lg font-semibold">Solicitar Serviço</h3>
                    <p className="mt-2 text-sm text-muted-foreground">
                      Solicite manutenção para seu veículo
                    </p>
                  </div>
                  <Truck className="h-8 w-8 text-accent" />
                </div>
              </Card>
            </Link>

            <Link href="/appointments">
              <Card className="card-elegant cursor-pointer">
                <div className="flex items-start justify-between">
                  <div>
                    <h3 className="text-lg font-semibold">Meus Agendamentos</h3>
                    <p className="mt-2 text-sm text-muted-foreground">
                      Visualize seus agendamentos
                    </p>
                  </div>
                  <Clock className="h-8 w-8 text-accent" />
                </div>
              </Card>
            </Link>

            <Link href="/notifications">
              <Card className="card-elegant cursor-pointer">
                <div className="flex items-start justify-between">
                  <div>
                    <h3 className="text-lg font-semibold">Notificações</h3>
                    <p className="mt-2 text-sm text-muted-foreground">
                      Acompanhe suas notificações
                    </p>
                  </div>
                  <CheckCircle className="h-8 w-8 text-accent" />
                </div>
              </Card>
            </Link>
          </div>

          {user?.role === "admin" && (
            <div className="mt-12 border-t border-border pt-12">
              <h2 className="mb-8 text-3xl font-bold">Painel Administrativo</h2>
              <div className="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                <Link href="/admin/dashboard">
                  <Card className="card-elegant cursor-pointer">
                    <div className="flex items-start justify-between">
                      <div>
                        <h3 className="text-lg font-semibold">Dashboard</h3>
                        <p className="mt-2 text-sm text-muted-foreground">
                          Visualize métricas principais
                        </p>
                      </div>
                      <BarChart3 className="h-8 w-8 text-accent" />
                    </div>
                  </Card>
                </Link>

                <Link href="/admin/appointments">
                  <Card className="card-elegant cursor-pointer">
                    <div className="flex items-start justify-between">
                      <div>
                        <h3 className="text-lg font-semibold">Agendamentos</h3>
                        <p className="mt-2 text-sm text-muted-foreground">
                          Gerencie todos os agendamentos
                        </p>
                      </div>
                      <Clock className="h-8 w-8 text-accent" />
                    </div>
                  </Card>
                </Link>

                <Link href="/admin/suppliers">
                  <Card className="card-elegant cursor-pointer">
                    <div className="flex items-start justify-between">
                      <div>
                        <h3 className="text-lg font-semibold">Fornecedores</h3>
                        <p className="mt-2 text-sm text-muted-foreground">
                          Gerencie oficinas credenciadas
                        </p>
                      </div>
                      <Shield className="h-8 w-8 text-accent" />
                    </div>
                  </Card>
                </Link>

                <Link href="/admin/budgets">
                  <Card className="card-elegant cursor-pointer">
                    <div className="flex items-start justify-between">
                      <div>
                        <h3 className="text-lg font-semibold">Orçamentos</h3>
                        <p className="mt-2 text-sm text-muted-foreground">
                          Aprove orçamentos de serviços
                        </p>
                      </div>
                      <CheckCircle className="h-8 w-8 text-accent" />
                    </div>
                  </Card>
                </Link>

                <Link href="/admin/invoices">
                  <Card className="card-elegant cursor-pointer">
                    <div className="flex items-start justify-between">
                      <div>
                        <h3 className="text-lg font-semibold">Notas Fiscais</h3>
                        <p className="mt-2 text-sm text-muted-foreground">
                          Gerencie documentos fiscais
                        </p>
                      </div>
                      <BarChart3 className="h-8 w-8 text-accent" />
                    </div>
                  </Card>
                </Link>

                <Link href="/admin/reports">
                  <Card className="card-elegant cursor-pointer">
                    <div className="flex items-start justify-between">
                      <div>
                        <h3 className="text-lg font-semibold">Relatórios</h3>
                        <p className="mt-2 text-sm text-muted-foreground">
                          Analise dados e tendências
                        </p>
                      </div>
                      <BarChart3 className="h-8 w-8 text-accent" />
                    </div>
                  </Card>
                </Link>
              </div>
            </div>
          )}
        </main>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-background via-background to-muted">
      <nav className="border-b border-border/50 bg-card/50 backdrop-blur-md">
        <div className="container flex items-center justify-between py-4">
          <div className="flex items-center gap-2">
            <Truck className="h-8 w-8 text-accent" />
            <h1 className="text-2xl font-bold gradient-text">FleetFlow</h1>
          </div>
          <Button onClick={() => (window.location.href = getLoginUrl())}>
            Entrar
          </Button>
        </div>
      </nav>

      <main className="container py-20">
        <div className="grid gap-12 lg:grid-cols-2 lg:gap-16">
          <div className="flex flex-col justify-center">
            <h2 className="text-5xl font-bold tracking-tight text-balance">
              Gestão Inteligente de Frota
            </h2>
            <p className="mt-6 text-xl text-muted-foreground text-balance">
              Organize agendamentos de serviços, conecte motoristas com oficinas credenciadas e
              acompanhe cada detalhe da manutenção da sua frota em tempo real.
            </p>
            <div className="mt-8 flex gap-4">
              <Button
                size="lg"
                onClick={() => (window.location.href = getLoginUrl())}
              >
                Começar Agora
              </Button>
              <Button size="lg" variant="outline">
                Saiba Mais
              </Button>
            </div>
          </div>

          <div className="grid gap-6">
            <div className="card-elegant">
              <div className="flex gap-4">
                <MapPin className="h-6 w-6 flex-shrink-0 text-accent" />
                <div>
                  <h3 className="font-semibold">Localização Inteligente</h3>
                  <p className="mt-1 text-sm text-muted-foreground">
                    Encontre as melhores oficinas próximas à sua localização
                  </p>
                </div>
              </div>
            </div>

            <div className="card-elegant">
              <div className="flex gap-4">
                <Clock className="h-6 w-6 flex-shrink-0 text-accent" />
                <div>
                  <h3 className="font-semibold">Agendamento Rápido</h3>
                  <p className="mt-1 text-sm text-muted-foreground">
                    Agende serviços em minutos com disponibilidade em tempo real
                  </p>
                </div>
              </div>
            </div>

            <div className="card-elegant">
              <div className="flex gap-4">
                <CheckCircle className="h-6 w-6 flex-shrink-0 text-accent" />
                <div>
                  <h3 className="font-semibold">Acompanhamento Total</h3>
                  <p className="mt-1 text-sm text-muted-foreground">
                    Monitore cada etapa do serviço com notificações automáticas
                  </p>
                </div>
              </div>
            </div>

            <div className="card-elegant">
              <div className="flex gap-4">
                <BarChart3 className="h-6 w-6 flex-shrink-0 text-accent" />
                <div>
                  <h3 className="font-semibold">Relatórios Detalhados</h3>
                  <p className="mt-1 text-sm text-muted-foreground">
                    Analise custos, tendências e otimize sua frota
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div className="section-divider" />

        <div className="grid gap-8 md:grid-cols-3">
          <div className="text-center">
            <div className="text-4xl font-bold text-accent">100+</div>
            <p className="mt-2 text-muted-foreground">Oficinas Credenciadas</p>
          </div>
          <div className="text-center">
            <div className="text-4xl font-bold text-accent">5000+</div>
            <p className="mt-2 text-muted-foreground">Agendamentos Realizados</p>
          </div>
          <div className="text-center">
            <div className="text-4xl font-bold text-accent">98%</div>
            <p className="mt-2 text-muted-foreground">Satisfação dos Clientes</p>
          </div>
        </div>
      </main>

      <footer className="border-t border-border bg-card py-8">
        <div className="container text-center text-sm text-muted-foreground">
          <p>&copy; 2026 FleetFlow. Todos os direitos reservados.</p>
        </div>
      </footer>
    </div>
  );
}
