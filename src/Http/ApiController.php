<?php

declare(strict_types=1);

namespace Apollo\Http;

use Apollo\Simulation\SimulationStore;
use Apollo\Simulation\Simulation;

/**
 * Public endpoint: there is no user data, and every visitor has a private session with one simulation
 * per browser tab. Cross-site
 * requests are stopped by the JSON content type rule (needs a CORS preflight, which this
 * endpoint never grants) and by the SameSite=Strict session cookie.
 */
final class ApiController
{
    /** @var \Closure(string): void */
    private readonly \Closure $logger;

    /**
     * @param (\Closure(string): void)|null $logger receives the details of an internal error; defaults to error_log()
     */
    public function __construct(private readonly SimulationStore $store, ?\Closure $logger = null)
    {
        $this->logger = $logger ?? static function (string $message): void {
            error_log($message);
        };
    }

    public function handle(string $method, string $contentType, string $body): ApiResponse
    {
        try {
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
}
