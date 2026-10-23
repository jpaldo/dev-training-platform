{{--
    The form that saves the participant's GitHub repository for the session, filled with the saved link (or the rejected
    input after a failed save). Rendered once on the session page: as the prompt until a repository is saved, then
    inside the Change disclosure.
--}}
<form method="POST" action="{{ $repositoryFormUrl }}" class="grid min-w-0 grid-cols-1 gap-2 sm:max-w-xl">
    @csrf
    @method('PUT')

    <label for="repository_url" class="text-sm font-extrabold">Repository link</label>
    <input
        id="repository_url"
        name="repository_url"
        type="url"
        value="{{ old('repository_url', $repositoryUrl) }}"
        maxlength="255"
        placeholder="https://github.com/owner/repository"
        autocomplete="url"
        required
        aria-describedby="repository_url_hint"
        @error('repository_url') aria-invalid="true" aria-errormessage="repository_url_error" @enderror
        class="w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50"
    >
    <p id="repository_url_hint" class="text-sm/relaxed text-muted">The repository itself, e.g. https://github.com/ana/l4-accelerator — not a branch, file or pull request.</p>
    @error('repository_url')
        <p id="repository_url_error" class="text-sm text-danger" role="alert">{{ $message }}</p>
    @enderror

    <div>
        <x-ui.button type="submit" variant="secondary">Save repository</x-ui.button>
    </div>
</form>
