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

class Fruugo_Integration_Helper_Logger extends Mage_Core_Helper_Abstract
{
    const ALWAYS = 0;
    const ERROR = 1;
    const WARNING = 2;
    const INFO = 3;
    const DEBUG = 4;

    public static $LOG_LEVEL = self::WARNING;
    public static $CODE = array(
        self::ALWAYS => 'NOTICE',
        self::ERROR => 'ERROR',
        self::WARNING=> 'WARNING',
        self::INFO  => 'INFO',
        self::DEBUG => 'DEBUG',
    );

    public static function log($message, $level = self::INFO)
    {
        if ($level <= self::$LOG_LEVEL) {
            Mage::log("Fruugo - " . self::$CODE[$level] . ': ' . $message);
        }
    }

    public static function getFormattedReport($report)
    {
        $repStr = "\n\n---------------------------[ Fruugo Product Export Results ]---------------------------\n";
        $repStr .= "Status: " . $report['status'] . "\n";
        $repStr .= "Total products exported: " . $report['total_exported'] . "\n";
        $repStr .= "Total products processed: " . $report['total_processed'] . "\n";
        $repStr .= "Start time: " . $report['start_time_utc'] . "\n";
        $repStr .= "End time: " . $report['end_time_utc'] . "\n";
        $repStr .= "Total processing time: " . $report['processing_time_sec'] . " seconds\n";
        $repStr .= "Total paused time: " . $report['time_paused_sec'] . " seconds\n";
        $repStr .= "Max memory used: " . $report['max_ram_usage_mb'] . " MB\n";
        $repStr .= "Avg memory used: " . round($report['avg_ram_usage_mb'], 2) . " MB\n";
        $repStr .= "Avg resource load: " . round($report['avg_load'], 2) . "\n";
        $repStr .= "Error count: " . $report['error_count'] . "\n";
        $repStr .= "Resource threshold triggers: " . $report['load_above_threshold_count'] . "\n";
        $repStr .= "XML File size: " . round($report['xml_file_size_mb'], 2) . " MB\n";
        $repStr .= "----------------------------------------------------------------------------------------\n\n";

        return $repStr;
    }
}
