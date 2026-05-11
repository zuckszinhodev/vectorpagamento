<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;

class AdminApprovalService
{
    public function consumeApprovedRequest(Request $request, string $approvalId, string $actionKey): bool
    {
        return true;
    }

    public function requireApproval(Request $request, array $context): array
    {
        return ['approved' => true];
    }
}
