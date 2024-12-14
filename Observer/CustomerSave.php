<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Psr\Log\LoggerInterface;

class CustomerSave implements ObserverInterface
{
    protected $webhookHelper;
    protected $dateTime;
    protected $addressRepository;
    protected $publisher;
    protected $jsonHelper;
    protected $customerRepository;
    protected $logger;

    public function __construct(
        WebhookHelper $webhookHelper,
        DateTime $dateTime,
        AddressRepositoryInterface $addressRepository,
        PublisherInterface $publisher,
        JsonHelper $jsonHelper,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->webhookHelper = $webhookHelper;
        $this->dateTime = $dateTime;
        $this->addressRepository = $addressRepository;
        $this->publisher = $publisher;
        $this->jsonHelper = $jsonHelper;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $customer = $observer->getEvent()->getCustomer();

            if (!$customer) {
                $customer = $observer->getEvent()->getDataObject();
            }

            // Defensive check for customer object
            if (!$customer || !is_object($customer)) {
                $this->logger->error('CustomerSave: Invalid customer object.');
                return;
            }

            // Log the event type and customer state
            $this->logger->info('CustomerSave: Processing customer', [
                'customer_id' => $customer->getId(),
                'event_name' => $observer->getEvent()->getName()
            ]);

            // Check if this is a new customer registration
            $isNewCustomer = $observer->getEvent()->getName() === 'customer_register_success' ||
                           !$customer->getId() ||
                           ($customer->getOrigData() && !$customer->getOrigData('entity_id'));

//            if (!$isNewCustomer) {
            if (!$customer->getId()) {
                $this->logger->info('CustomerSave: Not a new customer, skipping Zithara API call');
                return;
            }

            // Get primary mobile number with defensive checks
            $phoneNumber = null;
            $whatsappNumber = null;
            try {
                if ($customer->getDefaultBilling()) {
                    $billingAddress = $this->addressRepository->getById($customer->getDefaultBilling());
                    if ($billingAddress && method_exists($billingAddress, 'getTelephone')) {
                        $phoneNumber = $billingAddress->getTelephone();
                        // Assuming whatsapp number is same as phone for now
                        $whatsappNumber = $phoneNumber;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning('CustomerSave: Error retrieving customer address: ' . $e->getMessage());
            }

            // Get custom attributes with defensive checks
            $customAttributes = [];
            if (method_exists($customer, 'getCustomAttributes') && $customer->getCustomAttributes()) {
                foreach ($customer->getCustomAttributes() as $attribute) {
                    if ($attribute && is_object($attribute)) {
                        $customAttributes[$attribute->getAttributeCode()] = $attribute->getValue();
                    }
                }
            }

            // Get mobilenumber field from customAttributes when signup page
            if(!empty($customAttributes) && isset($customAttributes['mobilenumber']) && !empty($customAttributes['mobilenumber'])){
                $phoneNumber = str_replace(' ', '', $customAttributes['mobilenumber']);
            }

            // Get mobilenumber field from customer Data when checkout page
            if ($customer && method_exists($customer, 'getData')) {
                $customerData = $customer->getData();
                if(!empty($customerData) && isset($customerData['mobilenumber']) && !empty($customerData['mobilenumber'])){
                    $phoneNumber = str_replace(' ', '', $customerData['mobilenumber']);
                }
            }

	        $randomNumber = mt_rand(10000000, 99999999); // 8-digit random number
            // Prepare data for Zithara API
            $zitharaData = [
                'platform_customer_id' => $customer->getId(),
                'phone_number' => $phoneNumber ?? '+9199' . $randomNumber,
                'first_name' => $customer->getFirstname() ?? '',
                'last_name' => $customer->getLastname() ?? '',
                'name' => trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? '')),
                'whatsapp_phone_number' => $whatsappNumber ? '91' . ltrim($whatsappNumber, '+91') : '',
                'email' => $customer->getEmail() ?? '',
                'custom_attributes' => $customAttributes
            ];

            // Log the data being sent
            $this->logger->info('CustomerSave: Preparing to send data to Zithara', [
                'customer_id' => $customer->getId(),
                'email' => $customer->getEmail()
            ]);

            // Publish message to queue
            try {
                $this->publisher->publish(
                    'zithara.customer.events',
                    $this->jsonHelper->jsonEncode($zitharaData)
                );

                $this->logger->info('CustomerSave: Published customer data to queue', [
                    'customer_id' => $customer->getId()
                ]);
            } catch (\Exception $e) {
                $this->logger->error('CustomerSave: Error publishing to queue', [
                    'error' => $e->getMessage(),
                    'customer_id' => $customer->getId()
                ]);
            }

            // Continue with regular webhook
            $eventData = [
                'customer_id' => $customer->getId(),
                'is_new_customer' => $isNewCustomer,
                'store_id' => $customer->getStoreId() ?? '',
                'website_id' => $customer->getWebsiteId() ?? '',
                'current_data' => [
                    'email' => $customer->getEmail() ?? '',
                    'firstname' => $customer->getFirstname() ?? '',
                    'lastname' => $customer->getLastname() ?? '',
                    'group_id' => $customer->getGroupId() ?? '',
                    'created_at' => $customer->getCreatedAt() ?? '',
                    'updated_at' => $this->dateTime->gmtDate('Y-m-d H:i:s'),
                    'custom_attributes' => $customAttributes
                ]
            ];

            $this->webhookHelper->sendWebhook('customer_create', $eventData);

        } catch (\Exception $e) {
            $this->logger->error('CustomerSave Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
