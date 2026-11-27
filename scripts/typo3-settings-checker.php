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
 * Exit codes: 0 all checked rules ok (skipped rules are listed), 1 a rule failed or the configuration could not be read.
 *
 * Usage (from the project root, after composer install):
 *   ./typo3-settings-checker.php
 *   ./typo3-settings-checker.php --skip=SYS.devIPmask,BE.lockSSL
 */

class SettingsChecker
{
    private const ERROR_COLOR = "\e[0;31m";
    private const SUCCESS_COLOR = "\e[0;32m";
    private const INFO_COLOR = "\e[0;36m";
    private const SKIP_COLOR = "\e[0;33m";
    private const RESET_COLOR = "\e[0m";

    public const EXIT_OK = 0;
    public const EXIT_FAILED = 1;

    /**
     * IP list entries (devIPmask, reverseProxyIP) that match every client address. Explicit IPs and partial wildcards are fine.
     */
    private const MATCH_ALL_IP_ENTRIES = ['*', '*.*.*.*', '0.0.0.0/0', '::/0'];

    private const SECTIONS = ['BE', 'FE', 'SYS', 'MAIL', 'EXTENSIONS', 'HTTP', 'LOG', 'EXTCONF', 'DB'];

    /**
     * File names the fileDenyPattern must reject (FileNameValidator::DEFAULT_FILE_DENY_PATTERN blocks them all)
     */
    private const DENIED_FILE_NAMES = ['shell.php', 'shell.php5', 'shell.phtml', 'shell.phar', 'shell.php.jpg', '.htaccess'];

    /**
     * Hosts that only exist in local DDEV/Docker setups
     */
    private const DEV_HOSTS = '/\.ddev\.site\b|\bhost\.docker\.internal\b|\bddev-[a-z0-9-]+-(db|web|solr|redis|elasticsearch)\b/i';

    private const HIDDEN_SUFFIX = '.settings-checker-hidden';

    private const DEV_MAIL_HOSTS = '/(^|[^a-z0-9])(mailpit|mailhog|mailcatcher)([^a-z0-9]|$)|:1025$/i';

    private string $rootDir;

