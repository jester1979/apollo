<?php

declare(strict_types=1);

namespace Apollo\Tests\Http;

use Apollo\Chapter\ChapterId;
use Apollo\Dsky\Key;
use Apollo\Http\Action;
use Apollo\Http\InvalidRequest;
use Apollo\Http\TickRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TickRequestTest extends TestCase
{
    private const JSON = 'application/json';
    private const TAB = '0123456789abcdef';

    /**
     * One failing case per rule of TickRequest.
     *
     * @return array<string, array{string, string, string, bool, int}>
     */
    public static function invalidRequests(): array
    {
        $tick = static fn (array $overrides = []): string => json_encode(array_merge(
            ['action' => 'tick', 'tab' => self::TAB, 'keys' => [], 'rod' => 0, 'speed' => 1],
            $overrides,
        ), JSON_THROW_ON_ERROR);
        $start = static fn (array $overrides = []): string => json_encode(array_merge(
            ['action' => 'start', 'tab' => self::TAB, 'chapter' => 'landing', 'scenario' => false],
            $overrides,
        ), JSON_THROW_ON_ERROR);

        return [
            '1 GET' => ['GET', self::JSON, $tick(), true, 405],
            '2 text/plain' => ['POST', 'text/plain', $tick(), true, 415],
            '2 missing content type' => ['POST', '', $tick(), true, 415],
            '3 body of 4097 bytes' => ['POST', self::JSON, str_repeat(' ', 4097), true, 413],
            '4 list' => ['POST', self::JSON, '[1,2]', true, 400],
            '4 string' => ['POST', self::JSON, '"x"', true, 400],
            '4 broken json' => ['POST', self::JSON, '{"action":', true, 400],
            '4 empty body' => ['POST', self::JSON, '', true, 400],
            '4 nested too deep' => ['POST', self::JSON, '{"a":' . str_repeat('[', 9) . str_repeat(']', 9) . '}', true, 400],
            '5 unknown action' => ['POST', self::JSON, '{"action":"fly"}', true, 400],
            '5 missing action' => ['POST', self::JSON, '{}', true, 400],
            '5 action not a string' => ['POST', self::JSON, '{"action":1}', true, 400],
            '6 missing tab' => ['POST', self::JSON, '{"action":"tick","keys":[],"rod":0,"speed":1}', true, 400],
            '6 tab too short' => ['POST', self::JSON, $tick(['tab' => 'abc']), true, 400],
            '6 tab with other characters' => ['POST', self::JSON, $tick(['tab' => '0123456789ABCDEF']), true, 400],
            '6 tab with a newline' => ['POST', self::JSON, $tick(['tab' => "0123456789abcdef\n"]), true, 400],
            '6 tab not a string' => ['POST', self::JSON, $start(['tab' => 1]), false, 400],
            '7 unknown chapter' => ['POST', self::JSON, $start(['chapter' => 'mars']), false, 400],
            '7 missing chapter' => ['POST', self::JSON, '{"action":"start","tab":"0123456789abcdef","scenario":false}', false, 400],
            '7 scenario outside landing' => ['POST', self::JSON, $start(['chapter' => 'launch', 'scenario' => true]), false, 400],
            '7 scenario not a bool' => ['POST', self::JSON, $start(['scenario' => 'yes']), false, 400],
            '8 unknown key' => ['POST', self::JSON, $tick(['keys' => ['VERB', 'DROP TABLE']]), true, 400],
            '8 33 keys' => ['POST', self::JSON, $tick(['keys' => array_fill(0, 33, 'PRO')]), true, 400],
            '8 keys not a list' => ['POST', self::JSON, $tick(['keys' => 'VERB']), true, 400],
            '8 keys object' => ['POST', self::JSON, $tick(['keys' => ['a' => 'VERB']]), true, 400],
            '8 key not a string' => ['POST', self::JSON, $tick(['keys' => [5]]), true, 400],
            '9 rod 11' => ['POST', self::JSON, $tick(['rod' => 11]), true, 400],
            '9 rod -11' => ['POST', self::JSON, $tick(['rod' => -11]), true, 400],
            '9 rod 1.5' => ['POST', self::JSON, $tick(['rod' => 1.5]), true, 400],
            '10 speed 3' => ['POST', self::JSON, $tick(['speed' => 3]), true, 400],
            '10 speed as string' => ['POST', self::JSON, $tick(['speed' => '1']), true, 400],
            '11 tick without start' => ['POST', self::JSON, $tick(), false, 409],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestGetsItsStatusAndAFixedMessage(
        string $method,
        string $contentType,
        string $body,
        bool $simulationStarted,
        int $expectedStatus,
    ): void {
        try {
            TickRequest::fromHttp($method, $contentType, $body, static fn (string $tab): bool => $simulationStarted);
            self::fail('Expected an InvalidRequest');
        } catch (InvalidRequest $e) {
            self::assertSame($expectedStatus, $e->status);
            self::assertNotSame('', $e->getMessage());
            // The message never echoes what was received.
            self::assertStringNotContainsString('DROP TABLE', $e->getMessage());
            self::assertStringNotContainsString('mars', $e->getMessage());
        }
    }

    public function testRulesRunInTheGivenOrder(): void
    {
        // Wrong method beats wrong content type, and both beat an oversized body.
        try {
            TickRequest::fromHttp('GET', 'text/plain', str_repeat(' ', 5000), static fn (string $tab): bool => false);
            self::fail('Expected an InvalidRequest');
        } catch (InvalidRequest $e) {
            self::assertSame(405, $e->status);
        }

        // A bad speed (400) is reported before a missing simulation (409).
        try {
            TickRequest::fromHttp('POST', self::JSON, '{"action":"tick","tab":"0123456789abcdef","keys":[],"rod":0,"speed":3}', static fn (string $tab): bool => false);
            self::fail('Expected an InvalidRequest');
        } catch (InvalidRequest $e) {
            self::assertSame(400, $e->status);
        }
    }

    public function testStartLandingWithScenarioIsValid(): void
    {
        $request = TickRequest::fromHttp(
            'POST',
            'application/json; charset=utf-8',
            '{"action":"start","tab":"0123456789abcdef","chapter":"landing","scenario":true}',
            static fn (string $tab): bool => false,
        );

        self::assertSame(Action::Start, $request->action);
        self::assertSame(self::TAB, $request->tab);
        self::assertSame(ChapterId::Landing, $request->chapter);
        self::assertTrue($request->scenario);
    }

    public function testTickWithAllKeysAndTheLimitsIsValid(): void
    {
        $keys = array_map(static fn (Key $key): string => $key->value, Key::cases());
        self::assertCount(19, $keys);

        foreach ([-10, 10] as $rod) {
            $body = json_encode(
                ['action' => 'tick', 'tab' => self::TAB, 'keys' => $keys, 'rod' => $rod, 'speed' => 10],
                JSON_THROW_ON_ERROR,
            );
            $request = TickRequest::fromHttp('POST', self::JSON, $body, static fn (string $tab): bool => true);

            self::assertSame(Action::Tick, $request->action);
            self::assertNotNull($request->input);
            self::assertCount(19, $request->input->keys);
            self::assertSame($rod, $request->input->rod);
            self::assertSame(10, $request->input->speed);
        }
    }
}
