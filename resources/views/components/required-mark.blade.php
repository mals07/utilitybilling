{{--
    The red * after a required field's label; pass :when for a field that is only sometimes
    required. Screen readers skip it; the control's aria-required="true" says the same to them.
--}}
@props(['when' => true])
@if ($when)<span class="required-mark" aria-hidden="true">*</span>@endif
