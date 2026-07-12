<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Request;

final readonly class FilesystemUploadRequestHandler
{
    public function __construct(private FilesystemManager $files) {}

    public function finalizeChunkUpload(string $uploadId, ?string $directory = null, ?string $disk = null): string
    {
        return $this->files->upload($directory, $disk)->finalizeChunkUpload($uploadId);
    }

    /**
     * @return array{uploadId: string, receivedChunks: int, totalChunks: int, isComplete: bool}
     */
    public function processChunkUploadRequest(
        Request $request,
        string $field = 'file',
        ?string $uploadId = null,
        ?int $chunkIndex = null,
        ?int $totalChunks = null,
        ?string $originalFilename = null,
        ?string $directory = null,
        ?string $disk = null,
    ): array {
        $processor = $this->files->upload($directory, $disk);
        $file = $this->uploadedFile($request, $field);
        $resolvedUploadId = $this->resolveString(
            $uploadId,
            [
                $request->data('upload_id'),
                $request->data('uploadId'),
            ],
            'upload ID',
        );
        $resolvedChunkIndex = $this->resolveInt(
            $chunkIndex,
            [
                $request->data('chunk_index'),
                $request->data('chunkIndex'),
            ],
            'chunk index',
        );
        $resolvedTotalChunks = $this->resolveInt(
            $totalChunks,
            [
                $request->data('total_chunks'),
                $request->data('totalChunks'),
            ],
            'total chunks',
        );
        $resolvedFilename = $this->resolveFilename($originalFilename, $request, $file, $field);
        $payload = $this->materializeUpload($file, $processor, $resolvedFilename);

        return $processor->processChunkUpload(
            $payload,
            $resolvedUploadId,
            $resolvedChunkIndex,
            $resolvedTotalChunks,
            $resolvedFilename,
        );
    }

    public function processUploadRequest(
        Request $request,
        string $field = 'file',
        ?string $directory = null,
        ?string $disk = null,
    ): string {
        $processor = $this->files->upload($directory, $disk);
        $file = $this->uploadedFile($request, $field);
        $payload = $this->materializeUpload(
            $file,
            $processor,
            $file->getClientFilename() ?? $field,
        );

        return $processor->processUpload($payload);
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create upload temp directory "%s".', $directory));
        }
    }

    /**
     * @return array{
     *   error: int,
     *   size: int,
     *   tmp_name: string,
     *   name: string,
     *   type: string|null
     * }
     */
    private function materializeUpload(UploadedFile $file, UploadProcessor $processor, string $fallbackName): array
    {
        $clientName = $file->getClientFilename() ?? $fallbackName;
        $size = $file->getSize() ?? 0;
        $tempDirectory = $this->tempDirectory($processor);
        $extension = pathinfo($clientName, PATHINFO_EXTENSION);
        $suffix = $extension === ''
            ? ''
            : '.' . strtolower(ltrim($extension, '.'));
        $targetPath = rtrim($tempDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'foundation-upload-'
            . bin2hex(random_bytes(8))
            . $suffix;

        $this->ensureDirectory($tempDirectory);
        $file->moveTo($targetPath);

        return [
            'error' => $file->getError(),
            'size' => $size > 0 ? $size : (filesize($targetPath) ?: 0),
            'tmp_name' => $targetPath,
            'name' => $clientName,
            'type' => $file->getClientMediaType(),
        ];
    }

    private function resolveFilename(?string $filename, Request $request, UploadedFile $file, string $field): string
    {
        if (is_string($filename) && trim($filename) !== '') {
            return trim($filename);
        }

        $requestFilename = $request->data('original_filename') ?? $request->data('originalFilename');

        if (is_string($requestFilename) && trim($requestFilename) !== '') {
            return trim($requestFilename);
        }

        return $file->getClientFilename() ?? $field;
    }

    /**
     * @param list<mixed> $candidates
     */
    private function resolveInt(?int $value, array $candidates, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }

        foreach ($candidates as $candidate) {
            if (is_int($candidate)) {
                return $candidate;
            }

            if (is_string($candidate) && is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unable to resolve the %s for the chunk upload request.', $label));
    }

    /**
     * @param list<mixed> $candidates
     */
    private function resolveString(?string $value, array $candidates, string $label): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        throw new \InvalidArgumentException(sprintf('Unable to resolve the %s for the chunk upload request.', $label));
    }

    private function tempDirectory(UploadProcessor $processor): string
    {
        $info = $processor->getInfo();
        $tempDirectory = trim($info['tempDir']);

        if ($tempDirectory === '') {
            return sys_get_temp_dir();
        }

        return $tempDirectory;
    }

    private function uploadedFile(Request $request, string $field): UploadedFile
    {
        $file = $request->file($field);

        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException(sprintf('Uploaded file field "%s" is missing or invalid.', $field));
        }

        return $file;
    }
}
