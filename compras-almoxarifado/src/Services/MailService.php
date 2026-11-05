<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

class MailService
{
    private PHPMailer $mailer;

    public function __construct()
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST']     ?? 'localhost';
        $mail->Port       = (int)($_ENV['MAIL_PORT'] ?? 587);
        $mail->SMTPAuth   = !empty($_ENV['MAIL_USERNAME']);
        $mail->Username   = $_ENV['MAIL_USERNAME'] ?? '';
        $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
        $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

        $mail->CharSet  = 'UTF-8';
        $mail->setFrom(
            $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@exemplo.com',
            $_ENV['MAIL_FROM_NAME']    ?? 'Exemplo Compras'
        );

        $this->mailer = $mail;
    }

    /**
     * Envia e-mail simples em HTML.
     * Falha silenciosamente para não quebrar o fluxo da aplicação.
     */
    public function enviar(string $paraEmail, string $paraNome, string $assunto, string $htmlBody): bool
    {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($paraEmail, $paraNome);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $assunto;
            $this->mailer->Body    = $htmlBody;
            $this->mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
            $this->mailer->send();
            return true;
        } catch (MailException $e) {
            // Loga o erro sem derrubar a aplicação
            error_log('[MailService] Falha ao enviar e-mail para ' . $paraEmail . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica o comprador que um fornecedor respondeu a cotação.
     */
    public function notificarRespostaCotacao(
        string $compradorEmail,
        string $compradorNome,
        string $fornecedorNome,
        string $cotacaoId,
        int    $totalItens,
        int    $totalRespondidas,
        int    $totalFornecedores
    ): bool {
        $assunto = "Cotação respondida por {$fornecedorNome}";
        $urlComparativo = rtrim($_ENV['APP_URL'] ?? '', '/') . "/compras/cotacoes/{$cotacaoId}/comparativo";
        $cotacaoCurta   = strtoupper(substr($cotacaoId, 0, 8));

        $todasRespondidas = $totalRespondidas >= $totalFornecedores;
        $bannerCor   = $todasRespondidas ? '#198754' : '#0d6efd';
        $bannerTexto = $todasRespondidas
            ? 'Todas as respostas recebidas!'
            : "Aguardando mais respostas ({$totalRespondidas}/{$totalFornecedores})";
        $itemLabel = $totalItens === 1 ? 'item' : 'itens';

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0">
            <tr><td align="center">
              <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">

                <!-- Cabeçalho -->
                <tr>
                  <td style="background:#CC0000;padding:24px 32px">
                    <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">Exemplo Compras</p>
                    <p style="margin:4px 0 0;color:rgba(255,255,255,.8);font-size:13px">Sistema de Gestão de Suprimentos</p>
                  </td>
                </tr>

                <!-- Banner de status -->
                <tr>
                  <td style="background:{$bannerCor};padding:12px 32px;color:#fff;font-size:14px;font-weight:bold">
                    {$bannerTexto}
                  </td>
                </tr>

                <!-- Corpo -->
                <tr>
                  <td style="padding:32px">
                    <p style="margin:0 0 8px;color:#333;font-size:15px">Olá, <strong>{$compradorNome}</strong>!</p>
                    <p style="margin:0 0 24px;color:#555;font-size:14px;line-height:1.6">
                      O fornecedor <strong>{$fornecedorNome}</strong> enviou sua resposta para a cotação <strong>#{$cotacaoCurta}</strong>,
                      informando preços para <strong>{$totalItens}</strong> {$itemLabel}.
                    </p>

                    <!-- Card de resumo -->
                    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border-radius:6px;margin-bottom:24px">
                      <tr>
                        <td style="padding:16px 20px">
                          <p style="margin:0 0 8px;color:#888;font-size:12px;text-transform:uppercase;letter-spacing:.5px">Resumo</p>
                          <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="padding:4px 0;color:#555;font-size:13px">Fornecedor</td>
                              <td style="padding:4px 0;color:#333;font-size:13px;font-weight:bold;text-align:right">{$fornecedorNome}</td>
                            </tr>
                            <tr>
                              <td style="padding:4px 0;color:#555;font-size:13px">Cotação</td>
                              <td style="padding:4px 0;color:#333;font-size:13px;font-weight:bold;text-align:right">#{$cotacaoCurta}</td>
                            </tr>
                            <tr>
                              <td style="padding:4px 0;color:#555;font-size:13px">Respostas recebidas</td>
                              <td style="padding:4px 0;color:#333;font-size:13px;font-weight:bold;text-align:right">{$totalRespondidas} de {$totalFornecedores}</td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>

                    <!-- CTA -->
                    <p style="text-align:center;margin:0">
                      <a href="{$urlComparativo}"
                         style="display:inline-block;background:#CC0000;color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:14px;font-weight:bold">
                        Ver Comparativo de Cotação
                      </a>
                    </p>
                  </td>
                </tr>

                <!-- Rodapé -->
                <tr>
                  <td style="background:#f8f9fa;padding:16px 32px;text-align:center;color:#aaa;font-size:12px;border-top:1px solid #eee">
                    Exemplo Automotive &copy; 2026 &mdash; Este é um e-mail automático, não responda.
                  </td>
                </tr>

              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;

        return $this->enviar($compradorEmail, $compradorNome, $assunto, $html);
    }

    /**
     * Notifica o fornecedor que recebeu uma solicitação de cotação,
     * com o link único para resposta.
     */
    public function notificarCotacaoParaFornecedor(
        string $fornecedorEmail,
        string $fornecedorNome,
        string $token,
        string $prazoResposta,
        string $grupoNome,
        string $filialNome,
        int    $totalItens,
        array  $itens = []
    ): bool {
        $appNome   = $_ENV['MAIL_FROM_NAME'] ?? 'Exemplo Compras';
        $assunto   = "Solicitação de cotação - {$appNome}";
        $linkResposta = rtrim($_ENV['APP_URL'] ?? '', '/') . "/cotacao/responder/{$token}";
        $prazoFmt  = date('d/m/Y \a\s H:i', strtotime($prazoResposta));
        $itemLabel = $totalItens === 1 ? 'item' : 'itens';

        $itensHtml = '';
        if (!empty($itens)) {
            $rows = '';
            foreach ($itens as $item) {
                $nome = htmlspecialchars($item['item_nome'] ?? '', ENT_QUOTES);
                $qtd  = number_format((float)($item['quantidade'] ?? 0), 2, ',', '.');
                $un   = htmlspecialchars($item['unidade'] ?? '', ENT_QUOTES);
                $rows .= "<tr>
                            <td style=\"padding:5px 8px;color:#333;font-size:13px;border-bottom:1px solid #eee\">{$nome}</td>
                            <td style=\"padding:5px 8px;color:#555;font-size:13px;text-align:center;border-bottom:1px solid #eee\">{$qtd} {$un}</td>
                          </tr>";
            }
            $itensHtml = "
                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f8f9fa;border-radius:6px;margin-bottom:24px\">
                      <tr>
                        <td style=\"padding:12px 20px\">
                          <p style=\"margin:0 0 8px;color:#888;font-size:12px;text-transform:uppercase;letter-spacing:.5px\">Itens para cotar</p>
                          <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">
                            <tr>
                              <th style=\"padding:4px 8px;color:#888;font-size:11px;text-align:left;font-weight:normal;border-bottom:1px solid #dee2e6\">Item</th>
                              <th style=\"padding:4px 8px;color:#888;font-size:11px;text-align:center;font-weight:normal;border-bottom:1px solid #dee2e6\">Qtd.</th>
                            </tr>
                            {$rows}
                          </table>
                        </td>
                      </tr>
                    </table>";
        }

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0">
            <tr><td align="center">
              <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">

                <!-- Cabeçalho -->
                <tr>
                  <td style="background:#CC0000;padding:24px 32px">
                    <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">{$appNome}</p>
                    <p style="margin:4px 0 0;color:rgba(255,255,255,.8);font-size:13px">Sistema de Gestão de Suprimentos</p>
                  </td>
                </tr>

                <!-- Banner -->
                <tr>
                  <td style="background:#e8f4fd;padding:12px 32px;color:#0d6efd;font-size:14px;font-weight:bold;border-left:4px solid #0d6efd">
                    Nova solicitação de cotação recebida
                  </td>
                </tr>

                <!-- Corpo -->
                <tr>
                  <td style="padding:32px">
                    <p style="margin:0 0 8px;color:#333;font-size:15px">Olá, <strong>{$fornecedorNome}</strong>!</p>
                    <p style="margin:0 0 24px;color:#555;font-size:14px;line-height:1.6">
                      Você recebeu uma solicitação de cotação. Por favor, acesse o link abaixo,
                      preencha os preços dos itens e envie sua proposta antes do prazo.
                    </p>

                    <!-- Card de detalhes -->
                    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border-radius:6px;margin-bottom:24px">
                      <tr>
                        <td style="padding:16px 20px">
                          <p style="margin:0 0 10px;color:#888;font-size:12px;text-transform:uppercase;letter-spacing:.5px">Detalhes da cotação</p>
                          <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="padding:4px 0;color:#555;font-size:13px">Categoria</td>
                              <td style="padding:4px 0;color:#333;font-size:13px;font-weight:bold;text-align:right">{$grupoNome}</td>
                            </tr>
                            <tr>
                              <td style="padding:4px 0;color:#555;font-size:13px">Filial solicitante</td>
                              <td style="padding:4px 0;color:#333;font-size:13px;font-weight:bold;text-align:right">{$filialNome}</td>
                            </tr>
                            <tr>
                              <td style="padding:4px 0;color:#555;font-size:13px">Itens para cotar</td>
                              <td style="padding:4px 0;color:#333;font-size:13px;font-weight:bold;text-align:right">{$totalItens} {$itemLabel}</td>
                            </tr>
                            <tr>
                              <td style="padding:4px 0;color:#555;font-size:13px">Prazo para resposta</td>
                              <td style="padding:4px 0;color:#CC0000;font-size:13px;font-weight:bold;text-align:right">{$prazoFmt}</td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                    </table>

                    <!-- Lista de itens -->
                    {$itensHtml}

                    <!-- CTA -->
                    <p style="text-align:center;margin:0 0 16px">
                      <a href="{$linkResposta}"
                         style="display:inline-block;background:#CC0000;color:#fff;text-decoration:none;padding:14px 32px;border-radius:6px;font-size:15px;font-weight:bold">
                        Responder Cotação
                      </a>
                    </p>
                    <p style="text-align:center;margin:0;color:#aaa;font-size:11px">
                      Ou copie e cole o link no navegador:<br>
                      <span style="color:#555;word-break:break-all">{$linkResposta}</span>
                    </p>
                  </td>
                </tr>

                <!-- Rodapé -->
                <tr>
                  <td style="background:#f8f9fa;padding:16px 32px;text-align:center;color:#aaa;font-size:12px;border-top:1px solid #eee">
                    {$appNome} &copy; 2026 &mdash; Este link é de uso exclusivo de {$fornecedorNome}. Não compartilhe.
                  </td>
                </tr>

              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;

        return $this->enviar($fornecedorEmail, $fornecedorNome, $assunto, $html);
    }
}
