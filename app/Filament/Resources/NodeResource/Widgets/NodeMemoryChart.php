<?php

namespace App\Filament\Resources\NodeResource\Widgets;

use App\Models\Node;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;

class NodeMemoryChart extends ChartWidget
{
    protected static ?string $pollingInterval = '5s';
    protected static ?string $maxHeight = '300px';

    public ?Model $record = null;

    protected function getData(): array
    {
        /** @var Node $node */
        $node = $this->record;

        $memUsed = collect(cache()->get("nodes.$node->id.memory_used"))->slice(-10)
            ->map(fn ($value, $key) => [
                'memory' => config('panel.use_binary_prefix') ? $value / 1024 / 1024 / 1024 : $value / 1000 / 1000 / 1000,
                'timestamp' => Carbon::createFromTimestamp($key, (auth()->user()->timezone ?? 'UTC'))->format('H:i:s'),
            ])
            ->all();

        return [
            'datasets' => [
                [
                    'data' => array_column($memUsed, 'memory'),
                    'backgroundColor' => [
                        'rgba(96, 165, 250, 0.3)',
                    ],
                    'tension' => '0.3',
                    'fill' => true,
                ],
            ],
            'labels' => array_column($memUsed, 'timestamp'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            scales: {
                y: {
                    min: 0,
                },
            },
            plugins: {
                legend: {
                    display: false,
                }
            }
        }
    JS);
    }

    public function getHeading(): string
    {
        /** @var Node $node */
        $node = $this->record;
        $latestMemoryUsed = collect(cache()->get("nodes.$node->id.memory_used"))->last();
        $totalMemory = collect(cache()->get("nodes.$node->id.memory_total"))->last();

        $used = config('panel.use_binary_prefix')
            ? number_format($latestMemoryUsed / 1024 / 1024 / 1024, 2) .' GiB'
            : number_format($latestMemoryUsed / 1000 / 1000 / 1000, 2) . ' GB';

        $total = config('panel.use_binary_prefix')
            ? number_format($totalMemory / 1024 / 1024 / 1024, 2) .' GiB'
            : number_format($totalMemory / 1000 / 1000 / 1000, 2) . ' GB';

        return 'Memory - ' . $used . ' Of ' . $total;
    }
}
