<?php

declare(strict_types=1);

/**
 * qa — test-intelligence CLI. Dependency-free PHP; runs in the tester/mcp
 * containers (or anywhere with PHP 8.2+ and git).
 *
 *   php qa/qa.php steps                       registry overview
 *   php qa/qa.php matrix [--strict]           step → guarding tests (+ last results); writes docs/TRACEABILITY.md
 *   php qa/qa.php record                      learn from the latest JUnit reports → qa/history.json, quarantine files
 *   php qa/qa.php plan [--base=origin/main]   changed files → impacted steps → minimal, failure-first test selection
 *   php qa/qa.php gaps                        routes / source files / steps nothing guards (exit 1 if any)
 *   php qa/qa.php scaffold --step=S4 --suite=regression [--app=frontend] --name=LikesCest
 *   php qa/qa.php shards --suite=e2e --n=3    duration-balanced shard groups from history
 *   php qa/qa.php history [--top=15]          slowest / flakiest / most failing tests
 *
 * Everything derives from qa/steps.php and the @group step-<ID> tags in tests.
 */

const ROOT = __DIR__ . '/..';

/** suite => [codecept project, suite name, test dir] */
const SUITES = [
    'unit' => [['common', 'Unit', 'common/tests/Unit']],
    'regression' => [['frontend', 'Regression', 'frontend/tests/Regression'], ['backend', 'Regression', 'backend/tests/Regression']],
    'smoke' => [['blackbox', 'Smoke', 'blackbox/tests/Smoke']],
    'e2e' => [['blackbox', 'E2E', 'blackbox/tests/E2E']],
];

/** Where application code lives (scanned by `gaps`). */
const SOURCE_DIRS = [
    'common/models', 'common/services', 'common/realtime', 'common/helpers', 'common/demo', 'common/assets',
    'frontend/controllers', 'frontend/components', 'frontend/views', 'frontend/web/js', 'frontend/web/css',
    'backend/controllers', 'backend/views', 'backend/web/js', 'backend/web/css',
    'console/controllers', 'console/migrations',
];

exit(main($argv));

function main(array $argv): int
{
    $cmd = $argv[1] ?? 'help';
    $opts = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $arg, $m)) {
            $opts[$m[1]] = $m[2] ?? true;
        }
    }

    return match ($cmd) {
        'steps' => cmdSteps(),
        'matrix' => cmdMatrix($opts),
        'record' => cmdRecord(),
        'plan' => cmdPlan($opts),
        'gaps' => cmdGaps(),
        'scaffold' => cmdScaffold($opts),
        'shards' => cmdShards($opts),
        'history' => cmdHistory($opts),
        default => usage(),
    };
}

function usage(): int
{
    $src = (string) file_get_contents(__FILE__);
    preg_match('#/\*\*(.*?)\*/#s', $src, $m);
    echo preg_replace('/^\s*\* ?/m', '', trim($m[1])), "\n";

    return 0;
}

// ---------------------------------------------------------------- model

function steps(): array
{
    $steps = require __DIR__ . '/steps.php';
    foreach ($steps as $id => &$s) {
        $s['group'] ??= 'step-' . $id;
        $s['routes'] ??= [];
        $s['paths'] ??= [];
        $s['require'] ??= [];
    }

    return $steps;
}

/**
 * Every test method with its suite, file and @group tags (class + method level).
 *
 * @return array<string, array{suite:string, project:string, file:string, class:string, method:string, groups:list<string>, incomplete:bool}>
 */
