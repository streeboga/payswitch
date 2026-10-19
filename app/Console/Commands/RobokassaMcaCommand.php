<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DataTransferObjects\Admin\CreateConnectorData;
use App\Services\ConnectorService;
use Illuminate\Console\Command;

/** Creates the Robokassa connector account of a merchant from ROBOKASSA_* in .env. */
final class RobokassaMcaCommand extends Command
{
    protected $signature = 'payswitch:robokassa-mca {merchant : Merchant key}';

    protected $description = 'Create the Robokassa connector account for a merchant from ROBOKASSA_* env values';

    public function handle(ConnectorService $connectors): int
    {
        /** @var array<string, mixed> $account */
        $account = config('payswitch.robokassa_account');

        foreach (['login', 'password1', 'password2'] as $key) {
            if (! is_string($account[$key] ?? null) || $account[$key] === '') {
                $this->error('ROBOKASSA_* is empty in .env: '.$key);

                return self::FAILURE;
            }
        }

        $connector = $connectors->create((string) $this->argument('merchant'), new CreateConnectorData(
            connector_name: 'robokassa',
            connector_type: 'fiz_operations',
            connector_account_details: $account,
            payment_methods_enabled: [['payment_method' => 'card'], ['payment_method' => 'sbp']],
            test_mode: (bool) $account['test_mode'],
        ));

        $this->info("MCA: {$connector->key}");

        return self::SUCCESS;
    }
}
