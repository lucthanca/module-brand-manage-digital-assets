<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Plugin\Model\Product;

use Bss\DigitalAssetsManage\Helper\DownloadableHelper;
use Psr\Log\LoggerInterface;

/**
 * Class TypeTransitionManagerPlugin
 * Delete all link file if not downloadable product
 */
class TypeTransitionManagerPlugin
{
    /**
     * @var DownloadableHelper
     */
    protected $downloadableHelper;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * TypeTransitionManagerPlugin constructor.
     *
     * @param DownloadableHelper $downloadableHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        DownloadableHelper $downloadableHelper,
        LoggerInterface $logger
    ) {
        $this->downloadableHelper = $downloadableHelper;
        $this->logger = $logger;
    }

    /**
     * Change product type to downloadable if needed
     *
     * @param \Magento\Catalog\Model\Product\TypeTransitionManager $subject
     * @param callable $proceed
     * @param \Magento\Catalog\Model\Product $product
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundProcessProduct(
        \Magento\Catalog\Model\Product\TypeTransitionManager $subject,
        callable $proceed,
        \Magento\Catalog\Model\Product $product
    ) {
        $proceed($product);

        try {
            if ($product->getTypeId() === \Magento\Downloadable\Model\Product\Type::TYPE_DOWNLOADABLE) {
                return;
            }

            $extension = $product->getExtensionAttributes();
            $links = $extension->getDownloadableProductLinks();
            $samples = $extension->getDownloadableProductSamples();

            $this->downloadableHelper->deleteLink($links);
            $this->downloadableHelper->deleteLink($samples);
        } catch (\Exception $e) {
            $this->logger->critical(
                "BSS.ERROR: Delete all links. " . $e
            );
        }
    }
}
