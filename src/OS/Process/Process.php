<?php

declare(strict_types=1);

namespace DexproSolutionsGmbh\PhpCommons\OS\Process;

use Generator;

class Process
{
    public string $name;

    /** @var resource */
    public $stdoutStream;

    /** @var resource */
    public $stderrStream;

    public int $exitCode;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->stdoutStream = fopen('php://temp', 'rw+');
        $this->stderrStream = fopen('php://temp', 'rw+');
    }

    public function __destruct()
    {
        fclose($this->stdoutStream);
        fclose($this->stderrStream);
    }

    /**
     * @return Generator<int, string, mixed, void>
     */
    public function iterStdoutLines(): Generator
    {
        while (true) {
            $line = stream_get_line($this->stdoutStream, PHP_INT_MAX, "\n");
            if ($line === false) {
                break;
            }
            yield rtrim($line, "\r");
        }
    }

    /**
     * @return Generator<int, string, mixed, void>
     */
    public function iterStderrLines(): Generator
    {
        while (true) {
            $line = stream_get_line($this->stderrStream, PHP_INT_MAX, "\n");
            if ($line === false) {
                break;
            }
            yield rtrim($line, "\r");
        }
    }
}
