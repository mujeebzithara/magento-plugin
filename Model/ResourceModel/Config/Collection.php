<?php
namespace Zithara\Webhook\Model\ResourceModel\Config;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Psr\Log\LoggerInterface;

class Collection extends AbstractCollection
{
    protected $logger;

    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactoryInterface $entityFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\DB\Adapter\AdapterInterface $connection = null,
        \Magento\Framework\Model\ResourceModel\Db\AbstractDb $resource = null
    ) {
        $this->logger = $logger;
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $connection, $resource);
    }

    protected function _construct()
    {
        try {
            $this->_init(
                \Zithara\Webhook\Model\Config::class,
                \Zithara\Webhook\Model\ResourceModel\Config::class
            );
        } catch (\Exception $e) {
            $this->logger->error('Config Collection: Error initializing collection', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Add filter to get only active configurations
     *
     * @return $this
     */
    public function addActiveFilter()
    {
        try {
            $this->addFieldToFilter('is_active', 1);
            return $this;
        } catch (\Exception $e) {
            $this->logger->error('Config Collection: Error adding active filter', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this;
        }
    }

    /**
     * Add filter by event type
     *
     * @param string $eventType
     * @return $this
     */
    public function addEventTypeFilter($eventType)
    {
        try {
            if (!empty($eventType)) {
                $this->addFieldToFilter('event_types', ['like' => '%' . $eventType . '%']);
            }
            return $this;
        } catch (\Exception $e) {
            $this->logger->error('Config Collection: Error adding event type filter', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
                'trace' => $e->getTraceAsString()
            ]);
            return $this;
        }
    }

    /**
     * Load data with additional error handling
     *
     * @param bool $printQuery
     * @param bool $logQuery
     * @return $this
     */
    public function load($printQuery = false, $logQuery = false)
    {
        try {
            return parent::load($printQuery, $logQuery);
        } catch (\Exception $e) {
            $this->logger->error('Config Collection: Error loading collection', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this;
        }
    }
}