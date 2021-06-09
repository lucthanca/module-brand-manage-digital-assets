<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Controller\Adminhtml\Product\Initialization\Helper\Plugin;

use Bss\DigitalAssetsManage\Helper\DownloadableHelper;
use Bss\DigitalAssetsManage\Helper\GetBrandDirectory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Downloadable\Api\Data\LinkInterfaceFactory;
use Magento\Downloadable\Api\Data\SampleInterfaceFactory;
use \Magento\Downloadable\Controller\Adminhtml\Product\Initialization\Helper\Plugin\Downloadable as ParentClass;
use Magento\Downloadable\Helper\Download;
use Magento\Downloadable\Model\Link\Builder as LinkBuilder;
use Magento\Downloadable\Model\Product\Type;
use Magento\Downloadable\Model\ResourceModel\Sample\Collection;
use Magento\Downloadable\Model\Sample\Builder as SampleBuilder;
use Magento\Framework\App\RequestInterface;

/**
 * Class Downloadable
 * Rewrite class for brand directory manage processing
 */
class Downloadable extends ParentClass
{
    /**
     * @var GetBrandDirectory
     */
    protected $getBrandDirectory;

    /**
     * @var SampleInterfaceFactory
     */
    protected $sampleFactory;

    /**
     * @var LinkInterfaceFactory
     */
    protected $linkFactory;

    /**
     * @var LinkBuilder
     */
    protected $linkBuilder;

    /**
     * @var SampleBuilder
     */
    protected $sampleBuilder;

    /**
     * @var DownloadableHelper
     */
    protected $downloadableHelper;

    /**
     * Downloadable constructor.
     *
     * @param RequestInterface $request
     * @param LinkBuilder $linkBuilder
     * @param SampleBuilder $sampleBuilder
     * @param SampleInterfaceFactory $sampleFactory
     * @param LinkInterfaceFactory $linkFactory
     * @param GetBrandDirectory $getBrandDirectory
     * @param DownloadableHelper $downloadableHelper
     */
    public function __construct(
        RequestInterface $request,
        LinkBuilder $linkBuilder,
        SampleBuilder $sampleBuilder,
        SampleInterfaceFactory $sampleFactory,
        LinkInterfaceFactory $linkFactory,
        GetBrandDirectory $getBrandDirectory,
        DownloadableHelper $downloadableHelper
    ) {
        parent::__construct($request, $linkBuilder, $sampleBuilder, $sampleFactory, $linkFactory);
        $this->getBrandDirectory = $getBrandDirectory;
        $this->sampleFactory = $sampleFactory;
        $this->linkFactory = $linkFactory;
        $this->linkBuilder = $linkBuilder;
        $this->sampleBuilder = $sampleBuilder;
        $this->downloadableHelper = $downloadableHelper;
    }

