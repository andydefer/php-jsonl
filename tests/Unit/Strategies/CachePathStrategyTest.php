<?php

declare(strict_types=1);

namespace AndyDefer\PhpJsonl\Tests\Unit\Strategies;

use AndyDefer\PhpJsonl\Records\CacheRecord;
use AndyDefer\PhpJsonl\Strategies\CachePathStrategy;
use AndyDefer\PhpJsonl\Tests\Fixtures\Records\InvalidRecordFixture;
use AndyDefer\PhpJsonl\Tests\TestCase;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;
use InvalidArgumentException;

final class CachePathStrategyTest extends TestCase
{
    private const BASE_PATH = '/test/cache';

    private const HASH_LEVELS = 2;

    public function test_get_file_path_returns_correct_path_for_cache_record(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheRecord(
            key: 'user_123',
            value: '{"name":"John"}',
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $expectedPattern = '#^/test/cache/[a-f0-9]/[a-f0-9]/user_123\.jsonl$#';
        $this->assertMatchesRegularExpression($expectedPattern, $filePath);
        $this->assertStringEndsWith('.jsonl', $filePath);
        $this->assertStringContainsString('user_123', $filePath);
    }

    public function test_get_file_path_creates_two_hash_levels_by_default(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH);
        $record = new CacheRecord(
            key: 'session_abc',
            value: '',
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert - Le chemin doit contenir 2 niveaux de hash
        $pathParts = explode(DIRECTORY_SEPARATOR, $filePath);
        $hashLevels = array_slice($pathParts, -3, 2);

        $this->assertCount(2, $hashLevels);
        $this->assertEquals(1, strlen($hashLevels[0]));
        $this->assertEquals(1, strlen($hashLevels[1]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]$/', $hashLevels[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]$/', $hashLevels[1]);
    }

    public function test_get_file_path_creates_four_hash_levels_when_configured(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, 4);
        $record = new CacheRecord(
            key: 'user_456',
            value: '',
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert - Le chemin doit contenir 4 niveaux de hash
        $pathParts = explode(DIRECTORY_SEPARATOR, $filePath);
        $hashLevels = array_slice($pathParts, -5, 4);

        $this->assertCount(4, $hashLevels);
        foreach ($hashLevels as $level) {
            $this->assertEquals(1, strlen($level));
            $this->assertMatchesRegularExpression('/^[0-9a-f]$/', $level);
        }
    }

    public function test_get_file_path_replaces_dangerous_characters_in_key(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheRecord(
            key: 'user/with/slashes?and&special@chars',
            value: '',
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $this->assertStringContainsString('user_with_slashes_and_special_chars.jsonl', $filePath);
        $fileName = basename($filePath);
        $this->assertStringNotContainsString('/', $fileName);
        $this->assertStringNotContainsString('?', $fileName);
        $this->assertStringNotContainsString('&', $fileName);
        $this->assertStringNotContainsString('@', $fileName);
    }

    public function test_get_file_path_sanitizes_key_with_unicode_characters(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheRecord(
            key: 'user_éàç_€',
            value: '',
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $fileName = basename($filePath);
        $this->assertMatchesRegularExpression('/user_.+\.jsonl/', $fileName);
        $this->assertStringEndsWith('.jsonl', $fileName);
    }

    public function test_get_file_path_preserves_allowed_characters_in_key(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheRecord(
            key: 'user-123_abc.def',
            value: '',
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $this->assertStringContainsString('user-123_abc.def.jsonl', $filePath);
    }

    public function test_get_files_to_scan_returns_empty_array_for_cache(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheRecord(
            key: 'user_123',
            value: '',
            expires_at: null,
        );

        // Act
        $files = $strategy->getFilesToScan($record);

        // Assert
        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    public function test_get_base_directory_returns_configured_base_path(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);

        // Act
        $baseDirectory = $strategy->getBaseDirectory();

        // Assert
        $this->assertSame(self::BASE_PATH, $baseDirectory);
    }

    public function test_same_key_always_returns_same_file_path(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record1 = new CacheRecord(
            key: 'consistent_key',
            value: '',
            expires_at: null,
        );
        $record2 = new CacheRecord(
            key: 'consistent_key',
            value: 'different_value',
            expires_at: null,
        );

        // Act
        $path1 = $strategy->getFilePath($record1);
        $path2 = $strategy->getFilePath($record2);

        // Assert
        $this->assertSame($path1, $path2);
    }

    public function test_different_keys_return_different_paths(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record1 = new CacheRecord(
            key: 'key_one',
            value: '',
            expires_at: null,
        );
        $record2 = new CacheRecord(
            key: 'key_two',
            value: '',
            expires_at: null,
        );

        // Act
        $path1 = $strategy->getFilePath($record1);
        $path2 = $strategy->getFilePath($record2);

        // Assert
        $this->assertNotSame($path1, $path2);
    }

    public function test_file_path_always_ends_with_jsonl_extension(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $keys = ['a', 'long_key_name', 'key-with-dashes', 'key_with_underscores', 'key.with.dots'];

        foreach ($keys as $key) {
            $record = new CacheRecord(
                key: $key,
                value: '',
                expires_at: null,
            );

            // Act
            $filePath = $strategy->getFilePath($record);

            // Assert
            $this->assertStringEndsWith('.jsonl', $filePath);
        }
    }

    public function test_file_path_contains_directory_separator(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheRecord(
            key: 'test_key',
            value: '',
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $this->assertStringContainsString(DIRECTORY_SEPARATOR, $filePath);
    }

    public function test_get_file_path_with_null_expires_at_works_correctly(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheRecord(
            key: 'test_key',
            value: '',
            expires_at: null,
        );

        // Act & Assert - Ne doit pas lever d'exception
        $filePath = $strategy->getFilePath($record);
        $this->assertIsString($filePath);
        $this->assertNotEmpty($filePath);
    }

    public function test_get_file_path_with_expires_at_value_works_correctly(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheRecord(
            key: 'test_key',
            value: '',
            expires_at: new DateTimeVO('+1 hour'),
        );

        // Act & Assert - Ne doit pas lever d'exception
        $filePath = $strategy->getFilePath($record);
        $this->assertIsString($filePath);
        $this->assertNotEmpty($filePath);
    }

    public function test_get_file_path_throws_exception_for_invalid_entity_type(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $invalidEntity = new InvalidRecordFixture;

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CachePathStrategy expects CacheRecord');

        // Act
        $strategy->getFilePath($invalidEntity);
    }

    public function test_get_file_path_for_key_returns_correct_path(): void
    {
        // Arrange
        $strategy = new CachePathStrategy(self::BASE_PATH, self::HASH_LEVELS);

        // Act
        $filePath = $strategy->getFilePathForKey('user_123');

        // Assert
        $expectedPattern = '#^/test/cache/[a-f0-9]/[a-f0-9]/user_123\.jsonl$#';
        $this->assertMatchesRegularExpression($expectedPattern, $filePath);
        $this->assertStringEndsWith('.jsonl', $filePath);
    }
}
