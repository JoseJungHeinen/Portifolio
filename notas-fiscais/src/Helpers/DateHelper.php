<?php
// @versao Sys_16 V_01_16/07/2026
declare(strict_types=1);

/**
 * Helper de formatação e cálculo de datas.
 * Todas as datas são tratadas como strings ISO (Y-m-d) internamente
 * e formatadas apenas na camada de apresentação.
 */
class DateHelper
{
    /**
     * Converte data do formato ISO (Y-m-d) para formato brasileiro (d/m/Y).
     *
     * @param string $date Data no formato Y-m-d
     * @return string Data no formato d/m/Y
     * @throws InvalidArgumentException se o formato for inválido
     */
    public static function formatBr(string $date): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dt) {
            throw new InvalidArgumentException("Data inválida para formatação: {$date}");
        }
        return $dt->format('d/m/Y');
    }

    /**
     * Formata datetime (Y-m-d H:i:s ou timestamp MySQL) para d/m/Y H:i.
     *
     * @param string $datetime String de data/hora
     * @return string No formato d/m/Y H:i
     */
    public static function formatBrDatetime(string $datetime): string
    {
        // Aceitar tanto 'Y-m-d H:i:s' quanto 'Y-m-d H:i'
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime)
           ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', $datetime);

        if (!$dt) {
            throw new InvalidArgumentException("Datetime inválido para formatação: {$datetime}");
        }
        return $dt->format('d/m/Y H:i');
    }

    /**
     * Verifica se uma data já passou (é anterior a hoje).
     *
     * @param string $date Data no formato Y-m-d
     * @return bool true se a data for anterior a hoje
     */
    public static function isExpired(string $date): bool
    {
        $dt    = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        $hoje  = new \DateTimeImmutable('today');
        return $dt < $hoje;
    }

    /**
     * Calcula quantos dias faltam até a data (ou quantos dias passaram se negativo).
     *
     * @param string $date Data no formato Y-m-d
     * @return int Dias positivos = futuro; negativos = passado; 0 = hoje
     */
    public static function daysUntil(string $date): int
    {
        $dt   = \DateTimeImmutable::createFromFormat('Y-m-d', $date)->setTime(0, 0, 0);
        $hoje = new \DateTimeImmutable('today');
        $diff = $hoje->diff($dt);
        // $diff->invert = 1 quando a data é no passado
        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Verifica se uma data vence em até N dias (inclusive hoje).
     * Retorna true também se a data já estiver vencida.
     *
     * @param string $date Data no formato Y-m-d
     * @param int    $days Número de dias de antecedência (padrão 7)
     * @return bool true se a data vence em até $days dias
     */
    public static function isNearExpiry(string $date, int $days = 7): bool
    {
        $diasRestantes = self::daysUntil($date);
        return $diasRestantes <= $days;
    }

    /**
     * Retorna a data atual no formato Y-m-d.
     */
    public static function hoje(): string
    {
        return date('Y-m-d');
    }

    /**
     * Retorna o primeiro dia do mês atual no formato Y-m-d.
     */
    public static function primeiroDiaMes(): string
    {
        return date('Y-m-01');
    }

    /**
     * Retorna o último dia do mês atual no formato Y-m-d.
     */
    public static function ultimoDiaMes(): string
    {
        return date('Y-m-t');
    }

    /**
     * Calcula a data de vencimento de uma parcela.
     * Adiciona N meses à data base.
     *
     * @param string $dataBase    Data inicial no formato Y-m-d
     * @param int    $mesesAdicionar Quantidade de meses a adicionar
     * @return string Data no formato Y-m-d
     */
    public static function adicionarMeses(string $dataBase, int $mesesAdicionar): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $dataBase);
        return $dt->modify("+{$mesesAdicionar} months")->format('Y-m-d');
    }
}
