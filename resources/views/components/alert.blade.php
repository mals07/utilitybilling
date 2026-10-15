@props(['tone' => 'info'])

<div role="{{ $tone === 'error' ? 'alert' : 'status' }}" {{ $attributes->class(['alert', "alert-{$tone}"]) }}>
    {{ $slot }}
</div>
