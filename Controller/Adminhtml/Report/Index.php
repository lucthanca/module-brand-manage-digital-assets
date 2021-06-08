<?php
declare(strict_types=1);
namespace Bss\DigitalAssetsManage\Controller\Adminhtml\Report;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\ActionInterface;

/**
 * Class Index
 */
class Index implements ActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Bss_DigitalAssetsManage::digital_assets_report';

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var PageFactory
     */
    protected $pageFactory;

    /**
     * @var AuthorizationInterface
     */
    protected $authorization;

    /**
     * Index constructor.
     *
     * @param RequestInterface $request
     * @param PageFactory $pageFactory
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        RequestInterface $request,
        PageFactory $pageFactory,
        AuthorizationInterface $authorization
    ) {
        $this->request = $request;
        $this->pageFactory = $pageFactory;
        $this->authorization = $authorization;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $resultPage = $this->pageFactory->create();
        $resultPage->setActiveMenu("Bss_DigitalAssetsManage::storage_report");
        $resultPage
            ->getConfig()
            ->getTitle()
            ->prepend(__("Digital Assets Brand Storage Report"));

        return $resultPage;
    }

    /**
     * Get request object
     *
     * @return RequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Is allowed to access
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->authorization->isAllowed(static::ADMIN_RESOURCE);
    }
}
