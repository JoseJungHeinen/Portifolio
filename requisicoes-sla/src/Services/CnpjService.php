<?php
// @versao Ver.001.140926

namespace App\Services;

use App\Config\Database;
use PDO;

/**
 * Consulta de CNPJ na cnpj.ws.
 *
 * Sem token configurado usa a API pública (3 consultas por minuto); com token,
 * a comercial. A chamada sai do servidor para que o token nunca chegue ao HTML
 * e para não depender da política de CORS de um serviço que não controlamos.
 *
 * O serviço não conhece sessão nem requisição: recebe um CNPJ e devolve dados
 * já no formato dos campos do cadastro, ou um erro em português. Por isso
 * mapear() é público — permite verificar o mapeamento sem gastar requisição.
 */
class CnpjService
{
    private const HOST_PUBLICO   = 'https://publica.cnpj.ws/cnpj/';
    private const HOST_COMERCIAL = 'https://comercial.cnpj.ws/cnpj/';

    private PDO $db;
    private ?array $cfg = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function ativo(): bool
    {
        return ($this->config()['ativa'] ?? '1') !== '0';
    }

    /**
     * @return array{ok: bool, erro: ?string, dados?: array, aviso?: ?string}
     */
    public function consultar(string $cnpjBruto): array
    {
        $cnpj = self::apenasDigitos($cnpjBruto);

        if (!self::cnpjValido($cnpj)) {
            return ['ok' => false, 'erro' => 'CNPJ inválido — confira os números.'];
        }
        if (!$this->ativo()) {
            return ['ok' => false, 'erro' => 'A consulta de CNPJ está desativada nas configurações.'];
        }

        $cfg   = $this->config();
        $token = trim((string)($cfg['token'] ?? ''));
        $url   = ($token === '' ? self::HOST_PUBLICO : self::HOST_COMERCIAL) . $cnpj;

        try {
            [$status, $corpo] = $this->http($url, $token, (int)($cfg['timeout'] ?? 8));
        } catch (\Throwable $e) {
            // Serviço externo: falha dele não derruba o cadastro.
            error_log('[cnpj] ' . $e->getMessage());
            return ['ok' => false, 'erro' => 'Não foi possível consultar agora. Preencha manualmente.'];
        }

        if ($status === 404) {
            return ['ok' => false, 'erro' => 'CNPJ não encontrado na base da Receita.'];
        }
        if ($status === 429) {
            return ['ok' => false, 'erro' => 'Limite de 3 consultas por minuto atingido. Tente novamente em um minuto.'];
        }
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'erro' => 'Token da API rejeitado. Confira em Configurações → Integrações.'];
        }
        if ($status !== 200) {
            return ['ok' => false, 'erro' => 'A consulta falhou (HTTP ' . $status . '). Preencha manualmente.'];
        }

        $json = json_decode($corpo, true);
        if (!is_array($json) || empty($json['estabelecimento'])) {
            return ['ok' => false, 'erro' => 'Resposta inesperada da API. Preencha manualmente.'];
        }

        return ['ok' => true, 'erro' => null] + $this->mapear($json);
    }

    /**
     * Traduz o JSON da cnpj.ws para os campos do cadastro. Nomes conferidos
     * contra uma consulta real, não contra a documentação.
     *
     * @return array{dados: array<string,string>, aviso: ?string}
     */
    public function mapear(array $json): array
    {
        $est = $json['estabelecimento'] ?? [];

        $endereco = trim(implode(' ', array_filter([
            (string)($est['tipo_logradouro'] ?? ''),
            (string)($est['logradouro'] ?? ''),
        ])));
        if (!empty($est['numero']))      { $endereco .= ', ' . $est['numero']; }
        if (!empty($est['complemento'])) { $endereco .= ' ' . $est['complemento']; }
        if (!empty($est['bairro']))      { $endereco .= ' - ' . $est['bairro']; }

        // Quando a empresa não é do Simples, o objeto vem nulo e a API NÃO diz
        // se ela é Lucro Presumido ou Real. Chutar contaminaria decisão fiscal,
        // então o campo fica vazio.
        $simples = $json['simples'] ?? null;
        $regime  = '';
        if (is_array($simples)) {
            if (($simples['mei'] ?? '') === 'Sim') {
                $regime = 'mei';
            } elseif (($simples['simples'] ?? '') === 'Sim') {
                $regime = 'simples_nacional';
            }
        }

        $situacao = trim((string)($est['situacao_cadastral'] ?? ''));
        $aviso    = null;
        if ($situacao !== '' && mb_strtolower($situacao, 'UTF-8') !== 'ativa') {
            $aviso = 'Situação na Receita: ' . mb_strtoupper($situacao, 'UTF-8');
            if (!empty($est['data_situacao_cadastral'])) {
                $ts = strtotime((string)$est['data_situacao_cadastral']);
                if ($ts) { $aviso .= ' desde ' . date('d/m/Y', $ts); }
            }
            $aviso .= '. Confirme antes de cadastrar este fornecedor.';
        }

        return [
            'dados' => [
                'razao_social'      => trim((string)($json['razao_social'] ?? '')),
                'nome_fantasia'     => trim((string)($est['nome_fantasia'] ?? '')),
                'email'             => mb_strtolower(trim((string)($est['email'] ?? '')), 'UTF-8'),
                'telefone'          => $this->formatarTelefone((string)($est['ddd1'] ?? ''), (string)($est['telefone1'] ?? '')),
                'endereco'          => mb_substr($endereco, 0, 200, 'UTF-8'),
                'cep'               => self::apenasDigitos((string)($est['cep'] ?? '')),
                'cidade'            => trim((string)($est['cidade']['nome'] ?? '')),
                'estado'            => trim((string)($est['estado']['sigla'] ?? '')),
                'regime_tributario' => $regime,
            ],
            'aviso' => $aviso,
        ];
    }

    public static function apenasDigitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    /** Dígito verificador do CNPJ: barra número impossível antes de gastar requisição. */
    public static function cnpjValido(string $cnpj): bool
    {
        $cnpj = self::apenasDigitos($cnpj);
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        foreach ([12, 13] as $pos) {
            $soma = 0;
            $peso = 2;
            for ($i = $pos - 1; $i >= 0; $i--) {
                $soma += (int)$cnpj[$i] * $peso;
                $peso = ($peso === 9) ? 2 : $peso + 1;
            }
            $dv = ((10 * $soma) % 11) % 10;
            if ((int)$cnpj[$pos] !== $dv) {
                return false;
            }
        }
        return true;
    }

    private function formatarTelefone(string $ddd, string $numero): string
    {
        $ddd = self::apenasDigitos($ddd);
        $num = self::apenasDigitos($numero);
        if ($num === '') {
            return '';
        }
        if (strlen($num) === 9) {
            $num = substr($num, 0, 5) . '-' . substr($num, 5);
        } elseif (strlen($num) === 8) {
            $num = substr($num, 0, 4) . '-' . substr($num, 4);
        }
        return $ddd !== '' ? "({$ddd}) {$num}" : $num;
    }

    /** @return array{0:int, 1:string} status HTTP e corpo */
    private function http(string $url, string $token, int $timeout): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'x_api_token: ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => max(2, min(30, $timeout)),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'Exemplo-Compras/1.0',
        ]);
        $corpo = curl_exec($ch);
        $erro  = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($corpo === false) {
            throw new \RuntimeException('cURL: ' . $erro);
        }
        return [$status, (string)$corpo];
    }

    /** @return array{ativa: string, token: string, timeout: string} */
    private function config(): array
    {
        if ($this->cfg === null) {
            $this->cfg = ['ativa' => '1', 'token' => '', 'timeout' => '8'];
            $mapa = [
                'cnpj.consulta_ativa' => 'ativa',
                'cnpj.token'          => 'token',
                'cnpj.timeout_seg'    => 'timeout',
            ];
            try {
                $rows = $this->db->query(
                    "SELECT chave, valor FROM parametros_sistema WHERE chave IN ('cnpj.consulta_ativa','cnpj.token','cnpj.timeout_seg')"
                )->fetchAll();
                foreach ($rows as $r) {
                    if (isset($mapa[$r['chave']])) {
                        $this->cfg[$mapa[$r['chave']]] = (string)$r['valor'];
                    }
                }
            } catch (\Throwable $e) {
                // Migration 051 ainda não rodou: segue com os padrões.
                error_log('[cnpj] config: ' . $e->getMessage());
            }
        }
        return $this->cfg;
    }
}
