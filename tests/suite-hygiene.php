<?php

declare(strict_types=1);

/**
 * Guard against uncatchable fatal errors in the test suite itself.
 *
 * `php -l` (and PHPUnit) cannot protect against these: they are link-time
 * fatals, so the whole PHP process dies before a single test runs and the
 * JUnit report is left empty. The suite then reports a bare exit code 255 with
 * no failing test, which is very hard to diagnose from CI output.
 *
 * Checked here:
 *   1. a test class declaring a method that is `final` in PHPUnit's or
 *      Laravel's TestCase (for example `run()`), which is a fatal at load time;
 *   2. a test file whose class name or namespace does not match its path, which
 *      makes PHPUnit unable to load it (fatal "class not found").
 *
 * Run: php tests/suite-hygiene.php
 */

$root = dirname(__DIR__);
$autoload = $root . "/vendor/autoload.php";
$scripts = ["security-smoke.php", "suite-hygiene.php", "report-ci.php"];

$finalMethods = [];
$checkedParents = ["PHPUnit\\Framework\\TestCase", "Illuminate\\Foundation\\Testing\\TestCase"];
if (is_file($autoload)) {
    require $autoload;
    foreach ($checkedParents as $parent) {
        if (!class_exists($parent)) {
            continue;
        }
        foreach ((new ReflectionClass($parent))->getMethods() as $method) {
            if ($method->isFinal()) {
                $finalMethods[strtolower($method->getName())] = $parent . "::" . $method->getName() . "()";
            }
        }
    }
    echo "Final methods inherited by test classes: " . count($finalMethods) . "\n";
} else {
    echo "Notice: vendor/autoload.php missing; using the known final methods only.\n";
    // PHPUnit's TestCase::run() has been final since PHPUnit 10.
    $finalMethods["run"] = "PHPUnit\\Framework\\TestCase::run()";
}

$errors = [];
$files = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . "/tests", FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== "php" || in_array($file->getFilename(), $scripts, true)) {
        continue;
    }
    $path = $file->getPathname();
    $relative = ltrim(str_replace($root . "/", "", $path), "/");
    $source = (string) file_get_contents($path);
    $files++;

    preg_match('/^\s*namespace\s+([^;]+);/m', $source, $nsMatch);
    $namespace = trim($nsMatch[1] ?? "");

    if (!preg_match('/^\s*(?:final\s+|abstract\s+)*(?:class|interface|trait)\s+(\w+)/m', $source, $classMatch)) {
        continue; // A plain script, not a class file.
    }
    $class = $classMatch[1];

    // PSR-4 consistency: tests/Feature/FooTest.php is Tests\Feature\FooTest.
    $directory = trim(str_replace($root . "/", "", dirname($path)), "/");
    $rest = trim(substr($directory, strlen("tests")), "/");
    $expectedNamespace = "Tests" . ($rest === "" ? "" : "\\" . str_replace("/", "\\", $rest));
    if ($namespace !== $expectedNamespace) {
        $errors[] = "$relative declares namespace \"$namespace\" but should be \"$expectedNamespace\".";
    }
    if ($class !== pathinfo($path, PATHINFO_FILENAME)) {
        $errors[] = "$relative declares class \"$class\" but the file is named \"" . pathinfo($path, PATHINFO_FILENAME) . "\".";
    }

    preg_match('/class\s+\w+\s+extends\s+([\w\\\\]+)/', $source, $extendsMatch);
    if (!isset($extendsMatch[1])) {
        continue; // Not a test case (helper class or interface).
    }

    preg_match_all('/^\s*(?:public|protected|private)?\s*(?:static\s+)?function\s+(\w+)\s*\(/m', $source, $methodMatches);
    foreach ($methodMatches[1] as $method) {
        $lower = strtolower($method);
        if (isset($finalMethods[$lower])) {
            $errors[] = "$relative declares $class::$method(), which overrides the final method {$finalMethods[$lower]} — an uncatchable fatal error.";
        }
    }
}

echo "Test files checked: $files\n";
if ($errors === []) {
    echo "Suite hygiene: no fatal class-declaration risks found.\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "::error::$error\n";
    echo "ERROR: $error\n";
}
echo "Suite hygiene failed with " . count($errors) . " problem(s).\n";
exit(1);
