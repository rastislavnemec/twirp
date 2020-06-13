<?php

declare(strict_types=1);

namespace Twirp;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Client hook multiplexer.
 */
final class ChainClientHooks implements ClientHooks
{
    /**
     * @var ClientHooks[]
     */
    private $hooks;

    public function __construct(ClientHooks ...$hooks)
    {
        $this->hooks = $hooks;
    }

    /**
     * {@inheritdoc}
     */
    public function requestPrepared(array $ctx, ServerRequestInterface $request): array
    {
        foreach ($this->hooks as $hook) {
            $ctx = $hook->requestPrepared($ctx, $request);
        }

        return $ctx;
    }

    /**
     * {@inheritdoc}
     */
    public function responseReceived(array $ctx): void
    {
        foreach ($this->hooks as $hook) {
            $hook->responseReceived($ctx);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function error(array $ctx, \Throwable $error): void
    {
        foreach ($this->hooks as $hook) {
            $hook->error($ctx, $error);
        }
    }
}
