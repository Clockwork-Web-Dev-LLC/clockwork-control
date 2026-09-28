/**
 * Single server resource sample captured during periodic health polling.
 */
export interface ServerMetricItem {
    recorded_at: string;
    cpu_pct: number;
    memory_pct: number;
    disk_pct: number;
    load_1: number;
}

/**
 * Server Metrics Chart Component
 *
 * Mounted in `resources/views/dashboard/server/tab-stats.blade.php`:
 *   <div x-data="serverMetricsChart({{ json_encode($metricsForChart) }})">
 *
 * Renders dual-axis server resource trends using Apache ECharts:
 * - Left Y-Axis (0–100%): CPU %, Memory %, Disk % lines.
 * - Right Y-Axis: 1-minute load average line.
 *
 * Data originates from 5-minute health polls (`ServerMetric`). Includes
 * automatic window resize handlers and Alpine `$cleanup` lifecycle hooks.
 */
export function serverMetricsChart(metrics: ServerMetricItem[] = []) {
    return {
        chart: null as any,
        resizeHandler: null as (() => void) | null,

        init() {
            const el = document.getElementById('metrics-chart');
            if (!el || !window.echarts || metrics.length === 0) return;

            const cpu = metrics.map((d) => [d.recorded_at, d.cpu_pct]);
            const mem = metrics.map((d) => [d.recorded_at, d.memory_pct]);
            const disk = metrics.map((d) => [d.recorded_at, d.disk_pct]);
            const load = metrics.map((d) => [d.recorded_at, d.load_1]);

            this.chart = window.echarts.init(el);
            this.chart.setOption({
                grid: { left: 45, right: 45, top: 20, bottom: 40 },
                tooltip: {
                    trigger: 'axis',
                    formatter: (params: any[]) => {
                        if (!params || params.length === 0) return '';
                        const date = new Date(params[0].value[0]).toLocaleString(undefined, {
                            month: 'short',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                        });
                        let html = `<div class="font-medium text-xs mb-1">${date}</div>`;
                        params.forEach((item) => {
                            const val = item.seriesName === 'Load 1m' ? item.value[1] : `${item.value[1]}%`;
                            html += `<div class="text-xs flex items-center justify-between gap-3">
                                <span class="flex items-center gap-1.5">
                                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${item.color};"></span>
                                    <span>${item.seriesName}</span>
                                </span>
                                <b>${val}</b>
                            </div>`;
                        });
                        return html;
                    },
                },
                legend: {
                    data: ['CPU %', 'Memory %', 'Disk %', 'Load 1m'],
                    bottom: 0,
                    textStyle: { fontSize: 11 },
                },
                xAxis: {
                    type: 'time',
                    axisLabel: { fontSize: 10 },
                },
                yAxis: [
                    {
                        type: 'value',
                        name: '',
                        min: 0,
                        max: 100,
                        axisLabel: {
                            fontSize: 10,
                            formatter: '{value}%',
                        },
                    },
                    {
                        type: 'value',
                        name: '',
                        axisLabel: { fontSize: 10 },
                        splitLine: { show: false },
                    },
                ],
                series: [
                    {
                        name: 'CPU %',
                        type: 'line',
                        smooth: true,
                        showSymbol: false,
                        yAxisIndex: 0,
                        itemStyle: { color: '#3b82f6' },
                        lineStyle: { width: 1.5 },
                        data: cpu,
                    },
                    {
                        name: 'Memory %',
                        type: 'line',
                        smooth: true,
                        showSymbol: false,
                        yAxisIndex: 0,
                        itemStyle: { color: '#10b981' },
                        lineStyle: { width: 1.5 },
                        data: mem,
                    },
                    {
                        name: 'Disk %',
                        type: 'line',
                        smooth: true,
                        showSymbol: false,
                        yAxisIndex: 0,
                        itemStyle: { color: '#f59e0b' },
                        lineStyle: { width: 1.5 },
                        data: disk,
                    },
                    {
                        name: 'Load 1m',
                        type: 'line',
                        smooth: true,
                        showSymbol: false,
                        yAxisIndex: 1,
                        itemStyle: { color: '#a78bfa' },
                        lineStyle: { width: 1.5, type: 'dashed' },
                        data: load,
                    },
                ],
            });

            this.resizeHandler = () => {
                this.chart?.resize();
            };
            window.addEventListener('resize', this.resizeHandler);

            if (typeof (this as any).$cleanup === 'function') {
                (this as any).$cleanup(() => {
                    this.destroy();
                });
            }
        },

        destroy() {
            if (this.resizeHandler) {
                window.removeEventListener('resize', this.resizeHandler);
            }
            this.chart?.dispose();
        },
    };
}
