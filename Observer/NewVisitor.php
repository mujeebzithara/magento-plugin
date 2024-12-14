<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class NewVisitor implements ObserverInterface
{
    protected $webhookHelper;
    protected $httpHeader;
    protected $remoteAddress;
    protected $logger;

    public function __construct(
        WebhookHelper $webhookHelper,
        Header $httpHeader,
        RemoteAddress $remoteAddress,
        LoggerInterface $logger
    ) {
        $this->webhookHelper = $webhookHelper;
        $this->httpHeader = $httpHeader;
        $this->remoteAddress = $remoteAddress;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $visitor = $observer->getEvent()->getVisitor();
            
            // Defensive check for visitor object
            if (!$visitor || !is_object($visitor)) {
                $this->logger->error('NewVisitor: Invalid visitor object.');
                return;
            }

            // Defensive check for visitor ID
            if (!$visitor->getId()) {
                $this->logger->error('NewVisitor: Visitor ID is missing.');
                return;
            }

            // Get user agent with defensive check
            $userAgent = $this->httpHeader->getHttpUserAgent() ?: '';
            
            // Prepare visitor data with defensive checks
            $visitorData = [
                'visitor_id' => $visitor->getId(),
                'session_id' => $visitor->getSessionId() ?? '',
                'first_visit_at' => $visitor->getFirstVisitAt() ?? '',
                'last_visit_at' => $visitor->getLastVisitAt() ?? '',
                'user_agent' => $userAgent,
                'ip_address' => $this->remoteAddress->getRemoteAddress() ?? '',
                'http_referer' => $this->httpHeader->getHttpReferer() ?? '',
                'accept_language' => $this->httpHeader->getHttpAcceptLanguage() ?? '',
                'device_type' => $this->getDeviceType($userAgent)
            ];

            $this->webhookHelper->sendWebhook('new_visitor', $visitorData);

        } catch (\Exception $e) {
            $this->logger->error('NewVisitor Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function getDeviceType($userAgent)
    {
        if (empty($userAgent)) {
            return 'unknown';
        }

        $userAgent = strtolower($userAgent);

        if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', $userAgent)) {
            return 'tablet';
        }
        
        if (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile)/i', $userAgent)) {
            return 'mobile';
        }
        
        return 'desktop';
    }
}