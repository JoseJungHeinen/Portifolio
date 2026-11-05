<?php
declare(strict_types=1);

namespace App\Support;

/** Erros de validação por campo: ['campo' => 'mensagem']. */
class ValidacaoException extends RegraException
{
    public function __construct(public readonly array $erros)
    {
        parent::__construct(implode(' ', $erros));
    }
}
