<?php

if (PHP_VERSION_ID < 80427 || PHP_VERSION_ID >= 80500) {
    fwrite(STDERR, 'SupportDesk requires patched PHP 8.4.27 or newer in the 8.4 line. Current: '.PHP_VERSION.".\n");
    exit(1);
}
echo 'Verified patched PHP '.PHP_VERSION.".\n";
