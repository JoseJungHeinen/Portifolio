<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** Violação de regra de negócio, com mensagem própria para o usuário. */
class RegraException extends RuntimeException
{
}