function tests(): array
{
    $out = [];
    foreach (SUITES as $suite => $parts) {
        foreach ($parts as [$project, , $dir]) {
            foreach (rglob(ROOT . '/' . $dir, '*.php') as $file) {
                $src = (string) file_get_contents($file);
                if (!preg_match('/^namespace\s+([\w\\\\]+);/m', $src, $ns)
                    || !preg_match('/((?:\/\*\*(?:(?!\*\/).)*\*\/\s*)?)(?:final\s+|abstract\s+)?class\s+(\w+(?:Cest|Test))\b/s', $src, $cls)) {
                    continue;
                }
                $classGroups = groupsIn($cls[1]);
                $isUnit = str_ends_with($cls[2], 'Test');
                preg_match_all('/((?:\/\*\*(?:(?!\*\/).)*\*\/\s*)?)public function (\w+)\s*\(([^)]*)\)/s', $src, $ms, PREG_SET_ORDER);
                foreach ($ms as [, $doc, $method, $params]) {
                    if ($isUnit ? !str_starts_with($method, 'test') : (str_starts_with($method, '_') || !str_contains($params, 'Tester'))) {
                        continue;
                    }
                    $body = substr($src, strpos($src, "function {$method}("), 600);
                    $key = $ns[1] . '\\' . $cls[2] . '::' . $method;
                    $out[$key] = [
                        'suite' => $suite,
                        'project' => $project,
                        'file' => rel($file),
                        'class' => $ns[1] . '\\' . $cls[2],
                        'method' => $method,
                        'groups' => array_values(array_unique(array_merge($classGroups, groupsIn($doc)))),
                        'incomplete' => (bool) preg_match('/markTestIncomplete|TODO\(qa-scaffold\)/', $body),
                    ];
                }
            }
        }
    }
    ksort($out);

    return $out;
}

function groupsIn(string $doc): array
{
    preg_match_all('/@group\s+([\w-]+)/', $doc, $m);

    return $m[1];
}

/**
 * Latest JUnit results, keyed like tests(): Class::method => pass|fail|skip.
 *
 * @return array<string, array{status:string, time:float}>
 */
function results(): array
{
    $out = [];
    foreach (glob(ROOT . '/*/tests/Support/output/qa-*.xml') ?: [] as $xmlFile) {
        $xml = @simplexml_load_file($xmlFile);
        if ($xml === false) {
            continue;
        }
        foreach ($xml->xpath('//testcase') as $tc) {
            $method = preg_replace('/ with data set .*$/', '', (string) $tc['name']);
            $key = (string) $tc['class'] . '::' . $method;
            $status = isset($tc->failure) || isset($tc->error) ? 'fail' : (isset($tc->skipped) ? 'skip' : 'pass');
            $prev = $out[$key] ?? ['status' => 'pass', 'time' => 0.0];
            $out[$key] = [
                'status' => $prev['status'] === 'fail' ? 'fail' : $status,
                'time' => $prev['time'] + (float) $tc['time'],
            ];
        }
    }

    return $out;
}

// ---------------------------------------------------------------- commands

function cmdSteps(): int
{
    foreach (steps() as $id => $s) {
        printf("%-7s %s\n        group: %s · required suites: %s\n", $id, $s['title'], $s['group'], implode(', ', $s['require']));
    }

    return 0;
}

function matrix(): array
{
    $tests = tests();
    $results = results();
    $rows = [];
    foreach (steps() as $id => $s) {
        $bySuite = [];
        foreach ($tests as $key => $t) {
            if (in_array($s['group'], $t['groups'], true)) {
                $status = $t['incomplete'] ? 'incomplete' : ($results[$key]['status'] ?? 'not-run');
                $bySuite[$t['suite']][] = ['key' => $key, 'method' => $t['method'], 'class' => basename(str_replace('\\', '/', $t['class'])), 'status' => $status];
            }
        }
        $missing = [];
        foreach ($s['require'] as $suite) {
            $real = array_filter($bySuite[$suite] ?? [], static fn ($t) => $t['status'] !== 'incomplete');
            if ($real === []) {
                $missing[] = $suite;
            }
        }
        $failing = [];
        foreach ($bySuite as $list) {
            foreach ($list as $t) {
                if ($t['status'] === 'fail') {
                    $failing[] = $t['key'];
                }
            }
        }
        $rows[$id] = ['step' => $s, 'suites' => $bySuite, 'missing' => $missing, 'failing' => $failing];
    }

    return $rows;
}

