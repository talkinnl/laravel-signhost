<?php

namespace Signhost;

use Signhost\Exception\SignhostException;
use InvalidArgumentException;

/**
 * Class SignhostClient
 *
 * @package   laravel-signhost
 * @author    Stephan Eizinga <stephan@monkeysoft.nl>
 */
class SignhostClient
{
    const OPT_CAINFOPATH = 'ca-info-path';
    const OPT_URL        = 'url';
    const OPT_TIMEOUT    = 'timeout';
    const OPT_IGNORE_HTTP_ERRORS = 'ignore-http-errors';

    private static $KNOWN_OPTIONS = [
        self::OPT_CAINFOPATH,
        self::OPT_URL,
        self::OPT_TIMEOUT,
        self::OPT_IGNORE_HTTP_ERRORS
    ];

    /**
     * @var string $rootUrl
     */
    private $rootUrl = 'https://api.signhost.com/api';

    /**
     * @var array
     */
    private $headers;

    /**
     * @var array
     */
    private $requestOptions;

    public function __construct(
        string $appName,
        string $appKey,
        string $apiKey,
        array $requestOptions = []
    ) {
        $this->headers = [
            'Content-Type: application/json',
            "Application: APPKey $appName $appKey",
            "Authorization: APIKey $apiKey",
        ];

        $this->requestOptions = $requestOptions;
        foreach ($this->requestOptions as $optionName => $value) {
            if (!in_array($optionName, self::$KNOWN_OPTIONS, true)) {
                throw new InvalidArgumentException("Unknown request option: $optionName");
            }
        }
    }

    private function shouldIgnoreHttpErrors(): bool
    {
        return $this->requestOptions[self::OPT_IGNORE_HTTP_ERRORS] ?? false;
    }

    /**
     * @throws SignhostException
     */
    public function performRequest(string $endpoint, string $method, $data = null, $filePath = null): string
    {
        $headers   = $this->headers;
        $targetUrl = $this->requestOptions[self::OPT_URL] ?? $this->rootUrl;

        // Initialize a cURL session
        $curlHandle = $this->prepareHttpRequest(
            $targetUrl . $endpoint,
            $method,
            $data,
            $filePath
        );

        // When $filepath is set, provide a file descriptor to curl so it can use it to send
        // the file along with the request.
        if (isset($filePath)) {
            $fh = fopen($filePath, 'rb');
            curl_setopt($curlHandle, CURLOPT_INFILE, $fh);

            $headers[0] = 'Content-Type: application/pdf';
            $headers[]  = 'Digest: SHA256=' . base64_encode(pack('H*', hash_file('sha256', $filePath)));
        }

        try {
            // Set the headers and perform the request.
            curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($curlHandle);
            $this->assertSuccessfulResponse($curlHandle, $response);

            return $response;
        } finally {
            // when $fh is set for file upload we must close it for free up memory and remove any lock
            if (isset($fh)) {
                // close file handler
                fclose($fh);
            }
        }
    }

    private function prepareHttpRequest(string $url, string $method, $data = null, $filePath = null)
    {
        $curl = curl_init($url);

        switch ($method) {
            case 'DELETE':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;

            case 'PUT':
                if (!empty($data) && empty($filePath)) {
                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
                } elseif (empty($data) && !empty($filePath)) {
                    curl_setopt($curl, CURLOPT_PUT, 1);
                    curl_setopt($curl, CURLOPT_INFILESIZE, filesize($filePath));
                } else {
                    curl_setopt($curl, CURLOPT_PUT, 1);
                }
                break;

            case 'POST':
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
                break;
        }

        // Tell curl what timeout to use
        if (isset($this->requestOptions[self::OPT_TIMEOUT])) {
            curl_setopt($curl,CURLOPT_TIMEOUT, $this->requestOptions[self::OPT_TIMEOUT]);
        }

        // Tell curl where to find the root CA's we trust.
        if (isset($this->requestOptions[self::OPT_CAINFOPATH])) {
            curl_setopt($curl, CURLOPT_CAINFO, $this->requestOptions[self::OPT_CAINFOPATH]);
        }

        // We want the response returned from curl_exec()
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        // Don't reuse connections
        curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);

        // Make sure the x509 certificate presented is valid
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

        return $curl;
    }

    /**
     * Check http status code for errors
     * @throws SignHostException
     */
    private function assertSuccessfulResponse($curlHandle, $response)
    {
        if (curl_errno($curlHandle)) {
            throw new SignhostException(
                'Request to Signhost failed: ' . curl_error($curlHandle),
                0
            );
        }

        $statusCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        if ($statusCode < 400 || $this->shouldIgnoreHttpErrors()) {
            return;
        }

        $message = 'Unknown error';
        if ($statusCode >= 400 && $statusCode <= 499) {
            // decode message from json string
            $object = json_decode($response, false);
            $message = $object->Message ?? 'Unknown error';
        } elseif ($statusCode > 500 && $statusCode <= 599) {
            $message = 'Internal server error on remote server';
        }

        throw new SignhostException(
            "Response code: $statusCode, message: $message",
            $statusCode
        );
    }
}
