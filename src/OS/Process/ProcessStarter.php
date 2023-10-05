<?php

declare(strict_types=1);

namespace DexproSolutionsGmbh\PhpCommons\OS\Process;

use DexproSolutionsGmbh\PhpCommons\OS\OS;
use Exception;
use InvalidArgumentException;

/**
 * Utility for launching CLI programs
 *
 * This implements a builder-like interface. The implementation is so that you
 * can not change an existing instance (immutability guarantee), so that an
 * instance can be provided as shared service.
 */
class ProcessStarter
{
    private string $pathEnvKey;

    private string $name;

    private ?string $workingDirectory = null;

    /**
     * @var array<string, string|null>
     */
    private array $environment = [];

    private ?string $executable = null;

    /** @var string[] */
    private array $defaultArgs = [];

    private bool $combinedOutput = false;

    /**
     * @var int[]
     */
    private array $expectedExitCodes = [0];

    /**
     * private constructor, use the `forProgram` method below.
     */
    private function __construct(string $name)
    {
        // determine which path variable should be used
        if (OS::isLinux()) {
            $this->pathEnvKey = 'PATH';
        } else {
            $this->pathEnvKey = 'Path';
        }

        $this->name = $name;
    }

    /**
     * factory method
     *
     * The name is used as default name for the executable.
     */
    public static function forProgram(string $name): ProcessStarter
    {
        if (! function_exists('proc_open')) {
            throw new Exception('proc_open does not exist or is disabled');
        }

        return new ProcessStarter($name);
    }

    /**
     * configure the working directory
     *
     * When set to null, the parent's working directory is inherited by the
     * child process.
     *
     * @return static
     */
    public function withWorkingDirectory(?string $workingDirectory): self
    {
        if ($this->workingDirectory = $workingDirectory) {
            return $this;
        }

        $other = clone $this;
        $other->workingDirectory = $workingDirectory;

        return $other;
    }

    /**
     * set or delete an environment variable
     *
     * @return static
     */
    public function withEnvironmentVariable(string $name, ?string $value): self
    {
        $other = clone $this;
        $other->environment[$name] = $value;

        return $other;
    }

    /**
     * This method prepends the given value to the PATH variable, so that applications using this PATH variable will take the first executable that match.
     * e.g. /some/path/to/executable(priority 1)  /some/some/path/to/executable(not considered if same)
     *
     * Notice: If you want to unset the PATH environment variable or want to override it use {@link self::withEnvironmentVariable()}
     */
    public function withAdditionalPathVariable(string $value): ProcessStarter
    {
        $other = clone $this;
        $separator = OS::isWindows() ? ';' : ':';

        if (isset($other->environment[$this->pathEnvKey])) {
            $other->environment[$this->pathEnvKey] = $value.$separator.$this->environment[$this->pathEnvKey];
        } else {
            $PATH = getenv($this->pathEnvKey);
            if (! $PATH) {
                $PATH = '';
            }
            $other->environment[$this->pathEnvKey] = $value.$separator.$PATH;
        }

        return $other;
    }

    /**
     * set the executable
     *
     * Without the executable, the name of the program and the `PATH` environment
     * variable is used to locate the executable.
     *
     * @return static
     */
    public function withExecutable(?string $executable): self
    {
        if ($executable === $this->executable) {
            return $this;
        }

        // if an executable is specified, validate it
        if ($executable !== null) {
            if (! file_exists($executable)) {
                throw new Exception('path "'.$executable.'" does not exist');
            }
            if (! is_executable($executable)) {
                throw new Exception('file "'.$executable.'" is not executable');
            }
        }

        $other = clone $this;
        $other->executable = $executable;

        return $other;
    }

    /**
     * set the default arguments for the program
     *
     * All other arguments are passed to the `run()` method.
     *
     *
     * @param  int|string  ...$defaultArgs
     * @return static
     */
    public function withDefaultArguments(...$defaultArgs): self
    {
        $defaultArgs = $this->normalizeArgs($defaultArgs);

        if ($this->defaultArgs === $defaultArgs) {
            return $this;
        }

        $other = clone $this;
        $other->defaultArgs = $defaultArgs;

        return $other;
    }

    /**
     * configure whether the output is captured in a single stream
     *
     * On the shell, you would use `2>&1` to feed stderr into stdout. If you
     * set this flag, the `stderr` stream of the process doesn't receive any
     * output.
     *
     * @return static
     */
    public function withCombinedOutput(bool $combinedOutput): self
    {
        if ($this->combinedOutput === $combinedOutput) {
            return $this;
        }

        $other = clone $this;
        $other->combinedOutput = $combinedOutput;

        return $other;
    }

