<?php
if (PHP_SAPI === 'cli') {
    fprintf(STDERR, "This script is intended to be run from a web server.\n");
    exit(1);
}

header('Content-Type: application/json');
echo json_encode(\Relay\Relay::stats());
