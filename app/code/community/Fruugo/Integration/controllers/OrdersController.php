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

require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/OrdersFeedProcessor.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Logger.php';

class Fruugo_Integration_OrdersController extends Mage_Core_Controller_Front_Action
{
    public function indexAction()
    {
        Fruugo_Integration_Helper_Logger::log("Force downloading new orders.");
        $ordersFeedProcessor = new Fruugo_Integration_OrdersFeedProcessor();
        $from = $this->getRequest()->getParam('from');

        if (empty($from) || $from === null) {
            $ordersFeedProcessor->processOrders();
            return;
        } else {
            try {
                $date = new DateTime($from);
                $ordersFeedProcessor->processOrders($from);
            } catch (Exception $e) {
                Fruugo_Integration_Helper_Logger::log("Force downloading new orders cancelled. Invalid from time parameter: " . $from);
                echo "Force downloading new orders cancelled. Invalid from time parameter: " . $from;
            }
        }
    }
}
