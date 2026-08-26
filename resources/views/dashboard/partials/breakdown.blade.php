{{--
    Segmented share bar + labelled distribution list.

    @param  list<array{label: string, count: int}>  $items      Rows to chart, highest first.
    @param  int                                      $total      Denominator for the share percentages.
    @param  list<string>                             $palette    Categorical colours, cycled by index.
    @param  string                                   $emptyText  Shown when there is nothing to chart.
--}}
@if (count($items) === 0)
    <div class="breakdown empty-list">{{ $emptyText }}</div>
@else
    <div class="segbar">
        @foreach ($items as $item)
            <span style="width: {{ $total > 0 ? $item['count'] / $total * 100 : 0 }}%; background: {{ $palette[$loop->index % count($palette)] }}"></span>
        @endforeach
    </div>
    <div class="breakdown">
        @foreach ($items as $item)
            <div class="row">
                <span class="dot" style="background: {{ $palette[$loop->index % count($palette)] }}"></span>
                <span class="lbl">{{ $item['label'] }}</span>
                <span class="pct">{{ $total > 0 ? round($item['count'] / $total * 100) : 0 }}%</span>
                <span class="cnt">{{ $item['count'] }}</span>
            </div>
        @endforeach
    </div>
@endif
