<?php
namespace Zithara\Webhook\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Psr\Log\LoggerInterface;

class Config extends AbstractDb
{
    protected $logger;

    public function __construct(
        Context $context,
        LoggerInterface $logger,
        $connectionName = null
    ) {
        $this->logger = $logger;
        parent::__construct($context, $connectionName);
    }

    protected function _construct()
    {
        try {
            $this->_init('zithara_webhook_config', 'config_id');
        } catch (\Exception $e) {
            $this->logger->error('Config Resource Model: Error initializing model', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Perform actions before saving object
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function _beforeSave(\Magento\Framework\Model\AbstractModel $object)
    {
        try {
            // Validate object
            if (!$object || !($object instanceof \Zithara\Webhook\Model\Config)) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Invalid model object provided for saving')
                );
            }

            // Ensure required fields are present
            $requiredFields = ['client_id', 'event_types'];
            foreach ($requiredFields as $field) {
                if (!$object->getData($field)) {
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('Required field "%1" is missing', $field)
                    );
                }
            }

            return parent::_beforeSave($object);

        } catch (\Exception $e) {
            $this->logger->error('Config Resource Model: Error in before save processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Perform actions after saving object
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function _afterSave(\Magento\Framework\Model\AbstractModel $object)
    {
        try {
            return parent::_afterSave($object);
        } catch (\Exception $e) {
            $this->logger->error('Config Resource Model: Error in after save processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Perform actions before deleting object
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function _beforeDelete(\Magento\Framework\Model\AbstractModel $object)
    {
        try {
            // Validate object
            if (!$object || !($object instanceof \Zithara\Webhook\Model\Config)) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Invalid model object provided for deletion')
                );
            }

            return parent::_beforeDelete($object);

        } catch (\Exception $e) {
            $this->logger->error('Config Resource Model: Error in before delete processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Perform actions after loading object
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function _afterLoad(\Magento\Framework\Model\AbstractModel $object)
    {
        try {
            return parent::_afterLoad($object);
        } catch (\Exception $e) {
            $this->logger->error('Config Resource Model: Error in after load processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this;
        }
    }
}