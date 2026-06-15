<?php

declare(strict_types=1);

namespace AndyDefer\PhpJsonl\Contexts;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Collections\Utility\IntTypedCollection;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\PhpJsonl\Enums\OperationType;
use AndyDefer\PhpJsonl\ValueObjects\JsonlLockVO;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

/**
 * Unified context for JSONL service state management.
 *
 * Holds lock state, buffer state, and processing state for the JSONL service.
 *
 * @author Andy Defer
 */
final class JsonlContext
{
    // ============================================================
    // Lock State
    // ============================================================

    /** @var array<string, JsonlLockVO> */
    private array $locks = [];

    // ============================================================
    // Buffer State
    // ============================================================

    /** @var array<string, array<AbstractRecord>> */
    private array $buffer = [];

    private int $bufferSize = 0;

    /** @var callable(string, int):void|null */
    private $onFlushCallback = null;

    // ============================================================
    // Processing State
    // ============================================================

    private OperationType $currentOperation;

    private StringTypedCollection $processedFiles;

    private IntTypedCollection $writtenLines;

    private ?string $lastError;

    private int $totalLinesProcessed;

    private float $startTime;

    private ?float $endTime;

    // ============================================================
    // Constructor
    // ============================================================

    public function __construct()
    {
        $this->currentOperation = OperationType::IDLE;
        $this->processedFiles = new StringTypedCollection;
        $this->writtenLines = new IntTypedCollection;
        $this->lastError = null;
        $this->totalLinesProcessed = 0;
        $this->startTime = microtime(true);
        $this->endTime = null;
    }

    // ============================================================
    // Lock Management
    // ============================================================

    /**
     * Add a lock to the context.
     */
    public function addLock(string $key, JsonlLockVO $lock): void
    {
        $this->locks[$key] = $lock;
    }

    /**
     * Remove a lock from the context.
     */
    public function removeLock(string $key): void
    {
        unset($this->locks[$key]);
    }

    /**
     * Check if a lock exists in the context.
     */
    public function hasLock(string $key): bool
    {
        return isset($this->locks[$key]) && $this->locks[$key]->isAcquired();
    }

    /**
     * Get a lock from the context.
     */
    public function getLock(string $key): ?JsonlLockVO
    {
        return $this->locks[$key] ?? null;
    }

    /**
     * Get all active locks.
     *
     * @return array<string, JsonlLockVO>
     */
    public function getAllLocks(): array
    {
        return $this->locks;
    }

    /**
     * Clear all locks.
     */
    public function clearLocks(): void
    {
        $this->locks = [];
    }

    // ============================================================
    // Buffer Management
    // ============================================================

    /**
     * Get the entire buffer.
     *
     * @return array<string, array<AbstractRecord>>
     */
    public function getBuffer(): array
    {
        return $this->buffer;
    }

    /**
     * Add an entity to the buffer for a specific file path.
     */
    public function addToBuffer(string $filePath, AbstractRecord $entity): void
    {
        if (! isset($this->buffer[$filePath])) {
            $this->buffer[$filePath] = [];
        }

        $this->buffer[$filePath][] = $entity;
    }

    /**
     * Get buffered entities for a specific file path.
     *
     * @return array<AbstractRecord>
     */
    public function getFileBuffer(string $filePath): array
    {
        return $this->buffer[$filePath] ?? [];
    }

    /**
     * Clear buffer for a specific file path.
     */
    public function clearFileBuffer(string $filePath): void
    {
        unset($this->buffer[$filePath]);
    }

    /**
     * Clear all buffers.
     */
    public function clearAllBuffers(): void
    {
        $this->buffer = [];
    }

    /**
     * Set the buffer size limit.
     */
    public function setBufferSize(int $size): void
    {
        $this->bufferSize = $size;
    }

    /**
     * Get the current buffer size limit.
     */
    public function getBufferSize(): int
    {
        return $this->bufferSize;
    }

    /**
     * Check if buffering is enabled.
     */
    public function isBufferEnabled(): bool
    {
        return $this->bufferSize > 0;
    }

    /**
     * Set the on-flush callback.
     *
     * @param  callable(string, int):void|null  $callback
     */
    public function setOnFlushCallback(?callable $callback): void
    {
        $this->onFlushCallback = $callback;
    }

    /**
     * Get the on-flush callback.
     *
     * @return callable(string, int):void|null
     */
    public function getOnFlushCallback(): ?callable
    {
        return $this->onFlushCallback;
    }

    /**
     * Execute the on-flush callback if defined.
     */
    public function triggerOnFlush(string $filePath, int $count): void
    {
        if ($this->onFlushCallback !== null) {
            call_user_func($this->onFlushCallback, $filePath, $count);
        }
    }

    // ============================================================
    // Processing State - Getters
    // ============================================================

    public function getCurrentOperation(): OperationType
    {
        return $this->currentOperation;
    }

    public function getProcessedFiles(): StringTypedCollection
    {
        return $this->processedFiles;
    }

    public function getWrittenLines(): IntTypedCollection
    {
        return $this->writtenLines;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getTotalLinesProcessed(): int
    {
        return $this->totalLinesProcessed;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getEndTime(): ?float
    {
        return $this->endTime;
    }

    public function getDuration(): ?float
    {
        return $this->endTime !== null ? $this->endTime - $this->startTime : null;
    }

    public function getStartDateTime(): DateTimeVO
    {
        return new DateTimeVO(date('c', (int) $this->startTime));
    }

    // ============================================================
    // Processing State - Setters
    // ============================================================

    public function setCurrentOperation(OperationType $operation): void
    {
        $this->currentOperation = $operation;
    }

    public function addProcessedFile(string $filePath): void
    {
        $this->processedFiles->add($filePath);
    }

    public function addWrittenLines(string $filePath, int $count): void
    {
        $this->writtenLines->add($count);
        $this->totalLinesProcessed += $count;
    }

    public function setLastError(string $error): void
    {
        $this->lastError = $error;
        $this->currentOperation = OperationType::FAILED;
    }

    public function complete(): void
    {
        $this->endTime = microtime(true);
        $this->currentOperation = OperationType::COMPLETED;
    }

    public function reset(): void
    {
        $this->currentOperation = OperationType::IDLE;
        $this->processedFiles = new StringTypedCollection;
        $this->writtenLines = new IntTypedCollection;
        $this->lastError = null;
        $this->totalLinesProcessed = 0;
        $this->startTime = microtime(true);
        $this->endTime = null;
    }

    // ============================================================
    // Processing State - Questions
    // ============================================================

    public function hasError(): bool
    {
        return $this->lastError !== null;
    }

    public function isCompleted(): bool
    {
        return $this->currentOperation === OperationType::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->currentOperation === OperationType::FAILED;
    }

    public function isIdle(): bool
    {
        return $this->currentOperation === OperationType::IDLE;
    }
}
