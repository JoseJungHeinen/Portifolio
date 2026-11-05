<?php
// @versao Fis_06 V_01_26/08/2026
declare(strict_types=1);

/**
 * Consulta de CNPJ na Receita via API pública do cnpj.ws.
 *
 * A API aceita 3 consultas por minuto POR IP — e o IP é o do servidor
 * inteiro, não o do usuário. Por isso a trava é GLOBAL: um arquivo em
 * storage/locks/cnpjws.last, o MESMO usado pela consulta da tela de
 * Processos. Duas telas disputando o limite sem se enxergar tomariam 429 uma
 * da outra.
 *
 * Não lança exceção: devolve sempre um array com `sucesso`. Quem chama
 * decide se mostra, se grava ou se tenta de novo — inclusive o CLI, que roda
 * sem ninguém olhando.
 */
class CnpjReceita
{
    /** Segundos entre duas consultas — 3/min na API, com folga. */
    public const INTERVALO = 20;

    /**
     * @return array{sucesso:bool, dados?:array, erro?:string, esperar?:int}
     *         `esperar` = segundos que faltam para a próxima consulta poder
     *         sair (só vem quando a trava barrou).
     */
    public static function consultar(string $cnpj): array
    {
        $cnpj = (string) preg_replace('/\D/', '', $cnpj);
        if (strlen($cnpj) !== 14) {
            return ['sucesso' => false, 'erro' => 'Consulta disponível apenas para CNPJ (14 dígitos).'];
        }

        $espera = self::esperaRestante();
        if ($espera > 0) {
            return ['sucesso' => false, 'esperar' => $espera,
                    'erro' => "Aguarde {$espera}s para consultar novamente."];
        }
        @touch(self::marca());

        $ch = curl_init('https://publica.cnpj.ws/cnpj/' . $cnpj);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'ExemploNotas/1.0',
        ]);
        $corpo = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($corpo === false) {
            return ['sucesso' => false, 'erro' => 'Sem resposta da consulta.'];
        }
        if ($http === 429) {
            return ['sucesso' => false, 'esperar' => 60,
                    'erro' => 'Limite de consultas do serviço atingido.'];
        }
        if ($http === 404) {
            return ['sucesso' => false, 'erro' => 'CNPJ não encontrado na base da Receita.'];
        }

        $ws = json_decode((string) $corpo, true);
        if ($http !== 200 || !is_array($ws)) {
            return ['sucesso' => false, 'erro' => "Consulta indisponível (HTTP {$http})."];
        }

        $est = $ws['estabelecimento'] ?? [];
        $sim = $ws['simples'] ?? null;
        // A API responde 'Sim'/'Não'; ausente vira NULL, que é "não sei" —
        // diferente de 0, que é "consultei e não é".
        $flag = static fn($v) => $v === 'Sim' ? 1 : ($v === 'Não' ? 0 : null);

        return ['sucesso' => true, 'dados' => [
            'razao_social'       => mb_substr((string) ($ws['razao_social'] ?? ''), 0, 200),
            'situacao_cadastral' => mb_substr((string) ($est['situacao_cadastral'] ?? ''), 0, 30),
            'optante_simples'    => $flag($sim['simples'] ?? null),
            'optante_mei'        => $flag($sim['mei'] ?? null),
        ]];
    }

    /** Segundos que faltam para a trava liberar (0 = pode consultar agora). */
    public static function esperaRestante(): int
    {
        $marca  = self::marca();
        $ultima = is_file($marca) ? (int) filemtime($marca) : 0;
        return max(0, self::INTERVALO - (time() - $ultima));
    }

    /** Arquivo-carimbo da última consulta — compartilhado com Processos. */
    private static function marca(): string
    {
        $dir = (defined('STORAGE') ? STORAGE : ROOT . '/storage') . '/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/cnpjws.last';
    }
}
