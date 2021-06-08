<?php
declare(strict_types=1);
namespace Bss\DigitalAssetsManage\Observer;

use Bss\DigitalAssetsManage\Helper\GetBrandDirectory;
use Bss\DigitalAssetsManage\Model\ProductDigitalAssetsProcessor;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ImageUploader;
use Magento\Framework\App\ObjectManager;
use Psr\Log\LoggerInterface;

/**
 * Class Observer
 * Move image from category base path to brand path
 */
class MoveCategoryDigitalAssetsObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \Magento\Framework\Filesystem
     */
    protected $filesystem;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $categoryRepository;

    /**
     * @var ImageUploader
     */
    protected $imageUploader;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var GetBrandDirectory
     */
    protected $getBrandDirectory;

    /**
     * @var ProductDigitalAssetsProcessor
     */
    protected $digitalAssetsProcessor;

    /**
     * MoveCategoryDigitalAssetsObserver constructor.
     *
     * @param LoggerInterface $logger
     * @param \Magento\Framework\Filesystem $filesystem
     * @param CategoryRepositoryInterface $categoryRepository
     * @param GetBrandDirectory $getBrandDirectory
     * @param ProductDigitalAssetsProcessor $digitalAssetsProcessor
     * @param ImageUploader|null $imageUploader
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Filesystem $filesystem,
        CategoryRepositoryInterface $categoryRepository,
        GetBrandDirectory $getBrandDirectory,
        ProductDigitalAssetsProcessor $digitalAssetsProcessor,
        ImageUploader $imageUploader = null
    ) {
        $this->filesystem = $filesystem;
        $this->categoryRepository = $categoryRepository;
        $this->imageUploader = $imageUploader ??
            ObjectManager::getInstance()->get(ImageUploader::class);
        $this->logger = $logger;
        $this->getBrandDirectory = $getBrandDirectory;
        $this->digitalAssetsProcessor = $digitalAssetsProcessor;
    }

    /**
     * @inheritDoc
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var Category $category */
        $category = $observer->getData('category');
        $brandPath = $this->getBrandDirectory->getBrandPathWithCategory($category);

        if (!$brandPath) {
            return;
        }

        $products = $category->getPostedProducts();
        $oldProducts = $category->getProductsPosition();
        $insert = array_diff_key($products, $oldProducts);
        $delete = array_diff_key($oldProducts, $products);

        try {
            foreach (array_keys($insert) as $pId) {
                $this->digitalAssetsProcessor->moveAssetsToBrandFolder((int) $pId, $brandPath);
                $this->digitalAssetsProcessor->moveDownloadableAssetsToBrandDir((int) $pId, $brandPath);
            }

            foreach (array_keys($delete) as $pId) {
                $this->digitalAssetsProcessor->removeAssetsFromBrandFolder((int) $pId, $brandPath);
                $this->digitalAssetsProcessor->moveDownloadableAssetsToDispersionPath((int) $pId, $brandPath);
            }
        } catch (\Exception $e) {
            $this->logger->critical(
                "BSS - ERROR: When update product assets on category save. Detail: " . $e
            );
        }
    }
}
