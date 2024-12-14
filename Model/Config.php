<?php
namespace Zithara\Webhook\Model;

use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;

class Config extends AbstractModel
{
    protected $logger;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        LoggerInterface $logger,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->logger = $logger;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        try {
            $this->_init(\Zithara\Webhook\Model\ResourceModel\Config::class);
        } catch (\Exception $e) {
            $this->logger->error('Config Model: Error initializing model', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Validate model data before saving
     *
     * @return bool|string[] Returns true if valid, array of error messages otherwise
     */
    public function validateBeforeSave()
    {
        try {
            $errors = [];

            // Validate webhook URL
            //$webhookUrl = $this->getWebhookUrl();
            //if (empty($webhookUrl)) {
            //    $errors[] = __('Webhook URL is required.');
            //} elseif (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            //    $errors[] = __('Invalid webhook URL format.');
            //}

            // Validate client credentials
            if (empty($this->getClientId())) {
                $errors[] = __('Client ID is required.');
            }

            // Only validate client secret for new records or when it's being updated
            if (!$this->getId() || $this->getClientSecret()) {
                if (empty($this->getClientSecret())) {
                    $errors[] = __('Client Secret is required.');
                }
            }

            // Validate event types
            $eventTypes = $this->getEventTypes();
            if (empty($eventTypes)) {
                $errors[] = __('At least one event type must be selected.');
            }

            // Validate abandoned cart threshold
            $threshold = $this->getAbandonedCartThreshold();
            if ($threshold !== null && (!is_numeric($threshold) || $threshold < 1)) {
                $errors[] = __('Abandoned cart threshold must be a positive number.');
            }

            if (!empty($errors)) {
                $this->logger->warning('Config Model: Validation failed', [
                    'errors' => $errors
                ]);
                return $errors;
            }

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Config Model: Error validating data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [__('An error occurred while validating the configuration.')];
        }
    }

    /**
     * Before save processing
     *
     * @return $this
     */
    protected function _beforeSave()
    {
        try {
            // Validate data before saving
            $validation = $this->validateBeforeSave();
            if (is_array($validation)) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __("Validation failed: %1", implode(", ", $validation))
                );
            }

            // Convert event_types array to string if necessary
            $eventTypes = $this->getEventTypes();
            if (is_array($eventTypes)) {
                $this->setEventTypes(implode(',', array_filter($eventTypes)));
            }

            // Set timestamps
            if (!$this->getId()) {
                $this->setCreatedAt(date('Y-m-d H:i:s'));
            }
            $this->setUpdatedAt(date('Y-m-d H:i:s'));

            return parent::_beforeSave();

        } catch (\Exception $e) {
            $this->logger->error('Config Model: Error in before save processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * After load processing
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        try {
            // Convert event_types string to array if necessary
            $eventTypes = $this->getEventTypes();
            if (is_string($eventTypes) && !empty($eventTypes)) {
                $this->setEventTypes(explode(',', $eventTypes));
            }

            return parent::_afterLoad();

        } catch (\Exception $e) {
            $this->logger->error('Config Model: Error in after load processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this;
        }
    }
}