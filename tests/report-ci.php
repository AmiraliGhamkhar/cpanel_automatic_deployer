<?php

declare(strict_types=1);

/**
 * Turn a PHPUnit JUnit report (plus the raw console log) into GitHub
 * annotations, because job logs are not always retrievable from the
 * development environment. Prints a human-readable summary as well.
 *
 * Usage: php tests/report-ci.php [test-results.xml] [phpunit-output.log]
 */

$junit = $argv[1] ?? "test-results.xml";
$logPath = $argv[2] ?? null;

/** Escape a message for a GitHub workflow command. */
function gh(string $message, int $limit = 1500): string
{
    $message = str_replace("%", "%25", $message);
    $message = str_replace(["\r\n", "\r", "\n"], "%0A", $message);

    return strlen($message) > $limit ? substr($message, 0, $limit) . "…" : $message;
}

/** @return array{0:int,1:int,2:int} tests, failures+errors, skipped */
function report(string $junit): array
{
    if (!is_file($junit)) {
        // Not a workflow command on purpose; the caller adds context.
        return [0, 0, 0];
    }

    $xml = @simplexml_load_file($junit);
    if ($xml === false) {
        return [0, 0, 0];
    }

    $total = 0;
    $bad = 0;
    $skipped = 0;
    $annotations = 0;

    $suites = [];
    if (isset($xml->testsuite)) {
        foreach ($xml->testsuite as $suite) {
            $suites[] = $suite;
        }
    }
    $nested = [];
    foreach ($suites as $suite) {
        if (isset($suite->testsuite)) {
            foreach ($suite->testsuite as $child) {
                $nested[] = $child;
            }
        }
    }
    foreach (array_merge($suites, $nested) as $suite) {
        foreach ($suite->testcase as $case) {
            $total++;
            $class = (string) ($case["class"] ?? ($suite["name"] ?? "unknown"));
            $name = (string) ($case["name"] ?? "unnamed");
            $file = (string) ($case["file"] ?? "");
            $line = (int) ($case["line"] ?? 0);

            foreach (["failure", "error"] as $kind) {
                if (!isset($case->$kind)) {
                    continue;
                }
                $bad++;
                $detail = trim((string) $case->$kind);
                if (isset($case->$kind["message"])) {
                    $detail = trim((string) $case->$kind["message"]) . "\n" . $detail;
                }
                $message = $class . "::" . $name . " [" . $kind . "] " . $detail;
                if ($annotations < 40) {
                    $annotations++;
                    fwrite(
                        STDOUT,
                        "::error file=" . $file . ",line=" . $line . "::" . gh($message) . "\n",
                    );
                }
            }

            if (isset($case->skipped)) {
                $skipped++;
            }
        }
    }

    $summary = "PHPUnit: " . ($total - $bad - $skipped) . " passed, " . $bad . " failed, " . $skipped . " skipped, " . $total . " total";
    fwrite(STDOUT, $summary . "\n");
    if (getenv("GITHUB_ACTIONS") === "true") {
        fwrite(STDOUT, "::notice::" . gh($summary) . "\n");
    }

    return [$total, $bad, $skipped];
}

[$total, $bad] = report($junit);

if ($total === 0 && $logPath !== null && is_file($logPath)) {
    fwrite(STDOUT, "::error::PHPUnit produced no JUnit report; see the log tail below.\n");
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES) ?: [];
    $tail = array_slice($lines, -30);
    $chunk = "";
    foreach ($tail as $line) {
        $candidate = $chunk === "" ? $line : $chunk . "\n" . $line;
        if (strlen($candidate) > 1200) {
            fwrite(STDOUT, "::error::" . gh($chunk) . "\n");
            $chunk = $line;
            continue;
        }
        $chunk = $candidate;
    }
    if ($chunk !== "") {
        fwrite(STDOUT, "::error::" . gh($chunk) . "\n");
    }
}

exit($bad > 0 || ($total === 0 && $logPath !== null) ? 1 : 0);
