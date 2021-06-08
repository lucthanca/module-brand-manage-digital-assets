<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Helper;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Api\Data\CategoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Class GetBrandDirectory
 * Get digital brand folder path
 */
class GetBrandDirectory
{
    const DIGITAL_ASSETS_FOLDER_NAME = "DigitalAssets";
    const DIGITAL_ASSETS_CATEGORY_NAME_PATTERN = "/digital\sassets/i";
    const BRAND_CATEGORY_LV = '3';

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $categoryRepository;

    /**
     * GetBrandDirectory constructor.
     *
     * @param LoggerInterface $logger
     * @param CategoryRepositoryInterface $categoryRepository
     */
    public function __construct(
        LoggerInterface $logger,
        CategoryRepositoryInterface $categoryRepository
    ) {
        $this->logger = $logger;
        $this->categoryRepository = $categoryRepository;
    }

    /**
     * Get digital assets folder of product in digital assets category
     *
     * @param ProductInterface|Product $product
     * @param bool $checkOrigin
     * @return string
     */
    public function execute(ProductInterface $product, bool $checkOrigin = false)
    {
        try {
            $categoryIds = $product->getCategoryIds();

            if ($checkOrigin) {
                $categoryIds = $product->getOrigData('category_ids');
            }

            if (!$categoryIds) {
                return false;
            }

            $digitalBrand = null;
            foreach ($categoryIds as $categoryId) {
                $category = $this->categoryRepository->get($categoryId);
                if (preg_match(static::DIGITAL_ASSETS_CATEGORY_NAME_PATTERN, $category->getName() . "")) {
                    $digitalBrand = $category;
                    break;
                }
            }

            if ($digitalBrand) {
                return $this->getBrandPathWithCategory($digitalBrand);
            }
        } catch (\Exception $e) {
            $this->logger->critical(
                "BSS - ERROR: When get brand directory, because: ". $e
            );
        }

        return false;
    }

    /**
     * Get brand path with category
     *
     * @param CategoryInterface|\Magento\Catalog\Model\Category $category
     * @return false|string
     */
    public function getBrandPathWithCategory($category)
    {
        $brandName = $this->getBrandName($category);
        if ($brandName) {
            return DIRECTORY_SEPARATOR .
                $this->escapeBrandName($brandName) .
                DIRECTORY_SEPARATOR .
                static::DIGITAL_ASSETS_FOLDER_NAME;
        }
        return false;
    }

    /**
     * Reformat brand name with non-space
     *
     * @param string $brandName
     * @return string
     */
    public function escapeBrandName(string $brandName): string
    {
        return str_replace(" ", "", $brandName);
    }

    /**
     * Get brand name of category
     *
     * @param CategoryInterface|\Magento\Catalog\Model\Category $category
     *
     * @return string|false
     */
    public function getBrandName(CategoryInterface $category)
    {
        if (!$category) {
            return false;
        }

        if ($category->getLevel() === static::BRAND_CATEGORY_LV) {
            return $category->getName();
        }

        if (!$category->getParentId()) {
            return false;
        }

        return $this->getBrandName($category->getParentCategory());
    }
}
