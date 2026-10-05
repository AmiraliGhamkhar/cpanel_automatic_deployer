<?php
// Publish PHPUnit failures as GitHub annotations, without requiring log downloads.
$path = $argv[1] ?? 'test-results.xml';
if (!is_file($path)) {
    exit(0);
}
$report = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
foreach ($report->xpath('//testcase/failure | //testcase/error') as $failure) {
    $message = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], (string) $failure);
    echo "::error::$message\n";
}
