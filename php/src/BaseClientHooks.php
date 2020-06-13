<?php

declare(strict_types=1);

namespace Twirp;

use Psr\Http\Message\RequestInterface;

class BaseClientHooks implements ClientHooks
{
    /**
     * {@inheritdoc}
     */
    public function requestPrepared(array $ctx, RequestInterface $request): array
    {
        return $ctx;
    }

    /**
     * {@inheritdoc}
     */
    public function responseReceived(array $ctx): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function error(array $ctx, \Throwable $error): void
    {
    }
}
