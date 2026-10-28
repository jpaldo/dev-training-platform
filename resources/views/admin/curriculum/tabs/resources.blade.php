@php
    $resourceErrors = $errors->getBag('resources');
@endphp

<x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="program-resources-title">
    <h2 id="program-resources-title" class="text-xl font-extrabold">Resources</h2>

    <form method="POST" action="{{ $addResource['url'] }}" class="grid min-w-0 grid-cols-1 content-start gap-3 rounded-xl border border-line bg-surface-sunken p-4 lg:max-w-2xl" aria-labelledby="program-resource-add-title">
        @csrf
        <h3 id="program-resource-add-title" class="text-base font-extrabold">Add a resource</h3>

        @if($resourceErrors->any())
            <ul class="grid list-disc gap-1 pl-5 text-sm text-rose-200" role="alert">
                @foreach($resourceErrors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-2">
            <label for="program-resource-add-name" class="text-sm font-extrabold">Title</label>
            <input
                id="program-resource-add-name"
                name="title"
                type="text"
                required
                maxlength="255"
                value="{{ $resourceErrors->any() ? old('title') : '' }}"
                @if($resourceErrors->has('title')) aria-invalid="true" @endif
                class="{{ $controlClasses }}"
            >
        </div>

        <div class="grid min-w-0 grid-cols-1 gap-2">
            <label for="program-resource-add-url" class="text-sm font-extrabold">Link</label>
            <p id="program-resource-add-url-hint" class="text-sm/relaxed text-muted">Google Drive, Docs, Slides and Sheets links show a preview here. Other links, such as YouTube, open in a new tab.</p>
            <input
                id="program-resource-add-url"
                name="url"
                type="url"
                required
                maxlength="2048"
                placeholder="https://"
                value="{{ $resourceErrors->any() ? old('url') : '' }}"
                aria-describedby="program-resource-add-url-hint program-resource-add-sharing-hint"
                @if($resourceErrors->has('url')) aria-invalid="true" @endif
                class="{{ $controlClasses }}"
            >
            <p id="program-resource-add-sharing-hint" class="text-sm/relaxed text-muted">Share Google Drive files with ‘Anyone with the link’ or with everyone at Full Scale. The app cannot check sharing, and people without access cannot see the file.</p>
        </div>

        <div class="grid min-w-0 grid-cols-1 gap-2">
            <label for="program-resource-add-week" class="text-sm font-extrabold">For</label>
            <select id="program-resource-add-week" name="week_number" @if($resourceErrors->has('week_number')) aria-invalid="true" @endif class="{{ $controlClasses }}">
                @foreach($addResource['weekOptions'] as $option)
                    <option value="{{ $option['value'] }}" @selected($resourceErrors->any() && (string) old('week_number') === $option['value'])>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <x-ui.button variant="secondary" type="submit">Add Resource</x-ui.button>
        </div>
    </form>

    @forelse($resourceGroups as $group)
        <section class="grid min-w-0 grid-cols-1 gap-3" aria-labelledby="program-resources-{{ $group['key'] }}-title" data-resource-group>
            <h3 id="program-resources-{{ $group['key'] }}-title" class="text-base font-extrabold">{{ $group['label'] }}</h3>
            <ul class="grid grid-cols-1 gap-3">
                @foreach($group['resources'] as $resource)
                    @php
                        $renameErrors = $errors->getBag($resource['rename_error_bag']);
                    @endphp
                    <li class="min-w-0">
                        <x-session-resource :resource="$resource">
                            <details class="group min-w-0" @if($renameErrors->any()) open @endif>
                                <summary class="inline-flex cursor-pointer list-none items-center rounded-xl border border-line bg-raised px-4 py-2.5 text-sm font-extrabold text-white hover:border-brand focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50 [&::-webkit-details-marker]:hidden" aria-label="Rename {{ $resource['title'] }}">Rename</summary>
                                <form method="POST" action="{{ $resource['rename_url'] }}" class="mt-2 grid min-w-0 grid-cols-1 gap-2 sm:w-72">
                                    @csrf
                                    @method('PUT')
                                    <label for="program-resource-{{ $resource['id'] }}-title" class="text-sm font-extrabold">New title</label>
                                    <input
                                        id="program-resource-{{ $resource['id'] }}-title"
                                        name="title"
                                        type="text"
                                        required
                                        maxlength="255"
                                        value="{{ $renameErrors->any() ? old('title') : $resource['title'] }}"
                                        @if($renameErrors->any()) aria-invalid="true" aria-describedby="program-resource-{{ $resource['id'] }}-title-error" @endif
                                        class="{{ $controlClasses }}"
                                    >
                                    @if($renameErrors->any())
                                        <p id="program-resource-{{ $resource['id'] }}-title-error" class="text-sm text-rose-200" role="alert">{{ $renameErrors->first('title') }}</p>
                                    @endif
                                    <div>
                                        <x-ui.button variant="secondary" type="submit">Save Title</x-ui.button>
                                    </div>
                                </form>
                            </details>

                            <form method="POST" action="{{ $resource['remove_url'] }}" data-confirm="Remove {{ $resource['title'] }} from this curriculum?">
                                @csrf
                                @method('DELETE')
                                <x-ui.button variant="secondary" type="submit" aria-label="Remove {{ $resource['title'] }}">Remove</x-ui.button>
                            </form>
                        </x-session-resource>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="text-muted">No resources in this curriculum yet.</p>
    @endforelse
</x-ui.card>
