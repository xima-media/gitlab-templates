#!/usr/bin/env php
<?php

/**
 * TYPO3 Settings Checker
 *
 * Checks that the TYPO3 configuration committed to the repository (settings.php, additional.php, ext_localconf.php,
 * merged with the core defaults) only contains production-safe values. The repository configuration is deployed and acts as the
 * fallback on every instance, including Live, whenever the environment does not set anything else.
 *
 * The active configuration is read via "typo3 configuration:showactive" (requires helhum/typo3-console) in
 * "Production" context. The dotenv files (.env, .env.dist, .env.local, .env.dev, .env.dev.local) are moved aside while
 * reading and restored afterwards, because they hold local values and are not deployed.
 *
 * Usage (from the project root, after composer install):
 *   ./typo3-settings-checker.php
 *   ./typo3-settings-checker.php --ci=reports/junit.xml
 *   ./typo3-settings-checker.php --skip=SYS.devIPmask,BE.lockSSL --ci=reports/junit.xml
 */

class SettingsChecker
{
    private const ERROR_COLOR = "\e[0;31m";
    private const SUCCESS_COLOR = "\e[0;32m";
    private const INFO_COLOR = "\e[0;36m";
    private const RESET_COLOR = "\e[0m";

    /**
     * devIPmask entries that match every client address. Explicit IPs and partial wildcards are fine.
     */
    private const MATCH_ALL_DEV_IP_MASK_ENTRIES = ['*', '*.*.*.*', '0.0.0.0/0', '::/0'];

    private const SECTIONS = ['BE', 'FE', 'SYS', 'MAIL', 'EXTENSIONS', 'HTTP', 'LOG', 'EXTCONF'];

    private const HIDDEN_SUFFIX = '.settings-checker-hidden';

    private const DEV_MAIL_HOSTS = '/(^|[^a-z0-9])(mailpit|mailhog|mailcatcher)([^a-z0-9]|$)|:1025$/i';

    private string $rootDir;

    private ?string $ciReportPath = null;

    /** @var string[] */
    private array $skippedRules = [];

    /** @var string[] */
    private array $errors = [];

    /**
     * Dotenv files moved aside: [hidden path => original path]
     *
     * @var array<string, string>
     */
    private array $hiddenFiles = [];

    /**
     * Results per rule: [ruleName => ['skipped' => bool, 'failures' => string[]]]
     *
     * @var array<string, array{skipped: bool, failures: string[]}>
     */
    private array $results = [];

    public function __construct()
    {
        $this->rootDir = (string)getcwd();
    }

    public function validateEnvironment(array $argv): bool
    {
        foreach (array_slice($argv, 1) as $argument) {
            if (str_starts_with($argument, '--ci')) {
                $this->ciReportPath = substr($argument, 5) ?: 'junit.xml';
                $reportDir = dirname($this->ciReportPath);
                if (!is_dir($reportDir) && !mkdir($reportDir, 0777, true) && !is_dir($reportDir)) {
                    $this->addError("Could not create directory for CI report: {$reportDir}");
                    return false;
                }
            } elseif (str_starts_with($argument, '--skip=')) {
                $this->skippedRules = array_filter(array_map('trim', explode(',', substr($argument, 7))));
            } else {
                $this->addError("Unknown argument '{$argument}'.");
                $this->printMessage(
                    "Usage: './typo3-settings-checker.php [--skip=SYS.devIPmask,BE.lockSSL] [--ci=reports/junit.xml]'",
                    self::INFO_COLOR
                );
                return false;
            }
        }

        if (!is_file($this->rootDir . '/vendor/bin/typo3')) {
            $this->addError('vendor/bin/typo3 not found. Run the script from the project root after composer install.');
            return false;
        }

        exec('php vendor/bin/typo3 list --raw 2>&1', $commands);
        if (!preg_grep('/^configuration:showactive\s/', $commands)) {
            $this->addError('Command configuration:showactive not available, the check requires helhum/typo3-console.');
            return false;
        }

        $unknownRules = array_diff($this->skippedRules, array_keys($this->getRules()));
        if ($unknownRules !== []) {
            $this->addError('Unknown rule(s) in --skip: ' . implode(', ', $unknownRules));
            return false;
        }

        return true;
    }

