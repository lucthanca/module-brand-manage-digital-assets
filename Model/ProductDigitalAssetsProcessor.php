<?php
declare(strict_types=1);
namespace Bss\DigitalAssetsManage\Model;

use Bss\DigitalAssetsManage\Helper\GetBrandDirectory;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Magento\Framework\Exception\FileSystemException;
use Exception;
use Magento\MediaStorage\Model\File\Uploader;
use \Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use \Magento\Framework\File\Mime;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Downloadable\Model\Link as DownloadableLink;
use Magento\Downloadable\Model\Sample as DownloadableSample;
use \Magento\Downloadable\Model\LinkFactory;
use \Magento\Downloadable\Model\SampleFactory;

/**
 * Class ProductDigitalAssetsProcessor
 * Process move origin file to brand digital assets folder
 */
class ProductDigitalAssetsProcessor
{
    /**
     * @var GetBrandDirectory
     */
    protected $getBrandDirectory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var MediaConfig
     */
    protected $mediaConfig;

    /**
     * @var WriteInterface
     */
    protected $mediaDirectory;

    /**
     * @var Mime
     */
    protected $mime;

    /**
     * @var ImageContentInterfaceFactory
     */
    protected $imageContentInterfaceFactory;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var LinkFactory
     */
    protected $linkFactory;

    /**
     * @var SampleFactory
     */
    protected $sampleFactory;

    protected $linkConfig;

    protected $sampleConfig;

    /**
     * ProductDigitalAssetsProcessor constructor.
     *
     * @param GetBrandDirectory $getBrandDirectory
     * @param LoggerInterface $logger
     * @param ResourceConnection $resourceConnection
     * @param Filesystem $filesystem
     * @param MediaConfig $mediaConfig
     * @param Mime $mime
     * @param ImageContentInterfaceFactory $imageContentInterfaceFactory
     * @param ProductRepositoryInterface $productRepository
     * @param LinkFactory $linkFactory
     * @param SampleFactory $sampleFactory
     * @throws FileSystemException
     */
    public function __construct(
        GetBrandDirectory $getBrandDirectory,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection,
        Filesystem $filesystem,
        MediaConfig $mediaConfig,
        Mime $mime,
        ImageContentInterfaceFactory $imageContentInterfaceFactory,
        ProductRepositoryInterface $productRepository,
        LinkFactory $linkFactory,
        SampleFactory $sampleFactory
    ) {
        $this->getBrandDirectory = $getBrandDirectory;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->mediaConfig = $mediaConfig;
        $this->mime = $mime;
        $this->imageContentInterfaceFactory = $imageContentInterfaceFactory;
        $this->productRepository = $productRepository;
        $this->linkFactory = $linkFactory;
        $this->sampleFactory = $sampleFactory;
    }

    /**
     * Get link config object
     *
     * @return DownloadableLink
     */
    public function getLink()
    {
        if (!$this->linkConfig) {
            $this->linkConfig = $this->linkFactory->create();
        }

        return $this->linkConfig;
    }

    /**
     * Get sample config object
     *
     * @return DownloadableSample
     */
    public function getSample()
    {
        if (!$this->sampleConfig) {
            $this->sampleConfig = $this->sampleFactory->create();
        }
        return $this->sampleConfig;
    }

    /**
     * Process move origin file to brand digital assets folder
     *
     * @param Product $product
     * @param string|null $brandDir
     */
    public function process(
        Product $product,
        string $brandDir = null
    ) {
        $this->processDownloadableAssets($product, $brandDir);

        try {
            if ($this->removeAssetsFromBrandFolder($product)) {
                return;
            }
        } catch (Exception $e) {
            $this->logger->critical(
                "BSS - ERROR: When remove asssets from brand folder. Detail: " . $e
            );
        }

        if ($brandDir === null) {
            $brandDir = $this->getBrandDirectory->execute($product);
        }

        if (!$brandDir) {
            return;
        }

        $this->moveAssetsToBrandFolder($product, $brandDir);
    }

