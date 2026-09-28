/**
 * Daily HTTP traffic summary aggregated from Nginx access logs (`clockwork:rollup-traffic`).
 * Status codes are grouped into canonical 2xx/3xx/4xx/5xx families.
 */
export interface DailyTrafficItem {
    date: string;
    status_2xx: number;
    status_3xx: number;
    status_4xx: number;
    status_5xx: number;
}

export interface TrafficData {
    daily?: DailyTrafficItem[];
    calendar?: [string, number][];
}

/**
 * Traffic Charts Component
 *
 * Mounted in `resources/views/dashboard/site/tab-traffic.blade.php`:
 *   <div x-data="trafficCharts({{ json_encode($trafficData) }})">
 *
 * Renders dual Apache ECharts visualizations:
 * 1. Stacked daily HTTP status bar chart (2xx, 3xx, 4xx, 5xx) with an interactive dataZoom slider.
 * 2. 365-day calendar activity heatmap showing daily request intensity.
 *
 * Automatically manages window resize handlers and Alpine `$cleanup` lifecycle
 * hooks to dispose chart instances and prevent memory leaks on unmount.
 */
export function trafficCharts(trafficData: TrafficData = {}) {
    return {
        dailyChart: null as any,
        calChart: null as any,
        resizeHandler: null as (() => void) | null,

        init() {
            const daily = trafficData?.daily || [];
            const calendar = trafficData?.calendar || [];

            const dailyEl = document.getElementById('traffic-daily');
            if (dailyEl && window.echarts && daily.length > 0) {
                const dates = daily.map((d) => d.date);
                const s2xx = daily.map((d) => d.status_2xx);
                const s3xx = daily.map((d) => d.status_3xx);
                const s4xx = daily.map((d) => d.status_4xx);
                const s5xx = daily.map((d) => d.status_5xx);

                this.dailyChart = window.echarts.init(dailyEl);
                this.dailyChart.setOption({
                    grid: { left: 50, right: 16, top: 20, bottom: 50 },
                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                    legend: {
                        data: ['2xx', '3xx', '4xx', '5xx'],
                        bottom: 0,
                        textStyle: { fontSize: 11 },
                    },
                    xAxis: {
                        type: 'category',
                        data: dates,
                        axisLabel: { fontSize: 10, formatter: (v: string) => v.slice(5) },
                    },
                    yAxis: {
                        type: 'value',
                        axisLabel: {
                            fontSize: 10,
                            formatter: (v: number) => (v >= 1000 ? `${(v / 1000).toFixed(1)}k` : String(v)),
                        },
                    },
                    dataZoom: [
                        { type: 'inside', start: 50, end: 100 },
                        { type: 'slider', height: 18, bottom: 28, start: 50, end: 100 },
                    ],
                    series: [
                        { name: '2xx', type: 'bar', stack: 'total', data: s2xx, itemStyle: { color: '#10b981' } },
                        { name: '3xx', type: 'bar', stack: 'total', data: s3xx, itemStyle: { color: '#6366f1' } },
                        { name: '4xx', type: 'bar', stack: 'total', data: s4xx, itemStyle: { color: '#f59e0b' } },
                        { name: '5xx', type: 'bar', stack: 'total', data: s5xx, itemStyle: { color: '#ef4444' } },
                    ],
                });
            }

            const calEl = document.getElementById('traffic-calendar');
            if (calEl && window.echarts && calendar.length > 0) {
                this.calChart = window.echarts.init(calEl);
                const max = Math.max(...calendar.map((c) => c[1]), 1);
                const today = new Date();
                const start = new Date(today);
                start.setDate(start.getDate() - 364);
                const fmt = (d: Date) => d.toISOString().slice(0, 10);

                this.calChart.setOption({
                    tooltip: {
                        formatter: (p: any) => `${p.value[0]}<br/>${Number(p.value[1]).toLocaleString()} requests`,
                    },
                    visualMap: {
                        min: 0,
                        max,
                        type: 'piecewise',
                        orient: 'horizontal',
                        left: 'center',
                        bottom: 0,
                        pieces: [
                            { min: 0, max: 0, label: '0', color: '#f3f4f6' },
                            { min: 1, max: Math.max(1, max * 0.1), color: '#dbeafe' },
                            { min: Math.max(1, max * 0.1), max: Math.max(1, max * 0.3), color: '#93c5fd' },
                            { min: Math.max(1, max * 0.3), max: Math.max(1, max * 0.6), color: '#3b82f6' },
                            { min: Math.max(1, max * 0.6), color: '#1e40af' },
                        ],
                        textStyle: { fontSize: 10 },
                    },
                    calendar: {
                        top: 20,
                        bottom: 50,
                        left: 40,
                        right: 20,
                        range: [fmt(start), fmt(today)],
                        cellSize: ['auto', 14],
                        itemStyle: { borderColor: '#fff', borderWidth: 2 },
                        splitLine: { show: false },
                        yearLabel: { show: false },
                        monthLabel: { fontSize: 10, color: '#6b7280' },
                        dayLabel: { fontSize: 10, color: '#9ca3af' },
                    },
                    series: {
                        type: 'heatmap',
                        coordinateSystem: 'calendar',
                        data: calendar,
                    },
                });
            }

            this.resizeHandler = () => {
                this.dailyChart?.resize();
                this.calChart?.resize();
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
            this.dailyChart?.dispose();
            this.calChart?.dispose();
        },
    };
}
