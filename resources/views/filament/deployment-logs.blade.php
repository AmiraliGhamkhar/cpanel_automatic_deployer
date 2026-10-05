<x-filament-panels::page>
 <div wire:poll.3s>
 @php($deployment = $this->deployment())
 <x-filament::section>
 <x-slot name="heading">{{ $deployment->project->name }} · #{{ $deployment->id }} · {{ strtoupper($deployment->status) }}</x-slot>
 <p>{{ $deployment->project->server->name }} / {{ $deployment->branch }} / {{ $deployment->commit_hash ?? 'Resolving commit…' }}</p>
 <p class="mt-2">{{ $deployment->failure_reason }}</p>
 <pre class="mt-4 overflow-x-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-4 text-sm text-gray-100" aria-live="polite">{{ $deployment->log_output ?: 'Waiting for the database queue worker…' }}</pre>
 <p class="mt-4 text-sm">Raw remote output is intentionally withheld. Rollback is available from Projects when a previous verified release exists. A failed health check may leave the new release active; rollback is always explicit.</p>
 </x-filament::section>
 </div>
</x-filament-panels::page>
