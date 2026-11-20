<?php

declare(strict_types=1);

namespace Apollo\Http;

use Apollo\Simulation\SimulationStore;
use Apollo\Simulation\Simulation;

/**
 * Public endpoint: there is no user data, and every visitor has a private session with one simulation
 * per browser tab. Cross-site
 * requests are stopped by the JSON content type rule (needs a CORS preflight, which this
 * endpoint never grants) and by the SameSite=Strict session cookie. A client address gets a number of
 * requests and a smaller number of starts per minute; both limits are chosen values.
 */
final class ApiController
{
    /** A page ticks 10 times a second, so this is five tabs at once from one address. */
    public const REQUESTS_PER_MINUTE = 3000;

    /** A start builds a new simulation and can create a session, so it gets a tighter limit. */
    public const STARTS_PER_MINUTE = 30;

    /** @var \Closure(string): void */
    private readonly \Closure $logger;

    /**
     * @param (\Closure(string): void)|null $logger receives the details of an internal error; defaults to error_log()
     * @param RateLimiter|null $limiter counts the requests of $client; without one nothing is limited
     * @param string $client what the limits are counted per: the address of the client
     */
    public function __construct(
        private readonly SimulationStore $store,
        ?\Closure $logger = null,
        private readonly ?RateLimiter $limiter = null,
        private readonly string $client = '',
    ) {
        $this->logger = $logger ?? static function (string $message): void {
            error_log($message);
        };
    }

    public function handle(string $method, string $contentType, string $body): ApiResponse
    {
        try {
            $this->limit('request', self::REQUESTS_PER_MINUTE);
            $simulation = null;
            $request = TickRequest::fromHttp(
                $method,
                $contentType,
                $body,
                function (string $tab) use (&$simulation): bool {
                    $simulation = $this->store->simulation($tab);

                    return $simulation !== null;
                },
            );

            if ($request->action === Action::Start) {
                $this->limit('start', self::STARTS_PER_MINUTE);
                $simulation = new Simulation();
                assert($request->chapter !== null);
                $response = $simulation->start($request->chapter, $request->scenario);
                $this->store->save($request->tab, $simulation);

                return new ApiResponse(200, $response);
            }

            assert($simulation !== null && $request->input !== null);
            $response = $simulation->tick($request->input);
            $this->store->save($request->tab, $simulation);

            return new ApiResponse(200, $response);
        } catch (InvalidRequest $e) {
            return new ApiResponse($e->status, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            ($this->logger)(sprintf('%s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

            return new ApiResponse(500, ['error' => 'Internal error']);
        }
    }

    /**
     * @throws InvalidRequest
     */
    private function limit(string $bucket, int $perMinute): void
    {
        if ($this->limiter !== null && !$this->limiter->allow($bucket, $this->client, $perMinute)) {
            throw new InvalidRequest(429, 'Too many requests');
        }
    }
}
