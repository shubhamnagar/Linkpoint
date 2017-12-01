<?php
namespace Raveinfosys\Linkpoint\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class InstallSchema implements InstallSchemaInterface
{
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;

        $installer->startSetup();

        $installer->getConnection()->addColumn($installer->getTable("sales_order_payment"), "transaction_tag", [
             'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
             'comment' => 'Transaction Tag'
        ]);

        $installer->endSetup();
    }
}
