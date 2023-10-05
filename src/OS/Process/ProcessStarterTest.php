<?php

declare(strict_types=1);


namespace DexproSolutionsGmbh\PhpCommons\OS\Process;

use DexproSolutionsGmbh\PhpCommons\OS\OS;
use Exception;
use PHPUnit\Framework\TestCase;

class ProcessStarterTest extends TestCase
{
    /** @var string|false */
    private static $pathext;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // set up PATHEXT environment variable
        // This should only be necessary under MS Windows. We save the current
        // value and add ".exe" to it, just to make extra sure that "php.exe"
        // is found during test execution.
        self::$pathext = getenv('PATHEXT');
        putenv('PATHEXT=.exe;' . (self::$pathext ?: ''));
    }

    public static function tearDownAfterClass(): void
    {
        // restore previous PATHEXT environment variable
        if (self::$pathext === false) {
            putenv('PATHEXT');
        } else {
            putenv('PATHEXT=' . self::$pathext);
        }

        parent::tearDownAfterClass();
    }

    public function testDefaultArguments(): void
    {
        $starter = ProcessStarter::forProgram('php')
            ->withDefaultArguments(
                '-r', 'array_shift($argv); echo(json_encode($argv));'
            );

        $process = $starter->run('--', 1, 2, 3);

        $iterator = $process->iterStdoutLines();
        $this->assertSame(['["1","2","3"]'], iterator_to_array($iterator));
    }

    public function testEnvironment(): void
    {
        $foo = uniqid('foo-');

        $starter = ProcessStarter::forProgram('php')
            ->withEnvironmentVariable('FOO', $foo);

        $process = $starter->run('-r', 'echo(getenv("FOO"));');

        $iterator = $process->iterStdoutLines();
        $this->assertSame([$foo], iterator_to_array($iterator));
    }

    public function testAdditionalPathVariable(): void
    {
        $executables = [
            '/some/random/dir/to/executable1',
            '/some/random/dir/to/executable2',
            '/some/random/dir/to/executable3',
        ];

        $starter = ProcessStarter::forProgram('php')
            ->withAdditionalPathVariable($executables[0])
            ->withAdditionalPathVariable($executables[1])
            ->withAdditionalPathVariable($executables[2]);

        $pathBeforeExecutable = getenv('PATH');
        if (!$pathBeforeExecutable) {
            $pathBeforeExecutable = '';
        }
        $process = $starter->run('-r', 'echo(getenv("PATH"));');
        $iterator = $process->iterStdoutLines();

        // build excepted result
        $separator = OS::isWindows() ? ';' : ':';
        $expectedPathVariable = implode($separator, array_reverse($executables)) . $separator . $pathBeforeExecutable;

        // assert the PATH environment variable from the child process with the expected PATH environment variable
        $this->assertSame([$expectedPathVariable], iterator_to_array($iterator));

        // assert that the PATH variable in current process is not changed by the ProcessStarter
        $this->assertSame(getenv('PATH'), $pathBeforeExecutable);
    }

    public function testExitCodeHandling1(): void
    {
        $exitCode = 3;

        $starter = ProcessStarter::forProgram('php')
            ->withExpectedExitCodes($exitCode)
            ->withDefaultArguments('-r');

        $process = $starter->run('exit(' . $exitCode . ');');

        $this->assertSame($exitCode, $process->exitCode);
    }

    public function testExitCodeHandling2(): void
    {
        $exitCode = 3;

        $starter = ProcessStarter::forProgram('php')
            ->withExpectedExitCodes($exitCode)
            ->withDefaultArguments('-r');

        $this->expectException(Exception::class);
        $starter->run('exit(' . ($exitCode + 1) . ');');
    }

    public function testOutputHandling1(): void
    {
        $starter = ProcessStarter::forProgram('php')
            // Note: Turn off xdebug, otherwise there is a warning echoed when
            // the connection doesn't work, which causes the test to fail.
            ->withEnvironmentVariable('XDEBUG_MODE', 'off')
            ->withDefaultArguments('-r');

        $process = $starter->run('file_put_contents("php://stderr", "stderr\n"); file_put_contents("php://stdout", "stdout\n");');

        $iterator = $process->iterStdoutLines();
        $this->assertSame(['stdout'], iterator_to_array($iterator));

        $iterator = $process->iterStderrLines();
        $this->assertSame(['stderr'], iterator_to_array($iterator));
    }

    public function testOutputHandling2(): void
    {
        $starter = ProcessStarter::forProgram('php')
            // Note: Turn off xdebug, otherwise there is a warning echoed when
            // the connection doesn't work, which causes the test to fail.
            ->withEnvironmentVariable('XDEBUG_MODE', 'off')
            ->withCombinedOutput(true)
            ->withDefaultArguments('-r');

        $process = $starter->run('file_put_contents("php://stderr", "stderr\n"); file_put_contents("php://stdout", "stdout\n");');

        $iterator = $process->iterStdoutLines();
        $this->assertSame(['stderr', 'stdout'], iterator_to_array($iterator));

        $iterator = $process->iterStderrLines();
        $this->assertSame([], iterator_to_array($iterator));
    }

    public function testWorkingDirectory(): void
    {
        $dir = dirname(__DIR__);

        $starter = ProcessStarter::forProgram('php')
            ->withWorkingDirectory($dir)
            ->withDefaultArguments('-r');

        $process = $starter->run('echo getcwd();');

        $iterator = $process->iterStdoutLines();
        $this->assertSame([$dir], iterator_to_array($iterator));
    }
}
