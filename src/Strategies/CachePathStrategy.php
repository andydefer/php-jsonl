<?php

declare(strict_types=1);

namespace AndyDefer\PhpJsonl\Strategies;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\PhpJsonl\Records\CacheRecord;
use InvalidArgumentException;

/**
 * Path strategy for cache storage using JSONL.
 *
 * Structure: {basePath}/{hashLevels}/{key}.jsonl
 * - hashLevels: number of directory levels from MD5 hash (1-4)
 */
final class CachePathStrategy implements JsonlPathStrategyInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly int $hashLevels = 2,
    ) {}

    public function getFilePath(AbstractRecord $entity): string
    {
        if (! $entity instanceof CacheRecord) {
            throw new InvalidArgumentException(
                sprintf('CachePathStrategy expects CacheRecord, got %s', get_class($entity))
            );
        }

        return $this->getFilePathForKey($entity->key);
    }

    public function getFilesToScan(AbstractRecord $query): array
    {
        // Pour le cache, on ne scanne jamais - on accède directement par clé
        // Le query serait un CacheKeyQueryRecord si on implémente la recherche
        return [];
    }

    public function getBaseDirectory(): string
    {
        return $this->basePath;
    }

    public function getFilePathForKey(string $key): string
    {
        $hash = md5($key);
        $levels = $this->getHashLevels($hash);

        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->basePath, DIRECTORY_SEPARATOR),
            $levels,
            $this->sanitizeKey($key).'.jsonl',
        ]);
    }

    private function getHashLevels(string $hash): string
    {
        $levels = [];
        for ($i = 0; $i < $this->hashLevels; $i++) {
            $levels[] = $hash[$i];
        }

        return implode(DIRECTORY_SEPARATOR, $levels);
    }

    private function sanitizeKey(string $key): string
    {
        // Supprime les caractères dangereux pour le système de fichiers
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $key);
    }
}
