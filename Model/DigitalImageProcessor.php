<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Model;

use Bss\DigitalAssetsManage\Helper\DownloadableHelper;
use Bss\DigitalAssetsManage\Helper\GetBrandDirectory;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Exception;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\FileSystemException;
use Psr\Log\LoggerInterface;

/**
 * Class DigitalImageProcessor
 * Processing digital assets images
 */
class DigitalImageProcessor
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var GetBrandDirectory
     */
    protected $getBrandDirectory;

    /**
     * @var DownloadableHelper
     */
    protected $downloadableHelper;

    /**
     * @var MediaConfig
     */
    protected $mediaConfig;

    /**
     * @var MediaFile
     */
    protected $file;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * DigitalImageProcessor constructor.
     *
     * @param LoggerInterface $logger
     * @param GetBrandDirectory $getBrandDirectory
     * @param DownloadableHelper $downloadableHelper
     * @param MediaConfig $mediaConfig
     * @param MediaFile $file
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        LoggerInterface $logger,
        GetBrandDirectory $getBrandDirectory,
        DownloadableHelper $downloadableHelper,
        MediaConfig $mediaConfig,
        MediaFile $file,
        ResourceConnection $resourceConnection
    ) {
        $this->logger = $logger;
        $this->getBrandDirectory = $getBrandDirectory;
        $this->downloadableHelper = $downloadableHelper;
        $this->mediaConfig = $mediaConfig;
        $this->file = $file;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Move product gallery entries to brand directory
     *
     * @param ProductInterface $product
     * @param string|null $brandPath
     * @throws FileSystemException
     */
    public function moveToBrandDirectory(ProductInterface $product, string $brandPath = null)
    {
        if (!$brandPath) {
            $brandPath = $this->getBrandDirectory->execute($product);
        }
        if (!$brandPath) {
            return;
        }

        $entryChanged = false;
        $galleryEntries = $this->getMediaGalleryEntries($product);
        // process exists images
        foreach ($galleryEntries as $entry) {
            if (!$entry->getId()) {
                // skip no file uploaded
                if (!$entry->getFile() || strpos($entry->getFile(), ltrim($brandPath, "/")) !== false) {
                    continue;
                }

                // New file- > move to tmp for default working with it
                $newFile = $this->file->moveFile(
                    $this->mediaConfig->getBaseTmpMediaPath(),
                    $brandPath,
                    $entry->getFile()
                );

                $entry->setFile($newFile);

                // Set the image roles
                if ($entry->getTypes()) {
                    $this->setMediaAttribute($product, $entry->getTypes(), $newFile);
                }
                $entryChanged = true;
                continue;
            }
            try {
                // If file is in brand directory, skip
                if (strpos($entry->getFile(), ltrim($brandPath, "/")) !== false) {
                    continue;
                }

                // Use direct sql query to update
                $this->moveEntry(
                    $product,
                    $entry,
                    $brandPath,
                    $entryChanged
                );
            } catch (Exception $e) {
                $this->logger->critical(
                    "BSS.ERROR: Move entry to brand directory. " . $e
                );
            }
        }

        if ($entryChanged) {
            $product->setMediaGalleryEntries($galleryEntries);
            $product->setData("is_modify_images", true);
        }
    }

    /**
     * Process to rollback path to same with default
     *
     * @param ProductInterface $product
     * @param string|null $brandPath
     * @return bool
     */
    public function rollbackToDispersionFolder(ProductInterface $product, string $brandPath = null): bool
    {
        if ($brandPath) {
            $needToRemove = true;
        } else {
            $needToRemove = $this->isNeedToMove($product, $brandPath);
        }

        if ($needToRemove && $brandPath) {
            $galleryEntries = $this->getMediaGalleryEntries($product);
            $entryChanged = false;
            foreach ($galleryEntries as $entry) {
                if (strpos($entry->getFile(), ltrim($brandPath, "/")) !== false) {
                    $dispersionPath = $this->downloadableHelper->getFileHelper()->getDispersionPath($entry->getFile());
                    $this->moveEntry($product, $entry, $dispersionPath, $entryChanged);
                }
            }

            if ($entryChanged) {
                $product->setMediaGalleryEntries($galleryEntries);
                $product->setData("is_modify_images", true);
                return true;
            }
        }

        return false;
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
            $newPath = $this->file->moveFile(
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
}
