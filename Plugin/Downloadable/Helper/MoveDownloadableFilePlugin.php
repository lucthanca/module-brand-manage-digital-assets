<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Plugin\Downloadable\Helper;

use Magento\Downloadable\Helper\File as BePlugged;

/**
 * Class MoveDownloadableFilePlugin
 */
class MoveDownloadableFilePlugin
{
    /**
     * @param BePlugged $subject
     * @param callable $defaultProceed
     * @param string $baseTmpPath
     * @param string $basePath
     * @param array $file
     */
    public function aroundMoveFileFromTmp(
        BePlugged $subject,
        callable $defaultProceed,
        $baseTmpPath,
        $basePath,
        $file
    ) {
        if (isset($file[0])) {
            $fileName = $file[0]['file'];
            if ($file[0]['status'] === 'new') {
                try {
                    $fileName = $this->moveFileFromTmp($baseTmpPath, $basePath, $file[0]['file']);
                } catch (\Exception $e) {
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('Something went wrong while saving the file(s).')
                    );
                }
            }
            return $fileName;
        }
        return '';
    }

    /**
     * Move file from tmp path to base path
     *
     * @param BePlugged $subject
     * @param string $baseTmpPath
     * @param string $basePath
     * @param string $file
     * @return string
     */
    protected function moveFileFromTmp(BePlugged $subject, $baseTmpPath, $basePath, $file)
    {
        if (strrpos($file, '.tmp') == strlen($file) - 4) {
            $file = substr($file, 0, strlen($file) - 4);
        }

        $destFile = dirname(
                $file
            ) . '/' . \Magento\MediaStorage\Model\File\Uploader::getNewFileName(
                $subject->getFilePath($basePath, $file)
            );

        $this->_coreFileStorageDatabase->copyFile(
            $subject->getFilePath($baseTmpPath, $file),
            $subject->getFilePath($basePath, $destFile)
        );

        $this->_mediaDirectory->renameFile(
            $subject->getFilePath($baseTmpPath, $file),
            $subject->getFilePath($basePath, $destFile)
        );

        return str_replace('\\', '/', $destFile);
    }
}
