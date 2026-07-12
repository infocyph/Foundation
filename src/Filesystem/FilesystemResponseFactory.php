<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Pathwise\Utils\PathHelper;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;
use Infocyph\Webrick\Response\Response;

final readonly class FilesystemResponseFactory
{
    public function __construct(private FilesystemManager $files) {}

    /**
     * @param array<string, string|list<string>> $headers
     */
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

    /**
     * @param array<string, string|list<string>> $headers
     */
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

    /**
     * @param array<string, string|list<string>> $headers
     */
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

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function xSendfile(
        Request $request,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
        bool $inline = false,
    ): Response {
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

    /**
     * @param array{
     *   etag: string,
     *   lastModified: int
     * } $manifest
     */
    private function freshRangeHeader(Request $request, array $manifest): ?string
    {
        if (!$this->isGetOrHead($request)) {
            return null;
        }

        $rangeHeader = trim($request->getHeaderLine('Range'));
        if ($rangeHeader === '') {
            return null;
        }

        $validator = new ConditionalValidator($manifest['etag'], $manifest['lastModified']);

        return $validator->isRangeFresh($request)
            ? $rangeHeader
            : null;
    }

    private function isGetOrHead(Request $request): bool
    {
        $method = HttpMethodEnum::normalize($request->getMethod());

        return $method === HttpMethodEnum::GET->value
            || $method === HttpMethodEnum::HEAD->value;
    }

    private function localPath(string $path, ?string $disk): string
    {
        if ($path !== '' && PathHelper::isAbsolute($path)) {
            return PathHelper::normalize($path);
        }

        return $this->files->localPath($path, $disk);
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
                if ($name === '') {
                    continue;
                }

                $merged[$name] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
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
        [$processor, $resolvedPath] = $this->prepareProcessor($path, $directory, $disk, $inline);
        $manifest = $processor->prepareDownload($resolvedPath, $downloadName);
        $shortCircuit = $this->shortCircuitResponse($request, $manifest, $headers);

        if ($shortCircuit instanceof Response) {
            return $shortCircuit;
        }

        $response = Response::empty(200);

        foreach ($this->mergeHeaders($manifest['headers'], [$headerName => $headerValue], $headers) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @return array{0: \Infocyph\Pathwise\StreamHandler\DownloadProcessor, 1: string}
     */
    private function prepareProcessor(
        string $path,
        ?string $directory,
        ?string $disk,
        bool $inline,
    ): array {
        $processor = $this->files->download($directory, $disk);
        $processor->setForceAttachment(!$inline);

        return [$processor, $this->resolvedDownloadPath($path, $disk)];
    }

    private function resolvedDownloadPath(string $path, ?string $disk): string
    {
        if ($path !== '' && (PathHelper::isAbsolute($path) || PathHelper::hasScheme($path))) {
            return PathHelper::normalize($path);
        }

        try {
            return $this->files->localPath($path, $disk);
        } catch (\InvalidArgumentException) {
            return $this->files->path($path, $disk);
        }
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function respond(
        Request $request,
        string $path,
        ?string $downloadName,
        ?string $directory,
        ?string $disk,
        array $headers,
        bool $inline,
    ): Response {
        [$processor, $resolvedPath] = $this->prepareProcessor($path, $directory, $disk, $inline);
        $baseManifest = $processor->prepareDownload($resolvedPath, $downloadName);
        $shortCircuit = $this->shortCircuitResponse($request, $baseManifest, $headers);

        if ($shortCircuit instanceof Response) {
            return $shortCircuit;
        }

        $rangeHeader = $this->freshRangeHeader($request, $baseManifest);
        $manifest = $processor->prepareDownload($resolvedPath, $downloadName, $rangeHeader);
        $response = Response::stream(
            producer: function () use ($processor, $resolvedPath, $downloadName, $rangeHeader): string {
                $output = fopen('php://output', 'wb');
                if (!is_resource($output)) {
                    throw new \RuntimeException('Unable to open php://output for download streaming.');
                }

                try {
                    $processor->streamDownload($resolvedPath, $output, $downloadName, $rangeHeader);
                } finally {
                    fclose($output);
                }

                return '';
            },
            status: $manifest['status'],
        );

        foreach ($this->mergeHeaders($manifest['headers'], $headers) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array{
     *   etag: string,
     *   lastModified: int
     * } $manifest
     * @param array<string, string|list<string>> $headers
     */
    private function shortCircuitResponse(Request $request, array $manifest, array $headers): ?Response
    {
        $validator = new ConditionalValidator($manifest['etag'], $manifest['lastModified']);
        $outcome = $validator->evaluate($request);
        if ($outcome->state === Outcome::PASS) {
            return null;
        }

        $status = !$this->isGetOrHead($request) && $outcome->http === 304
            ? 412
            : $outcome->http;
        $response = Response::empty($status);

        foreach ($this->mergeHeaders($outcome->headers, $headers) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
