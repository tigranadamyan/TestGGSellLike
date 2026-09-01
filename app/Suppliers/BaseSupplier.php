<?php

declare(strict_types=1);

namespace App\Suppliers;

use App\DTO\IssueRequest;
use App\DTO\IssueResult;
use App\Models\SupplierRequest;
use App\Suppliers\Contracts\SupplierInterface;
use Illuminate\Support\Str;

/**
 * Shared behaviour for the supplier stubs.
 *
 * The contract guarantees one thing: a repeat call with the same request_id must
 * return the SAME code that was already issued. That makes "timeout != failure"
 * safe — a timed-out request that actually issued a code replays the same code
 * instead of burning a second one.
 *
 * A stored *failure* is deliberately NOT replayed. request_id is derived from the
 * order, so caching failures forever would permanently poison the order and make
 * recovery from delivery_failed / out_of_stock impossible.
 */
abstract class BaseSupplier implements SupplierInterface
{
    abstract public function name(): string;

    abstract protected function configKey(): string;

    abstract protected function codePrefix(): string;

    public function issue(IssueRequest $request): IssueResult
    {
        $config = config('suppliers.'.$this->configKey());

        $existing = SupplierRequest::where('request_id', $request->requestId)
            ->where('supplier', $this->name())
            ->first();

        // Only a completed issuance is replayed — this is the exactly-once guarantee.
        if ($existing && $existing->status === 'success') {
            return IssueResult::success($existing->code);
        }

        if ($this->rollsUnder($config['failure_rate'] ?? 0)) {
            $this->storeResult($request, 'failure', null, 'Simulated supplier failure');

            return IssueResult::failure('Simulated supplier failure');
        }

        if ($this->rollsUnder($config['out_of_stock_rate'] ?? 0)) {
            $this->storeResult($request, 'failure', null, 'out_of_stock');

            return IssueResult::outOfStock();
        }

        $delay = $config['delay_ms'] ?? 0;
        if ($delay > 0) {
            usleep($delay * 1000);
        }

        $code = strtoupper($this->codePrefix().'-'.Str::random(8));

        // The trap: the code IS issued, but the caller never sees the response.
        // Persisting before returning a timeout is what makes the retry safe.
        if ($this->rollsUnder($config['timeout_rate'] ?? 0)) {
            $this->storeResult($request, 'success', $code);

            return IssueResult::timeout();
        }

        $this->storeResult($request, 'success', $code);

        return IssueResult::success($code);
    }

    private function rollsUnder(float $rate): bool
    {
        if ($rate <= 0) {
            return false;
        }

        return mt_rand(1, 10000) <= (int) round($rate * 10000);
    }

    /**
     * request_id is UNIQUE, so a retry after a failure updates the existing row
     * rather than inserting a duplicate.
     */
    private function storeResult(IssueRequest $request, string $status, ?string $code, ?string $error = null): void
    {
        SupplierRequest::updateOrCreate(
            ['request_id' => $request->requestId],
            [
                'supplier' => $this->name(),
                'sku' => $request->sku,
                'status' => $status,
                'code' => $code,
                'error' => $error,
            ]
        );
    }
}
