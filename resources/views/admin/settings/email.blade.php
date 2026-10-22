@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $checkboxLabelClasses = 'flex min-h-11 items-center gap-2.5 text-sm font-extrabold';
        $checkboxClasses = 'size-4.5 shrink-0 accent-brand focus-visible:outline-3 focus-visible:outline-accent/50';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN SETTINGS · EMAIL</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Email configuration</h1>
            <p class="m-0 leading-relaxed text-muted">Configure the application mail transport and Mentor interview notifications. Passwords are encrypted and never displayed.</p>
        </header>

        {{-- The single main action stays in view: in the page header on desktop, pinned to the bottom of the screen on mobile. --}}
        <div id="email-settings-main-action" class="z-20 flex flex-wrap items-center gap-3 max-lg:sticky max-lg:bottom-3 max-lg:order-last max-lg:rounded-2xl max-lg:border max-lg:border-line max-lg:bg-surface/95 max-lg:p-3 max-lg:shadow-2xl max-lg:shadow-black/60 max-lg:backdrop-blur lg:col-start-2 lg:row-start-1 lg:self-start lg:justify-end">
            <x-ui.button type="submit" form="email-settings-form">Save Email Configuration</x-ui.button>
        </div>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="email-summary-title">
                <h2 id="email-summary-title" class="sr-only">Current configuration</h2>

                <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach($summary as $item)
                        <div class="grid min-w-0 content-start justify-items-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5">
                            <dt><x-ui.eyebrow as="span">{{ $item['label'] }}</x-ui.eyebrow></dt>
                            @if($item['tone'])
                                <dd class="m-0"><x-ui.badge :tone="$item['tone']">{{ $item['value'] }}</x-ui.badge></dd>
                            @else
                                <dd class="m-0 text-xl/snug font-extrabold wrap-break-word">{{ $item['value'] }}</dd>
                            @endif
                        </div>
                    @endforeach
                </dl>

                <dl class="m-0 grid grid-cols-1 gap-2.5">
                    @foreach($details as $detail)
                        <div class="flex flex-wrap gap-x-2 leading-relaxed">
                            <dt class="font-extrabold">{{ $detail['label'] }}</dt>
                            <dd class="m-0 min-w-0 wrap-anywhere">{{ $detail['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="email-delivery-title">
                <h2 id="email-delivery-title" class="{{ $sectionTitleClasses }}">Configure delivery</h2>

                <form id="email-settings-form" method="POST" action="{{ route('admin.settings.email.update') }}" class="m-0 grid grid-cols-1 gap-5">
                    @csrf
                    @method('PUT')

                    <div class="grid gap-2">
                        <label for="mailer" class="{{ $labelClasses }}">Mail provider / mailer *</label>
                        <select id="mailer" name="mailer" required class="{{ $controlClasses }}">
                            <option value="log" @selected(old('mailer', $settings['mailer']) === 'log')>Log (development)</option>
                            <option value="smtp" @selected(old('mailer', $settings['mailer']) === 'smtp')>SMTP</option>
                            <option value="sendmail" @selected(old('mailer', $settings['mailer']) === 'sendmail')>Sendmail</option>
                            <option value="array" @selected(old('mailer', $settings['mailer']) === 'array')>Array (testing)</option>
                        </select>
                        @error('mailer')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="grid min-w-0 content-start gap-2">
                            <label for="smtp_host" class="{{ $labelClasses }}">SMTP host <span class="font-normal text-muted">(required for SMTP)</span></label>
                            <input id="smtp_host" name="smtp_host" value="{{ old('smtp_host', $settings['smtp_host']) }}" autocomplete="off" class="{{ $controlClasses }}">
                            @error('smtp_host')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid min-w-0 content-start gap-2">
                            <label for="smtp_port" class="{{ $labelClasses }}">SMTP port</label>
                            <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="{{ old('smtp_port', $settings['smtp_port']) }}" class="{{ $controlClasses }}">
                            @error('smtp_port')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid min-w-0 content-start gap-2">
                            <label for="smtp_scheme" class="{{ $labelClasses }}">Encryption / scheme</label>
                            <select id="smtp_scheme" name="smtp_scheme" class="{{ $controlClasses }}">
                                <option value="" @selected(blank(old('smtp_scheme', $settings['smtp_scheme'])))>None</option>
                                <option value="tls" @selected(old('smtp_scheme', $settings['smtp_scheme']) === 'tls')>TLS / STARTTLS</option>
                                <option value="ssl" @selected(old('smtp_scheme', $settings['smtp_scheme']) === 'ssl')>SSL / SMTPS</option>
                            </select>
                            @error('smtp_scheme')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid min-w-0 content-start gap-2">
                            <label for="smtp_username" class="{{ $labelClasses }}">SMTP username</label>
                            <input id="smtp_username" name="smtp_username" value="{{ old('smtp_username', $settings['smtp_username']) }}" autocomplete="username" class="{{ $controlClasses }}">
                            @error('smtp_username')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <label for="smtp_password" class="{{ $labelClasses }}">SMTP password</label>
                        <input id="smtp_password" name="smtp_password" type="password" autocomplete="new-password" aria-describedby="smtp-password-hint" class="{{ $controlClasses }}">
                        <p id="smtp-password-hint" class="{{ $hintClasses }}">Leave blank to keep the existing password. {{ $passwordStatus }}</p>
                        @error('smtp_password')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <label class="{{ $checkboxLabelClasses }}">
                        <input type="checkbox" name="clear_smtp_password" value="1" class="{{ $checkboxClasses }}">
                        Clear the stored SMTP password
                    </label>
                    @error('clear_smtp_password')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="grid min-w-0 content-start gap-2">
                            <label for="from_address" class="{{ $labelClasses }}">From address *</label>
                            <input id="from_address" name="from_address" type="email" value="{{ old('from_address', $settings['from_address']) }}" required class="{{ $controlClasses }}">
                            @error('from_address')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid min-w-0 content-start gap-2">
                            <label for="from_name" class="{{ $labelClasses }}">From name *</label>
                            <input id="from_name" name="from_name" value="{{ old('from_name', $settings['from_name']) }}" required class="{{ $controlClasses }}">
                            @error('from_name')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <label class="{{ $checkboxLabelClasses }}">
                        <input type="hidden" name="mentor_emails_enabled" value="0">
                        <input type="checkbox" name="mentor_emails_enabled" value="1" @checked(old('mentor_emails_enabled', $settings['mentor_emails_enabled'])) class="{{ $checkboxClasses }}">
                        Enable Mentor interview emails
                    </label>
                    @error('mentor_emails_enabled')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </form>
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="email-test-title">
                <div class="grid gap-2">
                    <h2 id="email-test-title" class="{{ $sectionTitleClasses }}">Send test email</h2>
                    <p class="{{ $hintClasses }}">The test uses the saved runtime configuration and sends only to the recipient you enter.</p>
                </div>

                <form method="POST" action="{{ route('admin.settings.email.test') }}" class="m-0 grid grid-cols-1 justify-items-start gap-4">
                    @csrf
                    <div class="grid w-full max-w-120 gap-2">
                        <label for="test-recipient" class="{{ $labelClasses }}">Test recipient *</label>
                        <input id="test-recipient" name="recipient" type="email" value="{{ old('recipient') }}" required class="{{ $controlClasses }}">
                        @error('recipient')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                        @error('email_test')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                    <x-ui.button variant="secondary" type="submit">Send Test Email</x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
@endsection
