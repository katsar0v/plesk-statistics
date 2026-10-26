<?php
// Removes the request store and log positions kept in the extension's var directory.
pm_Context::init('live-traffic-monitor');

$dir = pm_Context::getVarDir() . 'state';
foreach (glob($dir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($dir);
exit(0);
