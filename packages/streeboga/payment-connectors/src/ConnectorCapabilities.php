<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors;

use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\SessionResultType;

final readonly class ConnectorCapabilities
{
    /**
     * @param  array<string, string>  $defaultDisplayName  Localized display names ['ru' => '...', 'en' => '...']
     * @param  array<string, DirectMethod>  $directMethods  Payment methods with direct support ['card' => DirectMethod, ...]
     */
    public function __construct(
        public array $defaultDisplayName,
        public string $logoPath,
        public array $directMethods,
        public SessionResultType $fallbackSessionType,
        public AmountUnit $amountUnit = AmountUnit::MinorUnits,
        public ?DirectMethod $sbpMethod = null,
    ) {}

    public function supportsDirectMethod(string $method): bool
    {
        if ($method === 'sbp' && $this->sbpMethod !== null) {
            return true;
        }

        return isset($this->directMethods[$method]);
    }

    public function getDirectMethod(string $method): ?DirectMethod
    {
        if ($method === 'sbp' && $this->sbpMethod !== null) {
            return $this->sbpMethod;
        }

        return $this->directMethods[$method] ?? null;
    }

    public function displayName(string $locale): string
    {
        if (isset($this->defaultDisplayName[$locale])) {
            return $this->defaultDisplayName[$locale];
        }

        if (isset($this->defaultDisplayName['en'])) {
            return $this->defaultDisplayName['en'];
        }

        $values = array_values($this->defaultDisplayName);

        return $values[0] ?? '';
    }
}
