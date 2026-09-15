{{-- Drop-zone file input: drag & drop + klik untuk pilih manual. File yang
     di-drop tetap masuk ke <input type="file"> yang sama supaya form submit,
     validasi backend, dan atribut (required/multiple/name) tidak berubah.
     Props: name, accept, multiple; atribut lain (mis. required) lewat $attributes. --}}
@props([
    'name' => 'file',
    'accept' => '.xlsx,.csv',
    'multiple' => false,
])

<div x-data="{
        over: 0,
        label: '',
        sync(files) {
            this.label = files.length === 0 ? ''
                : files.length > 1 ? files.length + ' berkas dipilih'
                : files[0].name;
        },
     }"
     class="rounded-lg border-2 border-dashed p-4 text-center transition-colors"
     :class="over > 0 ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300 bg-gray-50'"
     @dragenter.prevent="over++"
     @dragover.prevent
     @dragleave.prevent="over = Math.max(0, over - 1)"
     @drop.prevent="over = 0; if ($event.dataTransfer.files.length) { $refs.input.files = $event.dataTransfer.files; sync($event.dataTransfer.files); }">
    <input x-ref="input" type="file" name="{{ $name }}" accept="{{ $accept }}"
           @input="sync($event.target.files)"
           {{ $multiple ? 'multiple' : '' }} {{ $attributes }} class="sr-only">
    <button type="button" @click="$refs.input.click()"
            class="w-full cursor-pointer text-sm text-gray-600">
        <span class="font-semibold text-indigo-600">Klik untuk memilih berkas</span>
        atau tarik &amp; lepas ke sini
    </button>
    <p x-show="label" x-text="label" class="mt-2 text-sm font-medium text-gray-800"></p>
</div>
