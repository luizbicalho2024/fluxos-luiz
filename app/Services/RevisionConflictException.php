<?php

namespace App\Services;

use RuntimeException;

class RevisionConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $currentRevision,
        public readonly array $currentRecord
    ) {
        parent::__construct('O fluxo foi alterado por outro usuário.');
    }
}
