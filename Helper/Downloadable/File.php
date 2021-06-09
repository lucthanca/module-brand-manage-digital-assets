<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Helper\Downloadable;

use \Magento\MediaStorage\Model\File\Uploader;

/**
 * Class File
 * Rewrite for brand digital assets manage
 */
class File extends \Magento\Downloadable\Helper\File
{
    /**
     * Move to brand dir if need
     *
     * @param string $baseTmpPath
     * @param string $basePath
     * @param string $file
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function moveFileFromTmp($baseTmpPath, $basePath, $file)
    {
        if (!isset($file[0]['brand_path']) || $file[0]['brand_path'] === null) {
            return parent::moveFileFromTmp($baseTmpPath, $basePath, $file);
        }

        if (isset($file[0])) {
            $fileName = $file[0]['file'];
            $brandPath = $file[0]['brand_path'];
            if ($file[0]['status'] === 'new') {
                try {
                    $fileName = $this->_moveFileFromTmp($baseTmpPath, $basePath, $file[0]['file'], $brandPath);
                    try {
                        if (isset($file[0]['to_be_remove'])) {
                            $this->deleteFile(
                                $this->getFilePath($basePath, $file[0]['to_be_remove'])
                            );
                        }
                    } catch (\Exception $e) {
                        $this->_logger->critical(
                            "BSS.ERROR: Can't delete the old file. " . $e
                        );
                    }
                } catch (\Exception $e) {
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('Something went wrong while saving the file(s).')
                    );
                }
            }

            if (isset($file[0]['move_from_base_path']) && $file[0]['move_from_base_path']) {
                $fileName = $this->_moveFileFromTmp($basePath, $basePath, $file[0]['file'], $brandPath);
            }
            return $fileName;
        }
        return '';
    }

    /**
     * Move file từ tmp to thư mục đích. Custom cho việc move tới thư mục của brand nếu cần thiết
     *
     * @param string $baseTmpPath
     * @param string $basePath
     * @param string $file
     * @param string|null $brandPath
     * @return array|string|string[]
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function _moveFileFromTmp($baseTmpPath, $basePath, $file, string $brandPath = null)
    {
        if (!$brandPath) {
            return parent::_moveFileFromTmp($baseTmpPath, $basePath, $file);
        }

        if (strrpos($file, '.tmp') == strlen($file) - 4) {
            $file = substr($file, 0, strlen($file) - 4);
        }

        // Check sự tồn tại và tạo tên mới nếu cần của file trong thư mục của brand
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $brandPathFile = $brandPath . DIRECTORY_SEPARATOR . pathinfo($file)['basename'];
        $destFile = $brandPath . '/' . Uploader::getNewFileName(
            $this->_mediaDirectory->getAbsolutePath($this->getFilePath($basePath, $brandPathFile))
        );

        $this->_coreFileStorageDatabase->copyFile(
            $this->getFilePath($baseTmpPath, $file),
            $this->getFilePath($basePath, $destFile)
        );

        $this->_mediaDirectory->renameFile(
            $this->getFilePath($baseTmpPath, $file),
            $this->getFilePath($basePath, $destFile)
        );

        return str_replace('\\', '/', $destFile);
    }

    /**
     * Delete media file
     *
     * @param string $path
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function deleteFile(string $path)
    {
        $this->_mediaDirectory->delete($path);
    }

    /**
     * Get dispersion path of file
     *
     * @param string $file
     * @return string
     */
    public function getDispersionPath(string $file): string
    {
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $pathinfo = pathinfo($file);
        $fileName = $pathinfo['basename'];
        $dispersionPath = Uploader::getDispersionPath($fileName);
        $dispersionPath = ltrim($dispersionPath, DIRECTORY_SEPARATOR);

        return DIRECTORY_SEPARATOR . rtrim($dispersionPath, DIRECTORY_SEPARATOR);
    }
}
