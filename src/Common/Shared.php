<?php

namespace Apitoolkit\Common;

use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\Trace\SpanInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use JsonPath\JsonObject;
use JsonPath\InvalidJsonException;




class Shared {

  public static function setAttributes(
    Span $span,
    string $host,
    int $status_code,
    array $query_params,
    array $path_params,
    array $req_headers,
    array $resp_headers,
    string $method,
    string $raw_url,
    ?string $msg_id,
    string $url_path,
    string $req_body,
    string $resp_body,
    array $errors,
    array $config,
    string $sdk_type,
    ?string $parent_id = null
) {
  if ($span === null) {
        return;
    }
  try {
        $span->setAttributes([
            "net.host.name" => $host,
            "apitoolkit.msg_id" => $msg_id,
            "http.route" => $url_path,
            "http.target" => $raw_url,
            "http.request.method" => $method,
            "http.response.status_code" => $status_code,
            "http.request.query_params" => json_encode($query_params),
            "http.request.path_params" => json_encode($path_params),
            "apitoolkit.sdk_type" => $sdk_type,
            "apitoolkit.parent_id" => $parent_id ?? "",
            "http.request.body" => base64_encode(self::redactJSONFields($config['redactRequestBody'] ?? [], $req_body)),
            "http.response.body" => base64_encode(self::redactJSONFields($config['redactResponseBody'] ?? [], $resp_body)),
            "apitoolkit.errors" => json_encode($errors),
            "apitoolkit.service_version" => $config['serviceVersion'] ?? "",
            "apitoolkit.tags" => json_encode($config['tags'] ?? []),
        ]);

        foreach ($req_headers as $header => $value) {
            $span->setAttribute("http.request.header.$header", self::redactHeader(self::toString($value), $config['redactHeaders'] ?? []));
        }

        foreach ($resp_headers as $header => $value) {
            $span->setAttribute("http.response.header.$header", self::redactHeader(self::toString($value), $config['redactHeaders'] ?? []));
        }
    } catch (Exception $error) {
        $span->recordException($error);
    } finally {
        $span->end();
    }
}
public static function observeGuzzle($options, $msgId)
{
    $handlerStack = HandlerStack::create();
    $request_info = [];
    $span = null;
    $handlerStack->push(GuzzleMiddleware::mapRequest(function ($request) use (&$request_info, &$span, $options) {
        $tracerProvider = Globals::tracerProvider();
        $tracer = $tracerProvider->getTracer("apitoolkit-http-tracer");
        $span = $tracer->spanBuilder('apitoolkit-http-span')->startSpan();
        $query = "";
        parse_str($request->getUri()->getQuery(), $query);
        $request_info = [
            "method" => $request->getMethod(),
            "raw_url" => $request->getUri()->getPath() . '?' . $request->getUri()->getQuery(),
            "url_no_query" => $request->getUri()->getPath(),
            "url_path" => $options['pathPattern'] ?? $request->getUri()->getPath(),
            "headers" => $request->getHeaders(),
            "body" => $request->getBody()->getContents(),
            "query" => $query,
            "host" => $request->getUri()->getHost(),
        ];
        return $request;
    }));

    $handlerStack->push(GuzzleMiddleware::mapResponse(function ($response) use (&$request_info, &$span, $msgId, $options) {
        $respBody = $response->getBody()->getContents();
        try {
          $host = $request_info["host"];
          $queryParams = $request_info["query"];
          $reqHeaders = $request_info["headers"];
          $method = $request_info["method"];
          $pathParams = self::extractPathParams($request_info["url_path"], $request_info["url_no_query"]);

          self::setAttributes(
            $span,
            $host,
            $response->getStatusCode(),
            $queryParams,
            $pathParams,
            $reqHeaders,
            $response->getHeaders(),
            $method,
            $request_info["raw_url"],
            null,
            $request_info["url_path"],
            $request_info["body"],
            $respBody,
            [],
            $options,
            "GuzzleOutgoing",
            $msgId,
        );

        } catch (\Throwable $th) {
          throw $th;
        }

        $newBodyStream = \GuzzleHttp\Psr7\Utils::streamFor($respBody);
        $newResponse = new GuzzleResponse(
            $response->getStatusCode(),
            $response->getHeaders(),
            $newBodyStream,
            $response->getProtocolVersion(),
            $response->getReasonPhrase()
        );
        return $newResponse;
    }));

    $client = new Client(['handler' => $handlerStack]);
    return $client;
}


private static function extractPathParams($pattern, $url)
{
    $patternSegments = explode('/', trim($pattern, '/'));
    $urlSegments = explode('/', trim($url, '/'));

    $params = array();

    foreach ($patternSegments as $key => $segment) {
        if (strpos($segment, '{') === 0 && strpos($segment, '}') === strlen($segment) - 1) {
            $paramName = trim($segment, '{}');
            if (isset($urlSegments[$key])) {
                $params[$paramName] = $urlSegments[$key];
            }
        }
    }

    return $params;
}

private static function toString($input): string
{
    if (is_string($input)) {
        return $input;
    }
    if (is_array($input)) {
        return implode(", ", $input); // Customize delimiter as needed
    }
    return strval($input); // Fallback to string conversion
}

private static function rootCause($err)
{
    $cause = $err;
    while ($cause && property_exists($cause, 'cause')) {
        $cause = $cause->cause;
    }
    return $cause;
}

private static function buildError($err)
{
    $errType = get_class($err);
    $rootError = self::rootCause($err);
    $rootErrorType = get_class($rootError);

    return [
        'when' => date('c'),
        'error_type' => $errType,
        'message' => $err->getMessage(),
        'root_error_type' => $rootErrorType,
        'root_error_message' => $rootError->getMessage(),
        'stack_trace' => $err->getTraceAsString(),
    ];
}

public static function reportError($error, $client)
{
    $atError = self::buildError($error);
    $client->addError($atError);
}

private static function redactHeader(string $header, array $redact_headers): string
{
    $lowercase_header = strtolower($header);
    if (in_array($lowercase_header, array_map('strtolower', $redact_headers)) || in_array($lowercase_header, ["cookies", "authorization"])) {
        return "[CLIENT_REDACTED]";
    }
    return $header;
}

// redactJSONFields accepts a list of jsonpath's to redact, and a json object to redact from,
// and returns the final json after the redacting has been done.
private static function redactJSONFields(array $redactKeys, string $jsonStr): string
{
    try {
        $obj = new JsonObject($jsonStr);
    } catch (InvalidJsonException $e) {
        // For any data that isn't json, we simply return the data as is.
        return $jsonStr;
    }

    foreach ($redactKeys as $jsonPath) {
        $obj->set($jsonPath, '[CLIENT_REDACTED]');
    }
    return $obj->getJson();
}

}
