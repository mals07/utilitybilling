<x-layouts::app :title="$title">
    <x-page :title="$title">
        <div role="alert" class="flex flex-col gap-1 border border-table-line bg-panel p-6 text-[13px] text-label">
            <p class="text-[15px] font-semibold text-ink-strong">403 · Access denied</p>
            <p>Your role doesn't have access to this page. Ask your administrator if you need it.</p>
        </div>
    </x-page>
</x-layouts::app>
