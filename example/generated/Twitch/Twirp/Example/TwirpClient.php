<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!

namespace Twitch\Twirp\Example;

use Google\Protobuf\Internal\GPBDecodeException;
use Google\Protobuf\Internal\Message;
use Http\Client\HttpClient;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\MessageFactoryDiscovery;
use Http\Discovery\StreamFactoryDiscovery;
use Http\Message\MessageFactory;
use Http\Message\StreamFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Twirp\Error;
use Twirp\ErrorCode;

/**
 * Common client implementation.
 */
abstract class TwirpClient
{
    /**
     * @var HttpClient
     */
    private $httpClient;

    /**
     * @var MessageFactory
     */
    private $messageFactory;

    /**
     * @var StreamFactory
     */
    private $streamFactory;

    /**
     * @param HttpClient|null     $httpClient
     * @param MessageFactory|null $messageFactory
     * @param StreamFactory|null  $streamFactory
     */
    public function __construct(
        HttpClient $httpClient = null,
        MessageFactory $messageFactory = null,
        StreamFactory $streamFactory = null
    ) {
        if ($httpClient === null) {
            $httpClient = HttpClientDiscovery::find();
        }

        if ($messageFactory === null) {
            $messageFactory = MessageFactoryDiscovery::find();
        }

        if ($streamFactory === null) {
            $streamFactory = StreamFactoryDiscovery::find();
        }

        $this->httpClient = $httpClient;
        $this->messageFactory = $messageFactory;
        $this->streamFactory = $streamFactory;
    }

    /**
     * Common code to make a request to the remote twirp service.
     *
     * @param array   $ctx
     * @param string  $url
     * @param Message $in
     * @param Message $out
     */
    final protected function doProtobufRequest(array $ctx, $url, Message $in, Message $out)
    {
        $body = $in->serializeToString();

        $req = $this->newRequest($ctx, $url, $body, 'application/protobuf');

        try {
            $resp = $this->httpClient->sendRequest($req);
        } catch (\Exception $e) {
            throw $this->clientError("failed to do request", $e);
        }

        if ($resp->getStatusCode() !== 200) {
            throw $this->errorFromResponse($resp);
        }

        try {
            $out->mergeFromString((string)$resp->getBody());
        } catch (GPBDecodeException $e) {
            throw $this->clientError("failed to unmarshal proto response", $e);
        }
    }

    /**
     * Makes an HTTP request and adds common headers.
     *
     * @param array  $ctx
     * @param string $url
     * @param string $reqBody
     * @param string $contentType
     *
     * @return RequestInterface
     */
    final protected function newRequest(array $ctx, $url, $reqBody, $contentType)
    {
        $body = $this->streamFactory->createStream($reqBody);

        return $this->messageFactory->createRequest('POST', $url)
            ->withBody($body)
            ->withHeader('Accept', $contentType)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Twirp-Version', 'v5.3.0');
    }

    /**
     * Adds consistency to errors generated in the client.
     *
     * @param string     $desc
     * @param \Exception $e
     *
     * @return TwirpError
     */
    final protected function clientError($desc, \Exception $e)
    {
        return TwirpError::newError(ErrorCode::Internal, sprintf('%s: %s', $desc, $e->getMessage()));
    }

    /**
     * Builds a twirp Error from a non-200 HTTP response.
     * If the response has a valid serialized Twirp error, then it's returned.
     * If not, the response status code is used to generate a similar twirp
     * error. {@see self::twirpErrorFromIntermediary} for more info on intermediary errors.
     *
     * @param ResponseInterface $resp
     *
     * @return TwirpError
     */
    final protected function errorFromResponse(ResponseInterface $resp)
    {
        $statusCode = $resp->getStatusCode();
        $statusText = $resp->getReasonPhrase();

        if ($this->isHttpRedirect($statusCode)) {
            // Unexpected redirect: it must be an error from an intermediary.
            // Twirp clients don't follow redirects automatically, Twirp only handles
            // POST requests, redirects should only happen on GET and HEAD requests.
            $location = $resp->getHeaderLine('Location');
            $msg = sprintf(
                'unexpected HTTP status code %d "%s" received, Location="%s"',
                $statusCode,
                $statusText,
                $location
            );

            return $this->twirpErrorFromIntermediary($statusCode, $msg, $location);
        }

        $body = (string)$resp->getBody();

        $rawError = json_decode($body, true);
        if ($rawError === null) {
            $msg = sprintf('error from intermediary with HTTP status code %d "%s"', $statusCode, $statusText);

            return $this->twirpErrorFromIntermediary($statusCode, $msg, $body);
        }

        $rawError = $rawError + ['code' => '', 'msg' => '', 'meta' => []];

        if (ErrorCode::isValid($rawError['code']) === false) {
            $msg = 'invalid type returned from server error response: '.$rawError['code'];

            return TwirpError::newError(ErrorCode::Internal, $msg);
        }

        $error = TwirpError::newError($rawError['code'], $rawError['msg']);

        foreach ($rawError['meta'] as $key => $value) {
            $error = $error->withMeta($key, $value);
        }

        return $error;
    }

    /**
     * Maps HTTP errors from non-twirp sources to twirp errors.
     * The mapping is similar to gRPC: https://github.com/grpc/grpc/blob/master/doc/http-grpc-status-mapping.md.
     * Returned twirp Errors have some additional metadata for inspection.
     *
     * @param int    $status
     * @param string $msg
     * @param string $bodyOrLocation
     *
     * @return TwirpError
     */
    final private function twirpErrorFromIntermediary($status, $msg, $bodyOrLocation)
    {
        if ($this->isHttpRedirect($status)) {
            $code = ErrorCode::Internal;
        } else {
            switch ($status) {
                case 400: // Bad Request
                    $code = ErrorCode::Internal;
                    break;
                case 401: // Unauthorized
                    $code = ErrorCode::Unauthenticated;
                    break;
                case 403: // Forbidden
                    $code = ErrorCode::PermissionDenied;
                    break;
                case 404: // Not Found
                    $code = ErrorCode::BadRoute;
                    break;
                case 429: // Too Many Requests
                case 502: // Bad Gateway
                case 503: // Service Unavailable
                case 504: // Gateway Timeout
                    $code = ErrorCode::Unavailable;
                    break;
                default: // All other codes
                    $code = ErrorCode::Unknown;
                    break;
            }
        }

        $error = TwirpError::newError($code, $msg);
        $error = $error->withMeta('http_error_from_intermediary', 'true');
        $error = $error->withMeta('status_code', (string)$status);

        if ($this->isHttpRedirect($status)) {
            $error = $error->withMeta('location', $bodyOrLocation);
        } else {
            $error = $error->withMeta('body', $bodyOrLocation);
        }

        return $error;
    }

    /**
     * @param int $status
     *
     * @return bool
     */
    final private function isHttpRedirect($status)
    {
        return $status >= 300 && $status <= 399;
    }

    /**
     * Creates base URL for the client.
     *
     * @param string $addr
     *
     * @return UriInterface
     */
    final protected function urlBase($addr)
    {
        $url = $this->messageFactory->createRequest('POST', $addr)->getUri();

        if ($url->getScheme() == '') {
            $url = $url->withScheme('http');
        }

        return $url;
    }
}