    /**
     * Delete downloadable assets
     *
     * @param Product $product
     * @param string|null $brandDir
     */
    public function processDownloadableAssets(Product $product, string $brandDir = null)
    {
        try {
            if ($this->moveDownloadableAssetsToDispersionPath($product, $brandDir)) {
                return;
            }

            if (!$brandDir) {
                $brandDir = $this->getBrandDirectory->execute($product);
            }

            if (!$brandDir) {
                return;
            }

            // Delete assets file
            $extension = $product->getExtensionAttributes();
            $links = $extension->getDownloadableProductLinks();
            $samples = $extension->getDownloadableProductSamples();
            $originData = $product->getOrigData();
            $this->deleteRemovedAssetsInBrandDir(
                $links,
                $originData['downloadable_links'] ?? [],
                $this->getLink()->getBasePath(),
                $brandDir
            );

            if (isset($originData['downloadable_samples'])) {
                if (!is_array($originData['downloadable_samples'])) {
                    $downloadableSamples = $originData['downloadable_samples']->getItems();
                }
            }

            $this->deleteRemovedAssetsInBrandDir(
                $samples,
                $downloadableSamples ?? [],
                $this->getSample()->getBasePath(),
                $brandDir
            );
        } catch (Exception $e) {
            $this->logger->critical(
                "BSS.ERROR: When process downloadable assets. " . $e
            );
        }
    }

    /**
     * Check need to move assets form brand path
     *
     * Brand path chính là đại diện cho việc force remove
     *
     * @param Product $product
     * @param string|null $brandPath
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function needProcessRemove(Product $product, string &$brandPath = null): bool
    {
        if (is_int($product)) {
            $product = $this->productRepository->getById($product);
        }

        if (!$brandPath) {
            $newBrandDir = $this->getBrandDirectory->execute($product);
            $oldBrandDir = $this->getBrandDirectory->execute($product, true);
            $brandPath = $oldBrandDir;
            return $oldBrandDir !== false && $newBrandDir === false;
        }

        return true;
    }

    /**
     * Move downladoable assets back to dispersion path if not in digital assets
     *
     * @param Product|int $product
     * @param string|null $brandPath
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\StateException
     */
    public function moveDownloadableAssetsToDispersionPath($product, string $brandPath = null): bool
    {
        if (is_int($product)) {
            $product = $this->productRepository->getById($product);
        }
        $needProcess = $this->needProcessRemove($product, $brandPath);

        $entryChanged = false;
        if ($needProcess) {
            $this->moveDownloadableAssetsToBrandDir($product, $brandPath, $entryChanged, true);
        }

        if ($entryChanged) {
            $this->productRepository->save($product);
            return true;
        }

        return false;
    }

    /**
     * Move downloadable assets to brand directory
     *
     * @param Product|int $product
     * @param string|null $brandPath
     * @param bool $entryChanged
     * @param bool $backToDispersionPath
     */
    public function moveDownloadableAssetsToBrandDir(
        $product,
        string $brandPath = null,
        bool &$entryChanged = false,
        bool $backToDispersionPath = false
    ) {
        try {
            if (is_int($product)) {
                $product = $this->productRepository->getById($product);
            }
            $extension = $product->getExtensionAttributes();
            $links = $extension->getDownloadableProductLinks();
            $samples = $extension->getDownloadableProductSamples();

            if (!$brandPath) {
                $brandPath = $this->getBrandDirectory->execute($product);
            }

            if (!$brandPath) {
                return;
            }

            $this->processProductLinks($links, $brandPath, $backToDispersionPath, $entryChanged);
            $extension->setDownloadableProductLinks($links);


            $this->processProductSamples($samples, $brandPath, $backToDispersionPath, $entryChanged);
            $extension->setDownloadableProductSamples($samples);

            $product->setExtensionAttributes($extension);
        } catch (\Exception $e) {
            $this->logger->critical(__("Error when move to brand directory: ") . $e);
        }
    }

