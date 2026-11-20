<?php

declare(strict_types=1);

namespace Apollo\Tests\Http;

use Apollo\Http\ApiController;
use Apollo\Http\RateLimiter;
use Apollo\Simulation\LazySessionStore;
use Apollo\Simulation\SessionStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private const START = '{"action":"start","tab":"0123456789abcdef","chapter":"launch","scenario":false}';
    private const TICK = '{"action":"tick","tab":"0123456789abcdef","keys":[],"rod":0,"speed":1}';

    private string $directory;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/apollo-rate-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->directory, fn (): int => $this->now);
    }

    public function testAClientGetsItsLimitPerWindowAndAFreshCountInTheNextWindow(): void
    {
        $limiter = $this->limiter();

        self::assertTrue($limiter->allow('request', '203.0.113.7', 2));
        self::assertTrue($limiter->allow('request', '203.0.113.7', 2));
        self::assertFalse($limiter->allow('request', '203.0.113.7', 2));

        $this->now += RateLimiter::WINDOW_SECONDS;

        self::assertTrue($limiter->allow('request', '203.0.113.7', 2));
    }

    public function testClientsAndBucketsAreCountedApart(): void
    {
        $limiter = $this->limiter();
        $limiter->allow('request', '203.0.113.7', 1);

        self::assertFalse($limiter->allow('request', '203.0.113.7', 1));
        self::assertTrue($limiter->allow('request', '203.0.113.8', 1));
        self::assertTrue($limiter->allow('start', '203.0.113.7', 1));
    }

    public function testWhatTheClientSentNeverReachesAFileName(): void
    {
        $this->limiter()->allow('request', '../../etc/passwd', 1);

        $files = array_map(basename(...), glob($this->directory . '/*') ?: []);
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\.rate\z/', $files[0]);
    }

    public function testASweepRemovesTheCountersOfPassedWindows(): void
    {
        $limiter = $this->limiter();
        $limiter->allow('request', '203.0.113.7', 1);
        $file = (glob($this->directory . '/*.rate') ?: [])[0];
        touch($file, $this->now);

        $this->now += RateLimiter::WINDOW_SECONDS;
        $limiter->sweep();
        self::assertFileExists($file, 'the previous window is kept');

        $this->now += 2 * RateLimiter::WINDOW_SECONDS;
        $limiter->sweep();
        self::assertFileDoesNotExist($file);
    }

    public function testADirectoryThatCannotBeUsedLetsTheRequestThrough(): void
    {
        $limiter = new RateLimiter('/dev/null/apollo-rate');

        self::assertTrue($limiter->allow('request', '203.0.113.7', 0));
    }

    public function testTheControllerAnswers429AfterTheStartsOfOneMinute(): void
    {
        $session = [];
        $controller = new ApiController(new SessionStore($session), null, $this->limiter(), '203.0.113.7');

        for ($i = 0; $i < ApiController::STARTS_PER_MINUTE; $i++) {
            self::assertSame(200, $controller->handle('POST', 'application/json', self::START)->status);
        }
        $refused = $controller->handle('POST', 'application/json', self::START);
        $tick = $controller->handle('POST', 'application/json', self::TICK);

        self::assertSame(429, $refused->status);
        self::assertSame(['error' => 'Too many requests'], $refused->body);
        self::assertSame(200, $tick->status, 'the run that was started goes on');
    }

    public function testTheSessionOpensOnlyForAStartOrForARequestWithACookie(): void
    {
        $opened = 0;
        $session = [];
        $open = static function () use (&$opened, &$session): SessionStore {
            $opened++;

            return new SessionStore($session);
        };

        $withoutCookie = new ApiController(new LazySessionStore(false, $open));
        self::assertSame(405, $withoutCookie->handle('GET', '', '')->status);
        self::assertSame(400, $withoutCookie->handle('POST', 'application/json', '{"action":"fly"}')->status);
        self::assertSame(409, $withoutCookie->handle('POST', 'application/json', self::TICK)->status);
        self::assertSame(0, $opened, 'a refused request and a tick without a cookie open no session');

        self::assertSame(200, $withoutCookie->handle('POST', 'application/json', self::START)->status);
        self::assertSame(1, $opened);
        self::assertSame(200, $withoutCookie->handle('POST', 'application/json', self::TICK)->status);

        $withCookie = new ApiController(new LazySessionStore(true, $open));
        self::assertSame(400, $withCookie->handle('POST', 'application/json', '{"action":"fly"}')->status);
        self::assertSame(1, $opened, 'a refused request opens no session, cookie or not');
        self::assertSame(200, $withCookie->handle('POST', 'application/json', self::TICK)->status);
        self::assertSame(2, $opened);
    }
}
