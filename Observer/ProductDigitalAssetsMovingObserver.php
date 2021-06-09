<?php
declare(strict_types=1);
namespace Bss\DigitalAssetsManage\Observer;

use Bss\DigitalAssetsManage\Model\DigitalAssetsProcessor;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;

class ProductDigitalAssetsMovingObserver implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var DigitalAssetsProcessor
     */
    protected $digitalAssetsProcessor;

    /**
     * ProductDigitalAssetsMovingObserver constructor.
     *
     * @param DigitalAssetsProcessor $digitalAssetsProcessor
     */
    public function __construct(
        DigitalAssetsProcessor $digitalAssetsProcessor
    ) {
        $this->digitalAssetsProcessor = $digitalAssetsProcessor;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        /** @var Product $product */
        $product = $observer->getProduct();
        if (!$product || $product->getData("is_modify_images")) {
            return;
        }

        $this->digitalAssetsProcessor->processImageAssets($product, null, null, true);
    }
}