    public function check(): bool
    {
        try {
            $configuration = $this->loadConfiguration();
        } catch (Throwable $e) {
            $this->addError('Could not load the TYPO3 configuration: ' . $e->getMessage());
            $this->generateJUnitReport();
            return false;
        }

        foreach ($this->getRules() as $name => $rule) {
            $skipped = in_array($name, $this->skippedRules, true);
            $this->results[$name] = [
                'skipped' => $skipped,
                'failures' => $skipped ? [] : $rule($configuration),
            ];
        }

        $failed = false;
        foreach ($this->results as $name => $result) {
            if ($result['skipped']) {
                $this->printMessage("SKIP {$name}", self::INFO_COLOR);
            } elseif ($result['failures'] === []) {
                $this->printMessage("OK   {$name}", self::SUCCESS_COLOR);
            } else {
                $failed = true;
                foreach ($result['failures'] as $failure) {
                    $this->printMessage("FAIL {$name}: {$failure}", self::ERROR_COLOR);
                }
            }
        }

        $this->generateJUnitReport();

        if ($failed) {
            $this->printMessage(
                "\nThe repository configuration is deployed and used as fallback on Live. Keep production values in"
                . " config/system/settings.php and additional.php, enable debug settings only via .env (local, dev).",
                self::ERROR_COLOR
            );
            return false;
        }

        $this->printMessage("\nSuccess: The TYPO3 configuration only contains production-safe values.", self::SUCCESS_COLOR);
        return true;
    }

    /**
     * Read the active configuration via typo3-console (core defaults, settings.php, additional.php, ext_localconf.php)
     * in Production context. The dotenv files are moved aside meanwhile, they hold local values and are not deployed.
     */
    private function loadConfiguration(): array
    {
        putenv('TYPO3_CONTEXT=Production');

        try {
            $this->hideDotEnvFiles();
            $configuration = [];
            foreach (self::SECTIONS as $section) {
                exec('php vendor/bin/typo3 configuration:showactive ' . escapeshellarg($section) . ' --json 2>&1', $output, $exitCode);
                $json = implode("\n", $output);
                $output = [];
                if ($exitCode !== 0) {
                    // Unset sections ("No configuration found") are fine, everything else is an error
                    if (str_contains($json, 'No configuration found')) {
                        continue;
                    }
                    throw new RuntimeException("configuration:showactive {$section} failed: {$json}");
                }
                $configuration[$section] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            }
        } finally {
            $this->restoreDotEnvFiles();
        }

        return $configuration;
    }

    /**
     * Rename all files helhum/dotenv-connector (Symfony Dotenv::loadEnv()) would load: the base file, its .dist
     * fallback, .local and the files for the default APP_ENV "dev".
     */
    private function hideDotEnvFiles(): void
    {
        $composer = json_decode((string)file_get_contents($this->rootDir . '/composer.json'), true) ?: [];
        $base = $composer['extra']['helhum/dotenv-connector']['env-file'] ?? '.env';

        foreach (['', '.dist', '.local', '.dev', '.dev.local'] as $suffix) {
            $file = $this->rootDir . '/' . $base . $suffix;
            $hidden = $file . self::HIDDEN_SUFFIX;

            // Leftover from an aborted run
            if (is_file($hidden)) {
                if (file_exists($file)) {
                    throw new RuntimeException("Both {$base}{$suffix} and {$base}{$suffix}" . self::HIDDEN_SUFFIX . ' exist, resolve this manually.');
                }
                rename($hidden, $file);
            }

            if (is_file($file)) {
                if (!rename($file, $hidden)) {
                    throw new RuntimeException("Could not move {$base}{$suffix} aside.");
                }
                $this->hiddenFiles[$hidden] = $file;
            }
        }

        if ($this->hiddenFiles !== []) {
            register_shutdown_function([$this, 'restoreDotEnvFiles']);
            if (function_exists('pcntl_signal')) {
                pcntl_async_signals(true);
                foreach ([SIGINT, SIGTERM] as $signal) {
                    pcntl_signal($signal, function () {
                        $this->restoreDotEnvFiles();
                        exit(1);
                    });
                }
            }
            $this->printMessage(
                'Temporarily moved aside: ' . implode(', ', array_map('basename', $this->hiddenFiles)),
                self::INFO_COLOR
            );
        }
    }

    public function restoreDotEnvFiles(): void
    {
        foreach ($this->hiddenFiles as $hidden => $file) {
            rename($hidden, $file);
            unset($this->hiddenFiles[$hidden]);
        }
    }

