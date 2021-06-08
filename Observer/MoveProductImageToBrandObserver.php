<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Observer;

use Bss\DigitalAssetsManage\Model\ProductDigitalAssetsProcessor;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;

/**
 * Class MoveProductImageToBrandObserver
 * Process move digital images after save product
 */
class MoveProductImageToBrandObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var ProductDigitalAssetsProcessor
     */
    protected $digitalAssetsProcessor;

    /**
     * MoveProductImageToBrandObserver constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param ProductDigitalAssetsProcessor $digitalAssetsProcessor
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductDigitalAssetsProcessor $digitalAssetsProcessor
    ) {
        $this->productRepository = $productRepository;
        $this->digitalAssetsProcessor = $digitalAssetsProcessor;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        return;
        /** @var Product $product */
        $product = $observer->getProduct();
        if (!$product) {
            return;
        }

        $this->digitalAssetsProcessor->process($product);
    }
}
