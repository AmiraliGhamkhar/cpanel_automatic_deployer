<x-filament-panels::page>
    <div wire:poll.3s>
        @php($deployment = $this->deployment())
        @php($steps = $deployment->steps())

        <x-filament::section>
            <x-slot name="heading">
                {{ $deployment->project->name }} · #{{ $deployment->id }} · {{ strtoupper($deployment->status) }}
            </x-slot>

            <div class="space-y-2 text-sm">
                <p>
                    {{ $deployment->project->server->name }} ·
                    {{ $deployment->kind }} ·
                    {{ $deployment->branch }} ·
                    {{ $deployment->commit_hash ?? 'Resolving commit…' }}
                </p>
                @if ($deployment->started_at)
                    <p>
                        Started {{ $deployment->started_at->diffForHumans() }}
                        @if ($deployment->duration !== null)
                            · took {{ $deployment->duration }}s
                        @endif
                    </p>
                @endif
            </div>
        </x-filament::section>

        @if ($deployment->status === 'failed')
            <x-filament::section heading="Failure">
                <div class="space-y-2 text-sm">
                    <p><strong>Failed step:</strong> {{ $deployment->failure_step ?? 'Not identified' }}</p>
                    @if ($deployment->failure_detail)
                        <p class="whitespace-pre-wrap break-words">{{ $deployment->failure_detail }}</p>
                    @endif
                    <p>
                        <strong>Rollback:</strong>
                        {{ $deployment->rollback_available ? 'Available — use Rollback on the project.' : 'No previous verified release is active for this project.' }}
                    </p>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Steps">
            @if ($steps === [])
                <p class="text-sm">Waiting for the queue worker…</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            <th class="p-2 text-left">Step</th>
                            <th class="p-2 text-left">Status</th>
                            <th class="p-2 text-left">Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($steps as $step)
                            <tr class="border-t align-top">
                                <td class="p-2">
                                    <div>{{ $step['step'] }}</div>
                                    @if (! empty($step['message']) && $step['message'] !== 'Started')
                                        <details class="mt-1">
                                            <summary class="cursor-pointer text-xs">Output</summary>
                                            <pre class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap break-words rounded bg-gray-950 p-2 text-xs text-gray-100">{{ $step['message'] }}</pre>
                                        </details>
                                    @endif
                                </td>
                                <td class="p-2">
                                    @php($color = match ($step['status']) { 'success' => 'text-success-600', 'failed' => 'text-danger-600', 'running' => 'text-warning-600', default => '' })
                                    <span class="{{ $color }}">{{ strtoupper($step['status']) }}</span>
                                </td>
                                <td class="p-2">{{ $step['duration'] !== null ? $step['duration'] . 's' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section heading="Raw log" :collapsed="true" collapsible>
            <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-950 p-4 text-xs text-gray-100" aria-live="polite">{{ $deployment->log_output ?: 'No log lines yet.' }}</pre>
            <p class="mt-2 text-xs">
                Output is captured from the host, then redacted and truncated before storage. Secrets are never stored.
                Rollback is always explicit; a failed health check may leave the new release active.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
