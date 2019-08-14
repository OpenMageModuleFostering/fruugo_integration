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

class Fruugo_Integration_Model_Adminhtml_System_Config_Backend_OrderCron extends Mage_Core_Model_Config_Data
{
    const CRON_STRING_PATH = 'crontab/jobs/download_orders/schedule/cron_expr';

    protected function _afterSave()
    {
        $time = $this->getData('groups/orders_options/fields/fetch_frequency/value');

        $frequencyDaily = Mage_Adminhtml_Model_System_Config_Source_Cron_Frequency::CRON_DAILY;

        $cronExprString = '';

        if ($time === $frequencyDaily) {
            $cronExprString = '@daily';
        } else {
            $cronExprString = '0 */' . $time . ' * * *';
        }

        try {
            Mage::getModel('core/config_data')
                ->load(self::CRON_STRING_PATH, 'path')
                ->setValue($cronExprString)
                ->setPath(self::CRON_STRING_PATH)
                ->save();

            $message = "fetch_frequency cron job config saved. cron_expr: " . $cronExprString;

            Fruugo_Integration_Helper_Logger::log($message);
        } catch (Exception $e) {
            Fruugo_Integration_Helper_Logger::log("Unable to save the cron expression. Error: " . $e);
        }
    }
}
