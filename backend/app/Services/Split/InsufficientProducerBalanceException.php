<?php

declare(strict_types=1);

namespace App\Services\Split;

use RuntimeException;

/**
 * O produtor não tem saldo disponível suficiente para liquidar o split agora.
 *
 * Situação transitória, não erro de configuração: pode acontecer quando parte
 * do líquido está retida (rolling reserve) ou quando um saque saiu antes do
 * split rodar. O split continua `pending` para ser retentado depois — marcá-lo
 * como `failed` o tiraria da fila para sempre e o recebedor nunca receberia.
 */
final class InsufficientProducerBalanceException extends RuntimeException
{
}
