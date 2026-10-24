<x-modal title="Default User Role for Assigned To" close="$wire.closeRoleChooser()" width="sm">
    <form wire:submit="saveDefaultRole" class="mt-3">
        <fieldset class="m-0 min-w-0 border-0 p-0" @error('roleChoice') aria-describedby="role-choice-error" @enderror>
            <legend class="text-body text-label">Assigned To will only list active users with the chosen role.</legend>

            <div class="mt-3 grid max-h-72 gap-2 overflow-y-auto">
                <label class="flex items-center gap-2 text-body text-ink">
                    <input type="radio" name="work-order-role" value="" wire:model="roleChoice" class="size-4 accent-accent">
                    All active users <span class="text-label">(no default)</span>
                </label>
                @foreach ($this->roles as $role)
                    <label wire:key="role-choice-{{ $role->id }}" class="flex items-center gap-2 text-body text-ink">
                        <input type="radio" name="work-order-role" value="{{ $role->id }}" wire:model="roleChoice" class="size-4 accent-accent">
                        {{ $role->name }}
                    </label>
                @endforeach
            </div>

            @if ($this->roles->isEmpty())
                <p class="mt-2 text-caption text-placeholder">No roles yet. Add roles on the Roles page.</p>
            @endif
            @error('roleChoice')
                <p id="role-choice-error" class="form-error" role="alert">{{ $message }}</p>
            @enderror
        </fieldset>

        <div class="form-actions mt-5">
            <button type="button" class="text-button" wire:click="closeRoleChooser">Cancel</button>
            <button type="submit" class="form-button min-w-[100px]" wire:loading.attr="disabled" wire:target="saveDefaultRole">Save</button>
        </div>
    </form>
</x-modal>
