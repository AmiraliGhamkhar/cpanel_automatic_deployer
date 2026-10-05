<?php

declare(strict_types=1);

/**
 * Turn a PHPUnit JUnit report (plus the raw console log) into GitHub
 * annotations, because job logs are not always retrievable from the
 * development environment.
 *
 * Annotations are the fallback channel, so the report deliberately covers the
 * cases that matter for a process-level death: every fatal/PHP-level error line
 * found anywhere in the log, plus the head and tail of the log when no usable
 * JUnit report exists.
 *
 * Usage: php tests/report-ci.php [test-results.xml] [phpunit-output.log]
 */

$junit = $argv[1] ?? "test-results.xml";
$logPath = $argv[2] ?? null;

/** Escape a message for a GitHub workflow command. */
function gh(string $message, int $limit = 1200): string
{
    $message = str_replace("%", "%25", $message);
    $message = str_replace(["\r\n", "\r", "\n"], "%0A", $message);

    return strlen($message) > $limit ? substr($message, 0, $limit) . "…" : $message;
}

function emit(string $level, string $message, string $file = "", int $line = 0): void
{
    if ($file !== "") {
        fwrite(STDOUT, "::{$level} file={$file},line={$line}::" . gh($message) . "\n");
        return;
    }
    fwrite(STDOUT, "::{$level}::" . gh($message) . "\n");
}

/**
 * @return array{tests:int,bad:int,skipped:int,readable:bool}
 */
function readJunit(string $junit): array
{
    if (!is_file($junit)) {
        return ["tests" => 0, "bad" => 0, "skipped" => 0, "readable" => false];
    }

    // A fatal error mid-run leaves the stream created by PHPUnit's JUnit
    // logger empty; that is exactly the signal we must not swallow.
    $raw = (string) @file_get_contents($junit);
    if (trim($raw) === "" || stripos($raw, "<testsuites") === false) {
        return ["tests" => 0, "bad" => 0, "skipped" => 0, "readable" => false];
    }

    $xml = @simplexml_load_string($raw);
    if ($xml === false) {
        return ["tests" => 0, "bad" => 0, "skipped" => 0, "readable" => false];
    }

    $suites = [];
    foreach ($xml->testsuite ?? [] as $suite) {
        $suites[] = $suite;
        foreach ($suite->testsuite ?? [] as $nested) {
            $suites[] = $nested;
        }
    }

    $tests = 0;
    $bad = 0;
    $skipped = 0;
    $annotated = 0;

    foreach ($suites as $suite) {
        foreach ($suite->testcase ?? [] as $case) {
            $tests++;
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
                if ($annotated < 40) {
                    $annotated++;
                    emit("error", "{$class}::{$name} [{$kind}] {$detail}", $file, $line);
                }
            }

            if (isset($case->skipped)) {
                $skipped++;
            }
        }
    }

    return ["tests" => $tests, "bad" => $bad, "skipped" => $skipped, "readable" => true];
}

/**
 * Annotate process-level failures (fatals, uncaught throwables, OOM, signals)
 * wherever they appear in the log.
 */
function annotateFatalLines(string $logPath): int
{
    if (!is_file($logPath)) {
        return 0;
    }

    $pattern = "/Fatal error|Uncaught (Error|Exception|Throwable)|Allowed memory size|Stack overflow|Segmentation fault|Killed|Core dumped|PHP Warning:  Failed|not found in|Cannot declare|must be compatible|sh: line|bash: line/i";
    $found = 0;
    $seen = [];
    foreach ((array) @file($logPath, FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === "" || !preg_match($pattern, $line)) {
            continue;
        }
        $key = substr($line, 0, 200);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $found++;
        if ($found > 20) {
            break;
        }
        emit("error", $line);
    }

    return $found;
}

function annotateChunk(string $label, array $lines): void
{
    $chunk = "";
    foreach ($lines as $line) {
        $candidate = $chunk === "" ? $line : $chunk . "\n" . $line;
        if (strlen($candidate) > 1000) {
            emit("error", "{$label}\n{$chunk}");
            $chunk = $line;
            continue;
        }
        $chunk = $candidate;
    }
    if ($chunk !== "") {
        emit("error", "{$label}\n{$chunk}");
    }
}

$result = readJunit($junit);
$fatalLines = annotateFatalLines((string) $logPath);

if ($result["readable"]) {
    $summary = "PHPUnit: " . ($result["tests"] - $result["bad"] - $result["skipped"]) . " passed, " . $result["bad"] . " failed, " . $result["skipped"] . " skipped, " . $result["tests"] . " total";
    fwrite(STDOUT, $summary . "\n");
    if (getenv("GITHUB_ACTIONS") === "true") {
        emit("notice", $summary);
    }
} else {
    $summary = "PHPUnit produced no usable JUnit report (missing, empty or truncated): the process most likely died. " . $fatalLines . " fatal line(s) annotated.";
    fwrite(STDOUT, $summary . "\n");
    if (getenv("GITHUB_ACTIONS") === "true") {
        emit("error", $summary);
    }

    if ($logPath !== null && is_file($logPath)) {
        $lines = (array) @file($logPath, FILE_IGNORE_NEW_LINES);
        annotateChunk("phpunit log head", array_slice($lines, 0, 12));
        annotateChunk("phpunit log tail", array_slice($lines, -25));
    }
}

if (!$result["readable"] && $logPath !== null) {
    exit(1);
}

exit($result["bad"] > 0 ? 1 : 0);
