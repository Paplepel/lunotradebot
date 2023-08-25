<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
echo shell_exec('/usr/local/bin/ea-php81 /home/sopleudy/public_html/composer.phar update');
?>
