<?php
// @versao Tab_06 V_07_30/09/2026
declare(strict_types=1);

/**
 * Modelos de Veículos — lista de referência do módulo Listas (Listas › Modelos de Veículos).
 *
 * Alimenta o campo C3 (Modelo) do pedido de Faturamento. Cadastro pequeno de propósito:
 * marca, MVS (código Modelo.Versão.Série da montadora), ano-modelo (MY), descrição,
 * combustível e preço público sugerido (frete incluso) com a data de vigência.
 * Modelo inativo some do pedido, mas os pedidos já gravados continuam mostrando o texto salvo.
 */
class ModeloVeiculo
{
    /** Combustíveis aceitos (mesma lista do campo C6 do pedido). */
    public const COMBUSTIVEIS = ['Álcool/Gasolina', 'Gasolina', 'Diesel', 'Elétrico', 'Híbrido'];

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** @return string[] marcas já cadastradas */
    public static function marcas(): array
    {
        return Database::getInstance()
            ->query('SELECT DISTINCT marca FROM nf_fat_modelos ORDER BY marca')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function filtro(array $f, array &$params): string
    {
        $where = ['1=1'];
        if (($f['status'] ?? '') === 'ativo') {
            $where[] = 'ativo = 1';
        } elseif (($f['status'] ?? '') === 'inativo') {
            $where[] = 'ativo = 0';
        }
        if (!empty($f['marca'])) {
            $where[]  = 'marca = ?';
            $params[] = $f['marca'];
        }
        if (!empty($f['q'])) {
            $where[] = '(mvs LIKE ? OR descricao LIKE ?)';
            $like    = '%' . $f['q'] . '%';
            array_push($params, $like, $like);
        }
        return implode(' AND ', $where);
    }

    public static function contar(array $f = []): int
    {
        $params = [];
        $sql    = 'SELECT COUNT(*) FROM nf_fat_modelos WHERE ' . self::filtro($f, $params);
        $stmt   = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, array> */
    public static function listar(array $f, int $offset, int $limite): array
    {
        $params = [];
        $sql    = 'SELECT * FROM nf_fat_modelos WHERE ' . self::filtro($f, $params)
                . ' ORDER BY marca, descricao, ano_modelo DESC LIMIT ' . max(1, $limite) . ' OFFSET ' . max(0, $offset);
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Texto do campo Cores (uma por linha) → lista limpa, sem repetição. @return string[] */
    public static function listaDeCores(?string $texto): array
    {
        $out = [];
        foreach (preg_split('/\R/u', (string) $texto) ?: [] as $l) {
            $l = mb_substr(trim(strip_tags($l)), 0, 120);
            if ($l !== '' && !in_array($l, $out, true)) {
                $out[] = $l;
            }
        }
        return array_slice($out, 0, 60);
    }

    /** Modelos ativos para os campos C3/C4 do pedido (com a lista de cores de cada um). @return array<int, array> */
    public static function ativos(): array
    {
        $lista = Database::getInstance()
            ->query('SELECT marca, mvs, ano_modelo, descricao, combustivel, preco_sugerido, cores
                       FROM nf_fat_modelos WHERE ativo = 1 ORDER BY marca, descricao, ano_modelo DESC')
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lista as &$m) {
            $m['cores'] = self::listaDeCores($m['cores']);
        }
        unset($m);
        return $lista;
    }

    public static function buscar(string $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM nf_fat_modelos WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Cria ou atualiza. Sem $id cria.
     *
     * @param array{marca:string, mvs:string, ano_modelo:string, descricao:string, combustivel:string,
     *              preco_sugerido:string, preco_vigencia:string, cores?:string, ativo?:string} $d campos crus do formulário
     * @throws InvalidArgumentException dado inválido ou MVS já cadastrado (o MVS é a chave única)
     */
    public static function salvar(?string $id, array $d): string
    {
        $txt = static fn(string $k, int $max) => mb_substr(trim(strip_tags((string) ($d[$k] ?? ''))), 0, $max);

        $marca = mb_strtoupper($txt('marca', 40));
        $mvs   = strtoupper($txt('mvs', 20));
        $desc  = $txt('descricao', 200);
        if ($marca === '' || $mvs === '' || $desc === '') {
            throw new InvalidArgumentException('Marca, MVS e descrição são obrigatórios.');
        }

        $ano = (int) ($d['ano_modelo'] ?? 0);
        if ($ano < 2000 || $ano > 2100) {
            throw new InvalidArgumentException('Ano-modelo inválido (use 4 dígitos, ex.: 2027).');
        }

        $comb = $txt('combustivel', 30);
        if ($comb !== '' && !in_array($comb, self::COMBUSTIVEIS, true)) {
            throw new InvalidArgumentException('Combustível inválido.');
        }

        $preco = trim((string) ($d['preco_sugerido'] ?? ''));
        if ($preco !== '') {
            if (str_contains($preco, ',')) {
                $preco = str_replace(',', '.', str_replace('.', '', $preco));
            }
            if (!is_numeric($preco) || (float) $preco < 0) {
                throw new InvalidArgumentException('Preço sugerido inválido.');
            }
            $preco = round((float) $preco, 2);
        } else {
            $preco = null;
        }

        $vig = trim((string) ($d['preco_vigencia'] ?? ''));
        if ($vig !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $vig);
            if (!$dt || $dt->format('Y-m-d') !== $vig) {
                throw new InvalidArgumentException('Data de vigência inválida.');
            }
        } else {
            $vig = null;
        }

        $cores = implode("\n", self::listaDeCores((string) ($d['cores'] ?? '')));
        $cores = $cores !== '' ? $cores : null;

        $ativo = (($d['ativo'] ?? '1') === '1') ? 1 : 0;
        $pdo   = Database::getInstance();
        try {
            if ($id === null) {
                $id = self::uuid();
                $pdo->prepare(
                    'INSERT INTO nf_fat_modelos (id, marca, mvs, ano_modelo, descricao, combustivel, preco_sugerido, preco_vigencia, cores, ativo)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([$id, $marca, $mvs, $ano, $desc, $comb !== '' ? $comb : null, $preco, $vig, $cores, 1]);
            } else {
                if (!self::buscar($id)) {
                    throw new InvalidArgumentException('Modelo não encontrado.');
                }
                $pdo->prepare(
                    'UPDATE nf_fat_modelos SET marca = ?, mvs = ?, ano_modelo = ?, descricao = ?, combustivel = ?,
                            preco_sugerido = ?, preco_vigencia = ?, cores = ?, ativo = ? WHERE id = ?'
                )->execute([$marca, $mvs, $ano, $desc, $comb !== '' ? $comb : null, $preco, $vig, $cores, $ativo, $id]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException("Já existe um modelo com o MVS {$mvs} — o MVS é único (para o ano-modelo novo, edite o modelo existente).");
            }
            throw $e;
        }
        return $id;
    }

    /** Inverte ativo/inativo. @return bool novo estado (true = ativo) */
    public static function alternarAtivo(string $id): bool
    {
        $m = self::buscar($id);
        if (!$m) {
            throw new InvalidArgumentException('Modelo não encontrado.');
        }
        $novo = $m['ativo'] ? 0 : 1;
        Database::getInstance()->prepare('UPDATE nf_fat_modelos SET ativo = ? WHERE id = ?')->execute([$novo, $id]);
        return (bool) $novo;
    }
}
