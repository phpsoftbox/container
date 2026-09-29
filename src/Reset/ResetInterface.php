<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Reset;

/**
 * Сервис с состоянием, которое нужно сбрасывать между задачами долгоживущего процесса (воркер очереди, HTTP-воркер).
 */
interface ResetInterface
{
    public function reset(): void;
}
