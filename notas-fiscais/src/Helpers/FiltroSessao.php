<?php
// @versao Sys_29 V_01_05/08/2026
declare(strict_types=1);

/**
 * Persistência dos filtros de tela na sessão do usuário.
 *
 * Problema que resolve: o filtro vivia só na query string. Bastava clicar no
 * item do menu — que aponta para a URL sem parâmetros — para a seleção sumir,
 * e quem estava trabalhando em cima de um recorte tinha de remontá-lo a cada
 * volta para a tela.
 *
 * Regra: a seleção vale até o usuário limpar (ou até a sessão acabar).
 *
 *   1. `?limpar=1`                → apaga o filtro guardado e volta ao padrão
 *   2. formulário submetido       → a query manda e vira o novo filtro guardado
 *   3. entrou sem query nenhuma   → reaplica o que estava guardado
 *
 * A chave da tela é livre, mas use `<modulo>.<action>` para não colidir entre
 * telas do mesmo módulo (ex.: 'tabelas.itens' × 'tabelas.fornecedores').
 *
 * Sanitização continua sendo do controller: o helper recebe valores já
 * validados e só decide se usa o que veio da URL ou o que estava guardado.
 * O que vai para a sessão, portanto, já passou pelo Sanitize.
 *
 * Paginação NÃO entra aqui — `page` pertence à navegação, não ao recorte, e
 * guardá-la faria a tela reabrir numa página que talvez nem exista mais.
 */
class FiltroSessao
{
    /** Namespace único dentro de $_SESSION. */
    private const CHAVE = 'filtros_tela';

    /**
     * Decide entre a query string e o que está guardado na sessão.
     *
     * @param  string                $tela   Identificador da tela ('templates.index')
     * @param  array<string,string>  $doGet  Campos do filtro já sanitizados
     * @return array<string,string>          Filtro a aplicar (mesmas chaves de $doGet)
     */
    public static function resolver(string $tela, array $doGet): array
    {
        if (self::pediuLimpar()) {
            self::limpar($tela);
            return array_map(static fn () => '', $doGet);
        }

        // Presença na URL — e não valor preenchido — é o que identifica o
        // formulário submetido: filtrar por "tudo vazio" é uma escolha legítima
        // e precisa apagar o filtro anterior em vez de ressuscitá-lo.
        if (self::veioDaQuery(array_keys($doGet))) {
            self::guardar($tela, $doGet);
            return $doGet;
        }

        $guardado = $_SESSION[self::CHAVE][$tela] ?? null;
        if (!is_array($guardado)) {
            return $doGet;
        }

        // Só as chaves que o controller pediu: um filtro guardado antes de a
        // tela mudar de campos não pode injetar chave que ninguém mais espera.
        $resultado = [];
        foreach ($doGet as $campo => $valor) {
            $resultado[$campo] = is_string($guardado[$campo] ?? null) ? $guardado[$campo] : $valor;
        }
        return $resultado;
    }

    /** Apaga o filtro guardado de uma tela. */
    public static function limpar(string $tela): void
    {
        unset($_SESSION[self::CHAVE][$tela]);
    }

    /** O usuário clicou no botão Limpar? */
    public static function pediuLimpar(): bool
    {
        return ($_GET['limpar'] ?? '') === '1';
    }

    /**
     * Guarda o filtro da tela. Valor vazio não ocupa espaço na sessão.
     *
     * @param array<string,string> $filtro
     */
    private static function guardar(string $tela, array $filtro): void
    {
        $limpo = array_filter($filtro, static fn ($v) => is_string($v) && $v !== '');

        if ($limpo === []) {
            self::limpar($tela);
            return;
        }
        $_SESSION[self::CHAVE][$tela] = $limpo;
    }

    /**
     * Algum campo do filtro veio na URL?
     *
     * @param array<int,string> $campos
     */
    private static function veioDaQuery(array $campos): bool
    {
        foreach ($campos as $campo) {
            if (array_key_exists($campo, $_GET)) {
                return true;
            }
        }
        return false;
    }
}
