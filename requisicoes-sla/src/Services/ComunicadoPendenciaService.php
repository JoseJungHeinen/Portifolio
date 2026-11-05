<?php
// @versao Ver.001.090926

namespace App\Services;

/**
 * Monta e envia o comunicado de pendências.
 *
 * Não conhece SQL — recebe os itens já coletados pelo PendenciaService. A
 * separação existe porque a coleta muda conforme o uso e o envio é estável.
 */
class ComunicadoPendenciaService
{
    private const ROTULOS = [
        'aprovacao'   => 'Aguardando sua aprovação',
        'cotacao'     => 'Cotações sem resposta',
        'recebimento' => 'Recebimentos e notas fiscais',
        'contrato'    => 'Contratos vencendo',
    ];

    private MailService $mail;

    public function __construct(?MailService $mail = null)
    {
        $this->mail = $mail ?? new MailService();
    }

    /** @return bool true se o e-mail saiu */
    public function enviarIndividual(array $usuario, array $itens): bool
    {
        if (empty($usuario['email'])) {
            return false;  // sem e-mail cadastrado: pulado silenciosamente
        }

        $n       = count($itens);
        $assunto = $n === 1 ? '1 tarefa pendente com você' : "{$n} tarefas pendentes com você";

        $intro = 'Estas tarefas estão paradas com você há pelo menos um dia útil.';
        $html  = $this->montarHtml('Olá, ' . $this->primeiroNome($usuario['nome']), $intro, $itens);

        return $this->mail->enviar($usuario['email'], $usuario['nome'], $assunto, $html);
    }

    /** @return bool true se o e-mail saiu */
    public function enviarConsolidado(array $usuario, array $itens): bool
    {
        if (empty($usuario['email'])) {
            return false;
        }

        $n       = count($itens);
        $assunto = "Consolidado: {$n} tarefa(s) pendente(s) no sistema";
        $intro   = 'Panorama geral do que está parado no sistema, incluindo o que está com outras pessoas.';
        $html    = $this->montarHtml('Olá, ' . $this->primeiroNome($usuario['nome']), $intro, $itens);

        return $this->mail->enviar($usuario['email'], $usuario['nome'], $assunto, $html);
    }

    private function primeiroNome(string $nome): string
    {
        return explode(' ', trim($nome))[0] ?? $nome;
    }

    /**
     * HTML com estilo inline: cliente de e-mail ignora <style> externo e a maioria
     * ignora até <style> no <head>.
     */
    private function montarHtml(string $saudacao, string $intro, array $itens): string
    {
        $porTipo = [];
        foreach ($itens as $item) {
            $porTipo[$item['tipo']][] = $item;
        }

        $appNome = $_ENV['MAIL_FROM_NAME'] ?? 'Exemplo Compras';
        $corpo   = '';

        foreach (self::ROTULOS as $tipo => $rotulo) {
            if (empty($porTipo[$tipo])) {
                continue;
            }

            $corpo .= '<h3 style="font:600 15px/1.4 Arial,sans-serif;color:#1f2937;margin:24px 0 8px">'
                    . e($rotulo) . ' (' . count($porTipo[$tipo]) . ')</h3>'
                    . '<table cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse">';

            foreach ($porTipo[$tipo] as $item) {
                $dias = (int)$item['dias_parado'];
                $cor  = $dias >= 3 ? '#b91c1c' : '#6b7280';
                $selo = $dias <= 0 ? 'hoje' : ($dias === 1 ? 'há 1 dia' : "há {$dias} dias");

                $corpo .= '<tr>'
                    . '<td style="padding:10px 0;border-bottom:1px solid #e5e7eb;font:14px/1.5 Arial,sans-serif;color:#111827">'
                    . '<a href="' . e($item['url']) . '" style="color:#1d4ed8;text-decoration:none;font-weight:600">'
                    . e($item['titulo']) . '</a><br>'
                    . '<span style="color:#6b7280;font-size:13px">' . e($item['detalhe']) . '</span>'
                    . '</td>'
                    . '<td align="right" style="padding:10px 0;border-bottom:1px solid #e5e7eb;'
                    . 'font:600 13px Arial,sans-serif;color:' . $cor . ';white-space:nowrap">'
                    . e($selo) . '</td>'
                    . '</tr>';
            }

            $corpo .= '</table>';
        }

        return '<div style="max-width:640px;margin:0 auto;padding:24px;background:#ffffff">'
             . '<p style="font:15px/1.6 Arial,sans-serif;color:#111827;margin:0 0 4px">' . e($saudacao) . '</p>'
             . '<p style="font:14px/1.6 Arial,sans-serif;color:#4b5563;margin:0">' . e($intro) . '</p>'
             . $corpo
             . '<p style="font:12px/1.6 Arial,sans-serif;color:#9ca3af;margin:28px 0 0;'
             . 'border-top:1px solid #e5e7eb;padding-top:12px">'
             . e($appNome) . ' — mensagem automática, enviada nos dias úteis pela manhã. Não responda a este e-mail.'
             . '</p></div>';
    }
}
