<?php
declare(strict_types=1);

namespace Bss\DigitalAssetsManage\Helper;

use Magento\Downloadable\Model\LinkFactory;
use Magento\Downloadable\Model\Link;
use Magento\Downloadable\Model\SampleFactory;
use Magento\Downloadable\Model\Sample;

/**
 * Class DownloadableHelper
 */
class DownloadableHelper
{

    /**
     * @var LinkFactory
     */
    protected $linkFactory;

    /**
     * @var SampleFactory
     */
    protected $sampleFactory;

    /**
     * @var Link
     */
    protected $linkConfig;

    /**
     * @var Sample
     */
    protected $sampleConfig;

    /**
     * DownloadableHelper constructor.
     *
     * @param LinkFactory $linkFactory
     * @param SampleFactory $sampleFactory
     */
    public function __construct(
        LinkFactory $linkFactory,
        SampleFactory $sampleFactory
    ) {
        $this->linkFactory = $linkFactory;
        $this->sampleFactory = $sampleFactory;
    }

    /**
     * Get link model object
     *
     * @return Link
     */
    public function getLink(): Link
    {
        if (!$this->linkConfig) {
            $this->linkConfig = $this->linkFactory->create();
        }

        return $this->linkConfig;
    }

    /**
     * Get sample model object
     *
     * @return Sample
     */
    public function getSample(): Sample
    {
        if (!$this->sampleConfig) {
            $this->sampleConfig = $this->sampleFactory->create();
        }

        return $this->sampleConfig;
    }
}
