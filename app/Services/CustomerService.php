<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Customer\CreateCustomerData;
use App\DataTransferObjects\Customer\UpdateCustomerData;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\Customer;

final readonly class CustomerService
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
    ) {}

    public function create(CreateCustomerData $dto, int|string $merchantAccountId, ?string $customId = null): Customer
    {
        $attributes = [
            'merchant_account_id' => $merchantAccountId,
            ...$dto->toArray(),
        ];

        if ($customId !== null) {
            if (strlen($customId) > 64 || strlen($customId) < 1) {
                throw new PaymentException('Customer ID must be 1-64 characters', 'invalid_customer_id', 'invalid_request_error', 400);
            }
            if ($this->customerRepository->existsByKey($customId, $merchantAccountId)) {
                throw new PaymentException('Customer ID already exists', 'duplicate_customer_id', 'invalid_request_error', 409);
            }
            $attributes['key'] = $customId;
        }

        try {
            // Транзакция — точка сохранения внутри чужой: иначе на Postgres упавший INSERT
            // делает внешнюю транзакцию непригодной.
            return DB::transaction(fn () => $this->customerRepository->create($attributes));
        } catch (UniqueConstraintViolationException) {
            // Заданный мерчантом id — это customers.key: публичный ключ в маршрутах и в
            // payment_intents.customer_id, уникальный глобально. Занят другим мерчантом (или
            // параллельным запросом) — тот же 409, что и за своим: без миграции ключа и
            // ссылок на него развести пространства id по мерчантам нельзя.
            throw new PaymentException('Customer ID already exists', 'duplicate_customer_id', 'invalid_request_error', 409);
        }
    }

    /**
     * @return Collection<int, Customer>
     */
    public function list(int|string $merchantAccountId): Collection
    {
        return $this->customerRepository->list($merchantAccountId);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(int|string $merchantAccountId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->customerRepository->paginate($merchantAccountId, $filters, $perPage);
    }

    public function find(string $customerKey, int|string $merchantAccountId): Customer
    {
        return $this->customerRepository->findByKey($customerKey, $merchantAccountId);
    }

    public function update(string $customerKey, UpdateCustomerData $dto, int|string $merchantAccountId): Customer
    {
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);
        $updateData = $dto->toUpdateArray();

        if (empty($updateData)) {
            return $customer;
        }

        $this->customerRepository->update($customer, $updateData);

        $customer->refresh();

        return $customer;
    }

    public function delete(string $customerKey, int|string $merchantAccountId): void
    {
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);
        $this->customerRepository->delete($customer);
    }
}
