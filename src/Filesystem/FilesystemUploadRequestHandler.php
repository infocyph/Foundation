<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Pathwise\Results\ChunkUploadState;
use Infocyph\Pathwise\StreamHandler\UploadSource;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Request;

final readonly class FilesystemUploadRequestHandler
{
    public function __construct(private FilesystemTransferFactory $transfers) {}

    public function finalizeChunkUpload(string $uploadId, ?string $directory = null, ?string $disk = null): string
    {
        return $this->transfers->upload($directory, $disk)->finalizeChunkUpload($uploadId);
    }

    public function processChunkUploadRequest(
        Request $request,
        string $field = 'file',
        ?string $uploadId = null,
        ?int $chunkIndex = null,
        ?int $totalChunks = null,
        ?string $originalFilename = null,
        ?string $directory = null,
        ?string $disk = null,
    ): ChunkUploadState {
        $processor = $this->transfers->upload($directory, $disk);
        $file = $this->uploadedFile($request, $field);
        $resolvedUploadId = $this->resolveString(
            $uploadId,
            [$request->data('upload_id'), $request->data('uploadId')],
            'upload ID',
        );
        $resolvedChunkIndex = $this->resolveInt(
            $chunkIndex,
            [$request->data('chunk_index'), $request->data('chunkIndex')],
            'chunk index',
        );
        $resolvedTotalChunks = $this->resolveInt(
            $totalChunks,
            [$request->data('total_chunks'), $request->data('totalChunks')],
            'total chunks',
        );
        $resolvedFilename = $this->resolveFilename($originalFilename, $request, $file, $field);

        return $processor->processChunkUploadSource(
            $this->source($file, $resolvedFilename),
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
        $file = $this->uploadedFile($request, $field);
        $clientName = $file->getClientFilename() ?? $field;

        return $this->transfers
            ->upload($directory, $disk)
            ->ingestSource($this->source($file, $clientName));
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

    /** @param list<mixed> $candidates */
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

        throw new \InvalidArgumentException(sprintf(
            'Unable to resolve the %s for the chunk upload request.',
            $label,
        ));
    }

    /** @param list<mixed> $candidates */
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

        throw new \InvalidArgumentException(sprintf(
            'Unable to resolve the %s for the chunk upload request.',
            $label,
        ));
    }

    private function source(UploadedFile $file, string $fallbackName): UploadSource
    {
        return UploadSource::fromMover(
            static function (string $target) use ($file): void {
                $file->moveTo($target);
            },
            $file->getClientFilename() ?? $fallbackName,
            $file->getSize(),
            $file->getClientMediaType(),
            $file->getError(),
        );
    }

    private function uploadedFile(Request $request, string $field): UploadedFile
    {
        $file = $request->file($field);
        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException(sprintf(
                'Uploaded file field "%s" is missing or invalid.',
                $field,
            ));
        }

        return $file;
    }
}
