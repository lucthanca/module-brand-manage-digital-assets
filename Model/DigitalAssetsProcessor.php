<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Exception;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\FileSystemException;

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
     * @var DigitalImageProcessor
     */
    protected $digitalImageProcessor;

    /**
     * @var DownloadableAssetsProcessor
     */
    protected $downloadableAssetsProcessor;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * DigitalAssetsProcessor constructor.
     *
     * @param \Psr\Log\LoggerInterface $logger
     * @param DigitalImageProcessor $digitalImageProcessor
     * @param DownloadableAssetsProcessor $downloadableAssetsProcessor
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        DigitalImageProcessor $digitalImageProcessor,
        DownloadableAssetsProcessor $downloadableAssetsProcessor,
        ProductRepositoryInterface $productRepository
    ) {
        $this->logger = $logger;
        $this->digitalImageProcessor = $digitalImageProcessor;
        $this->downloadableAssetsProcessor = $downloadableAssetsProcessor;
        $this->productRepository = $productRepository;
    }

    /**
     * @param int|ProductInterface $product
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function process(
        $product,
        string $brandPath = null,
        string $action = null
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

        $this->processImageAssets($product, $brandPath, $action);
        $this->processDownloadableAssets($product, $brandPath, $action);
    }

    /**
     * Process move/remove product images to brand directory
     *
     * @param ProductInterface|int $product
     * @param string|null $brandPath
     * @param null $action
     * @throws FileSystemException
     */
    public function processImageAssets(ProductInterface $product, string $brandPath = null, $action = null)
    {
        if ($action === null || $action === 'remove') {
            $this->digitalImageProcessor->rollbackToDispersionFolder($product, $brandPath);
            if ($action === "remove") {
                return;
            }
        }

        $this->digitalImageProcessor->moveToBrandDirectory($product, $brandPath);
    }

    /**
     * Process move/remove downloadable assets to brand directory
     *
     * @param ProductInterface|int $product
     */
    public function processDownloadableAssets($product, string $brandPath = null, string $action = null)
    {
        if ($action === null || $action === "remove") {
            $this->downloadableAssetsProcessor->processDownloadableAssets($product, $brandPath, true);
            if ($action === "remove") {
                return;
            }
        }

        $this->downloadableAssetsProcessor->processDownloadableAssets($product);
    }
}
