<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Helper;

use Magento\Downloadable\Api\Data\LinkInterface;
use Magento\Downloadable\Model\LinkFactory;
use Magento\Downloadable\Model\Link;
use Magento\Downloadable\Model\SampleFactory;
use Magento\Downloadable\Model\Sample;

/**
 * Class DownloadableHelper
 */
class DownloadableHelper
{

    /**
     * @var LinkFactory
     */
    protected $linkFactory;

    /**
     * @var SampleFactory
     */
    protected $sampleFactory;

    /**
     * @var Link
     */
    protected $linkConfig;

    /**
     * @var Sample
     */
    protected $sampleConfig;

    /**
     * @var \Bss\DigitalAssetsManage\Helper\Downloadable\File
     */
    protected $downloadableFile;

    /**
     * DownloadableHelper constructor.
     *
     * @param LinkFactory $linkFactory
     * @param SampleFactory $sampleFactory
     * @param Downloadable\File $downloadableFile
     */
    public function __construct(
        LinkFactory $linkFactory,
        SampleFactory $sampleFactory,
        \Bss\DigitalAssetsManage\Helper\Downloadable\File $downloadableFile
    ) {
        $this->linkFactory = $linkFactory;
        $this->sampleFactory = $sampleFactory;
        $this->downloadableFile = $downloadableFile;
    }

    /**
     * Get link model object
     *
     * @return Link
     */
    public function getLink(): Link
    {
        if (!$this->linkConfig) {
            $this->linkConfig = $this->linkFactory->create();
        }

        return $this->linkConfig;
    }

    /**
     * Get sample model object
     *
     * @return Sample
     */
    public function getSample(): Sample
    {
        if (!$this->sampleConfig) {
            $this->sampleConfig = $this->sampleFactory->create();
        }

        return $this->sampleConfig;
    }

    /**
     * @param \Magento\Downloadable\Model\Link[] $currentProductLinks
     * @param array $stillUsedIds
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function deleteLink(?array $currentProductLinks, array $stillUsedIds = [])
    {
        if (!$currentProductLinks) {
            return;
        }

        foreach ($currentProductLinks as $id => $link) {
            if (!in_array($id, $stillUsedIds)) {
                if ($link->getLinkFile()) {
                    $this->downloadableFile->deleteFile(
                        $this->downloadableFile->getFilePath(
                            $this->getLink()->getBasePath(),
                            $link->getLinkFile()
                        )
                    );
                }

                if ($link->getSampleFile()) {
                    $basePath = $link instanceof LinkInterface ?
                        $this->getLink()->getBaseSamplePath() :
                        $this->getSample()->getBasePath();
                    $this->downloadableFile->deleteFile(
                        $this->downloadableFile->getFilePath(
                            $basePath,
                            $link->getSampleFile()
                        )
                    );
                }
            }
        }
    }

    /**
     * Delete file
     *
     * @param string $file
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function deleteFile($file)
    {
        $this->downloadableFile->deleteFile($file);
    }

    /**
     * Return full path to file
     *
     * @param string $path
     * @param string $file
     * @return string
     */
    public function getFilePath($path, $file)
    {
        return $this->downloadableFile->getFilePath($path, $file);
    }

    /**
     * Get file helper objecty
     *
     * @return Downloadable\File
     */
    public function getFileHelper(): Downloadable\File
    {
        return $this->downloadableFile;
    }
}