    /**
     * Prepare product to save
     *
     * @param \Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper $subject
     * @param \Magento\Catalog\Model\Product $product
     * @return \Magento\Catalog\Model\Product
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function afterInitialize(
        \Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper $subject,
        \Magento\Catalog\Model\Product $product
    ) {
        $brandPath = $this->getBrandDirectory->execute($product);
        // If product not in digital assets of brand
        if (!$brandPath) {

            // get before save brand (if visible)
            $oldBrand = $this->getBrandDirectory->execute($product, true);

            // if before save, current product be in digital category of brand
            if ($oldBrand) {
                $this->processInitializeDownloadable($product, $oldBrand, true);
                return $product;
            }

            return parent::afterInitialize($subject, $product);
        }
        $this->processInitializeDownloadable($product, $brandPath);

        return $product;
    }

    /**
     * @param ProductInterface|\Magento\Catalog\Model\Product $product
     * @param string $brandPath
     * @param bool $toDispersionPath
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function processInitializeDownloadable($product, string $brandPath, bool $toDispersionPath = false)
    {
        if ($downloadable = $this->request->getPost('downloadable')) {
            $product->setTypeId(Type::TYPE_DOWNLOADABLE);
            $product->setDownloadableData($downloadable);
            $extension = $product->getExtensionAttributes();
            $productLinks = $product->getTypeInstance()->getLinks($product);
            $productSamples = $product->getTypeInstance()->getSamples($product);

            // get flat ids for delete all link be removed
            $flatIds = [];
            if (isset($downloadable['link']) && is_array($downloadable['link'])) {
                $links = [];
                // Still exist links
                foreach ($downloadable['link'] as $linkData) {
                    // Add existed link id to flat ids
                    if (isset($linkData['link_id']) && $linkData['link_id']) {
                        $flatIds[] = $linkData['link_id'];
                    }

                    if (!$linkData || (isset($linkData['is_delete']) && $linkData['is_delete'])) {
                        if (isset($linkData['file']['0'])) {
                            $this->deleteFile(
                                $linkData['file']['0'],
                                $this->downloadableHelper->getSample()->getBasePath(),
                                "file"
                            );
                            $this->deleteFile(
                                $linkData['file']['0'],
                                $this->downloadableHelper->getSample()->getBaseSamplePath(),
                                "sample"
                            );
                        }
                    } else {
                        $linkData = $this->processLink($linkData, $productLinks, $brandPath, $toDispersionPath);

                        $links[] = $this->linkBuilder->setData(
                            $linkData
                        )->build(
                            $this->linkFactory->create()
                        );
                    }
                }

                $extension->setDownloadableProductLinks($links);
            } else {
                $extension->setDownloadableProductLinks([]);
            }

            // Delete for links
            $this->downloadableHelper->deleteLink($productLinks, $flatIds);

            $flatIds = [];
            if (isset($downloadable['sample']) && is_array($downloadable['sample'])) {
                $samples = [];
                foreach ($downloadable['sample'] as $sampleData) {

                    if (isset($sampleData['sample_id']) && $sampleData['sample_id']) {
                        $flatIds[] = $sampleData['sample_id'];
                    }

                    if (!$sampleData || (isset($sampleData['is_delete']) && (bool)$sampleData['is_delete'])) {
                        if (isset($linkData['file']['0'])) {
                            $this->deleteFile(
                                $linkData['file']['0'],
                                $this->downloadableHelper->getSample()->getBasePath(),
                                "file"
                            );
                        }
                    } else {
                        $sampleData = $this->processSample($sampleData, $productSamples, $brandPath, $toDispersionPath);
                        $samples[] = $this->sampleBuilder->setData(
                            $sampleData
                        )->build(
                            $this->sampleFactory->create()
                        );
                    }
                }
                $extension->setDownloadableProductSamples($samples);
            } else {
                $extension->setDownloadableProductSamples([]);
            }

            // Delete for samples
            $this->downloadableHelper->deleteLink($productSamples->getItems(), $flatIds);
            $product->setExtensionAttributes($extension);
            if ($product->getLinksPurchasedSeparately()) {
                $product->setTypeHasRequiredOptions(true)->setRequiredOptions(true);
            } else {
                $product->setTypeHasRequiredOptions(false)->setRequiredOptions(false);
            }
        }
    }

    /**
     * Delete specific link file with base path
     *
     * @param array $file
     * @param string $basePath
     * @param string $key
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function deleteFile(array $file, string $basePath, string $key)
    {
        if (!isset($file[$key])) {
            return;
        }
        $filePath = $file[$key];
        if ($filePath) {
            $this->downloadableHelper->deleteFile(
                $this->downloadableHelper->getFilePath(
                    $basePath,
                    $filePath
                )
            );
        }
    }

    /**
     * Check Links type and status.
     *
     * @param array $linkData
     * @param array $productLinks
     * @param string $brandPath
     * @param bool $toDispersionPath
     * @return array
     */
    private function processLink(array $linkData, array $productLinks, string $brandPath, bool $toDispersionPath = false): array
    {
        $linkId = $linkData['link_id'] ?? null;

        // Nếu là sửa link
        if ($linkId && isset($productLinks[$linkId])) {
            $linkData = $this->processFileStatus($linkData, $productLinks[$linkId]->getLinkFile());
            $linkData['sample'] = $this->processFileStatus(
                $linkData['sample'] ?? [],
                $productLinks[$linkId]->getSampleFile()
            );

            // check and set brand path for next processing
            $linkData = $this->processBrandAssetsStatus(
                $linkData,
                $productLinks[$linkId]->getLinkFile(),
                $brandPath,
                $toDispersionPath
            );
            $linkData['sample'] = $this->processBrandAssetsStatus(
                $linkData['sample'] ?? [],
                $productLinks[$linkId]->getSampleFile(),
                $brandPath,
                $toDispersionPath
            );
        } else {
            // Nếu là thêm mới
            $linkData = $this->processFileStatus($linkData, null);
            $linkData['sample'] = $this->processFileStatus($linkData['sample'] ?? [], null);

            // check and set brand path for next processing
            $linkData = $this->processBrandAssetsStatus($linkData, null, $brandPath, $toDispersionPath);
            $linkData['sample'] = $this->processBrandAssetsStatus($linkData['sample'] ?? [], null, $brandPath, $toDispersionPath);
        }

        return $linkData;
    }