    /**
     * @return array<string, Closure(array): string[]>
     */
    private function getRules(): array
    {
        return [
            'BE.debug' => fn(array $c): array => $this->isTruthy($c['BE']['debug'] ?? null)
                ? [sprintf('is %s, expected false', var_export($c['BE']['debug'], true))]
                : [],
            'FE.debug' => fn(array $c): array => $this->isTruthy($c['FE']['debug'] ?? null)
                ? [sprintf('is %s, expected false', var_export($c['FE']['debug'], true))]
                : [],
            'SYS.devIPmask' => function (array $c): array {
                $value = (string)($c['SYS']['devIPmask'] ?? '');
                return $this->isSafeDevIpMask($value)
                    ? []
                    : [sprintf("is '%s', which matches every client address; use explicit IPs instead", $value)];
            },
            'SYS.displayErrors' => function (array $c): array {
                $value = (int)($c['SYS']['displayErrors'] ?? -1);
                if ($value === 0) {
                    return [];
                }
                if ($value === -1 && $this->isSafeDevIpMask((string)($c['SYS']['devIPmask'] ?? ''))) {
                    return [];
                }
                return [sprintf('is %d, expected 0 (or -1 together with a devIPmask that does not match every address)', $value)];
            },
            'SYS.productionExceptionHandler' => function (array $c): array {
                $value = ltrim((string)($c['SYS']['productionExceptionHandler'] ?? ''), '\\');
                return str_ends_with($value, 'DebugExceptionHandler')
                    ? [sprintf("is '%s', the debug exception handler shows stack traces to every visitor", $value)]
                    : [];
            },
            'SYS.trustedHostsPattern' => function (array $c): array {
                $value = (string)($c['SYS']['trustedHostsPattern'] ?? 'SERVER_NAME');
                if ($value === 'SERVER_NAME') {
                    return [];
                }
                // Same check as GeneralUtility::hostHeaderValueMatchesTrustedHostsPattern()
                $matchesAnyHost = @preg_match('/^' . $value . '$/i', 'attacker.invalid') === 1;
                return $matchesAnyHost ? [sprintf("is '%s', which accepts any host header", $value)] : [];
            },
            'BE.lockSSL' => fn(array $c): array => $this->isTruthy($c['BE']['lockSSL'] ?? null)
                ? []
                : [sprintf('is %s, expected true', var_export($c['BE']['lockSSL'] ?? null, true))],
            'MAIL.transport' => function (array $c): array {
                $transport = (string)($c['MAIL']['transport'] ?? '');
                if (in_array(strtolower($transport), ['mbox', 'null'], true) || str_ends_with($transport, 'NullTransport')) {
                    return [sprintf("is '%s', mails would not be sent", $transport)];
                }
                $server = (string)($c['MAIL']['transport_smtp_server'] ?? '');
                if ($transport === 'smtp' && preg_match(self::DEV_MAIL_HOSTS, $server)) {
                    return [sprintf("SMTP server '%s' looks like a local mail catcher", $server)];
                }
                return [];
            },
            'EXTENSIONS.*debug*' => function (array $c): array {
                $failures = [];
                $this->findTruthyDebugKeys($c['EXTENSIONS'] ?? [], 'EXTENSIONS', $failures);
                return $failures;
            },
            'HTTP.timeout' => fn(array $c): array => (float)($c['HTTP']['timeout'] ?? 0) > 0
                ? []
                : [sprintf('is %s, expected a timeout > 0 (0 waits forever)', var_export($c['HTTP']['timeout'] ?? null, true))],
            'LOG.deprecations' => function (array $c): array {
                $failures = [];
                foreach ($c['LOG']['TYPO3']['CMS']['deprecations']['writerConfiguration'] ?? [] as $level => $writers) {
                    foreach ((array)$writers as $writer => $options) {
                        if (!$this->isTruthy($options['disabled'] ?? false)) {
                            $failures[] = sprintf('writer %s (%s) is enabled, expected disabled', ltrim((string)$writer, '\\'), $level);
                        }
                    }
                }
                return $failures;
            },
            'LOG.Scheduler' => function (array $c): array {
                foreach ($c['LOG']['TYPO3']['CMS']['Scheduler']['writerConfiguration'] ?? [] as $writers) {
                    foreach ((array)$writers as $writer => $options) {
                        if (str_ends_with((string)$writer, 'FileWriter')
                            && !$this->isTruthy($options['disabled'] ?? false)
                            && !empty($options['logFile'])
                        ) {
                            return [];
                        }
                    }
                }
                return ['expected an enabled FileWriter with logFile in LOG.TYPO3.CMS.Scheduler.writerConfiguration'];
            },
            'BE.warning_email_addr' => fn(array $c): array => filter_var($c['BE']['warning_email_addr'] ?? '', FILTER_VALIDATE_EMAIL)
                ? []
                : [sprintf("is '%s', expected a valid e-mail address", $c['BE']['warning_email_addr'] ?? '')],
            'EXTCONF.lang.availableLanguages' => fn(array $c): array => in_array('de', (array)($c['EXTCONF']['lang']['availableLanguages'] ?? []), true)
                ? []
                : ["expected 'de' in the list of language packs"],
            'SYS.defaultScheme' => fn(array $c): array => ($c['SYS']['defaultScheme'] ?? '') === 'https'
                ? []
                : [sprintf("is '%s', expected 'https'", $c['SYS']['defaultScheme'] ?? '')],
            'FE.disableNoCacheParameter' => fn(array $c): array => $this->isTruthy($c['FE']['disableNoCacheParameter'] ?? null)
                ? []
                : [sprintf('is %s, expected true', var_export($c['FE']['disableNoCacheParameter'] ?? null, true))],
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no', 'bool(false)'], true);
        }
        return (bool)$value;
    }

