<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Psr\Log\LoggerInterface;

class CustomerAddressSave implements ObserverInterface
{
    protected $publisher;
    protected $jsonHelper;
    protected $customerRepository;
    protected $logger;

    public function __construct(
        PublisherInterface $publisher,
        JsonHelper $jsonHelper,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->publisher = $publisher;
        $this->jsonHelper = $jsonHelper;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $customerAddress = $observer->getEvent()->getCustomerAddress();
            
            // Defensive check for customer address object
            if (!$customerAddress || !is_object($customerAddress)) {
                $this->logger->error('CustomerAddressSave: Invalid customer address object.');
                return;
            }

            $customer = $customerAddress->getCustomer();
            
            // Defensive check for customer object
            if (!$customer || !is_object($customer)) {
                $this->logger->error('CustomerAddressSave: Invalid customer object.');
                return;
            }

            // Get phone number from the address
            $phoneNumber = $customerAddress->getTelephone();
            if (empty($phoneNumber)) {
                $this->logger->error('CustomerAddressSave: Phone number is mandatory for customer updates.', [
                    'customer_id' => $customer->getId()
                ]);
                return;
            }

            // Prepare address data for custom attributes
            $addressData = [
                'street' => $customerAddress->getStreet(),
                'city' => $customerAddress->getCity(),
                'region' => $customerAddress->getRegion() ? $customerAddress->getRegion() : '',
                'postcode' => $customerAddress->getPostcode(),
                'country_id' => $customerAddress->getCountryId(),
                'is_default_billing' => ($customer->getDefaultBilling() == $customerAddress->getId()),
                'is_default_shipping' => ($customer->getDefaultShipping() == $customerAddress->getId())
            ];

            // Get existing custom attributes
            $customAttributes = [];
            if (method_exists($customer, 'getCustomAttributes') && $customer->getCustomAttributes()) {
                foreach ($customer->getCustomAttributes() as $attribute) {
                    if ($attribute && is_object($attribute)) {
                        $customAttributes[$attribute->getAttributeCode()] = $attribute->getValue();
                    }
                }
            }

            // Add address data to custom attributes
            $customAttributes['address_data'] = $addressData;

            // Prepare data for Zithara API
            $zitharaData = [
                'platform_customer_id' => $customer->getId(),
                'phone_number' => $phoneNumber,
                'first_name' => $customer->getFirstname(),
                'last_name' => $customer->getLastname(),
                'name' => trim($customer->getFirstname() . ' ' . $customer->getLastname()),
                'whatsapp_phone_number' => '91' . ltrim($phoneNumber, '+91'),
                'email' => $customer->getEmail(),
                'custom_attributes' => $customAttributes,
                'is_update' => true // Flag to indicate this is an update
            ];

            $this->logger->info('CustomerAddressSave: Preparing to send data to Zithara', [
                'customer_id' => $customer->getId(),
                'email' => $customer->getEmail()
            ]);

            // Publish to queue
            try {
                $this->publisher->publish(
                    'zithara.customer.events',
                    $this->jsonHelper->jsonEncode($zitharaData)
                );

                $this->logger->info('CustomerAddressSave: Published customer update to queue', [
                    'customer_id' => $customer->getId()
                ]);
            } catch (\Exception $e) {
                $this->logger->error('CustomerAddressSave: Error publishing to queue', [
                    'error' => $e->getMessage(),
                    'customer_id' => $customer->getId()
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('CustomerAddressSave Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
