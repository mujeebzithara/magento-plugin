<?php
namespace Zithara\Webhook\Block\Adminhtml\Config\Edit;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;
use Zithara\Webhook\Model\ConfigFactory;
use Psr\Log\LoggerInterface;

class Form extends \Magento\Backend\Block\Widget\Form\Generic
{
    protected $configFactory;
    protected $logger;

    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        ConfigFactory $configFactory,
        LoggerInterface $logger,
        array $data = []
    ) {
        $this->configFactory = $configFactory;
        $this->logger = $logger;
        parent::__construct($context, $registry, $formFactory, $data);
    }

    protected function _prepareForm()
    {
        try {
            // Load existing configuration with defensive check
            $model = $this->configFactory->create()->getCollection()->getFirstItem();
            if (!$model) {
                $this->logger->error('Config Form: Unable to load configuration model');
                return parent::_prepareForm();
            }

            // Create form with defensive checks
            try {
                $form = $this->_formFactory->create([
                    'data' => [
                        'id' => 'edit_form',
                        'action' => $this->getUrl('*/*/save'),
                        'method' => 'post'
                    ]
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Config Form: Error creating form', [
                    'error' => $e->getMessage()
                ]);
                return parent::_prepareForm();
            }

            // Add fieldset with defensive check
            try {
                $fieldset = $form->addFieldset(
                    'base_fieldset',
                    ['legend' => __('Webhook Configuration')]
                );
            } catch (\Exception $e) {
                $this->logger->error('Config Form: Error adding fieldset', [
                    'error' => $e->getMessage()
                ]);
                return parent::_prepareForm();
            }

            // Add config_id field if model exists
            if ($model->getId()) {
                $fieldset->addField(
                    'config_id',
                    'hidden',
                    ['name' => 'config_id']
                );
            }

            // Add form fields with defensive checks
            try {
                $this->addFormFields($fieldset);
            } catch (\Exception $e) {
                $this->logger->error('Config Form: Error adding form fields', [
                    'error' => $e->getMessage()
                ]);
                return parent::_prepareForm();
            }

            // Set form values if model exists
            if ($model->getId()) {
                try {
                    $data = $model->getData();
                    if (isset($data['event_types']) && is_string($data['event_types'])) {
                        $data['event_types'] = explode(',', $data['event_types']);
                    }
                    $form->setValues($data);
                } catch (\Exception $e) {
                    $this->logger->error('Config Form: Error setting form values', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Add save button
            $form->addField(
                'submit',
                'submit',
                [
                    'name' => 'submit',
                    'value' => __('Save Configuration'),
                    'class' => 'action-save action-primary',
                    'data_attribute' => [
                        'mage-init' => ['button' => ['event' => 'save']],
                        'form-role' => 'save',
                    ],
                ]
            );

            $form->setUseContainer(true);
            $this->setForm($form);

            return parent::_prepareForm();

        } catch (\Exception $e) {
            $this->logger->error('Config Form: Unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return parent::_prepareForm();
        }
    }

    protected function addFormFields($fieldset)
    {
        // Add client ID field
        $fieldset->addField(
            'client_id',
            'text',
            [
                'name' => 'client_id',
                'label' => __('Client ID'),
                'title' => __('Client ID'),
                'required' => true,
                'note' => __('Client ID for authentication')
            ]
        );

        // Add client secret field - now showing existing value
        $fieldset->addField(
            'client_secret',
            'text',
            [
                'name' => 'client_secret',
                'label' => __('Client Secret'),
                'title' => __('Client Secret'),
                'required' => true,
                'note' => __('Client Secret for authentication')
            ]
        );

        // Add event types field
        $fieldset->addField(
            'event_types',
            'multiselect',
            [
                'name' => 'event_types[]',
                'label' => __('Event Types'),
                'title' => __('Event Types'),
                'required' => true,
                'values' => $this->getEventTypeOptions()
            ]
        );

        // Add abandoned cart threshold field (in minutes) - now showing existing value
        $fieldset->addField(
            'abandoned_cart_threshold',
            'text',
            [
                'name' => 'abandoned_cart_threshold',
                'label' => __('Abandoned Cart Threshold (minutes)'),
                'title' => __('Abandoned Cart Threshold'),
                'required' => true,
                'class' => 'validate-number',
                'note' => __('Number of minutes of inactivity before a cart is considered abandoned')
            ]
        );

        // Add status field
        $fieldset->addField(
            'is_active',
            'select',
            [
                'name' => 'is_active',
                'label' => __('Status'),
                'title' => __('Status'),
                'required' => true,
                'values' => [
                    ['value' => 1, 'label' => __('Active')],
                    ['value' => 0, 'label' => __('Inactive')]
                ]
            ]
        );
    }

    protected function getEventTypeOptions()
    {
        return [
            // Order Events
            ['value' => 'create_order', 'label' => __('Create Order')],
            ['value' => 'update_order', 'label' => __('Update Order')],
            ['value' => 'cancel_order', 'label' => __('Cancel Order')],
            ['value' => 'order_refund', 'label' => __('Order Refund')],
            ['value' => 'order_payment', 'label' => __('Order Payment')],
            
            // Cart Events
            ['value' => 'cart_add_product', 'label' => __('Cart Add Product')],
            ['value' => 'cart_update', 'label' => __('Cart Update')],
            ['value' => 'cart_delete', 'label' => __('Cart Delete')],
            ['value' => 'cart_save', 'label' => __('Cart Save')],
            ['value' => 'cart_remove_item', 'label' => __('Cart Remove Item')],
            
            // Abandoned Cart Events
            ['value' => 'abandoned_cart', 'label' => __('Abandoned Cart')],
            ['value' => 'quote_update', 'label' => __('Quote Update')],
            
            // Customer Events
            ['value' => 'customer_create', 'label' => __('Customer Create')],
            ['value' => 'customer_login', 'label' => __('Customer Login')],
            ['value' => 'customer_logout', 'label' => __('Customer Logout')],
            ['value' => 'customer_save', 'label' => __('Customer Save')],
            ['value' => 'customer_delete', 'label' => __('Customer Delete')],
            ['value' => 'customer_address_update', 'label' => __('Customer Address Update')],
            
            // Transaction Events
            ['value' => 'payment_capture', 'label' => __('Payment Capture')],
            ['value' => 'payment_refund', 'label' => __('Payment Refund')],
            ['value' => 'payment_void', 'label' => __('Payment Void')],
            ['value' => 'payment_fail', 'label' => __('Payment Failure')]
        ];
    }
}