function cmdMatrix(array $opts): int
{
    $rows = matrix();
    $icon = ['pass' => '✅', 'fail' => '❌', 'skip' => '⏭', 'not-run' => '·', 'incomplete' => '🚧'];
    $md = "# Traceability matrix\n\n"
        . "_Generated by `php qa/qa.php matrix` from `qa/steps.php`, the `@group step-*` tags in the tests and the latest JUnit reports. Do not edit by hand._\n\n"
        . "Legend: ✅ passed in the last recorded run · ❌ failed · · not run yet · 🚧 scaffolded, not implemented\n\n";
    $bad = 0;
    foreach ($rows as $id => $r) {
        $md .= "## {$id} — {$r['step']['title']}\n\n";
        $md .= '| Suite | Guarding tests |' . "\n|---|---|\n";
        foreach (['unit', 'smoke', 'regression', 'e2e'] as $suite) {
            $list = $r['suites'][$suite] ?? [];
            $required = in_array($suite, $r['step']['require'], true);
            if ($list === [] && !$required) {
                continue;
            }
            $cells = $list === [] ? '**MISSING (required)**' : implode('<br>', array_map(
                static fn ($t) => $icon[$t['status']] . ' `' . $t['class'] . '::' . $t['method'] . '`',
                $list,
            ));
            $md .= '| ' . $suite . ($required ? ' *' : '') . " | {$cells} |\n";
        }
        $md .= "\n";
        if ($r['missing'] || $r['failing']) {
            $bad++;
        }
        printf(
            "%-7s %-3s %s%s%s\n",
            $id,
            ($r['missing'] || $r['failing']) ? '✗' : '✓',
            implode(' ', array_map(static fn ($s, $l) => $s . ':' . count($l), array_keys($r['suites']), $r['suites'])),
            $r['missing'] ? '   MISSING in: ' . implode(', ', $r['missing']) : '',
            $r['failing'] ? '   FAILING: ' . count($r['failing']) : '',
        );
    }
    $md .= "\\* = suite required for this step by `qa/steps.php`.\n";
    @mkdir(ROOT . '/docs');
    file_put_contents(ROOT . '/docs/TRACEABILITY.md', $md);
    echo "\nwrote docs/TRACEABILITY.md\n";

    return ($bad > 0 && isset($opts['strict'])) ? 1 : 0;
}

