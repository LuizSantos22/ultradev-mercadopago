<?php
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$conn  = $installer->getConnection();
$table = $installer->getTable('ultradev_mercadopago/threeds_session');

if (!$conn->isTableExists($table)) {
    $t = $conn->newTable($table)
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true,
            'nullable' => false,
            'primary'  => true,
            'unsigned' => true,
        ], 'Entity ID')
        ->addColumn('quote_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'nullable' => false,
            'unsigned' => true,
        ], 'Quote ID')
        ->addColumn('order_increment_id', Varien_Db_Ddl_Table::TYPE_TEXT, 32, [
            'nullable' => false,
        ], 'Order Increment ID')
        ->addColumn('payment_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, [
            'nullable' => false,
            'unsigned' => true,
            'default'  => 0,
        ], 'MP Payment ID')
        ->addColumn('external_resource_url', Varien_Db_Ddl_Table::TYPE_TEXT, 512, [
            'nullable' => true,
        ], '3DS Challenge URL')
        ->addColumn('creq', Varien_Db_Ddl_Table::TYPE_TEXT, null, [
            'nullable' => true,
        ], '3DS creq payload')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, [
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
        ], 'Created At')
        ->addIndex('IDX_ULTRADEV_MP_QUOTE',   ['quote_id'])
        ->addIndex('IDX_ULTRADEV_MP_PAYMENT', ['payment_id'])
        ->setComment('UltraDev MercadoPago 3DS Session');

    $conn->createTable($t);
}

$installer->endSetup();
