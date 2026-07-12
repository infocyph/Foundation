<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use DateTimeInterface;
use Infocyph\Foundation\Filesystem\FilesystemManager;
use Infocyph\Foundation\Filesystem\FilesystemResponseFactory;
use Infocyph\Foundation\Filesystem\FilesystemUploadRequestHandler;
use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\PathwiseFacade;
use Infocyph\Pathwise\Queue\FileJobQueue;
use Infocyph\Pathwise\Security\PolicyEngine;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use League\Flysystem\FilesystemOperator;

final class Files extends Facade
{
    public static function at(string $path = '', ?string $disk = null): PathwiseFacade
    {
        return self::manager()->at($path, $disk);
    }

    public static function audit(string $logFilePath): AuditTrail
    {
        return self::manager()->audit($logFilePath);
    }

    public static function base(string $path = ''): string
    {
        return self::manager()->base($path);
    }

    public static function cache(string $path = ''): string
    {
        return self::manager()->cache($path);
    }

    public static function config(string $path = ''): string
    {
        return self::manager()->config($path);
    }

    /**
     * @return array{linked: list<string>, skipped: list<string>}
     */
    public static function deduplicate(string $directory = '', string $algorithm = 'sha256', ?string $disk = null): array
    {
        return self::manager()->deduplicate($directory, $algorithm, $disk);
    }

    /**
     * @param array<string, array{mtime: int, size: int}> $previousSnapshot
     * @param array<string, array{mtime: int, size: int}> $currentSnapshot
     * @return array<string, array<int|string, mixed>>
     */
    public static function diffSnapshots(array $previousSnapshot, array $currentSnapshot): array
    {
        return self::manager()->diffSnapshots($previousSnapshot, $currentSnapshot);
    }

    public static function disk(?string $name = null): FilesystemOperator
    {
        return self::manager()->disk($name);
    }

    public static function diskPath(string $disk, string $path = ''): string
    {
        return self::manager()->diskPath($disk, $path);
    }

    public static function download(?string $directory = null, ?string $disk = null): DownloadProcessor
    {
        return self::manager()->download($directory, $disk);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function downloadResponse(
        Request $request,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
    ): Response {
        return self::manager()->downloadResponse($request, $path, $downloadName, $directory, $disk, $headers);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function duplicates(string $directory = '', string $algorithm = 'sha256', ?string $disk = null): array
    {
        return self::manager()->duplicates($directory, $algorithm, $disk);
    }

    public static function exists(string $path, ?string $disk = null): bool
    {
        return self::manager()->exists($path, $disk);
    }

    public static function finalizeChunkUpload(string $uploadId, ?string $directory = null, ?string $disk = null): string
    {
        return self::manager()->finalizeChunkUpload($uploadId, $directory, $disk);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function index(string $directory = '', string $algorithm = 'sha256', ?string $disk = null): array
    {
        return self::manager()->index($directory, $algorithm, $disk);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function inlineResponse(
        Request $request,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
    ): Response {
        return self::manager()->inlineResponse($request, $path, $downloadName, $directory, $disk, $headers);
    }

    public static function localPath(string $path = '', ?string $disk = null): string
    {
        return self::manager()->localPath($path, $disk);
    }

    public static function logs(string $path = ''): string
    {
        return self::manager()->logs($path);
    }

    public static function manager(): FilesystemManager
    {
        return self::app()->files();
    }

    public static function path(string $path = '', ?string $disk = null): string
    {
        return self::manager()->path($path, $disk);
    }

    public static function policy(): PolicyEngine
    {
        return self::manager()->policy();
    }

    /**
     * @return array{uploadId: string, receivedChunks: int, totalChunks: int, isComplete: bool}
     */
    public static function processChunkUploadRequest(
        Request $request,
        string $field = 'file',
        ?string $uploadId = null,
        ?int $chunkIndex = null,
        ?int $totalChunks = null,
        ?string $originalFilename = null,
        ?string $directory = null,
        ?string $disk = null,
    ): array {
        return self::manager()->processChunkUploadRequest(
            $request,
            $field,
            $uploadId,
            $chunkIndex,
            $totalChunks,
            $originalFilename,
            $directory,
            $disk,
        );
    }

    public static function processUploadRequest(
        Request $request,
        string $field = 'file',
        ?string $directory = null,
        ?string $disk = null,
    ): string {
        return self::manager()->processUploadRequest($request, $field, $directory, $disk);
    }

    public static function queue(string $queueFilePath): FileJobQueue
    {
        return self::manager()->queue($queueFilePath);
    }

    public static function read(string $path, ?string $disk = null): string
    {
        return self::manager()->read($path, $disk);
    }

    public static function responses(): FilesystemResponseFactory
    {
        return self::manager()->responses();
    }

    /**
     * @return array{deleted: list<string>, kept: list<string>}
     */
    public static function retain(
        string $directory = '',
        ?int $keepLast = null,
        ?int $maxAgeDays = null,
        string $sortBy = 'mtime',
        ?string $disk = null,
    ): array {
        return self::manager()->retain($directory, $keepLast, $maxAgeDays, $sortBy, $disk);
    }

    /**
     * @return array<string, array{mtime: int, size: int}>
     */
    public static function snapshot(string $path = '', bool $recursive = true, ?string $disk = null): array
    {
        return self::manager()->snapshot($path, $recursive, $disk);
    }

    public static function storage(string $path = ''): string
    {
        return self::manager()->storage($path);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function temporaryUrl(string $path, DateTimeInterface $expiresAt, ?string $disk = null, array $config = []): string
    {
        return self::manager()->temporaryUrl($path, $expiresAt, $disk, $config);
    }

    public static function upload(?string $directory = null, ?string $disk = null): UploadProcessor
    {
        return self::manager()->upload($directory, $disk);
    }

    public static function uploadRequests(): FilesystemUploadRequestHandler
    {
        return self::manager()->uploadRequests();
    }

    /**
     * @return array<string, array{mtime: int, size: int}>
     */
    public static function watch(
        string $path,
        callable $onChange,
        int $durationSeconds = 5,
        int $intervalMilliseconds = 500,
        bool $recursive = true,
        ?string $disk = null,
    ): array {
        return self::manager()->watch($path, $onChange, $durationSeconds, $intervalMilliseconds, $recursive, $disk);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function write(string $path, string $contents, ?string $disk = null, array $config = []): void
    {
        self::manager()->write($path, $contents, $disk, $config);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function xAccelRedirectResponse(
        Request $request,
        string $internalPath,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
        bool $inline = false,
    ): Response {
        return self::manager()->xAccelRedirectResponse(
            $request,
            $internalPath,
            $path,
            $downloadName,
            $directory,
            $disk,
            $headers,
            $inline,
        );
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function xSendfileResponse(
        Request $request,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
        bool $inline = false,
    ): Response {
        return self::manager()->xSendfileResponse($request, $path, $downloadName, $directory, $disk, $headers, $inline);
    }
}
