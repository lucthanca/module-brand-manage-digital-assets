<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Model;

use Bss\DigitalAssetsManage\Helper\DownloadableHelper;
use Bss\DigitalAssetsManage\Helper\GetBrandDirectory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Api\Data\ProductInterface;
use Exception;
use \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Downloadable\Api\Data\LinkInterface;
use Magento\Downloadable\Api\Data\SampleInterface;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\MediaStorage\Model\File\Uploader;
use \Magento\Downloadable\Model\Product\Type as DownloadableType;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use \Magento\Framework\File\Mime;

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
     * @var DownloadableHelper
     */
    protected $downloadableHelper;

    /**
     * @var ImageContentInterfaceFactory
     */
    protected $contentInterfaceFactory;

    /**
     * @var Mime
     */
    protected $mime;

    /**
     * DigitalAssetsProcessor constructor.
     *
     * @param \Psr\Log\LoggerInterface $logger
     * @param ProductRepositoryInterface $productRepository
     * @param GetBrandDirectory $getBrandDirectory
     * @param ResourceConnection $resourceConnection
     * @param MediaConfig $mediaConfig
     * @param Filesystem $filesystem
     * @param DownloadableHelper $downloadableHelper
     * @param ImageContentInterfaceFactory $contentInterfaceFactory
     * @param Mime $mime
     * @throws FileSystemException
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        ProductRepositoryInterface $productRepository,
        GetBrandDirectory $getBrandDirectory,
        ResourceConnection $resourceConnection,
        MediaConfig $mediaConfig,
        Filesystem $filesystem,
        DownloadableHelper $downloadableHelper,
        ImageContentInterfaceFactory $contentInterfaceFactory,
        Mime $mime
    ) {
        $this->logger = $logger;
        $this->productRepository = $productRepository;
        $this->getBrandDirectory = $getBrandDirectory;
        $this->resourceConnection = $resourceConnection;
        $this->mediaConfig = $mediaConfig;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->downloadableHelper = $downloadableHelper;
        $this->contentInterfaceFactory = $contentInterfaceFactory;
        $this->mime = $mime;
    }

    /**
     * @param int|ProductInterface $product
     */
    public function process(
        $product,
        string $brandPath = null
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

        $this->processImageAssets($product, $brandPath);
        $this->processDownloadableAssets($product, $brandPath);
//        $this->updateDownloadAssetsFiles($product, $brandPath);
    }

    /**
     * Process move/remove downloadable assets to brand directory
     *
     * @param ProductInterface|int $product
     */
    public function processDownloadableAssets($product, string $brandPath = null, string $action = null)
    {
        if (!$product instanceof ProductInterface) {
            $product = $this->productRepository->getById($product);
        }
        if ($action === "remove") {
            $this->moveDownloadableAssetsToDispersionPath($product, $brandPath);
            return;
        }

        $this->moveDownloadableAssetsToBrandDir($product);
    }

    /**
     * Move downladoable assets back to dispersion path if not in digital assets
     *
     * @param Product|ProductInterface $product
     * @param string|null $brandPath
     */
    public function moveDownloadableAssetsToDispersionPath($product, string $brandPath = null)
    {
        $entryChanged = false;
        $this->moveDownloadableAssetsToBrandDir($product, $brandPath, $entryChanged, true);
    }

    /**
     * Move downloadable assets to brand directory
     *
     * @param Product|ProductInterface $product
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
            $extension = $product->getExtensionAttributes();
            $links = $extension->getDownloadableProductLinks();
            $samples = $extension->getDownloadableProductSamples();

            $currentBrandPath = $this->getBrandDirectory->execute($product);
            if ($currentBrandPath) {
                $brandPath = $currentBrandPath;
                $backToDispersionPath = false;
            }

            if (!$brandPath) {
                return;
            }

            $this->processProductLinks($links, $brandPath, $backToDispersionPath, $entryChanged);
            $extension->setDownloadableProductLinks($links);


            $this->processProductLinks($samples, $brandPath, $backToDispersionPath, $entryChanged);
            $extension->setDownloadableProductSamples($samples);

            $product->setExtensionAttributes($extension);

            if ($entryChanged) {
                $this->productRepository->save($product);
            }
        } catch (\Exception $e) {
            $this->logger->critical(__("Error when move to brand directory: ") . $e);
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
                $newLinkFile = $this->moveFile(
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


                $newSampleFile = $this->moveFile(
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


    /**
     * Delete all assets in brand dir if the product is not downloadable
     *
     * @param ProductInterface $product
     * @return bool
     */
    public function deleteAll(ProductInterface $product): bool
    {
        if ($product->getTypeId() !== DownloadableType::TYPE_DOWNLOADABLE &&
            $product->getOrigData("type_id") === DownloadableType::TYPE_DOWNLOADABLE
        ) {
            $brandPath = $this->getBrandDirectory->execute($product, true);
            $modifiedLinks = [];
            $extension = $product->getExtensionAttributes();
            $links = $extension->getDownloadableProductLinks();
            $samples = $extension->getDownloadableProductSamples();
            $this->getAllLinks($modifiedLinks, $links, $brandPath, "remove");
            $this->getAllLinks($modifiedLinks, $samples , $brandPath, "remove");

            {
                $this->deleteLinks($modifiedLinks['remove'] ?? []);
            }

            return true;
        }

        return false;
    }

    /**
     * Move file to brand dir if available
     *
     * @param ProductInterface $product
     * @throws FileSystemException
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws StateException
     */
    public function updateDownloadAssetsFiles(ProductInterface $product, string $brandPath = null)
    {
        if (!$brandPath) {
            $brandPath = $this->getBrandDirectory->execute($product);
        }

        if (!$brandPath) {
            return;
        }

        $modifiedLinks = [];
        $extension = $product->getExtensionAttributes();
        $links = $extension->getDownloadableProductLinks();
        $samples = $extension->getDownloadableProductSamples();
        $this->getAllLinks($modifiedLinks, $links, $brandPath);
        $this->getAllLinks($modifiedLinks, $samples, $brandPath);
        dd($modifiedLinks);
        $oldLinks = $product->getOrigData("downloadable_links");
        $oldSamples = $product->getOrigData("downloadable_samples");
        if (is_object($oldSamples)) {
            $oldSamples = $oldSamples->getItems();
        }
        if (is_object($oldLinks)) {
            $oldLinks = $oldLinks->getItems();
        }
        $modifiedLinks = [];
        $this->getDifferenceLinks($modifiedLinks, $links, $oldLinks, $brandPath);
        $this->getDifferenceLinks($modifiedLinks, $samples, $oldSamples, $brandPath);

        $mappingPath = [
            'samples' => $this->downloadableHelper->getSample()->getBasePath(),
            'link_samples' => $this->downloadableHelper->getLink()->getBaseSamplePath(),
            'links' => $this->downloadableHelper->getLink()->getBasePath()
        ];

        $linksChanged = 0;
        $samplesChanged = 0;
        foreach ($mappingPath as $linkType => $basePath) {
            // update new path for links
            foreach ($links as $link) {
                if ($linkPath = $link->getData('modify_' . $linkType)) {
                    $newPath = $this->moveFile(
                        $basePath,
                        $brandPath,
                        $linkPath
                    );
                    if ($linkType === "links") {
                        $link->setLinkFile($newPath);
                    }

                    if ($linkType === "link_samples") {
                        $link->setSampleFile($newPath);
                    }

                    $link->setData('modify_' . $linkType, null);
                    $linksChanged++;
                }
            }
            // update new path for samples
            foreach ($samples as $link) {
                if ($linkPath = $link->getData('modify_' . $linkType)) {
                    $newPath = $this->moveFile(
                        $basePath,
                        $brandPath,
                        $linkPath
                    );

                    if ($linkType === "samples") {
                        $link->setSampleFile($newPath);
                    }

                    $link->setData('modify_' . $linkType, null);
                    $linksChanged++;
                }
            }

            //remove old file from the brand directory
            if (isset($modifiedLinks['remove'][$linkType])) {
                foreach ($modifiedLinks['remove'][$linkType] as $deleteLink) {
                    $this->mediaDirectory->delete(
                        $this->getFilePath($basePath, $deleteLink)
                    );
                }
            }
        }

        $needSaveProduct = false;
        if ($linksChanged) {
            $extension->setDownloadableProductLinks($links);
            $needSaveProduct = true;
        }

        if ($samplesChanged) {
            $extension->setDownloadableProductSamples($samples);
            $needSaveProduct = true;
        }

        if ($needSaveProduct) {
            $product->setExtensionAttributes($extension);
            // vadu_log(['save' => 'updateDownloadAssetsFiles']);
            $this->productRepository->save($product);
        }
    }

    private function deleteLinks(array $links)
    {
        $mappingPath = [
            'samples' => $this->downloadableHelper->getSample()->getBasePath(),
            'link_samples' => $this->downloadableHelper->getLink()->getBaseSamplePath(),
            'links' => $this->downloadableHelper->getLink()->getBasePath()
        ];

        foreach ($mappingPath as $linkType => $basePath) {
            if (isset($links[$linkType])) {
                foreach ($links[$linkType] as $deleteLink) {
                    $this->mediaDirectory->delete(
                        $this->getFilePath($basePath, $deleteLink)
                    );
                }
            }
        }
    }

    /**
     * Get all links and add to modified links
     *
     * @param array $modifiedLinks
     * @param array|null $links
     * @param string|null $brandPath - brand path for check
     * @param string $methodKey
     */
    protected function getAllLinks(
        array &$modifiedLinks,
        ?array $links,
        ?string $brandPath,
        string $methodKey = "update",
        string $compare = "include"
    ) {
        if (!$links) {
            return;
        }

        foreach ($links as $link) {
            if ($linkFile = $link->getLinkFile()) {
                if ($brandPath === null) {
                    $modifiedLinks[$methodKey]["links"][] = $linkFile;
                } elseif (strpos($linkFile, $brandPath) !== false) { // if include the path
                    $modifiedLinks[$methodKey]["links"][] = $linkFile;
                }
            }
            $linkType = $link instanceof SampleInterface ? "samples" : "link_samples";
            if ($sampleFile = $link->getSampleFile()) {
                if ($brandPath === null) {
                    $modifiedLinks[$methodKey][$linkType][] = $sampleFile;
                } elseif (strpos($linkFile, $brandPath) !== false) { // if include the path
                    $modifiedLinks[$methodKey][$linkType][] = $sampleFile;
                }
            }
        }
    }

    /**
     * get differnce links for update, need update to brand dir, need remove from brand dir
     *
     * @param array $modifiedLinks
     * @param LinkInterface[]|SampleInterface[] $links
     * @param LinkInterface[]|SampleInterface[] $originLinks
     * @param string $brandPath
     * @return array
     */
    protected function getDifferenceLinks(array &$modifiedLinks, ?array &$links, ?array $originLinks, string $brandPath): array
    {
        if ($links === null) {
            $links = [];
        }

        if ($originLinks === null) {
            $originLinks = [];
        }

        foreach ($links as $link) {
            // If new link be added, add to the modifiedLinks and go next turn
            if (!$link->getId()) {
                if ($link->getLinkFile() && !$link->getData("is_set_modify_links")) {
                    $modifiedLinks['update']['links'][] = $link->getLinkFile();
                    $link->setData("modify_links" , $link->getLinkFile());
                    // Set is_set_modify_ to defined the new assets was be added
                    // and next save process will skip this
                    $link->setData("is_set_modify_links", true);
                }
                if ($link->getSampleFile()) {
                    $linkType = $link instanceof LinkInterface ? "link_samples" : "samples";
                    if (!$link->getData("is_set_modify_$linkType")) {
                        $modifiedLinks['update'][$linkType][] = $link->getSampleFile();
                        $link->setData("modify_" . $linkType , $link->getSampleFile());
                        $link->setData("is_set_modify_$linkType", true);
                    }
                }
                continue;
            }

            foreach ($originLinks as $originLink) {
                if ($link->getId() !== $originLink->getId()) {
                    continue;
                }
                $this->getModifiedLinkFile($modifiedLinks, $link, $originLink, $brandPath, "getLinkFile", "links");
                $linkType = $link instanceof LinkInterface && $originLink instanceof LinkInterface ? "link_samples" : "samples";
                $this->getModifiedLinkFile($modifiedLinks, $link, $originLink, $brandPath, "getSampleFile", $linkType);
            }
        }


        // make flat links ids for check removed link
        $flatLinkIds = [];
        foreach ($links as $link) {
            $flatLinkIds[] = (int) $link->getId();
        }

        // get removed links
        foreach ($originLinks as $link) {
            if (!in_array((int) $link->getId(), $flatLinkIds)) {
                if ($link->getSampleFile()) {
                    $linkType = $link instanceof LinkInterface ? "link_samples" : "samples";
                    $modifiedLinks['remove'][$linkType][] = $link->getSampleFile();
                }

                if ($link->getLinkFile()) {
                    $modifiedLinks['remove']["links"][] = $link->getLinkFile();
                }
            }
        }

        return $modifiedLinks;
    }

    /**
     * @param array $modifiedLinks
     * @param LinkInterface|SampleInterface $link
     * @param LinkInterface|SampleInterface $originLink
     * @param string $brandPath
     * @param string $func
     * @param string $linkType
     */
    private function getModifiedLinkFile(array &$modifiedLinks, $link, $originLink, string $brandPath, string $func, string $linkType)
    {
        if (!$link->$func() && !$originLink->$func()) {
            return;
        }

        if ($this->compareTwoLinks($link->$func(), $originLink->$func())) {
            // If not in brand dir
            if ($link->$func() && strpos($link->$func(), $brandPath) === false) {
                $modifiedLinks['update'][$linkType][] = $link->$func();
                $link->setData("modify_" . $linkType, $link->$func());
            }
            return;
        }

        // If not in brand dir
        if ($link->$func() && strpos($link->$func(), $brandPath) === false) {
            $modifiedLinks['update'][$linkType][] = $link->$func();
            $link->setData("modify_" . $linkType, $link->$func());
        }

        // remove on file in brand dir for not affect to old product
        if ($originLink->$func() && strpos($originLink->$func(), $brandPath) !== false) {
            $modifiedLinks['remove'][$linkType][] = $originLink->$func();
        }
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
     * Process move/remove product images to brand directory
     *
     * @param ProductInterface|int $product
     * @param string|null $brandPath
     * @param bool $noSaveProduct
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function processImageAssets($product, string $brandPath = null, $action = null, bool $noSaveProduct = false)
    {
        if (!$product instanceof ProductInterface) {
            $product = $this->productRepository->getById($product);
        }

        if ($action === null) {
            if ($this->removeImagesFromBrandDir($product, $brandPath, $noSaveProduct)) {
                return;
            }

            $this->moveImagesToBrandDir($product, $brandPath, $noSaveProduct);
            return;
        }

        if ($action === 'remove') {
            $this->removeImagesFromBrandDir($product, $brandPath, $noSaveProduct);
            return;
        }

        $this->moveImagesToBrandDir($product, $brandPath, $noSaveProduct);
    }

    /**
     * Move product images assets to brand directory
     *
     * @param ProductInterface $product
     * @param string|null $brandPath
     */
    public function moveImagesToBrandDir(ProductInterface $product, string $brandPath = null, bool $noSave =false)
    {
        if (!$brandPath) {
            $brandPath = $this->getBrandDirectory->execute($product);
        }
        if (!$brandPath) {
            return;
        }


        $galleryEntries = $this->getMediaGalleryEntries($product);
        $entryChanged = false;
        $galleryMedia = $product->getData('media_gallery');

        // Process new gallery images
        if (isset($galleryMedia['images'])) {
            foreach ($galleryMedia['images'] as &$image) {
                if (!isset($image['value_id']) || !$image['value_id']) {
                    if (isset($image['file']) && strpos($image['file'], ltrim($brandPath, "/")) !== false) {
                        continue;
                    }
                    $origFile = $image['file'];
                    $newFile = $this->moveFile(
                        $this->mediaConfig->getBaseTmpMediaPath(),
                        $brandPath,
                        $origFile
                    );

                    $image['file'] = $newFile;
                }
            }
        }
        $product->setData("media_gallery", $galleryMedia);

        // process exists images
        foreach ($galleryEntries as $entry) {
            if (!$entry->getId()) {
                continue;
            }
            try {
                // If file is in brand directory, skip
                if (strpos($entry->getFile(), ltrim($brandPath, "/")) !== false) {
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
            $product->setData("is_modify_images", true);
            try {
                if (!$noSave) {
                    $this->productRepository->save($product);
                }
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
     * @param string|null $brandPath
     * @return bool
     */
    public function removeImagesFromBrandDir(ProductInterface $product, string $brandPath = null, bool $noSave = false): bool
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
                $this->moveEntryFileOutBrandDir($product, $entry, $brandPath, $entryChanged);
            }

            if ($entryChanged) {
                $product->setMediaGalleryEntries($galleryEntries);
                $product->setData("is_modify_images", true);
                try {
                    if (!$noSave) {
                        $this->productRepository->save($product);
                    }
                    return true;
                } catch (Exception $e) {
                    $this->logger->critical(
                        "BSS.ERROR: Save product when remove from brand directory. " . $e
                    );
                }
            }
        }

        return false;
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
            $dispersionPath = $this->downloadableHelper->getFileHelper()->getDispersionPath($entry->getFile());

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
     * @param string $fromTmp - move from tmp in base path
     * @return string
     * @throws FileSystemException
     */
    public function moveFile(
        string $basePath,
        string $subPath,
        string $file,
        string $fromTmp = null
    ): string {
//        if ($fromTmp) {
//            $subPath = DIRECTORY_SEPARATOR . $this->getFilePath(
//                'tmp',
//                $subPath
//            );
//        }

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

        if ($fromTmp) {
            $tmpPath = $fromTmp;
        }

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
        $basePath = $this->mediaConfig->getBaseMediaPath();
        if (!$entry->getId()) {
            $basePath = $this->mediaConfig->getBaseTmpMediaPath();
        }
        $file = $entry->getFile();

        $absoluteFilePath = $this->mediaDirectory->getAbsolutePath(
            $this->getFilePath(
                $basePath,
                $file
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
