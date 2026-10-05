<?php
namespace App\Filament;
use Filament\Notifications\Notification;
class ActionRunner
{
    public static function run(callable $action): void
    {
        try {
            $action();
            Notification::make()
                ->title("Request accepted")
                ->body(
                    "Queued operations run in the database worker. Status refreshes automatically.",
                )
                ->success()
                ->send();
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Only explicit first-party validation messages may reach the UI.
            $safe =
                $e instanceof \InvalidArgumentException ||
                get_class($e) === \RuntimeException::class;
            Notification::make()
                ->title("Request not accepted")
                ->body(
                    $safe
                        ? $e->getMessage()
                        : "Review the form and configuration. The operation could not be completed.",
                )
                ->danger()
                ->send();
        }
    }
}