    /**
     * Processing downloadable samples
     *
     * @param \Magento\Downloadable\Model\Sample[] $samples
     * @param string $brandPath
     * @param bool $backToDispersionPath
     * @param bool $entryChanged
     * @return void
     * @throws FileSystemException
     */
    protected function processProductSamples(
        $samples,
        $brandPath,
        bool $backToDispersionPath = false,
        bool &$entryChanged = false
    ) {
        if (!$samples) {
            return;
        }

        $changed = 0;
        foreach ($samples as $link) {
            if ($sampleFile = $link->getSampleFile()) {
                if ($backToDispersionPath) {
                    $brandPath = DS . $this->getDispersionPath($link->getSampleFile()) . DS;
                }
                $newSampleFile = $this->getNewBrandFilePath(
                    $this->getSample()->getBasePath(),
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
                    $brandPath = DS . $this->getDispersionPath($link->getLinkFile()) . DS;
                }
                $newLinkFile = $this->getNewBrandFilePath(
                    $this->getLink()->getBasePath(),
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
                    $brandPath = DS . $this->getDispersionPath($link->getSampleFile()) . DS;
                }
                $newSampleFile = $this->getNewBrandFilePath(
                    $this->getLink()->getBaseSamplePath(),
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

    /**
     * Get Dispersion path
     *
     * @param string $file
     * @return string
     */
    private function getDispersionPath(string $file): string
    {
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $pathinfo = pathinfo($file);
        $fileName = $pathinfo['basename'];
        $dispersionPath = Uploader::getDispersionPath($fileName);
        $dispersionPath = ltrim($dispersionPath, DS);

        return rtrim($dispersionPath, DS);
    }

    /**
     * Get brand file path
     *
     * @param string $basePath
     * @param string $brandPath
     * @param string $file
     * @return mixed|string
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function getNewBrandFilePath($basePath, $brandPath, $file)
    {
        // If be in brand path ren skip
        // phpcs:disable Magento2.Functions.DiscouragedFunction
//        if (ltrim(rtrim(dirname($file))) === ltrim(rtrim($brandPath))) {
//            return $file;
//        }

        if (strpos($file, $brandPath) !== false) {
            return $file;
        }

        return $this->moveToBrandDir($basePath, $brandPath, $file);
    }

    /**
     * Delete brand assets files
     *
     * @param array $curLinks
     * @param array $oriLinks
     * @param string $basePath
     * @param string $brandDir
     */
    protected function deleteRemovedAssetsInBrandDir(
        array $curLinks,
        array $oriLinks,
        string $basePath,
        string $brandDir
    ) {
        if (empty($oriLinks)) {
            return;
        }

        if (!is_array($curLinks)) {
            $curLinks = [];
        }

        $mappingCurlinks = [];

        /** @var DownloadableLink $link */
        foreach ($curLinks as $link) {
            $mappingCurlinks[] = $link->getId();
        }

        foreach ($oriLinks as $link) {
            try {
                if (in_array($link->getId(), $mappingCurlinks)) {
                    continue;
                }

                if ($link instanceof DownloadableLink) {
                    // link
                    $this->deleteFileInBrandDir(
                        $this->getFilePath($basePath, $link->getLinkFile()),
                        $brandDir
                    );

                    // set link sample base path
                    $basePath = $this->getLink()->getBaseSamplePath();
                    // link_sample
                    $this->deleteFileInBrandDir(
                        $this->getFilePath($basePath, $link->getSampleFile()),
                        $brandDir
                    );
                }

                // sample
                if ($link instanceof DownloadableSample) {
                    $this->deleteFileInBrandDir(
                        $this->getFilePath($basePath, $link->getSampleFile()),
                        $brandDir
                    );
                }
            } catch (Exception $e) {
                $this->logger->critical(
                    "BSS.ERROR: Can't delete link file. " . $e
                );
            }
        }
    }

    /**
     * Delete file in brand directory
     *
     * @param string $file
     * @param string $brandPath
     * @throws FileSystemException
     */
    public function deleteFileInBrandDir(string $file, string $brandPath)
    {
        if ($file && strpos($file, $brandPath) !== false) {
            $this->mediaDirectory->delete($file);
        }
    }

    /**
     * Move product asssets to brand dirctory
     *
     * @param Product|int $product
     * @param string $brandPath
     */
    public function moveAssetsToBrandFolder($product, string $brandPath)
    {
        if (is_int($product)) {
            try {
                $product = $this->productRepository->getById($product);
            } catch (Exception $e) {
                $this->logger->critical(__("BSS - ERROR: Can't get product: ") . $e->getMessage());
                return;
            }
        }
        $existingMediaGalleryEntries = $this->getMediaGalleryEntries($product);

        $entryChanged = false;
        foreach ($existingMediaGalleryEntries as $entry) {
            try {
                if (strpos($entry->getFile(), ltrim($brandPath)) !== false) {
                    continue;
                }

                $this->validateOriginalEntryPath($entry);

                $this->moveFileInCatalogProductFolder(
                    $brandPath,
                    $entry,
                    $product,
                    $entryChanged
                );

                // phpcs:disable Magento2.Functions.DiscouragedFunction
//                $pathInfo = pathinfo($imgPath);
//                $destinationFile = $this->getFilePath($this->mediaConfig->getBaseMediaPath(), $imgPath);
//                $absoluteFilePath = $this->mediaDirectory->getAbsolutePath($destinationFile);
//                $imageMimeType = $this->mime->getMimeType($absoluteFilePath);
//                $imageContent = $this->mediaDirectory->readFile($absoluteFilePath);
//                $imageBase64 = base64_encode($imageContent);
//                $imageName = $pathInfo['filename'];
//
//                /** @var ImageContentInterface $imgContent */
//                $imgContent = $this->imageContentInterfaceFactory->create();
//                $imgContent->setName($imageName);
//                $imgContent->setType($imageMimeType);
//                $imgContent->setBase64EncodedData($imageBase64);
//                $entry->setContent($imgContent);
//                $entry->setFile($imgPath);
//
//                $entryChanged = true;
            } catch (FileSystemException $e) {
                $this->logger->critical(__("BSS - ERROR: Can't move the assets file because: ") . $e->getMessage());
            } catch (Exception $e) {
                $this->logger->critical(
                    "BSS - ERROR: " . $e
                );
            }
        }

        try {
            if ($entryChanged) {
                $product->setMediaGalleryEntries($existingMediaGalleryEntries);
                $this->productRepository->save($product);
//                $this->cleanBrandTmpFolder($brandDir);
            }
        } catch (Exception $e) {
            $this->logger->critical(
                "BSS - ERROR: When update product gallery. Detail: " . $e
            );
        }
    }

    /**
     * Move file in catalog category folder
     *
     * @param string $subPath
     * @param ProductAttributeMediaGalleryEntryInterface $entry
     * @param Product $product
     * @param bool $entryChanged
     * @throws FileSystemException
     */
    protected function moveFileInCatalogProductFolder(
        string $subPath,
        ProductAttributeMediaGalleryEntryInterface $entry,
        Product $product,
        bool &$entryChanged
    ) {
        $imgPath = $this->moveToBrandDir(
            $this->mediaConfig->getBaseMediaPath(),
            $subPath,
            $entry->getFile()
        );
        $this->updateMediaGalleryEntityVal((int) $entry->getId(), $imgPath);
        if ($entry->getTypes() !== null || $entry->getTypes()) {
            $this->setMediaAttribute($product, $entry->getTypes(), $imgPath);

            // Set file for entry to escapse magento save product will execute event resize origin file
            $entry->setFile($imgPath);
            $entryChanged = true;
        }
    }

    /**
     * Move assets to other folder if product not in digital assets category
     *
     * @param Product|int $product
     * @param string|null $brandPath
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\StateException
     */
    public function removeAssetsFromBrandFolder($product, string $brandPath = null): bool
    {
        if (is_int($product)) {
            $product = $this->productRepository->getById($product);
        }
        $needProcess = $this->needProcessRemove($product, $brandPath);
        if ($needProcess) {
            $existingMediaGalleryEntries = $this->getMediaGalleryEntries($product);

            $entryChanged = false;
            foreach ($existingMediaGalleryEntries as $entry) {
                if (strpos($entry->getFile(), ltrim($brandPath)) !== false) {
                    $dispersionPath = $this->getDispersionPath($entry->getFile());

                    $this->moveFileInCatalogProductFolder(
                        DS . $dispersionPath . DS,
                        $entry,
                        $product,
                        $entryChanged
                    );
                }
            }

            if ($entryChanged) {
                $product->setMediaGalleryEntries($existingMediaGalleryEntries);

                $this->productRepository->save($product);
            }

            return true;
        }

        return false;
    }

    /**
     * Get media gallery entries
     *
     * @param Product $product
     * @return array|\Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface[]
     */
    private function getMediaGalleryEntries(Product $product)
    {
        try {
            $existingMediaGalleryEntries = $product->getMediaGalleryEntries();
            if (!$existingMediaGalleryEntries) {
                return [];
            }
        } catch (Exception $e) {
            $existingMediaGalleryEntries = [];
            $this->logger->critical(
                "BSS - ERROR: Can't not get the media galleries. Detail: " .
                $e
            );
        }

        return $existingMediaGalleryEntries;
    }

    /**
     * Clean the tmp folder
     *
     * @param string $brandDir
     */
    protected function cleanBrandTmpFolder(string $brandDir): void
    {
        try {
            $this->mediaDirectory->delete(
                $this->getFilePath(
                    $this->mediaConfig->getBaseMediaPath(),
                    DS . $this->getFilePath(
                        'tmp',
                        $brandDir
                    )
                )
            );
        } catch (Exception $e) {
            $this->logger->critical(__("Can't clean the %1 tmp folder. Detail: %2", $brandDir, $e));
        }
    }

    /**
     * Set media attribute value
     *
     * @param Product $product
     * @param string|string[] $mediaAttribute
     * @param string $value
     */
    public function setMediaAttribute(Product $product, $mediaAttribute, $value)
    {
        $mediaAttributeCodes = $this->mediaConfig->getMediaAttributeCodes();

        if (is_array($mediaAttribute)) {
            foreach ($mediaAttribute as $attribute) {
                if (in_array($attribute, $mediaAttributeCodes)) {
                    $product->setData($attribute, $value);
                }
            }
        } elseif (in_array($mediaAttribute, $mediaAttributeCodes)) {
            $product->setData($mediaAttribute, $value);
        }
    }

    /**
     * Validate original file
     *
     * @param Product\Gallery\Entry $entry
     * @throws FileSystemException
     */
    public function validateOriginalEntryPath(Product\Gallery\Entry $entry): void
    {
        $absoluteFilePath = $this->mediaDirectory->getAbsolutePath(
            $this->getFilePath(
                $this->mediaConfig->getBaseMediaPath(),
                $entry->getFile()
            )
        );

        // phpcs:disable Magento2.Functions.DiscouragedFunction
        if (!file_exists($absoluteFilePath)) {
            throw new FileSystemException(__("File %1 not found!", $absoluteFilePath));
        }
    }

    /**
     * Move file from origin path to brand digital assets folder
     *
     * @param string $basePath
     * @param string $brandPath
     * @param string $file
     * @param bool $toTmp
     * @return string
     * @throws FileSystemException
     */
    protected function moveToBrandDir(string $basePath, string $brandPath, string $file, bool $toTmp = false): string
    {
        if ($toTmp) {
            $brandPath = DS . $this->getFilePath(
                'tmp',
                $brandPath
            );
        }
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $pathInfo = pathinfo($file);

        if (!isset($pathInfo['basename'])) {
            throw new FileSystemException(__("File not exist!"));
        }

        // Get final brand digital assets destination file path
        $destFile = $brandPath . DS . $this->getUniqueFileNameInBrandDigitalFolder(
            $pathInfo['basename'],
            $basePath . $brandPath
        );

        // move file from default to brand path
        $this->mediaDirectory->renameFile(
            $this->getFilePath($basePath, $file),
            $this->getFilePath($basePath, $destFile)
        );

        return str_replace(
            '\\',
            '/',
            $destFile
        );
    }

    /**
     * Update gallery entry file path to Db
     *
     * @param int $id
     * @param string $value
     */
    protected function updateMediaGalleryEntityVal(int $id, string $value)
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $connection->update(
                ['entity_tbl' => $connection->getTableName('catalog_product_entity_media_gallery')],
                ['value' => $value],
                sprintf('value_id=%s', $id)
            );
        } catch (Exception $e) {
            $this->logger->critical(
                "BSS - ERROR: When update media gallery entry to DB. Detail: " . $e
            );
        }
    }

    /**
     * Get unique file name from brand digital assets folder
     *
     * @param string $fileName
     * @param string $brandPath
     * @return string
     */
    protected function getUniqueFileNameInBrandDigitalFolder(string $fileName, string $brandPath): string
    {
        return Uploader::getNewFileName(
            $this->getFilePath(
                $this->mediaDirectory->getAbsolutePath($brandPath),
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

        return $path . DS . $file;
    }
}
