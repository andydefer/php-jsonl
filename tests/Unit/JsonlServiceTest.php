<?php

declare(strict_types=1);

namespace AndyDefer\PhpJsonl\Tests\Integration;

use AndyDefer\DomainStructures\Enums\PhpType;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\PhpJsonl\Contexts\JsonlContext;
use AndyDefer\PhpJsonl\JsonlService;
use AndyDefer\PhpJsonl\Records\CacheJsonlRecord;
use AndyDefer\PhpJsonl\Records\LogJsonlRecord;
use AndyDefer\PhpJsonl\Records\TemporalLogQueryRecord;
use AndyDefer\PhpJsonl\Strategies\KeyBasedPathStrategy;
use AndyDefer\PhpJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\PhpJsonl\Tests\TestCase;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

final class JsonlServiceTest extends TestCase
{
    private JsonlService $service;

    private FileSystemService $fileSystem;

    private string $tempDir;

    private TemporalPathStrategy $temporalStrategy;

    private KeyBasedPathStrategy $keyBasedStrategy;

    private JsonlContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileSystem = new FileSystemService;
        $this->tempDir = sys_get_temp_dir().'/jsonl_test_'.uniqid();
        $this->fileSystem->makeDirectory($this->tempDir, PermissionMode::DIRECTORY, true);

        $this->temporalStrategy = new TemporalPathStrategy($this->tempDir);
        $this->keyBasedStrategy = new KeyBasedPathStrategy($this->tempDir, 2);
        $this->context = new JsonlContext;

        $this->service = $this->createTemporalService();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function getFileContent(string $filePath): string
    {
        return $this->fileSystem->get($filePath);
    }

    private function createTemporalService(): JsonlService
    {
        return new JsonlService(
            pathStrategy: $this->temporalStrategy,
            fileSystem: $this->fileSystem,
            context: $this->context,
        );
    }

    private function createKeyBasedService(): JsonlService
    {
        return new JsonlService(
            pathStrategy: $this->keyBasedStrategy,
            fileSystem: $this->fileSystem,
            context: $this->context,
        );
    }

    private function createBufferedService(int $bufferSize): JsonlService
    {
        return new JsonlService(
            pathStrategy: $this->temporalStrategy,
            fileSystem: $this->fileSystem,
            context: $this->context,
            defaultBufferSize: $bufferSize,
        );
    }

    // ============================================================
    // Tests pour write() avec LogJsonlRecord
    // ============================================================

