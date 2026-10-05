<div class="space-y-4">
 <p class="text-sm">Last checked: {{ $server->last_health_check_at?->toDateTimeString() ?? 'Never — run Test connection' }}. “Unavailable” may mean restricted, not installed, or unsupported. Symlink detection is preliminary; deployment verifies the actual switch.</p>
 <table class="w-full text-sm"><thead><tr><th class="text-left p-2">Capability</th><th class="text-left p-2">Status</th><th class="text-left p-2">Details</th></tr></thead><tbody>
 @foreach(($server->capabilities ?? []) as $name => $capability)
 <tr class="border-t"><td class="p-2">{{ str_replace('_', ' ', $name) }}</td><td class="p-2">{{ strtoupper($capability['status']) }}</td><td class="p-2">{{ $capability['message'] }}</td></tr>
 @endforeach
 </tbody></table>
</div>
