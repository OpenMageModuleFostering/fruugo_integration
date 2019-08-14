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

require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Defines.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Logger.php';

class Fruugo_Integration_PackinglistController extends Mage_Core_Controller_Front_Action
{
    public function getPackingListAction()
    {
        $fruugoOrderId = $this->getRequest()->getParam('fruugoOrderId');
        $shipmentId = $this->getRequest()->getParam('shipmentId');

        Fruugo_Integration_Helper_Logger::log("Downloading fruugo order packing list ". $fruugoOrderId . ', ' . $shipmentId);

        $apiUrl = Fruugo_Integration_Helper_Defines::FRUUGO_ORDERS_ENDPOINT;

        $devMode = Mage::getStoreConfig('integration_options/orders_options/dev_mode');
        if ($devMode == '1') {
            $apiUrl = Mage::getStoreConfig('integration_options/orders_options/order_api_url');
        }

        $username = Mage::getStoreConfig('integration_options/orders_options/username');
        $password = Mage::getStoreConfig('integration_options/orders_options/password');

        $apiUrl .= "/packinglist?orderId=". $fruugoOrderId . "&shipmentId=".$shipmentId;

        try {
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpcode != 200) {
                throw new Exception("Failed to download fruugo order packing list from the Fruugo API at the URL $apiUrl. Error: " . $response);
            }

            $filename = 'fruugo_packinglist_order_'.$fruugoOrderId.'_shipment_'.$shipmentId.'_'.date("Y_m_d_H_i_s").'.pdf';

            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header('Content-Description: File Transfer');
            header("Content-type: application/pdf");
            header("Content-Disposition: attachment; filename={$filename}");
            header("Expires: 0");
            header("Pragma: public");

            $packinglistFile = fopen('php://output', 'w');
            fwrite($packinglistFile, $response);
            fclose($packinglistFile);

            exit;
        } catch (Exception $ex) {
            Mage::logException($ex);
        }
    }
}
