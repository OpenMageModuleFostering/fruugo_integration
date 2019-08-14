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

require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Logger.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Defines.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/FruugoCountriesSeeder.php';

try {
    Fruugo_Integration_Helper_Logger::log('Running Fruugo plugin data script.');
    // create a new store for Fruugo orders
    Mage::registry('isSecureArea');
    $website_id = Mage::app()->getWebsite()->getId();
    $rootCategoryId = Mage::getModel('core/store')->load(Mage_Core_Model_App::DISTRO_STORE_ID)->getRootCategoryId();

    $fruugoStoreExists = false;
    $stores = Mage::app()->getStores();

    foreach ($stores as $id => $store) {
        if ($store->getCode() == 'fruugo') {
            $fruugoStoreExists = true;
        }
    }

    if (!$fruugoStoreExists) {
        // add store group
        $storeGroup = Mage::getModel('core/store_group');
        $storeGroup->setWebsiteId($website_id)
            ->setName('Fruugo')
            ->setRootCategoryId($rootCategoryId)
            ->save();

        // add store
        $store = Mage::getModel('core/store');
        $store->setCode('fruugo')
            ->setWebsiteId($storeGroup->getWebsiteId())
            ->setGroupId($storeGroup->getId())
            ->setName('Fruugo Orders')
            ->setIsActive(1)
            ->save();
    }

    // pulling Fruugo countries xml
    Fruugo_Integration_Helper_FruugoCountriesSeeder::getFruugoCountries();
} catch (Exception $e) {
    Fruugo_Integration_Helper_Logger::log('Error running Fruugo plugin data script. Detail: ' . $e);
}
