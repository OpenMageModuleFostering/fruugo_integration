<?php
/**
* NOTICE OF LICENSE
*
* Magento extension which extracts a product feed from Magento, imports the feed into Fruugo and uses the Fruugo Order API to export all Fruugo orders into Magento.
*
* Copyright (C) 2015  Fruugo.com Ltd
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
* See the GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License along with this program.
* If not, see <http://www.gnu.org/licenses/>.
*/

$installer = new Mage_Sales_Model_Resource_Setup('core_setup');
$installer->addAttribute('order', 'fruugo_order_id', array(
        'type'          => Varien_Db_Ddl_Table::TYPE_VARCHAR,
        'label'         => 'The Fruggo order ID for this order',
        'visible'       => true,
        'required'      => false,
        'user_defined'  => true,
        'searchable'    => true,
        'filterable'    => true,
        'comparable'    => true,
        'default'       => null,
        'visible_on_front' => true
));

$installer->addAttribute('order_item', 'fruugo_product_id', array(
        'type'          => Varien_Db_Ddl_Table::TYPE_VARCHAR,
        'label'         => 'The Fruggo product ID for this order item',
        'visible'       => true,
        'required'      => false,
        'user_defined'  => true,
        'searchable'    => true,
        'filterable'    => true,
        'comparable'    => true,
        'default'       => null,
        'visible_on_front' => true
));

$installer->addAttribute('order_item', 'fruugo_sku_id', array(
        'type'          => Varien_Db_Ddl_Table::TYPE_VARCHAR,
        'label'         => 'The Fruggo SKU for this order item',
        'visible'       => true,
        'required'      => false,
        'user_defined'  => true,
        'searchable'    => true,
        'filterable'    => true,
        'comparable'    => true,
        'default'       => null,
        'visible_on_front' => true
));

$installer->endSetup();

$installer = $this;

if (!$installer->getConnection()->isTableExists('fruugo_shipment')) {
    $installer->startSetup();
    $table = $installer->getConnection()
            ->newTable($installer->getTable('integration/shipment'))
            ->addColumn('shipment_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
                'identity'  => true,
                'unsigned'  => true,
                'nullable'  => false,
                'primary'   => true,
                ), 'Id')
            ->addColumn('fruugo_shipment_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
                'nullable'  => false,
                ), 'ShipmentId')
            ->addColumn('fruugo_order_id', Varien_Db_Ddl_Table::TYPE_VARCHAR, null, array(
                'nullable'  => false,
                ), 'FruugoOrderId')
            ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
                'nullable'  => true,
                ), 'CreatedAt')
            ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
                'nullable'  => true,
                ), 'UpdatedAt');

    $installer->getConnection()->createTable($table);
    $installer->endSetup();
}

if (!$installer->getConnection()->isTableExists('fruugo_product_countries')) {
    $installer->startSetup();
    $table = $installer->getConnection()
            ->newTable($installer->getTable('integration/countries'))
            ->addColumn('fpc_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
                'identity'  => true,
                'unsigned'  => true,
                'nullable'  => false,
                'primary'   => true,
                ), 'Id')
            ->addColumn('product_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
                'nullable'  => true,
                ), 'ProductId')
            ->addColumn('fruugo_countries', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
                'nullable'  => true,
                ), 'FruugoProductCountries')
            ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
                'nullable'  => true,
                ), 'CreatedAt')
            ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
                'nullable'  => true,
                ), 'UpdatedAt');

    $installer->getConnection()->createTable($table);
    $installer->endSetup();
}
