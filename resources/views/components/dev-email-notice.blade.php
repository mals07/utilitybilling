@props(['link', 'label'])

{{-- Shown only while EMAIL_DELIVERY=console, in place of checking a real inbox. --}}
<aside aria-label="Development email shortcut" {{ $attributes->class('dev-notice') }}>
    <strong>Development mode: email not sent</strong>
    <span>
        SMTP delivery is turned off (EMAIL_DELIVERY=console). The email was written to the application log
        instead. Use this link to continue:
    </span>
    <a href="{{ $link }}">{{ $label }}</a>
</aside>
