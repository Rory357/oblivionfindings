<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid {--show : Print the keys to the terminal instead of writing to .env}';

    protected $description = 'Generate a VAPID key pair for Web Push and write it to the .env file';

    public function handle(): int
    {
        try {
            ['publicKey' => $publicKey, 'privateKey' => $privateKey] = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $this->error('Could not generate VAPID keys: '.$e->getMessage());

            if (str_contains($e->getMessage(), 'create the key') || str_contains($e->getMessage(), 'configuration file')) {
                $this->newLine();
                $this->warn('This usually means PHP cannot find an OpenSSL config (common on local Windows/Herd).');
                $this->line('Fix: point the OPENSSL_CONF env var at a valid openssl.cnf, or run this command on the server.');
            }

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $this->line('VAPID_PUBLIC_KEY='.$publicKey);
            $this->line('VAPID_PRIVATE_KEY='.$privateKey);

            return self::SUCCESS;
        }

        $path = base_path('.env');

        if (! is_file($path)) {
            $this->error('.env file not found. Re-run with --show and copy the keys in manually.');
            $this->newLine();
            $this->line('VAPID_PUBLIC_KEY='.$publicKey);
            $this->line('VAPID_PRIVATE_KEY='.$privateKey);

            return self::FAILURE;
        }

        $contents = file_get_contents($path);

        if ($this->envHasValue($contents, 'VAPID_PUBLIC_KEY') || $this->envHasValue($contents, 'VAPID_PRIVATE_KEY')) {
            $this->warn('VAPID keys already exist in .env. Leaving them untouched.');
            $this->line('Re-run with --show to print a fresh pair, or clear the existing keys first.');

            return self::SUCCESS;
        }

        $contents = $this->setEnvValue($contents, 'VAPID_PUBLIC_KEY', $publicKey);
        $contents = $this->setEnvValue($contents, 'VAPID_PRIVATE_KEY', $privateKey);

        file_put_contents($path, $contents);

        $this->info('VAPID keys generated and written to .env.');
        $this->line('Set VAPID_SUBJECT to a "mailto:" or "https:" URI, then run: php artisan config:clear');

        return self::SUCCESS;
    }

    private function envHasValue(string $contents, string $key): bool
    {
        return (bool) preg_match('/^'.preg_quote($key, '/').'=.+$/m', $contents);
    }

    private function setEnvValue(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$value;

        // Replace an existing empty/placeholder assignment, otherwise append.
        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents)) {
            return preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents);
        }

        return rtrim($contents, "\n")."\n".$line."\n";
    }
}