    /**
     * Checking old file in brand dir
     *
     * @param array $linkData
     * @param string|null $file
     * @param string $brandPath
     * @param bool $toDispersionPath
     * @return array
     */
    private function processBrandAssetsStatus(array $linkData, ?string $file, string $brandPath, bool $toDispersionPath = false): array
    {
        if (isset($linkData['type']) &&
            $linkData['type'] === Download::LINK_TYPE_FILE &&
            isset($linkData['file']['0']['file'])
        ) {

            // Get file name of new upload file for check with brand path and old file in db
            // phpcs:disable Magento2.Functions.DiscouragedFunction
            $pathinfo = pathinfo($linkData['file'][0]['file']);

            // If the file not in brand directory
            // And if file is not has change but still set flag to move to brand directory if the current file is not in
            // brand directory
            // else if the new file was add (new link or update link), mark to remove file in origin folder
            // then check if not move to back to origin folder then set the brand path to the link for next processing
            // no set brand_path will execute core function for create link file
            if (!$this->compareTwoLinks($brandPath . $pathinfo['basename'], $file)) {
                if ($linkData['file'][0]['status'] === 'old') {
                    $linkData['file'][0]['move_from_base_path'] = true;
                } elseif ($file) {
                    $linkData['file'][0]['to_be_remove'] = $file;
                }
                if (!$toDispersionPath) {
                    $linkData['file'][0]['brand_path'] = $brandPath;
                }
            }

            // check if action to move back file to dispersion path (same origin link path) and no have any changes
            // Then set flag is move from base path and get the dispersion path and set the fake brand path to be dispersion path
            if ($toDispersionPath && $linkData['file'][0]['status'] === 'old') {
                $linkData['file'][0]['move_from_base_path'] = true;
                $dispersionPath = $this->downloadableHelper->getFileHelper()->getDispersionPath($linkData['file'][0]['file']);
                $linkData['file'][0]['brand_path'] = $dispersionPath;
            }
        }

        return $linkData;
    }

    /**
     * Check Sample type and status. and custom code for brand directory manage
     *
     * @param array $sampleData
     * @param Collection $productSamples
     * @param string $brandPath
     * @param bool $toDispersionPath
     * @return array
     */
    private function processSample(array $sampleData, Collection $productSamples, string $brandPath, bool $toDispersionPath = false): array
    {
        $sampleId = $sampleData['sample_id'] ?? null;
        /** @var \Magento\Downloadable\Model\Sample $productSample */
        $productSample = $sampleId ? $productSamples->getItemById($sampleId) : null;

        // đã tồn tại
        if ($sampleId && $productSample) {
            $sampleData = $this->processFileStatus($sampleData, $productSample->getSampleFile());

            // check and set brand path for next processing
            $sampleData = $this->processBrandAssetsStatus($sampleData, $productSample->getSampleFile(), $brandPath, $toDispersionPath);
        } else {
            // thêm mới
            $sampleData = $this->processFileStatus($sampleData, null);

            // check and set brand path for next processing
            $sampleData = $this->processBrandAssetsStatus($sampleData, null, $brandPath, $toDispersionPath);
        }

        return $sampleData;
    }

    /**
     * Compare two link
     *
     * @param string|null $link
     * @param string|null $oriLink
     * @return bool
     */
    private function compareTwoLinks(?string $link, ?string $oriLink): bool
    {
        if ($link === null) {
            $link = "";
        }

        if ($oriLink === null) {
            $oriLink = "";
        }

        $link = str_replace("/", "", str_replace("\\", "", $link));
        $oriLink = str_replace("/", "", str_replace("\\", "", $oriLink));


        return $link === $oriLink;
    }

    /**
     * Compare file path from request with DB and set status.
     *
     * @param array $data
     * @param string|null $file
     * @return array
     */
    private function processFileStatus(array $data, ?string $file): array
    {
        if (isset($data['type']) && $data['type'] === Download::LINK_TYPE_FILE && isset($data['file']['0']['file'])) {
            if ($data['file'][0]['file'] !== $file) {
                $data['file'][0]['status'] = 'new';
            } else {
                $data['file'][0]['status'] = 'old';
            }
        }

        return $data;
    }
}