    public function test_write_log_record_creates_file_with_correct_content(): void
    {
        // Arrange
        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123, 'username' => 'john_doe']),
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        // Act
        $this->service->write($record);

        // Assert
        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $lines = explode("\n", trim($content));
        $this->assertCount(1, $lines);

        $data = json_decode($lines[0], true);
        $this->assertSame('2026-01-15T14:35:00+00:00', $data['time']);
        $this->assertSame('info', $data['level']);
        $this->assertSame('user_login', $data['type']);
        $this->assertSame(123, $data['payload']['user_id']);
        $this->assertSame('john_doe', $data['payload']['username']);
    }

    public function test_write_multiple_log_records_appends_to_same_file(): void
    {
        // Arrange
        $record1 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        $record2 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:36:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 456]),
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        // Act
        $this->service->write($record1);
        $this->service->write($record2);

        // Assert
        $content = $this->getFileContent($expectedPath);
        $lines = explode("\n", trim($content));
        $this->assertCount(2, $lines);

        $data1 = json_decode($lines[0], true);
        $data2 = json_decode($lines[1], true);

        $this->assertSame(123, $data1['payload']['user_id']);
        $this->assertSame(456, $data2['payload']['user_id']);
    }

    public function test_write_log_record_creates_different_files_for_different_days(): void
    {
        // Arrange
        $recordDay1 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $recordDay2 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-16T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $expectedPathDay1 = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $expectedPathDay2 = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-16',
            '14.jsonl',
        ]);

        // Act
        $this->service->write($recordDay1);
        $this->service->write($recordDay2);

        // Assert
        $this->assertFileExists($expectedPathDay1);
        $this->assertFileExists($expectedPathDay2);
    }

    public function test_write_log_record_creates_different_files_for_different_hours(): void
    {
        // Arrange
        $recordHour14 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $recordHour15 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T15:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $expectedPathHour14 = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $expectedPathHour15 = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '15.jsonl',
        ]);

        // Act
        $this->service->write($recordHour14);
        $this->service->write($recordHour15);

        // Assert
        $this->assertFileExists($expectedPathHour14);
        $this->assertFileExists($expectedPathHour15);
    }

    // ============================================================
    // Tests pour write() avec CacheJsonlRecord
    // ============================================================

    public function test_write_cache_record_creates_file_with_correct_content(): void
    {
        // Arrange
        $service = $this->createKeyBasedService();

        $payload = new StrictDataObject(['name' => 'John Doe', 'email' => 'john@example.com']);
        $encodedValue = json_encode($payload->toArray());

        $record = new CacheJsonlRecord(
            key: 'user_123',
            value: $encodedValue,
            expires_at: null,
        );

        // Act
        $service->write($record);

        // Assert
        $hash = md5('user_123');
        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            $hash[0],
            $hash[1],
            'user_123.jsonl',
        ]);

        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $data = json_decode(trim($content), true);

        $this->assertSame('user_123', $data['key']);
        $this->assertSame($encodedValue, $data['value']);
        $this->assertNull($data['expires_at']);
    }

    public function test_write_cache_record_with_expiration_creates_file_with_expires_at(): void
    {
        // Arrange
        $service = $this->createKeyBasedService();

        $payload = new StrictDataObject(['name' => 'John Doe']);
        $encodedValue = json_encode($payload->toArray());

        $record = new CacheJsonlRecord(
            key: 'session_abc',
            value: $encodedValue,
            expires_at: new DateTimeVO('+1 hour'),
        );

        // Act
        $service->write($record);

        // Assert
        $hash = md5('session_abc');
        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            $hash[0],
            $hash[1],
            'session_abc.jsonl',
        ]);

        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $data = json_decode(trim($content), true);

        $this->assertArrayHasKey('expires_at', $data);
        $this->assertNotNull($data['expires_at']);
    }

    public function test_write_cache_record_sanitizes_dangerous_characters_in_key(): void
    {
        // Arrange
        $service = $this->createKeyBasedService();

        $record = new CacheJsonlRecord(
            key: 'user/with/slashes?and&special@chars',
            value: json_encode(['test' => true]),
            expires_at: null,
        );

        // Act
        $service->write($record);

        // Assert
        $hash = md5('user/with/slashes?and&special@chars');
        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            $hash[0],
            $hash[1],
            'user_with_slashes_and_special_chars.jsonl',
        ]);

        $this->assertFileExists($expectedPath);
    }

    // ============================================================
    // Tests pour readAll()
    // ============================================================

    public function test_read_all_returns_all_lines_from_file(): void
    {
        // Arrange
        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info","type":"test1"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"debug","type":"test2"}'."\n".
            '{"time":"2026-01-15T14:37:00+00:00","level":"warning","type":"test3"}'."\n";
        $this->fileSystem->put($filePath, $content);

        // Act
        $result = $this->service->readAll($filePath);

        // Assert
        $this->assertCount(3, $result);
        $this->assertSame('info', $result[0]['level']);
        $this->assertSame('debug', $result[1]['level']);
        $this->assertSame('warning', $result[2]['level']);
    }

    // ============================================================
    // Tests pour getFirstLine() et getLastLine()
    // ============================================================

    public function test_get_first_line_returns_first_line_of_file(): void
    {
        // Arrange
        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info","type":"first"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"debug","type":"second"}'."\n";
        $this->fileSystem->put($filePath, $content);

        // Act
        $result = $this->service->getFirstLine($filePath);

        // Assert
        $this->assertSame('first', $result['type']);
        $this->assertSame('info', $result['level']);
    }

    public function test_get_last_line_returns_last_line_of_file(): void
    {
        // Arrange
        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info","type":"first"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"debug","type":"second"}'."\n".
            '{"time":"2026-01-15T14:37:00+00:00","level":"error","type":"last"}'."\n";
        $this->fileSystem->put($filePath, $content);

        // Act
        $result = $this->service->getLastLine($filePath);

        // Assert
        $this->assertSame('last', $result['type']);
        $this->assertSame('error', $result['level']);
    }

    // ============================================================
    // Tests pour search()
    // ============================================================

    public function test_search_returns_lines_matching_filter(): void
    {
        // Arrange
        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info","user":"john"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"info","user":"jane"}'."\n".
            '{"time":"2026-01-15T14:37:00+00:00","level":"debug","user":"john"}'."\n";
        $this->fileSystem->put($filePath, $content);

        // Act
        $result = $this->service->search($filePath, function ($line) {
            return $line['user'] === 'john';
        });

        // Assert
        $this->assertCount(2, $result);
        $this->assertSame('john', $result[0]['user']);
        $this->assertSame('john', $result[1]['user']);
    }

    // ============================================================
    // Tests pour writeBatch()
    // ============================================================

    public function test_write_batch_writes_multiple_records_at_once(): void
    {
        // Arrange
        $records = [
            new LogJsonlRecord(
                time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
                level: 'info',
                type: 'event1',
                payload: new StrictDataObject(['id' => 1]),
            ),
            new LogJsonlRecord(
                time: new DateTimeVO('2026-01-15T14:36:00+00:00'),
                level: 'info',
                type: 'event2',
                payload: new StrictDataObject(['id' => 2]),
            ),
            new LogJsonlRecord(
                time: new DateTimeVO('2026-01-15T14:37:00+00:00'),
                level: 'info',
                type: 'event3',
                payload: new StrictDataObject(['id' => 3]),
            ),
        ];

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        // Act
        $this->service->writeBatch($records);

        // Assert
        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $lines = explode("\n", trim($content));
        $this->assertCount(3, $lines);

        $data1 = json_decode($lines[0], true);
        $data2 = json_decode($lines[1], true);
        $data3 = json_decode($lines[2], true);

        $this->assertSame('event1', $data1['type']);
        $this->assertSame('event2', $data2['type']);
        $this->assertSame('event3', $data3['type']);
    }

    // ============================================================
    // Tests pour buffer
    // ============================================================

    public function test_buffer_writes_after_reaching_size(): void
    {
        // Arrange
        $service = $this->createBufferedService(3);

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        // Act - Écrire 2 records (buffer pas encore flush)
        $service->writeBuffered($record);
        $service->writeBuffered($record);

        // Vérifier que le fichier n'existe pas encore
        $this->assertFileDoesNotExist($expectedPath);

        // Act - 3ème record déclenche le flush
        $service->writeBuffered($record);

        // Assert
        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $lines = explode("\n", trim($content));
        $this->assertCount(3, $lines);
    }

    public function test_flush_buffer_writes_pending_records(): void
    {
        // Arrange
        $service = $this->createBufferedService(10);

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        // Act
        $service->writeBuffered($record);
        $service->writeBuffered($record);

        // Vérifier que le fichier n'existe pas encore
        $this->assertFileDoesNotExist($expectedPath);

        // Act - Flush manuel
        $service->flushBuffer();

        // Assert
        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $lines = explode("\n", trim($content));
        $this->assertCount(2, $lines);
    }

    // ============================================================
    // Tests pour cleanOlderThan()
    // ============================================================

    public function test_clean_older_than_deletes_files_older_than_specified_days(): void
    {
        // Arrange
        $oldFile = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-01',
            '10.jsonl',
        ]);

        $newFile = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($oldFile));
        $this->fileSystem->ensureDirectoryExists(dirname($newFile));
        $this->fileSystem->put($oldFile, '{"test":"old"}');
        $this->fileSystem->put($newFile, '{"test":"new"}');

        touch($oldFile, strtotime('-31 days'));
        touch($newFile, strtotime('-1 day'));

        // Act
        $deletedCount = $this->service->cleanOlderThan(30, $this->tempDir);

        // Assert
        $this->assertSame(1, $deletedCount);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($newFile);
    }

    // ============================================================
    // Tests pour cleanExpired()
    // ============================================================

    public function test_clean_expired_removes_expired_cache_entries(): void
    {
        // Arrange
        $service = $this->createKeyBasedService();

        $payload = new StrictDataObject(['data' => 'test']);
        $encodedValue = json_encode($payload->toArray());

        $expiredRecord = new CacheJsonlRecord(
            key: 'expired_key',
            value: $encodedValue,
            expires_at: new DateTimeVO('-1 hour'),
        );

        $validRecord = new CacheJsonlRecord(
            key: 'valid_key',
            value: $encodedValue,
            expires_at: new DateTimeVO('+1 hour'),
        );

        $service->write($expiredRecord);
        $service->write($validRecord);

        $hashExpired = md5('expired_key');
        $expiredFilePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            $hashExpired[0],
            $hashExpired[1],
            'expired_key.jsonl',
        ]);
        $this->assertFileExists($expiredFilePath, 'Expired record file was not created');

        $hashValid = md5('valid_key');
        $validFilePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            $hashValid[0],
            $hashValid[1],
            'valid_key.jsonl',
        ]);
        $this->assertFileExists($validFilePath, 'Valid record file was not created');

        // Act
        $deletedCount = $service->cleanExpired($this->tempDir, function ($line) {
            if (! isset($line['expires_at'])) {
                return false;
            }
            $expiresAt = new DateTimeVO($line['expires_at']);
            $now = new DateTimeVO;

            return $expiresAt->isBefore($now);
        });

        // Assert
        $this->assertSame(1, $deletedCount, 'Expected 1 expired entry to be deleted');
        $this->assertFileDoesNotExist($expiredFilePath, 'Expired record file should be deleted');
        $this->assertFileExists($validFilePath, 'Valid record file should still exist');
    }

    // ============================================================
    // Tests pour cleanByPattern()
    // ============================================================

    public function test_clean_by_pattern_deletes_files_matching_pattern(): void
    {
        // Arrange
        $file1 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '14.jsonl']);
        $file2 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '15.jsonl']);
        $file3 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-16', '10.jsonl']);

        $this->fileSystem->ensureDirectoryExists(dirname($file1));
        $this->fileSystem->put($file1, '{"test":1}');
        $this->fileSystem->put($file2, '{"test":2}');
        $this->fileSystem->put($file3, '{"test":3}');

        $pattern = $this->tempDir.DIRECTORY_SEPARATOR.'2026-01-15'.DIRECTORY_SEPARATOR.'*.jsonl';

        // Act
        $deletedCount = $this->service->cleanByPattern($pattern);

        // Assert
        $this->assertSame(2, $deletedCount);
        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);
        $this->assertFileExists($file3);
    }

    // ============================================================
    // Tests pour dryRun()
    // ============================================================

    public function test_dry_run_returns_files_to_delete_without_deleting(): void
    {
        // Arrange
        $file1 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '14.jsonl']);
        $file2 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '15.jsonl']);

        $this->fileSystem->ensureDirectoryExists(dirname($file1));
        $this->fileSystem->put($file1, '{"test":1}');
        $this->fileSystem->put($file2, '{"test":2}');

        touch($file1, strtotime('-31 days'));
        touch($file2, strtotime('-1 day'));

        // Act
        $filesToDelete = $this->service->dryRun($this->tempDir, function ($file) {
            return filemtime($file) < strtotime('-30 days');
        });

        // Assert
        $this->assertCount(1, $filesToDelete);
        $this->assertStringContainsString('2026-01-15', $filesToDelete[0]);
        $this->assertStringContainsString('14.jsonl', $filesToDelete[0]);
        $this->assertFileExists($file1);
        $this->assertFileExists($file2);
    }

    // ============================================================
    // Tests pour clear()
    // ============================================================

    public function test_clear_deletes_all_jsonl_files_in_directory(): void
    {
        // Arrange
        $file1 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '14.jsonl']);
        $file2 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '15.jsonl']);
        $file3 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-16', '10.jsonl']);

        $this->fileSystem->ensureDirectoryExists(dirname($file1));
        $this->fileSystem->put($file1, '{"test":1}');
        $this->fileSystem->put($file2, '{"test":2}');
        $this->fileSystem->put($file3, '{"test":3}');

        // Act
        $deletedCount = $this->service->clear($this->tempDir);

        // Assert
        $this->assertSame(3, $deletedCount);
        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);
        $this->assertFileDoesNotExist($file3);
    }

    // ============================================================
    // Tests pour isExpired()
    // ============================================================

    public function test_is_expired_returns_true_for_expired_record(): void
    {
        // Arrange
        $record = new CacheJsonlRecord(
            key: 'test',
            value: '',
            expires_at: new DateTimeVO('-1 hour'),
        );

        // Act
        $result = $this->service->isExpired($record);

        // Assert
        $this->assertTrue($result);
    }

    public function test_is_expired_returns_false_for_valid_record(): void
    {
        // Arrange
        $record = new CacheJsonlRecord(
            key: 'test',
            value: '',
            expires_at: new DateTimeVO('+1 hour'),
        );

        // Act
        $result = $this->service->isExpired($record);

        // Assert
        $this->assertFalse($result);
    }

    public function test_is_expired_returns_false_for_record_without_expiration(): void
    {
        // Arrange
        $record = new CacheJsonlRecord(
            key: 'test',
            value: '',
            expires_at: null,
        );

        // Act
        $result = $this->service->isExpired($record);

        // Assert
        $this->assertFalse($result);
    }

    // ============================================================
    // Tests pour decodeCacheValue()
    // ============================================================

    public function test_decode_cache_value_returns_strict_data_object(): void
    {
        // Arrange
        $originalData = ['name' => 'John', 'age' => 30, 'active' => true];
        $encodedValue = json_encode($originalData);

        // Act
        $result = $this->service->decodeCacheValue($encodedValue, PhpType::STRING->value);

        // Assert
        $this->assertInstanceOf(StrictDataObject::class, $result);
        $this->assertSame('John', $result->name);
        $this->assertSame(30, $result->age);
        $this->assertTrue($result->active);
    }

    // ============================================================
    // Tests pour getFilePath() et getFilesToScan()
    // ============================================================

    public function test_get_file_path_returns_path_from_strategy(): void
    {
        // Arrange
        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        // Act
        $result = $this->service->getFilePath($record);

        // Assert
        $expected = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);
        $this->assertSame($expected, $result);
    }

    public function test_get_files_to_scan_returns_files_from_strategy(): void
    {
        // Arrange
        $query = new TemporalLogQueryRecord(
            from: new DateTimeVO('2026-01-15T00:00:00+00:00'),
            to: new DateTimeVO('2026-01-15T23:59:59+00:00'),
        );

        // Act
        $result = $this->service->getFilesToScan($query);

        // Assert
        $this->assertCount(24, $result);
    }

    // ============================================================
    // Tests pour setPathStrategy()
    // ============================================================

    public function test_set_path_strategy_changes_strategy(): void
    {
        // Arrange
        $service = $this->createTemporalService();
        $keyBasedStrategy = new KeyBasedPathStrategy($this->tempDir, 2);

        $record = new CacheJsonlRecord(
            key: 'user_123',
            value: '',
            expires_at: null,
        );

        // Act
        $service->setPathStrategy($keyBasedStrategy);
        $result = $service->getFilePath($record);

        // Assert
        $this->assertStringContainsString('user_123.jsonl', $result);
        $this->assertStringNotContainsString('2026-01-15', $result);
    }

    // ============================================================
    // Tests pour getContext() et resetProcessingState()
    // ============================================================

    public function test_get_context_returns_the_context(): void
    {
        // Act
        $context = $this->service->getContext();

        // Assert
        $this->assertSame($this->context, $context);
    }

    public function test_reset_processing_state_resets_context(): void
    {
        // Arrange
        $this->service->write(new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        ));

        // Act
        $result = $this->service->resetProcessingState();

        // Assert
        $this->assertSame($this->service, $result);
        $this->assertFalse($this->context->hasError());
        $this->assertTrue($this->context->isIdle());
    }
}
