export interface PerfPoint {
    x: string;
    y: number;
}

export interface PerfTrendData {
    mobile?: PerfPoint[];
    desktop?: PerfPoint[];
}

export function performanceTrendChart(data: PerfTrendData = {}) {
    return {
        chart: null as any,
        resizeHandler: null as (() => void) | null,

        init() {
            const el = document.getElementById('perf-trend-chart');
            if (!el || !window.echarts) return;

            const mobileData = (data.mobile || []).map((p) => [p.x, p.y]);
            const desktopData = (data.desktop || []).map((p) => [p.x, p.y]);

            this.chart = window.echarts.init(el);
            this.chart.setOption({
                grid: { left: 40, right: 20, top: 20, bottom: 40 },
                tooltip: {
                    trigger: 'axis',
                    formatter: (params: any[]) => {
                        if (!params || params.length === 0) return '';
                        const date = new Date(params[0].value[0]).toLocaleDateString(undefined, {
                            month: 'short',
                            day: 'numeric',
                        });
                        let html = `<div class="font-medium text-xs mb-1">${date}</div>`;
                        params.forEach((item) => {
                            html += `<div class="text-xs flex items-center gap-2">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${item.color};"></span>
                                <span>${item.seriesName}: <b>${item.value[1]}</b></span>
                            </div>`;
                        });
                        return html;
                    },
                },
                legend: {
                    data: ['Mobile', 'Desktop'],
                    bottom: 0,
                    textStyle: { fontSize: 11 },
                },
                xAxis: {
                    type: 'time',
                    axisLabel: { fontSize: 10 },
                },
                yAxis: {
                    type: 'value',
                    min: 0,
                    max: 100,
                    interval: 20,
                    axisLabel: { fontSize: 10 },
                },
                series: [
                    {
                        name: 'Mobile',
                        type: 'line',
                        smooth: true,
                        showSymbol: true,
                        symbolSize: 6,
                        itemStyle: { color: '#dc2626' },
                        lineStyle: { width: 2 },
                        areaStyle: { color: 'rgba(220, 38, 38, 0.08)' },
                        data: mobileData,
                    },
                    {
                        name: 'Desktop',
                        type: 'line',
                        smooth: true,
                        showSymbol: true,
                        symbolSize: 6,
                        itemStyle: { color: '#0ea5e9' },
                        lineStyle: { width: 2 },
                        areaStyle: { color: 'rgba(14, 165, 233, 0.08)' },
                        data: desktopData,
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
