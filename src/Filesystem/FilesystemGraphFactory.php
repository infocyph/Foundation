<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use League\Flysystem\FilesystemOperator;

final class FilesystemGraphFactory
{
    public static function download(FilesystemTransferFactory $transfers): DownloadProcessor
    {
        return $transfers->download();
    }

    public static function operator(StorageRegistry $storage): FilesystemOperator
    {
        return $storage->disk();
    }

    public static function upload(FilesystemTransferFactory $transfers): UploadProcessor
    {
        return $transfers->upload();
    }
}
