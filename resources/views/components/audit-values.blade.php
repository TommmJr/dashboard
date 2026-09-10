{{--
    Kolom "Perubahan" audit log versi bisa dibaca orang awam.
    Satu baris per kolom yang berubah: UPDATE tampil "lama -> baru",
    CREATE/IMPORT hanya nilai baru, DELETE hanya nilai lama (merah).
    Label kolom + humanisasi nilai dari App\Services\AuditLogFormatter.
--}}
@props(['log'])

@php
    $rows = App\Services\AuditLogFormatter::rows($log);
@endphp

@if ($rows === [])
    <span class="text-gray-400">-</span>
@else
    <ul class="space-y-1">
        @foreach ($rows as $row)
            <li class="leading-snug">
                <span class="font-medium text-gray-700">{{ $row['label'] }}</span>:
                @if ($row['old'] !== null && $row['new'] !== null)
                    <span class="text-red-600">{{ $row['old'] }}</span>
                    <span class="text-gray-400" aria-hidden="true">&rarr;</span>
                    <span class="font-medium text-green-700">{{ $row['new'] }}</span>
                @elseif ($row['new'] !== null)
                    <span class="text-gray-800">{{ $row['new'] }}</span>
                @else
                    <span class="text-red-600">{{ $row['old'] }}</span>
                @endif
            </li>
        @endforeach
    </ul>
@endif
