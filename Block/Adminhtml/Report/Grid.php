<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Block\Adminhtml\Report;

use Bss\DigitalAssetsManage\Helper\GetBrandDirectory;
use Magento\Framework\Data\Collection;

/**
 * Class Grid
 * Brand digital assets grid
 */
class Grid extends \Magento\Backend\Block\Widget\Grid\Extended
{
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    protected $categoryCollectionFactory;

    /**
     * @var GetBrandDirectory
     */
    protected $getBrandDirectory;

    /**
     * Grid constructor.
     *
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Backend\Helper\Data $backendHelper
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory
     * @param GetBrandDirectory $getBrandDirectory
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Backend\Helper\Data $backendHelper,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        GetBrandDirectory $getBrandDirectory,
        array $data = []
    ) {
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->getBrandDirectory = $getBrandDirectory;
        parent::__construct($context, $backendHelper, $data);
    }

    /**
     * Set brand colleciton to report
     *
     * @return Grid
     */
    protected function _prepareCollection()
    {
        $this->setCollection($this->getBrandDigitalAssetsStorageCollection());
        return parent::_prepareCollection();
    }

    /**
     * Avoid error in line 246 in extended.phtml template
     *
     * @param \Magento\Framework\DataObject $item
     * @return false
     */
    public function getMultipleRows($item)
    {
        return false;
    }

    /**
     * Get brand collection
     *
     * @return Collection
     */
    protected function getBrandDigitalAssetsStorageCollection()
    {
        $brands = $this->categoryCollectionFactory->create()
            ->addAttributeToSelect('*')->addFieldToFilter("level", ['eq' => 3]);

        return $brands;
    }

    /**
     * Prepare report grid cols
     *
     * @return Grid
     * @throws \Exception
     */
    protected function _prepareColumns()
    {
        $this->addColumn(
            'entity_id',
            [
                'header' => __("Brand ID"),
                'index' => 'entity_id',
                'header_css_class' => 'col-id',
                'column_css_class' => 'col-id'
            ]
        );
        $this->addColumn(
            'name',
            [
                'header' => __('Brand Name'),
                'index' => 'name'
            ]
        );

        $this->addColumn(
            'storage_amount',
            [
                'header' => __("Storage Amount"),
                'index' => 'storage_amount',
                'sortable' => false,
                'frame_callback' => [$this, "storageCalculate"]
            ]
        );

        return parent::_prepareColumns();
    }

    /**
     * Calculate the size of brand assets
     *
     * @param string $value
     * @param \Magento\Catalog\Model\Category $category
     * @param \Magento\Backend\Block\Widget\Grid\Column\Extended $column
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function storageCalculate($value, $category, $column)
    {
        $unitTbl = [
            0 => "B",
            1 => "KB",
            2 => "MB",
            3 => "GB"
        ];

        $unit = $unitTbl[0];
        try {
            if ($category instanceof \Magento\Catalog\Model\Category) {
                $size = $this->folderSize(
                    $this->getMediaDirectory()->getAbsolutePath(),
                    $this->getBrandDirectory->escapeBrandName($category->getName())
                );
                $i = 1;
                while ($size > 1024) {
                    $unit = $unitTbl[$i];
                    $size = $size / 1024;
                    $i++;
                }

                return round($size, 2) . " " . $unit;
            }
        } catch (\Exception $e) {
            $this->_logger->critical(
                "BSS - ERROR: When render storage amount. Detail: " . $e
            );
        }

        return 0 . $unitTbl[0];
    }

    /**
     * Get folder size of brand dir
     *
     * @param string $dir
     * @param string $brandName
     * @return int
     */
    protected function folderSize(string $dir, string $brandName): int
    {
        $size = 0;

        // @codingStandardsIgnoreStart
        foreach (glob(rtrim($dir, '/').'/*', GLOB_NOSORT) as $each) {
//            dump($each, is_file($each) && strpos(
//                    $each,
//                    $brandName . DIRECTORY_SEPARATOR . UniqueFileName::DIGITAL_ASSETS_FOLDER_NAME
//                ) !== false, ['strpos ' => [$each,
//                $brandName . DIRECTORY_SEPARATOR . UniqueFileName::DIGITAL_ASSETS_FOLDER_NAME]]);


            if (is_file($each) &&
                strpos($each, $this->getDigitalAssetsPath($brandName)) !== false &&
                $this->notInCache($each, $brandName)
            ) {
//                dump(['filesize' => $this->getMediaDirectory()->stat($each)]);
//                vadu_log(['sizeee_nme' => $each]);
                $size += (int) filesize($each);
            } else {
                $size += $this->folderSize($each, $brandName);
            }
        }

        return $size;
    }

    /**
     * @param string $brandName
     * @return string
     */
    protected function getDigitalAssetsPath(string $brandName): string
    {
        return $brandName . DIRECTORY_SEPARATOR . GetBrandDirectory::DIGITAL_ASSETS_FOLDER_NAME;
    }

    /**
     * Check if current path is not in cache folder
     *
     * @param string $path
     * @param string $brandName
     * @return bool
     */
    private function notInCache(string $path, string $brandName): bool
    {
        preg_match(
            "/cache\/.*$brandName\/" . GetBrandDirectory::DIGITAL_ASSETS_FOLDER_NAME . "/i",
            $path,
            $matchs
        );

        return empty($matchs);
    }

    /**
     * Disable filter
     *
     * @return false
     */
    public function getFilterVisibility()
    {
        return false;
    }
}