function cmdRecord(): int
{
    $results = results();
    if ($results === []) {
        fwrite(STDERR, "no JUnit reports found (run suites through docker/tester/run.sh)\n");

        return 1;
    }
    $tests = tests();
    $historyFile = __DIR__ . '/history.json';
    $history = is_file($historyFile) ? json_decode((string) file_get_contents($historyFile), true) : [];
    $history['tests'] ??= [];
    $rev = revision();

    foreach ($results as $key => $r) {
        $h = $history['tests'][$key] ?? ['runs' => 0, 'fails' => 0, 'avg_time' => null, 'trail' => '', 'revs' => []];
        $h['suite'] = $tests[$key]['suite'] ?? ($h['suite'] ?? '?');
        $h['file'] = $tests[$key]['file'] ?? ($h['file'] ?? null);
        $h['runs']++;
        $letter = ['pass' => 'P', 'fail' => 'F', 'skip' => 'S'][$r['status']];
        $h['fails'] += $letter === 'F' ? 1 : 0;
        $h['trail'] = substr($h['trail'] . $letter, -30);
        $h['avg_time'] = $h['avg_time'] === null ? round($r['time'], 3) : round(0.7 * $h['avg_time'] + 0.3 * $r['time'], 3);
        $h['last'] = $r['status'];
        if ($letter === 'F') {
            $h['last_failed_at'] = gmdate('c');
        }
        // Flaky = both outcomes observed on the SAME code revision.
        $h['revs'][$rev] = ($h['revs'][$rev] ?? '') . $letter;
        $h['revs'] = array_slice($h['revs'], -10, null, true);
        $h['flaky'] = false;
        foreach ($h['revs'] as $outcomes) {
            if (str_contains($outcomes, 'P') && str_contains($outcomes, 'F')) {
                $h['flaky'] = true;
            }
        }
        $history['tests'][$key] = $h;
    }
    $history['updated_at'] = gmdate('c');
    ksort($history['tests']);
    file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    // Quarantine: flaky tests leave the blocking gate but keep running (non-blocking).
    $quarantine = [];
    foreach ($history['tests'] as $key => $h) {
        if (($h['flaky'] ?? false) && isset($tests[$key])) {
            $t = $tests[$key];
            $quarantine[$t['project']][] = substr($t['file'], strlen($t['project']) + 1) . ':' . $t['method'];
        }
    }
    foreach (['common', 'frontend', 'backend', 'blackbox'] as $project) {
        $lines = $quarantine[$project] ?? [];
        file_put_contents(__DIR__ . "/quarantine/{$project}.txt", $lines ? implode("\n", $lines) . "\n" : '');
    }
    printf("recorded %d results at revision %s; %d flaky test(s) quarantined\n", count($results), $rev, array_sum(array_map('count', $quarantine)));

    return 0;
}

function cmdPlan(array $opts): int
{
    $base = is_string($opts['base'] ?? null) ? $opts['base'] : null;
    $changed = changedFiles($base);
    if ($changed === []) {
        echo "No changes" . ($base ? " vs {$base}" : '') . " — nothing to plan.\n";

        return 0;
    }

    $steps = steps();
    $tests = tests();
    $impacted = [];
    $testFiles = [];
    $unmapped = [];
    foreach ($changed as $file) {
        if (preg_match('#^(common|frontend|backend|blackbox)/tests/#', $file)) {
            $testFiles[] = $file;
            continue;
        }
        if (!preg_match('#^(common|frontend|backend|console)/#', $file) || str_ends_with($file, '.md')) {
            continue;
        }
        $hit = false;
        foreach ($steps as $id => $s) {
            foreach ($s['paths'] as $glob) {
                if (fnmatch($glob, $file)) {
                    $impacted[$id] = true;
                    $hit = true;
                }
            }
        }
        if (!$hit) {
            $unmapped[] = $file;
        }
    }

    echo 'Changed files: ' . count($changed) . ($base ? " (vs {$base})" : ' (working tree vs HEAD)') . "\n";
    echo 'Impacted steps: ' . ($impacted ? implode(', ', array_keys($impacted)) : 'none') . "\n";
    if ($unmapped) {
        echo "\n⚠ Not mapped to any step (add them to qa/steps.php — until then run the FULL suites):\n  - " . implode("\n  - ", $unmapped) . "\n";
    }

    $groups = array_map(static fn ($id) => $steps[$id]['group'], array_keys($impacted));
    $suites = [];
    foreach ($tests as $t) {
        if (array_intersect($groups, $t['groups']) || in_array($t['file'], $testFiles, true)) {
            $suites[$t['suite']] = true;
        }
    }

    $history = historyTests();
    $priority = array_keys(array_filter($history, static fn ($h) => ($h['last'] ?? '') === 'fail' || ($h['flaky'] ?? false)));

    echo "\nRun (cheapest first):\n";
    $args = implode(' ', array_map(static fn ($g) => '-g ' . $g, $groups));
    foreach (['unit', 'smoke', 'regression', 'e2e'] as $suite) {
        if ($unmapped) {
            echo "  docker compose run --rm tester {$suite}\n";
        } elseif (isset($suites[$suite])) {
            echo "  docker compose run --rm tester {$suite} {$args}\n";
        }
    }
    if (!$unmapped && !isset($suites['smoke'])) {
        echo "  docker compose run --rm tester smoke          # always: the 10-second gate\n";
    }
    if ($priority) {
        echo "\nRecently failing / flaky — look at these first:\n  - " . implode("\n  - ", array_slice($priority, 0, 10)) . "\n";
    }

    return 0;
}

