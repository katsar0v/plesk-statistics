<?php
/**
 * Adds "Live Traffic" to the administrator's navigation (Server Management) and to Tools & Settings.
 * Both places exist only in the administrator's panel.
 */
class Modules_LiveTrafficMonitor_CustomButtons extends pm_Hook_CustomButtons
{
    public function getButtons()
    {
        $button = [
            'title' => 'Live Traffic',
            'description' => 'HTTP requests to all websites on this server, in near real time',
            'icon' => pm_Context::getBaseUrl() . 'images/icon.png',
            'link' => pm_Context::getActionUrl('index', 'index'),
        ];
        return [
            $button + ['place' => self::PLACE_ADMIN_NAVIGATION, 'section' => self::SECTION_NAV_SERVER_MANAGEMENT],
            $button + ['place' => self::PLACE_ADMIN_TOOLS_AND_SETTINGS],
        ];
    }
}
