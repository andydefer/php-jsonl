<?php

declare(strict_types=1);

namespace AndyDefer\PhpJsonl\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\PhpJsonl\Collections\HashLevelCollection;
use AndyDefer\PhpJsonl\Enums\HashLevel;

final class HashLevelsVO extends AbstractValueObject
{
    private string $value;

    private HashLevelCollection $levels;

    public function __construct(string $key, int $levelCount = 2)
    {
        $hash = md5($key);
        $levels = new HashLevelCollection;

        for ($i = 0; $i < $levelCount; $i++) {
            $levels->add(HashLevel::fromChar($hash[$i]));
        }

        $this->levels = $levels;
        $this->value = $levels->toPathString(DIRECTORY_SEPARATOR);
    }

    public function getLevels(): HashLevelCollection
    {
        return $this->levels;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function toArray(): array
    {
        return $this->levels->toArray();
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
