<x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="program-activities-title">
    <h2 id="program-activities-title" class="text-xl font-extrabold">Activities</h2>
    <p class="text-sm/relaxed text-muted" data-weeks-link>
        Edit an activity on its week's page, from the
        <a href="#weeks" class="text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">Weeks tab</a>.
    </p>
    @if($activities !== [])
        <p class="text-sm/relaxed text-muted">The curriculum's participants by their latest attempt at each activity. In progress is a draft not handed in yet, including a revision or a retry.</p>
        <div class="{{ $tableRegionClasses }}" role="region" aria-labelledby="program-activities-title" tabindex="0">
            <table class="w-full min-w-220 border-collapse text-sm" aria-labelledby="program-activities-title">
                <thead>
                    <tr>
                        <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Activity</th>
                        @foreach($activityCountColumns as $column)
                            <th scope="col" class="{{ $headerCellClasses }}">{{ $column['label'] }}</th>
                        @endforeach
                        <th scope="col" class="{{ $headerCellClasses }}">Reviews</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($activities as $activity)
                        <tr data-activity-row>
                            <td class="{{ $cellClasses }} whitespace-nowrap text-muted">{{ $activity['week'] }}</td>
                            <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $activity['title'] }}</th>
                            @foreach($activityCountColumns as $column)
                                <td class="{{ $cellClasses }} tabular-nums" data-activity-count="{{ $column['key'] }}">{{ $activity['counts'][$column['key']] }}</td>
                            @endforeach
                            <td class="{{ $cellClasses }} whitespace-nowrap">
                                <a href="{{ $activity['review_queue_url'] }}" aria-label="Open the review queue for {{ $activity['title'] }}" class="text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">Review queue</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-muted">This curriculum's active weeks have no activities yet.</p>
    @endif
</x-ui.card>
