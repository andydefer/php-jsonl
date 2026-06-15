<?php

declare(strict_types=1);

namespace AndyDefer\PhpJsonl\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

/**
 * Cache record DTO - PURE DATA CONTAINER ONLY.
 * NO LOGIC WHATSOEVER.
 */
final class CacheRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
        public readonly ?DateTimeVO $expires_at = null,
        public readonly ?DateTimeVO $created_at = null,
    ) {}
}
