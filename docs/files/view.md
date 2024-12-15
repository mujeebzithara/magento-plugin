# View Documentation

## layout/zithara_webhook_config_index.xml
`app/code/Zithara/Webhook/view/adminhtml/layout/zithara_webhook_config_index.xml`

### Purpose
Defines admin configuration page layout.

### Example
```xml
<page>
    <body>
        <referenceContainer name="content">
            <block class="Zithara\Webhook\Block\Adminhtml\Config\Edit\Form" 
                   name="webhook_form" />
        </referenceContainer>
    </body>
</page>
```