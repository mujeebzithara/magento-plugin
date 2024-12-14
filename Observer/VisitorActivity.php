<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\App\RequestInterface;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class VisitorActivity implements ObserverInterface
{
    protected $webhookHelper;
    protected $httpHeader;
    protected $remoteAddress;
    protected $request;
    protected $logger;

    public function __construct(
        WebhookHelper $webhookHelper,
        Header $httpHeader,
        RemoteAddress $remoteAddress,
        RequestInterface $request,
        LoggerInterface $logger
    ) {
        $this->webhookHelper = $webhookHelper;
        $this->httpHeader = $httpHeader;
        $this->remoteAddress = $remoteAddress;
        $this->request = $request;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $visitor = $observer->getEvent()->getVisitor();
            
            // Defensive check for visitor object
            if (!$visitor || !is_object($visitor)) {
                $this->logger->error('VisitorActivity: Invalid visitor object.');
                return;
            }

            // Defensive check for visitor ID
            if (!$visitor->getId()) {
                $this->logger->error('VisitorActivity: Visitor ID is missing.');
                return;
            }

            // Get user agent with defensive check
            $userAgent = $this->httpHeader->getHttpUserAgent() ?: '';

            // Get current URL with defensive check
            $currentUrl = $this->request->getRequestUri() ?: '';

            // Prepare activity data with defensive checks
            $activityData = [
                'visitor_id' => $visitor->getId(),
                'session_id' => $visitor->getSessionId() ?? '',
                'last_visit_at' => $visitor->getLastVisitAt() ?? '',
                'current_url' => $currentUrl,
                'ip_address' => $this->remoteAddress->getRemoteAddress() ?? '',
                'user_agent' => $userAgent,
                'http_referer' => $this->httpHeader->getHttpReferer() ?? '',
                'page_type' => $this->getPageType($this->request->getFullActionName() ?? '')
            ];

            $this->webhookHelper->sendWebhook('visitor_activity', $activityData);

        } catch (\Exception $e) {
            $this->logger->error('VisitorActivity Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function getPageType($fullActionName)
    {
        if (empty($fullActionName)) {
            return 'other';
        }

        $actionMap = [
            'cms_index_index' => 'homepage',
            'catalog_product_view' => 'product',
            'catalog_category_view' => 'category',
            'checkout_cart_index' => 'cart',
            'checkout_index_index' => 'checkout',
            'customer_account' => 'account',
            'catalogsearch_result_index' => 'search'
        ];

        return isset($actionMap[$fullActionName]) ? $actionMap[$fullActionName] : 'other';
    }
}