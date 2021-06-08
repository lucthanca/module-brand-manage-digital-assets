<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Model;

use Bss\DigitalAssetsManage\Helper\GetBrandDirectory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Api\Data\ProductInterface;
use Exception;
use \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\MediaStorage\Model\File\Uploader;

/**
 * Processing digital assets
 * @SuppressWarnings(CouplingBetweenObjects)
 */
class DigitalAssetsProcessor
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var GetBrandDirectory
     */
    protected $getBrandDirectory;

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
     * DigitalAssetsProcessor constructor.
     *
     * @param \Psr\Log\LoggerInterface $logger
     * @param ProductRepositoryInterface $productRepository
     * @param GetBrandDirectory $getBrandDirectory
     * @param ResourceConnection $resourceConnection
     * @param MediaConfig $mediaConfig
     * @param Filesystem $filesystem
     * @throws FileSystemException
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        ProductRepositoryInterface $productRepository,
        GetBrandDirectory $getBrandDirectory,
        ResourceConnection $resourceConnection,
        MediaConfig $mediaConfig,
        Filesystem $filesystem
    ) {
        $this->logger = $logger;
        $this->productRepository = $productRepository;
        $this->getBrandDirectory = $getBrandDirectory;
        $this->resourceConnection = $resourceConnection;
        $this->mediaConfig = $mediaConfig;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     * @param int|ProductInterface $product
     */
    public function process(
        $product
    ) {
        try {
            if (!$product instanceof ProductInterface) {
                $product = $this->productRepository->getById($product);
            }
        } catch (Exception $e) {
            $this->logger->critical(
                "BSS.ERROR: When get product. " . $e
            );

            return;
        }

        $this->processImageAssets($product);
        $this->processDownloadableAssets($product);
    }

    /**
     * Process move/remove downloadable assets to brand directory
     *
     * @param ProductInterface $product
     */
    public function processDownloadableAssets(ProductInterface $product)
    {
        if ($this->removeDownloadableAssetsFromBrandDir($product)) {
            return;
        }

        $this->moveDownloadableAssetsToBrandDir($product);
    }

    public function removeDownloadableAssetsFromBrandDir(ProductInterface $product)
    {
        $needToRemove = $this->isNeedToMove($product, $brandPath);
    }

    /**
     * Process move/remove product images to brand directory
     *
     * @param ProductInterface $product
     */
    public function processImageAssets(ProductInterface $product)
    {
        if ($this->removeImagesFromBrandDir($product)) {
            return;
        }

        $this->moveImagesToBrandDir($product);
    }

    /**
     * Move product images assets to brand directory
     *
     * @param ProductInterface $product
     */
    public function moveImagesToBrandDir(ProductInterface $product)
    {
        $brandPath = $this->getBrandDirectory->execute($product);

        if (!$brandPath) {
            return;
        }

        $galleryEntries = $this->getMediaGalleryEntries($product);
        $entryChanged = false;
        foreach ($galleryEntries as $entry) {
            try {
                // If file is in brand directory, skip
                if (strpos($entry->getFile(), ltrim($brandPath)) !== false) {
                    continue;
                }
                $this->validateOriginalEntryPath($entry);
                $this->moveEntry(
                    $product,
                    $entry,
                    $brandPath,
                    $entryChanged
                );
            } catch (FileSystemException $e) {
                $this->logger->critical($e);
            } catch (Exception $e) {
                $this->logger->critical(
                    "BSS.ERROR: Move entry to brand directory. " . $e
                );
            }
        }

        if ($entryChanged) {
            $product->setMediaGalleryEntries($galleryEntries);
            try {
                $this->productRepository->save($product);
            } catch (Exception $e) {
                $this->logger->critical(
                    "BSS.ERROR: Save product when move image assets to brand directory. " . $e
                );
            }
        }
    }

    /**
     * Remove all gallery entries to outside brand directory if product has no longer assigned to digital category
     *
     * @param ProductInterface $product
     * @return bool
     */
    public function removeImagesFromBrandDir(ProductInterface $product): bool
    {
        $needToRemove = $this->isNeedToMove($product, $brandPath);

        if ($needToRemove && $brandPath) {
            $galleryEntries = $this->getMediaGalleryEntries($product);
            $entryChanged = false;
            foreach ($galleryEntries as $entry) {
                $this->moveEntryFileOutBrandDir($product, $entry, $brandPath, $entryChanged);
            }

            if ($entryChanged) {
                $product->setMediaGalleryEntries($galleryEntries);
                try {
                    $this->productRepository->save($product);
                } catch (Exception $e) {
                    $this->logger->critical(
                        "BSS.ERROR: Save product when remove from brand directory. " . $e
                    );
                }
            }
        }

        return $needToRemove;
    }

    /**
     * Get is need to move assets from brand directory
     *
     * @param ProductInterface $product
     * @param string|null $brandPath
     * @return bool
     */
    private function isNeedToMove(ProductInterface $product, string &$brandPath = null): bool
    {
        $newBrandDir = $this->getBrandDirectory->execute($product);
        $oldBrandDir = $this->getBrandDirectory->execute($product, true);

        $brandPath = $oldBrandDir;
        return $oldBrandDir !== false && $newBrandDir === false;
    }

    /**
     * Remove entry file to outside brand directory
     *
     * @param ProductInterface $product
     * @param ProductAttributeMediaGalleryEntryInterface $entry
     * @param string $brandPath
     * @param bool $entryChanged
     */
    public function moveEntryFileOutBrandDir(
        ProductInterface $product,
        ProductAttributeMediaGalleryEntryInterface $entry,
        string $brandPath,
        bool &$entryChanged = false
    ) {
        if (strpos($entry->getFile(), ltrim($brandPath)) !== false) {
            $dispersionPath = $this->getDispersionPath($entry->getFile());

            $this->moveEntry($product, $entry, $dispersionPath, $entryChanged);
        }
    }

    /**
     * Move entry to sub-path in catalog product base path
     *
     * @param ProductInterface $product
     * @param ProductAttributeMediaGalleryEntryInterface $entry
     * @param string $path - Sub-path in catalog \ product base path
     * @param bool $entryChanged
     */
    public function moveEntry(
        ProductInterface $product,
        ProductAttributeMediaGalleryEntryInterface $entry,
        string $path,
        bool &$entryChanged = false
    ) {
        if (!$entry->getFile()) {
            return;
        }

        try {
            $newPath = $this->moveFile(
                $this->mediaConfig->getBaseMediaPath(),
                $path,
                $entry->getFile()
            );
            $this->updateMediaGalleryEntityVal((int) $entry->getId(), $newPath);
            if ($entry->getTypes() !== null || $entry->getTypes()) {
                $this->setMediaAttribute($product, $entry->getTypes(), $newPath);

                // Set file for entry to escapse magento save product will execute event resize origin file
                $entry->setFile($newPath);
                $entryChanged = true;
            }
        } catch (Exception $e) {
            $this->logger->critical(
                "BSS.ERROR: When move gallery entry. " . $e
            );
        }
    }

    /**
     * Set media attribute value
     *
     * @param ProductInterface $product
     * @param string|string[] $mediaAttribute
     * @param string $value
     */
    public function setMediaAttribute(ProductInterface $product, $mediaAttribute, $value)
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
     * @param string $basePath - media base path
     * @param string $subPath - sub path in base path
     * @param string $file
     * @param bool $toTmp - move to tmp in base path
     * @return string
     * @throws FileSystemException
     */
    public function moveFile(
        string $basePath,
        string $subPath,
        string $file,
        bool $toTmp = false
    ): string {
        if ($toTmp) {
            $subPath = DS . $this->getFilePath(
                'tmp',
                $subPath
            );
        }
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $pathInfo = pathinfo($file);

        if (!isset($pathInfo['basename'])) {
            throw new FileSystemException(__("File not exist!"));
        }

        // Get final brand digital assets destination file path
        $destFile = $subPath . DS . $this->getUniqueFileNameInBrandDigitalFolder(
            $pathInfo['basename'],
            $basePath . $subPath
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
     * Validate original file
     *
     * @param Product\Gallery\Entry $entry
     * @throws FileSystemException
     */
    public function validateOriginalEntryPath(ProductAttributeMediaGalleryEntryInterface $entry): void
    {
        $absoluteFilePath = $this->mediaDirectory->getAbsolutePath(
            $this->getFilePath(
                $this->mediaConfig->getBaseMediaPath(),
                $entry->getFile()
            )
        );

        // phpcs:disable Magento2.Functions.DiscouragedFunction
        if (!file_exists($absoluteFilePath)) {
            throw new FileSystemException(__("BSS.ERROR: File %1 not found!", $absoluteFilePath));
        }
    }

    /**
     * Get media gallery entries
     *
     * @param Product $product
     * @return array|ProductAttributeMediaGalleryEntryInterface[]
     */
    private function getMediaGalleryEntries(ProductInterface $product): array
    {
        try {
            $existingMediaGalleryEntries = $product->getMediaGalleryEntries();
            if (!$existingMediaGalleryEntries) {
                return [];
            }
        } catch (Exception $e) {
            $existingMediaGalleryEntries = [];
            $this->logger->critical(
                "BSS.ERROR: Can't not get the media galleries. " .
                $e
            );
        }

        return $existingMediaGalleryEntries;
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
                "BSS.ERROR: When update media gallery entry to DB. " . $e
            );
        }
    }
}
