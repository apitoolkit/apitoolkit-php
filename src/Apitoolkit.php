<?php

use OpenTelemetry\API\Trace\TracerProvider;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\Trace\SpanInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

use JsonPath\JsonObject;
use JsonPath\InvalidJsonException;

function setAttributes(
    SpanInterface $span,
    string $host,
    int $status_code,
    array $query_params,
    array $path_params,
    array $req_headers,
    array $resp_headers,
    string $method,
    string $raw_url,
    string $msg_id,
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
            "http.request.body" => base64_encode(redactJSONFields($config['redact_request_body'] ?? [], $req_body)),
            "http.response.body" => base64_encode(redactJSONFields($config['redact_response_body'] ?? [], $resp_body)),
            "apitoolkit.errors" => json_encode($errors),
            "apitoolkit.service_version" => $config['serviceVersion'] ?? "",
            "apitoolkit.tags" => json_encode($config['tags'] ?? []),
        ]);

        foreach ($req_headers as $header => $value) {
            $span->setAttribute("http.request.header.$header", redactHeader($value, $config['redact_headers'] ?? []));
        }

        foreach ($resp_headers as $header => $value) {
            $span->setAttribute("http.response.header.$header", redactHeader($value, $config['redact_headers'] ?? []));
        }
    } catch (Exception $error) {
        $span->recordException($error);
    } finally {
        $span->end();
    }
}

function observeGuzzle($request, $options)
{
    $handlerStack = HandlerStack::create();
    $request_info = [];
    $span = null;
    $handlerStack->push(GuzzleMiddleware::mapRequest(function ($request) use (&$request_info, &$span, $options) {
        $tracer = TracerProvider::getTracer("apitoolkit-http-tracer");
        $span = $tracer->startSpan("apitoolkit-http-span");
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

    $handlerStack->push(GuzzleMiddleware::mapResponse(function ($response) use (&$request_info, &$span, $request, $options) {
        $apitoolkit = $request->apitoolkitData;
        $msg_id = $apitoolkit['msg_id'];
        $respBody = $response->getBody()->getContents();
        try {
          $host = $request_info["host"];
          $queryParams = $request_info["query"];
          $reqHeaders = $request_info["headers"];
          $method = $request_info["method"];
          $pathParams = extractPathParams($request_info["url_path"], $request_info["url_no_query"]);

          set_attributes(
            $span,
            $host,
            $response->getStatusCode(),
            $queryParams,
            $pathParams,
            $reqHeaders,
            $res_headers,
            $parent_request->getMethod(),
            $request_info["raw_url"],
            $message_id,
            $request_info["url_path"],
            $request_info["body"],
            $resp_body,
            [],
            $options,
            "GuzzleOutgoing"
        );

        } catch (\Throwable $th) {
          //throw $th;
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


function extractPathParams($pattern, $url)
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

function rootCause($err)
{
    $cause = $err;
    while ($cause && property_exists($cause, 'cause')) {
        $cause = $cause->cause;
    }
    return $cause;
}

function buildError($err)
{
    $errType = get_class($err);
    $rootError = rootCause($err);
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

function reportError($error, $request)
{
    $atError = buildError($error);
    $apitoolkit = $request->apitoolkitData;
    $errors = $apitoolkit['errors'] ?? [];
    $errors[] = $atError;
    $apitoolkit['errors'] = $errors;
    $request->merge(['apitoolkitData' => $apitoolkit]);
}

// function redactHeaderFields(array $redactKeys, array $headerFields): array
// {
//     array_walk($headerFields, function (&$value, $key, $redactKeys) {
//         if (in_array(strtolower($key), array_map('strtolower', $redactKeys))) {
//             $value = ['[CLIENT_REDACTED]'];
//         }
//     }, $redactKeys);
//     return $headerFields;
// }

function redactHeader(string $header, array $redact_headers): string
{
    $lowercase_header = strtolower($header);
    if (in_array($lowercase_header, array_map('strtolower', $redact_headers)) || in_array($lowercase_header, ["cookies", "authorization"])) {
        return "[CLIENT_REDACTED]";
    }
    return $header;
}

// redactJSONFields accepts a list of jsonpath's to redact, and a json object to redact from,
// and returns the final json after the redacting has been done.
function redactJSONFields(array $redactKeys, string $jsonStr): string
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
