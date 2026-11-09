<?php

declare(strict_types=1);

/**
 * Thrown anywhere in a handler to stop and return a JSON error response.
 */
final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $headers = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}

final class Request
{
    private ?array $jsonCache = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        private readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            (string) file_get_contents('php://input'),
        );
    }

    /** Decode the request body as a JSON object, or fail with 400. */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        if (trim($this->rawBody) === '') {
            throw new HttpException(400, 'Request body must be a JSON object');
        }
        try {
            $data = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HttpException(400, 'Invalid JSON: ' . $e->getMessage());
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new HttpException(400, 'Request body must be a JSON object');
        }

        return $this->jsonCache = $data;
    }

    public function queryString(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $data = null,
        public readonly array $headers = [],
    ) {
    }

    public static function fromException(HttpException $e): self
    {
        $body = ['error' => $e->getMessage()];
        if ($e->details) {
            $body['details'] = $e->details;
        }

        return new self($e->status, $body, $e->headers);
    }

    public function body(): string
    {
        if ($this->status === 204) {
            return '';
        }

        return json_encode(
            $this->data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->status !== 204) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->body();
        }
    }
}
