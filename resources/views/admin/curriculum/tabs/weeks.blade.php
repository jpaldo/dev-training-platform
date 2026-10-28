<x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="program-weeks-title">
    <h2 id="program-weeks-title" class="text-xl font-extrabold">Weeks</h2>
    @if($weeks !== [])
        <div class="{{ $tableRegionClasses }}" role="region" aria-labelledby="program-weeks-title" tabindex="0">
            <table class="w-full min-w-170 border-collapse text-sm" aria-labelledby="program-weeks-title">
                <thead>
                    <tr>
                        <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Title</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Dates</th>
                        <th scope="col" class="{{ $headerCellClasses }} text-right">Active activities</th>
                        <th scope="col" class="{{ $headerCellClasses }} text-right">Checklist items</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($weeks as $week)
                        <tr data-week-row>
                            <th scope="row" class="{{ $cellClasses }} font-bold whitespace-nowrap">
                                <a href="{{ $week['url'] }}" class="text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $week['label'] }}</a>
                            </th>
                            <td class="{{ $cellClasses }} wrap-break-word">{{ $week['title'] }}</td>
                            <td class="{{ $cellClasses }}">
                                <x-ui.badge :tone="$week['status_tone']">{{ $week['status_label'] }}</x-ui.badge>
                            </td>
                            <td class="{{ $cellClasses }} whitespace-nowrap" data-week-dates>{{ $week['dates'] }}</td>
                            <td class="{{ $cellClasses }} text-right tabular-nums" data-active-activity-count>{{ $week['active_activity_count'] }}</td>
                            <td class="{{ $cellClasses }} text-right tabular-nums" data-checklist-item-count>{{ $week['checklist_item_count'] }}</td>
                            <td class="{{ $cellClasses }}">
                                <x-ui.button variant="secondary" :href="$week['url']" aria-label="Edit {{ $week['label'] }}">Edit Week</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-muted">This curriculum has no weeks yet.</p>
    @endif
</x-ui.card>
