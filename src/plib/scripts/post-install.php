<?php
// Runs after installing or upgrading. A non-zero exit code aborts the installation and shows this output.
pm_Context::init('live-traffic-monitor');

if (!extension_loaded('pdo_sqlite')) {
    echo "Live Traffic Monitor needs the pdo_sqlite extension in Plesk's own PHP (sw-engine).\n";
    exit(1);
}
exit(0);