    /**
     * configure expected exit codes
     *
     * This configures all exit codes that are not signalled as exceptions.
     *
     * @return static
     */
    public function withExpectedExitCodes(int ...$expectedExitCodes): self
    {
        if ($this->expectedExitCodes === $expectedExitCodes) {
            return $this;
        }

        $other = clone $this;
        $other->expectedExitCodes = $expectedExitCodes;

        return $other;
    }

    /**
     * run the program and return the captured output
     *
     * This throws an exception when the execution failed, which is signalled
     * by a non-zero exit code of the process.
     *
     * TODO: When it fails, attach the captured output to the exception instead.
     *
     *
     * @param  int|string  $args
     *
     * @throws Exception
     */
    public function run(...$args): Process
    {
        $args = $this->normalizeArgs($args);
        $environment = $this->resolveEnvironment();
        $executable = $this->resolveExecutablePath();

        $argv = array_merge([$executable], $this->defaultArgs, $args);

        $process = new Process($this->name);

        $process->exitCode = $this->runImpl(
            $argv,
            $process->stdoutStream,
            $this->combinedOutput ? $process->stdoutStream : $process->stderrStream,
            $this->workingDirectory,
            $environment
        );

        // prepare the streams for consumption
        rewind($process->stdoutStream);
        rewind($process->stderrStream);

        if (! in_array($process->exitCode, $this->expectedExitCodes)) {
            // Todo: The exception does not really help debugging why a subprocess might have failed. Maybe include the first X stdout/stderr lines in custom exception class?
            throw new Exception('program "'.$this->name.'" failed with error code '.$process->exitCode, $process->exitCode);
        }

        return $process;
    }

    /**
     * @param  mixed[]  $argv
     * @param  resource  $stdout
     * @param  resource  $stderr
     * @param  null|string  $workingDirectory
     * @param  array<string, string>  $environment
     */
    private function runImpl(array $argv, $stdout, $stderr, $workingDirectory, ?array $environment): int
    {
        // start process
        $process = proc_open(
            $argv,
            [
                // Note: We're not providing a stdin stream, we don't expect
                // the called program to require any input.
                // 0 => ['pipe', 'r'],
                1 => $stdout,
                2 => $stderr,
            ],
            $pipes,
            $workingDirectory,
            $environment
        );
        if ($process === false) {
            throw new Exception('failed to start process');
        }

        // wait for the process to finish
        return proc_close($process);
    }

    /**
     * utility function to type-check and normalize arguments
     *
     * @param  mixed[]  $args
     * @return string[]
     */
    private function normalizeArgs(array $args): array
    {
        $res = [];
        foreach ($args as $arg) {
            if (is_int($arg)) {
                $res[] = strval($arg);
            } elseif (is_string($arg)) {
                $res[] = $arg;
            } else {
                throw new InvalidArgumentException('invalid commandline argument type');
            }
        }

        return $res;
    }

    /**
     * resolve modifications for the process' environment
     *
     * If no modifications are required, it just returns null as a shortcut to
     * inherit the whole environment.
     *
     * @return null|array<string, string>
     */
    private function resolveEnvironment(): ?array
    {
        if ($this->environment === []) {
            return null;
        }
        $environment = getenv();
        foreach ($this->environment as $key => $value) {
            if ($value === null) {
                unset($environment[$key]);
            } else {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    /**
     * utility function to locate the executable
     *
     * This returns either the previously configured executable or uses the
     * configured name and the PATH to locate the executable.
     *
     * For MS Windows, it additionally evaluates the PATHEXT variable. This
     * contains a list of extensions that are automatically added to the file
     * name in order to locate the according executable.
     */
    private function resolveExecutablePath(): string
    {
        if ($this->executable) {
            return $this->executable;
        }

        $path = getenv($this->pathEnvKey);
        if ($path === false) {
            throw new Exception('no executable path configured and no PATH environment var set');
        }

        if (OS::isWindows()) {
            $separator = ';';
            $pathext = getenv('PATHEXT');
            if ($pathext === false) {
                $pathext = [''];
            } else {
                $pathext = [''] + explode($separator, $pathext);
            }
        } else {
            $separator = ':';
            $pathext = [''];
        }

        foreach (explode($separator, $path) as $location) {
            foreach ($pathext as $ext) {
                $fullPath = $location.DIRECTORY_SEPARATOR.$this->name.$ext;
                if (file_exists($fullPath)) {
                    return $fullPath;
                }
            }
        }

        throw new Exception('no executable named "'.$this->name.'" found in PATH');
    }
}
