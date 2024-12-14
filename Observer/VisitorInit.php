<?php
namespace Zithara\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Zithara\Webhook\Helper\Data as WebhookHelper;
use Psr\Log\LoggerInterface;

class VisitorInit implements ObserverInterface
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
            // Defensive check for request object
            $request = $observer->getEvent()->getRequest();
            if (!$request || !is_object($request)) {
                $this->logger->error('VisitorInit: Invalid request object.');
                return;
            }

            // Get user agent with defensive check
            $userAgent = $this->httpHeader->getHttpUserAgent() ?: '';

            // Prepare visitor data with defensive checks
            $visitorData = [
                'timestamp' => time(),
                'user_agent' => $userAgent,
                'ip_address' => $this->remoteAddress->getRemoteAddress() ?? '',
                'request_uri' => $request->getRequestUri() ?? '',
                'http_referer' => $this->httpHeader->getHttpReferer() ?? '',
                'accept_language' => $this->httpHeader->getHttpAcceptLanguage() ?? ''
            ];

            // Additional defensive check for request URI
            if (empty($visitorData['request_uri'])) {
                $this->logger->warning('VisitorInit: Request URI is empty.');
            }

            $this->webhookHelper->sendWebhook('visitor_init', $visitorData);

        } catch (\Exception $e) {
            $this->logger->error('VisitorInit Observer Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}