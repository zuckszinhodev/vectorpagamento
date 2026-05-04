<?php

declare(strict_types=1);

namespace Domain\Acquirer\ValueObjects;

use JsonSerializable;

final class AcquirerResponse implements JsonSerializable
{
    private bool $success;
    private ?string $transactionId;
    private ?string $authorizationCode;
    private ?string $nsu;
    private string $status;
    private ?string $errorCode;
    private ?string $errorMessage;
    private array $data;
    private array $rawResponse;

    private function __construct(
        bool $success,
        string $status,
        ?string $transactionId = null,
        ?string $authorizationCode = null,
        ?string $nsu = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        array $data = [],
        array $rawResponse = []
    ) {
        $this->success = $success;
        $this->transactionId = $transactionId;
        $this->authorizationCode = $authorizationCode;
        $this->nsu = $nsu;
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->data = $data;
        $this->rawResponse = $rawResponse;
    }

    public static function success(
        string $transactionId,
        string $authorizationCode,
        string $nsu,
        string $status = 'approved',
        array $data = [],
        array $rawResponse = []
    ): self {
        return new self(
            success: true,
            status: $status,
            transactionId: $transactionId,
            authorizationCode: $authorizationCode,
            nsu: $nsu,
            data: $data,
            rawResponse: $rawResponse
        );
    }

    public static function pending(
        string $transactionId,
        string $status = 'pending',
        array $data = [],
        array $rawResponse = []
    ): self {
        return new self(
            success: true,
            status: $status,
            transactionId: $transactionId,
            data: $data,
            rawResponse: $rawResponse
        );
    }

    public static function failure(
        string $errorCode,
        string $errorMessage,
        string $status = 'declined',
        ?string $transactionId = null,
        array $rawResponse = []
    ): self {
        return new self(
            success: false,
            status: $status,
            transactionId: $transactionId,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse
        );
    }

    public static function error(
        string $errorCode,
        string $errorMessage,
        array $rawResponse = []
    ): self {
        return new self(
            success: false,
            status: 'error',
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isPending(): bool
    {
        return $this->success && in_array($this->status, ['pending', 'processing', 'waiting_payment']);
    }

    public function isApproved(): bool
    {
        return $this->success && $this->status === 'approved';
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function getAuthorizationCode(): ?string
    {
        return $this->authorizationCode;
    }

    public function getNsu(): ?string
    {
        return $this->nsu;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    public function getPixQrCode(): ?string
    {
        return $this->data['pix_qr_code'] ?? null;
    }

    public function getPixCopyPaste(): ?string
    {
        return $this->data['pix_copy_paste'] ?? null;
    }

    public function getBoletoUrl(): ?string
    {
        return $this->data['boleto_url'] ?? null;
    }

    public function getBoletoBarcode(): ?string
    {
        return $this->data['boleto_barcode'] ?? null;
    }

    public function getBoletoDigitableLine(): ?string
    {
        return $this->data['boleto_digitable_line'] ?? null;
    }

    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'transaction_id' => $this->transactionId,
            'authorization_code' => $this->authorizationCode,
            'nsu' => $this->nsu,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'data' => $this->data,
        ];
    }

    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