function cmdGaps(): int
{
    $steps = steps();
    $gaps = 0;

    // 1. Web actions that exist in code but in no step.
    $known = [];
    foreach ($steps as $id => $s) {
        foreach ($s['routes'] as $r) {
            $known[$r] = $id;
        }
    }
    $actual = discoverRoutes();
    $newRoutes = array_diff($actual, array_keys($known));
    $staleRoutes = array_diff(array_keys($known), $actual);

    // 2. Source files covered by no step path.
    $ignore = require __DIR__ . '/unguarded.php';
    $unmapped = [];
    foreach (SOURCE_DIRS as $dir) {
        foreach (rglob(ROOT . '/' . $dir, '*') as $abs) {
            $file = rel($abs);
            if (!preg_match('/\.(php|js|css)$/', $file) || matchesAny($file, array_keys($ignore))) {
                continue;
            }
            $hit = false;
            foreach ($steps as $s) {
                if (matchesAny($file, $s['paths'])) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $unmapped[] = $file;
            }
        }
    }

    // 3. Steps lacking a required suite.
    $missing = [];
    foreach (matrix() as $id => $r) {
        foreach ($r['missing'] as $suite) {
            $missing[] = "{$id} has no {$suite} test (required)";
        }
    }

    foreach ([
        'Routes with no step (new feature? add to qa/steps.php)' => $newRoutes,
        'Registry routes that no longer exist (remove them)' => $staleRoutes,
        'Source files guarded by no step' => $unmapped,
        'Steps missing a required suite' => $missing,
    ] as $title => $list) {
        echo ($list ? '✗ ' : '✓ ') . $title . ($list ? ':' : '') . "\n";
        foreach ($list as $item) {
            echo "    - {$item}\n";
        }
        $gaps += count($list);
    }
    echo $gaps ? "\n{$gaps} gap(s). Close them: php qa/qa.php scaffold --step=<ID> --suite=<suite> --name=<Name>\n" : "\nNo gaps.\n";

    return $gaps ? 1 : 0;
}

function cmdScaffold(array $opts): int
{
    $step = $opts['step'] ?? null;
    $suite = $opts['suite'] ?? null;
    $name = $opts['name'] ?? null;
    $steps = steps();
    if (!is_string($step) || !isset($steps[$step]) || !is_string($suite) || !isset(SUITES[$suite]) || !is_string($name) || !preg_match('/^[A-Z]\w+$/', $name)) {
        fwrite(STDERR, "usage: scaffold --step=<" . implode('|', array_keys($steps)) . "> --suite=<unit|regression|smoke|e2e> --name=<ClassName> [--app=frontend|backend]\n");

        return 64;
    }
    $parts = SUITES[$suite];
    $part = $parts[0];
    if ($suite === 'regression') {
        $app = $opts['app'] ?? null;
        $part = $app === 'backend' ? $parts[1] : ($app === 'frontend' ? $parts[0] : null);
        if ($part === null) {
            fwrite(STDERR, "regression needs --app=frontend|backend\n");

            return 64;
        }
    }
    [$project, $suiteName, $dir] = $part;
    $isUnit = $suite === 'unit';
    $class = $isUnit ? (str_ends_with($name, 'Test') ? $name : $name . 'Test') : (str_ends_with($name, 'Cest') ? $name : $name . 'Cest');
    $file = ROOT . "/{$dir}/{$class}.php";
    if (is_file($file)) {
        fwrite(STDERR, rel($file) . " already exists\n");

        return 1;
    }
    $ns = ($project === 'blackbox' ? 'blackbox\\tests\\' : $project . '\\tests\\') . $suiteName;
    $actor = ($project === 'blackbox' ? 'blackbox\\tests\\' : $project . '\\tests\\') . 'Support\\' . $suiteName . 'Tester';
    $group = $steps[$step]['group'];
    $title = $steps[$step]['title'];

    $code = $isUnit ? <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Codeception\\Test\\Unit;

/**
 * {$step} — {$title}
 *
 * @group {$group}
 */
final class {$class} extends Unit
{
    public function testBehaviour(): void
    {
        // TODO(qa-scaffold): arrange / act / assert. Counts as NOT guarding the step until implemented.
        \$this->markTestIncomplete('scaffolded by qa/qa.php');
    }
}

PHP : <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use {$actor};

/**
 * {$step} — {$title}
 *
 * @group {$group}
 */
final class {$class}
{
    public function behaviour({$suiteName}Tester \$I): void
    {
        // TODO(qa-scaffold): drive the behaviour and assert it. Counts as NOT guarding the step until implemented.
        \$I->markTestIncomplete('scaffolded by qa/qa.php');
    }
}

PHP;
    file_put_contents($file, $code);
    echo 'created ' . rel($file) . " (group {$group}). Run: docker compose run --rm tester {$suite} -g {$group}\n";

    return 0;
}

