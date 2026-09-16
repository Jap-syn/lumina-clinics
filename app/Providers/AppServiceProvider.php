<?php

namespace App\Providers;

use App\Database\PostgresTimestampTzGrammar;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase one runs against a stub. Swapping in a real provider (Omise and
        // 2C2P both fit Thai card + PromptPay) means one class and this binding.
        $this->app->singleton(PaymentGateway::class, FakeGateway::class);
    }

    public function boot(): void
    {
        // Catch a mistyped attribute in development rather than silently
        // writing nothing - money and times are involved.
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // Times reach the database with their offset intact. Without this a
        // branch-local Carbon is written as a bare wall clock and Postgres
        // reads it in the session timezone - see PostgresTimestampTzGrammar.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            if ($event->connection->getDriverName() === 'pgsql') {
                $event->connection->setQueryGrammar(
                    new PostgresTimestampTzGrammar($event->connection)
                );
            }
        });
    }
}
