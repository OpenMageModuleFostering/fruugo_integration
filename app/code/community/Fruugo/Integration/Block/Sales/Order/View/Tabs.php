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

class Fruugo_Integration_Block_Sales_Order_View_Tabs extends Mage_Adminhtml_Block_Template implements Mage_Adminhtml_Block_Widget_Tab_Interface
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('integration/sales/order/view/tab/packinglist.phtml');
    }

    public function getTabLabel()
    {
        return $this->__('Fruugo Order Information');
    }

    public function getTabTitle()
    {
        return $this->__('Fruugo Order Information');
    }

    public function canShowTab()
    {
        $order = $this->getOrder();
        $fruugoId = $order->getFruugoOrderId();

        if (!empty($fruugoId) && $fruugoId !== null) {
            return true;
        }

        return false;
    }

    public function isHidden()
    {
        $order = $this->getOrder();
        $fruugoId = $order->getFruugoOrderId();

        if (!empty($fruugoId) && $fruugoId !== null) {
            return false;
        }

        return true;
    }

    public function getOrder()
    {
        return Mage::registry('current_order');
    }

    public function isOrderShipped()
    {
        $shipmentModel = Mage::getModel('integration/shipment');
        $shipmentCollection = $shipmentModel->getCollection();
        if (count($shipmentCollection) > 0) {
            return true;
        }

        return false;
    }

    public function getFruugoOrderId()
    {
        return $this->getOrder()->getFruugoOrderId();
    }

    public function getFruugoShipmentIds()
    {
        $shipmentIds = array();
        $shipmentModel = Mage::getModel('integration/shipment');
        $shipmentCollection = $shipmentModel->getCollection();
        foreach ($shipmentCollection as $shipment) {
            array_push($shipmentIds, $shipment->getShipmentId());
        }
        return $shipmentIds;
    }
}
