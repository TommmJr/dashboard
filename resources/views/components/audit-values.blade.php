{{--
    Kolom "Perubahan" audit log versi bisa dibaca orang awam.
    Satu baris per kolom yang berubah: UPDATE tampil "lama -> baru",
    CREATE/IMPORT hanya nilai baru, DELETE hanya nilai lama (merah).
    Label kolom + humanisasi nilai dari App\Services\AuditLogFormatter.
--}}
@props(['log'])

@php
    $rows = App\Services\AuditLogFormatter::rows($log);
    $formatAuditValue = static function (string $label, ?string $value): ?string {
        if ($value === null) {
            return null;
        }

        if ($label === 'Status') {
            return \App\Enums\DatasetStatus::tryFrom($value)?->label() ?? $value;
        }

        return $value;
    };
@endphp

@if ($rows === [])
    <span class="text-xs text-slate-400">Tidak tercatat</span>
@else
    <ul class="space-y-1">
        @foreach ($rows as $row)
            @php
                $oldValue = $formatAuditValue($row['label'], $row['old']);
                $newValue = $formatAuditValue($row['label'], $row['new']);
            @endphp
            <li class="leading-snug">
                <span class="font-medium text-gray-700">{{ $row['label'] }}</span>:
                @if ($oldValue !== null && $newValue !== null)
                    <span class="text-red-600">{{ $oldValue }}</span>
                    <span class="text-gray-400" aria-hidden="true">&rarr;</span>
                    <span class="font-medium text-green-700">{{ $newValue }}</span>
                @elseif ($newValue !== null)
                    <span class="text-gray-800">{{ $newValue }}</span>
                @else
                    <span class="text-red-600">{{ $oldValue }}</span>
                @endif
            </li>
        @endforeach
    </ul>
@endif