function cmdShards(array $opts): int
{
    $suite = $opts['suite'] ?? null;
    $n = (int) ($opts['n'] ?? 0);
    if (!is_string($suite) || !isset(SUITES[$suite]) || $n < 2) {
        fwrite(STDERR, "usage: shards --suite=<unit|regression|smoke|e2e> --n=<2..>\n");

        return 64;
    }
    $history = historyTests();
    $files = [];
    foreach (tests() as $key => $t) {
        if ($t['suite'] === $suite) {
            $files[$t['project']][$t['file']] = ($files[$t['project']][$t['file']] ?? 0) + ($history[$key]['avg_time'] ?? null ?? 1.0);
        }
    }
    foreach ($files as $project => $durations) {
        arsort($durations);
        $bins = array_fill(1, $n, ['load' => 0.0, 'files' => []]);
        foreach ($durations as $file => $d) { // LPT: longest job to the least-loaded shard
            uasort($bins, static fn ($a, $b) => $a['load'] <=> $b['load']);
            $k = array_key_first($bins);
            $bins[$k]['load'] += $d;
            $bins[$k]['files'][] = substr($file, strlen($project) + 1);
        }
        ksort($bins);
        @mkdir(__DIR__ . "/shards/{$project}", 0777, true);
        foreach (glob(__DIR__ . "/shards/{$project}/qashard_{$suite}_*") ?: [] as $old) {
            unlink($old);
        }
        foreach ($bins as $k => $bin) {
            file_put_contents(__DIR__ . "/shards/{$project}/qashard_{$suite}_{$k}", implode("\n", $bin['files']) . "\n");
            printf("%s shard %d: %5.1fs  %d file(s)\n", $project, $k, $bin['load'], count($bin['files']));
        }
    }
    echo "\nRun each shard in its own container, each with its own database:\n";
    for ($k = 1; $k <= $n; $k++) {
        echo "  docker compose run --rm -e QA_WORKER=w{$k} tester {$suite} -g qashard_{$suite}_{$k} &\n";
    }
    echo "  wait\n";

    return 0;
}

