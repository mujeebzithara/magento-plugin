<?php
namespace Zithara\Webhook\Controller\Adminhtml\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Psr\Log\LoggerInterface;

class Index extends Action
{
    protected $resultPageFactory;
    protected $logger;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->logger = $logger;
    }

    public function execute()
    {
        try {
            // Create result page with defensive check
            $resultPage = $this->resultPageFactory->create();
            if (!$resultPage) {
                throw new \Exception(__('Error creating result page.'));
            }

            // Set page title with defensive check
            $config = $resultPage->getConfig();
            if ($config) {
                $title = $config->getTitle();
                if ($title) {
                    $title->prepend(__('Webhook Configuration'));
                }
            }

            return $resultPage;

        } catch (\Exception $e) {
            $this->logger->error('Config Index: Error loading configuration page', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->messageManager->addErrorMessage(__('An error occurred while loading the configuration page.'));
            return $this->resultRedirectFactory->create()->setPath('adminhtml/dashboard/index');
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Zithara_Webhook::webhook');
    }
}