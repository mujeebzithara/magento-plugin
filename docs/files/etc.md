# Configuration Documentation

## module.xml
`app/code/Zithara/Webhook/etc/module.xml`

### Purpose
Defines module and dependencies.

### Example
```xml
<config>
    <module name="Zithara_Webhook" setup_version="1.0.0">
        <sequence>
            <module name="Magento_Sales"/>
        </sequence>
    </module>
</config>
```

## events.xml
`app/code/Zithara/Webhook/etc/events.xml`

### Purpose
Defines event observers.

### Example
```xml
<config>
    <event name="checkout_cart_save_after">
        <observer name="zithara_webhook_cart_save" 
                 instance="Zithara\Webhook\Observer\CartSave" />
    </event>
</config>
```

## queue_consumer.xml, queue_publisher.xml
Define queue configuration for async processing.

## di.xml
Defines dependency injection configuration.