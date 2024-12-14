<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Customer\Api\AddressRepositoryInterface;
use Psr\Log\LoggerInterface;

class CustomerEdit implements ObserverInterface
{
    protected $publisher;
    protected $jsonHelper;
    protected $addressRepository;
    protected $logger;

    public function __construct(
        PublisherInterface $publisher,
        JsonHelper $jsonHelper,
        AddressRepositoryInterface $addressRepository,
        LoggerInterface $logger
    ) {
        $this->publisher = $publisher;
        $this->jsonHelper = $jsonHelper;
        $this->addressRepository = $addressRepository;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $customer = $observer->getEvent()->getCustomer();
            
            // Defensive check for customer object
            if (!$customer || !is_object($customer)) {
                $this->logger->error('CustomerEdit: Invalid customer object.');
                return;
            }

            // Get primary mobile number from default billing address
            $phoneNumber = null;
            $whatsappNumber = null;
            try {
                if ($customer->getDefaultBilling()) {
                    $billingAddress = $this->addressRepository->getById($customer->getDefaultBilling());
                    if ($billingAddress && method_exists($billingAddress, 'getTelephone')) {
                        $phoneNumber = $billingAddress->getTelephone();
                        // Using the same number for WhatsApp
                        $whatsappNumber = $phoneNumber;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning('CustomerEdit: Error retrieving customer address: ' . $e->getMessage());
            }

            // Phone number is mandatory for updates
            if (!$phoneNumber) {
                $this->logger->error('CustomerEdit: Phone number is mandatory for customer updates.', [
                    'customer_id' => $customer->getId()
                ]);
                return;
            }

            // Get custom attributes
            $customAttributes = [];
            if (method_exists($customer, 'getCustomAttributes') && $customer->getCustomAttributes()) {
                foreach ($customer->getCustomAttributes() as $attribute) {
                    if ($attribute && is_object($attribute)) {
                        $customAttributes[$attribute->getAttributeCode()] = $attribute->getValue();
                    }
                }
            }

            // Prepare data for Zithara API
            $zitharaData = [
                'platform_customer_id' => $customer->getId(),
                'phone_number' => $phoneNumber,
                'first_name' => $customer->getFirstname() ?? '',
                'last_name' => $customer->getLastname() ?? '',
                'name' => trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? '')),
                'whatsapp_phone_number' => $whatsappNumber ? '91' . ltrim($whatsappNumber, '+91') : '',
                'email' => $customer->getEmail() ?? '',
                'custom_attributes' => $customAttributes,
                'is_update' => true // Flag to indicate this is an update
            ];

            // Log the data being sent
            $this->logger->info('CustomerEdit: Preparing to send data to Zithara', [
                'customer_id' => $customer->getId(),
                'email' => $customer->getEmail()
            ]);

            // Publish to queue
            try {
                $this->publisher->publish(
                    'zithara.customer.events',
                    $this->jsonHelper->jsonEncode($zitharaData)
                );

                $this->logger->info('CustomerEdit: Published customer update to queue', [
                    'customer_id' => $customer->getId()
                ]);
            } catch (\Exception $e) {
                $this->logger->error('CustomerEdit: Error publishing to queue', [
                    'error' => $e->getMessage(),
                    'customer_id' => $customer->getId()
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('CustomerEdit Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
