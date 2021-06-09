<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\MediaStorage\Model\File\Uploader;

/**
 * Class MediaFile
 * Function with filesystem in pub/media/
 */
class MediaFile
{
    /**
     * @var WriteInterface
     */
    protected $mediaDirectory;

    /**
     * MediaFile constructor.
     *
     * @param Filesystem $filesystem
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function __construct(
        Filesystem $filesystem
    ) {
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     * Move file to subpath in basepath in media directory
     *
     * @param string $basePath
     * @param string $subPath
     * @param string $file
     * @return string
     * @throws FileSystemException
     */
    public function moveFile(
        string $basePath,
        string $subPath,
        string $file
    ) {
        if (strrpos($file, '.tmp') == strlen($file) - 4) {
            $file = substr($file, 0, strlen($file) - 4);
        }

        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $pathInfo = pathinfo($file);

        if (!isset($pathInfo['basename'])) {
            throw new FileSystemException(__("File not exist!"));
        }

        // Get final brand digital assets destination file path
        $destFile = $subPath . DIRECTORY_SEPARATOR . $this->getUniqueFileNameInBrandDigitalFolder(
                $pathInfo['basename'],
                $basePath . $subPath
            );

        // move file from default to brand path
        $this->mediaDirectory->renameFile(
            $this->getFilePath($tmpPath ?? $basePath, $file),
            $this->getFilePath($basePath, $destFile)
        );

        return str_replace(
            '\\',
            '/',
            $destFile
        );
    }

    /**
     * Get unique file name
     *
     * @param string $fileName
     * @param string $path
     * @return string
     */
    protected function getUniqueFileNameInBrandDigitalFolder(string $fileName, string $path): string
    {
        return Uploader::getNewFileName(
            $this->getFilePath(
                $this->mediaDirectory->getAbsolutePath($path),
                $fileName
            )
        );
    }

    /**
     * Return full path to file
     *
     * @param string $path
     * @param string $file
     * @return string
     */
    public function getFilePath(string $path, string $file): string
    {
        $path = rtrim($path, '/');
        $file = ltrim($file, '/');

        return $path . DIRECTORY_SEPARATOR . $file;
    }
}
