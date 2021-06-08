<?php
declare(strict_types=1);
namespace Bss\DigitalAssetsManage\Plugin\Controller\Adminhtml\Product\Initialization;

use Bss\DigitalAssetsManage\Model\ProductDigitalAssetsProcessor;

/**
 * Class MoveDownloadableLinksToBrand
 * Move assets file to brand path for digital cate
 * @SuppressWarnings(CouplingBetweenObjects)
 */
class MoveDownloadableLinksToBrand
{
    /**
     * @var ProductDigitalAssetsProcessor
     */
    protected $digitalAssetsProcessor;

    /**
     * MoveDownloadableLinksToBrand constructor.
     *
     * @param ProductDigitalAssetsProcessor $digitalAssetsProcessor
     */
    public function __construct(
        ProductDigitalAssetsProcessor $digitalAssetsProcessor
    ) {
        $this->digitalAssetsProcessor = $digitalAssetsProcessor;
    }

    /**
     * Move assets file to brand path for digital cate
     *
     * @param \Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper $subject
     * @param \Magento\Catalog\Model\Product $product
     * @return \Magento\Catalog\Model\Product
     * @SuppressWarnings (UnusedFormalParameter)
     */
    public function afterInitialize(
        \Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper $subject,
        \Magento\Catalog\Model\Product $product
    ) {
        if ($product->getTypeId() === 'downloadable') {
            $this->digitalAssetsProcessor->moveDownloadableAssetsToBrandDir($product);
        }

        return $product;
    }
}