    private function isSafeDevIpMask(string $value): bool
    {
        foreach (explode(',', $value) as $entry) {
            if (in_array(trim($entry), self::MATCH_ALL_DEV_IP_MASK_ENTRIES, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string[] $failures
     */
    private function findTruthyDebugKeys(mixed $configuration, string $path, array &$failures): void
    {
        if (!is_array($configuration)) {
            return;
        }
        foreach ($configuration as $key => $value) {
            $currentPath = $path . '.' . $key;
            if (is_array($value)) {
                $this->findTruthyDebugKeys($value, $currentPath, $failures);
            } elseif (stripos((string)$key, 'debug') !== false && $this->isTruthy($value)) {
                $failures[] = sprintf('%s is %s, expected false', $currentPath, var_export($value, true));
            }
        }
    }

    private function addError(string $message): void
    {
        $this->errors[] = $message;
        $this->printMessage('Error: ' . $message, self::ERROR_COLOR);
    }

    private function printMessage(string $message, string $color = self::INFO_COLOR): void
    {
        echo $color . $message . self::RESET_COLOR . PHP_EOL;
    }

    private function generateJUnitReport(): void
    {
        if (!$this->ciReportPath) {
            return;
        }

        $failures = count(array_filter($this->results, static fn(array $r): bool => $r['failures'] !== []));
        $skipped = count(array_filter($this->results, static fn(array $r): bool => $r['skipped']));

        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><testsuites/>');
        $testsuite = $xml->addChild('testsuite');
        $testsuite->addAttribute('name', 'TYPO3 Settings');
        $testsuite->addAttribute('tests', (string)(count($this->results) + count($this->errors)));
        $testsuite->addAttribute('failures', (string)$failures);
        $testsuite->addAttribute('errors', (string)count($this->errors));
        $testsuite->addAttribute('skipped', (string)$skipped);
        $testsuite->addAttribute('timestamp', date('c'));

        foreach ($this->results as $name => $result) {
            $testcase = $testsuite->addChild('testcase');
            $testcase->addAttribute('name', $name);
            $testcase->addAttribute('classname', 'SettingsChecker');
            if ($result['skipped']) {
                $testcase->addChild('skipped');
            } elseif ($result['failures'] !== []) {
                $failure = $testcase->addChild('failure');
                $failure[0] = implode("\n", $result['failures']);
                $failure->addAttribute('type', 'UnsafeSetting');
                $failure->addAttribute('message', "{$name}: " . $result['failures'][0]);
            }
        }

        foreach ($this->errors as $message) {
            $testcase = $testsuite->addChild('testcase');
            $testcase->addAttribute('name', 'Load configuration');
            $testcase->addAttribute('classname', 'SettingsChecker');
            $error = $testcase->addChild('error');
            $error->addAttribute('message', $message);
        }

        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML() ?: '');

        if (!$dom->save($this->ciReportPath)) {
            $this->addError("Failed to write CI report to {$this->ciReportPath}");
        } else {
            $this->printMessage("JUnit report generated: {$this->ciReportPath}", self::SUCCESS_COLOR);
        }
    }
}

$checker = new SettingsChecker();
if ($checker->validateEnvironment($argv)) {
    exit($checker->check() ? 0 : 1);
}
exit(1);