    /** @var string[] */
    private array $skippedRules = [];

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
            if (str_starts_with($argument, '--skip=')) {
                $this->skippedRules = array_filter(array_map('trim', explode(',', substr($argument, 7))));
            } else {
                $this->addError("Unknown argument '{$argument}'.");
                $this->printMessage(
                    "Usage: './typo3-settings-checker.php [--skip=SYS.devIPmask,BE.lockSSL]'",
                    self::INFO_COLOR
                );
                return false;
            }
        }

        if (!is_file($this->rootDir . '/vendor/bin/typo3')) {
            $this->addError('vendor/bin/typo3 not found. Run the script from the project root after composer install.');
            return false;
        }

        $commands = $this->run('php vendor/bin/typo3 list --raw', $exitCode);
        if ($exitCode !== 0) {
            $this->addError("The TYPO3 CLI does not start:\n" . implode("\n", $commands));
            return false;
        }
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

    /**
     * @return int self::EXIT_OK or self::EXIT_FAILED
     */
    public function check(): int
    {
        try {
            $configuration = $this->loadConfiguration();
        } catch (Throwable $e) {
            $this->addError('Could not load the TYPO3 configuration: ' . $e->getMessage());
            return self::EXIT_FAILED;
        }

        $this->printMessage('');

        foreach ($this->getRules() as $name => $check) {
            $skipped = in_array($name, $this->skippedRules, true);
            $this->results[$name] = [
                'skipped' => $skipped,
                'failures' => $skipped ? [] : $check($configuration),
            ];
        }

        foreach ($this->results as $name => $result) {
            if ($result['skipped']) {
                $this->printMessage("SKIP {$name}", self::SKIP_COLOR);
            } elseif ($result['failures'] === []) {
                $this->printMessage("OK   {$name}", self::SUCCESS_COLOR);
            } else {
                foreach ($result['failures'] as $failure) {
                    $this->printMessage("FAIL {$name}: {$failure}", self::ERROR_COLOR);
                }
            }
        }

        $count = fn(callable $filter): int => count(array_filter($this->results, $filter));
        $failed = $count(static fn(array $r): bool => $r['failures'] !== []);
        $skipped = $count(static fn(array $r): bool => $r['skipped']);
        $this->printMessage(sprintf(
            "\n%d rules: %d ok, %d failed, %d skipped",
            count($this->results),
            count($this->results) - $failed - $skipped,
            $failed,
            $skipped
        ));

        if ($failed > 0) {
            $this->printMessage(
                "\nThe repository configuration is deployed and used as fallback on Live. Keep production values in"
                . " config/system/settings.php and additional.php, enable debug settings only via .env (local, dev).",
                self::ERROR_COLOR
            );
            return self::EXIT_FAILED;
        }

        $this->printMessage("\nSuccess: The TYPO3 configuration only contains production-safe values.", self::SUCCESS_COLOR);
        return self::EXIT_OK;
    }

    /**
     * Read the active configuration via typo3-console (core defaults, settings.php, additional.php, ext_localconf.php)
     * in Production context. The dotenv files are moved aside meanwhile, they hold local values and are not deployed.
     */
    private function loadConfiguration(): array
    {
        putenv('TYPO3_CONTEXT=Production');
        $this->printMessage('$ export TYPO3_CONTEXT=Production');

        try {
            $this->hideDotEnvFiles();
            $configuration = [];
            foreach (self::SECTIONS as $section) {
                $json = implode("\n", $this->run(
                    'php vendor/bin/typo3 configuration:showactive ' . escapeshellarg($section) . ' --json',
                    $exitCode
                ));
                if ($exitCode !== 0) {
                    // Unset sections ("No configuration found") are fine, everything else is an error
                    if (str_contains($json, 'No configuration found')) {
                        $this->printMessage("  {$section} is not set, using an empty section");
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
        $passwordPolicy = fn(string $section): Closure => function (array $c) use ($section): array {
            $policy = (string)($c[$section]['passwordPolicy'] ?? 'default');
            if ($policy === '') {
                return ["is '', which disables the password policy"];
            }
            return isset($c['SYS']['passwordPolicies'][$policy])
                ? []
                : [sprintf("is '%s', which is not defined in SYS.passwordPolicies", $policy)];
        };

        return [
            '*.devHosts' => function (array $c): array {
                $failures = [];
                foreach (['DB', 'EXTENSIONS', 'HTTP'] as $section) {
                    foreach ($this->flatten($c[$section] ?? [], $section) as $path => $value) {
                        if (is_string($value) && preg_match(self::DEV_HOSTS, $value)) {
                            $failures[] = sprintf("%s is '%s', which only exists locally", $path, $value);
                        }
                    }
                }
                foreach ($c['DB']['Connections'] ?? [] as $name => $connection) {
                    if (($connection['host'] ?? '') === 'db') {
                        $failures[] = sprintf("DB.Connections.%s.host is 'db', the DDEV database container", $name);
                    }
                }
                return $failures;
            },
            'BE.adminOnly' => fn(array $c): array => (int)($c['BE']['adminOnly'] ?? 0) === 0
                ? []
                : [sprintf('is %d, expected 0 (otherwise editors are locked out of the backend)', $c['BE']['adminOnly'])],
            'BE.debug' => fn(array $c): array => $this->isTruthy($c['BE']['debug'] ?? null)
                ? [sprintf('is %s, expected false', var_export($c['BE']['debug'], true))]
                : [],
            'BE.fileDenyPattern' => function (array $c): array {
                if (!array_key_exists('fileDenyPattern', $c['BE'] ?? [])) {
                    return []; // FileNameValidator::DEFAULT_FILE_DENY_PATTERN applies
                }
                $pattern = (string)$c['BE']['fileDenyPattern'];
                // Same check as FileNameValidator::isValid()
                $allowed = array_filter(
                    self::DENIED_FILE_NAMES,
                    static fn(string $file): bool => $pattern === '' || @preg_match('/(?:[[:cntrl:]]|' . $pattern . ')/iu', $file) !== 1
                );
                return $allowed === []
                    ? []
                    : [sprintf("'%s' does not block %s", $pattern, implode(', ', $allowed))];
            },
            'BE.lockSSL' => fn(array $c): array => $this->isTruthy($c['BE']['lockSSL'] ?? null)
                ? []
                : [sprintf('is %s, expected true', var_export($c['BE']['lockSSL'] ?? null, true))],
            'BE.passwordPolicy' => $passwordPolicy('BE'),
            'BE.warning_email_addr' => fn(array $c): array => filter_var($c['BE']['warning_email_addr'] ?? '', FILTER_VALIDATE_EMAIL)
                ? []
                : [sprintf("is '%s', expected a valid e-mail address", $c['BE']['warning_email_addr'] ?? '')],
            'DB.Connections.Default.charset' => fn(array $c): array => ($c['DB']['Connections']['Default']['charset'] ?? '') === 'utf8mb4'
                ? []
                : [sprintf("is '%s', expected 'utf8mb4'", $c['DB']['Connections']['Default']['charset'] ?? '')],
            'EXTCONF.lang.availableLanguages' => fn(array $c): array => in_array('de', (array)($c['EXTCONF']['lang']['availableLanguages'] ?? []), true)
                ? []
                : ["expected 'de' in the list of language packs"],
            'EXTENSIONS.*debug*' => function (array $c): array {
                $failures = [];
                foreach ($this->flatten($c['EXTENSIONS'] ?? [], 'EXTENSIONS') as $path => $value) {
                    if (stripos(substr($path, strrpos($path, '.') + 1), 'debug') !== false && $this->isTruthy($value)) {
                        $failures[] = sprintf('%s is %s, expected false', $path, var_export($value, true));
                    }
                }
                return $failures;
            },
            'FE.debug' => fn(array $c): array => $this->isTruthy($c['FE']['debug'] ?? null)
                ? [sprintf('is %s, expected false', var_export($c['FE']['debug'], true))]
                : [],
            'FE.disableNoCacheParameter' => fn(array $c): array => $this->isTruthy($c['FE']['disableNoCacheParameter'] ?? null)
                ? []
                : [sprintf('is %s, expected true', var_export($c['FE']['disableNoCacheParameter'] ?? null, true))],
            'FE.pageNotFoundOnCHashError' => fn(array $c): array => $this->isTruthy($c['FE']['pageNotFoundOnCHashError'] ?? true)
                ? []
                : [sprintf('is %s, expected true (otherwise invalid cHash requests get rendered and cached)', var_export($c['FE']['pageNotFoundOnCHashError'], true))],
            'FE.pageUnavailable_force' => fn(array $c): array => $this->isTruthy($c['FE']['pageUnavailable_force'] ?? false)
                ? [sprintf('is %s, the whole frontend would answer with 503', var_export($c['FE']['pageUnavailable_force'], true))]
                : [],
            'FE.passwordPolicy' => $passwordPolicy('FE'),
            'HTTP.timeout' => fn(array $c): array => (float)($c['HTTP']['timeout'] ?? 0) > 0
                ? []
                : [sprintf('is %s, expected a timeout > 0 (0 waits forever)', var_export($c['HTTP']['timeout'] ?? null, true))],
            'HTTP.verify' => fn(array $c): array => $this->isTruthy($c['HTTP']['verify'] ?? true)
                ? []
                : [sprintf('is %s, TLS certificates of outgoing requests would not be verified', var_export($c['HTTP']['verify'], true))],
            'LOG.debug' => function (array $c): array {
                $failures = [];
                foreach ($this->findLogWriterConfigurations($c['LOG'] ?? [], 'LOG') as $path => $levels) {
                    foreach ($levels as $level => $writers) {
                        if (!in_array(strtolower((string)$level), ['debug', '7'], true)) {
                            continue;
                        }
                        foreach ((array)$writers as $writer => $options) {
                            // EXT:adminpanel logs into memory for its own panel, nothing is written
                            if (!str_ends_with((string)$writer, 'InMemoryLogWriter') && !$this->isTruthy($options['disabled'] ?? false)) {
                                $failures[] = sprintf('%s.%s: writer %s is enabled', $path, $level, ltrim((string)$writer, '\\'));
                            }
                        }
                    }
                }
                return $failures;
            },
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
            'MAIL.defaultMailFromAddress' => fn(array $c): array => filter_var($c['MAIL']['defaultMailFromAddress'] ?? '', FILTER_VALIDATE_EMAIL)
                ? []
                : [sprintf("is '%s', expected a valid e-mail address (otherwise no-reply@<hostname> is used)", $c['MAIL']['defaultMailFromAddress'] ?? '')],
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
            'SYS.caching' => function (array $c): array {
                $failures = [];
                foreach ($c['SYS']['caching']['cacheConfigurations'] ?? [] as $cache => $configuration) {
                    $backend = ltrim((string)($configuration['backend'] ?? ''), '\\');
                    if (str_ends_with($backend, 'NullBackend')) {
                        $failures[] = sprintf('cache %s uses %s, nothing would be cached', $cache, $backend);
                    }
                }
                return $failures;
            },
            'SYS.defaultScheme' => fn(array $c): array => ($c['SYS']['defaultScheme'] ?? '') === 'https'
                ? []
                : [sprintf("is '%s', expected 'https'", $c['SYS']['defaultScheme'] ?? '')],
            'SYS.devIPmask' => function (array $c): array {
                $value = (string)($c['SYS']['devIPmask'] ?? '');
                return !$this->matchesEveryAddress($value)
                    ? []
                    : [sprintf("is '%s', which matches every client address; use explicit IPs instead", $value)];
            },
            'SYS.displayErrors' => function (array $c): array {
                $value = (int)($c['SYS']['displayErrors'] ?? -1);
                if ($value === 0) {
                    return [];
                }
                if ($value === -1 && !$this->matchesEveryAddress((string)($c['SYS']['devIPmask'] ?? ''))) {
                    return [];
                }
                return [sprintf('is %d, expected 0 (or -1 together with a devIPmask that does not match every address)', $value)];
            },
            'SYS.features.security' => function (array $c): array {
                $features = $c['SYS']['features'] ?? [];
                $failures = [];
                if (!$this->isTruthy($features['security.backend.enforceReferrer'] ?? true)) {
                    $failures[] = 'security.backend.enforceReferrer is disabled, expected true';
                }
                foreach (['security.frontend.allowInsecureSiteResolutionByQueryParameters', 'security.frontend.allowInsecureFrameOptionInShowImageController'] as $feature) {
                    if ($this->isTruthy($features[$feature] ?? false)) {
                        $failures[] = sprintf('%s is enabled, expected false', $feature);
                    }
                }
                return $failures;
            },
            'SYS.productionExceptionHandler' => function (array $c): array {
                $value = ltrim((string)($c['SYS']['productionExceptionHandler'] ?? ''), '\\');
                return str_ends_with($value, 'DebugExceptionHandler')
                    ? [sprintf("is '%s', the debug exception handler shows stack traces to every visitor", $value)]
                    : [];
            },
            'SYS.reverseProxyIP' => function (array $c): array {
                $value = (string)($c['SYS']['reverseProxyIP'] ?? '');
                return $this->matchesEveryAddress($value)
                    ? [sprintf("is '%s', every client could fake its IP via X-Forwarded-For; use the proxy IPs instead", $value)]
                    : [];
            },
            'SYS.systemMaintainers' => fn(array $c): array => empty($c['SYS']['systemMaintainers'])
                ? ['is empty, every admin is a system maintainer (Install Tool, settings)']
                : [],
            'SYS.trustedHostsPattern' => function (array $c): array {
                $value = (string)($c['SYS']['trustedHostsPattern'] ?? 'SERVER_NAME');
                if ($value === 'SERVER_NAME') {
                    return [];
                }
                // Same check as GeneralUtility::hostHeaderValueMatchesTrustedHostsPattern()
                $matchesAnyHost = @preg_match('/^' . $value . '$/i', 'attacker.invalid') === 1;
                return $matchesAnyHost ? [sprintf("is '%s', which accepts any host header", $value)] : [];
            },
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no', 'bool(false)'], true);
        }
        return (bool)$value;
    }

    private function matchesEveryAddress(string $ipList): bool
    {
        foreach (explode(',', $ipList) as $entry) {
            if (in_array(trim($entry), self::MATCH_ALL_IP_ENTRIES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Flatten a configuration array to [dotted path => scalar value]
     *
     * @return array<string, mixed>
     */
    private function flatten(mixed $configuration, string $path): array
    {
        if (!is_array($configuration)) {
            return [$path => $configuration];
        }
        $values = [];
        foreach ($configuration as $key => $value) {
            $values += $this->flatten($value, $path . '.' . $key);
        }
        return $values;
    }

    /**
     * Find all "writerConfiguration" arrays below LOG
     *
     * @return array<string, array> [dotted path => writerConfiguration]
     */
    private function findLogWriterConfigurations(mixed $configuration, string $path): array
    {
        if (!is_array($configuration)) {
            return [];
        }
        $found = [];
        foreach ($configuration as $key => $value) {
            if ($key === 'writerConfiguration' && is_array($value)) {
                $found[$path . '.' . $key] = $value;
            } else {
                $found += $this->findLogWriterConfigurations($value, $path . '.' . $key);
            }
        }
        return $found;
    }

    /**
     * Run a shell command, print it like a CI log line and return its output (stdout and stderr).
     *
     * @return string[]
     */
    private function run(string $command, ?int &$exitCode = null): array
    {
        $this->printMessage('$ ' . $command);
        $output = [];
        exec($command . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->printMessage("  exit code {$exitCode}", self::ERROR_COLOR);
        }
        return $output;
    }

    private function addError(string $message): void
    {
        $this->printMessage('Error: ' . $message, self::ERROR_COLOR);
    }

    private function printMessage(string $message, string $color = self::INFO_COLOR): void
    {
        echo $color . $message . self::RESET_COLOR . PHP_EOL;
    }

}

$checker = new SettingsChecker();
if ($checker->validateEnvironment($argv)) {
    exit($checker->check());
}
exit(SettingsChecker::EXIT_FAILED);
