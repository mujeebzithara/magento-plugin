<?php
namespace Zithara\Webhook\Controller\Adminhtml\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Zithara\Webhook\Model\ConfigFactory;
use Psr\Log\LoggerInterface;

class Save extends Action
{
    protected $configFactory;
    protected $logger;

    public function __construct(
        Context $context,
        ConfigFactory $configFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->configFactory = $configFactory;
        $this->logger = $logger;
    }

    public function execute()
    {
        try {
            $data = $this->getRequest()->getPostValue();
            
            // Defensive check for post data
            if (empty($data)) {
                $this->logger->error('Config Save: No post data received');
                $this->messageManager->addErrorMessage(__('No data to save.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/index');
            }

            try {
                $model = $this->configFactory->create();
                
                // Load existing config or create new with defensive checks
                if (isset($data['config_id'])) {
                    $model->load($data['config_id']);
                    if (!$model->getId()) {
                        $this->logger->error('Config Save: Invalid config_id', [
                            'config_id' => $data['config_id']
                        ]);
                        throw new \Exception(__('Invalid configuration ID.'));
                    }
                    // If client_secret is empty, keep the existing one
                    if (empty($data['client_secret'])) {
                        unset($data['client_secret']);
                    }
                } else {
                    // Get first item if exists
                    $existingConfig = $model->getCollection()->getFirstItem();
                    if ($existingConfig->getId()) {
                        $model = $existingConfig;
                        if (empty($data['client_secret'])) {
                            unset($data['client_secret']);
                        }
                    }
                }
                
                // Validate required fields
                $requiredFields = ['client_id', 'event_types'];
                foreach ($requiredFields as $field) {
                    if (empty($data[$field])) {
                        throw new \Exception(__('Required field "%1" is missing.', $field));
                    }
                }

                // Validate webhook URL format
                //if (!filter_var($data['webhook_url'], FILTER_VALIDATE_URL)) {
                //    throw new \Exception(__('Invalid webhook URL format.'));
                //}

                // Convert event_types array to comma-separated string with defensive check
                if (isset($data['event_types']) && is_array($data['event_types'])) {
                    $data['event_types'] = implode(',', array_filter($data['event_types']));
                }

                // Clear existing tokens when client credentials change
                if (isset($data['client_id']) || isset($data['client_secret'])) {
                    $data['access_token'] = null;
                    $data['refresh_token'] = null;
                    $data['token_expiry'] = null;
                }

                // Validate abandoned cart threshold if present
                if (isset($data['abandoned_cart_threshold'])) {
                    if (!is_numeric($data['abandoned_cart_threshold']) || $data['abandoned_cart_threshold'] < 1) {
                        throw new \Exception(__('Abandoned cart threshold must be a positive number.'));
                    }
                }
                
                $model->setData($data);
                $model->save();
                
                $this->messageManager->addSuccessMessage(__('Webhook configuration has been saved.'));
                $this->logger->info('Config Save: Configuration saved successfully', [
                    'config_id' => $model->getId()
                ]);

                return $this->resultRedirectFactory->create()->setPath('*/*/index');

            } catch (\Exception $e) {
                $this->logger->error('Config Save: Error saving configuration', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->messageManager->addErrorMessage($e->getMessage());
                return $this->resultRedirectFactory->create()->setPath('*/*/index');
            }

        } catch (\Exception $e) {
            $this->logger->error('Config Save: Unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->messageManager->addErrorMessage(__('An error occurred while saving the configuration.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Zithara_Webhook::webhook');
    }
}