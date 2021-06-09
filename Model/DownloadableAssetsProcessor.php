<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Model;

use Bss\DigitalAssetsManage\Helper\DownloadableHelper;
use Bss\DigitalAssetsManage\Helper\GetBrandDirectory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Downloadable\Api\Data\LinkInterface;
use Psr\Log\LoggerInterface;

/**
 * Class DownloadableAssetsProcessor
 * Process links and samples of downloadable product
 */
class DownloadableAssetsProcessor
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var DownloadableHelper
     */
    protected $downloadableHelper;

    /**
     * @var MediaFile
     */
    protected $file;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var GetBrandDirectory
     */
    protected $getBrandDirectory;

    /**
     * DownloadableAssetsProcessor constructor.
     *
     * @param LoggerInterface $logger
     * @param DownloadableHelper $downloadableHelper
     * @param MediaFile $file
     * @param ProductRepositoryInterface $productRepository
     * @param GetBrandDirectory $getBrandDirectory
     */
    public function __construct(
        LoggerInterface $logger,
        DownloadableHelper $downloadableHelper,
        MediaFile $file,
        ProductRepositoryInterface $productRepository,
        GetBrandDirectory $getBrandDirectory
    ) {
        $this->logger = $logger;
        $this->downloadableHelper = $downloadableHelper;
        $this->file = $file;
        $this->productRepository = $productRepository;
        $this->getBrandDirectory = $getBrandDirectory;
    }

    /**
     * Move downloadable assets to brand directory
     *
     * @param Product|ProductInterface $product
     * @param string|null $brandPath
     * @param bool $backToDispersionPath
     */
    public function processDownloadableAssets(
        $product,
        string $brandPath = null,
        bool $backToDispersionPath = false
    ) {
        try {
            $extension = $product->getExtensionAttributes();
            $links = $extension->getDownloadableProductLinks();
            $samples = $extension->getDownloadableProductSamples();

            $currentBrandPath = $this->getBrandDirectory->execute($product);

            // If product still assign to other brand, then not back to dispersion path
            if ($currentBrandPath) {
                $brandPath = $currentBrandPath;
                $backToDispersionPath = false;
            }

            if (!$brandPath) {
                return;
            }

            $entryChanged = false;
            $this->processProductLinks($links, $brandPath, $backToDispersionPath, $entryChanged);
            $extension->setDownloadableProductLinks($links);


            $this->processProductLinks($samples, $brandPath, $backToDispersionPath, $entryChanged);
            $extension->setDownloadableProductSamples($samples);

            $product->setExtensionAttributes($extension);

            if ($entryChanged) {
                $this->productRepository->save($product);
            }
        } catch (\Exception $e) {
            $this->logger->critical(__("BSS.ERROR: when move to brand directory. ") . $e);
        }
    }

    /**
     * Processing downloadable product links
     *
     * @param \Magento\Downloadable\Model\Link[] $links
     * @param string $brandPath
     * @param bool $backToDispersionPath
     * @param bool $entryChanged
     * @return void
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function processProductLinks(
        $links,
        $brandPath,
        bool $backToDispersionPath = false,
        bool &$entryChanged = false
    ) {
        if (!$links) {
            return;
        }

        $changed = 0;
        foreach ($links as $link) {
            if ($linkFile = $link->getLinkFile()) {
                if ($backToDispersionPath) {
                    $brandPath = $this->downloadableHelper->getFileHelper()->getDispersionPath($link->getLinkFile());
                }
                // skip exist file in brand dir
                if (strpos($linkFile, $brandPath) !== false) {
                    continue;
                }
                $newLinkFile = $this->file->moveFile(
                    $this->downloadableHelper->getLink()->getBasePath(),
                    $brandPath,
                    $linkFile
                );
                if ($newLinkFile !== $linkFile) {
                    $changed++;
                }
                $link->setLinkFile($newLinkFile);
            }

            if ($sampleFile = $link->getSampleFile()) {
                if ($backToDispersionPath) {
                    $brandPath = $this->downloadableHelper->getFileHelper()->getDispersionPath($link->getSampleFile());
                }
                // skip exist file in brand dir
                if (strpos($sampleFile, $brandPath) !== false) {
                    continue;
                }
                $basePath = $link instanceof LinkInterface ?
                    $this->downloadableHelper->getLink()->getBaseSamplePath() :
                    $this->downloadableHelper->getSample()->getBasePath();


                $newSampleFile = $this->file->moveFile(
                    $basePath,
                    $brandPath,
                    $sampleFile
                );
                if ($newSampleFile !== $sampleFile) {
                    $changed++;
                }
                $link->setSampleFile($newSampleFile);
            }
        }

        $entryChanged = $changed > 0;
    }
}