function cmdHistory(array $opts): int
{
    $top = (int) ($opts['top'] ?? 10);
    $h = historyTests();
    if ($h === []) {
        echo "No history yet — run suites via docker/tester/run.sh (it records automatically).\n";

        return 0;
    }
    $show = static function (string $title, array $list) use ($top): void {
        echo "\n{$title}\n";
        foreach (array_slice($list, 0, $top, true) as $key => $line) {
            echo "  {$line}  {$key}\n";
        }
    };
    $slow = $h;
    uasort($slow, static fn ($a, $b) => ($b['avg_time'] ?? 0) <=> ($a['avg_time'] ?? 0));
    $show('Slowest (EWMA seconds):', array_map(static fn ($x) => sprintf('%6.2fs', $x['avg_time']), $slow));
    $flaky = array_filter($h, static fn ($x) => $x['flaky'] ?? false);
    $show('Flaky (quarantined):', array_map(static fn ($x) => str_pad($x['trail'], 12), $flaky));
    $failing = array_filter($h, static fn ($x) => $x['fails'] > 0);
    uasort($failing, static fn ($a, $b) => $b['fails'] <=> $a['fails']);
    $show('Most failing:', array_map(static fn ($x) => sprintf('%2d/%-3d %s', $x['fails'], $x['runs'], $x['trail']), $failing));

    return 0;
}

// ---------------------------------------------------------------- helpers

function historyTests(): array
{
    $f = __DIR__ . '/history.json';

    return is_file($f) ? (json_decode((string) file_get_contents($f), true)['tests'] ?? []) : [];
}

function discoverRoutes(): array
{
    $routes = [];
    foreach (['frontend', 'backend'] as $app) {
        foreach (glob(ROOT . "/{$app}/controllers/*Controller.php") ?: [] as $file) {
            $id = kebab(substr(basename($file), 0, -strlen('Controller.php')));
            $src = (string) file_get_contents($file);
            preg_match_all('/public function action(\w+)\s*\(/', $src, $m);
            foreach ($m[1] as $action) {
                if ($action !== 's') {
                    $routes[] = "{$app}:{$id}/" . kebab($action);
                }
            }
            if (preg_match("/'error'\s*=>\s*\[\s*'class'\s*=>\s*ErrorAction/", $src)) {
                $routes[] = "{$app}:{$id}/error";
            }
        }
    }
    sort($routes);

    return $routes;
}

function changedFiles(?string $base): array
{
    $cmds = $base
        ? ['git diff --name-only ' . escapeshellarg($base) . '...HEAD', 'git diff --name-only HEAD', 'git ls-files --others --exclude-standard']
        : ['git diff --name-only HEAD', 'git ls-files --others --exclude-standard'];
    $files = [];
    foreach ($cmds as $cmd) {
        exec('cd ' . escapeshellarg(ROOT) . ' && ' . $cmd . ' 2>/dev/null', $lines);
        $files = array_merge($files, $lines);
        $lines = [];
    }

    return array_values(array_unique(array_filter($files)));
}

function revision(): string
{
    $rev = getenv('QA_REVISION') ?: trim((string) @shell_exec('cd ' . escapeshellarg(ROOT) . ' && git rev-parse --short HEAD 2>/dev/null'));
    if ($rev === '') {
        return 'unknown';
    }
    $dirty = trim((string) @shell_exec('cd ' . escapeshellarg(ROOT) . ' && git diff --quiet HEAD -- . ":!qa" ":!docs" 2>/dev/null || echo dirty'));

    return $dirty === 'dirty' ? $rev . '+' . substr(md5((string) shell_exec('cd ' . escapeshellarg(ROOT) . ' && git diff HEAD -- . ":!qa" ":!docs"')), 0, 6) : $rev;
}

function kebab(string $s): string
{
    return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $s));
}

function matchesAny(string $file, array $globs): bool
{
    foreach ($globs as $g) {
        if (fnmatch($g, $file)) {
            return true;
        }
    }

    return false;
}

function rglob(string $dir, string $pattern): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && fnmatch($pattern, $f->getFilename())) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
}

function rel(string $abs): string
{
    $root = realpath(ROOT);

    return ltrim(str_replace('\\', '/', substr(realpath($abs) ?: $abs, strlen((string) $root))), '/');
}
