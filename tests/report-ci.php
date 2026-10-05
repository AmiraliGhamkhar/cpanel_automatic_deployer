<?php
// Publish PHPUnit failures as GitHub annotations, without requiring log downloads.
$path = $argv[1] ?? 'test-results.xml';
if (!is_file($path)) {
    exit(0);
}
$report = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
$totals = ['tests' => 0, 'assertions' => 0, 'errors' => 0, 'failures' => 0, 'skipped' => 0];
foreach ($report->xpath('/testsuites/testsuite') as $suite) {
    foreach ($totals as $name => &$value) {
        $value += (int) $suite[$name];
    }
    unset($value);
}
echo '::notice::PHPUnit results: ' . json_encode($totals, JSON_THROW_ON_ERROR) . "\n";
foreach ($report->xpath('//testcase/failure | //testcase/error') as $failure) {
    $message = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], (string) $failure);
    echo "::error::$message\n";
}
