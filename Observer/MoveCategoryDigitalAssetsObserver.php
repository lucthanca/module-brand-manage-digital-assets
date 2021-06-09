<?php
declare(strict_types=1);
namespace Bss\DigitalAssetsManage\Observer;

use Bss\DigitalAssetsManage\Helper\GetBrandDirectory;
use Bss\DigitalAssetsManage\Model\DigitalAssetsProcessor;
use Magento\Catalog\Model\Category;
use Psr\Log\LoggerInterface;

/**
 * Class Observer
 * Move image from category base path to brand path
 */
class MoveCategoryDigitalAssetsObserver implements \Magento\Framework\Event\ObserverInterface
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
     * @var DigitalAssetsProcessor
     */
    protected $assetsProcessor;

    /**
     * MoveCategoryDigitalAssetsObserver constructor.
     *
     * @param LoggerInterface $logger
     * @param GetBrandDirectory $getBrandDirectory
     * @param DigitalAssetsProcessor $assetsProcessor
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        GetBrandDirectory $getBrandDirectory,
        DigitalAssetsProcessor $assetsProcessor
    ) {
        $this->logger = $logger;
        $this->getBrandDirectory = $getBrandDirectory;
        $this->assetsProcessor = $assetsProcessor;
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

        foreach (array_keys($insert) as $pId) {
            $this->assetsProcessor->process($pId, null, 'move');
        }
        foreach (array_keys($delete) as $pId) {
            $this->assetsProcessor->process($pId, $brandPath, "remove");
        }
    }
}
