<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Transport-neutral result of a successful application use case. */
final readonly class ServiceResult
{
    public function __construct(
        public bool $success,
        public int $statuscode,
        public string $message,
        public mixed $data = null,
    ) {
        if ($statuscode < 200 || $statuscode >= 600) {
            throw new \InvalidArgumentException('Invalid result status code.');
        }
    }

    public static function ok(mixed $data = null, string $message = 'Operation successful.', int $statuscode = 200): self
    {
        return new self(true, $statuscode, $message, $data);
    }

    public function toArray(): array
    {
        $response = [
            'success' => $this->success,
            'statuscode' => $this->statuscode,
            'message' => $this->message,
        ];

        if ($this->data instanceof LengthAwarePaginator) {
            $response['data'] = $this->data->items();
            $response['pagination'] = [
                'current_page' => $this->data->currentPage(),
                'per_page' => $this->data->perPage(),
                'last_page' => $this->data->lastPage(),
                'total' => $this->data->total(),
            ];
        } elseif ($this->data !== null) {
            $response['data'] = $this->data;
        }

        return $response;
    }
}
