<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Pathwise\Results\DownloadPreparation;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\Utils\PathHelper;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Body\FileBody;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;
use Infocyph\Webrick\Response\Response;

final readonly class FilesystemResponseFactory
{
    private const int STREAM_CHUNK_SIZE = 65_536;

    public function __construct(
        private ConfigRepository $config,
        private FilesystemTransferFactory $transfers,
        private StorageRegistry $storage,
    ) {}

    /** @param array<string, string|list<string>> $headers */
    public function download(
        Request $request,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
    ): Response {
        return $this->respond(
            request: $request,
            path: $path,
            downloadName: $downloadName,
            directory: $directory,
            disk: $disk,
            headers: $headers,
            inline: false,
        );
    }

    /** @param array<string, string|list<string>> $headers */
    public function inline(
        Request $request,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
    ): Response {
        return $this->respond(
            request: $request,
            path: $path,
            downloadName: $downloadName,
            directory: $directory,
            disk: $disk,
            headers: $headers,
            inline: true,
        );
    }

    /** @param array<string, string|list<string>> $headers */
    public function xAccelRedirect(
        Request $request,
        string $internalPath,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
        bool $inline = false,
    ): Response {
        $this->assertOffloadEnabled('x_accel_redirect', 'X-Accel-Redirect');
        $resolvedInternalPath = trim($internalPath);
        if ($resolvedInternalPath === '') {
            throw new \InvalidArgumentException('The X-Accel-Redirect internal path must be non-empty.');
        }

        return $this->offloadResponse(
            request: $request,
            path: $path,
            headerName: 'X-Accel-Redirect',
            headerValue: $resolvedInternalPath,
            downloadName: $downloadName,
            directory: $directory,
            disk: $disk,
            headers: $headers,
            inline: $inline,
        );
    }

    /** @param array<string, string|list<string>> $headers */
    public function xSendfile(
        Request $request,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
        bool $inline = false,
    ): Response {
        $this->assertOffloadEnabled('x_sendfile', 'X-Sendfile');
        $resolvedPath = $this->localPath($path, $disk);

        return $this->offloadResponse(
            request: $request,
            path: $resolvedPath,
            headerName: 'X-Sendfile',
            headerValue: $resolvedPath,
            downloadName: $downloadName,
            directory: $directory,
            disk: null,
            headers: $headers,
            inline: $inline,
        );
    }

    private function assertOffloadEnabled(string $driver, string $label): void
    {
        if (ValueNormalizer::bool(
            $this->config->get('filesystem.offload.' . $driver . '.enabled', false),
            false,
        )) {
            return;
        }

        throw new \LogicException(sprintf(
            '%s responses are disabled by filesystem.offload.%s.enabled.',
            $label,
            $driver,
        ));
    }

    private function freshRangeHeader(Request $request, DownloadPreparation $manifest): ?string
    {
        if (!$this->isGetOrHead($request)) {
            return null;
        }

        $rangeHeader = trim($request->getHeaderLine('Range'));
        if ($rangeHeader === '') {
            return null;
        }

        $validator = new ConditionalValidator($manifest->etag, $manifest->lastModified);

        return $validator->isRangeFresh($request) ? $rangeHeader : null;
    }

    private function isGetOrHead(Request $request): bool
    {
        $method = HttpMethodEnum::normalize($request->getMethod());

        return $method === HttpMethodEnum::GET->value
            || $method === HttpMethodEnum::HEAD->value;
    }

    private function isHead(Request $request): bool
    {
        return HttpMethodEnum::normalize($request->getMethod()) === HttpMethodEnum::HEAD->value;
    }

    private function localPath(string $path, ?string $disk): string
    {
        if ($path !== '' && PathHelper::hasScheme($path)) {
            throw new \InvalidArgumentException('X-Sendfile requires a local filesystem path.');
        }
        if ($path !== '' && PathHelper::isAbsolute($path)) {
            return PathHelper::normalize($path);
        }

        return $this->storage->localPath($path, $disk);
    }

    private function localBodyPath(string $path, ?string $disk): ?string
    {
        if ($path !== '' && PathHelper::hasScheme($path)) {
            return null;
        }

        try {
            return $this->storage->localPath($path, $disk);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, string|list<string>> ...$groups
     * @return array<string, string|list<string>>
     */
    private function mergeHeaders(array ...$groups): array
    {
        $merged = [];
        foreach ($groups as $group) {
            foreach ($group as $name => $value) {
                if ($name !== '') {
                    $merged[$name] = $value;
                }
            }
        }

        return $merged;
    }

    /** @param array<string, string|list<string>> $headers */
    private function offloadResponse(
        Request $request,
        string $path,
        string $headerName,
        string $headerValue,
        ?string $downloadName,
        ?string $directory,
        ?string $disk,
        array $headers,
        bool $inline,
    ): Response {
        [, , $manifest, $shortCircuit] = $this->prepareInitialDownload(
            $request,
            $path,
            $downloadName,
            $directory,
            $disk,
            $headers,
            $inline,
        );

        if ($shortCircuit instanceof Response) {
            return $shortCircuit;
        }

        $response = Response::empty(200);
        foreach ($this->mergeHeaders($manifest->headers, [$headerName => $headerValue], $headers) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @return array{0:DownloadProcessor,1:string,2:DownloadPreparation,3:?Response}
     */
    private function prepareInitialDownload(
        Request $request,
        string $path,
        ?string $downloadName,
        ?string $directory,
        ?string $disk,
        array $headers,
        bool $inline,
    ): array {
        [$processor, $resolvedPath] = $this->prepareProcessor($path, $directory, $disk, $inline);
        $manifest = $processor->prepareDownload($resolvedPath, $downloadName);

        return [$processor, $resolvedPath, $manifest, $this->shortCircuitResponse($request, $manifest, $headers)];
    }

    /** @return array{0:DownloadProcessor,1:string} */
    private function prepareProcessor(
        string $path,
        ?string $directory,
        ?string $disk,
        bool $inline,
    ): array {
        $processor = $this->transfers->download($directory, $disk);
        $processor->setForceAttachment(!$inline);

        return [$processor, $this->resolvedDownloadPath($path, $disk)];
    }

    private function resolvedDownloadPath(string $path, ?string $disk): string
    {
        if ($path !== '' && (PathHelper::isAbsolute($path) || PathHelper::hasScheme($path))) {
            return PathHelper::normalize($path);
        }

        try {
            return $this->storage->localPath($path, $disk);
        } catch (\InvalidArgumentException) {
            return $this->storage->path($path, $disk);
        }
    }

    /** @param array<string, string|list<string>> $headers */
    private function respond(
        Request $request,
        string $path,
        ?string $downloadName,
        ?string $directory,
        ?string $disk,
        array $headers,
        bool $inline,
    ): Response {
        [$processor, $resolvedPath, $baseManifest, $shortCircuit] = $this->prepareInitialDownload(
            $request,
            $path,
            $downloadName,
            $directory,
            $disk,
            $headers,
            $inline,
        );

        if ($shortCircuit instanceof Response) {
            return $shortCircuit;
        }

        $rangeHeader = $this->freshRangeHeader($request, $baseManifest);
        $manifest = $processor->prepareDownload($resolvedPath, $downloadName, $rangeHeader);
        $mergedHeaders = $this->mergeHeaders($manifest->headers, $headers);

        if ($this->isHead($request)) {
            return new Response($manifest->status, '', $mergedHeaders);
        }

        $localPath = $this->localBodyPath($path, $disk);
        if ($localPath !== null) {
            return new Response(
                $manifest->status,
                new FileBody(
                    $localPath,
                    $manifest->range->start,
                    $manifest->range->contentLength,
                ),
                $mergedHeaders,
            );
        }

        $response = Response::stream(
            producer: fn (): iterable => $this->streamChunks($resolvedPath, $manifest),
            status: $manifest->status,
        );

        foreach ($mergedHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** @return iterable<string> */
    private function streamChunks(string $resolvedPath, DownloadPreparation $manifest): iterable
    {
        $stream = fopen($resolvedPath, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException(sprintf('Unable to open download stream "%s".', $resolvedPath));
        }

        try {
            $start = $manifest->range->start;
            $remaining = $manifest->range->contentLength;
            $metadata = stream_get_meta_data($stream);
            $seekable = ($metadata['seekable'] ?? false) === true;

            if ($start > 0) {
                if ($seekable) {
                    if (fseek($stream, $start) !== 0) {
                        throw new \RuntimeException('Unable to seek download stream to requested range.');
                    }
                } else {
                    $discard = $start;
                    while ($discard > 0) {
                        $chunk = fread($stream, min(self::STREAM_CHUNK_SIZE, $discard));
                        if ($chunk === false || $chunk === '') {
                            throw new \RuntimeException('Unable to advance download stream to requested range.');
                        }
                        $discard -= strlen($chunk);
                    }
                }
            }

            while ($remaining > 0) {
                $chunk = fread($stream, min(self::STREAM_CHUNK_SIZE, $remaining));
                if ($chunk === false) {
                    throw new \RuntimeException('Unable to read download stream.');
                }
                if ($chunk === '') {
                    if (feof($stream)) {
                        throw new \RuntimeException('Download stream ended before the prepared response range.');
                    }
                    continue;
                }

                $remaining -= strlen($chunk);
                yield $chunk;
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, string|list<string>> $headers */
    private function shortCircuitResponse(Request $request, DownloadPreparation $manifest, array $headers): ?Response
    {
        $validator = new ConditionalValidator($manifest->etag, $manifest->lastModified);
        $outcome = $validator->evaluate($request);
        if ($outcome->state === Outcome::PASS) {
            return null;
        }

        $status = !$this->isGetOrHead($request) && $outcome->http === 304 ? 412 : $outcome->http;
        $response = Response::empty($status);
        foreach ($this->mergeHeaders($outcome->headers, $headers) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
